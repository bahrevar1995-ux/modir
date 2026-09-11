<?php
if (!defined('ABSPATH')) exit;

/**
 * ماژول پیگیری سفارش
 */

function modir_tracking_init() {
    add_action('add_meta_boxes', 'modir_tracking_add_meta_box');
    add_action('woocommerce_process_shop_order_meta', 'modir_tracking_save_meta_box');
    add_action('save_post_shop_order', 'modir_tracking_save_meta_box');
    add_action('admin_enqueue_scripts', 'modir_tracking_order_screen_css');

    add_shortcode('modir_order_tracking', 'modir_tracking_shortcode');

    add_action('wp_enqueue_scripts', 'modir_tracking_frontend_assets');

    add_action('admin_init', 'modir_tracking_maybe_create_page');
    add_action('admin_init', 'modir_tracking_maybe_bulk_save');
    add_action('admin_init', 'modir_tracking_maybe_save_sms_settings');
    add_action('wp_ajax_modir_tracking_test_sms', 'modir_tracking_ajax_test_sms');
    add_action('admin_enqueue_scripts', 'modir_tracking_admin_assets');
}

/* -------------------------------------------------------------------- */
/* باکس کد رهگیری در صفحه ویرایش سفارش (رنگی و قابل‌تفکیک)                  */
/* -------------------------------------------------------------------- */

function modir_tracking_add_meta_box() {
    $screen = (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled())
        ? wc_get_page_screen_id('shop-order')
        : 'shop_order';

    add_meta_box('modir_tracking_box', 'پیگیری مرسوله', 'modir_tracking_render_meta_box', $screen, 'side', 'default');
}

function modir_tracking_order_screen_css($hook) {
    $is_order_screen = in_array($hook, array('post.php', 'post-new.php'), true) || 'woocommerce_page_wc-orders' === $hook;
    if (!$is_order_screen) {
        return;
    }

    wp_register_style('modir-tracking-order-css', false, array(), MODIR_VERSION);
    wp_enqueue_style('modir-tracking-order-css');
    wp_add_inline_style('modir-tracking-order-css', '
        #modir_tracking_box .inside { margin: 0; padding: 0; }
        .modir-tracking-metabox { padding: 12px; border-right: 4px solid; }
        .modir-tracking-metabox.has-code { background: #ecfdf5; border-color: #16a34a; }
        .modir-tracking-metabox.no-code { background: #fffbeb; border-color: #d97706; }
        .modir-tracking-metabox label { font-weight: 600; font-size: 12px; display: block; margin-bottom: 4px; }
        .modir-tracking-metabox p { margin: 0 0 10px; }
        .modir-tracking-badge { display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 10px; border-radius: 999px; margin-bottom: 10px; }
        .modir-tracking-metabox.has-code .modir-tracking-badge { background: #16a34a; color: #fff; }
        .modir-tracking-metabox.no-code .modir-tracking-badge { background: #d97706; color: #fff; }
        .modir-tracking-sms-note { font-size: 11px; color: #6b7280; margin-top: 6px; }
    ');
}

function modir_tracking_render_meta_box($post_or_order) {
    $order = $post_or_order instanceof WP_Post ? wc_get_order($post_or_order->ID) : $post_or_order;
    if (!$order) {
        return;
    }

    $code       = $order->get_meta('_modir_tracking_code');
    $carrier    = $order->get_meta('_modir_tracking_carrier');
    $link       = $order->get_meta('_modir_tracking_link');
    $sms_status = $order->get_meta('_modir_tracking_sms_status');
    $has_code   = (bool) $code;

    wp_nonce_field('modir_tracking_save', 'modir_tracking_nonce');
    ?>
    <div class="modir-tracking-metabox <?php echo $has_code ? 'has-code' : 'no-code'; ?>">
        <span class="modir-tracking-badge"><?php echo $has_code ? 'کد رهگیری ثبت شده' : 'هنوز کد رهگیری ثبت نشده'; ?></span>

        <p>
            <label for="modir_tracking_carrier">شرکت حمل‌ونقل</label>
            <input type="text" id="modir_tracking_carrier" name="modir_tracking_carrier" class="widefat" value="<?php echo esc_attr($carrier); ?>" placeholder="مثلاً: تیپاکس">
        </p>
        <p>
            <label for="modir_tracking_code">کد رهگیری مرسوله</label>
            <input type="text" id="modir_tracking_code" name="modir_tracking_code" class="widefat" value="<?php echo esc_attr($code); ?>">
        </p>
        <p>
            <label for="modir_tracking_link">لینک پیگیری شرکت حمل‌ونقل</label>
            <input type="url" id="modir_tracking_link" name="modir_tracking_link" class="widefat" value="<?php echo esc_attr($link); ?>" placeholder="https://">
        </p>

        <?php if ($sms_status) : ?>
            <p class="modir-tracking-sms-note">وضعیت آخرین پیامک: <?php echo esc_html($sms_status); ?></p>
        <?php endif; ?>
    </div>
    <?php
}

function modir_tracking_save_meta_box($order_id) {
    if (empty($_POST['modir_tracking_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_tracking_nonce'])), 'modir_tracking_save')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $code    = sanitize_text_field(wp_unslash($_POST['modir_tracking_code'] ?? ''));
    $carrier = sanitize_text_field(wp_unslash($_POST['modir_tracking_carrier'] ?? ''));
    $link    = esc_url_raw(wp_unslash($_POST['modir_tracking_link'] ?? ''));

    modir_tracking_update_order($order_id, $code, $carrier, $link);
}

/**
 * ذخیره اطلاعات رهگیری یک سفارش و در صورت نیاز ارسال پیامک؛ هم توسط باکس
 * تکی صفحه ویرایش سفارش استفاده می‌شود، هم توسط تب کدرهگیری دسته‌جمعی.
 */
function modir_tracking_update_order($order_id, $code, $carrier = '', $link = '') {
    $order = wc_get_order($order_id);
    if (!$order) {
        return false;
    }

    $old_code = $order->get_meta('_modir_tracking_code');

    $order->update_meta_data('_modir_tracking_code', $code);
    if ('' !== $carrier) {
        $order->update_meta_data('_modir_tracking_carrier', $carrier);
    }
    if ('' !== $link) {
        $order->update_meta_data('_modir_tracking_link', $link);
    }
    $order->save();

    if ($code && $code !== $old_code) {
        $result = modir_tracking_send_sms($order, $code, $carrier ?: $order->get_meta('_modir_tracking_carrier'));
        $order->update_meta_data('_modir_tracking_sms_status', $result['ok'] ? 'ارسال موفق' : ('ناموفق — ' . $result['message']));
        $order->save();
    }

    return true;
}

/* -------------------------------------------------------------------- */
/* صفحه عمومی پیگیری (ساخت خودکار صفحه + شورت‌کد)                          */
/* -------------------------------------------------------------------- */

function modir_tracking_maybe_create_page() {
    $page_id = get_option('modir_tracking_page_id');
    if ($page_id && get_post($page_id)) {
        return;
    }

    $existing = get_page_by_path('order-tracking');
    if ($existing) {
        update_option('modir_tracking_page_id', $existing->ID);
        return;
    }

    $new_id = wp_insert_post(array(
        'post_title'   => 'پیگیری سفارش',
        'post_name'    => 'order-tracking',
        'post_content' => '[modir_order_tracking]',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ));

    if ($new_id && !is_wp_error($new_id)) {
        update_option('modir_tracking_page_id', $new_id);
    }
}

function modir_tracking_frontend_assets() {
    if (!is_singular() || !has_shortcode(get_post()->post_content ?? '', 'modir_order_tracking')) {
        return;
    }
    wp_enqueue_style('modir-tracking-css', MODIR_URL . 'assets/css/tracking.css', array(), MODIR_VERSION);
    wp_enqueue_script('modir-tracking-js', MODIR_URL . 'assets/js/tracking.js', array(), MODIR_VERSION, true);
}

/* -------------------------------------------------------------------- */
/* پیامک ملی‌پیامک (الگو/پترن)                                            */
/* -------------------------------------------------------------------- */

function modir_tracking_get_sms_settings() {
    return wp_parse_args(get_option('modir_tracking_sms_settings', array()), array(
        'enabled'  => 0,
        'username' => '',
        'password' => '',
        'body_id'  => '',
        'var1'     => 'customer_name',
        'var2'     => 'tracking_code',
        'var3'     => 'carrier',
    ));
}

function modir_tracking_sms_variable_options() {
    return array(
        ''              => '(خالی)',
        'customer_name' => 'نام مشتری',
        'tracking_code' => 'کد رهگیری',
        'order_number'  => 'شماره سفارش',
        'carrier'       => 'نام شرکت حمل‌ونقل',
    );
}

function modir_tracking_build_sms_value($key, $order, $tracking_code, $carrier) {
    switch ($key) {
        case 'customer_name':
            return $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : '';
        case 'tracking_code':
            return $tracking_code;
        case 'order_number':
            return $order ? $order->get_order_number() : '';
        case 'carrier':
            return $carrier;
        default:
            return '';
    }
}

function modir_tracking_send_sms($order, $tracking_code, $carrier = '') {
    $s = modir_tracking_get_sms_settings();
    if (empty($s['enabled'])) {
        return array('ok' => false, 'message' => 'ارسال پیامک غیرفعال است.');
    }
    if (empty($s['username']) || empty($s['password']) || empty($s['body_id'])) {
        return array('ok' => false, 'message' => 'تنظیمات پیامک کامل نیست.');
    }

    $phone = $order ? $order->get_billing_phone() : '';
    if (!$phone) {
        return array('ok' => false, 'message' => 'شماره موبایل سفارش خالی است.');
    }

    $values = array(
        modir_tracking_build_sms_value($s['var1'], $order, $tracking_code, $carrier),
        modir_tracking_build_sms_value($s['var2'], $order, $tracking_code, $carrier),
        modir_tracking_build_sms_value($s['var3'], $order, $tracking_code, $carrier),
    );

    return modir_tracking_sms_api_call($phone, $s['username'], $s['password'], $s['body_id'], $values);
}

function modir_tracking_sms_api_call($phone, $username, $password, $body_id, $values) {
    $phone = preg_replace('/[^0-9]/', '', (string) $phone);
    if (0 === strpos($phone, '0098')) {
        $phone = '0' . substr($phone, 4);
    } elseif (0 === strpos($phone, '98')) {
        $phone = '0' . substr($phone, 2);
    }

    $response = wp_remote_post('https://rest.payamak-panel.com/api/SendSMS/BaseNumber', array(
        'timeout' => 20,
        'body'    => array(
            'username' => $username,
            'password' => $password,
            'text'     => implode(';', $values),
            'to'       => $phone,
            'bodyId'   => $body_id,
        ),
    ));

    if (is_wp_error($response)) {
        return array('ok' => false, 'message' => 'خطای اتصال: ' . $response->get_error_message());
    }

    $body = trim(wp_remote_retrieve_body($response));
    $num  = is_numeric($body) ? (float) $body : null;

    if (null !== $num && $num > 0) {
        return array('ok' => true, 'message' => 'شناسه پیامک: ' . esc_html($body));
    }

    return array('ok' => false, 'message' => 'پاسخ سرویس: ' . esc_html($body ?: 'نامشخص'));
}

function modir_tracking_maybe_save_sms_settings() {
    if (empty($_POST['modir_tracking_sms_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_tracking_sms_nonce'])), 'modir_tracking_save_sms')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    update_option('modir_tracking_sms_settings', array(
        'enabled'  => !empty($_POST['enabled']) ? 1 : 0,
        'username' => sanitize_text_field(wp_unslash($_POST['username'] ?? '')),
        'password' => sanitize_text_field(wp_unslash($_POST['password'] ?? '')),
        'body_id'  => sanitize_text_field(wp_unslash($_POST['body_id'] ?? '')),
        'var1'     => sanitize_key($_POST['var1'] ?? 'customer_name'),
        'var2'     => sanitize_key($_POST['var2'] ?? 'tracking_code'),
        'var3'     => sanitize_key($_POST['var3'] ?? 'carrier'),
    ));

    wp_safe_redirect(add_query_arg(array('page' => 'modir-orders', 'tab' => 'tracking', 'ttab' => 'sms', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

function modir_tracking_admin_assets($hook) {
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    $tab  = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
    $is_new_url    = ('modir-orders' === $page && 'tracking' === $tab);
    $is_legacy_url = ('modir-tracking' === $page);
    if (!$is_new_url && !$is_legacy_url) {
        return;
    }
    wp_enqueue_style('woocommerce_admin_styles');
    wp_enqueue_script('wc-enhanced-select');
    wp_enqueue_script('modir-tracking-admin-js', MODIR_URL . 'assets/js/tracking-admin.js', array('jquery'), MODIR_VERSION, true);
    wp_localize_script('modir-tracking-admin-js', 'ModirTrackingAdmin', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('modir_tracking_test_sms'),
    ));
}

function modir_tracking_ajax_test_sms() {
    check_ajax_referer('modir_tracking_test_sms', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }

    $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
    if (!$phone) {
        wp_send_json_error(array('message' => 'شماره موبایل را وارد کن.'));
    }

    $username = sanitize_text_field(wp_unslash($_POST['username'] ?? ''));
    $password = sanitize_text_field(wp_unslash($_POST['password'] ?? ''));
    $body_id  = sanitize_text_field(wp_unslash($_POST['body_id'] ?? ''));

    if (!$username || !$password || !$body_id) {
        wp_send_json_error(array('message' => 'نام کاربری، رمز و شناسه الگو را کامل کن.'));
    }

    $result = modir_tracking_sms_api_call($phone, $username, $password, $body_id, array('علی محمدی', 'TEST123456', 'تیپاکس'));

    if ($result['ok']) {
        wp_send_json_success(array('message' => $result['message']));
    }
    wp_send_json_error(array('message' => $result['message']));
}

/* ======================================================================
 * صفحه ادمین
 * ====================================================================== */

function modir_tracking_render_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $tabs = array(
        'info' => 'صفحه پیگیری',
        'bulk' => 'کدرهگیری دسته‌جمعی',
        'sms'  => 'پیامک خودکار',
    );
    $ttab = isset($_GET['ttab']) ? sanitize_key(wp_unslash($_GET['ttab'])) : 'info';
    if (!isset($tabs[$ttab])) {
        $ttab = 'info';
    }
    ?>
    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>ذخیره شد.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['bulk_saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo (int) $_GET['bulk_saved']; ?> کد رهگیری ذخیره شد.</p></div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper modir-tabs">
        <?php foreach ($tabs as $key => $label) :
            $url    = add_query_arg(array('page' => 'modir-orders', 'tab' => 'tracking', 'ttab' => $key), admin_url('admin.php'));
            $active = ($ttab === $key) ? ' nav-tab-active' : '';
            ?>
            <a href="<?php echo esc_url($url); ?>" class="nav-tab<?php echo esc_attr($active); ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="modir-tab-panel">
        <?php
        if ('bulk' === $ttab) {
            modir_tracking_render_bulk_tab();
        } elseif ('sms' === $ttab) {
            modir_tracking_render_sms_tab();
        } else {
            modir_tracking_render_info_tab();
        }
        ?>
    </div>
    <?php
}

/**
 * برای لینک‌های قدیمی/بوکمارک‌شده: مستقیم صفحه سفارشات را با تب «پیگیری
 * سفارش» فعال نمایش می‌دهد، چون این ماژول به آنجا منتقل شده است.
 */
function modir_render_tracking_page() {
    $_GET['tab'] = 'tracking';
    modir_render_orders_list_page();
}

function modir_tracking_render_info_tab() {
    $page_id  = get_option('modir_tracking_page_id');
    $page_url = $page_id ? get_permalink($page_id) : '';
    ?>
    <table class="form-table">
        <tr>
            <th scope="row">صفحه پیگیری</th>
            <td>
                <?php if ($page_url) : ?>
                    <a href="<?php echo esc_url($page_url); ?>" target="_blank"><?php echo esc_html($page_url); ?></a>
                <?php else : ?>
                    <span class="modir-empty">هنوز ساخته نشده</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row">شورت‌کد</th>
            <td><code>[modir_order_tracking]</code></td>
        </tr>
    </table>
    <?php
}

/* -------------------------------------------------------------------- */
/* تب کدرهگیری دسته‌جمعی                                                  */
/* -------------------------------------------------------------------- */

function modir_tracking_maybe_bulk_save() {
    if (empty($_POST['modir_tracking_bulk_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_tracking_bulk_nonce'])), 'modir_tracking_bulk_save')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $count = 0;
    if (!empty($_POST['tracking']) && is_array($_POST['tracking'])) {
        foreach (wp_unslash($_POST['tracking']) as $order_id => $row) {
            $order_id = absint($order_id);
            $code     = isset($row['code']) ? sanitize_text_field($row['code']) : '';
            $carrier  = isset($row['carrier']) ? sanitize_text_field($row['carrier']) : '';
            if (!$order_id || !$code) {
                continue;
            }
            if (modir_tracking_update_order($order_id, $code, $carrier)) {
                $count++;
            }
        }
    }

    $redirect = add_query_arg(array('page' => 'modir-orders', 'tab' => 'tracking', 'ttab' => 'bulk', 'bulk_saved' => $count), admin_url('admin.php'));
    foreach (array('status', 'has_code') as $key) {
        if (!empty($_POST[$key])) {
            $redirect = add_query_arg($key, sanitize_key($_POST[$key]), $redirect);
        }
    }

    wp_safe_redirect($redirect);
    exit;
}

function modir_tracking_render_bulk_tab() {
    $status_filter   = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
    $has_code_filter = isset($_GET['has_code']) ? sanitize_key(wp_unslash($_GET['has_code'])) : '';

    $all_statuses = wc_get_order_statuses();

    $args = array('limit' => 200, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects');
    if ($status_filter) {
        $args['status'] = array($status_filter);
    } else {
        $args['status'] = array_map(function ($k) {
            return str_replace('wc-', '', $k);
        }, array_keys($all_statuses));
    }

    $orders = wc_get_orders($args);

    if ('yes' === $has_code_filter) {
        $orders = array_filter($orders, function ($o) {
            return (bool) $o->get_meta('_modir_tracking_code');
        });
    } elseif ('no' === $has_code_filter) {
        $orders = array_filter($orders, function ($o) {
            return !$o->get_meta('_modir_tracking_code');
        });
    }
    ?>
    <form method="get" class="modir-filter-form">
        <input type="hidden" name="page" value="modir-tracking">
        <input type="hidden" name="tab" value="bulk">
        <div class="modir-filter-row">
            <div class="modir-filter-field">
                <label>وضعیت سفارش</label>
                <select name="status">
                    <option value="">همه وضعیت‌ها</option>
                    <?php foreach ($all_statuses as $key => $label) :
                        $slug = str_replace('wc-', '', $key);
                        ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($status_filter, $slug); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modir-filter-field">
                <label>وضعیت کدرهگیری</label>
                <select name="has_code">
                    <option value="">همه</option>
                    <option value="yes" <?php selected($has_code_filter, 'yes'); ?>>دارای کد</option>
                    <option value="no" <?php selected($has_code_filter, 'no'); ?>>بدون کد</option>
                </select>
            </div>
            <div class="modir-filter-field modir-filter-submit">
                <button type="submit" class="button button-primary modir-btn">اعمال فیلتر</button>
            </div>
        </div>
    </form>

    <?php if (empty($orders)) : ?>
        <p class="modir-empty">سفارشی با این فیلتر پیدا نشد.</p>
    <?php else : ?>
        <form method="post">
            <?php wp_nonce_field('modir_tracking_bulk_save', 'modir_tracking_bulk_nonce'); ?>
            <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
            <input type="hidden" name="has_code" value="<?php echo esc_attr($has_code_filter); ?>">

            <table class="widefat striped modir-table">
                <thead>
                    <tr>
                        <th>سفارش</th>
                        <th>مشتری</th>
                        <th>شهر</th>
                        <th>محصولات</th>
                        <th>وضعیت</th>
                        <th>شرکت حمل‌ونقل</th>
                        <th>کد رهگیری</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order) :
                        $name  = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()) ?: trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                        $city  = $order->get_shipping_city() ?: $order->get_billing_city();
                        $items = array();
                        foreach ($order->get_items() as $item) {
                            $items[] = $item->get_name() . ' × ' . $item->get_quantity();
                        }
                        $status_label = $all_statuses['wc-' . $order->get_status()] ?? $order->get_status();
                        $edit_url     = method_exists($order, 'get_edit_order_url') ? $order->get_edit_order_url() : admin_url('post.php?post=' . $order->get_id() . '&action=edit');
                        ?>
                        <tr>
                            <td><a href="<?php echo esc_url($edit_url); ?>" target="_blank">#<?php echo esc_html($order->get_order_number()); ?></a></td>
                            <td><?php echo esc_html($name); ?></td>
                            <td><?php echo esc_html($city); ?></td>
                            <td><?php echo esc_html(implode('، ', $items)); ?></td>
                            <td><?php echo esc_html($status_label); ?></td>
                            <td><input type="text" name="tracking[<?php echo esc_attr($order->get_id()); ?>][carrier]" value="<?php echo esc_attr($order->get_meta('_modir_tracking_carrier')); ?>" style="width:110px;"></td>
                            <td><input type="text" name="tracking[<?php echo esc_attr($order->get_id()); ?>][code]" value="<?php echo esc_attr($order->get_meta('_modir_tracking_code')); ?>" style="width:150px;"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p><button type="submit" class="button button-primary modir-btn">ذخیره همه کدهای رهگیری</button></p>
        </form>
    <?php endif; ?>
    <?php
}

/* -------------------------------------------------------------------- */
/* تب پیامک خودکار                                                       */
/* -------------------------------------------------------------------- */

function modir_tracking_render_sms_tab() {
    $s   = modir_tracking_get_sms_settings();
    $var = modir_tracking_sms_variable_options();
    ?>
    <form method="post">
        <?php wp_nonce_field('modir_tracking_save_sms', 'modir_tracking_sms_nonce'); ?>
        <table class="form-table">
            <tr>
                <th scope="row">فعال‌سازی</th>
                <td>
                    <label class="modir-toggle">
                        <input type="checkbox" name="enabled" value="1" <?php checked(!empty($s['enabled'])); ?>>
                        وقتی کد رهگیری وارد/تغییر کند، خودکار پیامک ارسال شود
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">نام کاربری ملی‌پیامک</th>
                <td><input type="text" name="username" id="modir-sms-username" class="regular-text" value="<?php echo esc_attr($s['username']); ?>"></td>
            </tr>
            <tr>
                <th scope="row">رمز عبور</th>
                <td><input type="password" name="password" id="modir-sms-password" class="regular-text" value="<?php echo esc_attr($s['password']); ?>"></td>
            </tr>
            <tr>
                <th scope="row">شناسه الگو (Body ID)</th>
                <td><input type="text" name="body_id" id="modir-sms-bodyid" class="regular-text" value="<?php echo esc_attr($s['body_id']); ?>"></td>
            </tr>
            <tr>
                <th scope="row">ترتیب متغیرهای الگو</th>
                <td>
                    <p>
                        متغیر اول:
                        <select name="var1">
                            <?php foreach ($var as $k => $l) : ?><option value="<?php echo esc_attr($k); ?>" <?php selected($s['var1'], $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?>
                        </select>
                        متغیر دوم:
                        <select name="var2">
                            <?php foreach ($var as $k => $l) : ?><option value="<?php echo esc_attr($k); ?>" <?php selected($s['var2'], $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?>
                        </select>
                        متغیر سوم:
                        <select name="var3">
                            <?php foreach ($var as $k => $l) : ?><option value="<?php echo esc_attr($k); ?>" <?php selected($s['var3'], $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?>
                        </select>
                    </p>
                    <p class="description">باید دقیقاً همان ترتیبی باشد که موقع ساخت الگو در پنل ملی‌پیامک تعریف کردی.</p>
                </td>
            </tr>
        </table>
        <?php submit_button('ذخیره تنظیمات پیامک', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>

    <h2 class="modir-subtitle">تست پیامک</h2>
    <div class="modir-filter-row">
        <div class="modir-filter-field">
            <label>شماره موبایل برای تست</label>
            <input type="text" id="modir-sms-test-phone" placeholder="09xxxxxxxxx">
        </div>
        <div class="modir-filter-field modir-filter-submit">
            <button type="button" class="button modir-btn" id="modir-sms-test-btn">ارسال پیامک تست</button>
        </div>
    </div>
    <p id="modir-sms-test-result"></p>
    <?php
}

/* -------------------------------------------------------------------- */
/* شورت‌کد و جست‌وجو                                                       */
/* -------------------------------------------------------------------- */

function modir_tracking_normalize_phone($phone) {
    $phone = preg_replace('/[^0-9]/', '', (string) $phone);
    return substr($phone, -10);
}

function modir_tracking_shortcode() {
    $result   = null;
    $error    = '';
    $order_no = '';

    if (!empty($_POST['modir_tracking_submit'])) {
        $order_no = sanitize_text_field(wp_unslash($_POST['modir_order_no'] ?? ''));
        $phone    = sanitize_text_field(wp_unslash($_POST['modir_phone'] ?? ''));

        $order_id = (int) preg_replace('/[^0-9]/', '', $order_no);
        $order    = $order_id ? wc_get_order($order_id) : false;

        if (!$order) {
            $error = 'سفارشی با این شماره پیدا نشد.';
        } elseif (modir_tracking_normalize_phone($order->get_billing_phone()) !== modir_tracking_normalize_phone($phone)) {
            $error = 'شماره تماس با سفارش مطابقت ندارد.';
        } else {
            $result = $order;
        }
    }

    ob_start();
    ?>
    <div class="modir-tracking-wrap" dir="rtl">
        <div class="modir-tracking-card">
            <h2 class="modir-tracking-title">پیگیری سفارش</h2>

            <form method="post" class="modir-tracking-form">
                <input type="text" name="modir_order_no" placeholder="شماره سفارش" value="<?php echo esc_attr($order_no); ?>" required>
                <input type="text" name="modir_phone" placeholder="شماره تماس ثبت‌شده در سفارش" required>
                <button type="submit" name="modir_tracking_submit" value="1">پیگیری سفارش</button>
            </form>

            <?php if ($error) : ?>
                <div class="modir-tracking-error"><?php echo esc_html($error); ?></div>
            <?php endif; ?>

            <?php if ($result) :
                $order         = $result;
                $statuses      = wc_get_order_statuses();
                $status_label  = $statuses['wc-' . $order->get_status()] ?? $order->get_status();
                $code          = $order->get_meta('_modir_tracking_code');
                $carrier       = $order->get_meta('_modir_tracking_carrier');
                $link          = $order->get_meta('_modir_tracking_link');
                ?>
                <div class="modir-tracking-result">
                    <div class="modir-tracking-result-row">
                        <span>شماره سفارش</span>
                        <strong>#<?php echo esc_html($order->get_order_number()); ?></strong>
                    </div>
                    <div class="modir-tracking-result-row">
                        <span>تاریخ ثبت</span>
                        <strong><?php echo esc_html(function_exists('modir_format_jalali') ? modir_format_jalali($order->get_date_created()) : $order->get_date_created()->date('Y-m-d')); ?></strong>
                    </div>
                    <div class="modir-tracking-result-row">
                        <span>وضعیت سفارش</span>
                        <strong class="modir-tracking-status"><?php echo esc_html($status_label); ?></strong>
                    </div>

                    <?php if ($code) : ?>
                        <div class="modir-tracking-code-box">
                            <span class="modir-tracking-code-label"><?php echo $carrier ? esc_html($carrier) . ' — ' : ''; ?>کد رهگیری</span>
                            <div class="modir-tracking-code-row">
                                <code id="modir-tracking-code-value"><?php echo esc_html($code); ?></code>
                                <button type="button" class="modir-tracking-copy-btn" data-copy="<?php echo esc_attr($code); ?>">کپی</button>
                            </div>
                            <?php if ($link) : ?>
                                <a href="<?php echo esc_url($link); ?>" target="_blank" class="modir-tracking-link">پیگیری در سایت شرکت حمل‌ونقل</a>
                            <?php endif; ?>
                        </div>
                    <?php else : ?>
                        <p class="modir-tracking-note">هنوز کد رهگیری برای این سفارش ثبت نشده است.</p>
                    <?php endif; ?>

                    <h3 class="modir-tracking-items-title">کالاهای سفارش</h3>
                    <ul class="modir-tracking-items">
                        <?php foreach ($order->get_items() as $item) : ?>
                            <li><span><?php echo esc_html($item->get_name()); ?></span><span><?php echo esc_html($item->get_quantity()); ?> عدد</span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
