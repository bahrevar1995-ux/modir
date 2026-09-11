<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «انبار»: پیگیری دستی موجودی و ارزش انبار — مستقل از موجودی خود
 * ووکامرس؛ فقط قیمت فروش را زنده از محصول می‌خواند.
 * ==========================================================================
 */

function modir_warehouse_init() {
    add_action('admin_init', 'modir_warehouse_maybe_add');
    add_action('admin_init', 'modir_warehouse_maybe_update');
    add_action('admin_init', 'modir_warehouse_maybe_delete');
    add_action('admin_enqueue_scripts', 'modir_warehouse_admin_assets');
}

function modir_warehouse_admin_assets($hook) {
    if (strpos($hook, 'modir') === false) {
        return;
    }
    wp_enqueue_style('woocommerce_admin_styles');
    wp_enqueue_script('wc-enhanced-select');
}

/* -------------------------------------------------------------------- */
/* داده‌ها                                                               */
/* -------------------------------------------------------------------- */

function modir_warehouse_get_items() {
    $v = get_option('modir_warehouse_items', array());
    return is_array($v) ? $v : array();
}

/* -------------------------------------------------------------------- */
/* افزودن/ویرایش/حذف                                                     */
/* -------------------------------------------------------------------- */

function modir_warehouse_maybe_add() {
    if (empty($_POST['modir_warehouse_add_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_warehouse_add_nonce'])), 'modir_warehouse_add')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $qty        = isset($_POST['qty']) ? absint($_POST['qty']) : 0;
    $purchase   = isset($_POST['purchase_price']) ? (float) wp_unslash($_POST['purchase_price']) : 0;

    if (!$product_id || !wc_get_product($product_id)) {
        wp_safe_redirect(add_query_arg(array('page' => 'modir-accounting', 'tab' => 'warehouse', 'error' => '1'), admin_url('admin.php')));
        exit;
    }

    $items = modir_warehouse_get_items();

    foreach ($items as $i => $row) {
        if ((int) $row['product_id'] === $product_id) {
            $items[$i]['qty']            += $qty;
            $items[$i]['purchase_price']  = $purchase;
            update_option('modir_warehouse_items', $items);
            wp_safe_redirect(add_query_arg(array('page' => 'modir-accounting', 'tab' => 'warehouse', 'saved' => '1'), admin_url('admin.php')));
            exit;
        }
    }

    $items[] = array(
        'id'             => 'wh_' . uniqid(),
        'product_id'     => $product_id,
        'qty'            => $qty,
        'purchase_price' => $purchase,
    );
    update_option('modir_warehouse_items', $items);

    wp_safe_redirect(add_query_arg(array('page' => 'modir-accounting', 'tab' => 'warehouse', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

function modir_warehouse_maybe_update() {
    if (empty($_POST['modir_warehouse_update_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_warehouse_update_nonce'])), 'modir_warehouse_update')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $items = modir_warehouse_get_items();

    if (!empty($_POST['row']) && is_array($_POST['row'])) {
        foreach (wp_unslash($_POST['row']) as $id => $vals) {
            foreach ($items as $i => $row) {
                if ($row['id'] === $id) {
                    $items[$i]['qty']            = isset($vals['qty']) ? max(0, absint($vals['qty'])) : $row['qty'];
                    $items[$i]['purchase_price'] = isset($vals['purchase_price']) ? (float) $vals['purchase_price'] : $row['purchase_price'];
                    break;
                }
            }
        }
    }

    update_option('modir_warehouse_items', $items);

    wp_safe_redirect(add_query_arg(array('page' => 'modir-accounting', 'tab' => 'warehouse', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

function modir_warehouse_maybe_delete() {
    if (empty($_GET['modir_warehouse_action']) || 'delete' !== $_GET['modir_warehouse_action']) {
        return;
    }
    if (empty($_GET['item_id']) || empty($_GET['_wpnonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'modir_warehouse_delete')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $id    = sanitize_text_field(wp_unslash($_GET['item_id']));
    $items = array_values(array_filter(modir_warehouse_get_items(), function ($r) use ($id) {
        return $r['id'] !== $id;
    }));
    update_option('modir_warehouse_items', $items);

    wp_safe_redirect(add_query_arg(array('page' => 'modir-accounting', 'tab' => 'warehouse', 'deleted' => '1'), admin_url('admin.php')));
    exit;
}

/* ======================================================================
 * رندر تب
 * ====================================================================== */

function modir_render_warehouse_tab_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $items = modir_warehouse_get_items();

    $total_purchase_value = 0;
    $total_sale_value     = 0;
    ?>
    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>ذخیره شد.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])) : ?>
        <div class="notice notice-error is-dismissible"><p>محصول انتخابی معتبر نیست.</p></div>
    <?php endif; ?>

    <h2 class="modir-subtitle">افزودن کالا به انبار</h2>
    <form method="post" class="modir-filter-form">
        <?php wp_nonce_field('modir_warehouse_add', 'modir_warehouse_add_nonce'); ?>
        <div class="modir-filter-row">
            <div class="modir-filter-field" style="min-width:280px;">
                <label>محصول</label>
                <select class="wc-product-search" style="width:100%;" name="product_id" data-placeholder="جستجوی محصول..." data-action="woocommerce_json_search_products"></select>
            </div>
            <div class="modir-filter-field">
                <label>تعداد</label>
                <input type="number" name="qty" min="1" value="1" style="width:100px;">
            </div>
            <div class="modir-filter-field">
                <label>قیمت خرید واحد</label>
                <input type="number" step="0.01" min="0" name="purchase_price" style="width:140px;">
            </div>
            <div class="modir-filter-field modir-filter-submit">
                <button type="submit" class="button button-primary modir-btn">افزودن به انبار</button>
            </div>
        </div>
        <p class="description">اگر محصول از قبل تو انبار باشه، تعداد جدید به موجودی قبلی اضافه و قیمت خرید به‌روزرسانی می‌شه.</p>
    </form>

    <?php if (empty($items)) : ?>
        <p class="modir-empty">هنوز کالایی به انبار اضافه نکرده‌ای.</p>
    <?php else : ?>
        <?php
        $rows_data = array();
        foreach ($items as $row) {
            $product = wc_get_product($row['product_id']);
            if (!$product) {
                continue;
            }
            $sale_price            = (float) $product->get_price();
            $purchase_value        = (float) $row['purchase_price'] * (int) $row['qty'];
            $sale_value            = $sale_price * (int) $row['qty'];
            $total_purchase_value += $purchase_value;
            $total_sale_value     += $sale_value;
            $rows_data[] = array(
                'row'            => $row,
                'product'        => $product,
                'sale_price'     => $sale_price,
                'purchase_value' => $purchase_value,
                'sale_value'     => $sale_value,
            );
        }
        ?>

        <div class="modir-summary-cards">
            <div class="modir-card">
                <span class="modir-card-label">ارزش خرید کل انبار</span>
                <span class="modir-card-value"><?php echo wp_kses_post(wc_price($total_purchase_value)); ?></span>
            </div>
            <div class="modir-card modir-card-success">
                <span class="modir-card-label">ارزش فروش کل انبار</span>
                <span class="modir-card-value"><?php echo wp_kses_post(wc_price($total_sale_value)); ?></span>
            </div>
            <div class="modir-card <?php echo ($total_sale_value - $total_purchase_value) >= 0 ? 'modir-card-success' : 'modir-card-danger'; ?>">
                <span class="modir-card-label">سود بالقوه (در صورت فروش کامل)</span>
                <span class="modir-card-value"><?php echo wp_kses_post(wc_price($total_sale_value - $total_purchase_value)); ?></span>
            </div>
        </div>

        <form method="post">
            <?php wp_nonce_field('modir_warehouse_update', 'modir_warehouse_update_nonce'); ?>
            <table class="widefat striped modir-table modir-product-table">
                <thead>
                    <tr>
                        <th>کالا</th>
                        <th>تعداد</th>
                        <th>قیمت خرید واحد</th>
                        <th>قیمت فروش (ووکامرس)</th>
                        <th>ارزش خرید</th>
                        <th>ارزش فروش</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows_data as $rd) :
                        $row        = $rd['row'];
                        $product    = $rd['product'];
                        $img        = $product->get_image(array('width' => 36, 'height' => 36));
                        $delete_url = wp_nonce_url(add_query_arg(array('page' => 'modir-accounting', 'tab' => 'warehouse', 'modir_warehouse_action' => 'delete', 'item_id' => $row['id']), admin_url('admin.php')), 'modir_warehouse_delete');
                        ?>
                        <tr class="modir-product-row">
                            <td>
                                <div class="modir-product-cell">
                                    <span class="modir-product-thumb"><?php echo wp_kses_post($img); ?></span>
                                    <span class="modir-product-name"><?php echo esc_html($product->get_name()); ?></span>
                                </div>
                            </td>
                            <td><input type="number" min="0" name="row[<?php echo esc_attr($row['id']); ?>][qty]" value="<?php echo esc_attr($row['qty']); ?>" style="width:90px;"></td>
                            <td><input type="number" step="0.01" min="0" name="row[<?php echo esc_attr($row['id']); ?>][purchase_price]" value="<?php echo esc_attr($row['purchase_price']); ?>" style="width:120px;"></td>
                            <td class="modir-text-muted"><?php echo wp_kses_post(wc_price($rd['sale_price'])); ?></td>
                            <td><?php echo wp_kses_post(wc_price($rd['purchase_value'])); ?></td>
                            <td><?php echo wp_kses_post(wc_price($rd['sale_value'])); ?></td>
                            <td><a href="<?php echo esc_url($delete_url); ?>" class="modir-link-danger" onclick="return confirm('حذف شود؟');">حذف</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="submit" class="button button-primary modir-btn">ذخیره تغییرات تعداد/قیمت خرید</button></p>
        </form>
    <?php endif; ?>
    <?php
}
