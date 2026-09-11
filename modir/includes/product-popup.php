<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «پاپ‌آپ محصول»: پیشنهاد هزینه‌های جانبی و محصولات مکمل هنگام افزودن به سبد خرید
 * ==========================================================================
 */

function modir_popup_init() {
    add_action('admin_init', 'modir_popup_maybe_save_group');
    add_action('admin_init', 'modir_popup_maybe_delete_group');
    add_action('admin_init', 'modir_popup_maybe_toggle_group');
    add_action('admin_init', 'modir_popup_maybe_save_settings');

    add_action('admin_enqueue_scripts', 'modir_popup_admin_assets');
    add_action('wp_enqueue_scripts', 'modir_popup_frontend_assets');

    add_action('wp_ajax_modir_popup_get_popup', 'modir_popup_ajax_get_popup');
    add_action('wp_ajax_nopriv_modir_popup_get_popup', 'modir_popup_ajax_get_popup');

    add_action('wp_ajax_modir_popup_add_to_cart', 'modir_popup_ajax_add_to_cart');
    add_action('wp_ajax_nopriv_modir_popup_add_to_cart', 'modir_popup_ajax_add_to_cart');

    add_action('wp_ajax_modir_popup_remove_addon', 'modir_popup_ajax_remove_addon');
    add_action('wp_ajax_nopriv_modir_popup_remove_addon', 'modir_popup_ajax_remove_addon');

    add_action('woocommerce_cart_calculate_fees', 'modir_popup_apply_fees');
    add_filter('woocommerce_cart_item_name', 'modir_popup_cart_item_name', 20, 3);
}

/* -------------------------------------------------------------------- */
/* دیتای گروه‌ها                                                         */
/* -------------------------------------------------------------------- */

function modir_popup_get_groups() {
    $groups = get_option('modir_addon_groups', array());
    return is_array($groups) ? $groups : array();
}

function modir_popup_get_group($id) {
    $groups = modir_popup_get_groups();
    return isset($groups[$id]) ? $groups[$id] : null;
}

function modir_popup_save_groups($groups) {
    update_option('modir_addon_groups', $groups);
}

function modir_popup_new_group_defaults() {
    return array(
        'id'             => 'grp_' . uniqid(),
        'title'          => '',
        'active'         => 1,
        'target_type'    => 'product',
        'target_ids'     => array(),
        'selection_mode' => 'multiple',
        'items'          => array(),
    );
}

function modir_popup_new_item_defaults() {
    return array(
        'id'               => 'itm_' . uniqid(),
        'type'             => 'fee',
        'label'            => '',
        'required'         => 0,
        'default_selected' => 0,
        'price_type'       => 'fixed',
        'price_value'      => 0,
        'product_id'       => 0,
    );
}

/* -------------------------------------------------------------------- */
/* تطبیق گروه‌های فعال با یک محصول                                        */
/* -------------------------------------------------------------------- */

function modir_popup_matching_groups($product_id) {
    $product_id = (int) $product_id;
    $matched    = array();

    foreach (modir_popup_get_groups() as $group) {
        if (empty($group['active']) || empty($group['items'])) {
            continue;
        }

        $target_ids = array_map('intval', (array) $group['target_ids']);
        $hit        = false;

        switch ($group['target_type']) {
            case 'category':
                $hit = ! empty($target_ids) && has_term($target_ids, 'product_cat', $product_id);
                break;
            case 'tag':
                $hit = ! empty($target_ids) && has_term($target_ids, 'product_tag', $product_id);
                break;
            default:
                $hit = in_array($product_id, $target_ids, true);
                break;
        }

        if ($hit) {
            $matched[] = $group;
        }
    }

    return $matched;
}

/* -------------------------------------------------------------------- */
/* ذخیره/حذف/تغییر وضعیت گروه (روی admin_init با ریدایرکت)                */
/* -------------------------------------------------------------------- */

function modir_popup_maybe_save_group() {
    if (empty($_POST['modir_popup_group_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_popup_group_nonce'])), 'modir_popup_save_group')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $groups = modir_popup_get_groups();

    $id = isset($_POST['group_id']) ? sanitize_text_field(wp_unslash($_POST['group_id'])) : '';
    $group = ($id && isset($groups[$id])) ? $groups[$id] : modir_popup_new_group_defaults();
    if (!$id) {
        $id = $group['id'];
    }

    $group['id']             = $id;
    $group['title']          = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    $group['active']         = !empty($_POST['active']) ? 1 : 0;
    $group['target_type']    = isset($_POST['target_type']) && in_array($_POST['target_type'], array('product', 'category', 'tag'), true) ? sanitize_key($_POST['target_type']) : 'product';
    $group['selection_mode'] = (isset($_POST['selection_mode']) && 'single' === $_POST['selection_mode']) ? 'single' : 'multiple';

    $raw_target_ids     = isset($_POST['target_ids']) ? (array) wp_unslash($_POST['target_ids']) : array();
    $group['target_ids'] = array_values(array_filter(array_map('absint', $raw_target_ids)));

    $items = array();
    if (!empty($_POST['items']) && is_array($_POST['items'])) {
        foreach (wp_unslash($_POST['items']) as $row) {
            if (empty($row['label']) && empty($row['product_id'])) {
                continue; // ردیف خالی نادیده گرفته می‌شود
            }

            $item_id = !empty($row['id']) ? sanitize_text_field($row['id']) : 'itm_' . uniqid();
            $type    = (isset($row['type']) && 'product' === $row['type']) ? 'product' : 'fee';

            $item = array(
                'id'               => $item_id,
                'type'             => $type,
                'label'            => isset($row['label']) ? sanitize_text_field($row['label']) : '',
                'required'         => !empty($row['required']) ? 1 : 0,
                'default_selected' => !empty($row['default_selected']) ? 1 : 0,
                'price_type'       => (isset($row['price_type']) && 'percent' === $row['price_type']) ? 'percent' : 'fixed',
                'price_value'      => isset($row['price_value']) ? (float) $row['price_value'] : 0,
                'product_id'       => isset($row['product_id']) ? absint($row['product_id']) : 0,
            );

            if ('product' === $type && empty($item['label'])) {
                $p = wc_get_product($item['product_id']);
                $item['label'] = $p ? $p->get_name() : '';
            }

            $items[] = $item;
        }
    }
    $group['items'] = $items;

    $groups[$id] = $group;
    modir_popup_save_groups($groups);

    wp_safe_redirect(add_query_arg(array('page' => 'modir', 'tab' => 'popup', 'ptab' => 'groups', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

function modir_popup_maybe_delete_group() {
    if (empty($_GET['modir_popup_action']) || 'delete' !== $_GET['modir_popup_action']) {
        return;
    }
    if (empty($_GET['group_id']) || empty($_GET['_wpnonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'modir_popup_delete_group')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $groups = modir_popup_get_groups();
    $id     = sanitize_text_field(wp_unslash($_GET['group_id']));
    unset($groups[$id]);
    modir_popup_save_groups($groups);

    wp_safe_redirect(add_query_arg(array('page' => 'modir', 'tab' => 'popup', 'ptab' => 'groups', 'deleted' => '1'), admin_url('admin.php')));
    exit;
}

function modir_popup_maybe_toggle_group() {
    if (empty($_GET['modir_popup_action']) || 'toggle' !== $_GET['modir_popup_action']) {
        return;
    }
    if (empty($_GET['group_id']) || empty($_GET['_wpnonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'modir_popup_toggle_group')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $groups = modir_popup_get_groups();
    $id     = sanitize_text_field(wp_unslash($_GET['group_id']));
    if (isset($groups[$id])) {
        $groups[$id]['active'] = empty($groups[$id]['active']) ? 1 : 0;
        modir_popup_save_groups($groups);
    }

    wp_safe_redirect(add_query_arg(array('page' => 'modir', 'tab' => 'popup', 'ptab' => 'groups'), admin_url('admin.php')));
    exit;
}

function modir_popup_maybe_save_settings() {
    if (empty($_POST['modir_popup_settings_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_popup_settings_nonce'])), 'modir_popup_save_settings')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    update_option('modir_popup_enabled', !empty($_POST['modir_popup_enabled']) ? 1 : 0);

    wp_safe_redirect(add_query_arg(array('page' => 'modir', 'tab' => 'popup', 'ptab' => 'settings', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

/* -------------------------------------------------------------------- */
/* بارگذاری استایل/اسکریپت ادمین                                        */
/* -------------------------------------------------------------------- */

function modir_popup_admin_assets($hook) {
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    $tab  = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
    $is_new_url    = ('modir' === $page && 'popup' === $tab);
    $is_legacy_url = ('modir-popup' === $page);
    if (!$is_new_url && !$is_legacy_url) {
        return;
    }

    wp_enqueue_style('woocommerce_admin_styles');
    wp_enqueue_script('wc-enhanced-select');

    wp_enqueue_style('modir-popup-admin-css', MODIR_URL . 'assets/css/popup-admin.css', array(), MODIR_VERSION);
    wp_enqueue_script('modir-admin-popup-js', MODIR_URL . 'assets/js/admin-popup.js', array('jquery', 'wc-enhanced-select'), MODIR_VERSION, true);
}

/* -------------------------------------------------------------------- */
/* بارگذاری استایل/اسکریپت فرانت                                        */
/* -------------------------------------------------------------------- */

function modir_popup_frontend_assets() {
    if (empty(get_option('modir_popup_enabled', 0))) {
        return;
    }
    if (!(is_product() || is_cart() || is_checkout())) {
        return;
    }

    wp_enqueue_style('modir-popup-css', MODIR_URL . 'assets/css/popup.css', array(), MODIR_VERSION);
    wp_enqueue_script('modir-popup-js', MODIR_URL . 'assets/js/popup.js', array('jquery'), MODIR_VERSION, true);

    $has_groups = false;
    if (is_product()) {
        global $post;
        $pid        = $post ? $post->ID : 0;
        $has_groups = $pid ? !empty(modir_popup_matching_groups($pid)) : false;
    }

    wp_localize_script('modir-popup-js', 'ModirPopup', array(
        'ajaxUrl'   => admin_url('admin-ajax.php'),
        'nonce'     => wp_create_nonce('modir_popup_nonce'),
        'hasGroups' => $has_groups,
        'i18n'      => array(
            'adding'  => 'در حال افزودن...',
            'confirm' => 'افزودن به سبد خرید',
            'close'   => 'بستن',
            'error'   => 'خطایی رخ داد، لطفاً دوباره تلاش کنید.',
        ),
    ));
}

/* -------------------------------------------------------------------- */
/* AJAX: رندر محتوای پاپ‌آپ                                              */
/* -------------------------------------------------------------------- */

function modir_popup_ajax_get_popup() {
    check_ajax_referer('modir_popup_nonce', 'nonce');

    $product_id   = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;

    $display_product = wc_get_product($variation_id ?: $product_id);
    if (!$product_id || !$display_product) {
        wp_send_json_error(array('message' => 'محصول یافت نشد.'));
    }

    $groups = modir_popup_matching_groups($product_id);
    if (empty($groups)) {
        wp_send_json_error(array('message' => 'no_groups'));
    }

    ob_start();
    modir_popup_render_modal_content($display_product, $groups);
    $html = ob_get_clean();

    wp_send_json_success(array('html' => $html));
}

function modir_popup_render_modal_content($product, $groups) {
    $image = $product->get_image('woocommerce_thumbnail');
    ?>
    <div class="modir-popup-header">
        <div class="modir-popup-thumb"><?php echo wp_kses_post($image); ?></div>
        <div class="modir-popup-meta">
            <h3 class="modir-popup-product-title"><?php echo esc_html($product->get_name()); ?></h3>
            <div class="modir-popup-product-price"><?php echo wp_kses_post(wc_price($product->get_price())); ?></div>
        </div>
    </div>

    <form class="modir-popup-form">
        <input type="hidden" name="action" value="modir_popup_add_to_cart">
        <input type="hidden" name="product_id" value="">
        <input type="hidden" name="variation_id" value="">
        <input type="hidden" name="quantity" value="1">

        <?php foreach ($groups as $group) : ?>
            <fieldset class="modir-popup-group">
                <legend><?php echo esc_html($group['title']); ?></legend>

                <?php foreach ($group['items'] as $item) :
                    $price = modir_popup_calc_item_price($item, $product);
                    ?>
                    <?php if (!empty($item['required'])) : ?>
                        <label class="modir-popup-row modir-popup-row-locked" data-price="<?php echo esc_attr($price); ?>" data-required="1">
                            <span class="modir-popup-row-icon" aria-hidden="true">&#128274;</span>
                            <span class="modir-popup-row-label"><?php echo esc_html($item['label']); ?> <em>(اجباری)</em></span>
                            <span class="modir-popup-row-price"><?php echo wp_kses_post(wc_price($price)); ?></span>
                            <input type="hidden" name="modir_popup_group_<?php echo esc_attr($group['id']); ?>[]" value="<?php echo esc_attr($item['id']); ?>">
                        </label>
                    <?php else : ?>
                        <label class="modir-popup-row" data-price="<?php echo esc_attr($price); ?>" data-required="0">
                            <span class="modir-popup-row-input">
                                <input type="<?php echo ('single' === $group['selection_mode']) ? 'radio' : 'checkbox'; ?>"
                                       name="modir_popup_group_<?php echo esc_attr($group['id']); ?>[]"
                                       value="<?php echo esc_attr($item['id']); ?>"
                                       <?php checked(!empty($item['default_selected'])); ?>>
                            </span>
                            <span class="modir-popup-row-label"><?php echo esc_html($item['label']); ?></span>
                            <span class="modir-popup-row-price">+<?php echo wp_kses_post(wc_price($price)); ?></span>
                        </label>
                    <?php endif; ?>
                <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>

        <div class="modir-popup-footer">
            <button type="button" class="modir-popup-confirm modir-btn">افزودن به سبد خرید</button>
        </div>
    </form>
    <?php
}

function modir_popup_calc_item_price($item, $product) {
    if ('product' === $item['type']) {
        $p = wc_get_product($item['product_id']);
        return $p ? (float) $p->get_price() : 0;
    }

    if ('percent' === $item['price_type']) {
        $base = $product ? (float) $product->get_price() : 0;
        return round($base * ((float) $item['price_value'] / 100), 2);
    }

    return (float) $item['price_value'];
}

/* -------------------------------------------------------------------- */
/* AJAX: افزودن به سبد خرید همراه با انتخاب‌ها                            */
/* -------------------------------------------------------------------- */

function modir_popup_ajax_add_to_cart() {
    check_ajax_referer('modir_popup_nonce', 'nonce');

    $product_id   = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
    $quantity     = isset($_POST['quantity']) ? wc_stock_amount(wp_unslash($_POST['quantity'])) : 1;

    $variation_data = array();
    if ($variation_id && !empty($_POST['variation']) && is_array($_POST['variation'])) {
        foreach (wp_unslash($_POST['variation']) as $k => $v) {
            $variation_data[sanitize_text_field($k)] = sanitize_text_field($v);
        }
    }

    if (!$product_id || !wc_get_product($product_id)) {
        wp_send_json_error(array('message' => 'محصول نامعتبر است.'));
    }

    $groups              = modir_popup_matching_groups($product_id);
    $main_product_for_fee = wc_get_product($variation_id ?: $product_id);

    $selected_fees   = array();
    $extra_products  = array();

    foreach ($groups as $group) {
        $posted_key = 'modir_popup_group_' . $group['id'];
        $posted_raw = isset($_POST[$posted_key]) ? array_map('sanitize_text_field', (array) wp_unslash($_POST[$posted_key])) : array();

        $chosen_optional = array();

        foreach ($group['items'] as $item) {
            if (!empty($item['required'])) {
                if ('fee' === $item['type']) {
                    $selected_fees[] = modir_popup_build_fee_row($item, $main_product_for_fee);
                } elseif ('product' === $item['type'] && $item['product_id']) {
                    $extra_products[(int) $item['product_id']] = true;
                }
                continue;
            }

            if (in_array($item['id'], $posted_raw, true)) {
                $chosen_optional[] = $item;
            }
        }

        if ('single' === $group['selection_mode'] && count($chosen_optional) > 1) {
            $chosen_optional = array($chosen_optional[0]);
        }

        foreach ($chosen_optional as $item) {
            if ('fee' === $item['type']) {
                $selected_fees[] = modir_popup_build_fee_row($item, $main_product_for_fee);
            } elseif ('product' === $item['type'] && $item['product_id']) {
                $extra_products[(int) $item['product_id']] = true;
            }
        }
    }

    $cart_item_data = array();
    if (!empty($selected_fees)) {
        $cart_item_data['modir_addons'] = $selected_fees;
    }

    $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation_data, $cart_item_data);

    if (!$cart_item_key) {
        $message = wc_has_notices() ? wp_strip_all_tags(wc_print_notices(true)) : 'افزودن به سبد خرید ناموفق بود.';
        wp_send_json_error(array('message' => $message));
    }

    foreach (array_keys($extra_products) as $pid) {
        if ($pid && wc_get_product($pid)) {
            WC()->cart->add_to_cart($pid, 1);
        }
    }

    do_action('woocommerce_ajax_added_to_cart', $product_id);

    wp_send_json_success(array(
        'cart_hash' => WC()->cart->get_cart_hash(),
        'fragments' => apply_filters('woocommerce_add_to_cart_fragments', array()),
        'redirect'  => ('yes' === get_option('woocommerce_cart_redirect_after_add')) ? wc_get_cart_url() : false,
    ));
}

function modir_popup_build_fee_row($item, $product) {
    return array(
        'item_id'  => $item['id'],
        'label'    => $item['label'],
        'price'    => modir_popup_calc_item_price($item, $product),
        'required' => !empty($item['required']),
    );
}

/* -------------------------------------------------------------------- */
/* اعمال هزینه‌های انتخابی روی سبد خرید                                    */
/* -------------------------------------------------------------------- */

function modir_popup_apply_fees($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }
    if (empty($cart) || !method_exists($cart, 'get_cart') || empty($cart->get_cart())) {
        return;
    }

    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        if (empty($cart_item['modir_addons']) || !is_array($cart_item['modir_addons'])) {
            continue;
        }

        foreach ($cart_item['modir_addons'] as $addon) {
            $amount = isset($addon['price']) ? (float) $addon['price'] : 0;
            if ($amount <= 0 && empty($addon['required'])) {
                continue;
            }

            $product_name = isset($cart_item['data']) && is_object($cart_item['data']) ? $cart_item['data']->get_name() : '';
            $fee_name     = $addon['label'] . ($product_name ? ' (' . $product_name . ')' : '');
            $fee_id       = 'modir_addon_' . substr(md5($cart_item_key . $addon['item_id']), 0, 16);

            if (is_callable(array($cart, 'fees_api'))) {
                $cart->fees_api()->add_fee(array(
                    'id'      => $fee_id,
                    'name'    => $fee_name,
                    'amount'  => $amount,
                    'taxable' => false,
                ));
            } else {
                $cart->add_fee($fee_name, $amount, false);
            }
        }
    }
}

/* -------------------------------------------------------------------- */
/* نمایش ردیف‌های هزینه/محصول زیر نام کالا در سبد خرید                     */
/* -------------------------------------------------------------------- */

function modir_popup_cart_item_name($name, $cart_item, $cart_item_key) {
    if (empty($cart_item['modir_addons']) || !is_array($cart_item['modir_addons'])) {
        return $name;
    }

    $allow_remove = function_exists('is_cart') && is_cart();
    $rows         = '';

    foreach ($cart_item['modir_addons'] as $addon) {
        $price_html = wc_price($addon['price']);

        if (!empty($addon['required'])) {
            $rows .= '<div class="modir-cart-addon modir-cart-addon-locked"><span class="modir-cart-addon-lock">&#128274;</span> '
                . esc_html($addon['label']) . ' — ' . $price_html . '</div>';
        } else {
            $remove = '';
            if ($allow_remove) {
                $remove = ' <a href="#" class="modir-cart-addon-remove" data-cart-item-key="' . esc_attr($cart_item_key) . '" data-item-id="' . esc_attr($addon['item_id']) . '" title="حذف">&times;</a>';
            }
            $rows .= '<div class="modir-cart-addon">' . esc_html($addon['label']) . ' — ' . $price_html . $remove . '</div>';
        }
    }

    return $name . '<div class="modir-cart-addons">' . $rows . '</div>';
}

/* -------------------------------------------------------------------- */
/* AJAX: حذف یک هزینه اختیاری از سبد خرید                                 */
/* -------------------------------------------------------------------- */

function modir_popup_ajax_remove_addon() {
    check_ajax_referer('modir_popup_nonce', 'nonce');

    $cart_item_key = isset($_POST['cart_item_key']) ? sanitize_text_field(wp_unslash($_POST['cart_item_key'])) : '';
    $item_id       = isset($_POST['item_id']) ? sanitize_text_field(wp_unslash($_POST['item_id'])) : '';

    if (!$cart_item_key || !$item_id || !isset(WC()->cart->cart_contents[$cart_item_key])) {
        wp_send_json_error();
    }

    $cart_item = WC()->cart->cart_contents[$cart_item_key];
    if (empty($cart_item['modir_addons']) || !is_array($cart_item['modir_addons'])) {
        wp_send_json_error();
    }

    $updated = array();
    foreach ($cart_item['modir_addons'] as $addon) {
        if ($addon['item_id'] === $item_id && empty($addon['required'])) {
            continue;
        }
        $updated[] = $addon;
    }

    WC()->cart->cart_contents[$cart_item_key]['modir_addons'] = $updated;
    WC()->cart->set_session();

    wp_send_json_success();
}

/* ======================================================================
 * صفحه ادمین
 * ====================================================================== */

function modir_popup_render_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $tabs = array(
        'groups'   => 'گروه‌ها',
        'settings' => 'تنظیمات',
    );
    $ptab = isset($_GET['ptab']) ? sanitize_key(wp_unslash($_GET['ptab'])) : 'groups';
    if (!isset($tabs[$ptab])) {
        $ptab = 'groups';
    }

    $action = isset($_GET['modir_popup_action']) ? sanitize_key(wp_unslash($_GET['modir_popup_action'])) : '';
    ?>
    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>تغییرات با موفقیت ذخیره شد.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])) : ?>
        <div class="notice notice-success is-dismissible"><p>گروه حذف شد.</p></div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper modir-tabs">
        <?php foreach ($tabs as $key => $label) :
            $url    = add_query_arg(array('page' => 'modir', 'tab' => 'popup', 'ptab' => $key), admin_url('admin.php'));
            $active = ($ptab === $key) ? ' nav-tab-active' : '';
            ?>
            <a href="<?php echo esc_url($url); ?>" class="nav-tab<?php echo esc_attr($active); ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="modir-tab-panel">
        <?php
        if ('settings' === $ptab) {
            modir_popup_render_settings_tab();
        } elseif ('new' === $action || 'edit' === $action) {
            modir_popup_render_group_form($action);
        } else {
            modir_popup_render_groups_list();
        }
        ?>
    </div>
    <?php
}

/**
 * برای لینک‌های قدیمی/بوکمارک‌شده: مستقیم صفحه محصولات را با تب «پاپ‌آپ
 * محصول» فعال نمایش می‌دهد، چون این ماژول به آنجا منتقل شده است.
 */
function modir_render_popup_page() {
    $_GET['tab'] = 'popup';
    modir_render_products_page();
}

function modir_popup_render_settings_tab() {
    $enabled = get_option('modir_popup_enabled', 0);
    ?>
    <form method="post">
        <?php wp_nonce_field('modir_popup_save_settings', 'modir_popup_settings_nonce'); ?>
        <table class="form-table">
            <tr>
                <th scope="row">فعال‌سازی پاپ‌آپ محصول</th>
                <td>
                    <label class="modir-toggle">
                        <input type="checkbox" name="modir_popup_enabled" value="1" <?php checked($enabled, 1); ?>>
                        این ماژول برای کل سایت فعال باشد
                    </label>
                    <p class="description">حتی اگر این گزینه فعال باشد، پاپ‌آپ فقط برای محصولات/دسته‌ها/برچسب‌هایی نمایش داده می‌شود که در تب «گروه‌ها» به آن‌ها متصل کرده‌اید.</p>
                </td>
            </tr>
        </table>
        <?php submit_button('ذخیره تنظیمات', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>
    <?php
}

function modir_popup_render_groups_list() {
    $groups = modir_popup_get_groups();
    $new_url = add_query_arg(array('page' => 'modir', 'tab' => 'popup', 'ptab' => 'groups', 'modir_popup_action' => 'new'), admin_url('admin.php'));

    $type_labels = array('product' => 'محصول خاص', 'category' => 'دسته‌بندی', 'tag' => 'برچسب');
    ?>
    <p>
        <a href="<?php echo esc_url($new_url); ?>" class="button button-primary modir-btn">+ افزودن گروه جدید</a>
    </p>

    <?php if (empty($groups)) : ?>
        <p class="modir-empty">هنوز هیچ گروهی نساخته‌اید.</p>
    <?php else : ?>
        <table class="widefat striped modir-table">
            <thead>
                <tr>
                    <th>عنوان گروه</th>
                    <th>نوع هدف</th>
                    <th>تعداد آیتم</th>
                    <th>حالت انتخاب</th>
                    <th>وضعیت</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $group) :
                    $edit_url   = add_query_arg(array('page' => 'modir', 'tab' => 'popup', 'ptab' => 'groups', 'modir_popup_action' => 'edit', 'group_id' => $group['id']), admin_url('admin.php'));
                    $toggle_url = wp_nonce_url(add_query_arg(array('page' => 'modir', 'tab' => 'popup', 'modir_popup_action' => 'toggle', 'group_id' => $group['id']), admin_url('admin.php')), 'modir_popup_toggle_group');
                    $delete_url = wp_nonce_url(add_query_arg(array('page' => 'modir', 'tab' => 'popup', 'modir_popup_action' => 'delete', 'group_id' => $group['id']), admin_url('admin.php')), 'modir_popup_delete_group');
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($group['title'] ? $group['title'] : '(بدون عنوان)'); ?></strong></td>
                        <td><?php echo esc_html($type_labels[$group['target_type']] ?? $group['target_type']); ?></td>
                        <td><?php echo number_format_i18n(count($group['items'])); ?></td>
                        <td><?php echo ('single' === $group['selection_mode']) ? 'تک‌انتخابی' : 'چندانتخابی'; ?></td>
                        <td>
                            <a href="<?php echo esc_url($toggle_url); ?>" class="modir-status-badge <?php echo !empty($group['active']) ? 'is-active' : 'is-inactive'; ?>">
                                <?php echo !empty($group['active']) ? 'فعال' : 'غیرفعال'; ?>
                            </a>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($edit_url); ?>">ویرایش</a>
                            |
                            <a href="<?php echo esc_url($delete_url); ?>" class="modir-link-danger" onclick="return confirm('حذف این گروه مطمئن هستید؟');">حذف</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
}

function modir_popup_render_group_form($action) {
    $group = modir_popup_new_group_defaults();

    if ('edit' === $action && !empty($_GET['group_id'])) {
        $existing = modir_popup_get_group(sanitize_text_field(wp_unslash($_GET['group_id'])));
        if ($existing) {
            $group = $existing;
        }
    }

    $back_url = add_query_arg(array('page' => 'modir', 'tab' => 'popup', 'ptab' => 'groups'), admin_url('admin.php'));

    $terms_cat = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
    $terms_tag = get_terms(array('taxonomy' => 'product_tag', 'hide_empty' => false));
    ?>
    <p><a href="<?php echo esc_url($back_url); ?>">&rarr; بازگشت به لیست گروه‌ها</a></p>

    <form method="post" id="modir-popup-group-form">
        <?php wp_nonce_field('modir_popup_save_group', 'modir_popup_group_nonce'); ?>
        <input type="hidden" name="group_id" value="<?php echo esc_attr($group['id']); ?>">

        <table class="form-table">
            <tr>
                <th scope="row">عنوان گروه (داخلی)</th>
                <td><input type="text" name="title" class="regular-text" value="<?php echo esc_attr($group['title']); ?>" placeholder="مثلاً: گارانتی و بیمه لپ‌تاپ"></td>
            </tr>
            <tr>
                <th scope="row">فعال باشد</th>
                <td>
                    <label class="modir-toggle">
                        <input type="checkbox" name="active" value="1" <?php checked(!empty($group['active'])); ?>>
                        این گروه در پاپ‌آپ نمایش داده شود
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">اتصال به</th>
                <td>
                    <p>
                        <label><input type="radio" name="target_type" value="product" class="modir-target-type-radio" <?php checked($group['target_type'], 'product'); ?>> محصول خاص</label>
                        &nbsp;&nbsp;
                        <label><input type="radio" name="target_type" value="category" class="modir-target-type-radio" <?php checked($group['target_type'], 'category'); ?>> دسته‌بندی</label>
                        &nbsp;&nbsp;
                        <label><input type="radio" name="target_type" value="tag" class="modir-target-type-radio" <?php checked($group['target_type'], 'tag'); ?>> برچسب</label>
                    </p>

                    <div class="modir-target-select modir-target-select-product" style="<?php echo ('product' === $group['target_type']) ? '' : 'display:none;'; ?>">
                        <select class="wc-product-search" multiple="multiple" style="width:60%;" name="target_ids[]" data-placeholder="جستجو و انتخاب محصول..." data-action="woocommerce_json_search_products">
                            <?php foreach ($group['target_ids'] as $pid) :
                                $p = ('product' === $group['target_type']) ? wc_get_product($pid) : null;
                                if ($p) : ?>
                                    <option value="<?php echo esc_attr($pid); ?>" selected="selected"><?php echo esc_html($p->get_name()); ?></option>
                                <?php endif;
                            endforeach; ?>
                        </select>
                    </div>

                    <div class="modir-target-select modir-target-select-category" style="<?php echo ('category' === $group['target_type']) ? '' : 'display:none;'; ?>">
                        <select class="wc-enhanced-select" multiple="multiple" style="width:60%;" name="target_ids[]" data-placeholder="انتخاب دسته‌بندی...">
                            <?php foreach ($terms_cat as $term) : ?>
                                <option value="<?php echo esc_attr($term->term_id); ?>" <?php selected(('category' === $group['target_type']) && in_array((int) $term->term_id, array_map('intval', $group['target_ids']), true)); ?>><?php echo esc_html($term->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="modir-target-select modir-target-select-tag" style="<?php echo ('tag' === $group['target_type']) ? '' : 'display:none;'; ?>">
                        <select class="wc-enhanced-select" multiple="multiple" style="width:60%;" name="target_ids[]" data-placeholder="انتخاب برچسب...">
                            <?php foreach ($terms_tag as $term) : ?>
                                <option value="<?php echo esc_attr($term->term_id); ?>" <?php selected(('tag' === $group['target_type']) && in_array((int) $term->term_id, array_map('intval', $group['target_ids']), true)); ?>><?php echo esc_html($term->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row">حالت انتخاب آیتم‌های اختیاری</th>
                <td>
                    <label><input type="radio" name="selection_mode" value="multiple" <?php checked($group['selection_mode'], 'multiple'); ?>> چندانتخابی (چک‌باکس)</label>
                    &nbsp;&nbsp;
                    <label><input type="radio" name="selection_mode" value="single" <?php checked($group['selection_mode'], 'single'); ?>> تک‌انتخابی (رادیو)</label>
                </td>
            </tr>
        </table>

        <h2 class="modir-subtitle">آیتم‌های گروه (هزینه یا محصول مکمل)</h2>

        <div id="modir-popup-items-wrap">
            <?php
            $index = 0;
            foreach ($group['items'] as $item) {
                modir_popup_render_item_row($item, $index);
                $index++;
            }
            ?>
        </div>

        <p><button type="button" class="button" id="modir-popup-add-item">+ افزودن آیتم</button></p>

        <div id="modir-popup-item-template" style="display:none;">
            <?php modir_popup_render_item_row(modir_popup_new_item_defaults(), '__INDEX__'); ?>
        </div>

        <?php submit_button('ذخیره گروه', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>
    <?php
}

function modir_popup_render_item_row($item, $index) {
    $is_fee = ('product' !== $item['type']);
    $p      = $item['product_id'] ? wc_get_product($item['product_id']) : null;
    ?>
    <div class="modir-item-row">
        <input type="hidden" name="items[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($item['id']); ?>">

        <div class="modir-item-row-main">
            <label>نوع
                <select name="items[<?php echo esc_attr($index); ?>][type]" class="modir-item-type-select">
                    <option value="fee" <?php selected($is_fee); ?>>هزینه</option>
                    <option value="product" <?php selected(!$is_fee); ?>>محصول مکمل</option>
                </select>
            </label>

            <label class="modir-item-fee-only">عنوان نمایشی
                <input type="text" name="items[<?php echo esc_attr($index); ?>][label]" value="<?php echo esc_attr($item['label']); ?>" placeholder="مثلاً: بیمه ۱۲ ماهه">
            </label>

            <label><input type="checkbox" name="items[<?php echo esc_attr($index); ?>][required]" value="1" <?php checked(!empty($item['required'])); ?>> اجباری</label>
            <label><input type="checkbox" name="items[<?php echo esc_attr($index); ?>][default_selected]" value="1" <?php checked(!empty($item['default_selected'])); ?>> پیش‌فرض انتخاب‌شده</label>
        </div>

        <div class="modir-item-row-fee modir-item-fee-only" style="<?php echo $is_fee ? '' : 'display:none;'; ?>">
            <label>نوع قیمت
                <select name="items[<?php echo esc_attr($index); ?>][price_type]">
                    <option value="fixed" <?php selected($item['price_type'], 'fixed'); ?>>مبلغ ثابت</option>
                    <option value="percent" <?php selected($item['price_type'], 'percent'); ?>>درصدی از قیمت محصول</option>
                </select>
            </label>
            <label>مقدار
                <input type="number" step="0.01" min="0" name="items[<?php echo esc_attr($index); ?>][price_value]" value="<?php echo esc_attr($item['price_value']); ?>">
            </label>
        </div>

        <div class="modir-item-row-product modir-item-product-only" style="<?php echo !$is_fee ? '' : 'display:none;'; ?>">
            <label>انتخاب محصول مکمل
                <select class="wc-product-search modir-item-product-search" style="width:320px;" name="items[<?php echo esc_attr($index); ?>][product_id]" data-placeholder="جستجوی محصول..." data-action="woocommerce_json_search_products">
                    <?php if ($p) : ?>
                        <option value="<?php echo esc_attr($p->get_id()); ?>" selected="selected"><?php echo esc_html($p->get_name()); ?></option>
                    <?php endif; ?>
                </select>
            </label>
        </div>

        <button type="button" class="button modir-item-remove">حذف آیتم</button>
    </div>
    <?php
}
