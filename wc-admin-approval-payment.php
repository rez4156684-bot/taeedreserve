<?php
/**
 * Plugin Name: WooCommerce Admin Approval Payment
 * Plugin URI: https://example.com
 * Description: افزونه تایید مدیر قبل از پرداخت برای محصولات ساده ووکامرس - الهام گرفته از WooCommerce Order Approval
 * Version: 3.1.0
 * Author: Your Name
 * Author URI: https://example.com
 * Text Domain: wc-admin-approval
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// جلوگیری از دسترسی مستقیم
if (!defined('ABSPATH')) {
    exit;
}

// بررسی وجود ووکامرس
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>افزونه "WooCommerce Admin Approval Payment" نیاز به فعال بودن ووکامرس دارد.</p></div>';
    });
    return;
}

/**
 * کلاس اصلی افزونه
 */
class WC_Admin_Approval_Payment {

    private static $instance = null;

    /**
     * دریافت نمونه واحد کلاس
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * سازنده کلاس
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * راه‌اندازی هوک‌ها
     */
    private function init_hooks() {
        // اضافه کردن فیلد به محصولات ساده
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_admin_approval_field'));
        add_action('woocommerce_process_product_meta', array($this, 'save_admin_approval_field'));

        // مدیریت وضعیت سفارش جدید - اینجا مهم است!
        add_action('woocommerce_checkout_update_order_meta', array($this, 'set_order_awaiting_approval'), 10, 2);

        // جلوگیری از تغییر خودکار وضعیت
        add_filter('woocommerce_payment_complete_order_status', array($this, 'prevent_auto_complete'), 10, 3);

        // تغییر redirect بعد از checkout
        add_filter('woocommerce_get_checkout_order_received_url', array($this, 'custom_redirect_after_purchase'), 10, 2);

        // صفحه انتظار تایید
        add_action('init', array($this, 'register_pending_approval_endpoint'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_action('template_redirect', array($this, 'handle_pending_approval_page'));

        // پنل مدیریت
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_post_approve_order_payment', array($this, 'approve_order_payment'));
        add_action('admin_post_reject_order_payment', array($this, 'reject_order_payment'));

        // نمایش در پنل کاربری
        add_filter('woocommerce_account_menu_items', array($this, 'add_my_account_menu_item'));
        add_action('woocommerce_account_pending-payments_endpoint', array($this, 'pending_payments_content'));
        add_action('init', array($this, 'add_pending_payments_endpoint'));

        // اضافه کردن ستون وضعیت تایید به لیست سفارشات
        add_filter('manage_edit-shop_order_columns', array($this, 'add_approval_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'approval_column_content'), 10, 2);

        // نمایش در صفحه جزئیات سفارش
        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'display_approval_status_in_order'));

        // AJAX برای بارگذاری خودکار
        add_action('wp_ajax_check_payment_approval', array($this, 'ajax_check_payment_approval'));
        add_action('wp_ajax_nopriv_check_payment_approval', array($this, 'ajax_check_payment_approval'));

        // اضافه کردن اکشن‌های دستی به لیست سفارشات
        add_filter('woocommerce_admin_order_actions', array($this, 'add_custom_order_actions'), 10, 2);
        add_action('admin_action_approve_order', array($this, 'process_approve_order_action'));
    }

    /**
     * اضافه کردن فیلد تایید مدیر به محصولات ساده
     */
    public function add_admin_approval_field() {
        global $post;

        $product = wc_get_product($post->ID);

        // فقط برای محصولات ساده نمایش داده شود
        if ($product && $product->is_type('simple')) {
            echo '<div class="options_group show_if_simple">';

            woocommerce_wp_checkbox(array(
                'id' => '_require_admin_approval',
                'label' => __('نیاز به تایید مدیر', 'wc-admin-approval'),
                'description' => __('فعال کردن این گزینه باعث می‌شود که پرداخت این محصول نیاز به تایید مدیر داشته باشد.', 'wc-admin-approval'),
                'desc_tip' => true,
            ));

            echo '</div>';
        }
    }

    /**
     * ذخیره فیلد تایید مدیر
     */
    public function save_admin_approval_field($post_id) {
        $require_approval = isset($_POST['_require_admin_approval']) ? 'yes' : 'no';
        update_post_meta($post_id, '_require_admin_approval', $require_approval);
    }

    /**
     * تنظیم وضعیت سفارش به "در حال بررسی" - مهم‌ترین بخش!
     */
    public function set_order_awaiting_approval($order_id, $data) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        $needs_approval = false;

        // بررسی آیتم‌های سفارش
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->is_type('simple')) {
                $require_approval = get_post_meta($product->get_id(), '_require_admin_approval', true);
                if ($require_approval === 'yes') {
                    $needs_approval = true;
                    break;
                }
            }
        }

        if ($needs_approval) {
            // ذخیره اطلاعات
            update_post_meta($order_id, '_requires_admin_approval', 'yes');
            update_post_meta($order_id, '_approval_request_time', current_time('mysql'));

            // تغییر وضعیت به "در حال بررسی" - FORCE!
            $order->update_status('on-hold', 'سفارش در انتظار تایید مدیر است.', true);
        }
    }

    /**
     * جلوگیری از تغییر خودکار وضعیت به completed
     */
    public function prevent_auto_complete($status, $order_id, $order) {
        $requires_approval = get_post_meta($order_id, '_requires_admin_approval', true);

        if ($requires_approval === 'yes') {
            // اگر هنوز تایید نشده، وضعیت را به on-hold نگه دار
            return 'on-hold';
        }

        return $status;
    }

    /**
     * تغییر redirect بعد از خرید
     */
    public function custom_redirect_after_purchase($url, $order) {
        $requires_approval = get_post_meta($order->get_id(), '_requires_admin_approval', true);

        if ($requires_approval === 'yes') {
            $url = home_url('/pending-approval/?order_id=' . $order->get_id() . '&key=' . $order->get_order_key());
        }

        return $url;
    }

    /**
     * ثبت endpoint برای صفحه انتظار تایید
     */
    public function register_pending_approval_endpoint() {
        add_rewrite_rule('^pending-approval/?', 'index.php?pending_approval=1', 'top');

        // فلاش rewrite rules در صورت نیاز
        if (!get_option('wc_admin_approval_flushed')) {
            flush_rewrite_rules();
            update_option('wc_admin_approval_flushed', 1);
        }
    }

    /**
     * اضافه کردن query vars
     */
    public function add_query_vars($vars) {
        $vars[] = 'pending_approval';
        return $vars;
    }

    /**
     * مدیریت صفحه انتظار تایید
     */
    public function handle_pending_approval_page() {
        if (get_query_var('pending_approval')) {
            $this->display_pending_approval_page();
            exit;
        }
    }

    /**
     * نمایش صفحه انتظار تایید
     */
    private function display_pending_approval_page() {
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        $order_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';

        if (!$order_id || !$order_key) {
            wp_die('سفارش نامعتبر است.');
        }

        $order = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== $order_key) {
            wp_die('سفارش نامعتبر است.');
        }

        $order_status = $order->get_status();

        get_header();

        ?>
        <style>
            .approval-container {
                max-width: 800px;
                margin: 50px auto;
                padding: 30px;
                background: #fff;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                direction: rtl;
                text-align: right;
            }
            .approval-status {
                text-align: center;
                padding: 40px 20px;
            }
            .approval-status.pending {
                background: #fff3cd;
                border: 2px solid #ffc107;
                border-radius: 8px;
            }
            .approval-status.approved {
                background: #d4edda;
                border: 2px solid #28a745;
                border-radius: 8px;
            }
            .approval-status.rejected {
                background: #f8d7da;
                border: 2px solid #dc3545;
                border-radius: 8px;
            }
            .approval-status h2 {
                margin: 0 0 15px 0;
                font-size: 24px;
            }
            .approval-status p {
                font-size: 16px;
                margin: 10px 0;
            }
            .spinner {
                border: 4px solid #f3f3f3;
                border-top: 4px solid #ffc107;
                border-radius: 50%;
                width: 50px;
                height: 50px;
                animation: spin 1s linear infinite;
                margin: 20px auto;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            .order-details {
                margin-top: 30px;
                border-top: 2px solid #eee;
                padding-top: 30px;
            }
            .order-details h3 {
                margin-bottom: 20px;
                font-size: 20px;
            }
            .order-item {
                display: flex;
                justify-content: space-between;
                padding: 15px;
                background: #f9f9f9;
                margin-bottom: 10px;
                border-radius: 5px;
            }
            .order-item-details {
                flex: 1;
            }
            .order-item-price {
                font-weight: bold;
                color: #333;
            }
            .order-summary {
                background: #f0f0f0;
                padding: 20px;
                border-radius: 5px;
                margin-top: 20px;
            }
            .order-summary-row {
                display: flex;
                justify-content: space-between;
                padding: 10px 0;
                border-bottom: 1px solid #ddd;
            }
            .order-summary-row:last-child {
                border-bottom: none;
                font-weight: bold;
                font-size: 18px;
            }
            .payment-button {
                display: inline-block;
                background: #28a745;
                color: #fff;
                padding: 15px 40px;
                text-decoration: none;
                border-radius: 5px;
                font-size: 18px;
                margin-top: 20px;
                transition: background 0.3s;
            }
            .payment-button:hover {
                background: #218838;
                color: #fff;
            }
            .icon {
                font-size: 48px;
                margin-bottom: 15px;
            }
        </style>

        <div class="approval-container">
            <?php if ($order_status === 'on-hold'): ?>
                <div class="approval-status pending">
                    <div class="icon">⏳</div>
                    <h2>در حال بررسی سفارش</h2>
                    <p>سفارش شما با موفقیت ثبت شد و در حال بررسی توسط مدیریت است.</p>
                    <p>لطفاً صبور باشید، پس از تایید مدیر می‌توانید پرداخت را انجام دهید.</p>
                    <div class="spinner"></div>
                    <p style="font-size: 14px; color: #666;">این صفحه به صورت خودکار بروزرسانی می‌شود...</p>
                </div>
            <?php elseif ($order_status === 'pending'): ?>
                <div class="approval-status approved">
                    <div class="icon">✅</div>
                    <h2>سفارش شما تایید شد!</h2>
                    <p>سفارش شما توسط مدیریت تایید شد.</p>
                    <p>اکنون می‌توانید نسبت به پرداخت اقدام کنید.</p>
                    <div style="text-align: center;">
                        <a href="<?php echo esc_url($order->get_checkout_payment_url()); ?>" class="payment-button">
                            پرداخت سفارش
                        </a>
                    </div>
                </div>
            <?php elseif ($order_status === 'cancelled' || $order_status === 'failed'): ?>
                <div class="approval-status rejected">
                    <div class="icon">❌</div>
                    <h2>سفارش رد شد</h2>
                    <p>متأسفانه سفارش شما توسط مدیریت تایید نشد.</p>
                    <p>برای اطلاعات بیشتر با پشتیبانی تماس بگیرید.</p>
                </div>
            <?php endif; ?>

            <div class="order-details">
                <h3>جزئیات سفارش #<?php echo $order->get_order_number(); ?></h3>

                <?php foreach ($order->get_items() as $item): ?>
                    <div class="order-item">
                        <div class="order-item-details">
                            <strong><?php echo $item->get_name(); ?></strong>
                            <div>تعداد: <?php echo $item->get_quantity(); ?></div>
                        </div>
                        <div class="order-item-price">
                            <?php echo wc_price($item->get_total()); ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="order-summary">
                    <div class="order-summary-row">
                        <span>جمع جزء:</span>
                        <span><?php echo wc_price($order->get_subtotal()); ?></span>
                    </div>
                    <?php if ($order->get_total_tax() > 0): ?>
                    <div class="order-summary-row">
                        <span>مالیات:</span>
                        <span><?php echo wc_price($order->get_total_tax()); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($order->get_shipping_total() > 0): ?>
                    <div class="order-summary-row">
                        <span>هزینه ارسال:</span>
                        <span><?php echo wc_price($order->get_shipping_total()); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="order-summary-row">
                        <span>جمع کل:</span>
                        <span><?php echo wc_price($order->get_total()); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($order_status === 'on-hold'): ?>
        <script>
            // بروزرسانی خودکار هر 5 ثانیه
            setInterval(function() {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success && response.data.status !== 'on-hold') {
                            location.reload();
                        }
                    }
                };
                xhr.send('action=check_payment_approval&order_id=<?php echo $order_id; ?>');
            }, 5000);
        </script>
        <?php endif; ?>

        <?php
        get_footer();
    }

    /**
     * اضافه کردن منوی مدیریت
     */
    public function add_admin_menu() {
        add_menu_page(
            'تایید پرداخت‌ها',
            'تایید پرداخت‌ها',
            'manage_woocommerce',
            'wc-payment-approvals',
            array($this, 'admin_page_content'),
            'dashicons-yes-alt',
            56
        );
    }

    /**
     * محتوای صفحه مدیریت
     */
    public function admin_page_content() {
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'on-hold';

        // دریافت سفارشات نیازمند تایید
        $args = array(
            'limit' => -1,
            'meta_query' => array(
                array(
                    'key' => '_requires_admin_approval',
                    'value' => 'yes',
                ),
            ),
        );

        if ($status_filter !== 'all') {
            $args['status'] = $status_filter;
        }

        $orders = wc_get_orders($args);

        ?>
        <div class="wrap">
            <h1>مدیریت تایید پرداخت‌ها</h1>

            <div class="tablenav top">
                <div class="alignleft actions">
                    <select name="status_filter" id="status_filter" onchange="location.href='?page=wc-payment-approvals&status='+this.value">
                        <option value="all" <?php selected($status_filter, 'all'); ?>>همه</option>
                        <option value="on-hold" <?php selected($status_filter, 'on-hold'); ?>>در حال بررسی</option>
                        <option value="pending" <?php selected($status_filter, 'pending'); ?>>در انتظار پرداخت</option>
                        <option value="cancelled" <?php selected($status_filter, 'cancelled'); ?>>رد شده</option>
                    </select>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>شماره سفارش</th>
                        <th>مشتری</th>
                        <th>محصولات</th>
                        <th>مبلغ</th>
                        <th>تاریخ درخواست</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center;">سفارشی یافت نشد.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <?php
                            $order_status = $order->get_status();
                            $request_time = get_post_meta($order->get_id(), '_approval_request_time', true);

                            $status_label = array(
                                'on-hold' => '<span style="color: #ffc107;">⏳ در حال بررسی</span>',
                                'pending' => '<span style="color: #28a745;">✅ در انتظار پرداخت</span>',
                                'cancelled' => '<span style="color: #dc3545;">❌ رد شده</span>',
                            );
                            ?>
                            <tr>
                                <td>
                                    <strong>
                                        <a href="<?php echo admin_url('post.php?post=' . $order->get_id() . '&action=edit'); ?>">
                                            #<?php echo $order->get_order_number(); ?>
                                        </a>
                                    </strong>
                                </td>
                                <td>
                                    <?php echo $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(); ?>
                                    <br>
                                    <small><?php echo $order->get_billing_email(); ?></small>
                                </td>
                                <td>
                                    <?php
                                    $items = array();
                                    foreach ($order->get_items() as $item) {
                                        $items[] = $item->get_name() . ' × ' . $item->get_quantity();
                                    }
                                    echo implode('<br>', $items);
                                    ?>
                                </td>
                                <td><?php echo wc_price($order->get_total()); ?></td>
                                <td><?php echo $request_time ? date_i18n('Y/m/d H:i', strtotime($request_time)) : '-'; ?></td>
                                <td><?php echo $status_label[$order_status] ?? wc_get_order_status_name($order_status); ?></td>
                                <td>
                                    <?php if ($order_status === 'on-hold'): ?>
                                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                            <input type="hidden" name="action" value="approve_order_payment">
                                            <input type="hidden" name="order_id" value="<?php echo $order->get_id(); ?>">
                                            <?php wp_nonce_field('approve_payment_' . $order->get_id()); ?>
                                            <button type="submit" class="button button-primary" onclick="return confirm('آیا از تایید این سفارش مطمئن هستید؟')">
                                                تایید
                                            </button>
                                        </form>

                                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                            <input type="hidden" name="action" value="reject_order_payment">
                                            <input type="hidden" name="order_id" value="<?php echo $order->get_id(); ?>">
                                            <?php wp_nonce_field('reject_payment_' . $order->get_id()); ?>
                                            <button type="submit" class="button" onclick="return confirm('آیا از رد این سفارش مطمئن هستید؟')">
                                                رد
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <style>
            .wrap {
                direction: rtl;
                text-align: right;
            }
            .wp-list-table {
                direction: rtl;
            }
            .wp-list-table th,
            .wp-list-table td {
                text-align: right;
            }
        </style>
        <?php
    }

    /**
     * تایید سفارش
     */
    private function approve_order($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        update_post_meta($order_id, '_approval_time', current_time('mysql'));
        update_post_meta($order_id, '_approved_by', get_current_user_id());

        // تغییر وضعیت به "در انتظار پرداخت"
        $order->update_status('pending', 'سفارش توسط مدیریت تایید شد و آماده پرداخت است.');
    }

    /**
     * رد سفارش
     */
    private function reject_order($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        update_post_meta($order_id, '_rejection_time', current_time('mysql'));
        update_post_meta($order_id, '_rejected_by', get_current_user_id());

        // تغییر وضعیت به لغو شده
        $order->update_status('cancelled', 'سفارش توسط مدیریت رد شد.');
    }

    /**
     * تایید پرداخت سفارش
     */
    public function approve_order_payment() {
        if (!isset($_POST['order_id'])) {
            wp_die('درخواست نامعتبر است.');
        }

        $order_id = intval($_POST['order_id']);

        if (!wp_verify_nonce($_POST['_wpnonce'], 'approve_payment_' . $order_id)) {
            wp_die('احراز هویت ناموفق بود.');
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die('شما دسترسی لازم را ندارید.');
        }

        $this->approve_order($order_id);

        wp_redirect(add_query_arg(array(
            'page' => 'wc-payment-approvals',
            'approved' => 1
        ), admin_url('admin.php')));
        exit;
    }

    /**
     * رد پرداخت سفارش
     */
    public function reject_order_payment() {
        if (!isset($_POST['order_id'])) {
            wp_die('درخواست نامعتبر است.');
        }

        $order_id = intval($_POST['order_id']);

        if (!wp_verify_nonce($_POST['_wpnonce'], 'reject_payment_' . $order_id)) {
            wp_die('احراز هویت ناموفق بود.');
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die('شما دسترسی لازم را ندارید.');
        }

        $this->reject_order($order_id);

        wp_redirect(add_query_arg(array(
            'page' => 'wc-payment-approvals',
            'rejected' => 1
        ), admin_url('admin.php')));
        exit;
    }

    /**
     * اضافه کردن منو به پنل کاربری
     */
    public function add_my_account_menu_item($items) {
        $items['pending-payments'] = 'پرداخت‌های در انتظار';
        return $items;
    }

    /**
     * محتوای پرداخت‌های در انتظار
     */
    public function pending_payments_content() {
        $customer_id = get_current_user_id();

        $orders = wc_get_orders(array(
            'customer_id' => $customer_id,
            'limit' => -1,
            'meta_query' => array(
                array(
                    'key' => '_requires_admin_approval',
                    'value' => 'yes',
                ),
            ),
        ));

        if (empty($orders)) {
            echo '<p>شما هیچ سفارش در انتظار تاییدی ندارید.</p>';
            return;
        }

        echo '<h3>سفارشات در انتظار تایید</h3>';
        echo '<table class="shop_table my_account_orders">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>سفارش</th>';
        echo '<th>تاریخ</th>';
        echo '<th>وضعیت</th>';
        echo '<th>مبلغ</th>';
        echo '<th>عملیات</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($orders as $order) {
            $order_status = $order->get_status();

            $status_labels = array(
                'on-hold' => 'در حال بررسی',
                'pending' => 'در انتظار پرداخت',
                'cancelled' => 'رد شده',
            );

            echo '<tr>';
            echo '<td>#' . $order->get_order_number() . '</td>';
            echo '<td>' . $order->get_date_created()->date_i18n('Y/m/d') . '</td>';
            echo '<td>' . ($status_labels[$order_status] ?? wc_get_order_status_name($order_status)) . '</td>';
            echo '<td>' . wc_price($order->get_total()) . '</td>';
            echo '<td>';

            if ($order_status === 'pending') {
                echo '<a href="' . esc_url($order->get_checkout_payment_url()) . '" class="button">پرداخت</a>';
            } else {
                echo '<a href="' . esc_url(home_url('/pending-approval/?order_id=' . $order->get_id() . '&key=' . $order->get_order_key())) . '" class="button">مشاهده</a>';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    /**
     * اضافه کردن endpoint پنل کاربری
     */
    public function add_pending_payments_endpoint() {
        add_rewrite_endpoint('pending-payments', EP_ROOT | EP_PAGES);
    }

    /**
     * اضافه کردن ستون به لیست سفارشات
     */
    public function add_approval_column($columns) {
        $new_columns = array();

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;

            if ($key === 'order_status') {
                $new_columns['approval_status'] = 'وضعیت تایید';
            }
        }

        return $new_columns;
    }

    /**
     * محتوای ستون وضعیت تایید
     */
    public function approval_column_content($column, $post_id) {
        if ($column === 'approval_status') {
            $requires_approval = get_post_meta($post_id, '_requires_admin_approval', true);

            if ($requires_approval === 'yes') {
                $order = wc_get_order($post_id);
                $order_status = $order->get_status();

                $status_labels = array(
                    'on-hold' => '<span style="color: #ffc107;">⏳ در حال بررسی</span>',
                    'pending' => '<span style="color: #28a745;">✅ در انتظار پرداخت</span>',
                    'cancelled' => '<span style="color: #dc3545;">❌ رد شده</span>',
                );

                echo $status_labels[$order_status] ?? '-';
            } else {
                echo '-';
            }
        }
    }

    /**
     * نمایش وضعیت تایید در صفحه جزئیات سفارش
     */
    public function display_approval_status_in_order($order) {
        $requires_approval = get_post_meta($order->get_id(), '_requires_admin_approval', true);

        if ($requires_approval !== 'yes') {
            return;
        }

        $order_status = $order->get_status();
        $request_time = get_post_meta($order->get_id(), '_approval_request_time', true);

        echo '<div class="order_data_column" style="clear:both; float:none; width:100%;">';
        echo '<h3>وضعیت تایید پرداخت</h3>';

        $status_info = array(
            'on-hold' => array('label' => 'در حال بررسی', 'color' => '#ffc107'),
            'pending' => array('label' => 'در انتظار پرداخت (تایید شده)', 'color' => '#28a745'),
            'cancelled' => array('label' => 'رد شده', 'color' => '#dc3545'),
        );

        if (isset($status_info[$order_status])) {
            echo '<p style="background: ' . $status_info[$order_status]['color'] . '; color: #fff; padding: 10px; border-radius: 5px;">';
            echo '<strong>وضعیت:</strong> ' . $status_info[$order_status]['label'];
            echo '</p>';
        }

        if ($request_time) {
            echo '<p><strong>زمان درخواست:</strong> ' . date_i18n('Y/m/d H:i', strtotime($request_time)) . '</p>';
        }

        if ($order_status === 'pending') {
            $approval_time = get_post_meta($order->get_id(), '_approval_time', true);
            $approved_by = get_post_meta($order->get_id(), '_approved_by', true);

            if ($approval_time) {
                echo '<p><strong>زمان تایید:</strong> ' . date_i18n('Y/m/d H:i', strtotime($approval_time)) . '</p>';
            }

            if ($approved_by) {
                $user = get_userdata($approved_by);
                echo '<p><strong>تایید کننده:</strong> ' . $user->display_name . '</p>';
            }
        }

        echo '</div>';
    }

    /**
     * اضافه کردن اکشن‌های دستی به سفارشات
     */
    public function add_custom_order_actions($actions, $order) {
        $requires_approval = get_post_meta($order->get_id(), '_requires_admin_approval', true);

        if ($requires_approval === 'yes') {
            $status = $order->get_status();

            if ($status === 'on-hold') {
                $actions['approve_order'] = array(
                    'url' => wp_nonce_url(admin_url('admin.php?action=approve_order&order_id=' . $order->get_id()), 'approve-order'),
                    'name' => 'تایید سفارش',
                    'action' => 'approve_order',
                );
            }
        }

        return $actions;
    }

    /**
     * پردازش اکشن تایید سفارش
     */
    public function process_approve_order_action() {
        if (!isset($_GET['order_id']) || !wp_verify_nonce($_GET['_wpnonce'], 'approve-order')) {
            wp_die('درخواست نامعتبر است.');
        }

        $order_id = intval($_GET['order_id']);
        $this->approve_order($order_id);

        wp_redirect(wp_get_referer());
        exit;
    }

    /**
     * AJAX برای بررسی وضعیت تایید
     */
    public function ajax_check_payment_approval() {
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

        if (!$order_id) {
            wp_send_json_error(array('message' => 'سفارش نامعتبر است.'));
        }

        $order = wc_get_order($order_id);
        $order_status = $order->get_status();

        wp_send_json_success(array(
            'status' => $order_status,
        ));
    }
}

// راه‌اندازی افزونه
function wc_admin_approval_payment_init() {
    return WC_Admin_Approval_Payment::get_instance();
}

add_action('plugins_loaded', 'wc_admin_approval_payment_init');

// فعال‌سازی افزونه
register_activation_hook(__FILE__, function() {
    // فلاش rewrite rules
    flush_rewrite_rules();
    update_option('wc_admin_approval_flushed', 0);
});

// غیرفعال‌سازی افزونه
register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
    delete_option('wc_admin_approval_flushed');
});
