<?php
/**
 * Plugin Name: مدیر
 * Description: افزونه حسابداری کامل ووکامرس با صفحه اختصاصی سفارشات، تحلیل RFM مشتریان، قیف فروش، پرفروش‌های همراه، نقشه حرارتی فروش، گزارش جغرافیایی، سودآوری دسته‌بندی‌ها، ماشین‌حساب قیمت، سود و زیان، گزارش درگاه‌ها با دوره تسویه، انبار، هزینه کرد، آلارم فروش، تقویم شمسی کلیکی، نوار نوتیف، پاپ‌آپ محصول، دستیار گفتگوی هوش مصنوعی، مدیریت مرجوعی، ابزارک‌های پیشخوان، مدیریت منو، افزودن محصول خودکار از لینک، حمل‌ونقل پیشرفته، سفارش‌های حرفه‌ای، ویرایش دسته‌جمعی، وضعیت‌های سفارش سفارشی، لیبل آدرس، پیگیری سفارش، تشخیص VPN، و قفل فعال‌سازی با رمز
 * Version: 5.4.0
 * Author: محمد بهره‌ور
 * Author URI: tel:09190960958
 * Text Domain: modir
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit; // جلوگیری از دسترسی مستقیم
}

define('MODIR_VERSION', '5.4.0');
define('MODIR_PATH', plugin_dir_path(__FILE__));
define('MODIR_URL', plugin_dir_url(__FILE__));

/**
 * بررسی فعال بودن ووکامرس
 */
function modir_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>افزونه مدیر</strong> برای کارکرد صحیح، نیاز به فعال بودن ووکامرس دارد.</p></div>';
        });
        return false;
    }
    return true;
}

add_action('plugins_loaded', function () {
    if (!modir_check_woocommerce()) {
        return;
    }

    require_once MODIR_PATH . 'includes/license.php';
    modir_license_init(); // این ماژول همیشه لازم است، حتی وقتی افزونه قفل است

    // فایل‌های عمومی: چون هوک‌هایی مثل محاسبه هزینه ارسال در سبد خرید، ثبت
    // متادیتای سفارش هنگام خرید، نوار نوتیف، پاپ‌آپ، دستیار گفتگو، و صفحه
    // پیگیری همگی باید در فرانت سایت هم کار کنند، همیشه بارگذاری می‌شوند.
    require_once MODIR_PATH . 'includes/jalali.php';
    require_once MODIR_PATH . 'includes/functions.php';
    require_once MODIR_PATH . 'includes/settings.php';
    require_once MODIR_PATH . 'includes/notifbar.php';
    require_once MODIR_PATH . 'includes/product-popup.php';
    require_once MODIR_PATH . 'includes/chat-assistant.php';
    require_once MODIR_PATH . 'includes/returns.php';
    require_once MODIR_PATH . 'includes/menu-manager.php';
    require_once MODIR_PATH . 'includes/shipping.php';
    require_once MODIR_PATH . 'includes/orders-columns.php';
    require_once MODIR_PATH . 'includes/order-statuses.php';
    require_once MODIR_PATH . 'includes/order-tracking.php';
    require_once MODIR_PATH . 'includes/vpn-guard.php';
    require_once MODIR_PATH . 'includes/diagnostics.php';
    require_once MODIR_PATH . 'includes/wholesale.php';
    require_once MODIR_PATH . 'includes/extra-fees.php';
    require_once MODIR_PATH . 'includes/ai-content.php';

    // فایل‌های سنگین «فقط ادمین»: هیچ هوک فرانتی ندارند (گزارش‌ها، تحلیل‌ها،
    // ابزارهای دسته‌جمعی و...)، پس فقط وقتی واقعاً در پیشخوان/AJAX ادمین
    // هستیم بارگذاری می‌شوند — این باعث سبک‌تر و سریع‌تر شدن هر بار بازدید
    // فرانت سایت می‌شود، چون این ۱۵+ فایل دیگر برای بازدیدکننده‌های سایت
    // اصلاً خوانده و پردازش نمی‌شوند.
    if (is_admin()) {
        require_once MODIR_PATH . 'includes/report-products.php';
        require_once MODIR_PATH . 'includes/report-sales.php';
        require_once MODIR_PATH . 'includes/report-status.php';
        require_once MODIR_PATH . 'includes/reports-hub.php';
        require_once MODIR_PATH . 'includes/dashboard-widgets.php';
        require_once MODIR_PATH . 'includes/product-import.php';
        require_once MODIR_PATH . 'includes/bulk-edit.php';
        require_once MODIR_PATH . 'includes/address-label.php';
        require_once MODIR_PATH . 'includes/profit-loss.php';
        require_once MODIR_PATH . 'includes/gateways-report.php';
        require_once MODIR_PATH . 'includes/warehouse.php';
        require_once MODIR_PATH . 'includes/expenses.php';
        require_once MODIR_PATH . 'includes/sales-alert.php';
        require_once MODIR_PATH . 'includes/products-grid.php';
        require_once MODIR_PATH . 'includes/price-calculator.php';
        require_once MODIR_PATH . 'includes/analytics-extra.php';
        require_once MODIR_PATH . 'includes/orders-list.php';
        require_once MODIR_PATH . 'includes/marketing-hub.php';
        require_once MODIR_PATH . 'includes/dashboard-welcome.php';
        require_once MODIR_PATH . 'includes/suppliers.php';
    }

    add_action('admin_menu', 'modir_register_admin_menu');
    add_action('admin_enqueue_scripts', 'modir_enqueue_admin_assets');

    // همه امکانات واقعی افزونه (فرانت و ادمین) فقط وقتی رمز فعال‌سازی درست
    // وارد شده باشد فعال می‌شوند. تا آن زمان، فقط صفحه وارد کردن رمز نمایش
    // داده می‌شود و هیچ هوکی که رفتار سایت را تغییر بدهد ثبت نمی‌شود.
    if (modir_license_is_unlocked()) {
        // هرکدام از این ماژول‌ها را می‌توان از تنظیمات ← عیب‌یابی، بدون
        // نیاز به دسترسی فایل سرور، موقتاً خاموش کرد تا مشخص شود کدام
        // ماژول باعث یک مشکل خاص می‌شود.
        if (!modir_diag_module_disabled('notifbar')) {
            modir_notifbar_init();
        }
        if (!modir_diag_module_disabled('popup')) {
            modir_popup_init();
        }
        if (!modir_diag_module_disabled('chat')) {
            modir_chat_init();
        }
        if (!modir_diag_module_disabled('returns')) {
            modir_returns_init();
        }
        if (!modir_diag_module_disabled('menu')) {
            modir_menu_init();
        }
        if (!modir_diag_module_disabled('shipping')) {
            modir_shipping_init();
        }
        if (!modir_diag_module_disabled('orders_columns')) {
            modir_orders_init();
        }
        if (!modir_diag_module_disabled('order_statuses')) {
            modir_order_statuses_init();
        }
        if (!modir_diag_module_disabled('tracking')) {
            modir_tracking_init();
        }
        if (!modir_diag_module_disabled('vpn')) {
            modir_vpn_init();
        }
        modir_variations_init();
        modir_diagnostics_init(); // خود ابزار عیب‌یابی هرگز قابل‌خاموش‌کردن نیست
        if (!modir_diag_module_disabled('wholesale')) {
            modir_wholesale_init();
        }
        modir_custom_css_init();
        if (!modir_diag_module_disabled('extra_fees')) {
            modir_extra_fees_init();
        }
        if (!modir_diag_module_disabled('ai_content')) {
            modir_ai_content_init();
        }

        // این‌ها فقط برای فایل‌های «فقط ادمین» هستند که بالاتر فقط در
        // is_admin() بارگذاری شدند؛ صدازدنشان در فرانت باعث خطای «تابع
        // تعریف‌نشده» می‌شود، پس دقیقاً با همان شرط محافظت می‌شوند.
        if (is_admin()) {
            if (!modir_diag_module_disabled('dashboard_widgets')) {
                modir_widgets_init();
            }
            if (!modir_diag_module_disabled('import')) {
                modir_import_init();
            }
            if (!modir_diag_module_disabled('bulk_edit')) {
                modir_bulk_edit_init();
            }
            if (!modir_diag_module_disabled('label')) {
                modir_label_init();
            }
            if (!modir_diag_module_disabled('pl')) {
                modir_pl_init();
            }
            if (!modir_diag_module_disabled('warehouse')) {
                modir_warehouse_init();
            }
            if (!modir_diag_module_disabled('expenses')) {
                modir_expenses_init();
            }
            if (!modir_diag_module_disabled('products')) {
                modir_products_init();
            }
            if (!modir_diag_module_disabled('price_calc')) {
                modir_price_calc_init();
            }
            if (!modir_diag_module_disabled('orders_list')) {
                modir_orders_list_init();
            }
            if (!modir_diag_module_disabled('dashboard')) {
                modir_dashboard_init();
            }
            if (!modir_diag_module_disabled('suppliers')) {
                modir_suppliers_init();
            }
        }
    }
});

register_activation_hook(__FILE__, 'modir_license_on_activate_bootstrap');
function modir_license_on_activate_bootstrap() {
    if (false === get_option('modir_license_unlocked', false)) {
        add_option('modir_license_unlocked', 0);
    }
}

/**
 * ثبت منوهای مدیریت
 */
function modir_register_admin_menu() {
    $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"><defs><linearGradient id="modirMenuIconGrad" x1="0" y1="0" x2="20" y2="20"><stop offset="0%" stop-color="#22c55e"/><stop offset="100%" stop-color="#2563eb"/></linearGradient></defs><circle cx="10" cy="10" r="9.2" fill="url(#modirMenuIconGrad)"/><path d="M4.2 10.3h2.1l1.4-4.3 2.6 8.2 1.4-4.3h4.1" fill="none" stroke="#ffffff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    $icon_url = 'data:image/svg+xml;base64,' . base64_encode($icon_svg);

    add_menu_page(
        'مدیر',
        'مدیر',
        'manage_woocommerce',
        'modir',
        '', // خالی عمداً؛ کال‌بک واقعی از طریق زیرمنوی هم‌نام «حسابداری» پایین ثبت می‌شود (وگرنه صفحه دوبار رندر می‌شود)
        $icon_url,
        56
    );

    // فقط «محصولات» زیرمنوی واقعی «مدیر» است (هم‌نام با اسلاگ والد)، به همین
    // خاطر وردپرس به‌جای نمایش لیست بازشو، مستقیم با یک کلیک وارد آن می‌شود —
    // این یعنی محصولات همیشه اولین و پیش‌فرض‌ترین صفحه‌ای است که با کلیک
    // روی «مدیر» باز می‌شود.
    add_submenu_page('modir', 'محصولات', 'محصولات', 'manage_woocommerce', 'modir', modir_license_gate('modir_render_products_page'));

    // بقیه صفحات به‌صورت «صفحه پنهان» (بدون parent) ثبت می‌شوند: کاملاً فعال و
    // با بررسی سطح دسترسی صحیح، ولی نه در سایدبار و نه زیر «مدیر» ظاهر
    // نمی‌شوند. دسترسی به همه‌شان از طریق نوار تب داخل صفحه تنظیماته.
    $hidden_pages = array(
        'modir-accounting'     => 'modir_render_reports_page',
        'modir-settings'       => 'modir_render_settings_page',
        'modir-notifbar'       => 'modir_render_notifbar_page',
        'modir-popup'          => 'modir_render_popup_page',
        'modir-chat'           => 'modir_render_chat_page',
        'modir-returns-admin'  => 'modir_render_returns_page',
        'modir-menu'           => 'modir_render_menu_page',
        'modir-import'         => 'modir_render_import_page',
        'modir-shipping'       => 'modir_render_shipping_page',
        'modir-bulk-edit'      => 'modir_render_bulk_edit_page',
        'modir-order-statuses' => 'modir_render_order_statuses_page',
        'modir-label'          => 'modir_render_label_page',
        'modir-tracking'       => 'modir_render_tracking_page',
        'modir-pl'             => 'modir_render_pl_page',
        'modir-orders'         => 'modir_render_orders_list_page',
        'modir-dashboard'      => 'modir_render_dashboard_page',
        'modir-marketing'      => 'modir_render_marketing_page',
    );
    foreach ($hidden_pages as $slug => $callback) {
        add_submenu_page(null, $slug, $slug, 'manage_woocommerce', $slug, modir_license_gate($callback));
    }
}

/**
 * بارگذاری استایل و اسکریپت فقط در صفحات افزونه مدیر
 */
function modir_enqueue_admin_assets($hook) {
    if (strpos($hook, 'modir') === false) {
        return;
    }

    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');

    wp_enqueue_style('modir-admin-css', MODIR_URL . 'assets/css/admin.css', array(), MODIR_VERSION);
    wp_enqueue_script('modir-admin-js', MODIR_URL . 'assets/js/admin.js', array('jquery', 'wp-color-picker'), MODIR_VERSION, true);

    wp_enqueue_style('modir-jdp-css', MODIR_URL . 'assets/css/jalali-datepicker.css', array(), MODIR_VERSION);
    wp_enqueue_script('modir-jdp-js', MODIR_URL . 'assets/js/jalali-datepicker.js', array(), MODIR_VERSION, true);

    $color = modir_get_primary_color();
    $custom_css = ":root{--modir-primary: {$color};}";
    wp_add_inline_style('modir-admin-css', $custom_css);

    wp_localize_script('modir-admin-js', 'ModirHeader', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('modir_header_nonce'),
    ));
}
