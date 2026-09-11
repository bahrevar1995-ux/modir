<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «نوار نوتیف»: نوار اطلاعیه/تبلیغاتی بالای سایت
 * ==========================================================================
 */

function modir_notifbar_init() {
    add_action('admin_init', 'modir_notifbar_maybe_save');
    add_action('admin_enqueue_scripts', 'modir_notifbar_admin_assets');
    add_action('wp_enqueue_scripts', 'modir_notifbar_frontend_assets');
    add_action('wp_footer', 'modir_notifbar_render_frontend');
    add_action('wp_ajax_modir_notifbar_track', 'modir_notifbar_ajax_track');
    add_action('wp_ajax_nopriv_modir_notifbar_track', 'modir_notifbar_ajax_track');
}

/* -------------------------------------------------------------------- */
/* تنظیمات پیش‌فرض و دریافت مقادیر                                       */
/* -------------------------------------------------------------------- */

function modir_notifbar_default_settings() {
    return array(
        'enabled'        => 0,
        'style'          => 'simple',
        'bg_color'       => '#111827',
        'text_color'     => '#ffffff',
        'btn_bg'         => '#2563eb',
        'btn_text_color' => '#ffffff',
        'message'        => 'اطلاعیه یا طرح جدید شما اینجا نمایش داده می‌شود.',
        'btn_label'      => 'مشاهده',
        'btn_url'        => '',
        'btn_new_tab'    => 0,
        'image_id'       => 0,
        'dismissible'    => 1,
    );
}

function modir_notifbar_get_settings() {
    $saved = get_option('modir_notifbar', array());
    return wp_parse_args($saved, modir_notifbar_default_settings());
}

function modir_notifbar_styles() {
    return array(
        'simple'  => array('label' => 'ساده',        'desc' => 'نوار تک‌رنگ و مینیمال با متن و دکمه در وسط'),
        'gradient'=> array('label' => 'گرادیانت',     'desc' => 'پس‌زمینه گرادیانی ملایم از رنگ انتخابی'),
        'bold'    => array('label' => 'دکمه برجسته',  'desc' => 'تاکید بیشتر روی دکمه با سایه و گردی بیشتر'),
        'banner'  => array('label' => 'بنری/تصویری',  'desc' => 'مناسب زمانی که تصویر آپلود می‌کنید؛ تصویر در کنار متن'),
    );
}

/* -------------------------------------------------------------------- */
/* ذخیره تنظیمات (فرم داخل همان صفحه ادمین ارسال می‌شود)                  */
/* -------------------------------------------------------------------- */

function modir_notifbar_maybe_save() {
    if (empty($_POST['modir_notifbar_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_notifbar_nonce'])), 'modir_notifbar_save')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $defaults = modir_notifbar_default_settings();
    $input    = isset($_POST['modir_notifbar']) ? wp_unslash($_POST['modir_notifbar']) : array();

    $styles = array_keys(modir_notifbar_styles());

    $clean = array();
    $clean['enabled']     = !empty($input['enabled']) ? 1 : 0;
    $clean['style']       = (isset($input['style']) && in_array($input['style'], $styles, true)) ? $input['style'] : $defaults['style'];
    $clean['bg_color']    = !empty($input['bg_color']) ? sanitize_hex_color($input['bg_color']) : $defaults['bg_color'];
    $clean['text_color']  = !empty($input['text_color']) ? sanitize_hex_color($input['text_color']) : $defaults['text_color'];
    $clean['btn_bg']      = !empty($input['btn_bg']) ? sanitize_hex_color($input['btn_bg']) : $defaults['btn_bg'];
    $clean['btn_text_color'] = !empty($input['btn_text_color']) ? sanitize_hex_color($input['btn_text_color']) : $defaults['btn_text_color'];
    $clean['message']     = isset($input['message']) ? wp_kses($input['message'], array('b' => array(), 'strong' => array(), 'em' => array(), 'br' => array())) : '';
    $clean['btn_label']   = isset($input['btn_label']) ? sanitize_text_field($input['btn_label']) : '';
    $clean['btn_url']     = isset($input['btn_url']) ? esc_url_raw($input['btn_url']) : '';
    $clean['btn_new_tab'] = !empty($input['btn_new_tab']) ? 1 : 0;
    $clean['image_id']    = isset($input['image_id']) ? absint($input['image_id']) : 0;
    $clean['dismissible'] = !empty($input['dismissible']) ? 1 : 0;

    foreach (array('bg_color', 'text_color', 'btn_bg', 'btn_text_color') as $c) {
        if (empty($clean[$c])) {
            $clean[$c] = $defaults[$c];
        }
    }

    update_option('modir_notifbar', $clean);

    wp_safe_redirect(add_query_arg(array('page' => 'modir-notifbar', 'tab' => 'settings', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

/* -------------------------------------------------------------------- */
/* آمار (بازدید/کلیک)                                                    */
/* -------------------------------------------------------------------- */

function modir_notifbar_get_stats() {
    return wp_parse_args(get_option('modir_notifbar_stats', array()), array(
        'impressions' => 0,
        'clicks'      => 0,
        'daily'       => array(),
    ));
}

function modir_notifbar_ajax_track() {
    check_ajax_referer('modir_notifbar_track', 'nonce');

    $type = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : '';
    if (!in_array($type, array('impression', 'click'), true)) {
        wp_send_json_error();
    }

    $stats = modir_notifbar_get_stats();
    $today = current_time('Y-m-d');

    if (!isset($stats['daily'][$today])) {
        $stats['daily'][$today] = array('impressions' => 0, 'clicks' => 0);
    }

    if ('impression' === $type) {
        $stats['impressions']++;
        $stats['daily'][$today]['impressions']++;
    } else {
        $stats['clicks']++;
        $stats['daily'][$today]['clicks']++;
    }

    // فقط ۴۵ روز اخیر نگه داشته می‌شود تا آپشن سنگین نشود
    if (count($stats['daily']) > 45) {
        uksort($stats['daily'], 'strcmp');
        $stats['daily'] = array_slice($stats['daily'], -45, null, true);
    }

    update_option('modir_notifbar_stats', $stats, false);
    wp_send_json_success();
}

/* -------------------------------------------------------------------- */
/* بارگذاری استایل/اسکریپت                                              */
/* -------------------------------------------------------------------- */

function modir_notifbar_admin_assets($hook) {
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    $tab  = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
    $is_new_url    = ('modir-marketing' === $page && 'notifbar' === $tab);
    $is_legacy_url = ('modir-notifbar' === $page);
    if (!$is_new_url && !$is_legacy_url) {
        return;
    }

    wp_enqueue_media();
    wp_enqueue_style('modir-notifbar-css', MODIR_URL . 'assets/css/notifbar.css', array(), MODIR_VERSION);
    wp_enqueue_script('modir-admin-notifbar-js', MODIR_URL . 'assets/js/admin-notifbar.js', array('jquery', 'wp-color-picker'), MODIR_VERSION, true);
}

function modir_notifbar_frontend_assets() {
    $settings = modir_notifbar_get_settings();
    if (empty($settings['enabled'])) {
        return;
    }

    wp_enqueue_style('modir-notifbar-css', MODIR_URL . 'assets/css/notifbar.css', array(), MODIR_VERSION);
    wp_enqueue_script('modir-notifbar-js', MODIR_URL . 'assets/js/notifbar.js', array(), MODIR_VERSION, true);

    $dismiss_key = md5($settings['message'] . $settings['btn_label'] . $settings['btn_url'] . $settings['style']);

    wp_localize_script('modir-notifbar-js', 'ModirNotifBar', array(
        'ajaxUrl'    => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('modir_notifbar_track'),
        'dismissKey' => $dismiss_key,
        'dismissible'=> (bool) $settings['dismissible'],
    ));
}

/* -------------------------------------------------------------------- */
/* رندر نوار در فرانت (بالای سایت، ثابت روی صفحه)                        */
/* -------------------------------------------------------------------- */

function modir_notifbar_render_frontend() {
    $settings = modir_notifbar_get_settings();
    if (empty($settings['enabled'])) {
        return;
    }
    modir_notifbar_render_bar($settings);
}

/**
 * رندر مشترک نوار؛ هم برای فرانت و هم برای پیش‌نمایش داخل ادمین استفاده می‌شود.
 */
function modir_notifbar_render_bar($settings, $is_preview = false) {
    $image_url = $settings['image_id'] ? wp_get_attachment_image_url($settings['image_id'], 'medium') : '';

    $classes = array('modir-notifbar', 'modir-notif-style-' . $settings['style']);
    if ($is_preview) {
        $classes[] = 'modir-notif-preview';
    }

    $target = !empty($settings['btn_new_tab']) ? ' target="_blank" rel="noopener noreferrer"' : '';
    ?>
    <div class="<?php echo esc_attr(implode(' ', $classes)); ?>"
         style="--modir-notif-bg: <?php echo esc_attr($settings['bg_color']); ?>; --modir-notif-text: <?php echo esc_attr($settings['text_color']); ?>; --modir-notif-btn-bg: <?php echo esc_attr($settings['btn_bg']); ?>; --modir-notif-btn-text: <?php echo esc_attr($settings['btn_text_color']); ?>;"
         <?php if (!$is_preview) : ?>id="modir-notifbar"<?php endif; ?>>
        <div class="modir-notif-inner">
            <?php if ($image_url) : ?>
                <img class="modir-notif-image" src="<?php echo esc_url($image_url); ?>" alt="">
            <?php endif; ?>

            <div class="modir-notif-text"><?php echo wp_kses($settings['message'], array('b' => array(), 'strong' => array(), 'em' => array(), 'br' => array())); ?></div>

            <?php if (!empty($settings['btn_label']) && !empty($settings['btn_url'])) : ?>
                <a href="<?php echo esc_url($settings['btn_url']); ?>" class="modir-notif-btn" <?php echo $target; // phpcs:ignore ?>>
                    <?php echo esc_html($settings['btn_label']); ?>
                </a>
            <?php endif; ?>

            <?php if (!empty($settings['dismissible']) && !$is_preview) : ?>
                <button type="button" class="modir-notif-close" aria-label="بستن">&times;</button>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/* -------------------------------------------------------------------- */
/* صفحه ادمین: تنظیمات + آمار                                            */
/* -------------------------------------------------------------------- */

function modir_notifbar_page_url($tab = 'settings') {
    return add_query_arg(array('page' => 'modir-marketing', 'tab' => 'notifbar', 'ntab' => $tab), admin_url('admin.php'));
}

function modir_notifbar_render_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $saved_ok = isset($_GET['saved']) && '1' === $_GET['saved'];

    $tabs = array(
        'settings' => 'تنظیمات نوار',
        'stats'    => 'آمار بازدید و کلیک',
    );
    $ntab = isset($_GET['ntab']) ? sanitize_key(wp_unslash($_GET['ntab'])) : 'settings';
    if (!isset($tabs[$ntab])) {
        $ntab = 'settings';
    }
    ?>
    <?php if ($saved_ok) : ?>
        <div class="notice notice-success is-dismissible"><p>تنظیمات نوار نوتیف با موفقیت ذخیره شد.</p></div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper modir-tabs">
        <?php foreach ($tabs as $key => $label) :
            $active = ($ntab === $key) ? ' nav-tab-active' : '';
            ?>
            <a href="<?php echo esc_url(modir_notifbar_page_url($key)); ?>" class="nav-tab<?php echo esc_attr($active); ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="modir-tab-panel">
        <?php
        if ('stats' === $ntab) {
            modir_notifbar_render_stats_tab();
        } else {
            modir_notifbar_render_settings_tab();
        }
        ?>
    </div>
    <?php
}

/**
 * برای لینک‌های قدیمی/بوکمارک‌شده: مستقیم صفحه بازاریابی را با تب «نوار
 * نوتیف» فعال نمایش می‌دهد، چون این ماژول به آنجا منتقل شده است.
 */
function modir_render_notifbar_page() {
    $_GET['tab'] = 'notifbar';
    modir_render_marketing_page();
}

function modir_notifbar_render_settings_tab() {
    $settings = modir_notifbar_get_settings();
    $styles   = modir_notifbar_styles();
    $image_url = $settings['image_id'] ? wp_get_attachment_image_url($settings['image_id'], 'thumbnail') : '';
    ?>
    <div class="modir-notif-preview-wrap">
        <p class="modir-notif-preview-label">پیش‌نمایش زنده</p>
        <div class="modir-notif-preview-frame">
            <?php modir_notifbar_render_bar($settings, true); ?>
        </div>
    </div>

    <form method="post" class="modir-filter-form modir-settings-form modir-notif-form" id="modir-notifbar-form">
        <?php wp_nonce_field('modir_notifbar_save', 'modir_notifbar_nonce'); ?>

        <table class="form-table">
            <tr>
                <th scope="row">فعال‌سازی نوار</th>
                <td>
                    <label class="modir-toggle">
                        <input type="checkbox" name="modir_notifbar[enabled]" value="1" <?php checked($settings['enabled'], 1); ?> data-preview="enabled">
                        نوار در بالای سایت نمایش داده شود
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row">طرح گرافیکی</th>
                <td>
                    <div class="modir-style-grid">
                        <?php foreach ($styles as $key => $s) : ?>
                            <label class="modir-style-card <?php echo ($settings['style'] === $key) ? 'is-selected' : ''; ?>">
                                <input type="radio" name="modir_notifbar[style]" value="<?php echo esc_attr($key); ?>" <?php checked($settings['style'], $key); ?> data-preview="style">
                                <span class="modir-style-name"><?php echo esc_html($s['label']); ?></span>
                                <span class="modir-style-desc"><?php echo esc_html($s['desc']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </td>
            </tr>

            <tr>
                <th scope="row">رنگ‌ها</th>
                <td>
                    <div class="modir-color-grid">
                        <label>پس‌زمینه نوار
                            <input type="text" name="modir_notifbar[bg_color]" value="<?php echo esc_attr($settings['bg_color']); ?>" class="modir-color-picker" data-default-color="#111827" data-preview="bg_color">
                        </label>
                        <label>رنگ متن
                            <input type="text" name="modir_notifbar[text_color]" value="<?php echo esc_attr($settings['text_color']); ?>" class="modir-color-picker" data-default-color="#ffffff" data-preview="text_color">
                        </label>
                        <label>پس‌زمینه دکمه
                            <input type="text" name="modir_notifbar[btn_bg]" value="<?php echo esc_attr($settings['btn_bg']); ?>" class="modir-color-picker" data-default-color="#2563eb" data-preview="btn_bg">
                        </label>
                        <label>رنگ متن دکمه
                            <input type="text" name="modir_notifbar[btn_text_color]" value="<?php echo esc_attr($settings['btn_text_color']); ?>" class="modir-color-picker" data-default-color="#ffffff" data-preview="btn_text_color">
                        </label>
                    </div>
                </td>
            </tr>

            <tr>
                <th scope="row">تصویر (اختیاری)</th>
                <td>
                    <input type="hidden" name="modir_notifbar[image_id]" id="modir-notif-image-id" value="<?php echo esc_attr($settings['image_id']); ?>" data-preview="image_id">
                    <div id="modir-notif-image-preview" class="modir-notif-image-preview">
                        <?php if ($image_url) : ?><img src="<?php echo esc_url($image_url); ?>" alt=""><?php endif; ?>
                    </div>
                    <button type="button" class="button" id="modir-notif-choose-image">انتخاب تصویر</button>
                    <button type="button" class="button" id="modir-notif-remove-image" style="<?php echo $image_url ? '' : 'display:none;'; ?>">حذف تصویر</button>
                    <p class="description">مناسب طرح «بنری/تصویری»؛ در طرح‌های دیگر به‌صورت آیکون کوچک نمایش داده می‌شود.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">متن اطلاعیه</th>
                <td>
                    <textarea name="modir_notifbar[message]" rows="3" class="large-text" data-preview="message"><?php echo esc_textarea($settings['message']); ?></textarea>
                    <p class="description">تگ‌های ساده مجاز: b، strong، em، br</p>
                </td>
            </tr>

            <tr>
                <th scope="row">دکمه</th>
                <td>
                    <div class="modir-inline-fields">
                        <label>متن دکمه
                            <input type="text" name="modir_notifbar[btn_label]" value="<?php echo esc_attr($settings['btn_label']); ?>" data-preview="btn_label">
                        </label>
                        <label>لینک دکمه
                            <input type="url" name="modir_notifbar[btn_url]" value="<?php echo esc_attr($settings['btn_url']); ?>" placeholder="https://" data-preview="btn_url">
                        </label>
                    </div>
                    <label class="modir-toggle">
                        <input type="checkbox" name="modir_notifbar[btn_new_tab]" value="1" <?php checked($settings['btn_new_tab'], 1); ?>>
                        باز شدن لینک در تب جدید
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row">قابلیت بستن</th>
                <td>
                    <label class="modir-toggle">
                        <input type="checkbox" name="modir_notifbar[dismissible]" value="1" <?php checked($settings['dismissible'], 1); ?>>
                        نمایش دکمه × برای بستن نوار توسط بازدیدکننده
                    </label>
                </td>
            </tr>
        </table>

        <?php submit_button('ذخیره تنظیمات نوار', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>
    <?php
}

function modir_notifbar_render_stats_tab() {
    $stats = modir_notifbar_get_stats();
    $ctr   = $stats['impressions'] > 0 ? ($stats['clicks'] / $stats['impressions']) * 100 : 0;

    $daily = $stats['daily'];
    krsort($daily);
    $daily = array_slice($daily, 0, 14, true);
    ?>
    <div class="modir-summary-cards">
        <div class="modir-card">
            <span class="modir-card-label">تعداد بازدید نوار</span>
            <span class="modir-card-value"><?php echo number_format_i18n($stats['impressions']); ?></span>
        </div>
        <div class="modir-card">
            <span class="modir-card-label">تعداد کلیک روی دکمه</span>
            <span class="modir-card-value"><?php echo number_format_i18n($stats['clicks']); ?></span>
        </div>
        <div class="modir-card modir-card-success">
            <span class="modir-card-label">نرخ کلیک (CTR)</span>
            <span class="modir-card-value"><?php echo number_format_i18n($ctr, 2); ?>%</span>
        </div>
    </div>

    <?php if (empty($daily)) : ?>
        <p class="modir-empty">هنوز آماری ثبت نشده است.</p>
    <?php else : ?>
        <table class="widefat striped modir-table">
            <thead>
                <tr>
                    <th>تاریخ</th>
                    <th>بازدید</th>
                    <th>کلیک</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($daily as $date => $row) : ?>
                    <tr>
                        <td><?php echo esc_html(modir_format_jalali($date)); ?></td>
                        <td><?php echo number_format_i18n($row['impressions']); ?></td>
                        <td><?php echo number_format_i18n($row['clicks']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
}
