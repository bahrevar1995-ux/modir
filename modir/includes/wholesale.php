<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «عمده‌فروشی»: با فعال‌سازی این ماژول، هر محصول یک «قیمت عمده»
 * جداگانه می‌گیرد که فقط به کاربرانی با نقش «مشتری عمده» نمایش داده می‌شود.
 * ==========================================================================
 */

function modir_wholesale_init() {
    add_action('admin_init', 'modir_wholesale_maybe_save_settings');
    add_action('init', 'modir_wholesale_register_role');

    if (!modir_wholesale_is_enabled()) {
        return;
    }

    add_action('woocommerce_product_options_pricing', 'modir_wholesale_add_product_field');
    add_action('woocommerce_process_product_meta', 'modir_wholesale_save_product_field');
    add_action('woocommerce_product_after_variable_attributes', 'modir_wholesale_add_variation_field', 10, 3);
    add_action('woocommerce_save_product_variation', 'modir_wholesale_save_variation_field', 10, 1);

    add_filter('woocommerce_product_get_price', 'modir_wholesale_maybe_override_price', 20, 2);
    add_filter('woocommerce_product_variation_get_price', 'modir_wholesale_maybe_override_price', 20, 2);
}

function modir_wholesale_is_enabled() {
    return !empty(get_option('modir_wholesale_enabled', 0));
}

/* -------------------------------------------------------------------- */
/* نقش کاربری «مشتری عمده»                                              */
/* -------------------------------------------------------------------- */

function modir_wholesale_register_role() {
    if (!get_role('modir_wholesale_customer')) {
        $customer_role = get_role('customer');
        $caps          = $customer_role ? $customer_role->capabilities : array('read' => true);
        add_role('modir_wholesale_customer', 'مشتری عمده', $caps);
    }
}

function modir_wholesale_is_wholesale_customer($user_id = null) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    if (!$user_id) {
        return false;
    }
    $user = get_userdata($user_id);
    return $user && in_array('modir_wholesale_customer', (array) $user->roles, true);
}

/* -------------------------------------------------------------------- */
/* فیلد قیمت عمده در صفحه ویرایش محصول ووکامرس                            */
/* -------------------------------------------------------------------- */

function modir_wholesale_add_product_field() {
    echo '<div class="options_group">';
    woocommerce_wp_text_input(array(
        'id'          => '_modir_wholesale_price',
        'label'       => 'قیمت عمده (' . get_woocommerce_currency_symbol() . ')',
        'desc_tip'    => true,
        'description' => 'این قیمت فقط به کاربرانی که نقش «مشتری عمده» دارند نمایش داده می‌شود؛ برای بقیه بازدیدکنندگان قیمت عادی محصول دیده می‌شود.',
        'data_type'   => 'price',
    ));
    echo '</div>';
}

function modir_wholesale_save_product_field($post_id) {
    if (isset($_POST['_modir_wholesale_price'])) {
        update_post_meta($post_id, '_modir_wholesale_price', wc_format_decimal(sanitize_text_field(wp_unslash($_POST['_modir_wholesale_price']))));
    }
}

function modir_wholesale_add_variation_field($loop, $variation_data, $variation) {
    $value = get_post_meta($variation->ID, '_modir_wholesale_price', true);
    ?>
    <p class="form-row form-row-full">
        <label>قیمت عمده (<?php echo esc_html(get_woocommerce_currency_symbol()); ?>)</label>
        <input type="text" name="modir_wholesale_price[<?php echo esc_attr($loop); ?>]" value="<?php echo esc_attr($value); ?>" class="short wc_input_price">
    </p>
    <?php
}

function modir_wholesale_save_variation_field($variation_id) {
    if (isset($_POST['modir_wholesale_price'])) {
        $loop = array_search($variation_id, $_POST['variable_post_id'] ?? array(), true);
        if (false !== $loop && isset($_POST['modir_wholesale_price'][$loop])) {
            update_post_meta($variation_id, '_modir_wholesale_price', wc_format_decimal(sanitize_text_field(wp_unslash($_POST['modir_wholesale_price'][$loop]))));
        }
    }
}

/* -------------------------------------------------------------------- */
/* اعمال خودکار قیمت عمده برای مشتری عمده                                 */
/* -------------------------------------------------------------------- */

function modir_wholesale_maybe_override_price($price, $product) {
    if (!modir_wholesale_is_wholesale_customer()) {
        return $price;
    }
    $wholesale = $product->get_meta('_modir_wholesale_price');
    if ('' !== $wholesale && is_numeric($wholesale) && (float) $wholesale > 0) {
        return (float) $wholesale;
    }
    return $price;
}

/* -------------------------------------------------------------------- */
/* ذخیره تنظیمات فعال‌سازی                                               */
/* -------------------------------------------------------------------- */

function modir_wholesale_maybe_save_settings() {
    if (empty($_POST['modir_wholesale_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_wholesale_nonce'])), 'modir_wholesale_save')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    update_option('modir_wholesale_enabled', !empty($_POST['enabled']) ? 1 : 0);
    wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'tab' => 'wholesale', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

/* ======================================================================
 * رندر تب
 * ====================================================================== */

function modir_wholesale_render_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $enabled  = modir_wholesale_is_enabled();
    $role_obj = get_role('modir_wholesale_customer');
    ?>
    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>ذخیره شد.</p></div>
    <?php endif; ?>

    <form method="post">
        <?php wp_nonce_field('modir_wholesale_save', 'modir_wholesale_nonce'); ?>
        <table class="form-table">
            <tr>
                <th scope="row">فعال‌سازی عمده‌فروشی</th>
                <td>
                    <label class="modir-toggle">
                        <input type="checkbox" name="enabled" value="1" <?php checked($enabled); ?>>
                        فعال شود
                    </label>
                    <p class="description">وقتی فعال باشد، تو صفحه ویرایش هر محصول ووکامرس یه فیلد «قیمت عمده» اضافه می‌شه.</p>
                </td>
            </tr>
        </table>
        <?php submit_button('ذخیره', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>

    <div class="modir-alert">
        چطور کار می‌کنه:
        <br>۱. این ماژول یه نقش کاربری جدید به اسم «مشتری عمده» ساخته (<?php echo $role_obj ? '<strong>الان فعاله</strong>' : '<strong>هنوز ساخته نشده — یه بار صفحه رو رفرش کن</strong>'; ?>).
        <br>۲. از کاربران ← ویرایش کاربر، هر مشتری که می‌خوای قیمت عمده ببینه رو با این نقش تنظیم کن.
        <br>۳. تو صفحه ویرایش هر محصول (بخش قیمت‌گذاری ووکامرس)، یه فیلد «قیمت عمده» می‌بینی — همون‌جا یا از «ویرایش دسته‌جمعی» تو منوی محصولات، قیمت عمده رو مشخص کن.
        <br>۴. کاربرهایی با نقش «مشتری عمده» به‌صورت خودکار همون قیمت رو به‌جای قیمت عادی می‌بینن؛ بقیه بازدیدکننده‌ها هیچ تغییری نمی‌بینن.
    </div>
    <?php
}
