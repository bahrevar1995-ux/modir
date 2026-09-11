<?php
if (!defined('ABSPATH')) exit;

/**
 * گزارش ۱: چه کالایی، در چه تعداد، از چه درگاهی، با چه قیمتی فروخته شده است
 */
function modir_render_product_report_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $range = modir_get_date_range();

    // سفارش‌های لغوشده/مستردشده در هیچ حالتی در این گزارش لحاظ نمی‌شوند
    $statuses = array_values(array_diff(modir_get_default_paid_statuses(), array('cancelled', 'refunded')));

    $orders = wc_get_orders(array(
        'status'       => $statuses,
        'type'         => 'shop_order',
        'date_created' => $range['from'] . '...' . $range['to'] . ' 23:59:59',
        'limit'        => -1,
        'return'       => 'objects',
    ));

    $products       = array();
    $grand_qty      = 0;
    $grand_total    = 0;
    $shipping_total = 0;

    foreach ($orders as $order) {
        if (!is_a($order, 'WC_Order')) {
            continue; // رکوردهای مرجوعی (Refund) متدهای درگاه پرداخت ندارند
        }

        $shipping_total += (float) $order->get_shipping_total();
        $gateway_title   = $order->get_payment_method_title();
        if (!$gateway_title) {
            $gateway_title = $order->get_payment_method() ?: 'نامشخص';
        }

        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            $pid     = $product ? $product->get_id() : 0;
            $name    = $item->get_name();
            $qty     = (float) $item->get_quantity();
            $total   = (float) $item->get_total();

            $key = $pid ? $pid : 'name-' . $name;

            if (!isset($products[$key])) {
                $products[$key] = array('name' => $name, 'qty' => 0, 'total' => 0, 'product_id' => $pid, 'gateways' => array());
            }
            $products[$key]['qty']   += $qty;
            $products[$key]['total'] += $total;

            if (!isset($products[$key]['gateways'][$gateway_title])) {
                $products[$key]['gateways'][$gateway_title] = 0;
            }
            $products[$key]['gateways'][$gateway_title] += $qty;

            $grand_qty   += $qty;
            $grand_total += $total;
        }
    }

    uasort($products, function ($a, $b) {
        return $b['qty'] <=> $a['qty'];
    });

    $grand_total_with_shipping = $grand_total + $shipping_total;

    ?>
    <?php modir_render_date_filter('modir-accounting', '', 'products'); ?>
    <p class="modir-jalali-range">بازه انتخابی: <?php echo esc_html(modir_format_jalali($range['from']) . ' تا ' . modir_format_jalali($range['to'])); ?> — سفارش‌های لغوشده و مستردشده در این گزارش محاسبه نمی‌شوند.</p>

        <div class="modir-summary-cards">
            <div class="modir-card">
                <span class="modir-card-label">تعداد کل کالای فروخته شده</span>
                <span class="modir-card-value"><?php echo number_format_i18n($grand_qty); ?></span>
            </div>
            <div class="modir-card">
                <span class="modir-card-label">جمع فروش کالاها</span>
                <span class="modir-card-value"><?php echo wp_kses_post(wc_price($grand_total)); ?></span>
            </div>
            <div class="modir-card">
                <span class="modir-card-label">مبلغ حمل‌ونقل دریافتی</span>
                <span class="modir-card-value"><?php echo wp_kses_post(wc_price($shipping_total)); ?></span>
            </div>
            <div class="modir-card modir-card-success">
                <span class="modir-card-label">جمع کل (با احتساب حمل‌ونقل)</span>
                <span class="modir-card-value"><?php echo wp_kses_post(wc_price($grand_total_with_shipping)); ?></span>
            </div>
            <div class="modir-card">
                <span class="modir-card-label">تعداد سفارشات بررسی شده</span>
                <span class="modir-card-value"><?php echo number_format_i18n(count($orders)); ?></span>
            </div>
        </div>

        <?php if (empty($products)) : ?>
            <p class="modir-empty">در این بازه زمانی هیچ فروشی ثبت نشده است.</p>
        <?php else : ?>
        <table class="widefat modir-table modir-product-table">
            <thead>
                <tr>
                    <th>کالا</th>
                    <th>تعداد فروخته شده</th>
                    <th>تفکیک بر اساس درگاه پرداخت</th>
                    <th>جمع مبلغ فروش</th>
                    <th>میانگین قیمت واحد</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $row) :
                    $avg     = $row['qty'] > 0 ? $row['total'] / $row['qty'] : 0;
                    $product = !empty($row['product_id']) ? wc_get_product($row['product_id']) : false;
                    $img     = $product ? $product->get_image(array('width' => 40, 'height' => 40)) : wc_placeholder_img(array('width' => 40, 'height' => 40));

                    arsort($row['gateways']);
                    ?>
                    <tr class="modir-product-row">
                        <td>
                            <div class="modir-product-cell">
                                <span class="modir-product-thumb"><?php echo wp_kses_post($img); ?></span>
                                <span class="modir-product-name"><?php echo esc_html($row['name']); ?></span>
                            </div>
                        </td>
                        <td><span class="modir-qty-pill"><?php echo number_format_i18n($row['qty']); ?></span></td>
                        <td>
                            <div class="modir-gateway-chips">
                                <?php foreach ($row['gateways'] as $gw_title => $gw_qty) : ?>
                                    <span class="modir-gateway-chip">
                                        <span class="modir-gateway-chip-name"><?php echo esc_html($gw_title); ?></span>
                                        <span class="modir-gateway-chip-qty"><?php echo number_format_i18n($gw_qty); ?></span>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td><?php echo wp_kses_post(wc_price($row['total'])); ?></td>
                        <td class="modir-text-muted"><?php echo wp_kses_post(wc_price($avg)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>جمع کل</th>
                    <th><?php echo number_format_i18n($grand_qty); ?></th>
                    <th></th>
                    <th><?php echo wp_kses_post(wc_price($grand_total)); ?></th>
                    <th>—</th>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>
    <?php
}
