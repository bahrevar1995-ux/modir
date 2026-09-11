<?php
if (!defined('ABSPATH')) exit;

/**
 * ماژول لیبل آدرس
 */

function modir_label_init() {
    add_action('admin_post_modir_print_labels', 'modir_label_handle_print');

    add_filter('bulk_actions-edit-shop_order', 'modir_label_bulk_action');
    add_filter('bulk_actions-woocommerce_page_wc-orders', 'modir_label_bulk_action');
    add_filter('handle_bulk_actions-edit-shop_order', 'modir_label_handle_bulk', 10, 3);
    add_filter('handle_bulk_actions-woocommerce_page_wc-orders', 'modir_label_handle_bulk', 10, 3);

    add_filter('woocommerce_admin_order_actions', 'modir_label_add_row_action', 10, 2);

    add_action('admin_init', 'modir_label_maybe_save_sender');
    add_action('admin_enqueue_scripts', 'modir_label_orders_screen_css');
}

function modir_label_orders_screen_css($hook) {
    $is_orders_screen = ('edit.php' === $hook && isset($_GET['post_type']) && 'shop_order' === $_GET['post_type'])
        || 'woocommerce_page_wc-orders' === $hook;

    if (!$is_orders_screen) {
        return;
    }

    wp_register_style('modir-label-icon', false, array(), MODIR_VERSION);
    wp_enqueue_style('modir-label-icon');
    wp_add_inline_style('modir-label-icon', 'a.modir-label::after{font-family:dashicons;content:"\f169";}');
}

/* -------------------------------------------------------------------- */
/* اطلاعات فرستنده                                                       */
/* -------------------------------------------------------------------- */

function modir_label_get_sender() {
    return wp_parse_args(get_option('modir_label_sender', array()), array(
        'name'        => '',
        'phone'       => '',
        'website'     => '',
        'postal_code' => '',
        'address'     => '',
    ));
}

function modir_label_maybe_save_sender() {
    if (empty($_POST['modir_label_sender_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_label_sender_nonce'])), 'modir_label_save_sender')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    update_option('modir_label_sender', array(
        'name'        => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
        'phone'       => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
        'website'     => sanitize_text_field(wp_unslash($_POST['website'] ?? '')),
        'postal_code' => sanitize_text_field(wp_unslash($_POST['postal_code'] ?? '')),
        'address'     => sanitize_textarea_field(wp_unslash($_POST['address'] ?? '')),
    ));

    wp_safe_redirect(add_query_arg(array('page' => 'modir-label', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

/* -------------------------------------------------------------------- */
/* اکشن‌های لیست سفارش‌ها (تکی و دسته‌جمعی)                                */
/* -------------------------------------------------------------------- */

function modir_label_bulk_action($actions) {
    $actions['modir_print_label'] = 'چاپ لیبل آدرس';
    return $actions;
}

function modir_label_handle_bulk($redirect_to, $action, $ids) {
    if ('modir_print_label' !== $action || empty($ids)) {
        return $redirect_to;
    }
    return add_query_arg(array('action' => 'modir_print_labels', 'orders' => implode(',', array_map('absint', $ids))), admin_url('admin-post.php'));
}

function modir_label_add_row_action($actions, $order) {
    $url = add_query_arg(array('action' => 'modir_print_labels', 'orders' => $order->get_id()), admin_url('admin-post.php'));
    $actions['modir_label'] = array(
        'url'    => $url,
        'name'   => 'چاپ لیبل آدرس',
        'action' => 'modir-label',
    );
    return $actions;
}

/* -------------------------------------------------------------------- */
/* رندر صفحه چاپ                                                        */
/* -------------------------------------------------------------------- */

function modir_label_handle_print() {
    if (!current_user_can('manage_woocommerce')) {
        modir_label_die('دسترسی غیرمجاز.');
    }

    $ids = isset($_GET['orders']) ? array_filter(array_map('absint', explode(',', sanitize_text_field(wp_unslash($_GET['orders']))))) : array();
    if (empty($ids)) {
        modir_label_die('سفارشی برای چاپ انتخاب نشده است.');
    }

    try {
        modir_label_render_print_html($ids);
    } catch (\Throwable $e) {
        modir_label_die('خطا در ساخت لیبل: ' . $e->getMessage());
    }
    exit;
}

/**
 * صفحه خطای ساده و واضح؛ به‌جای wp_die پیش‌فرض که ممکن است تقریباً سفید و
 * کم‌رنگ به نظر برسد.
 */
function modir_label_die($message) {
    ?>
    <!DOCTYPE html>
    <html dir="rtl" lang="fa">
    <head><meta charset="utf-8"><title>خطا</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css">
    <style>
        body{font-family:'Vazirmatn',Tahoma,Arial,sans-serif;background:#f9fafb;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
        .box{background:#fff;border:1px solid #fecaca;border-radius:12px;padding:28px 32px;max-width:420px;text-align:center;box-shadow:0 8px 30px rgba(0,0,0,.06);}
        .box h1{font-size:16px;color:#b91c1c;margin:0 0 10px;}
        .box p{font-size:13px;color:#374151;line-height:1.8;margin:0;}
        .box a{display:inline-block;margin-top:16px;font-size:12px;color:#2563eb;text-decoration:none;}
    </style></head>
    <body>
        <div class="box">
            <h1>مشکلی پیش آمد</h1>
            <p><?php echo esc_html($message); ?></p>
            <a href="javascript:history.back()">بازگشت</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function modir_label_render_print_html($order_ids) {
    $sender = modir_label_get_sender();
    ?>
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
<meta charset="utf-8">
<title>لیبل سفارش</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/JsBarcode/3.11.6/JsBarcode.all.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css">
<style>
    @page { size: A5; margin: 8mm; }
    * { box-sizing: border-box; }
    body { font-family: 'Vazirmatn', Tahoma, Arial, sans-serif; margin: 0; padding: 0; color: #111; background: #eee; font-size: 12px; }
    .modir-label-page {
        width: 190mm; min-height: 138mm; margin: 12px auto; background: #fff;
        padding: 8mm; page-break-after: always; position: relative;
    }
    .modir-print-date {
        border: 1px solid #d1d5db; border-radius: 8px; padding: 8px 14px;
        display: inline-block; font-size: 12px; font-weight: 700; margin-bottom: 10px;
    }
    table.modir-parties { width: 100%; border-collapse: collapse; margin-bottom: 10px; table-layout: fixed; }
    table.modir-parties td { border: 1px solid #333; padding: 8px 10px; vertical-align: middle; font-size: 11.5px; }
    .modir-party-label {
        width: 34px; background: #e5e7eb; text-align: center; font-weight: 700;
        writing-mode: vertical-rl; text-orientation: mixed;
    }
    .modir-party-side { width: 130px; text-align: center; font-size: 11px; line-height: 1.9; }
    .modir-party-side strong { display: block; font-size: 13px; }
    .modir-party-main b { font-weight: 700; }
    .modir-party-main { line-height: 2; }

    table.modir-items { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 10px; }
    table.modir-items th, table.modir-items td { border: 1px solid #333; padding: 6px 8px; text-align: center; }
    table.modir-items thead th { background: #e5e7eb; font-weight: 700; }
    table.modir-items td.modir-item-name { text-align: right; }
    table.modir-items td.modir-item-supplier { font-size: 11px; color: #4b5563; }
    table.modir-items tfoot td { font-weight: 700; }
    table.modir-items tfoot td.modir-sum-label { text-align: right; }
    .modir-barcode-wrap { display: flex; flex-direction: column; align-items: center; }
    .modir-barcode-wrap svg { max-width: 100%; height: 30px; }
    .modir-barcode-id { font-size: 10px; margin-top: 2px; }
    .modir-needs-check-flag {
        display: inline-block;
        margin-inline-start: 6px;
        padding: 1px 6px;
        border: 1.5px solid #b91c1c;
        border-radius: 5px;
        color: #b91c1c;
        font-size: 10px;
        font-weight: 700;
    }

    .modir-shipping-banner {
        display: flex;
        align-items: center;
        gap: 6px;
        background: #f3f4f6;
        border: 1px solid #333;
        border-radius: 6px;
        padding: 6px 12px;
        margin-bottom: 12px;
        font-size: 12.5px;
    }
    .modir-shipping-banner-label { font-weight: 700; }

    table.modir-totals { width: 60%; border-collapse: collapse; font-size: 12px; margin-bottom: 12px; }
    table.modir-totals td { border: 1px solid #333; padding: 8px 12px; }
    table.modir-totals td.modir-total-label { text-align: center; font-weight: 700; width: 60%; }
    table.modir-totals td.modir-total-value { text-align: center; }
    table.modir-totals tr.modir-grand-total td { background: #f3f4f6; font-weight: 700; }

    .modir-print-btn {
        position: fixed; top: 14px; left: 14px; background: #111; color: #fff; border: none;
        padding: 10px 18px; border-radius: 8px; cursor: pointer; font-size: 13px; z-index: 10;
    }
    @media print { .modir-print-btn { display: none; } body { background: #fff; } .modir-label-page { margin: 0; width: auto; } }
</style>
</head>
<body>
<button class="modir-print-btn" onclick="window.print()">چاپ</button>
<?php
    $barcode_index = 0;
    foreach ($order_ids as $order_id) :
    $order = wc_get_order($order_id);
    if (!$order) {
        continue;
    }

    $recipient_name = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
    if (!$recipient_name) {
        $recipient_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    }

    list($recipient_addr, $recipient_postcode) = modir_label_get_full_address($order, 'shipping');
    if (!$recipient_addr) {
        list($recipient_addr, $recipient_postcode) = modir_label_get_full_address($order, 'billing');
    }
    $recipient_phone = $order->get_billing_phone();

    $statuses     = wc_get_order_statuses();
    $status_label = $statuses['wc-' . $order->get_status()] ?? $order->get_status();

    $order_date_str = '';
    if ($order->get_date_created()) {
        $g = $order->get_date_created();
        $order_date_str = (function_exists('modir_gregorian_to_jalali_string') ? modir_gregorian_to_jalali_string($g->date('Y-m-d')) : $g->date('Y-m-d')) . ' ' . $g->date('H:i');
    }

    $shipping_total  = (float) $order->get_shipping_total();
    $shipping_method = '';
    foreach ($order->get_shipping_methods() as $sm) {
        $shipping_method = $sm->get_name();
        break;
    }
    $items_total = (float) $order->get_subtotal();
    $grand_total = (float) $order->get_total();
    ?>
    <div class="modir-label-page">
        <div class="modir-print-date">تاریخ چاپ: <?php echo esc_html((function_exists('modir_gregorian_to_jalali_string') ? modir_gregorian_to_jalali_string(current_time('Y-m-d')) : current_time('Y-m-d')) . ' ' . current_time('H:i')); ?></div>

        <table class="modir-parties">
            <tr>
                <td class="modir-party-label">فرستنده</td>
                <td class="modir-party-main">
                    <b>نام:</b> <?php echo esc_html($sender['name']); ?>
                    <b>آدرس:</b> <?php echo esc_html($sender['address']); ?>
                    <b>کد پستی:</b> <?php echo esc_html($sender['postal_code']); ?>
                </td>
                <td class="modir-party-side">
                    <?php if ($sender['website']) : ?><span><?php echo esc_html($sender['website']); ?></span><?php endif; ?>
                    <strong><?php echo esc_html($sender['phone']); ?></strong>
                </td>
            </tr>
            <tr>
                <td class="modir-party-label">گیرنده</td>
                <td class="modir-party-main">
                    <b>نام:</b> <?php echo esc_html($recipient_name); ?>
                    <b>آدرس:</b> <?php echo esc_html($recipient_addr); ?>
                    <b>کد پستی:</b> <?php echo esc_html($recipient_postcode); ?>
                    <br><b>تلفن:</b> <?php echo esc_html($recipient_phone); ?>
                </td>
                <td class="modir-party-side">
                    <strong>#<?php echo esc_html($order->get_order_number()); ?></strong>
                    <span><?php echo esc_html($status_label); ?></span>
                    <span><?php echo esc_html($order_date_str); ?></span>
                </td>
            </tr>
        </table>

        <table class="modir-items">
            <thead>
                <tr>
                    <th style="width:30px;">ردیف</th>
                    <th style="width:110px;">شناسه محصول</th>
                    <th>نام کالا</th>
                    <th style="width:110px;">تأمین‌کننده</th>
                    <th style="width:90px;">قیمت</th>
                    <th style="width:50px;">تعداد</th>
                    <th style="width:90px;">مجموع</th>
                </tr>
            </thead>
            <tbody>
                <?php $row_no = 1; foreach ($order->get_items() as $item) :
                    $product   = $item->get_product();
                    $sku       = $product ? ($product->get_sku() ?: $product->get_id()) : $item->get_id();
                    $barcode_index++;
                    $barcode_id = 'modir-barcode-' . $barcode_index;

                    $supplier_name = '';
                    if ($product && function_exists('modir_suppliers_get')) {
                        $supplier_id = $product->get_meta('_modir_supplier_id');
                        if ($supplier_id) {
                            $supplier = modir_suppliers_get($supplier_id);
                            $supplier_name = $supplier ? $supplier['name'] : '';
                        }
                    }
                    $needs_check = $product && ('1' === $product->get_meta('_modir_needs_check'));
                    ?>
                    <tr>
                        <td><?php echo esc_html($row_no++); ?></td>
                        <td>
                            <div class="modir-barcode-wrap">
                                <svg id="<?php echo esc_attr($barcode_id); ?>" data-code="<?php echo esc_attr($sku); ?>"></svg>
                                <span class="modir-barcode-id"><?php echo esc_html($sku); ?></span>
                            </div>
                        </td>
                        <td class="modir-item-name">
                            <?php echo esc_html($item->get_name()); ?>
                            <?php if ($needs_check) : ?>
                                <span class="modir-needs-check-flag">⚠️ چک شود</span>
                            <?php endif; ?>
                        </td>
                        <td class="modir-item-supplier"><?php echo $supplier_name ? esc_html($supplier_name) : '—'; ?></td>
                        <td><?php echo wp_kses_post(wc_price($item->get_total() / max(1, $item->get_quantity()))); ?></td>
                        <td><?php echo esc_html($item->get_quantity()); ?></td>
                        <td><?php echo wp_kses_post(wc_price($item->get_total())); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="modir-sum-label">مجموع</td>
                    <td></td>
                    <td><?php echo esc_html($order->get_item_count()); ?></td>
                    <td><?php echo wp_kses_post(wc_price($items_total)); ?></td>
                </tr>
            </tfoot>
        </table>

        <?php if ($shipping_method) : ?>
            <div class="modir-shipping-banner">
                <span class="modir-shipping-banner-label">روش ارسال:</span>
                <span class="modir-shipping-banner-value"><?php echo esc_html($shipping_method); ?></span>
            </div>
        <?php endif; ?>

        <table class="modir-totals">
            <tr>
                <td class="modir-total-label">مجموع محصولات</td>
                <td class="modir-total-value"><?php echo wp_kses_post(wc_price($items_total)); ?></td>
            </tr>
            <tr>
                <td class="modir-total-label">هزینه ارسال</td>
                <td class="modir-total-value"><?php echo wp_kses_post(wc_price($shipping_total)); ?></td>
            </tr>
            <tr class="modir-grand-total">
                <td class="modir-total-label">مجموع سفارش</td>
                <td class="modir-total-value"><?php echo wp_kses_post(wc_price($grand_total)); ?></td>
            </tr>
        </table>
    </div>
<?php endforeach; ?>
<script>
document.querySelectorAll('svg[id^="modir-barcode-"]').forEach(function (el) {
    try {
        JsBarcode(el, el.getAttribute('data-code'), { format: 'CODE128', displayValue: false, height: 30, margin: 0 });
    } catch (e) { /* اگر بارکد نامعتبر بود، فقط شناسه متنی زیرش باقی می‌ماند */ }
});
</script>
</body>
</html>
    <?php
}

/**
 * آدرس کامل (خط ۱ + خط ۲ + شهر + استان) را برمی‌گرداند. کد استان (که ووکامرس
 * معمولاً به‌جای اسم کامل ذخیره می‌کند، مثلاً THR به‌جای «تهران») را با
 * استفاده از دیتای خود ووکامرس به اسم واقعی تبدیل می‌کند.
 *
 * @return array [آدرس متنی، کدپستی]
 */
function modir_label_get_full_address($order, $type = 'shipping') {
    $getter = function ($field) use ($order, $type) {
        $method = 'get_' . $type . '_' . $field;
        return method_exists($order, $method) ? $order->$method() : '';
    };

    $address_1 = $getter('address_1');
    $address_2 = $getter('address_2');
    $city      = $getter('city');
    $state_raw = $getter('state');
    $postcode  = $getter('postcode');
    $country   = $getter('country');

    if (!$address_1 && !$address_2 && !$city) {
        return array('', $postcode);
    }

    $state_label = $state_raw;
    if ($state_raw && $country && function_exists('WC') && WC()->countries) {
        $states = WC()->countries->get_states($country);
        if (!empty($states[$state_raw])) {
            $state_label = $states[$state_raw];
        }
    }

    $parts = array_values(array_filter(array($state_label, $city, $address_1, $address_2), function ($v) {
        return '' !== trim((string) $v);
    }));

    return array(implode('، ', $parts), $postcode);
}

/* ======================================================================
 * صفحه ادمین
 * ====================================================================== */

function modir_label_render_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $sender = modir_label_get_sender();
    ?>
    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>ذخیره شد.</p></div>
    <?php endif; ?>

    <h2 class="modir-subtitle">اطلاعات فرستنده</h2>
    <form method="post">
        <?php wp_nonce_field('modir_label_save_sender', 'modir_label_sender_nonce'); ?>
        <table class="form-table">
            <tr>
                <th scope="row">نام / نام فروشگاه</th>
                <td><input type="text" name="name" class="regular-text" value="<?php echo esc_attr($sender['name']); ?>"></td>
            </tr>
            <tr>
                <th scope="row">وب‌سایت</th>
                <td><input type="text" name="website" class="regular-text" value="<?php echo esc_attr($sender['website']); ?>" placeholder="http://www.example.com"></td>
            </tr>
            <tr>
                <th scope="row">تلفن</th>
                <td><input type="text" name="phone" class="regular-text" value="<?php echo esc_attr($sender['phone']); ?>"></td>
            </tr>
            <tr>
                <th scope="row">کد پستی</th>
                <td><input type="text" name="postal_code" class="regular-text" value="<?php echo esc_attr($sender['postal_code']); ?>"></td>
            </tr>
            <tr>
                <th scope="row">آدرس کامل</th>
                <td><textarea name="address" rows="3" class="large-text"><?php echo esc_textarea($sender['address']); ?></textarea></td>
            </tr>
        </table>
        <?php submit_button('ذخیره', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>

    <h2 class="modir-subtitle">چاپ لیبل تکی</h2>
    <form method="get" class="modir-filter-form">
        <div class="modir-filter-row">
            <div class="modir-filter-field">
                <label>شماره سفارش</label>
                <input type="number" id="modir-label-order-id" placeholder="مثلاً 1024">
            </div>
            <div class="modir-filter-field modir-filter-submit">
                <button type="button" class="button button-primary modir-btn" id="modir-label-print-btn">چاپ لیبل</button>
            </div>
        </div>
    </form>
    <p class="description">برای چاپ چند لیبل با هم، از لیست سفارش‌ها چند سفارش رو تیک بزن و از منوی «اقدامات دسته‌جمعی» گزینه «چاپ لیبل آدرس» رو انتخاب کن.</p>
    <script>
    document.getElementById('modir-label-print-btn').addEventListener('click', function () {
        var id = document.getElementById('modir-label-order-id').value.trim();
        if (!id) { return; }
        var url = '<?php echo esc_js(add_query_arg(array('action' => 'modir_print_labels', 'orders' => 'MODIR_ORDER_ID'), admin_url('admin-post.php'))); ?>';
        window.open(url.replace('MODIR_ORDER_ID', encodeURIComponent(id)), '_blank');
    });
    </script>
    <?php
}

/**
 * برای لینک‌های قدیمی/بوکمارک‌شده: مستقیم صفحه سفارشات را با تب «لیبل
 * آدرس» فعال نمایش می‌دهد، چون این ماژول به آنجا منتقل شده است.
 */
function modir_render_label_page() {
    $_GET['tab'] = 'label';
    modir_render_orders_list_page();
}
