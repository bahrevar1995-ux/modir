<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «تشخیص VPN»: وقتی IP بازدیدکننده به‌عنوان پراکسی/دیتاسنتر (که اغلب
 * نشانه استفاده از فیلترشکن است) شناسایی شود، یک پاپ‌آپ نمایش داده می‌شود
 * که از کاربر می‌خواهد فیلترشکن خود را خاموش کند.
 * ==========================================================================
 */

function modir_vpn_init() {
    add_action('template_redirect', 'modir_vpn_check');
    add_action('wp_footer', 'modir_vpn_maybe_render_popup', 999);
}

function modir_vpn_get_settings() {
    return wp_parse_args(get_option('modir_vpn_settings', array()), array(
        'enabled' => 0,
        'message' => 'برای دسترسی کامل به سایت و انجام درست فرآیند خرید، لطفاً فیلترشکن (VPN) خود را خاموش کنید و دوباره تلاش کنید.',
    ));
}

/* -------------------------------------------------------------------- */
/* دریافت IP واقعی بازدیدکننده                                           */
/* -------------------------------------------------------------------- */

function modir_vpn_get_ip() {
    $keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR');
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '';
}

/* -------------------------------------------------------------------- */
/* بررسی VPN/پراکسی بودن IP                                              */
/* -------------------------------------------------------------------- */

function modir_vpn_check() {
    if (is_admin()) {
        return;
    }
    $s = modir_vpn_get_settings();
    if (empty($s['enabled'])) {
        return;
    }
    if (current_user_can('manage_woocommerce')) {
        return; // خود مدیر فروشگاه هیچ‌وقت مسدود نشود
    }

    $ip = modir_vpn_get_ip();
    if (!$ip || in_array($ip, array('127.0.0.1', '::1'), true)) {
        return;
    }

    $cache_key = 'modir_vpn_' . md5($ip);
    $is_vpn    = get_transient($cache_key);

    if (false === $is_vpn) {
        $is_vpn = modir_vpn_lookup($ip) ? '1' : '0';
        set_transient($cache_key, $is_vpn, HOUR_IN_SECONDS);
    }

    if ('1' === $is_vpn && !defined('MODIR_VPN_DETECTED')) {
        define('MODIR_VPN_DETECTED', true);
    }
}

/**
 * استعلام IP از یک سرویس رایگان تشخیص پراکسی/دیتاسنتر.
 * توجه: این سرویس (ip-api.com) در پلن رایگانش برای استفاده غیرتجاری در
 * نظر گرفته شده؛ برای استفاده تجاری پایدار، بهتره یک سرویس پولی (مثلاً
 * IPQualityScore) جایگزینش کنی — نقطه اتصال همینجا قابل تغییره.
 */
function modir_vpn_lookup($ip) {
    $response = wp_remote_get('http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,proxy,hosting', array(
        'timeout' => 4,
    ));

    if (is_wp_error($response)) {
        return false; // در صورت خطای اتصال، هیچ‌وقت کاربر واقعی را مسدود نکن
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data) || 'success' !== ($data['status'] ?? '')) {
        return false;
    }

    return !empty($data['proxy']) || !empty($data['hosting']);
}

/* -------------------------------------------------------------------- */
/* رندر پاپ‌آپ                                                           */
/* -------------------------------------------------------------------- */

function modir_vpn_maybe_render_popup() {
    if (!defined('MODIR_VPN_DETECTED') || !MODIR_VPN_DETECTED) {
        return;
    }

    $s = modir_vpn_get_settings();
    ?>
    <div id="modir-vpn-overlay" dir="rtl">
        <div class="modir-vpn-modal">
            <div class="modir-vpn-icon"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.6"><path d="M12 3l7 3v6c0 4.5-3 8-7 9-4-1-7-4.5-7-9V6l7-3z"/><path d="M9.5 12l1.8 1.8L15 10"/></svg></div>
            <h2>اتصال شما از طریق فیلترشکن تشخیص داده شد</h2>
            <p><?php echo esc_html($s['message']); ?></p>
            <button type="button" onclick="location.reload()">امتحان مجدد</button>
        </div>
    </div>
    <style>
        #modir-vpn-overlay { position: fixed; inset: 0; z-index: 2147483647; background: rgba(15,23,42,.94); display: flex; align-items: center; justify-content: center; padding: 20px; font-family: inherit; }
        .modir-vpn-modal { max-width: 380px; width: 100%; background: #fff; border-radius: 16px; padding: 32px 26px; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,.35); }
        .modir-vpn-icon { font-size: 34px; margin-bottom: 12px; }
        .modir-vpn-modal h2 { font-size: 16px; margin: 0 0 10px; color: #111827; }
        .modir-vpn-modal p { font-size: 13px; color: #6b7280; line-height: 1.9; margin: 0; }
        .modir-vpn-modal button { margin-top: 18px; background: #111827; color: #fff; border: none; padding: 11px 26px; border-radius: 9px; cursor: pointer; font-size: 13px; }
        .modir-vpn-modal button:hover { opacity: .9; }
        body { overflow: hidden !important; }
    </style>
    <?php
}
