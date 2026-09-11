<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «ماشین‌حساب قیمت»: قیمت خرید + درصد سود دلخواه (پیش‌فرض یا اختصاصی
 * هر محصول) را می‌گیرد و قیمت فروش پیشنهادی را طوری محاسبه می‌کند که بعد از
 * کسر کارمزد درگاه پرداخت مرجع، همان درصد سود واقعاً محقق شود.
 *
 * فرمول: P = (purchase*(1+margin/100) + fixed) / (1 - percent/100)
 * ==========================================================================
 */

function modir_price_calc_init() {
    add_action('admin_enqueue_scripts', 'modir_price_calc_admin_assets');
    add_action('admin_init', 'modir_price_calc_maybe_save_settings');
    add_action('wp_ajax_modir_price_calc_list', 'modir_price_calc_ajax_list');
    add_action('wp_ajax_modir_price_calc_save', 'modir_price_calc_ajax_save');
    add_action('wp_ajax_modir_price_calc_bulk_apply', 'modir_price_calc_ajax_bulk_apply');
}

function modir_price_calc_admin_assets($hook) {
    if (strpos($hook, 'modir') === false) {
        return;
    }
    wp_enqueue_script('modir-price-calc-js', MODIR_URL . 'assets/js/price-calculator.js', array('jquery'), MODIR_VERSION, true);
    wp_localize_script('modir-price-calc-js', 'ModirPriceCalc', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('modir_price_calc_nonce'),
    ));
}

/* -------------------------------------------------------------------- */
/* تنظیمات                                                               */
/* -------------------------------------------------------------------- */

function modir_price_calc_get_settings() {
    return wp_parse_args(get_option('modir_price_calc_settings', array()), array(
        'default_margin'    => 30,
        'reference_gateway' => '',
        'sync_enabled'      => 0,
    ));
}

function modir_price_calc_maybe_save_settings() {
    if (empty($_POST['modir_price_calc_settings_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_price_calc_settings_nonce'])), 'modir_price_calc_save_settings')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    update_option('modir_price_calc_settings', array(
        'default_margin'    => isset($_POST['default_margin']) ? (float) $_POST['default_margin'] : 30,
        'reference_gateway' => isset($_POST['reference_gateway']) ? sanitize_text_field(wp_unslash($_POST['reference_gateway'])) : '',
        'sync_enabled'      => !empty($_POST['sync_enabled']) ? 1 : 0,
    ));

    wp_safe_redirect(add_query_arg(array('page' => 'modir-accounting', 'tab' => 'price_calc', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

/* -------------------------------------------------------------------- */
/* محاسبه                                                                */
/* -------------------------------------------------------------------- */

function modir_price_calc_suggested($purchase_price, $margin, $gateway_id) {
    $purchase_price = (float) $purchase_price;
    $margin         = (float) $margin;

    $cfg     = function_exists('modir_orders_get_commission') ? modir_orders_get_commission($gateway_id) : array('percent' => 0, 'fixed' => 0);
    $percent = (float) $cfg['percent'];
    $fixed   = (float) $cfg['fixed'];

    $numerator   = ($purchase_price * (1 + $margin / 100)) + $fixed;
    $denominator = 1 - ($percent / 100);

    if ($denominator <= 0) {
        return null;
    }

    return $numerator / $denominator;
}

function modir_price_calc_get_margin_for_product($product_id, $default_margin) {
    $override = get_post_meta($product_id, '_modir_margin_override', true);
    return ('' === $override || null === $override) ? $default_margin : (float) $override;
}

/* -------------------------------------------------------------------- */
/* AJAX: لیست محصولات                                                    */
/* -------------------------------------------------------------------- */

function modir_price_calc_render_rows($search = '', $offset = 0, $per_page = 25) {
    $settings = modir_price_calc_get_settings();

    $query_args = array(
        'status'  => array('publish', 'draft', 'private'),
        'limit'   => $per_page,
        'offset'  => $offset,
        'orderby' => 'title',
        'order'   => 'ASC',
        'return'  => 'objects',
        'type'    => array('simple', 'external'),
    );
    if ($search) {
        $query_args['s'] = $search;
    }

    $products = wc_get_products($query_args);

    ob_start();
    foreach ($products as $p) :
        $pid              = $p->get_id();
        $purchase_price   = $p->get_meta('_modir_purchase_price');
        $margin_override  = get_post_meta($pid, '_modir_margin_override', true);
        $margin_effective = modir_price_calc_get_margin_for_product($pid, $settings['default_margin']);
        $suggested        = ('' !== $purchase_price && null !== $purchase_price) ? modir_price_calc_suggested($purchase_price, $margin_effective, $settings['reference_gateway']) : null;
        $img              = $p->get_image(array('width' => 24, 'height' => 24));
        ?>
        <tr data-product-id="<?php echo esc_attr($pid); ?>">
            <td><?php echo wp_kses_post($img); ?></td>
            <td><?php echo esc_html($p->get_name()); ?></td>
            <td class="modir-text-muted"><?php echo wp_kses_post(wc_price($p->get_price())); ?></td>
            <td><input type="number" step="0.01" min="0" class="modir-grid-input modir-pc-purchase" value="<?php echo esc_attr($purchase_price); ?>"></td>
            <td><input type="number" step="0.1" min="0" class="modir-grid-input modir-pc-margin" placeholder="پیش‌فرض (<?php echo esc_attr($settings['default_margin']); ?>٪)" value="<?php echo esc_attr($margin_override); ?>"></td>
            <td class="modir-pc-suggested">
                <?php if (null === $suggested) : ?>
                    <span class="modir-text-muted">—</span>
                <?php else : ?>
                    <strong><?php echo wp_kses_post(wc_price($suggested)); ?></strong>
                <?php endif; ?>
            </td>
            <td class="modir-pc-status">
                <?php if (!empty($settings['sync_enabled']) && null !== $suggested) : ?>
                    <span class="modir-stat-badge is-good">همگام با ووکامرس</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach;

    return array('html' => ob_get_clean(), 'count' => count($products), 'has_more' => (count($products) === $per_page));
}

function modir_price_calc_ajax_list() {
    check_ajax_referer('modir_price_calc_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }
    $result = modir_price_calc_render_rows(
        isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '',
        isset($_POST['offset']) ? absint($_POST['offset']) : 0
    );
    wp_send_json_success($result);
}

/* -------------------------------------------------------------------- */
/* AJAX: ذخیره قیمت خرید/درصد سود اختصاصی یک محصول (و همگام‌سازی احتمالی)   */
/* -------------------------------------------------------------------- */

function modir_price_calc_ajax_save() {
    check_ajax_referer('modir_price_calc_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }

    $pid              = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $purchase_price   = isset($_POST['purchase_price']) ? wp_unslash($_POST['purchase_price']) : '';
    $margin_override  = isset($_POST['margin']) ? wp_unslash($_POST['margin']) : '';

    $product = $pid ? wc_get_product($pid) : false;
    if (!$product) {
        wp_send_json_error(array('message' => 'محصول پیدا نشد.'));
    }

    $product->update_meta_data('_modir_purchase_price', '' === $purchase_price ? '' : wc_format_decimal($purchase_price));
    $product->update_meta_data('_modir_margin_override', '' === $margin_override ? '' : (float) $margin_override);
    $product->save();

    $settings  = modir_price_calc_get_settings();
    $margin    = modir_price_calc_get_margin_for_product($pid, $settings['default_margin']);
    $suggested = ('' !== $purchase_price) ? modir_price_calc_suggested($purchase_price, $margin, $settings['reference_gateway']) : null;

    $synced = false;
    if (!empty($settings['sync_enabled']) && null !== $suggested) {
        $product->set_regular_price(wc_format_decimal($suggested));
        $product->save();
        $synced = true;
    }

    wp_send_json_success(array(
        'suggested' => (null === $suggested) ? null : wc_price($suggested),
        'synced'    => $synced,
    ));
}

/* -------------------------------------------------------------------- */
/* AJAX: اعمال گروهی قیمت پیشنهادی روی همه محصولاتی که قیمت خرید دارند      */
/* -------------------------------------------------------------------- */

function modir_price_calc_ajax_bulk_apply() {
    check_ajax_referer('modir_price_calc_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }

    $settings = modir_price_calc_get_settings();

    $products = wc_get_products(array(
        'status'     => array('publish', 'draft', 'private'),
        'limit'      => -1,
        'type'       => array('simple', 'external'),
        'return'     => 'objects',
        'meta_query' => array(
            array('key' => '_modir_purchase_price', 'value' => '', 'compare' => '!='),
        ),
    ));

    $count = 0;
    foreach ($products as $p) {
        $purchase = $p->get_meta('_modir_purchase_price');
        if ('' === $purchase || null === $purchase) {
            continue;
        }
        $margin    = modir_price_calc_get_margin_for_product($p->get_id(), $settings['default_margin']);
        $suggested = modir_price_calc_suggested($purchase, $margin, $settings['reference_gateway']);
        if (null === $suggested) {
            continue;
        }
        $p->set_regular_price(wc_format_decimal($suggested));
        $p->save();
        $count++;
    }

    wp_send_json_success(array('count' => $count, 'message' => $count . ' محصول به‌روزرسانی شد.'));
}

/* ======================================================================
 * رندر تب
 * ====================================================================== */

function modir_render_price_calc_tab_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $settings = modir_price_calc_get_settings();
    $gateways = function_exists('modir_orders_get_all_gateway_options') ? modir_orders_get_all_gateway_options() : array();
    ?>
    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div>
    <?php endif; ?>

    <form method="post" class="modir-filter-form">
        <?php wp_nonce_field('modir_price_calc_save_settings', 'modir_price_calc_settings_nonce'); ?>
        <div class="modir-filter-row">
            <div class="modir-filter-field">
                <label>درصد سود پیش‌فرض</label>
                <input type="number" step="0.1" min="0" name="default_margin" value="<?php echo esc_attr($settings['default_margin']); ?>" style="width:110px;">
            </div>
            <div class="modir-filter-field">
                <label>درگاه مرجع برای محاسبه کارمزد</label>
                <select name="reference_gateway" class="modir-pill-select">
                    <option value="">بدون کارمزد (فقط سود خالص)</option>
                    <?php foreach ($gateways as $gid => $title) : ?>
                        <option value="<?php echo esc_attr($gid); ?>" <?php selected($settings['reference_gateway'], $gid); ?>><?php echo esc_html($title); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modir-filter-field">
                <label>&nbsp;</label>
                <label class="modir-toggle">
                    <input type="checkbox" name="sync_enabled" value="1" <?php checked(!empty($settings['sync_enabled'])); ?>>
                    قیمت ووکامرس خودکار با قیمت پیشنهادی جایگزین شود
                </label>
            </div>
            <div class="modir-filter-field modir-filter-submit">
                <button type="submit" class="button button-primary modir-btn">ذخیره تنظیمات</button>
            </div>
        </div>
    </form>

    <div class="modir-alert">
        فرمول: قیمت پیشنهادی طوری محاسبه می‌شود که بعد از کسر کارمزد درگاه مرجع، دقیقاً همان درصد سود (نسبت به قیمت خرید) برایت بماند. تغییر قیمت خرید یا درصد سود هر محصول، بلافاصله قیمت پیشنهادی را به‌روز می‌کند.
    </div>

    <?php if (!empty($settings['sync_enabled'])) : ?>
        <p>
            <button type="button" class="button modir-btn" id="modir-pc-bulk-apply">اعمال قیمت پیشنهادی روی همه محصولات دارای قیمت خرید</button>
            <span id="modir-pc-bulk-result" style="margin-inline-start:10px;"></span>
        </p>
    <?php endif; ?>

    <div class="modir-toolbar">
        <div style="flex:1;"></div>
        <input type="text" class="modir-search-input" id="modir-pc-search" placeholder="جست‌وجوی محصول...">
        <button type="button" class="modir-search-btn" id="modir-pc-search-btn"><span class="dashicons dashicons-search"></span></button>
    </div>

    <table class="widefat modir-table modir-product-table modir-products-grid">
        <thead>
            <tr>
                <th style="width:40px;"></th>
                <th>نام محصول</th>
                <th style="width:110px;">قیمت فعلی</th>
                <th style="width:120px;">قیمت خرید</th>
                <th style="width:130px;">درصد سود</th>
                <th style="width:130px;">قیمت پیشنهادی</th>
                <th style="width:120px;"></th>
            </tr>
        </thead>
        <tbody id="modir-pc-rows"></tbody>
    </table>

    <p style="text-align:center;margin-top:16px;">
        <button type="button" class="button modir-btn" id="modir-pc-load-more">بارگذاری محصولات بیشتر</button>
    </p>
    <?php
}
