<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * صفحه تنظیمات افزونه — همه گزینه‌ها با یک فرم و یک نانس واحد ذخیره می‌شوند
 * (نه از طریق Settings API/options.php وردپرس، بلکه با همون الگوی مطمئنی
 * که در بقیه ماژول‌های افزونه استفاده شده، تا هیچ داده‌ای گم نشود).
 * ==========================================================================
 */

function modir_allow_font_uploads($mimes) {
    $mimes['woff']  = 'application/font-woff';
    $mimes['woff2'] = 'application/font-woff2';
    $mimes['ttf']   = 'application/font-sfnt';
    $mimes['otf']   = 'application/font-sfnt';
    return $mimes;
}
add_filter('upload_mimes', 'modir_allow_font_uploads');

/* -------------------------------------------------------------------- */
/* هدر بالای صفحه (طرح یکپارچه جدید): آیکون + عنوان + انتخاب رنگ + حالت تیره */
/* -------------------------------------------------------------------- */

function modir_color_presets() {
    return array('#16a34a', '#2563eb', '#dc2626', '#9333ea', '#ea580c', '#0891b2', '#db2777');
}

function modir_get_ui_theme() {
    $theme = get_user_meta(get_current_user_id(), 'modir_ui_theme', true);
    return ('dark' === $theme) ? 'dark' : 'light';
}

add_filter('admin_body_class', function ($classes) {
    if ('dark' === modir_get_ui_theme()) {
        $classes .= ' modir-dark-mode';
    }
    return $classes;
});

add_action('wp_ajax_modir_set_theme', function () {
    check_ajax_referer('modir_header_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error();
    }
    $theme = ('dark' === sanitize_text_field(wp_unslash($_POST['theme'] ?? ''))) ? 'dark' : 'light';
    update_user_meta(get_current_user_id(), 'modir_ui_theme', $theme);
    wp_send_json_success();
});

add_action('wp_ajax_modir_set_color', function () {
    check_ajax_referer('modir_header_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error();
    }
    $color = sanitize_hex_color(wp_unslash($_POST['color'] ?? ''));
    if (!$color) {
        wp_send_json_error();
    }
    $options = get_option('modir_options', array());
    $options['primary_color'] = $color;
    update_option('modir_options', $options);
    wp_send_json_success();
});

/**
 * هدر یکپارچه بالای هر صفحه افزونه — آیکون، عنوان، زیرنویس، دکمه انتخاب رنگ،
 * و دکمه تعویض حالت روشن/تیره. همه صفحات افزونه از همین تابع استفاده می‌کنند.
 */
function modir_render_top_header($icon, $title, $subtitle = '') {
    $color = modir_get_primary_color();
    $theme = modir_get_ui_theme();
    ?>
    <div class="modir-top-header">
        <div class="modir-top-header-right">
            <div class="modir-top-header-icon"><span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span></div>
            <div>
                <p class="modir-top-header-title"><?php echo esc_html($title); ?></p>
                <?php if ($subtitle) : ?><p class="modir-top-header-subtitle"><?php echo esc_html($subtitle); ?></p><?php endif; ?>
            </div>
        </div>
        <div class="modir-top-header-controls">
            <div class="modir-color-swatch-wrap">
                <button type="button" class="modir-color-swatch-btn" id="modir-color-swatch-btn" title="تغییر رنگ اصلی">
                    <span class="modir-color-swatch-dot" style="background:<?php echo esc_attr($color); ?>;"></span>
                </button>
                <div class="modir-color-swatch-popup" id="modir-color-swatch-popup">
                    <?php foreach (modir_color_presets() as $c) : ?>
                        <button type="button" class="modir-color-preset" data-color="<?php echo esc_attr($c); ?>" style="background:<?php echo esc_attr($c); ?>;"></button>
                    <?php endforeach; ?>
                    <label class="modir-color-custom-label">
                        <input type="color" id="modir-color-custom" value="<?php echo esc_attr($color); ?>">
                        سفارشی
                    </label>
                </div>
            </div>
            <button type="button" class="modir-theme-toggle-btn" id="modir-theme-toggle-btn" data-theme="<?php echo esc_attr($theme); ?>" title="تغییر حالت روشن/تیره">
                <span class="dashicons dashicons-lightbulb modir-theme-icon-light"></span>
                <span class="modir-theme-icon-dark"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 1020.354 15.354z"/></svg></span>
            </button>
            <button type="button" class="modir-theme-toggle-btn" id="modir-fullscreen-btn" title="تمام‌صفحه (مخفی‌کردن منوی وردپرس)">
                <span class="dashicons dashicons-editor-expand modir-fullscreen-icon-off"></span>
                <span class="dashicons dashicons-editor-contract modir-fullscreen-icon-on"></span>
            </button>
        </div>
    </div>
    <?php
}

/**
 * نوار تب اصلی و سطح‌بالا — فقط ۶ گروه منطقی، هرکدام با آیکون اختصاصی خودش.
 * زیرمجموعه‌های هر بخش (مثلاً افزودن محصول زیر محصولات) با نوار تب دومی
 * داخل همان صفحه نمایش داده می‌شوند (به تابع modir_render_sub_tabs_nav نگاه کن).
 */
function modir_settings_tabs_nav($current_slug) {
    $groups = array(
        'modir-dashboard'  => array('dashboard', 'داشبورد'),
        'modir'            => array('cart', 'محصولات'),
        'modir-orders'     => array('clipboard', 'سفارشات'),
        'modir-accounting' => array('chart-area', 'حسابداری'),
        'modir-marketing'  => array('megaphone', 'بازاریابی'),
        'modir-settings'   => array('admin-generic', 'تنظیمات'),
    );

    $pending_support = 0;
    if (function_exists('get_option')) {
        foreach (get_option('modir_chat_log', array()) as $row) {
            if (!empty($row['needs_support']) && empty($row['resolved'])) {
                $pending_support++;
            }
        }
    }
    ?>
    <div class="modir-tabs-nav-wrap">
        <button type="button" class="modir-tabs-nav-arrow" data-scroll-dir="1" aria-label="نمایش تب‌های راست‌تر">
            <span class="dashicons dashicons-arrow-right-alt2"></span>
        </button>
        <nav class="nav-tab-wrapper modir-tabs modir-settings-tabs modir-scroll-rail modir-tabs-main">
            <?php foreach ($groups as $slug => $meta) :
                $url    = add_query_arg(array('page' => $slug), admin_url('admin.php'));
                $active = ($current_slug === $slug) ? ' nav-tab-active' : '';
                ?>
                <a href="<?php echo esc_url($url); ?>" class="nav-tab<?php echo esc_attr($active); ?>">
                    <span class="dashicons dashicons-<?php echo esc_attr($meta[0]); ?>"></span>
                    <?php echo esc_html($meta[1]); ?>
                    <?php if ('modir-marketing' === $slug && $pending_support > 0) : ?>
                        <span class="modir-nav-badge"><?php echo number_format_i18n($pending_support); ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <button type="button" class="modir-tabs-nav-arrow" data-scroll-dir="-1" aria-label="نمایش تب‌های چپ‌تر">
            <span class="dashicons dashicons-arrow-left-alt2"></span>
        </button>
    </div>
    <?php
}

/**
 * نوار تب دومی (زیرمجموعه‌های داخلی هر بخش اصلی) — مثلاً داخل «محصولات»:
 * محصولات / افزودن محصول / ویرایش دسته‌جمعی / پاپ‌آپ محصول.
 */
function modir_render_sub_tabs_nav($base_page, $tabs, $current_tab) {
    ?>
    <div class="modir-tabs-nav-wrap modir-sub-tabs-wrap">
        <button type="button" class="modir-tabs-nav-arrow" data-scroll-dir="1" aria-label="نمایش تب‌های راست‌تر">
            <span class="dashicons dashicons-arrow-right-alt2"></span>
        </button>
        <nav class="nav-tab-wrapper modir-tabs modir-scroll-rail">
            <?php foreach ($tabs as $key => $label) :
                $url    = add_query_arg(array('page' => $base_page, 'tab' => $key), admin_url('admin.php'));
                $active = ($current_tab === $key) ? ' nav-tab-active' : '';
                ?>
                <a href="<?php echo esc_url($url); ?>" class="nav-tab<?php echo esc_attr($active); ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </nav>
        <button type="button" class="modir-tabs-nav-arrow" data-scroll-dir="-1" aria-label="نمایش تب‌های چپ‌تر">
            <span class="dashicons dashicons-arrow-left-alt2"></span>
        </button>
    </div>
    <?php
}

function modir_font_get_settings() {
    return wp_parse_args(get_option('modir_font_settings', array()), array('choice' => 'default', 'custom_url' => ''));
}

/**
 * اعمال فونت انتخابی روی کل پیشخوان وردپرس (همه صفحات ادمین).
 * عمداً فقط font-family تغییر می‌کند، نه اندازه/چیدمان/رنگ — تا هیچ ریسکی
 * برای از کار افتادن ابزارهای دیگر ایجاد نشود.
 */
function modir_font_enqueue() {
    $f = modir_font_get_settings();
    if ('default' === $f['choice']) {
        return;
    }

    $font_face_css = '';
    $family        = '';
    $handle        = '';

    if ('vazirmatn' === $f['choice']) {
        wp_enqueue_style('modir-font-vazirmatn', 'https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css', array(), null);
        $family = 'Vazirmatn';
        $handle = 'modir-font-vazirmatn';
    } elseif ('custom' === $f['choice'] && !empty($f['custom_url'])) {
        $family        = 'ModirCustomFont';
        $font_face_css = "@font-face{font-family:'ModirCustomFont';src:url('" . esc_url($f['custom_url']) . "');font-weight:normal;font-style:normal;font-display:swap;}";
        wp_register_style('modir-font-custom', false, array(), MODIR_VERSION);
        wp_enqueue_style('modir-font-custom');
        $handle = 'modir-font-custom';
    } else {
        return;
    }

    $apply_css = "body, #wpcontent, #wpbody-content, .wrap, input, select, textarea, button, .button, .wp-list-table, #adminmenu, #adminmenu a, #wpadminbar, #wpadminbar *, .postbox { font-family: '{$family}', -apple-system, Tahoma, sans-serif !important; }";

    wp_add_inline_style($handle, $font_face_css . $apply_css);
}
add_action('admin_enqueue_scripts', 'modir_font_enqueue');

/* -------------------------------------------------------------------- */
/* ذخیره همه تنظیمات با یک فرم                                           */
/* -------------------------------------------------------------------- */

function modir_settings_maybe_save() {
    if (function_exists('modir_license_is_unlocked') && !modir_license_is_unlocked()) {
        return;
    }
    if (empty($_POST['modir_settings_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_settings_nonce'])), 'modir_save_settings')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $input = wp_unslash($_POST);

    /* ابزارک‌های پیشخوان */
    $w_in  = isset($input['modir_widgets_settings']) && is_array($input['modir_widgets_settings']) ? $input['modir_widgets_settings'] : array();
    $w_out = array();
    foreach (array('today_sales', 'month_sales', 'processing_orders', 'status_counts', 'returns_stats', 'returns_recent', 'chat_support', 'chat_overview') as $key) {
        $w_out[$key] = !empty($w_in[$key]) ? 1 : 0;
    }
    $w_out['processing_status']  = isset($w_in['processing_status']) ? sanitize_key($w_in['processing_status']) : 'processing';
    $w_out['status_counts_list'] = !empty($w_in['status_counts_list']) && is_array($w_in['status_counts_list'])
        ? array_values(array_map('sanitize_key', $w_in['status_counts_list']))
        : array();
    update_option('modir_widgets_settings', $w_out);

    /* فونت */
    $f_in     = isset($input['modir_font_settings']) && is_array($input['modir_font_settings']) ? $input['modir_font_settings'] : array();
    $f_choice = isset($f_in['choice']) && in_array($f_in['choice'], array('default', 'vazirmatn', 'custom'), true) ? $f_in['choice'] : 'default';
    update_option('modir_font_settings', array(
        'choice'     => $f_choice,
        'custom_url' => isset($f_in['custom_url']) ? esc_url_raw($f_in['custom_url']) : '',
    ));

    /* سفارش‌های حرفه‌ای + کارمزد هر درگاه */
    $o_in          = isset($input['modir_orders_settings']) && is_array($input['modir_orders_settings']) ? $input['modir_orders_settings'] : array();
    $o_commissions = array();
    if (!empty($o_in['commissions']) && is_array($o_in['commissions'])) {
        foreach ($o_in['commissions'] as $gateway_id => $vals) {
            $percent = isset($vals['percent']) ? (float) $vals['percent'] : 0;
            $fixed   = isset($vals['fixed']) ? (float) $vals['fixed'] : 0;
            $months  = isset($vals['settlement_months']) ? max(1, min(12, absint($vals['settlement_months']))) : 1;
            // توجه: عمداً sanitize_key استفاده نمی‌شود چون حروف بزرگ رو کوچک و
            // برخی کاراکترها رو حذف می‌کند؛ خیلی از افزونه‌های درگاه پرداخت
            // ایرانی (زرین‌پال، آیدی‌پی و ...) آی‌دی‌های حروف بزرگ/کوچک دارند
            // و این باعث می‌شد مقدار ذخیره‌شده هیچ‌وقت پیدا نشه و صفر دیده بشه.
            $clean_id = sanitize_text_field($gateway_id);
            if ($clean_id && ($percent > 0 || $fixed > 0 || $months > 1)) {
                $o_commissions[$clean_id] = array('percent' => $percent, 'fixed' => $fixed, 'settlement_months' => $months);
            }
        }
    }
    update_option('modir_orders_settings', array(
        'enabled'     => !empty($o_in['enabled']) ? 1 : 0,
        'commissions' => $o_commissions,
    ));

    /* وضعیت‌های «پرداخت موفق» برای گزارش‌ها و آمار */
    $paid_in = isset($input['modir_paid_statuses_list']) && is_array($input['modir_paid_statuses_list'])
        ? array_values(array_map('sanitize_key', $input['modir_paid_statuses_list']))
        : array();
    update_option('modir_paid_statuses_list', $paid_in);

    /* قیمت پیش‌فرض ارسال */
    update_option('modir_default_shipping_cost', isset($input['modir_default_shipping_cost']) ? (float) $input['modir_default_shipping_cost'] : 180000);

    /* پنهان‌کردن تنوع‌های ناموجود */
    update_option('modir_hide_oos_variations', !empty($input['modir_hide_oos_variations']) ? 1 : 0);

    /* تشخیص VPN */
    $v_in = isset($input['modir_vpn_settings']) && is_array($input['modir_vpn_settings']) ? $input['modir_vpn_settings'] : array();
    update_option('modir_vpn_settings', array(
        'enabled' => !empty($v_in['enabled']) ? 1 : 0,
        'message' => isset($v_in['message']) ? sanitize_textarea_field($v_in['message']) : '',
    ));

    wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'saved' => '1'), admin_url('admin.php')));
    exit;
}
add_action('admin_init', 'modir_settings_maybe_save');

/* ======================================================================
 * صفحه ادمین
 * ====================================================================== */

function modir_settings_render_general_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $w                 = function_exists('modir_widgets_get_settings') ? modir_widgets_get_settings() : array();
    $all_statuses      = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array();
    $selected_statuses = !empty($w['status_counts_list']) ? $w['status_counts_list'] : array();

    $font = modir_font_get_settings();

    $os       = function_exists('modir_orders_get_settings') ? modir_orders_get_settings() : array('enabled' => 0, 'commissions' => array());
    $gateways = function_exists('modir_orders_get_all_gateway_options') ? modir_orders_get_all_gateway_options() : array();

    $vpn = function_exists('modir_vpn_get_settings') ? modir_vpn_get_settings() : array('enabled' => 0, 'message' => '');
    $default_shipping_cost = function_exists('modir_get_default_shipping_cost') ? modir_get_default_shipping_cost() : 180000;
    $hide_oos_variations   = get_option('modir_hide_oos_variations', 0);
    ?>
        <?php if (isset($_GET['saved'])) : ?>
            <div class="notice notice-success is-dismissible"><p>همه تنظیمات با موفقیت ذخیره شد.</p></div>
        <?php endif; ?>

        <form method="post" class="modir-filter-form modir-settings-form">
            <?php wp_nonce_field('modir_save_settings', 'modir_settings_nonce'); ?>

            <h2 class="modir-subtitle">وضعیت‌های «پرداخت موفق»</h2>
            <p class="description">وضعیت‌هایی که به‌عنوان «فروش موفق» در گزارش‌ها و آمارها محاسبه می‌شوند.</p>
            <table class="form-table">
                <tr>
                    <th scope="row">وضعیت‌های موفق</th>
                    <td>
                        <div class="modir-status-checkbox-grid">
                            <?php
                            $paid_selected = modir_get_paid_statuses_for_settings_display();
                            foreach ($all_statuses as $key => $label) :
                                $slug = str_replace('wc-', '', $key);
                                ?>
                                <label>
                                    <input type="checkbox" name="modir_paid_statuses_list[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $paid_selected, true)); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description">این لیست شامل هر وضعیت سفارش سفارشی‌ای هم می‌شود که در سایتتان تعریف شده باشد؛ همیشه دقیق‌ترین لیست موجود روی سایت شماست.</p>
                    </td>
                </tr>
            </table>

            <h2 class="modir-subtitle">نمایش محصولات متغیر</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">پنهان‌کردن تنوع‌های ناموجود</th>
                    <td>
                        <label class="modir-toggle">
                            <input type="checkbox" name="modir_hide_oos_variations" value="1" <?php checked(!empty($hide_oos_variations)); ?>>
                            در صفحه محصول، تنوع‌هایی (مثل رنگ یا سایز) که موجودی‌شان تمام شده، اصلاً به مشتری نمایش داده نشود
                        </label>
                    </td>
                </tr>
            </table>

            <h2 class="modir-subtitle">قیمت پیش‌فرض ارسال</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">هزینه ارسال هر واحد کالا</th>
                    <td>
                        <input type="number" step="0.01" min="0" name="modir_default_shipping_cost" value="<?php echo esc_attr($default_shipping_cost); ?>" style="width:160px;">
                        <p class="description">این عدد به‌صورت پیش‌فرض در ستون «ارسال (واحد)» تب سود و زیان برای محصولات جدید استفاده می‌شود؛ برای هر محصول همیشه قابل تغییر جداگانه است.</p>
                    </td>
                </tr>
            </table>

            <h2 class="modir-subtitle">ابزارک‌های پیشخوان وردپرس</h2>
            <p class="description">هرکدام از این بخش‌ها را که می‌خواهید در صفحه اصلی پیشخوان (Dashboard) نمایش داده شود، فعال کنید.</p>

            <table class="form-table">
                <tr>
                    <th scope="row">ابزارک‌های فعال</th>
                    <td>
                        <label class="modir-toggle" style="margin-bottom:8px;">
                            <input type="checkbox" name="modir_widgets_settings[today_sales]" value="1" <?php checked(!empty($w['today_sales'])); ?>>
                            آمار فروش امروز
                        </label><br>
                        <label class="modir-toggle" style="margin-bottom:8px;">
                            <input type="checkbox" name="modir_widgets_settings[month_sales]" value="1" <?php checked(!empty($w['month_sales'])); ?>>
                            فروش ماه جاری (شمسی)
                        </label><br>
                        <label class="modir-toggle" style="margin-bottom:8px;">
                            <input type="checkbox" name="modir_widgets_settings[processing_orders]" value="1" <?php checked(!empty($w['processing_orders'])); ?>>
                            سفارش‌های در حال انجام (تصویر + نام + مبلغ)
                        </label><br>
                        <label class="modir-toggle" style="margin-bottom:8px;">
                            <input type="checkbox" name="modir_widgets_settings[status_counts]" value="1" <?php checked(!empty($w['status_counts'])); ?>>
                            تعداد سفارش‌ها بر اساس وضعیت
                        </label><br>
                        <label class="modir-toggle" style="margin-bottom:8px;">
                            <input type="checkbox" name="modir_widgets_settings[returns_stats]" value="1" <?php checked(!empty($w['returns_stats'])); ?>>
                            آمار مرجوعی‌ها
                        </label><br>
                        <label class="modir-toggle" style="margin-bottom:8px;">
                            <input type="checkbox" name="modir_widgets_settings[returns_recent]" value="1" <?php checked(!empty($w['returns_recent'])); ?>>
                            آخرین درخواست‌های مرجوعی
                        </label><br>
                        <label class="modir-toggle" style="margin-bottom:8px;">
                            <input type="checkbox" name="modir_widgets_settings[chat_support]" value="1" <?php checked(!empty($w['chat_support'])); ?>>
                            درخواست‌های تماس از دستیار گفتگو
                        </label><br>
                        <label class="modir-toggle">
                            <input type="checkbox" name="modir_widgets_settings[chat_overview]" value="1" <?php checked(!empty($w['chat_overview'])); ?>>
                            گزارش کلی دستیار گفتگو
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row">وضعیت «در حال انجام»</th>
                    <td>
                        <select name="modir_widgets_settings[processing_status]">
                            <?php foreach ($all_statuses as $key => $label) :
                                $slug = str_replace('wc-', '', $key);
                                ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php selected(!empty($w['processing_status']) ? $w['processing_status'] : 'processing', $slug); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">ابزارک «سفارش‌های در حال انجام» بر اساس همین وضعیت لیست را نمایش می‌دهد. اگر سایتتان وضعیت سفارش سفارشی/جدیدی دارد، از همین‌جا انتخابش کنید.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">وضعیت‌های نمایشی در ابزارک «تعداد سفارش‌ها»</th>
                    <td>
                        <div class="modir-status-checkbox-grid">
                            <?php foreach ($all_statuses as $key => $label) :
                                $slug = str_replace('wc-', '', $key);
                                ?>
                                <label>
                                    <input type="checkbox" name="modir_widgets_settings[status_counts_list][]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $selected_statuses, true)); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description">فقط وضعیت‌هایی که تیک بخورند در ابزارک «تعداد سفارش‌ها بر اساس وضعیت» نمایش داده می‌شوند. این لیست شامل وضعیت‌های سفارشیِ افزوده‌شده توسط سایر افزونه‌ها هم می‌شود.</p>
                    </td>
                </tr>
            </table>

            <h2 class="modir-subtitle">فونت پیشخوان وردپرس</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">فونت</th>
                    <td>
                        <label style="display:block;margin-bottom:6px;"><input type="radio" name="modir_font_settings[choice]" value="default" <?php checked($font['choice'], 'default'); ?>> فونت پیش‌فرض وردپرس</label>
                        <label style="display:block;margin-bottom:6px;"><input type="radio" name="modir_font_settings[choice]" value="vazirmatn" <?php checked($font['choice'], 'vazirmatn'); ?>> فونت وزیرمتن (Vazirmatn) — رایگان و متن‌باز</label>
                        <label style="display:block;margin-bottom:6px;"><input type="radio" name="modir_font_settings[choice]" value="custom" <?php checked($font['choice'], 'custom'); ?>> فونت دلخواه (مثلاً یکان، اگر مجوزش رو داری)</label>
                        <input type="url" name="modir_font_settings[custom_url]" class="regular-text" value="<?php echo esc_attr($font['custom_url']); ?>" placeholder="https://example.com/fonts/yekan.woff2" style="margin-inline-start:24px;">
                        <p class="description" style="margin-inline-start:24px;">فایل فونت (woff2/woff/ttf/otf) رو از پیشخوان ← رسانه‌ها آپلود کن، لینکش رو کپی و اینجا بچسبون.</p>
                    </td>
                </tr>
            </table>

            <h2 class="modir-subtitle">سفارش‌های حرفه‌ای</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">فعال‌سازی</th>
                    <td>
                        <label class="modir-toggle">
                            <input type="checkbox" name="modir_orders_settings[enabled]" value="1" <?php checked(!empty($os['enabled'])); ?>>
                            ستون تصویر محصول، درگاه پرداخت، خالص پس از کارمزد، و فیلتر روش حمل‌ونقل/درگاه در صفحه سفارش‌های ووکامرس اضافه شود
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">درصد و مبلغ ثابت کارمزد هر درگاه</th>
                    <td>
                        <?php if (empty($gateways)) : ?>
                            <p class="description">هیچ درگاه پرداخت فعالی در ووکامرس پیدا نشد.</p>
                        <?php else : ?>
                            <table class="modir-commission-table">
                                <thead>
                                    <tr><th>درگاه</th><th>درصد کارمزد (%)</th><th>مبلغ ثابت کارمزد</th><th>دوره تسویه (ماه)</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($gateways as $id => $title) :
                                        $c = function_exists('modir_orders_get_commission') ? modir_orders_get_commission($id) : array('percent' => 0, 'fixed' => 0, 'settlement_months' => 1);
                                        ?>
                                        <tr>
                                            <td><?php echo esc_html($title); ?></td>
                                            <td><input type="number" step="0.01" min="0" max="100" name="modir_orders_settings[commissions][<?php echo esc_attr($id); ?>][percent]" value="<?php echo esc_attr($c['percent']); ?>" style="width:90px;"></td>
                                            <td><input type="number" step="0.01" min="0" name="modir_orders_settings[commissions][<?php echo esc_attr($id); ?>][fixed]" value="<?php echo esc_attr($c['fixed']); ?>" style="width:110px;"></td>
                                            <td><input type="number" step="1" min="1" max="12" name="modir_orders_settings[commissions][<?php echo esc_attr($id); ?>][settlement_months]" value="<?php echo esc_attr($c['settlement_months'] ?? 1); ?>" style="width:70px;"></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p class="description">هزینه نهایی هر سفارش = (درصد × مبلغ کل) + مبلغ ثابت. دوره تسویه یعنی درگاه هر ماه چند ماه رو باهم واریز می‌کنه (مثلاً ۲ یعنی نصف این ماه + نصف ماه قبل) — تو تب «درگاه‌ها» بر اساسش محاسبه می‌شه.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php
            $backfill_url = wp_nonce_url(add_query_arg(array('page' => 'modir-settings', 'modir_orders_action' => 'backfill'), admin_url('admin.php')), 'modir_orders_backfill');
            if (isset($_GET['orders_synced'])) {
                echo '<div class="notice notice-success inline"><p>متادیتای روش حمل‌ونقل برای ' . (int) $_GET['orders_synced'] . ' سفارش اخیر به‌روزرسانی شد.</p></div>';
            }
            ?>
            <p>
                <a href="<?php echo esc_url($backfill_url); ?>" class="button modir-btn">همگام‌سازی سفارش‌های قدیمی برای فیلتر حمل‌ونقل</a>
                <span class="description" style="display:block;margin-top:6px;">برای سفارش‌های قدیمی، جهت فعال شدن فیلتر روش حمل‌ونقل.</span>
            </p>

            <h2 class="modir-subtitle">تشخیص VPN</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">فعال‌سازی</th>
                    <td>
                        <label class="modir-toggle">
                            <input type="checkbox" name="modir_vpn_settings[enabled]" value="1" <?php checked(!empty($vpn['enabled'])); ?>>
                            به بازدیدکنندگانی که با VPN/فیلترشکن وارد سایت می‌شوند، یک پاپ‌آپ نمایش داده شود
                        </label>
                        <p class="description">تشخیص بر پایه سرویس آزمایشی/رایگان ip-api.com انجام می‌شود و ۱۰۰٪ دقیق نیست؛ گاهی شبکه‌های سازمانی هم به‌اشتباه تشخیص داده می‌شوند.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">متن پیام</th>
                    <td><textarea name="modir_vpn_settings[message]" rows="3" class="large-text"><?php echo esc_textarea($vpn['message']); ?></textarea></td>
                </tr>
            </table>

            <?php submit_button('ذخیره تنظیمات', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
        </form>

        <?php if (function_exists('modir_license_is_unlocked') && modir_license_is_unlocked()) :
            $relock_url = wp_nonce_url(add_query_arg(array('page' => 'modir', 'modir_license_action' => 'relock'), admin_url('admin.php')), 'modir_license_relock');
            ?>
            <p style="margin-top:24px;">
                <a href="<?php echo esc_url($relock_url); ?>" class="modir-link-danger" onclick="return confirm('افزونه دوباره قفل شود و رمز فعال‌سازی دوباره خواسته شود؟');">قفل کردن مجدد افزونه</a>
            </p>
        <?php endif; ?>
    <?php
}

function modir_render_settings_page() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $sub_tabs = array(
        'general'     => 'عمومی',
        'menu'        => 'مدیریت منو',
        'shipping'    => 'حمل و نقل پیشرفته',
        'wholesale'   => 'عمده‌فروشی',
        'extra_fees'  => 'هزینه‌های اضافی',
        'css'         => 'ویرایشگر CSS',
        'diagnostics' => 'عیب‌یابی',
    );
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
    if (!isset($sub_tabs[$tab])) {
        $tab = 'general';
    }
    ?>
    <div class="wrap modir-wrap">
        <?php modir_render_top_header('admin-generic', 'تنظیمات', 'تنظیمات عمومی، مدیریت منو، و حمل‌ونقل پیشرفته'); ?>
        <?php modir_settings_tabs_nav('modir-settings'); ?>
        <?php modir_render_sub_tabs_nav('modir-settings', $sub_tabs, $tab); ?>

        <div class="modir-tab-panel">
            <?php
            switch ($tab) {
                case 'menu':
                    modir_menu_render_content();
                    break;
                case 'shipping':
                    modir_shipping_render_content();
                    break;
                case 'wholesale':
                    modir_wholesale_render_content();
                    break;
                case 'extra_fees':
                    modir_extra_fees_render_content();
                    break;
                case 'css':
                    modir_custom_css_render_content();
                    break;
                case 'diagnostics':
                    modir_diagnostics_render_content();
                    break;
                default:
                    modir_settings_render_general_content();
                    break;
            }
            ?>
        </div>
    </div>
    <?php
}
