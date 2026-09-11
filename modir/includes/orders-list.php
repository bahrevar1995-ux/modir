<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * صفحه «سفارشات»: لیست سفارش‌ها با فیلتر وضعیت/موفق-ناموفق (با امکان تنظیم
 * پیش‌فرض)، ستون‌های تصویر، مشتری، شهر/استان، درگاه، کد رهگیری (قابل ثبت)،
 * وضعیت (تکی و دسته‌جمعی)، چاپ لیبل، و لینک ویرایش در ووکامرس.
 * ==========================================================================
 */

function modir_orders_list_init() {
    add_action('admin_enqueue_scripts', 'modir_orders_list_admin_assets');
    add_action('admin_init', 'modir_orders_list_maybe_save_default_filter');
    add_action('wp_ajax_modir_orders_list', 'modir_orders_list_ajax_list');
    add_action('wp_ajax_modir_orders_save_tracking', 'modir_orders_list_ajax_save_tracking');
    add_action('wp_ajax_modir_orders_save_status', 'modir_orders_list_ajax_save_status');
    add_action('wp_ajax_modir_orders_bulk_status', 'modir_orders_list_ajax_bulk_status');
}

function modir_orders_list_admin_assets($hook) {
    if (strpos($hook, 'modir') === false) {
        return;
    }
    wp_enqueue_script('modir-orders-list-js', MODIR_URL . 'assets/js/orders-list.js', array('jquery'), MODIR_VERSION, true);
    wp_localize_script('modir-orders-list-js', 'ModirOrdersList', array(
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('modir_orders_list_nonce'),
        'printUrl' => add_query_arg(array('action' => 'modir_print_labels'), admin_url('admin-post.php')),
    ));
}

/* -------------------------------------------------------------------- */
/* فیلتر پیش‌فرض                                                         */
/* -------------------------------------------------------------------- */

function modir_orders_list_get_default_filter() {
    return wp_parse_args(get_option('modir_orders_default_filter', array()), array('status' => '', 'paid' => ''));
}

function modir_orders_list_maybe_save_default_filter() {
    if (empty($_POST['modir_orders_default_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_orders_default_nonce'])), 'modir_orders_save_default')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    update_option('modir_orders_default_filter', array(
        'status' => isset($_POST['default_status']) ? sanitize_key($_POST['default_status']) : '',
        'paid'   => isset($_POST['default_paid']) ? sanitize_key($_POST['default_paid']) : '',
    ));

    wp_safe_redirect(add_query_arg(array('page' => 'modir-orders', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

/**
 * آیکون رسمی درگاه پرداخت را از خود شیء درگاه ثبت‌شده در ووکامرس می‌گیرد
 * (اکثر افزونه‌های درگاه ایرانی این را تنظیم می‌کنند)؛ اگر نبود، خالی برمی‌گردد
 * تا به‌جایش متن نام درگاه نمایش داده شود.
 */
function modir_orders_list_get_gateway_icon($gateway_id) {
    if (!$gateway_id || !function_exists('WC') || !WC()->payment_gateways()) {
        return '';
    }
    $gateways = WC()->payment_gateways()->payment_gateways();
    return (!empty($gateways[$gateway_id]->icon)) ? $gateways[$gateway_id]->icon : '';
}

/**
 * رنگ مشخص برای هر وضعیت سفارش، برای تفکیک بصری سریع ردیف‌ها. اگر وضعیت
 * سفارشی (ساخته‌شده در ماژول «وضعیت‌های سفارش») رنگ خودش را داشته باشد،
 * همان استفاده می‌شود؛ در غیر این صورت از یک پالت پیش‌فرض منطقی استفاده می‌شود.
 */
function modir_orders_list_status_color($slug) {
    if (function_exists('modir_order_statuses_get_all')) {
        foreach (modir_order_statuses_get_all() as $custom) {
            if (!empty($custom['slug']) && $custom['slug'] === $slug && !empty($custom['color'])) {
                return $custom['color'];
            }
        }
    }

    $palette = array(
        'pending'        => '#f59e0b',
        'processing'     => '#2563eb',
        'on-hold'        => '#f97316',
        'completed'      => '#16a34a',
        'cancelled'      => '#6b7280',
        'refunded'       => '#7c3aed',
        'failed'         => '#dc2626',
        'checkout-draft' => '#9ca3af',
    );

    return $palette[$slug] ?? '#9ca3af';
}

/* -------------------------------------------------------------------- */
/* کوئری و رندر ردیف‌ها                                                   */
/* -------------------------------------------------------------------- */

/**
 * فیلدهای قابل‌جست‌وجو در سفارش‌ها را با نام و تلفن مشتری گسترش می‌دهد. این
 * هوک، مسیر رسمی و تضمین‌شده‌ی خود ووکامرس برای جست‌وجوست (چه ذخیره‌سازی
 * سنتی باشد، چه HPOS) — برخلاف meta_query دستی که مطمئن نیست زیر HPOS
 * درست کار کند.
 */
add_filter('woocommerce_shop_order_search_fields', function ($fields) {
    $fields[] = '_billing_first_name';
    $fields[] = '_billing_last_name';
    $fields[] = '_billing_phone';
    $fields[] = '_shipping_first_name';
    $fields[] = '_shipping_last_name';
    return $fields;
});

function modir_orders_list_direct_match($search) {
    if ($search && is_numeric($search)) {
        $maybe_order = wc_get_order(absint($search));
        if ($maybe_order) {
            return array($maybe_order);
        }
    }
    return null;
}

function modir_orders_list_apply_search($args, $search) {
    if ($search) {
        $args['s'] = $search;
    }
    return $args;
}

function modir_orders_list_render_rows($status, $paid, $search, $offset, $per_page = 25) {
    $args = array(
        'type'    => 'shop_order',
        'limit'   => $per_page,
        'offset'  => $offset,
        'orderby' => 'date',
        'order'   => 'DESC',
        'return'  => 'objects',
    );

    if ($status) {
        $args['status'] = array($status);
    } elseif ('paid' === $paid) {
        $args['status'] = array_values(array_diff(modir_get_default_paid_statuses(), array('cancelled', 'refunded')));
    } elseif ('unpaid' === $paid) {
        $paid_list = modir_get_default_paid_statuses();
        $unpaid    = array();
        foreach (wc_get_order_statuses() as $k => $l) {
            $slug = str_replace('wc-', '', $k);
            if (!in_array($slug, $paid_list, true)) {
                $unpaid[] = $slug;
            }
        }
        $args['status'] = $unpaid;
    }

    $args = modir_orders_list_apply_search($args, $search);

    $direct = modir_orders_list_direct_match($search);
    $orders = (null !== $direct) ? $direct : wc_get_orders($args);
    $all_statuses = wc_get_order_statuses();

    ob_start();
    foreach ($orders as $order) {
        if (!is_a($order, 'WC_Order')) {
            continue;
        }

        $img = '';
        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            if ($product) {
                $img = $product->get_image(array('width' => 22, 'height' => 22));
                break;
            }
        }
        if (!$img) {
            $img = wc_placeholder_img(array('width' => 22, 'height' => 22));
        }

        $name  = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
        $name  = $name ?: trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $phone = $order->get_billing_phone();

        $city        = $order->get_shipping_city() ?: $order->get_billing_city();
        $state_code  = $order->get_shipping_state() ?: $order->get_billing_state();
        $state_label = $state_code;
        if ($state_code && function_exists('WC') && WC()->countries) {
            $country = $order->get_shipping_country() ?: $order->get_billing_country() ?: 'IR';
            $states  = WC()->countries->get_states($country);
            if (!empty($states[$state_code])) {
                $state_label = $states[$state_code];
            }
        }

        $gateway_id    = $order->get_payment_method();
        $gateway_title = $order->get_payment_method_title() ?: $gateway_id;
        $gateway_icon  = modir_orders_list_get_gateway_icon($gateway_id);
        $tracking_code = $order->get_meta('_modir_tracking_code');
        $status_slug   = $order->get_status();
        $status_color  = modir_orders_list_status_color($status_slug);
        $edit_url      = method_exists($order, 'get_edit_order_url') ? $order->get_edit_order_url() : admin_url('post.php?post=' . $order->get_id() . '&action=edit');
        $print_url     = add_query_arg(array('action' => 'modir_print_labels', 'orders' => $order->get_id()), admin_url('admin-post.php'));
        $suppliers_txt = function_exists('modir_suppliers_get_order_suppliers_text') ? modir_suppliers_get_order_suppliers_text($order) : '';
        ?>
        <tr data-order-id="<?php echo esc_attr($order->get_id()); ?>" style="border-inline-start:3px solid <?php echo esc_attr($status_color); ?>;">
            <td><input type="checkbox" class="modir-orders-row-select"></td>
            <td><?php echo wp_kses_post($img); ?></td>
            <td>
                #<?php echo esc_html($order->get_order_number()); ?> —
                <?php echo esc_html($name ?: '—'); ?>
                <br><span class="modir-text-muted"><?php echo esc_html($phone); ?></span>
            </td>
            <td><?php echo esc_html(trim($state_label . ' / ' . $city, ' /')); ?></td>
            <td>
                <?php if ($gateway_icon) : ?>
                    <img src="<?php echo esc_url($gateway_icon); ?>" alt="<?php echo esc_attr($gateway_title); ?>" title="<?php echo esc_attr($gateway_title); ?>" style="height:18px;max-width:44px;object-fit:contain;">
                <?php else : ?>
                    <span class="modir-text-muted" style="font-size:11px;"><?php echo esc_html($gateway_title); ?></span>
                <?php endif; ?>
            </td>
            <td class="modir-text-muted"><?php echo esc_html($suppliers_txt ?: '—'); ?></td>
            <td><input type="text" class="modir-grid-input modir-orders-tracking-input" value="<?php echo esc_attr($tracking_code); ?>" placeholder="ثبت کد رهگیری"></td>
            <td>
                <div style="display:flex;align-items:center;gap:6px;">
                    <span style="width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr($status_color); ?>;flex-shrink:0;"></span>
                    <select class="modir-grid-input modir-orders-status-select">
                        <?php foreach ($all_statuses as $k => $l) :
                            $s = str_replace('wc-', '', $k);
                            ?>
                            <option value="<?php echo esc_attr($s); ?>" <?php selected($status_slug, $s); ?>><?php echo esc_html($l); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </td>
            <td>
                <a href="<?php echo esc_url($print_url); ?>" target="_blank" class="modir-icon-link" title="چاپ لیبل آدرس"><span class="dashicons dashicons-printer"></span></a>
                <a href="<?php echo esc_url($edit_url); ?>" target="_blank" class="modir-icon-link" title="ویرایش در ووکامرس"><span class="dashicons dashicons-edit"></span></a>
            </td>
        </tr>
        <?php
    }

    return array('html' => ob_get_clean(), 'count' => count($orders), 'has_more' => (count($orders) === $per_page));
}

/* -------------------------------------------------------------------- */
/* AJAX                                                                  */
/* -------------------------------------------------------------------- */

function modir_orders_list_ajax_list() {
    check_ajax_referer('modir_orders_list_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }
    $result = modir_orders_list_render_rows(
        isset($_POST['status']) ? sanitize_key($_POST['status']) : '',
        isset($_POST['paid']) ? sanitize_key($_POST['paid']) : '',
        isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '',
        isset($_POST['offset']) ? absint($_POST['offset']) : 0
    );
    wp_send_json_success($result);
}

function modir_orders_list_ajax_save_tracking() {
    check_ajax_referer('modir_orders_list_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }
    $oid  = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';

    if (!$oid || !function_exists('modir_tracking_update_order')) {
        wp_send_json_error(array('message' => 'خطا.'));
    }

    modir_tracking_update_order($oid, $code);
    wp_send_json_success(array('message' => 'ذخیره شد.'));
}

function modir_orders_list_ajax_save_status() {
    check_ajax_referer('modir_orders_list_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }
    $oid    = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $status = isset($_POST['status']) ? sanitize_key($_POST['status']) : '';

    $order = $oid ? wc_get_order($oid) : false;
    if (!$order) {
        wp_send_json_error(array('message' => 'سفارش پیدا نشد.'));
    }

    $order->set_status($status);
    $order->save();
    wp_send_json_success(array('message' => 'وضعیت تغییر کرد.'));
}

function modir_orders_list_ajax_bulk_status() {
    check_ajax_referer('modir_orders_list_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }

    $ids    = isset($_POST['order_ids']) && is_array($_POST['order_ids']) ? array_map('absint', wp_unslash($_POST['order_ids'])) : array();
    $status = isset($_POST['status']) ? sanitize_key($_POST['status']) : '';

    if (empty($ids) || !$status) {
        wp_send_json_error(array('message' => 'اطلاعات ناقص است.'));
    }

    $count = 0;
    foreach ($ids as $oid) {
        $order = wc_get_order($oid);
        if (!$order) {
            continue;
        }
        $order->set_status($status);
        $order->save();
        $count++;
    }

    wp_send_json_success(array('count' => $count, 'message' => $count . ' سفارش به‌روزرسانی شد.'));
}

/* ======================================================================
 * صفحه ادمین
 * ====================================================================== */

function modir_orders_list_render_main_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $default      = modir_orders_list_get_default_filter();
    $all_statuses = wc_get_order_statuses();

    $today    = current_time('Y-m-d');
    $statuses = array_values(array_diff(modir_get_default_paid_statuses(), array('cancelled', 'refunded')));
    $today_orders = wc_get_orders(array('status' => $statuses, 'type' => 'shop_order', 'date_created' => $today . ' 00:00:00...' . $today . ' 23:59:59', 'limit' => -1, 'return' => 'ids'));
    $pending_count = count(wc_get_orders(array('status' => array('pending', 'on-hold'), 'type' => 'shop_order', 'limit' => -1, 'return' => 'ids')));
    ?>
    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>فیلتر پیش‌فرض ذخیره شد.</p></div>
    <?php endif; ?>

    <div class="modir-summary-cards">
        <div class="modir-card">
            <span class="modir-card-label">سفارش‌های موفق امروز</span>
            <span class="modir-card-value"><?php echo number_format_i18n(count($today_orders)); ?> عدد</span>
        </div>
        <div class="modir-card <?php echo $pending_count > 0 ? 'modir-card-danger' : 'modir-card-success'; ?>">
            <span class="modir-card-label">در انتظار پرداخت</span>
            <span class="modir-card-value"><?php echo number_format_i18n($pending_count); ?> عدد</span>
        </div>
    </div>

    <div class="modir-toolbar">
        <div class="modir-toolbar-group">
            <select class="modir-pill-select" id="modir-orders-status-filter">
                <option value="">همه وضعیت‌ها</option>
                <?php foreach ($all_statuses as $k => $l) :
                    $s = str_replace('wc-', '', $k);
                    ?>
                    <option value="<?php echo esc_attr($s); ?>" <?php selected($default['status'], $s); ?>><?php echo esc_html($l); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="modir-toolbar-group">
            <select class="modir-pill-select" id="modir-orders-paid-filter">
                <option value="">همه (موفق و ناموفق)</option>
                <option value="paid" <?php selected($default['paid'], 'paid'); ?>>فقط موفق</option>
                <option value="unpaid" <?php selected($default['paid'], 'unpaid'); ?>>فقط ناموفق</option>
            </select>
        </div>
        <div style="flex:1;"></div>
        <input type="text" class="modir-search-input" id="modir-orders-search" placeholder="جست‌وجو (نام، تلفن، شماره سفارش)...">
        <button type="button" class="modir-search-btn" id="modir-orders-search-btn"><span class="dashicons dashicons-search"></span></button>

        <div class="modir-color-swatch-wrap">
            <button type="button" class="modir-color-swatch-btn" id="modir-orders-default-btn" title="تنظیم فیلتر پیش‌فرض صفحه">
                <span class="dashicons dashicons-admin-generic" style="color:var(--modir-text-light);"></span>
            </button>
            <div class="modir-color-swatch-popup" id="modir-orders-default-popup" style="width:230px;flex-direction:column;align-items:stretch;">
                <form method="post">
                    <?php wp_nonce_field('modir_orders_save_default', 'modir_orders_default_nonce'); ?>
                    <p style="font-size:11px;color:var(--modir-text-light);margin:0 0 8px;">فیلتری که هر بار با باز کردن این صفحه به‌طور خودکار اعمال شود:</p>
                    <label style="font-size:11px;display:block;margin-bottom:4px;">وضعیت پیش‌فرض</label>
                    <select name="default_status" class="modir-pill-select" style="width:100%;margin-bottom:8px;">
                        <option value="">همه</option>
                        <?php foreach ($all_statuses as $k => $l) :
                            $s = str_replace('wc-', '', $k);
                            ?>
                            <option value="<?php echo esc_attr($s); ?>" <?php selected($default['status'], $s); ?>><?php echo esc_html($l); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label style="font-size:11px;display:block;margin-bottom:4px;">وضعیت پرداخت پیش‌فرض</label>
                    <select name="default_paid" class="modir-pill-select" style="width:100%;margin-bottom:10px;">
                        <option value="">همه</option>
                        <option value="paid" <?php selected($default['paid'], 'paid'); ?>>فقط موفق</option>
                        <option value="unpaid" <?php selected($default['paid'], 'unpaid'); ?>>فقط ناموفق</option>
                    </select>
                    <button type="submit" class="button button-primary modir-btn" style="width:100%;">ذخیره به‌عنوان پیش‌فرض</button>
                </form>
            </div>
        </div>
    </div>

    <div class="modir-bulk-stage-card">
        <div class="modir-bulk-stage">
            <span class="modir-bulk-stage-label">تغییر وضعیت دسته‌جمعی سفارش‌های تیک‌خورده</span>
            <select id="modir-orders-bulk-status" class="modir-pill-select">
                <?php foreach ($all_statuses as $k => $l) :
                    $s = str_replace('wc-', '', $k);
                    ?>
                    <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html($l); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="button" class="button button-primary modir-btn" id="modir-orders-bulk-apply">اعمال</button>
        <button type="button" class="button modir-btn" id="modir-orders-bulk-print"><span class="dashicons dashicons-printer" style="vertical-align:-3px;"></span> چاپ دسته‌جمعی لیبل</button>
        <span id="modir-orders-bulk-result" style="margin-inline-start:10px;"></span>
    </div>

    <table class="widefat modir-table modir-product-table modir-products-grid">
        <thead>
            <tr>
                <th style="width:36px;"><input type="checkbox" id="modir-orders-select-all"></th>
                <th style="width:40px;"></th>
                <th>مشتری</th>
                <th>شهر / استان</th>
                <th>درگاه</th>
                <th style="width:130px;">تأمین‌کننده</th>
                <th style="width:150px;">کد رهگیری</th>
                <th style="width:170px;">وضعیت</th>
                <th style="width:80px;"></th>
            </tr>
        </thead>
        <tbody id="modir-orders-rows"></tbody>
    </table>

    <p style="text-align:center;margin-top:16px;">
        <button type="button" class="button modir-btn" id="modir-orders-load-more">بارگذاری سفارش‌های بیشتر</button>
    </p>
    <?php
}

function modir_render_orders_list_page() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $sub_tabs = array(
        'main'     => 'سفارشات',
        'statuses' => 'وضعیت‌های سفارش',
        'label'    => 'لیبل آدرس',
        'tracking' => 'پیگیری سفارش',
    );
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'main';
    if (!isset($sub_tabs[$tab])) {
        $tab = 'main';
    }
    ?>
    <div class="wrap modir-wrap">
        <?php modir_render_top_header('cart', 'سفارشات', 'مدیریت سریع وضعیت، کد رهگیری، چاپ لیبل، و ابزارهای مربوط به سفارش'); ?>
        <?php modir_settings_tabs_nav('modir-orders'); ?>
        <?php modir_render_sub_tabs_nav('modir-orders', $sub_tabs, $tab); ?>

        <div class="modir-tab-panel">
            <?php
            switch ($tab) {
                case 'statuses':
                    modir_order_statuses_render_content();
                    break;
                case 'label':
                    modir_label_render_content();
                    break;
                case 'tracking':
                    modir_tracking_render_content();
                    break;
                default:
                    modir_orders_list_render_main_content();
                    break;
            }
            ?>
        </div>
    </div>
    <?php
}
