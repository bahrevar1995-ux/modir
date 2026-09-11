<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * تب «درگاه‌ها»: فروش/کارمزد هر درگاه در یک بازه، و پیش‌بینی ورودی نقدی هر
 * ماه بر اساس دوره تسویه‌ای که برای هر درگاه در تنظیمات مشخص شده.
 *
 * توجه فنی: عمداً سفارش‌ها را یک‌بار کامل می‌خوانیم و خودمان بر اساس
 * get_payment_method() واقعی هر سفارش گروه‌بندی می‌کنیم، نه با meta_query
 * روی _payment_method — چون در حالت HPOS ووکامرس این متادیتا همیشه به‌درستی
 * همگام‌سازی نمی‌شود و باعث می‌شد آمار بعضی درگاه‌ها (مثل زرین‌پال) ناقص
 * دیده شود.
 * ==========================================================================
 */

function modir_shift_jalali_month($jy, $jm, $delta) {
    $total  = ($jy * 12) + ($jm - 1) + $delta;
    $new_jy = intdiv($total, 12);
    $new_jm = ($total % 12) + 1;
    if ($new_jm <= 0) {
        $new_jm += 12;
        $new_jy -= 1;
    }
    return array($new_jy, $new_jm);
}

/**
 * همه سفارش‌های یک بازه را یک‌بار می‌خواند و بر اساس درگاه واقعی هرکدام
 * (get_payment_method()) گروه‌بندی می‌کند. خروجی: [gateway_id => [title, count, sales, commission, net]]
 */
function modir_gateways_aggregate($date_from, $date_to) {
    $statuses = array_values(array_diff(modir_get_default_paid_statuses(), array('cancelled', 'refunded')));

    $orders = wc_get_orders(array(
        'status'       => $statuses,
        'type'         => 'shop_order',
        'date_created' => $date_from . '...' . $date_to,
        'limit'        => -1,
        'return'       => 'objects',
    ));

    $data = array();

    foreach ($orders as $order) {
        if (!is_a($order, 'WC_Order')) {
            continue;
        }

        $gid   = $order->get_payment_method();
        $gid   = $gid ? $gid : '(بدون درگاه)';
        $title = $order->get_payment_method_title();
        $title = $title ? $title : $gid;

        $cfg = modir_orders_get_commission($gid);

        if (!isset($data[$gid])) {
            $data[$gid] = array(
                'title'      => $title,
                'count'      => 0,
                'sales'      => 0,
                'commission' => 0,
                'settlement' => $cfg['settlement_months'] ?? 1,
            );
        }

        $total = (float) $order->get_total();

        $data[$gid]['count']++;
        $data[$gid]['sales']      += $total;
        $data[$gid]['commission'] += ($total * ((float) $cfg['percent']) / 100) + (float) $cfg['fixed'];
    }

    foreach ($data as $gid => $row) {
        $data[$gid]['net'] = $row['sales'] - $row['commission'];
    }

    return $data;
}

/**
 * فروش/کارمزد/خالص یک درگاه در یک ماه شمسی مشخص (کش‌شده در حافظه درخواست).
 */
function modir_gateways_month_net($gateway_id, $jy, $jm) {
    static $month_cache = array();
    $month_key = $jy . '_' . $jm;

    if (!isset($month_cache[$month_key])) {
        list($start, $end) = modir_pl_month_range($jy, $jm);
        $month_cache[$month_key] = modir_gateways_aggregate($start, $end);
    }

    if (isset($month_cache[$month_key][$gateway_id])) {
        return $month_cache[$month_key][$gateway_id];
    }

    return array('sales' => 0, 'commission' => 0, 'net' => 0, 'count' => 0);
}

/**
 * ورودی نقدی تخمینی یک درگاه در یک ماه شمسی، با توجه به دوره تسویه‌اش.
 * مثال: دوره تسویه ۲ ماهه یعنی هر ماه، نصف خالص همین ماه + نصف خالص ماه قبل دریافت می‌شود.
 */
function modir_gateways_cash_inflow($gateway_id, $jy, $jm, $settlement_months) {
    $months = max(1, (int) $settlement_months);
    $total  = 0;
    for ($i = 0; $i < $months; $i++) {
        list($ry, $rm) = modir_shift_jalali_month($jy, $jm, -$i);
        $net    = modir_gateways_month_net($gateway_id, $ry, $rm);
        $total += $net['net'] / $months;
    }
    return $total;
}

/* ======================================================================
 * رندر تب
 * ====================================================================== */

function modir_render_gateways_tab_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $range = modir_get_date_range();
    $rows  = modir_gateways_aggregate($range['from'], $range['to'] . ' 23:59:59');
    arsort($rows);
    ?>
    <?php modir_render_date_filter('modir-accounting', '', 'gateways'); ?>
    <p class="modir-jalali-range">بازه انتخابی: <?php echo esc_html(modir_format_jalali($range['from']) . ' تا ' . modir_format_jalali($range['to'])); ?></p>

    <?php if (empty($rows)) : ?>
        <p class="modir-empty">در این بازه زمانی فروشی ثبت نشده است.</p>
    <?php else : ?>
        <table class="widefat striped modir-table">
            <thead>
                <tr>
                    <th>درگاه</th>
                    <th>تعداد سفارش</th>
                    <th>فروش کل</th>
                    <th>کارمزد</th>
                    <th>خالص</th>
                    <th>دوره تسویه</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r) : ?>
                    <tr>
                        <td><?php echo esc_html($r['title']); ?></td>
                        <td><?php echo number_format_i18n($r['count']); ?></td>
                        <td><?php echo wp_kses_post(wc_price($r['sales'])); ?></td>
                        <td class="modir-text-muted"><?php echo wp_kses_post(wc_price($r['commission'])); ?></td>
                        <td style="font-weight:700;"><?php echo wp_kses_post(wc_price($r['net'])); ?></td>
                        <td><?php echo (1 == $r['settlement']) ? 'فوری' : number_format_i18n($r['settlement']) . ' ماهه'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2 class="modir-subtitle">گزارش تسویه ماهانه</h2>
    <?php
    list($cur_jy, $cur_jm) = modir_jalali_today();
    $s_jy = isset($_GET['s_jy']) ? absint($_GET['s_jy']) : $cur_jy;
    $s_jm = isset($_GET['s_jm']) ? absint($_GET['s_jm']) : $cur_jm;
    if (!$s_jy) {
        $s_jy = $cur_jy;
    }
    if (!$s_jm || $s_jm > 12) {
        $s_jm = $cur_jm;
    }
    $months = modir_jalali_month_names();
    list($m_start, $m_end)  = modir_pl_month_range($s_jy, $s_jm);
    $this_month_data = modir_gateways_aggregate($m_start, $m_end);
    ?>
    <form method="get" class="modir-filter-form">
        <input type="hidden" name="page" value="modir-accounting">
        <input type="hidden" name="tab" value="gateways">
        <div class="modir-filter-row">
            <div class="modir-filter-field">
                <label>سال شمسی</label>
                <select name="s_jy">
                    <?php for ($y = $cur_jy - 3; $y <= $cur_jy + 1; $y++) : ?>
                        <option value="<?php echo esc_attr($y); ?>" <?php selected($s_jy, $y); ?>><?php echo esc_html(modir_to_persian_digits($y)); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="modir-filter-field">
                <label>ماه شمسی</label>
                <select name="s_jm">
                    <?php foreach ($months as $num => $mname) : ?>
                        <option value="<?php echo esc_attr($num); ?>" <?php selected($s_jm, $num); ?>><?php echo esc_html($mname); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modir-filter-field modir-filter-submit">
                <button type="submit" class="button button-primary modir-btn">محاسبه تسویه این ماه</button>
            </div>
        </div>
    </form>

    <p class="description">این جدول تخمین می‌زند بر اساس دوره تسویه هر درگاه، برای ماه انتخابی چقدر پول واقعاً به حسابت واریز می‌شود (نه لزوماً فروش همان ماه).</p>

    <?php if (empty($this_month_data)) : ?>
        <p class="modir-empty">در این ماه فروشی ثبت نشده است.</p>
    <?php else : ?>
    <table class="widefat striped modir-table">
        <thead>
            <tr>
                <th>درگاه</th>
                <th>فروش این ماه</th>
                <th>کارمزد این ماه</th>
                <th>خالص این ماه</th>
                <th>دوره تسویه</th>
                <th>ورودی نقدی تخمینی این ماه</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($this_month_data as $gid => $row) :
                $inflow = modir_gateways_cash_inflow($gid, $s_jy, $s_jm, $row['settlement']);
                ?>
                <tr>
                    <td><?php echo esc_html($row['title']); ?></td>
                    <td><?php echo wp_kses_post(wc_price($row['sales'])); ?></td>
                    <td class="modir-text-muted"><?php echo wp_kses_post(wc_price($row['commission'])); ?></td>
                    <td><?php echo wp_kses_post(wc_price($row['net'])); ?></td>
                    <td><?php echo (1 == $row['settlement']) ? 'فوری' : number_format_i18n($row['settlement']) . ' ماهه'; ?></td>
                    <td class="modir-amount-positive"><?php echo wp_kses_post(wc_price($inflow)); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <?php
}
