<?php
/**
 * Plugin Name: WooCommerce Admin Approval Payment
 * Plugin URI: https://example.com
 * Description: افزونه تایید مدیر قبل از پرداخت برای محصولات ساده ووکامرس
 * Version: 1.0.0
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

        // مدیریت فرآیند پرداخت
        add_action('woocommerce_checkout_order_processed', array($this, 'handle_checkout_process'), 10, 3);
        add_filter('woocommerce_payment_successful_result', array($this, 'modify_payment_result'), 10, 2);

        // صفحه انتظار تایید
        add_action('init', array($this, 'register_pending_approval_endpoint'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_action('template_redirect', array($this, 'handle_pending_approval_page'));

        // پنل مدیریت
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_post_approve_order_payment', array($this, 'approve_order_payment'));
        add_action('admin_post_reject_order_payment', array($this, 'reject_order_payment'));

        // استایل و اسکریپت
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));

        // نمایش در پنل کاربری
        add_filter('woocommerce_account_menu_items', array($this, 'add_my_account_menu_item'));
        add_action('woocommerce_account_pending-payments_endpoint', array($this, 'pending_payments_content'));

        // ایجاد endpoint برای پنل کاربری
        add_action('init', array($this, 'add_pending_payments_endpoint'));

        // اضافه کردن ستون وضعیت تایید به لیست سفارشات
        add_filter('manage_edit-shop_order_columns', array($this, 'add_approval_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'approval_column_content'), 10, 2);

        // نمایش در صفحه جزئیات سفارش
        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'display_approval_status_in_order'));

        // AJAX برای بارگذاری خودکار
        add_action('wp_ajax_check_payment_approval', array($this, 'ajax_check_payment_approval'));
        add_action('wp_ajax_nopriv_check_payment_approval', array($this, 'ajax_check_payment_approval'));
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
     * مدیریت فرآیند checkout
     */
    public function handle_checkout_process($order_id, $posted_data, $order) {
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
            update_post_meta($order_id, '_requires_admin_approval', 'yes');
            update_post_meta($order_id, '_approval_status', 'pending');
            update_post_meta($order_id, '_approval_request_time', current_time('mysql'));
        }
    }

    /**
     * تغییر نتیجه پرداخت
     */
    public function modify_payment_result($result, $order_id) {
        $requires_approval = get_post_meta($order_id, '_requires_admin_approval', true);

        if ($requires_approval === 'yes') {
            $result = array(
                'result' => 'success',
                'redirect' => home_url('/pending-approval/?order_id=' . $order_id . '&key=' . get_post_meta($order_id, '_order_key', true))
            );
        }

        return $result;
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

        $approval_status = get_post_meta($order_id, '_approval_status', true);

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
            <?php if ($approval_status === 'pending'): ?>
                <div class="approval-status pending">
                    <div class="icon">⏳</div>
                    <h2>در انتظار تایید مدیر</h2>
                    <p>سفارش شما ثبت شده و در انتظار تایید مدیریت است.</p>
                    <p>لطفاً صبور باشید، پس از تایید مدیر می‌توانید پرداخت را انجام دهید.</p>
                    <div class="spinner"></div>
                    <p style="font-size: 14px; color: #666;">این صفحه به صورت خودکار بروزرسانی می‌شود...</p>
                </div>
            <?php elseif ($approval_status === 'approved'): ?>
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
            <?php elseif ($approval_status === 'rejected'): ?>
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

        <?php if ($approval_status === 'pending'): ?>
        <script>
            // بروزرسانی خودکار هر 5 ثانیه
            setInterval(function() {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success && response.data.status !== 'pending') {
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
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'pending';

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
            $args['meta_query'][] = array(
                'key' => '_approval_status',
                'value' => $status_filter,
            );
        }

        $orders = wc_get_orders($args);

        ?>
        <div class="wrap">
            <h1>مدیریت تایید پرداخت‌ها</h1>

            <div class="tablenav top">
                <div class="alignleft actions">
                    <select name="status_filter" id="status_filter" onchange="location.href='?page=wc-payment-approvals&status='+this.value">
                        <option value="all" <?php selected($status_filter, 'all'); ?>>همه</option>
                        <option value="pending" <?php selected($status_filter, 'pending'); ?>>در انتظار تایید</option>
                        <option value="approved" <?php selected($status_filter, 'approved'); ?>>تایید شده</option>
                        <option value="rejected" <?php selected($status_filter, 'rejected'); ?>>رد شده</option>
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
                            $approval_status = get_post_meta($order->get_id(), '_approval_status', true);
                            $request_time = get_post_meta($order->get_id(), '_approval_request_time', true);

                            $status_label = array(
                                'pending' => '<span style="color: #ffc107;">⏳ در انتظار</span>',
                                'approved' => '<span style="color: #28a745;">✅ تایید شده</span>',
                                'rejected' => '<span style="color: #dc3545;">❌ رد شده</span>',
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
                                <td><?php echo $status_label[$approval_status] ?? '-'; ?></td>
                                <td>
                                    <?php if ($approval_status === 'pending'): ?>
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

        update_post_meta($order_id, '_approval_status', 'approved');
        update_post_meta($order_id, '_approval_time', current_time('mysql'));
        update_post_meta($order_id, '_approved_by', get_current_user_id());

        $order = wc_get_order($order_id);
        $order->add_order_note('پرداخت توسط مدیریت تایید شد.');

        // ارسال ایمیل به مشتری
        $customer_email = $order->get_billing_email();
        $subject = 'سفارش شما تایید شد';
        $message = 'سفارش شماره ' . $order->get_order_number() . ' تایید شد. اکنون می‌توانید نسبت به پرداخت اقدام کنید.';
        wp_mail($customer_email, $subject, $message);

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

        update_post_meta($order_id, '_approval_status', 'rejected');
        update_post_meta($order_id, '_rejection_time', current_time('mysql'));
        update_post_meta($order_id, '_rejected_by', get_current_user_id());

        $order = wc_get_order($order_id);
        $order->update_status('cancelled', 'پرداخت توسط مدیریت رد شد.');

        // ارسال ایمیل به مشتری
        $customer_email = $order->get_billing_email();
        $subject = 'سفارش شما رد شد';
        $message = 'متأسفانه سفارش شماره ' . $order->get_order_number() . ' تایید نشد.';
        wp_mail($customer_email, $subject, $message);

        wp_redirect(add_query_arg(array(
            'page' => 'wc-payment-approvals',
            'rejected' => 1
        ), admin_url('admin.php')));
        exit;
    }

    /**
     * اضافه کردن استایل و اسکریپت به پنل مدیریت
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'toplevel_page_wc-payment-approvals') {
            return;
        }

        wp_enqueue_style('wc-admin-approval-admin', false);
    }

    /**
     * اضافه کردن استایل و اسکریپت به frontend
     */
    public function enqueue_frontend_scripts() {
        if (get_query_var('pending_approval')) {
            wp_enqueue_style('wc-admin-approval-frontend', false);
            wp_enqueue_script('wc-admin-approval-ajax', false, array('jquery'), '1.0', true);
        }
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
            $approval_status = get_post_meta($order->get_id(), '_approval_status', true);

            $status_labels = array(
                'pending' => 'در انتظار تایید',
                'approved' => 'تایید شده',
                'rejected' => 'رد شده',
            );

            echo '<tr>';
            echo '<td>#' . $order->get_order_number() . '</td>';
            echo '<td>' . $order->get_date_created()->date_i18n('Y/m/d') . '</td>';
            echo '<td>' . ($status_labels[$approval_status] ?? '-') . '</td>';
            echo '<td>' . wc_price($order->get_total()) . '</td>';
            echo '<td>';

            if ($approval_status === 'approved') {
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
                $approval_status = get_post_meta($post_id, '_approval_status', true);

                $status_labels = array(
                    'pending' => '<span style="color: #ffc107;">⏳ در انتظار</span>',
                    'approved' => '<span style="color: #28a745;">✅ تایید شده</span>',
                    'rejected' => '<span style="color: #dc3545;">❌ رد شده</span>',
                );

                echo $status_labels[$approval_status] ?? '-';
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

        $approval_status = get_post_meta($order->get_id(), '_approval_status', true);
        $request_time = get_post_meta($order->get_id(), '_approval_request_time', true);

        echo '<div class="order_data_column" style="clear:both; float:none; width:100%;">';
        echo '<h3>وضعیت تایید پرداخت</h3>';

        $status_info = array(
            'pending' => array('label' => 'در انتظار تایید', 'color' => '#ffc107'),
            'approved' => array('label' => 'تایید شده', 'color' => '#28a745'),
            'rejected' => array('label' => 'رد شده', 'color' => '#dc3545'),
        );

        if (isset($status_info[$approval_status])) {
            echo '<p style="background: ' . $status_info[$approval_status]['color'] . '; color: #fff; padding: 10px; border-radius: 5px;">';
            echo '<strong>وضعیت:</strong> ' . $status_info[$approval_status]['label'];
            echo '</p>';
        }

        if ($request_time) {
            echo '<p><strong>زمان درخواست:</strong> ' . date_i18n('Y/m/d H:i', strtotime($request_time)) . '</p>';
        }

        if ($approval_status === 'approved') {
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
     * AJAX برای بررسی وضعیت تایید
     */
    public function ajax_check_payment_approval() {
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

        if (!$order_id) {
            wp_send_json_error(array('message' => 'سفارش نامعتبر است.'));
        }

        $approval_status = get_post_meta($order_id, '_approval_status', true);

        wp_send_json_success(array(
            'status' => $approval_status,
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
