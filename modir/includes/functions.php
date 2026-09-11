<?php
if (!defined('ABSPATH')) exit;

/**
 * دریافت بازه تاریخ از فیلتر کاربر (به شمسی) یا مقدار پیش‌فرض (اول ماه شمسی جاری تا امروز).
 * خروجی 'from'/'to' همیشه میلادی (Y-m-d) برای استفاده در کوئری ووکامرس است؛
 * 'from_jalali'/'to_jalali' برای نمایش در فیلد ورودی شمسی است.
 */
function modir_get_date_range() {
    list($def_start_mysql, $def_end_mysql) = modir_jalali_month_gregorian_range();
    $default_from = substr($def_start_mysql, 0, 10);
    $default_to   = substr($def_end_mysql, 0, 10);

    $from = $default_from;
    $to   = $default_to;

    if (!empty($_GET['date_from'])) {
        $parsed = modir_parse_jalali_date(sanitize_text_field(wp_unslash($_GET['date_from'])));
        if ($parsed) {
            $from = $parsed;
        }
    }
    if (!empty($_GET['date_to'])) {
        $parsed = modir_parse_jalali_date(sanitize_text_field(wp_unslash($_GET['date_to'])));
        if ($parsed) {
            $to = $parsed;
        }
    }

    if (strtotime($from) > strtotime($to)) {
        list($from, $to) = array($to, $from);
    }

    return array(
        'from'        => $from,
        'to'          => $to,
        'from_jalali' => modir_gregorian_to_jalali_string($from),
        'to_jalali'   => modir_gregorian_to_jalali_string($to),
    );
}

function modir_is_valid_date($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

/**
 * لیست وضعیت‌های سفارش که به عنوان "پرداخت موفق" در نظر گرفته می‌شوند.
 * این لیست از تنظیمات افزونه خوانده می‌شود تا وضعیت‌های سفارشی/شخصی‌سازی‌شده
 * سایت هم لحاظ شوند؛ اگر هنوز تنظیم نشده باشد، پیش‌فرض خود ووکامرس استفاده می‌شود.
 */
function modir_get_default_paid_statuses() {
    $custom = get_option('modir_paid_statuses_list', null);
    if (is_array($custom) && !empty($custom)) {
        return apply_filters('modir_paid_statuses', $custom);
    }
    return apply_filters('modir_paid_statuses', wc_get_is_paid_statuses());
}

/**
 * مقدار پیش‌فرض/فعلیِ چک‌باکس‌های «پرداخت موفق» برای نمایش در صفحه تنظیمات.
 */
function modir_get_paid_statuses_for_settings_display() {
    $saved = get_option('modir_paid_statuses_list', null);
    if (is_array($saved)) {
        return $saved;
    }
    return array_values(wc_get_is_paid_statuses());
}

/**
 * رندر باکس فیلتر تاریخ مشترک بین گزارش‌ها (به همراه فیلدهای اضافی اختیاری)
 * $page_slug: اسلاگ صفحه ادمین
 * $extra_html: فیلدهای اضافی (select درگاه پرداخت و ...)
 * $tab: در صورتی که گزارش داخل یک صفحه‌ی تب‌دار باشد، نام تب فعلی
 */
function modir_render_date_filter($page_slug, $extra_html = '', $tab = '') {
    $range = modir_get_date_range();
    ?>
    <form method="get" class="modir-filter-form">
        <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
        <?php if (!empty($tab)) : ?>
            <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
        <?php endif; ?>
        <div class="modir-filter-row">
            <div class="modir-filter-field">
                <label>از تاریخ (شمسی)</label>
                <input type="text" name="date_from" value="<?php echo esc_attr($range['from_jalali']); ?>" placeholder="۱۴۰۴/۰۵/۰۱" class="modir-jalali-date-input" dir="ltr" inputmode="numeric">
            </div>
            <div class="modir-filter-field">
                <label>تا تاریخ (شمسی)</label>
                <input type="text" name="date_to" value="<?php echo esc_attr($range['to_jalali']); ?>" placeholder="۱۴۰۴/۰۵/۱۴" class="modir-jalali-date-input" dir="ltr" inputmode="numeric">
            </div>
            <?php echo $extra_html; // phpcs:ignore ?>
            <div class="modir-filter-field modir-filter-submit">
                <button type="submit" class="button button-primary modir-btn">اعمال فیلتر</button>
            </div>
        </div>
    </form>
    <?php
}

/**
 * دریافت رنگ اصلی انتخاب شده در تنظیمات
 */
function modir_get_primary_color() {
    $options = get_option('modir_options', array());
    return !empty($options['primary_color']) ? $options['primary_color'] : '#16a34a';
}

/* -------------------------------------------------------------------- */
/* پنهان‌کردن تنوع‌های ناموجود از فرانت سایت                                */
/* -------------------------------------------------------------------- */

function modir_variations_init() {
    add_filter('woocommerce_variation_is_visible', 'modir_variations_hide_oos', 10, 4);
}

function modir_variations_hide_oos($visible, $variation_id, $product_id, $variation) {
    if (empty(get_option('modir_hide_oos_variations', 0))) {
        return $visible;
    }
    if ($visible && $variation && !$variation->is_in_stock()) {
        return false;
    }
    return $visible;
}

/* -------------------------------------------------------------------- */
/* ویرایشگر CSS سراسری سایت                                              */
/* -------------------------------------------------------------------- */

function modir_custom_css_init() {
    add_action('admin_init', 'modir_custom_css_maybe_save');
    add_action('wp_head', 'modir_custom_css_output', 100);

    if (!empty(get_option('modir_custom_css_apply_admin', 0))) {
        add_action('admin_head', 'modir_custom_css_output', 100);
    }
}

function modir_custom_css_output() {
    $css = get_option('modir_custom_css', '');
    if (trim($css) === '') {
        return;
    }
    echo "\n<style id=\"modir-custom-css\">\n" . wp_strip_all_tags($css) . "\n</style>\n";
}

function modir_custom_css_maybe_save() {
    if (empty($_POST['modir_custom_css_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_custom_css_nonce'])), 'modir_custom_css_save')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $css = isset($_POST['custom_css']) ? wp_unslash($_POST['custom_css']) : '';
    // فقط تگ‌های HTML را حذف می‌کند (برای جلوگیری از تزریق اسکریپت)؛ کدهای CSS دست‌نخورده می‌مانند
    $css = wp_strip_all_tags($css);
    update_option('modir_custom_css', $css);
    update_option('modir_custom_css_apply_admin', !empty($_POST['apply_admin']) ? 1 : 0);

    wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'tab' => 'css', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

function modir_custom_css_render_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $css          = get_option('modir_custom_css', '');
    $apply_admin  = get_option('modir_custom_css_apply_admin', 0);
    ?>
    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>CSS ذخیره شد و همین الان اعمال شده.</p></div>
    <?php endif; ?>

    <div class="modir-alert">
        هر کد CSS که اینجا بنویسی، مستقیم در تمام صفحات فرانت سایت اعمال می‌شه. اگه لازم داری همین CSS تو خود پیشخوان وردپرس هم اجرا بشه (مثلاً برای شخصی‌سازی ظاهر خود پیشخوان یا یه افزونه‌ی دیگه)، تیک پایین رو بزن.
    </div>

    <form method="post">
        <?php wp_nonce_field('modir_custom_css_save', 'modir_custom_css_nonce'); ?>
        <textarea name="custom_css" rows="16" class="large-text code" style="font-family:monospace;direction:ltr;text-align:left;" placeholder=".site-header { background: #111; }"><?php echo esc_textarea($css); ?></textarea>
        <p>
            <label class="modir-toggle">
                <input type="checkbox" name="apply_admin" value="1" <?php checked(!empty($apply_admin)); ?>>
                این CSS در پیشخوان وردپرس (نه فقط فرانت سایت) هم اعمال شود
            </label>
        </p>
        <?php submit_button('ذخیره و اعمال', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>
    <?php
}

