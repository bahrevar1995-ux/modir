<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «هزینه‌های اضافی»: تعریف هزینه‌های جانبی (مبلغ ثابت یا درصدی از
 * کل سفارش) که در برگه تسویه‌حساب، یا همیشه (اجباری) یا با انتخاب مشتری
 * (اختیاری، با تیک‌زدن) به مبلغ نهایی اضافه می‌شوند.
 * ==========================================================================
 */

function modir_extra_fees_init() {
    add_action('admin_init', 'modir_extra_fees_maybe_save');
    add_action('admin_init', 'modir_extra_fees_maybe_delete');

    add_action('woocommerce_cart_calculate_fees', 'modir_extra_fees_apply_fees');
    add_action('woocommerce_review_order_before_order_total', 'modir_extra_fees_render_optional_checkboxes');
    add_action('wp_ajax_modir_extra_fee_toggle', 'modir_extra_fees_ajax_toggle');
    add_action('wp_ajax_nopriv_modir_extra_fee_toggle', 'modir_extra_fees_ajax_toggle');
    add_action('wp_enqueue_scripts', 'modir_extra_fees_frontend_assets');
}

function modir_extra_fees_frontend_assets() {
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }
    wp_enqueue_script('modir-extra-fees-js', MODIR_URL . 'assets/js/extra-fees.js', array('jquery'), MODIR_VERSION, true);
    wp_localize_script('modir-extra-fees-js', 'ModirExtraFees', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('modir_extra_fees_nonce'),
    ));
}

/* -------------------------------------------------------------------- */
/* CRUD لیست هزینه‌ها                                                    */
/* -------------------------------------------------------------------- */

function modir_extra_fees_get_all() {
    $list = get_option('modir_extra_fees', array());
    return is_array($list) ? $list : array();
}

function modir_extra_fees_get($id) {
    foreach (modir_extra_fees_get_all() as $f) {
        if ($f['id'] === $id) {
            return $f;
        }
    }
    return null;
}

function modir_extra_fees_maybe_save() {
    if (empty($_POST['modir_extra_fee_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_extra_fee_nonce'])), 'modir_extra_fee_save')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $label = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '';
    if (!$label) {
        return;
    }

    $fees = modir_extra_fees_get_all();
    $id   = isset($_POST['fee_id']) ? sanitize_text_field(wp_unslash($_POST['fee_id'])) : '';

    $data = array(
        'id'       => $id ?: 'fee_' . uniqid(),
        'label'    => $label,
        'type'     => ('percent' === ($_POST['type'] ?? '')) ? 'percent' : 'fixed',
        'amount'   => isset($_POST['amount']) ? wc_format_decimal(sanitize_text_field(wp_unslash($_POST['amount']))) : 0,
        'required' => !empty($_POST['required']) ? 1 : 0,
        'enabled'  => !empty($_POST['enabled']) ? 1 : 0,
    );

    $found = false;
    foreach ($fees as &$f) {
        if ($f['id'] === $data['id']) {
            $f     = $data;
            $found = true;
            break;
        }
    }
    unset($f);
    if (!$found) {
        $fees[] = $data;
    }

    update_option('modir_extra_fees', $fees);
    wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'tab' => 'extra_fees', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

function modir_extra_fees_maybe_delete() {
    if (empty($_GET['modir_extra_fee_action']) || 'delete' !== $_GET['modir_extra_fee_action']) {
        return;
    }
    if (empty($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'modir_extra_fee_delete')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $id   = sanitize_text_field(wp_unslash($_GET['fee_id'] ?? ''));
    $fees = array_values(array_filter(modir_extra_fees_get_all(), function ($f) use ($id) {
        return $f['id'] !== $id;
    }));
    update_option('modir_extra_fees', $fees);

    wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'tab' => 'extra_fees', 'deleted' => '1'), admin_url('admin.php')));
    exit;
}

/* -------------------------------------------------------------------- */
/* اعمال هزینه‌ها روی سبد خرید/تسویه‌حساب (هوک رسمی ووکامرس)                */
/* -------------------------------------------------------------------- */

function modir_extra_fees_apply_fees($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }
    if (!function_exists('WC') || !WC()->cart || !WC()->session) {
        return;
    }

    foreach (modir_extra_fees_get_all() as $fee) {
        if (empty($fee['enabled'])) {
            continue;
        }

        $should_apply = !empty($fee['required']);
        if (!$should_apply) {
            $should_apply = (bool) WC()->session->get('modir_extra_fee_' . $fee['id']);
        }
        if (!$should_apply) {
            continue;
        }

        $amount = ('percent' === $fee['type'])
            ? ($cart->get_subtotal() * ((float) $fee['amount'] / 100))
            : (float) $fee['amount'];

        if ($amount > 0) {
            $cart->add_fee($fee['label'], $amount, false);
        }
    }
}

/**
 * چک‌باکس‌های انتخابی برای هزینه‌های اختیاری، درست در بخش جمع‌کل برگه
 * تسویه‌حساب (بالای ردیف مبلغ نهایی).
 */
function modir_extra_fees_render_optional_checkboxes() {
    if (!function_exists('WC') || !WC()->session) {
        return;
    }

    $optional_fees = array_filter(modir_extra_fees_get_all(), function ($f) {
        return !empty($f['enabled']) && empty($f['required']);
    });
    if (empty($optional_fees)) {
        return;
    }
    ?>
    <tr class="modir-extra-fees-options">
        <th colspan="2" style="font-weight:normal;padding-top:10px;">
            <?php foreach ($optional_fees as $fee) :
                $checked = (bool) WC()->session->get('modir_extra_fee_' . $fee['id']);
                $desc    = ('percent' === $fee['type']) ? modir_to_persian_digits($fee['amount']) . '٪ از مبلغ سفارش' : wp_strip_all_tags(wc_price($fee['amount']));
                ?>
                <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:8px;">
                    <input type="checkbox" class="modir-extra-fee-checkbox" data-fee-id="<?php echo esc_attr($fee['id']); ?>" <?php checked($checked); ?>>
                    <span><?php echo esc_html($fee['label']); ?> (+<?php echo esc_html($desc); ?>)</span>
                </label>
            <?php endforeach; ?>
        </th>
    </tr>
    <?php
}

function modir_extra_fees_ajax_toggle() {
    check_ajax_referer('modir_extra_fees_nonce', 'nonce');
    if (!function_exists('WC') || !WC()->session) {
        wp_send_json_error();
    }

    $fee_id  = isset($_POST['fee_id']) ? sanitize_text_field(wp_unslash($_POST['fee_id'])) : '';
    $checked = !empty($_POST['checked']);

    if ($fee_id) {
        WC()->session->set('modir_extra_fee_' . $fee_id, $checked);
    }

    wp_send_json_success();
}

/* ======================================================================
 * محتوای تب «هزینه‌های اضافی»
 * ====================================================================== */

function modir_extra_fees_render_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $editing = null;
    if (!empty($_GET['edit'])) {
        $editing = modir_extra_fees_get(sanitize_text_field(wp_unslash($_GET['edit'])));
    }
    ?>
    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>هزینه ذخیره شد.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])) : ?>
        <div class="notice notice-success is-dismissible"><p>هزینه حذف شد.</p></div>
    <?php endif; ?>

    <div class="modir-alert">
        این هزینه‌ها دقیقاً در برگه تسویه‌حساب، در بخش جمع‌کل (بالای مبلغ نهایی) نمایش داده می‌شوند. هزینه‌ی «اجباری» همیشه به سفارش اضافه می‌شود؛ هزینه‌ی «اختیاری» با یک تیک‌باکس در همان بخش به مشتری نشان داده می‌شود و فقط اگر تیک بزند اضافه می‌شود.
    </div>

    <h2 class="modir-subtitle"><?php echo $editing ? 'ویرایش هزینه' : 'افزودن هزینه جدید'; ?></h2>
    <form method="post">
        <?php wp_nonce_field('modir_extra_fee_save', 'modir_extra_fee_nonce'); ?>
        <input type="hidden" name="fee_id" value="<?php echo esc_attr($editing['id'] ?? ''); ?>">
        <table class="form-table">
            <tr>
                <th scope="row">عنوان هزینه</th>
                <td><input type="text" name="label" class="regular-text" value="<?php echo esc_attr($editing['label'] ?? ''); ?>" placeholder="مثلاً: بسته‌بندی هدیه" required></td>
            </tr>
            <tr>
                <th scope="row">نوع هزینه</th>
                <td>
                    <label><input type="radio" name="type" value="fixed" <?php checked(($editing['type'] ?? 'fixed'), 'fixed'); ?>> مبلغ ثابت (تومان)</label>
                    &nbsp;&nbsp;
                    <label><input type="radio" name="type" value="percent" <?php checked(($editing['type'] ?? ''), 'percent'); ?>> درصدی از کل مبلغ سفارش</label>
                </td>
            </tr>
            <tr>
                <th scope="row">مقدار</th>
                <td><input type="number" step="0.01" min="0" name="amount" value="<?php echo esc_attr($editing['amount'] ?? ''); ?>" style="width:160px;"></td>
            </tr>
            <tr>
                <th scope="row">نوع الزام</th>
                <td>
                    <label><input type="radio" name="required" value="0" <?php checked(empty($editing['required'])); ?>> اختیاری (با تیک مشتری)</label>
                    &nbsp;&nbsp;
                    <label><input type="radio" name="required" value="1" <?php checked(!empty($editing['required'])); ?>> اجباری (همیشه اضافه شود)</label>
                </td>
            </tr>
            <tr>
                <th scope="row">فعال</th>
                <td>
                    <label class="modir-toggle">
                        <input type="checkbox" name="enabled" value="1" <?php checked($editing ? !empty($editing['enabled']) : true); ?>>
                        این هزینه فعال باشد
                    </label>
                </td>
            </tr>
        </table>
        <?php submit_button($editing ? 'ذخیره تغییرات' : 'افزودن', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>

    <h2 class="modir-subtitle">لیست هزینه‌های تعریف‌شده</h2>
    <?php $fees = modir_extra_fees_get_all(); ?>
    <?php if (empty($fees)) : ?>
        <p class="modir-empty">هنوز هزینه‌ای تعریف نشده است.</p>
    <?php else : ?>
        <table class="widefat striped modir-table">
            <thead><tr><th>عنوان</th><th>نوع</th><th>مقدار</th><th>الزام</th><th>وضعیت</th><th style="width:100px;"></th></tr></thead>
            <tbody>
                <?php foreach ($fees as $f) :
                    $edit_url   = add_query_arg(array('page' => 'modir-settings', 'tab' => 'extra_fees', 'edit' => $f['id']), admin_url('admin.php'));
                    $delete_url = wp_nonce_url(add_query_arg(array('page' => 'modir-settings', 'tab' => 'extra_fees', 'modir_extra_fee_action' => 'delete', 'fee_id' => $f['id']), admin_url('admin.php')), 'modir_extra_fee_delete');
                    ?>
                    <tr>
                        <td><?php echo esc_html($f['label']); ?></td>
                        <td class="modir-text-muted"><?php echo ('percent' === $f['type']) ? 'درصدی' : 'مبلغ ثابت'; ?></td>
                        <td class="modir-text-muted"><?php echo ('percent' === $f['type']) ? number_format_i18n($f['amount'], 1) . '٪' : wp_kses_post(wc_price($f['amount'])); ?></td>
                        <td>
                            <?php if (!empty($f['required'])) : ?>
                                <span class="modir-stat-badge is-bad">اجباری</span>
                            <?php else : ?>
                                <span class="modir-stat-badge is-neutral">اختیاری</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($f['enabled'])) : ?>
                                <span class="modir-stat-badge is-good">فعال</span>
                            <?php else : ?>
                                <span class="modir-stat-badge is-neutral">غیرفعال</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($edit_url); ?>" class="modir-icon-link" title="ویرایش"><span class="dashicons dashicons-edit"></span></a>
                            <a href="<?php echo esc_url($delete_url); ?>" class="modir-icon-link" title="حذف" onclick="return confirm('این هزینه حذف شود؟');"><span class="dashicons dashicons-trash"></span></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
}
