<?php
/**
 * Plugin Name: WooCommerce Admin Approval Payment
 * Plugin URI: https://example.com
 * Description: افزونه تایید مدیر قبل از پرداخت برای محصولات ساده ووکامرس با وضعیت‌های سفارشی
 * Version: 4.4.0
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
 * کلاس تبدیل تاریخ شمسی
 */
class WC_Persian_Date {

    /**
     * تبدیل تاریخ میلادی به شمسی
     */
    public static function gregorian_to_jalali($gy, $gm, $gd) {
        $g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);

        if ($gy > 1600) {
            $jy = 979;
            $gy -= 1600;
        } else {
            $jy = 0;
            $gy -= 621;
        }

        if ($gm > 2) {
            $gy2 = $gy + 1;
        } else {
            $gy2 = $gy;
        }

        $days = (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100)) + ((int)(($gy2 + 399) / 400)) - 80 + $gd + $g_d_m[$gm - 1];
        $jy += 33 * ((int)($days / 12053));
        $days %= 12053;
        $jy += 4 * ((int)($days / 1461));
        $days %= 1461;

        if ($days > 365) {
            $jy += (int)(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + (int)($days / 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + (int)(($days - 186) / 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return array($jy, $jm, $jd);
    }

    /**
     * فرمت کردن تاریخ شمسی
     */
    public static function format($format, $timestamp = null) {
        if ($timestamp === null) {
            $timestamp = time();
        }

        // تبدیل به تاریخ میلادی
        $date_array = getdate($timestamp);
        $gy = $date_array['year'];
        $gm = $date_array['mon'];
        $gd = $date_array['mday'];
        $hour = $date_array['hours'];
        $minute = $date_array['minutes'];

        // تبدیل به شمسی
        list($jy, $jm, $jd) = self::gregorian_to_jalali($gy, $gm, $gd);

        // نام ماه‌های شمسی
        $month_names = array(
            '', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
            'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'
        );

        // جایگزینی فرمت‌ها
        $formatted = $format;
        $formatted = str_replace('Y', $jy, $formatted);
        $formatted = str_replace('m', str_pad($jm, 2, '0', STR_PAD_LEFT), $formatted);
        $formatted = str_replace('d', str_pad($jd, 2, '0', STR_PAD_LEFT), $formatted);
        $formatted = str_replace('H', str_pad($hour, 2, '0', STR_PAD_LEFT), $formatted);
        $formatted = str_replace('i', str_pad($minute, 2, '0', STR_PAD_LEFT), $formatted);
        $formatted = str_replace('F', $month_names[$jm], $formatted);

        return $formatted;
    }

    /**
     * تبدیل تاریخ MySQL datetime به شمسی
     */
    public static function mysql_to_jalali($mysql_date, $format = 'Y/m/d H:i') {
        if (empty($mysql_date)) {
            return '-';
        }

        $timestamp = strtotime($mysql_date);
        return self::format($format, $timestamp);
    }
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
        // ثبت وضعیت‌های سفارشی - این باید اول اجرا شود!
        add_action('init', array($this, 'register_custom_order_statuses'));

        // اضافه کردن وضعیت‌ها به لیست ووکامرس
        add_filter('wc_order_statuses', array($this, 'add_custom_order_statuses'));

        // اضافه کردن به bulk actions در پنل مدیریت
        add_filter('bulk_actions-edit-shop_order', array($this, 'add_bulk_actions'));

        // اضافه کردن به فیلترهای سفارشات
        add_filter('views_edit-shop_order', array($this, 'add_order_status_filters'));

        // اضافه کردن فیلد به محصولات ساده
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_admin_approval_field'));
        add_action('woocommerce_process_product_meta', array($this, 'save_admin_approval_field'));

        // مدیریت وضعیت سفارش جدید
        add_action('woocommerce_checkout_update_order_meta', array($this, 'set_order_awaiting_approval'), 10, 2);

        // جلوگیری از تغییر خودکار وضعیت
        add_filter('woocommerce_payment_complete_order_status', array($this, 'prevent_auto_complete'), 10, 3);

        // تغییر redirect بعد از checkout - استفاده از woocommerce_thankyou
        add_action('woocommerce_thankyou', array($this, 'redirect_to_approval_page'), 1);

        // صفحه انتظار تایید
        add_action('init', array($this, 'register_pending_approval_endpoint'), 20);
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_action('template_redirect', array($this, 'handle_pending_approval_page'));

        // پنل مدیریت
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_post_approve_order_payment', array($this, 'approve_order_payment'));
        add_action('admin_post_reject_order_payment', array($this, 'reject_order_payment'));

        // نمایش در پنل کاربری
        add_filter('woocommerce_account_menu_items', array($this, 'add_my_account_menu_item'));
        add_action('woocommerce_account_pending-payments_endpoint', array($this, 'pending_payments_content'));
        add_action('init', array($this, 'add_pending_payments_endpoint'), 20);

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

        // اضافه کردن رنگ به وضعیت‌ها در ادمین
        add_action('admin_head', array($this, 'add_custom_status_colors'));

        // اضافه کردن placeholder برای افزونه‌های پیامکی
        add_filter('woocommerce_email_order_meta_fields', array($this, 'add_email_order_meta'), 10, 3);

        // پشتیبانی از افزونه‌های پیامکی مختلف - همه فیلترهای ممکن
        add_filter('woocommerce_order_status_changed', array($this, 'set_sms_global_order'), 10, 4);

        // فیلترهای متن پیامک برای افزونه‌های مختلف
        add_filter('wc_parsgreen_sms_text', array($this, 'replace_sms_placeholders'), 999, 2);
        add_filter('woocommerce_sms_text', array($this, 'replace_sms_placeholders'), 999, 2);
        add_filter('wp_sms_text_content', array($this, 'replace_sms_placeholders'), 999, 2);
        add_filter('woocommerce_sms_message', array($this, 'replace_sms_placeholders'), 999, 2);
        add_filter('wc_sms_message', array($this, 'replace_sms_placeholders'), 999, 2);
    }

    /**
     * ثبت وضعیت‌های سفارشی سفارش
     */
    public function register_custom_order_statuses() {
        register_post_status('wc-awaiting-approval', array(
            'label'                     => 'منتظر تایید مدیر',
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('منتظر تایید مدیر <span class="count">(%s)</span>', 'منتظر تایید مدیر <span class="count">(%s)</span>')
        ));

        register_post_status('wc-approved-payment', array(
            'label'                     => 'تایید شده - منتظر پرداخت',
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('تایید شده - منتظر پرداخت <span class="count">(%s)</span>', 'تایید شده - منتظر پرداخت <span class="count">(%s)</span>')
        ));
    }

    /**
     * اضافه کردن وضعیت‌های سفارشی به لیست ووکامرس
     */
    public function add_custom_order_statuses($order_statuses) {
        $new_order_statuses = array();

        foreach ($order_statuses as $key => $status) {
            $new_order_statuses[$key] = $status;

            if ('wc-pending' === $key) {
                $new_order_statuses['wc-awaiting-approval'] = 'منتظر تایید مدیر';
                $new_order_statuses['wc-approved-payment'] = 'تایید شده - منتظر پرداخت';
            }
        }

        return $new_order_statuses;
    }

    /**
     * اضافه کردن وضعیت‌ها به bulk actions
     */
    public function add_bulk_actions($bulk_actions) {
        $bulk_actions['mark_awaiting-approval'] = 'تغییر وضعیت به منتظر تایید مدیر';
        $bulk_actions['mark_approved-payment'] = 'تغییر وضعیت به تایید شده - منتظر پرداخت';

        return $bulk_actions;
    }

    /**
     * نمایش تعداد در فیلترهای سفارشات
     */
    public function add_order_status_filters($views) {
        global $wpdb;

        $awaiting_count = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->posts}
            WHERE post_type = 'shop_order'
            AND post_status = 'wc-awaiting-approval'
        ");

        $approved_count = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->posts}
            WHERE post_type = 'shop_order'
            AND post_status = 'wc-approved-payment'
        ");

        $views['wc-awaiting-approval'] = sprintf(
            '<a href="%s"%s>منتظر تایید مدیر <span class="count">(%d)</span></a>',
            admin_url('edit.php?post_status=wc-awaiting-approval&post_type=shop_order'),
            (isset($_GET['post_status']) && $_GET['post_status'] === 'wc-awaiting-approval') ? ' class="current"' : '',
            $awaiting_count
        );

        $views['wc-approved-payment'] = sprintf(
            '<a href="%s"%s>تایید شده - منتظر پرداخت <span class="count">(%d)</span></a>',
            admin_url('edit.php?post_status=wc-approved-payment&post_type=shop_order'),
            (isset($_GET['post_status']) && $_GET['post_status'] === 'wc-approved-payment') ? ' class="current"' : '',
            $approved_count
        );

        return $views;
    }

    /**
     * اضافه کردن رنگ‌های سفارشی به وضعیت‌ها
     */
    public function add_custom_status_colors() {
        ?>
        <style>
            .order-status.status-awaiting-approval {
                background: #ffc107;
                color: #fff;
            }
            .order-status.status-approved-payment {
                background: #28a745;
                color: #fff;
            }
            mark.awaiting-approval {
                background: #ffc107;
                color: #fff;
            }
            mark.approved-payment {
                background: #28a745;
                color: #fff;
            }
        </style>
        <?php
    }

    /**
     * اضافه کردن فیلد تایید مدیر به محصولات ساده
     */
    public function add_admin_approval_field() {
        global $post;

        if (!$post || !$post->ID) {
            return;
        }

        $product = wc_get_product($post->ID);

        if ($product && $product->is_type('simple')) {
            echo '<div class="options_group">';

            woocommerce_wp_checkbox(array(
                'id' => '_require_admin_approval',
                'label' => 'نیاز به تایید مدیر',
                'description' => 'فعال کردن این گزینه باعث می‌شود که پرداخت این محصول نیاز به تایید مدیر داشته باشد.',
                'desc_tip' => true,
                'value' => get_post_meta($post->ID, '_require_admin_approval', true)
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
     * تنظیم وضعیت سفارش به "منتظر تایید مدیر"
     */
    public function set_order_awaiting_approval($order_id, $data) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        $needs_approval = false;

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
            update_post_meta($order_id, '_approval_request_time', current_time('mysql'));

            $order->update_status('awaiting-approval', 'سفارش در انتظار تایید مدیر است.', true);
        }
    }

    /**
     * جلوگیری از تغییر خودکار وضعیت
     */
    public function prevent_auto_complete($status, $order_id, $order) {
        $requires_approval = get_post_meta($order_id, '_requires_admin_approval', true);

        if ($requires_approval === 'yes') {
            $current_status = $order->get_status();

            if ($current_status === 'awaiting-approval') {
                return 'awaiting-approval';
            }

            if ($current_status === 'approved-payment') {
                return 'approved-payment';
            }
        }

        return $status;
    }

    /**
     * Redirect به صفحه پرداخت‌های در انتظار بعد از checkout
     * این در woocommerce_thankyou اجرا می‌شود که مطمئناً بعد از save order است
     */
    public function redirect_to_approval_page($order_id) {
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // بررسی نیاز به تایید
        $requires_approval = get_post_meta($order_id, '_requires_admin_approval', true);

        if ($requires_approval === 'yes' && $order->get_status() === 'awaiting-approval') {
            // Redirect به صفحه pending-payments در پنل کاربری
            $redirect_url = wc_get_account_endpoint_url('pending-payments');

            // JavaScript redirect - قابل اطمینان‌ترین روش در این مرحله
            ?>
            <script type="text/javascript">
                window.location.replace('<?php echo esc_js($redirect_url); ?>');
            </script>
            <noscript>
                <meta http-equiv="refresh" content="0;url=<?php echo esc_url($redirect_url); ?>">
            </noscript>
            <?php
            exit;
        }
    }

    /**
     * ثبت endpoint برای صفحه انتظار تایید
     */
    public function register_pending_approval_endpoint() {
        add_rewrite_rule('^pending-approval/?', 'index.php?pending_approval=1', 'top');

        if (!get_option('wc_admin_approval_flushed_v42')) {
            flush_rewrite_rules();
            update_option('wc_admin_approval_flushed_v42', 1);
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
                font-weight: bold;
            }
            .payment-button:hover {
                background: #218838;
                color: #fff;
                text-decoration: none;
            }
            .icon {
                font-size: 48px;
                margin-bottom: 15px;
            }
            .payment-link-box {
                background: #e8f5e9;
                border: 2px dashed #28a745;
                padding: 20px;
                margin: 20px 0;
                border-radius: 8px;
                text-align: center;
            }
            .payment-link-box p {
                margin: 10px 0;
                font-size: 14px;
                color: #555;
            }
            .payment-link-url {
                display: block;
                background: #fff;
                padding: 10px;
                border-radius: 5px;
                margin: 10px 0;
                word-wrap: break-word;
                font-size: 12px;
                color: #666;
                border: 1px solid #ddd;
            }
        </style>

        <div class="approval-container">
            <?php if ($order_status === 'awaiting-approval'): ?>
                <div class="approval-status pending">
                    <div class="icon">⏳</div>
                    <h2>در حال بررسی سفارش</h2>
                    <p>سفارش شما با موفقیت ثبت شد و در حال بررسی توسط مدیریت است.</p>
                    <p>لطفاً صبور باشید، پس از تایید مدیر می‌توانید پرداخت را انجام دهید.</p>
                    <div class="spinner"></div>
                    <p style="font-size: 14px; color: #666;">این صفحه به صورت خودکار بروزرسانی می‌شود...</p>
                </div>
            <?php elseif ($order_status === 'approved-payment'): ?>
                <div class="approval-status approved">
                    <div class="icon">✅</div>
                    <h2>سفارش شما تایید شد!</h2>
                    <p>سفارش شما توسط مدیریت تایید شد.</p>
                    <p>اکنون می‌توانید نسبت به پرداخت اقدام کنید.</p>

                    <div style="text-align: center; margin: 30px 0;">
                        <a href="<?php echo esc_url($order->get_checkout_payment_url()); ?>" class="payment-button">
                            💳 پرداخت سفارش
                        </a>
                    </div>

                    <div class="payment-link-box">
                        <p><strong>🔗 لینک پرداخت شما:</strong></p>
                        <p style="font-size: 13px;">می‌توانید این لینک را ذخیره کنید و در هر زمان از طریق آن پرداخت نمایید.</p>
                        <span class="payment-link-url"><?php echo esc_url($order->get_checkout_payment_url()); ?></span>
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

        <?php if ($order_status === 'awaiting-approval'): ?>
        <script>
            setInterval(function() {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success && response.data.status !== 'awaiting-approval') {
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
     * ذخیره order در global برای استفاده در فیلترهای SMS
     */
    public function set_sms_global_order($order_id, $old_status, $new_status, $order) {
        // فقط برای وضعیت تایید شده
        if ($new_status === 'approved-payment') {
            $GLOBALS['wc_approval_current_order'] = $order;
            $GLOBALS['wc_approval_current_order_id'] = $order_id;
        }
    }

    /**
     * جایگزینی placeholders در متن پیامک
     * این تابع با تمام افزونه‌های پیامکی کار می‌کند
     */
    public function replace_sms_placeholders($text, $order = null) {
        // ابتدا سعی می‌کنیم order را پیدا کنیم
        $order_to_use = null;

        // اگر order مستقیماً پاس شده
        if ($order && is_a($order, 'WC_Order')) {
            $order_to_use = $order;
        }
        // اگر order یک order_id است
        elseif ($order && is_numeric($order)) {
            $order_to_use = wc_get_order($order);
        }
        // از global بگیریم
        elseif (isset($GLOBALS['wc_approval_current_order'])) {
            $order_to_use = $GLOBALS['wc_approval_current_order'];
        }
        elseif (isset($GLOBALS['wc_approval_current_order_id'])) {
            $order_to_use = wc_get_order($GLOBALS['wc_approval_current_order_id']);
        }

        // اگر هیچ order نداریم، متن را بدون تغییر برگردانیم
        if (!$order_to_use) {
            return $text;
        }

        $order_id = $order_to_use->get_id();
        $requires_approval = get_post_meta($order_id, '_requires_admin_approval', true);

        // فقط برای سفارشات تایید شده
        if ($requires_approval === 'yes' && $order_to_use->get_status() === 'approved-payment') {
            $payment_url = $order_to_use->get_checkout_payment_url();

            // جایگزینی placeholder های مختلف - همه فرمت‌های ممکن
            $placeholders = array(
                '{payment_link}',
                '{order_pay_url}',
                '{pay_link}',
                '{pay_url}',
                '[payment_link]',
                '[order_pay_url]',
                '[pay_link]',
                '%payment_link%',
                '%order_pay_url%',
                '{{payment_link}}',
                '{{order_pay_url}}',
            );

            foreach ($placeholders as $placeholder) {
                $text = str_replace($placeholder, $payment_url, $text);
            }
        }

        return $text;
    }

    /**
     * اضافه کردن placeholder برای ایمیل‌ها
     */
    public function add_email_order_meta($fields, $sent_to_admin, $order) {
        $requires_approval = get_post_meta($order->get_id(), '_requires_admin_approval', true);

        if ($requires_approval === 'yes' && $order->get_status() === 'approved-payment') {
            $fields['payment_link'] = array(
                'label' => 'لینک پرداخت',
                'value' => $order->get_checkout_payment_url(),
            );
        }

        return $fields;
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
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'awaiting-approval';

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
                        <option value="awaiting-approval" <?php selected($status_filter, 'awaiting-approval'); ?>>منتظر تایید</option>
                        <option value="approved-payment" <?php selected($status_filter, 'approved-payment'); ?>>تایید شده - منتظر پرداخت</option>
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
                                'awaiting-approval' => '<span style="color: #ffc107;">⏳ منتظر تایید</span>',
                                'approved-payment' => '<span style="color: #28a745;">✅ تایید شده - منتظر پرداخت</span>',
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
                                <td><?php echo $request_time ? WC_Persian_Date::mysql_to_jalali($request_time, 'Y/m/d H:i') : '-'; ?></td>
                                <td><?php echo $status_label[$order_status] ?? wc_get_order_status_name($order_status); ?></td>
                                <td>
                                    <?php if ($order_status === 'awaiting-approval'): ?>
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
                                    <?php elseif ($order_status === 'approved-payment'): ?>
                                        <a href="<?php echo esc_url($order->get_checkout_payment_url()); ?>" class="button" target="_blank">
                                            مشاهده لینک پرداخت
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if (!empty($orders)): ?>
            <div style="margin-top: 20px; padding: 15px; background: #f0f8ff; border-right: 4px solid #2196F3; direction: rtl;">
                <h3 style="margin-top: 0;">💡 راهنمای استفاده از لینک پرداخت در پیامک</h3>
                <p>برای ارسال لینک پرداخت در پیامک‌های وضعیت <strong>"تایید شده - منتظر پرداخت"</strong> (approved-payment)، می‌توانید از یکی از placeholder های زیر استفاده کنید:</p>

                <div style="background: #fff; padding: 15px; border-radius: 5px; margin: 10px 0;">
                    <h4 style="margin-top: 0; color: #2196F3;">✅ Placeholders پشتیبانی شده:</h4>
                    <p style="margin: 5px 0;"><code>{order_pay_url}</code> ← اولین انتخاب (توصیه می‌شود)</p>
                    <p style="margin: 5px 0;"><code>{payment_link}</code></p>
                    <p style="margin: 5px 0;"><code>{pay_link}</code></p>
                    <p style="margin: 5px 0;"><code>{pay_url}</code></p>
                    <p style="margin: 5px 0;"><code>[payment_link]</code> و <code>[order_pay_url]</code></p>
                    <p style="margin: 5px 0;"><code>%payment_link%</code> و <code>%order_pay_url%</code></p>
                </div>

                <p style="font-size: 13px; color: #666; margin-top: 10px;">
                    <strong>مثال متن پیامک:</strong><br>
                    <code style="background: #fff; padding: 10px; display: block; margin: 5px 0; border: 1px solid #ddd; border-radius: 3px;">
                        سلام {customer_name}<br>
                        سفارش شما تایید شد!<br>
                        برای پرداخت کلیک کنید:<br>
                        {order_pay_url}
                    </code>
                </p>

                <p style="font-size: 12px; color: #999; margin-top: 10px;">
                    ⚠️ مهم: این placeholders فقط در پیامک‌های وضعیت "تایید شده - منتظر پرداخت" جایگزین می‌شوند.
                </p>
            </div>
            <?php endif; ?>
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

        $order->update_status('approved-payment', 'سفارش توسط مدیریت تایید شد و آماده پرداخت است.', true);
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

        $order->update_status('cancelled', 'سفارش توسط مدیریت رد شد.', true);
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
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        if (empty($orders)) {
            echo '<div class="woocommerce-message woocommerce-message--info">';
            echo '<p>شما هیچ سفارش در انتظار تاییدی ندارید.</p>';
            echo '</div>';
            return;
        }

        // نمایش سفارشات با کارت‌های زیبا
        echo '<style>
            .approval-cards-container {
                display: grid;
                gap: 20px;
                margin: 20px 0;
            }
            .approval-card {
                background: #fff;
                border: 2px solid #ddd;
                border-radius: 8px;
                padding: 25px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                transition: all 0.3s;
            }
            .approval-card:hover {
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            }
            .approval-card.awaiting {
                border-color: #ffc107;
                background: #fffbf0;
            }
            .approval-card.approved {
                border-color: #28a745;
                background: #f0fff4;
            }
            .approval-card.rejected {
                border-color: #dc3545;
                background: #fff0f0;
            }
            .approval-card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 15px;
                padding-bottom: 15px;
                border-bottom: 2px solid #eee;
            }
            .approval-card-order-number {
                font-size: 20px;
                font-weight: bold;
                color: #333;
            }
            .approval-card-status {
                padding: 8px 16px;
                border-radius: 20px;
                font-weight: bold;
                font-size: 14px;
            }
            .approval-card-status.awaiting {
                background: #ffc107;
                color: #fff;
            }
            .approval-card-status.approved {
                background: #28a745;
                color: #fff;
            }
            .approval-card-status.rejected {
                background: #dc3545;
                color: #fff;
            }
            .approval-card-items {
                margin: 15px 0;
            }
            .approval-card-item {
                display: flex;
                justify-content: space-between;
                padding: 10px 0;
                border-bottom: 1px solid #eee;
            }
            .approval-card-item:last-child {
                border-bottom: none;
            }
            .approval-card-footer {
                margin-top: 20px;
                padding-top: 15px;
                border-top: 2px solid #eee;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .approval-card-total {
                font-size: 18px;
                font-weight: bold;
                color: #333;
            }
            .approval-card .button {
                padding: 12px 30px;
                font-size: 16px;
                font-weight: bold;
            }
            .approval-card .button.payment-button {
                background: #28a745;
                color: #fff;
                border: none;
            }
            .approval-card .button.payment-button:hover {
                background: #218838;
            }
            .approval-spinner {
                display: inline-block;
                width: 20px;
                height: 20px;
                border: 3px solid #f3f3f3;
                border-top: 3px solid #ffc107;
                border-radius: 50%;
                animation: spin 1s linear infinite;
                margin-left: 10px;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            .approval-card-info {
                font-size: 13px;
                color: #666;
                margin-top: 10px;
            }
        </style>';

        echo '<h3>سفارشات در انتظار تایید و پرداخت</h3>';
        echo '<div class="approval-cards-container">';

        $has_awaiting = false;

        foreach ($orders as $order) {
            $order_status = $order->get_status();
            $order_id = $order->get_id();

            if ($order_status === 'awaiting-approval') {
                $has_awaiting = true;
            }

            // تعیین کلاس کارت
            $card_class = 'approval-card';
            $status_class = '';
            $status_text = '';

            if ($order_status === 'awaiting-approval') {
                $card_class .= ' awaiting';
                $status_class = 'awaiting';
                $status_text = '⏳ منتظر تایید مدیر';
            } elseif ($order_status === 'approved-payment') {
                $card_class .= ' approved';
                $status_class = 'approved';
                $status_text = '✅ تایید شده - آماده پرداخت';
            } elseif ($order_status === 'cancelled' || $order_status === 'failed') {
                $card_class .= ' rejected';
                $status_class = 'rejected';
                $status_text = '❌ رد شده';
            } else {
                continue; // سفارشات کامل شده را نمایش نده
            }

            echo '<div class="' . $card_class . '">';

            // Header
            echo '<div class="approval-card-header">';
            echo '<span class="approval-card-order-number">سفارش #' . $order->get_order_number() . '</span>';
            echo '<span class="approval-card-status ' . $status_class . '">' . $status_text . '</span>';
            echo '</div>';

            // Items
            echo '<div class="approval-card-items">';
            foreach ($order->get_items() as $item) {
                echo '<div class="approval-card-item">';
                echo '<span>' . $item->get_name() . ' × ' . $item->get_quantity() . '</span>';
                echo '<span>' . wc_price($item->get_total()) . '</span>';
                echo '</div>';
            }
            echo '</div>';

            // تاریخ
            $request_time = get_post_meta($order_id, '_approval_request_time', true);
            if ($request_time) {
                echo '<div class="approval-card-info">';
                echo '📅 تاریخ ثبت: ' . WC_Persian_Date::mysql_to_jalali($request_time, 'Y/m/d H:i');
                echo '</div>';
            }

            // Footer
            echo '<div class="approval-card-footer">';
            echo '<span class="approval-card-total">جمع کل: ' . wc_price($order->get_total()) . '</span>';

            if ($order_status === 'approved-payment') {
                echo '<a href="' . esc_url($order->get_checkout_payment_url()) . '" class="button payment-button">💳 پرداخت سفارش</a>';
            } elseif ($order_status === 'awaiting-approval') {
                echo '<span style="color: #666; font-size: 14px;"><span class="approval-spinner"></span> در حال بررسی...</span>';
            } else {
                echo '<span style="color: #999;">سفارش رد شده</span>';
            }

            echo '</div>';

            echo '</div>'; // end approval-card
        }

        echo '</div>'; // end approval-cards-container

        // Auto-refresh برای سفارشات در انتظار
        if ($has_awaiting) {
            ?>
            <script>
                // بارگذاری مجدد صفحه هر 10 ثانیه برای بررسی تایید
                setInterval(function() {
                    location.reload();
                }, 10000);
            </script>
            <div class="woocommerce-message woocommerce-message--info" style="margin-top: 20px;">
                <p>این صفحه هر 10 ثانیه به صورت خودکار بروزرسانی می‌شود تا وضعیت سفارش شما بررسی شود.</p>
            </div>
            <?php
        }
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
                    'awaiting-approval' => '<span style="color: #ffc107;">⏳ منتظر تایید</span>',
                    'approved-payment' => '<span style="color: #28a745;">✅ تایید شده - منتظر پرداخت</span>',
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
            'awaiting-approval' => array('label' => 'منتظر تایید مدیر', 'color' => '#ffc107'),
            'approved-payment' => array('label' => 'تایید شده - منتظر پرداخت', 'color' => '#28a745'),
            'cancelled' => array('label' => 'رد شده', 'color' => '#dc3545'),
        );

        if (isset($status_info[$order_status])) {
            echo '<p style="background: ' . $status_info[$order_status]['color'] . '; color: #fff; padding: 10px; border-radius: 5px;">';
            echo '<strong>وضعیت:</strong> ' . $status_info[$order_status]['label'];
            echo '</p>';
        }

        if ($request_time) {
            echo '<p><strong>زمان درخواست:</strong> ' . WC_Persian_Date::mysql_to_jalali($request_time, 'Y/m/d H:i') . '</p>';
        }

        if ($order_status === 'approved-payment') {
            $approval_time = get_post_meta($order->get_id(), '_approval_time', true);
            $approved_by = get_post_meta($order->get_id(), '_approved_by', true);

            if ($approval_time) {
                echo '<p><strong>زمان تایید:</strong> ' . WC_Persian_Date::mysql_to_jalali($approval_time, 'Y/m/d H:i') . '</p>';
            }

            if ($approved_by) {
                $user = get_userdata($approved_by);
                echo '<p><strong>تایید کننده:</strong> ' . $user->display_name . '</p>';
            }

            echo '<div style="margin-top: 15px; padding: 15px; background: #e8f5e9; border-right: 4px solid #28a745;">';
            echo '<p><strong>🔗 لینک پرداخت:</strong></p>';
            echo '<input type="text" readonly value="' . esc_url($order->get_checkout_payment_url()) . '" style="width: 100%; padding: 8px; font-size: 12px;" onclick="this.select();">';
            echo '<p style="font-size: 11px; margin: 5px 0 0 0; color: #666;">برای کپی کردن روی لینک کلیک کنید</p>';
            echo '</div>';
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

            if ($status === 'awaiting-approval') {
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
    flush_rewrite_rules();
    update_option('wc_admin_approval_flushed_v42', 0);
});

// غیرفعال‌سازی افزونه
register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
    delete_option('wc_admin_approval_flushed_v42');
});
