<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «قفل فعال‌سازی»: تا وقتی رمز صحیح وارد نشود، هیچ بخشی از افزونه
 * (نه صفحات ادمین، نه امکانات فرانت سایت مثل نوار نوتیف/پاپ‌آپ/چت/حمل‌ونقل)
 * کار نمی‌کند.
 * ==========================================================================
 */

define('MODIR_LICENSE_CODE', '09308995092');

function modir_license_init() {
    add_action('admin_init', 'modir_license_maybe_unlock');
    add_action('admin_init', 'modir_license_maybe_relock');
    add_action('admin_notices', 'modir_license_activated_notice');
}

function modir_license_activated_notice() {
    if (!isset($_GET['modir_license_activated'])) {
        return;
    }
    echo '<div class="notice notice-success is-dismissible"><p>افزونه «مدیر» با موفقیت فعال شد. حالا می‌تونی از همه‌ی امکاناتش استفاده کنی.</p></div>';
}

function modir_license_is_unlocked() {
    return (bool) get_option('modir_license_unlocked', 0);
}

function modir_license_check_code($code) {
    return hash_equals(MODIR_LICENSE_CODE, (string) $code);
}

/* -------------------------------------------------------------------- */
/* پردازش فرم وارد کردن رمز                                              */
/* -------------------------------------------------------------------- */

function modir_license_maybe_unlock() {
    if (empty($_POST['modir_license_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_license_nonce'])), 'modir_license_unlock')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $code = isset($_POST['modir_license_code']) ? sanitize_text_field(wp_unslash($_POST['modir_license_code'])) : '';

    if (modir_license_check_code($code)) {
        update_option('modir_license_unlocked', 1);
        wp_safe_redirect(add_query_arg(array('page' => 'modir', 'modir_license_activated' => '1'), admin_url('admin.php')));
        exit;
    }

    wp_safe_redirect(add_query_arg(array('page' => 'modir', 'modir_license_error' => '1'), admin_url('admin.php')));
    exit;
}

function modir_license_maybe_relock() {
    if (empty($_GET['modir_license_action']) || 'relock' !== $_GET['modir_license_action']) {
        return;
    }
    if (empty($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'modir_license_relock')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    update_option('modir_license_unlocked', 0);

    wp_safe_redirect(add_query_arg(array('page' => 'modir'), admin_url('admin.php')));
    exit;
}

/* -------------------------------------------------------------------- */
/* پوشش‌دادن (gate) صفحات ادمین                                          */
/* -------------------------------------------------------------------- */

/**
 * یک callback واقعی صفحه را می‌گیرد و آن را طوری می‌پیچد که فقط وقتی افزونه
 * فعال (آنلاک) است اجرا شود؛ در غیر این صورت صفحه‌ی وارد کردن رمز را نشان می‌دهد.
 */
function modir_license_gate($real_callback) {
    return function () use ($real_callback) {
        if (!modir_license_is_unlocked()) {
            modir_license_render_lock_screen();
            return;
        }
        call_user_func($real_callback);
    };
}

function modir_license_render_lock_screen() {
    ?>
    <div class="wrap modir-wrap">
        <div class="modir-license-overlay">
            <div class="modir-license-modal">
                <div class="modir-license-modal-accent"></div>
                <div class="modir-license-icon-wrap">
                    <span class="modir-license-icon dashicons dashicons-lock" aria-hidden="true"></span>
                </div>
                <h1>فعال‌سازی افزونه مدیر</h1>
                <p>برای استفاده از امکانات افزونه «مدیر» (گزارش‌ها، نوار نوتیف، پاپ‌آپ محصول، دستیار گفتگو، مرجوعی‌ها و بقیه بخش‌ها)، لطفاً رمز فعال‌سازی را وارد کنید.</p>

                <?php if (isset($_GET['modir_license_error'])) : ?>
                    <div class="modir-license-error-msg">رمز واردشده صحیح نیست. لطفاً دوباره تلاش کنید.</div>
                <?php endif; ?>

                <form method="post" class="modir-license-form">
                    <?php wp_nonce_field('modir_license_unlock', 'modir_license_nonce'); ?>
                    <input type="password" name="modir_license_code" class="modir-license-input" placeholder="رمز فعال‌سازی" required autofocus>
                    <button type="submit" class="button button-primary modir-btn modir-license-submit">فعال‌سازی</button>
                </form>
            </div>
        </div>
    </div>
    <?php
}
