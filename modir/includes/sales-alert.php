<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * تب «آلارم فروش»: محصولاتی که قبلاً فروش معناداری داشته‌اند ولی مدتی است
 * هیچ فروشی نداشته‌اند را با شدت هشدار رنگی مشخص می‌کند.
 * ==========================================================================
 */

/**
 * سابقه فروش هر محصول را در بازه بررسی جمع می‌کند و محصولاتی که از آخرین
 * فروششان بیش از آستانه تعیین‌شده گذشته را برمی‌گرداند.
 */
function modir_sales_alert_gather($lookback_days, $no_sale_threshold_days) {
    $now_ts = current_time('timestamp');
    $from   = date('Y-m-d 00:00:00', $now_ts - ($lookback_days * DAY_IN_SECONDS));
    $to     = date('Y-m-d 23:59:59', $now_ts);

    $statuses = array_values(array_diff(modir_get_default_paid_statuses(), array('cancelled', 'refunded')));

    $orders = wc_get_orders(array(
        'status'       => $statuses,
        'type'         => 'shop_order',
        'date_created' => $from . '...' . $to,
        'limit'        => -1,
        'return'       => 'objects',
    ));

    $products = array();

    foreach ($orders as $order) {
        if (!is_a($order, 'WC_Order')) {
            continue;
        }
        $created  = $order->get_date_created();
        $order_ts = $created ? $created->getTimestamp() : 0;
        if (!$order_ts) {
            continue;
        }

        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            $pid     = $product ? $product->get_id() : 0;
            if (!$pid) {
                continue; // محصولات حذف‌شده از فروشگاه را نادیده می‌گیریم
            }

            $qty = (float) $item->get_quantity();

            if (!isset($products[$pid])) {
                $products[$pid] = array(
                    'name'          => $item->get_name(),
                    'qty_total'     => 0,
                    'order_count'   => 0,
                    'last_sale_ts'  => 0,
                    'first_sale_ts' => PHP_INT_MAX,
                );
            }

            $products[$pid]['qty_total']    += $qty;
            $products[$pid]['order_count']++;
            $products[$pid]['last_sale_ts']  = max($products[$pid]['last_sale_ts'], $order_ts);
            $products[$pid]['first_sale_ts'] = min($products[$pid]['first_sale_ts'], $order_ts);
        }
    }

    $alerts = array();

    foreach ($products as $pid => $row) {
        $days_since = (int) floor(($now_ts - $row['last_sale_ts']) / DAY_IN_SECONDS);
        if ($days_since < $no_sale_threshold_days) {
            continue; // هنوز به اندازه کافی اخیراً فروش داشته
        }

        if ($row['order_count'] < 2 && $row['qty_total'] < 2) {
            continue; // یک خرید تکی تصادفی را «قبلاً پرفروش بوده» حساب نمی‌کنیم
        }

        $span_weeks   = max(1, ($row['last_sale_ts'] - $row['first_sale_ts']) / WEEK_IN_SECONDS);
        $avg_per_week = $row['qty_total'] / $span_weeks;

        $alerts[$pid] = array(
            'product_id'   => $pid,
            'name'         => $row['name'],
            'qty_total'    => $row['qty_total'],
            'order_count'  => $row['order_count'],
            'last_sale_ts' => $row['last_sale_ts'],
            'days_since'   => $days_since,
            'avg_per_week' => $avg_per_week,
        );
    }

    uasort($alerts, function ($a, $b) {
        return $b['days_since'] <=> $a['days_since'];
    });

    return $alerts;
}

function modir_sales_alert_severity($days_since) {
    if ($days_since >= 60) {
        return array('label' => 'بحرانی', 'class' => 'is-critical');
    }
    if ($days_since >= 30) {
        return array('label' => 'جدی', 'class' => 'is-serious');
    }
    if ($days_since >= 14) {
        return array('label' => 'هشدار', 'class' => 'is-warning');
    }
    return array('label' => 'توجه', 'class' => 'is-notice');
}

/* ======================================================================
 * رندر تب
 * ====================================================================== */

function modir_render_sales_alert_tab_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $lookback  = isset($_GET['lookback']) ? absint($_GET['lookback']) : 90;
    $threshold = isset($_GET['threshold']) ? absint($_GET['threshold']) : 14;
    if (!in_array($lookback, array(30, 60, 90, 180), true)) {
        $lookback = 90;
    }
    if (!in_array($threshold, array(7, 14, 30, 60), true)) {
        $threshold = 14;
    }

    $alerts = modir_sales_alert_gather($lookback, $threshold);

    $critical_count = 0;
    foreach ($alerts as $a) {
        if ($a['days_since'] >= 60) {
            $critical_count++;
        }
    }
    ?>
    <form method="get" class="modir-filter-form">
        <input type="hidden" name="page" value="modir-accounting">
        <input type="hidden" name="tab" value="sales_alert">
        <div class="modir-filter-row">
            <div class="modir-filter-field">
                <label>بازه بررسی سابقه فروش</label>
                <select name="lookback">
                    <option value="30" <?php selected($lookback, 30); ?>>۳۰ روز اخیر</option>
                    <option value="60" <?php selected($lookback, 60); ?>>۶۰ روز اخیر</option>
                    <option value="90" <?php selected($lookback, 90); ?>>۹۰ روز اخیر</option>
                    <option value="180" <?php selected($lookback, 180); ?>>۱۸۰ روز اخیر</option>
                </select>
            </div>
            <div class="modir-filter-field">
                <label>حداقل روزهای بدون فروش</label>
                <select name="threshold">
                    <option value="7" <?php selected($threshold, 7); ?>>۷ روز</option>
                    <option value="14" <?php selected($threshold, 14); ?>>۱۴ روز</option>
                    <option value="30" <?php selected($threshold, 30); ?>>۳۰ روز</option>
                    <option value="60" <?php selected($threshold, 60); ?>>۶۰ روز</option>
                </select>
            </div>
            <div class="modir-filter-field modir-filter-submit">
                <button type="submit" class="button button-primary modir-btn">اعمال فیلتر</button>
            </div>
        </div>
    </form>
    <p class="modir-jalali-range">سابقه فروش <?php echo number_format_i18n($lookback); ?> روز اخیر بررسی شد؛ محصولاتی که بیش از <?php echo number_format_i18n($threshold); ?> روز است فروشی نداشته‌اند نمایش داده می‌شوند.</p>

    <div class="modir-summary-cards">
        <div class="modir-card <?php echo $alerts ? 'modir-card-danger' : ''; ?>">
            <span class="modir-card-label">تعداد محصولات هشداردار</span>
            <span class="modir-card-value"><?php echo number_format_i18n(count($alerts)); ?></span>
        </div>
        <div class="modir-card modir-card-danger">
            <span class="modir-card-label">در وضعیت بحرانی (بیش از ۶۰ روز)</span>
            <span class="modir-card-value"><?php echo number_format_i18n($critical_count); ?></span>
        </div>
    </div>

    <?php if (empty($alerts)) : ?>
        <p class="modir-empty">در این تنظیمات، محصولی که قبلاً پرفروش بوده و حالا متوقف شده باشد پیدا نشد.</p>
    <?php else : ?>
        <table class="widefat modir-table modir-product-table">
            <thead>
                <tr>
                    <th>کالا</th>
                    <th>شدت هشدار</th>
                    <th>آخرین فروش</th>
                    <th>روز بدون فروش</th>
                    <th>فروش در بازه بررسی</th>
                    <th>میانگین فروش هفتگی (قبل از توقف)</th>
                    <th>موجودی فعلی</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($alerts as $row) :
                    $product  = wc_get_product($row['product_id']);
                    $img      = $product ? $product->get_image(array('width' => 40, 'height' => 40)) : wc_placeholder_img(array('width' => 40, 'height' => 40));
                    $severity = modir_sales_alert_severity($row['days_since']);
                    $stock    = $product ? ($product->is_in_stock() ? 'موجود' : 'ناموجود') : '—';
                    ?>
                    <tr class="modir-product-row">
                        <td>
                            <div class="modir-product-cell">
                                <span class="modir-product-thumb"><?php echo wp_kses_post($img); ?></span>
                                <span class="modir-product-name"><?php echo esc_html($row['name']); ?></span>
                            </div>
                        </td>
                        <td><span class="modir-alert-badge <?php echo esc_attr($severity['class']); ?>"><?php echo esc_html($severity['label']); ?></span></td>
                        <td><?php echo esc_html(modir_format_jalali(date('Y-m-d', $row['last_sale_ts']))); ?></td>
                        <td style="font-weight:700;"><?php echo number_format_i18n($row['days_since']); ?> روز</td>
                        <td><?php echo number_format_i18n($row['qty_total']); ?> عدد / <?php echo number_format_i18n($row['order_count']); ?> سفارش</td>
                        <td class="modir-text-muted"><?php echo number_format_i18n(round($row['avg_per_week'], 1)); ?> عدد در هفته</td>
                        <td><?php echo esc_html($stock); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
}
