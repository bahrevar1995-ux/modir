<?php
if (!defined('ABSPATH')) exit;

/**
 * گزارش ۲: گزارش کامل فروش (ناخالص/خالص) با فیلتر زمان و درگاه پرداخت
 */
function modir_render_full_sales_report_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    try {
        modir_render_full_sales_report_inner();
    } catch (\Throwable $e) {
        echo '<div class="notice notice-error"><p>مشکلی در بارگذاری این گزارش پیش آمد. لطفاً صفحه را رفرش کنید؛ اگر مشکل ادامه داشت، این پیام را به توسعه‌دهنده اطلاع دهید: ' . esc_html($e->getMessage()) . '</p></div>';
    }
}

function modir_render_full_sales_report_inner() {
    $range          = modir_get_date_range();
    $payment_method = isset($_GET['payment_method']) ? sanitize_text_field(wp_unslash($_GET['payment_method'])) : '';

    $args = array(
        'status'       => modir_get_default_paid_statuses(),
        'type'         => 'shop_order',
        'date_created' => $range['from'] . '...' . $range['to'] . ' 23:59:59',
        'limit'        => -1,
        'return'       => 'objects',
    );

    if (!empty($payment_method)) {
        $args['meta_query'] = array(
            array(
                'key'   => '_payment_method',
                'value' => $payment_method,
            ),
        );
    }

    $orders = wc_get_orders($args);
    if (!is_array($orders)) {
        $orders = array();
    }

    $gross = 0.0;
    $refunded = 0.0;
    $tax = 0.0;
    $shipping = 0.0;
    $discount = 0.0;
    $by_gateway = array(); // slug => [title, count, gross, refunded]

    foreach ($orders as $order) {
        if (!$order || !is_a($order, 'WC_Order')) {
            continue;
        }

        $total = (float) $order->get_total();
        $ref   = (float) abs($order->get_total_refunded());

        $gross    += $total;
        $refunded += $ref;
        $tax      += (float) $order->get_total_tax();
        $shipping += (float) $order->get_shipping_total();
        $discount += (float) $order->get_discount_total();

        $method       = $order->get_payment_method();
        $method_title = $order->get_payment_method_title();
        if (empty($method_title)) {
            $method_title = 'نامشخص';
        }
        $gw_key = $method ? $method : 'unknown';
        if (!isset($by_gateway[$gw_key])) {
            $by_gateway[$gw_key] = array('title' => $method_title, 'count' => 0, 'gross' => 0, 'refunded' => 0);
        }
        $by_gateway[$gw_key]['count']++;
        $by_gateway[$gw_key]['gross']    += $total;
        $by_gateway[$gw_key]['refunded'] += $ref;
    }

    $net = $gross - $refunded;

    // لیست درگاه‌های پرداخت فعال برای فیلتر (فقط درگاه‌هایی که واقعاً روشن هستند)
    $gateways = function_exists('modir_orders_get_gateway_filter_options') ? modir_orders_get_gateway_filter_options() : array();

    $extra_html  = '<div class="modir-filter-field"><label>درگاه پرداخت</label><select name="payment_method">';
    $extra_html .= '<option value="">همه درگاه‌ها</option>';
    foreach ($gateways as $id => $title) {
        $selected = ($payment_method === $id) ? 'selected' : '';
        $extra_html .= '<option value="' . esc_attr($id) . '" ' . $selected . '>' . esc_html($title) . '</option>';
    }
    $extra_html .= '</select></div>';

    ?>
    <?php modir_render_date_filter('modir-accounting', $extra_html, 'sales'); ?>
    <p class="modir-jalali-range">بازه انتخابی: <?php echo esc_html(modir_format_jalali($range['from']) . ' تا ' . modir_format_jalali($range['to'])); ?></p>

        <div class="modir-summary-cards">
            <div class="modir-card">
                <span class="modir-card-label">فروش ناخالص</span>
                <span class="modir-card-value"><?php echo wp_kses_post(wc_price($gross)); ?></span>
            </div>
            <div class="modir-card modir-card-danger">
                <span class="modir-card-label">مجموع مرجوعی / استرداد</span>
                <span class="modir-card-value"><?php echo wp_kses_post(wc_price($refunded)); ?></span>
            </div>
            <div class="modir-card modir-card-success">
                <span class="modir-card-label">فروش خالص</span>
                <span class="modir-card-value"><?php echo wp_kses_post(wc_price($net)); ?></span>
            </div>
            <div class="modir-card">
                <span class="modir-card-label">مالیات</span>
                <span class="modir-card-value"><?php echo wp_kses_post(wc_price($tax)); ?></span>
            </div>
            <div class="modir-card">
                <span class="modir-card-label">هزینه ارسال</span>
                <span class="modir-card-value"><?php echo wp_kses_post(wc_price($shipping)); ?></span>
            </div>
            <div class="modir-card">
                <span class="modir-card-label">تخفیفات</span>
                <span class="modir-card-value"><?php echo wp_kses_post(wc_price($discount)); ?></span>
            </div>
            <div class="modir-card">
                <span class="modir-card-label">تعداد سفارشات</span>
                <span class="modir-card-value"><?php echo number_format_i18n(count($orders)); ?></span>
            </div>
        </div>

        <p class="description">این گزارش فقط شامل سفارش‌هایی است که وضعیتشان در تنظیمات به‌عنوان «پرداخت موفق» علامت خورده باشد (مدیر → تنظیمات).</p>

        <h2 class="modir-subtitle">تفکیک بر اساس درگاه پرداخت</h2>
        <?php if (empty($by_gateway)) : ?>
            <p class="modir-empty">داده‌ای برای نمایش وجود ندارد.</p>
        <?php else : ?>
        <table class="widefat striped modir-table">
            <thead>
                <tr>
                    <th>درگاه پرداخت</th>
                    <th>تعداد سفارش</th>
                    <th>فروش ناخالص</th>
                    <th>مرجوعی</th>
                    <th>فروش خالص</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($by_gateway as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row['title']); ?></td>
                        <td><?php echo number_format_i18n($row['count']); ?></td>
                        <td><?php echo wp_kses_post(wc_price($row['gross'])); ?></td>
                        <td><?php echo wp_kses_post(wc_price($row['refunded'])); ?></td>
                        <td><?php echo wp_kses_post(wc_price($row['gross'] - $row['refunded'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    <?php
}
