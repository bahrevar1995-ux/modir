<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «سفارش‌های حرفه‌ای»: ستون‌های اضافه، فیلتر پیشرفته و ظاهر مینیمال
 * برای صفحه سفارش‌های ووکامرس — سازگار با حالت سنتی و حالت HPOS.
 * ==========================================================================
 */

function modir_orders_init() {
    add_action('admin_init', 'modir_orders_maybe_backfill');

    $s = modir_orders_get_settings();
    if (empty($s['enabled'])) {
        return;
    }

    // ستون‌ها — حالت سنتی (Custom Post Type)
    add_filter('manage_edit-shop_order_columns', 'modir_orders_add_columns');
    add_action('manage_shop_order_posts_custom_column', 'modir_orders_render_column', 10, 2);

    // ستون‌ها — حالت HPOS
    add_filter('manage_woocommerce_page_wc-orders_columns', 'modir_orders_add_columns');
    add_action('manage_woocommerce_page_wc-orders_custom_column', 'modir_orders_render_column_hpos', 10, 2);

    // فیلترها — حالت سنتی
    add_action('restrict_manage_posts', 'modir_orders_render_filters_legacy', 20, 2);
    add_action('pre_get_posts', 'modir_orders_apply_filters_legacy');

    // فیلترها — حالت HPOS
    add_action('woocommerce_order_list_table_restrict_manage_orders', 'modir_orders_render_filters_hpos');
    add_filter('woocommerce_order_list_table_prepare_items_query_args', 'modir_orders_apply_filters_hpos');

    // همگام‌نگه‌داشتن متادیتای روش حمل‌ونقل برای فیلتر کردن
    add_action('woocommerce_new_order', 'modir_orders_sync_shipping_meta');
    add_action('woocommerce_update_order', 'modir_orders_sync_shipping_meta');

    // ظاهر مینیمال
    add_action('admin_enqueue_scripts', 'modir_orders_enqueue_assets');
}

/* -------------------------------------------------------------------- */
/* تنظیمات                                                              */
/* -------------------------------------------------------------------- */

function modir_orders_get_settings() {
    return wp_parse_args(get_option('modir_orders_settings', array()), array(
        'enabled'     => 0,
        'commissions' => array(),
    ));
}

function modir_orders_get_commission($gateway_id) {
    $s = modir_orders_get_settings();
    if (empty($s['commissions'][$gateway_id])) {
        return array('percent' => 0, 'fixed' => 0, 'settlement_months' => 1);
    }
    return wp_parse_args($s['commissions'][$gateway_id], array('percent' => 0, 'fixed' => 0, 'settlement_months' => 1));
}

function modir_orders_calc_net($order) {
    $total   = (float) $order->get_total();
    $gateway = $order->get_payment_method();
    $c       = modir_orders_get_commission($gateway);

    if (empty($c['percent']) && empty($c['fixed'])) {
        return null; // یعنی کارمزدی برای این درگاه تعریف نشده
    }

    $fee = ($total * ((float) $c['percent']) / 100) + (float) $c['fixed'];
    return max(0, $total - $fee);
}

/* -------------------------------------------------------------------- */
/* ستون‌ها                                                                */
/* -------------------------------------------------------------------- */

function modir_orders_add_columns($columns) {
    $new = array();
    foreach ($columns as $key => $label) {
        $new[$key] = $label;
        if ('order_number' === $key) {
            $new['modir_thumb'] = 'تصویر';
        }
        if ('order_total' === $key) {
            $new['modir_gateway'] = 'درگاه پرداخت';
            $new['modir_net']     = 'خالص پس از کارمزد';
        }
    }
    // اگر کلیدهای مورد انتظار پیدا نشد (به‌خاطر افزونه دیگری که ترتیب را عوض کرده)، در انتها اضافه شود
    if (!isset($new['modir_thumb'])) {
        $new['modir_thumb'] = 'تصویر';
    }
    if (!isset($new['modir_gateway'])) {
        $new['modir_gateway'] = 'درگاه پرداخت';
        $new['modir_net']     = 'خالص پس از کارمزد';
    }
    return $new;
}

function modir_orders_render_column($column, $post_id) {
    $order = wc_get_order($post_id);
    if (!$order) {
        return;
    }
    modir_orders_render_column_content($column, $order);
}

function modir_orders_render_column_hpos($column, $order_or_id) {
    $order = is_object($order_or_id) ? $order_or_id : wc_get_order($order_or_id);
    if (!$order) {
        return;
    }
    modir_orders_render_column_content($column, $order);
}

function modir_orders_render_column_content($column, $order) {
    switch ($column) {
        case 'modir_thumb':
            modir_orders_render_thumb_column($order);
            break;
        case 'modir_gateway':
            modir_orders_render_gateway_column($order);
            break;
        case 'modir_net':
            modir_orders_render_net_column($order);
            break;
    }
}

function modir_orders_render_thumb_column($order) {
    $items = $order->get_items();
    if (empty($items)) {
        echo '&#8212;';
        return;
    }
    $first = reset($items);
    $extra = count($items) - 1;
    $product = $first->get_product();
    $img = $product ? $product->get_image(array('width' => 36, 'height' => 36)) : wc_placeholder_img(array('width' => 36, 'height' => 36));

    echo '<div class="modir-order-thumb-wrap">' . wp_kses_post($img);
    if ($extra > 0) {
        echo '<span class="modir-order-thumb-extra">+' . esc_html($extra) . '</span>';
    }
    echo '</div>';
}

function modir_orders_render_gateway_column($order) {
    $gateway_id = $order->get_payment_method();
    $title      = $order->get_payment_method_title();

    if (!$gateway_id) {
        echo '&#8212;';
        return;
    }

    $icon_url = '';
    if (function_exists('WC') && WC()->payment_gateways()) {
        $gateways = WC()->payment_gateways()->payment_gateways();
        if (isset($gateways[$gateway_id]) && !empty($gateways[$gateway_id]->icon)) {
            $icon_url = $gateways[$gateway_id]->icon;
        }
    }

    echo '<span class="modir-gateway-badge">';
    if ($icon_url) {
        echo '<img src="' . esc_url($icon_url) . '" alt="" class="modir-gateway-icon">';
    }
    echo esc_html($title ?: $gateway_id);
    echo '</span>';
}

function modir_orders_render_net_column($order) {
    $net = modir_orders_calc_net($order);
    if (null === $net) {
        echo '<span class="modir-net-empty" title="کارمزدی برای این درگاه در تنظیمات تعریف نشده">&#8212;</span>';
        return;
    }
    echo wp_kses_post(wc_price($net));
}

/* -------------------------------------------------------------------- */
/* همگام‌سازی متادیتای روش حمل‌ونقل                                        */
/* -------------------------------------------------------------------- */

function modir_orders_sync_shipping_meta($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || !is_a($order, 'WC_Order')) {
        return;
    }

    $rules_by_label = array();
    if (function_exists('modir_shipping_get_rules')) {
        foreach (modir_shipping_get_rules() as $rule) {
            if (!empty($rule['label'])) {
                $rules_by_label[$rule['label']] = $rule['id'];
            }
        }
    }

    $values = array();
    foreach ($order->get_shipping_methods() as $item) {
        $method_id = $item->get_method_id();

        if ('modir_shipping' === $method_id && !empty($rules_by_label)) {
            $name    = $item->get_name();
            $matched = '';
            foreach ($rules_by_label as $label => $rule_id) {
                if (0 === strpos($name, $label)) {
                    $matched = $rule_id;
                    break;
                }
            }
            $values[] = $matched ? ('modir_shipping:' . $matched) : 'modir_shipping';
        } else {
            $values[] = $method_id;
        }
    }

    $order->update_meta_data('_modir_shipping_method_id', implode(',', array_unique(array_filter($values))));
    $order->save_meta_data();
}

/**
 * برای سفارش‌های قدیمی که قبل از فعال‌سازی این ماژول ثبت شده‌اند، این تابع
 * یک‌بار همه را پیمایش و متادیتای روش حمل‌ونقل را برایشان می‌سازد.
 */
function modir_orders_backfill_shipping_meta($limit = 500) {
    $orders = wc_get_orders(array('limit' => $limit, 'type' => 'shop_order', 'return' => 'ids', 'orderby' => 'date', 'order' => 'DESC'));
    foreach ($orders as $id) {
        modir_orders_sync_shipping_meta($id);
    }
    return count($orders);
}

function modir_orders_maybe_backfill() {
    if (empty($_GET['modir_orders_action']) || 'backfill' !== $_GET['modir_orders_action']) {
        return;
    }
    if (empty($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'modir_orders_backfill')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $count = modir_orders_backfill_shipping_meta(1000);

    wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'orders_synced' => $count), admin_url('admin.php')));
    exit;
}

/* -------------------------------------------------------------------- */
/* فیلترها — رندر و اعمال                                                */
/* -------------------------------------------------------------------- */

function modir_orders_get_gateway_filter_options() {
    if (!function_exists('WC') || !WC()->payment_gateways()) {
        return array();
    }
    $options = array();
    foreach (WC()->payment_gateways()->get_available_payment_gateways() as $id => $gateway) {
        $options[$id] = $gateway->get_title() ?: $id;
    }
    return $options;
}

/**
 * برخلاف تابع بالا (که فقط درگاه‌های فعال را می‌دهد و برای فیلتر لیست سفارش‌ها
 * مناسب است)، این تابع همه درگاه‌های ثبت‌شده را برمی‌گرداند — چون در محیط
 * ادمین بعضی درگاه‌ها (به‌خاطر منطق is_available() که به سبد خرید خالی وابسته
 * است) ممکن است اشتباهاً «نامعتبر» تشخیص داده شوند و از جدول کارمزد تنظیمات
 * ناپدید شوند و مقدار ذخیره‌شده‌شان روی صفر برگردد.
 */
function modir_orders_get_all_gateway_options() {
    if (!function_exists('WC') || !WC()->payment_gateways()) {
        return array();
    }
    $options = array();
    foreach (WC()->payment_gateways()->payment_gateways() as $id => $gateway) {
        $options[$id] = $gateway->get_title() ?: $id;
    }
    return $options;
}

function modir_orders_get_shipping_filter_options() {
    $options = array();

    if (class_exists('WC_Shipping_Zones') && class_exists('WC_Shipping_Zone')) {
        $zone_ids = array(0);
        foreach (WC_Shipping_Zones::get_zones() as $z) {
            $zone_ids[] = $z['id'];
        }

        foreach ($zone_ids as $zone_id) {
            $zone = new WC_Shipping_Zone($zone_id);
            foreach ($zone->get_shipping_methods(true) as $method) {
                if ('modir_shipping' === $method->id) {
                    continue; // به‌جای این، قوانین اختصاصی زیر جداگانه اضافه می‌شوند
                }
                $options[$method->id] = $method->get_method_title() ?: $method->id;
            }
        }
    }

    if (function_exists('modir_shipping_get_rules')) {
        foreach (modir_shipping_get_rules() as $rule) {
            if (empty($rule['enabled']) || empty($rule['label'])) {
                continue;
            }
            $options['modir_shipping:' . $rule['id']] = $rule['label'];
        }
    }

    return $options;
}

function modir_orders_render_filters_common() {
    $gateways         = modir_orders_get_gateway_filter_options();
    $shipping_methods = modir_orders_get_shipping_filter_options();

    $current_gateway  = isset($_GET['modir_filter_gateway']) ? sanitize_text_field(wp_unslash($_GET['modir_filter_gateway'])) : '';
    $current_shipping = isset($_GET['modir_filter_shipping']) ? sanitize_text_field(wp_unslash($_GET['modir_filter_shipping'])) : '';

    if (empty($gateways) && empty($shipping_methods)) {
        return;
    }
    ?>
    <?php if (!empty($gateways)) : ?>
        <select name="modir_filter_gateway">
            <option value="">همه درگاه‌های پرداخت</option>
            <?php foreach ($gateways as $id => $title) : ?>
                <option value="<?php echo esc_attr($id); ?>" <?php selected($current_gateway, $id); ?>><?php echo esc_html($title); ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    <?php if (!empty($shipping_methods)) : ?>
        <select name="modir_filter_shipping">
            <option value="">همه روش‌های حمل‌ونقل</option>
            <?php foreach ($shipping_methods as $id => $title) : ?>
                <option value="<?php echo esc_attr($id); ?>" <?php selected($current_shipping, $id); ?>><?php echo esc_html($title); ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    <?php
}

function modir_orders_render_filters_legacy($post_type, $which) {
    if ('shop_order' !== $post_type) {
        return;
    }
    modir_orders_render_filters_common();
}

function modir_orders_render_filters_hpos($post_type = '') {
    modir_orders_render_filters_common();
}

function modir_orders_apply_filters_legacy($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }
    if ('shop_order' !== $query->get('post_type')) {
        return;
    }

    $meta_query = (array) $query->get('meta_query');

    if (!empty($_GET['modir_filter_gateway'])) {
        $meta_query[] = array(
            'key'   => '_payment_method',
            'value' => sanitize_text_field(wp_unslash($_GET['modir_filter_gateway'])),
        );
    }
    if (!empty($_GET['modir_filter_shipping'])) {
        $meta_query[] = array(
            'key'     => '_modir_shipping_method_id',
            'value'   => sanitize_text_field(wp_unslash($_GET['modir_filter_shipping'])),
            'compare' => 'LIKE',
        );
    }

    if (!empty($meta_query)) {
        $query->set('meta_query', $meta_query);
    }
}

function modir_orders_apply_filters_hpos($query_args) {
    $meta_query = isset($query_args['meta_query']) ? $query_args['meta_query'] : array();

    if (!empty($_GET['modir_filter_gateway'])) {
        $meta_query[] = array(
            'key'   => '_payment_method',
            'value' => sanitize_text_field(wp_unslash($_GET['modir_filter_gateway'])),
        );
    }
    if (!empty($_GET['modir_filter_shipping'])) {
        $meta_query[] = array(
            'key'     => '_modir_shipping_method_id',
            'value'   => sanitize_text_field(wp_unslash($_GET['modir_filter_shipping'])),
            'compare' => 'LIKE',
        );
    }

    if (!empty($meta_query)) {
        $query_args['meta_query'] = $meta_query;
    }

    return $query_args;
}

/* -------------------------------------------------------------------- */
/* ظاهر مینیمال                                                          */
/* -------------------------------------------------------------------- */

function modir_orders_enqueue_assets($hook) {
    $is_orders_screen = ('edit.php' === $hook && isset($_GET['post_type']) && 'shop_order' === $_GET['post_type'])
        || 'woocommerce_page_wc-orders' === $hook;

    if (!$is_orders_screen) {
        return;
    }

    wp_enqueue_style('modir-orders-css', MODIR_URL . 'assets/css/orders.css', array(), MODIR_VERSION);

    $color = function_exists('modir_get_primary_color') ? modir_get_primary_color() : '#16a34a';
    wp_add_inline_style('modir-orders-css', ':root{--modir-primary: ' . esc_attr($color) . ';}');
}
