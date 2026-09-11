<?php
if (!defined('ABSPATH')) exit;

/**
 * گزارش ۳: آمار فروش و تعداد سفارشات در وضعیت‌های مختلف
 */
function modir_render_order_status_report_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $range         = modir_get_date_range();
    $statuses      = wc_get_order_statuses(); // wc-xxx => label
    $paid_statuses = modir_get_default_paid_statuses(); // فقط وضعیت‌هایی که در تنظیمات به‌عنوان «پرداخت موفق» علامت خورده‌اند

    $rows         = array();
    $total_orders = 0;
    $total_amount = 0;
    $paid_orders  = 0;
    $paid_amount  = 0;

    foreach ($statuses as $key => $label) {
        $status = str_replace('wc-', '', $key);

        $orders = wc_get_orders(array(
            'status'       => array($status),
            'type'         => 'shop_order',
            'date_created' => $range['from'] . '...' . $range['to'] . ' 23:59:59',
            'limit'        => -1,
            'return'       => 'objects',
        ));

        $count = count($orders);
        $sum   = 0;
        foreach ($orders as $o) {
            $sum += (float) $o->get_total();
        }

        if ($count > 0) {
            $is_paid = in_array($status, $paid_statuses, true);

            $rows[] = array('label' => $label, 'count' => $count, 'sum' => $sum, 'is_paid' => $is_paid);
            $total_orders += $count;
            $total_amount += $sum;

            if ($is_paid) {
                $paid_orders += $count;
                $paid_amount += $sum;
            }
        }
    }

    ?>
    <?php modir_render_date_filter('modir-accounting', '', 'status'); ?>
    <p class="modir-jalali-range">بازه انتخابی: <?php echo esc_html(modir_format_jalali($range['from']) . ' تا ' . modir_format_jalali($range['to'])); ?></p>
    <p class="description">
        وضعیت‌هایی که «پرداخت موفق» محسوب می‌شوند از <a href="<?php echo esc_url(add_query_arg(array('page' => 'modir-settings'), admin_url('admin.php'))); ?>">تنظیمات افزونه</a> قابل تغییرند.
    </p>

        <div class="modir-summary-cards">
            <div class="modir-card">
                <span class="modir-card-label">تعداد کل سفارشات (همه وضعیت‌ها)</span>
                <span class="modir-card-value"><?php echo number_format_i18n($total_orders); ?></span>
            </div>
            <div class="modir-card">
                <span class="modir-card-label">جمع مبلغ کل سفارشات</span>
                <span class="modir-card-value"><?php echo wp_kses_post(wc_price($total_amount)); ?></span>
            </div>
            <div class="modir-card modir-card-success">
                <span class="modir-card-label">تعداد سفارش‌های موفق</span>
                <span class="modir-card-value"><?php echo number_format_i18n($paid_orders); ?></span>
            </div>
            <div class="modir-card modir-card-success">
                <span class="modir-card-label">جمع مبلغ سفارش‌های موفق</span>
                <span class="modir-card-value"><?php echo wp_kses_post(wc_price($paid_amount)); ?></span>
            </div>
        </div>

        <?php if (empty($rows)) : ?>
            <p class="modir-empty">در این بازه زمانی سفارشی ثبت نشده است.</p>
        <?php else : ?>
        <table class="widefat striped modir-table">
            <thead>
                <tr>
                    <th>وضعیت سفارش</th>
                    <th>دسته</th>
                    <th>تعداد سفارشات</th>
                    <th>درصد از کل</th>
                    <th>جمع مبلغ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <?php $percent = $total_orders > 0 ? ($row['count'] / $total_orders) * 100 : 0; ?>
                    <tr>
                        <td><?php echo esc_html($row['label']); ?></td>
                        <td>
                            <?php if ($row['is_paid']) : ?>
                                <span class="modir-status-badge is-active">موفق</span>
                            <?php else : ?>
                                <span class="modir-status-badge is-inactive">سایر</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo number_format_i18n($row['count']); ?></td>
                        <td><?php echo number_format_i18n($percent, 1); ?>%</td>
                        <td><?php echo wp_kses_post(wc_price($row['sum'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    <?php
}
