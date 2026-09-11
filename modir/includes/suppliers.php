<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «تأمین‌کنندگان»: تعریف لیست تأمین‌کننده، انتخابش برای هر محصول، و
 * نمایش آن زیر فاکتور سفارش، در لیست سفارشات مدیر، و در لیست سفارشات ووکامرس.
 * ==========================================================================
 */

function modir_suppliers_init() {
    add_action('admin_init', 'modir_suppliers_maybe_save');
    add_action('admin_init', 'modir_suppliers_maybe_delete');

    // نمایش تأمین‌کننده زیر هر قلم سفارش (فاکتور/صفحه ویرایش سفارش)
    add_action('woocommerce_after_order_itemmeta', 'modir_suppliers_show_on_order_item', 10, 3);

    // ستون تأمین‌کننده در لیست سفارشات ووکامرس (سنتی و HPOS)
    add_filter('manage_edit-shop_order_columns', 'modir_suppliers_add_order_column');
    add_filter('manage_woocommerce_page_wc-orders_columns', 'modir_suppliers_add_order_column');
    add_action('manage_shop_order_posts_custom_column', 'modir_suppliers_render_order_column', 20, 2);
    add_action('manage_woocommerce_page_wc-orders_custom_column', 'modir_suppliers_render_order_column_hpos', 20, 2);
}

/* -------------------------------------------------------------------- */
/* CRUD لیست تأمین‌کنندگان                                                */
/* -------------------------------------------------------------------- */

function modir_suppliers_get_all() {
    $list = get_option('modir_suppliers', array());
    return is_array($list) ? $list : array();
}

function modir_suppliers_get($id) {
    foreach (modir_suppliers_get_all() as $s) {
        if ($s['id'] === $id) {
            return $s;
        }
    }
    return null;
}

function modir_suppliers_maybe_save() {
    if (empty($_POST['modir_supplier_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_supplier_nonce'])), 'modir_supplier_save')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    if (!$name) {
        return;
    }

    $suppliers = modir_suppliers_get_all();
    $id        = isset($_POST['supplier_id']) ? sanitize_text_field(wp_unslash($_POST['supplier_id'])) : '';

    $data = array(
        'id'    => $id ?: 'sup_' . uniqid(),
        'name'  => $name,
        'phone' => isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '',
        'notes' => isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '',
    );

    $found = false;
    foreach ($suppliers as &$s) {
        if ($s['id'] === $data['id']) {
            $s     = $data;
            $found = true;
            break;
        }
    }
    unset($s);
    if (!$found) {
        $suppliers[] = $data;
    }

    update_option('modir_suppliers', $suppliers);
    wp_safe_redirect(add_query_arg(array('page' => 'modir', 'tab' => 'suppliers', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

function modir_suppliers_maybe_delete() {
    if (empty($_GET['modir_supplier_action']) || 'delete' !== $_GET['modir_supplier_action']) {
        return;
    }
    if (empty($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'modir_supplier_delete')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $id        = sanitize_text_field(wp_unslash($_GET['supplier_id'] ?? ''));
    $suppliers = array_values(array_filter(modir_suppliers_get_all(), function ($s) use ($id) {
        return $s['id'] !== $id;
    }));
    update_option('modir_suppliers', $suppliers);

    wp_safe_redirect(add_query_arg(array('page' => 'modir', 'tab' => 'suppliers', 'deleted' => '1'), admin_url('admin.php')));
    exit;
}

/* -------------------------------------------------------------------- */
/* نمایش تأمین‌کننده زیر هر قلم سفارش (فاکتور)                             */
/* -------------------------------------------------------------------- */

function modir_suppliers_show_on_order_item($item_id, $item, $product) {
    if (!$product || !is_a($item, 'WC_Order_Item_Product')) {
        return;
    }
    $supplier_id = $product->get_meta('_modir_supplier_id');
    if (!$supplier_id) {
        return;
    }
    $supplier = modir_suppliers_get($supplier_id);
    if (!$supplier) {
        return;
    }
    echo '<p style="margin:4px 0 0;font-size:12px;color:#6b7280;"><strong>تأمین‌کننده:</strong> ' . esc_html($supplier['name']) . '</p>';
}

/* -------------------------------------------------------------------- */
/* ستون تأمین‌کننده در لیست سفارشات ووکامرس                                */
/* -------------------------------------------------------------------- */

function modir_suppliers_add_order_column($columns) {
    $columns['modir_suppliers'] = 'تأمین‌کننده';
    return $columns;
}

function modir_suppliers_get_order_suppliers_text($order) {
    if (!is_a($order, 'WC_Order')) {
        return '';
    }
    $names = array();
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if (!$product) {
            continue;
        }
        $supplier_id = $product->get_meta('_modir_supplier_id');
        if (!$supplier_id) {
            continue;
        }
        $supplier = modir_suppliers_get($supplier_id);
        if ($supplier) {
            $names[$supplier['name']] = true;
        }
    }
    return implode('، ', array_keys($names));
}

function modir_suppliers_render_order_column($column, $post_id) {
    if ('modir_suppliers' !== $column) {
        return;
    }
    $order = wc_get_order($post_id);
    echo esc_html(modir_suppliers_get_order_suppliers_text($order) ?: '—');
}

function modir_suppliers_render_order_column_hpos($column, $order) {
    if ('modir_suppliers' !== $column) {
        return;
    }
    echo esc_html(modir_suppliers_get_order_suppliers_text($order) ?: '—');
}

/* ======================================================================
 * محتوای تب «تأمین‌کنندگان» (زیر منوی محصولات)
 * ====================================================================== */

function modir_suppliers_render_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $editing = null;
    if (!empty($_GET['edit'])) {
        $editing = modir_suppliers_get(sanitize_text_field(wp_unslash($_GET['edit'])));
    }
    ?>
    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>تأمین‌کننده ذخیره شد.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])) : ?>
        <div class="notice notice-success is-dismissible"><p>تأمین‌کننده حذف شد.</p></div>
    <?php endif; ?>

    <h2 class="modir-subtitle"><?php echo $editing ? 'ویرایش تأمین‌کننده' : 'افزودن تأمین‌کننده جدید'; ?></h2>
    <form method="post">
        <?php wp_nonce_field('modir_supplier_save', 'modir_supplier_nonce'); ?>
        <input type="hidden" name="supplier_id" value="<?php echo esc_attr($editing['id'] ?? ''); ?>">
        <table class="form-table">
            <tr>
                <th scope="row">نام تأمین‌کننده</th>
                <td><input type="text" name="name" class="regular-text" value="<?php echo esc_attr($editing['name'] ?? ''); ?>" required></td>
            </tr>
            <tr>
                <th scope="row">تلفن</th>
                <td><input type="text" name="phone" class="regular-text" value="<?php echo esc_attr($editing['phone'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th scope="row">یادداشت</th>
                <td><textarea name="notes" rows="2" class="large-text"><?php echo esc_textarea($editing['notes'] ?? ''); ?></textarea></td>
            </tr>
        </table>
        <?php submit_button($editing ? 'ذخیره تغییرات' : 'افزودن', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>

    <h2 class="modir-subtitle">لیست تأمین‌کنندگان</h2>
    <?php $suppliers = modir_suppliers_get_all(); ?>
    <?php if (empty($suppliers)) : ?>
        <p class="modir-empty">هنوز تأمین‌کننده‌ای ثبت نشده است.</p>
    <?php else : ?>
        <table class="widefat striped modir-table">
            <thead><tr><th>نام</th><th>تلفن</th><th>یادداشت</th><th style="width:100px;"></th></tr></thead>
            <tbody>
                <?php foreach ($suppliers as $s) :
                    $edit_url   = add_query_arg(array('page' => 'modir', 'tab' => 'suppliers', 'edit' => $s['id']), admin_url('admin.php'));
                    $delete_url = wp_nonce_url(add_query_arg(array('page' => 'modir', 'tab' => 'suppliers', 'modir_supplier_action' => 'delete', 'supplier_id' => $s['id']), admin_url('admin.php')), 'modir_supplier_delete');
                    ?>
                    <tr>
                        <td><?php echo esc_html($s['name']); ?></td>
                        <td class="modir-text-muted"><?php echo esc_html($s['phone']); ?></td>
                        <td class="modir-text-muted"><?php echo esc_html($s['notes']); ?></td>
                        <td>
                            <a href="<?php echo esc_url($edit_url); ?>" class="modir-icon-link" title="ویرایش"><span class="dashicons dashicons-edit"></span></a>
                            <a href="<?php echo esc_url($delete_url); ?>" class="modir-icon-link" title="حذف" onclick="return confirm('این تأمین‌کننده حذف شود؟');"><span class="dashicons dashicons-trash"></span></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
}
