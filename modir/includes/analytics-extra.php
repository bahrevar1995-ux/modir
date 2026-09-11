<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * تب‌های تحلیلی: RFM مشتریان، قیف فروش/سبد رهاشده، پرفروش‌های همراه هم،
 * نقشه حرارتی فروش، گزارش جغرافیایی، سودآوری دسته‌بندی‌ها.
 * ==========================================================================
 */

/* ======================================================================
 * ۱. تحلیل RFM مشتریان
 * ====================================================================== */

function modir_rfm_gather($days_lookback) {
    $now  = current_time('timestamp');
    $from = date('Y-m-d 00:00:00', $now - ($days_lookback * DAY_IN_SECONDS));
    $to   = current_time('Y-m-d 23:59:59');

    $statuses = array_values(array_diff(modir_get_default_paid_statuses(), array('cancelled', 'refunded')));

    $orders = wc_get_orders(array(
        'status'       => $statuses,
        'type'         => 'shop_order',
        'date_created' => $from . '...' . $to,
        'limit'        => -1,
        'return'       => 'objects',
    ));

    $customers = array();
    foreach ($orders as $order) {
        if (!is_a($order, 'WC_Order')) {
            continue;
        }
        $email = $order->get_billing_email();
        $key   = $email ? $email : ('phone-' . $order->get_billing_phone());
        if (!$key || 'phone-' === $key) {
            continue;
        }
        $ts = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;

        if (!isset($customers[$key])) {
            $customers[$key] = array(
                'name'    => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'email'   => $email,
                'phone'   => $order->get_billing_phone(),
                'orders'  => 0,
                'total'   => 0,
                'last_ts' => 0,
            );
        }
        $customers[$key]['orders']++;
        $customers[$key]['total'] += (float) $order->get_total();
        $customers[$key]['last_ts'] = max($customers[$key]['last_ts'], $ts);
    }

    if (empty($customers)) {
        return array();
    }

    $avg_total = array_sum(array_column($customers, 'total')) / count($customers);

    foreach ($customers as $key => &$c) {
        $days = (int) floor(($now - $c['last_ts']) / DAY_IN_SECONDS);
        $c['recency_days'] = $days;

        if ($days <= 7) {
            $r = 5;
        } elseif ($days <= 30) {
            $r = 4;
        } elseif ($days <= 60) {
            $r = 3;
        } elseif ($days <= 120) {
            $r = 2;
        } else {
            $r = 1;
        }

        $orders_count = $c['orders'];
        if ($orders_count >= 10) {
            $f = 5;
        } elseif ($orders_count >= 5) {
            $f = 4;
        } elseif ($orders_count >= 3) {
            $f = 3;
        } elseif ($orders_count >= 2) {
            $f = 2;
        } else {
            $f = 1;
        }

        $ratio = $avg_total > 0 ? ($c['total'] / $avg_total) : 0;
        if ($ratio >= 3) {
            $m = 5;
        } elseif ($ratio >= 2) {
            $m = 4;
        } elseif ($ratio >= 1) {
            $m = 3;
        } elseif ($ratio >= 0.5) {
            $m = 2;
        } else {
            $m = 1;
        }

        $c['r'] = $r;
        $c['f'] = $f;
        $c['m'] = $m;
        $avg    = ($r + $f + $m) / 3;

        if ($avg >= 4.5) {
            $c['segment'] = array('label' => 'مشتری طلایی', 'class' => 'is-good');
        } elseif ($avg >= 3.5) {
            $c['segment'] = array('label' => 'وفادار', 'class' => 'is-good');
        } elseif ($avg >= 2.5) {
            $c['segment'] = array('label' => 'معمولی', 'class' => 'is-neutral');
        } elseif ($avg >= 1.5) {
            $c['segment'] = array('label' => 'در خطر ریزش', 'class' => 'is-bad');
        } else {
            $c['segment'] = array('label' => 'غیرفعال', 'class' => 'is-bad');
        }
    }
    unset($c);

    uasort($customers, function ($a, $b) {
        return ($b['r'] + $b['f'] + $b['m']) <=> ($a['r'] + $a['f'] + $a['m']);
    });

    return $customers;
}

function modir_render_rfm_tab_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $days = isset($_GET['rfm_days']) ? absint($_GET['rfm_days']) : 180;
    if (!in_array($days, array(90, 180, 365), true)) {
        $days = 180;
    }

    $customers = modir_rfm_gather($days);
    $champions = 0;
    foreach ($customers as $c) {
        if ('مشتری طلایی' === $c['segment']['label']) {
            $champions++;
        }
    }
    ?>
    <form method="get" class="modir-filter-form">
        <input type="hidden" name="page" value="modir-accounting">
        <input type="hidden" name="tab" value="rfm">
        <div class="modir-filter-row">
            <div class="modir-filter-field">
                <label>بازه بررسی</label>
                <select name="rfm_days" class="modir-pill-select">
                    <option value="90" <?php selected($days, 90); ?>>۹۰ روز اخیر</option>
                    <option value="180" <?php selected($days, 180); ?>>۱۸۰ روز اخیر</option>
                    <option value="365" <?php selected($days, 365); ?>>یک سال اخیر</option>
                </select>
            </div>
            <div class="modir-filter-field modir-filter-submit">
                <button type="submit" class="button button-primary modir-btn">اعمال</button>
            </div>
        </div>
    </form>

    <div class="modir-alert">
        R = تازگی آخرین خرید، F = تعداد دفعات خرید، M = مجموع مبلغ خرید نسبت به میانگین مشتریان. هرکدام از ۱ تا ۵ امتیاز می‌گیرند.
    </div>

    <div class="modir-summary-cards">
        <div class="modir-card">
            <span class="modir-card-label">تعداد مشتریان بررسی‌شده</span>
            <span class="modir-card-value"><?php echo number_format_i18n(count($customers)); ?></span>
        </div>
        <div class="modir-card modir-card-success">
            <span class="modir-card-label">مشتریان طلایی</span>
            <span class="modir-card-value"><?php echo number_format_i18n($champions); ?></span>
        </div>
    </div>

    <?php if (empty($customers)) : ?>
        <p class="modir-empty">داده‌ای برای این بازه پیدا نشد.</p>
    <?php else : ?>
        <table class="widefat striped modir-table">
            <thead>
                <tr>
                    <th>مشتری</th>
                    <th>تماس</th>
                    <th>آخرین خرید</th>
                    <th>تعداد سفارش</th>
                    <th>جمع خرید</th>
                    <th>R</th>
                    <th>F</th>
                    <th>M</th>
                    <th>دسته‌بندی</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($customers, 0, 300) as $c) : ?>
                    <tr>
                        <td><?php echo esc_html($c['name'] ?: '—'); ?></td>
                        <td class="modir-text-muted"><?php echo esc_html($c['phone'] ?: $c['email']); ?></td>
                        <td><?php echo number_format_i18n($c['recency_days']); ?> روز پیش</td>
                        <td><?php echo number_format_i18n($c['orders']); ?></td>
                        <td><?php echo wp_kses_post(wc_price($c['total'])); ?></td>
                        <td><?php echo number_format_i18n($c['r']); ?></td>
                        <td><?php echo number_format_i18n($c['f']); ?></td>
                        <td><?php echo number_format_i18n($c['m']); ?></td>
                        <td><span class="modir-stat-badge <?php echo esc_attr($c['segment']['class']); ?>"><?php echo esc_html($c['segment']['label']); ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (count($customers) > 300) : ?>
            <p class="description">فقط ۳۰۰ مشتری برتر نمایش داده شد.</p>
        <?php endif; ?>
    <?php endif; ?>
    <?php
}

/* ======================================================================
 * ۲. قیف فروش / سبد رهاشده
 * ====================================================================== */

function modir_render_funnel_tab_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $range = modir_get_date_range();

    $abandoned_statuses = array('pending', 'checkout-draft', 'failed', 'on-hold');
    $completed_statuses = array_values(array_diff(modir_get_default_paid_statuses(), array('cancelled', 'refunded')));

    $abandoned = wc_get_orders(array(
        'status'       => $abandoned_statuses,
        'type'         => 'shop_order',
        'date_created' => $range['from'] . '...' . $range['to'] . ' 23:59:59',
        'limit'        => -1,
        'return'       => 'objects',
    ));

    $completed_count = count(wc_get_orders(array(
        'status'       => $completed_statuses,
        'type'         => 'shop_order',
        'date_created' => $range['from'] . '...' . $range['to'] . ' 23:59:59',
        'limit'        => -1,
        'return'       => 'ids',
    )));

    $total_started = count($abandoned) + $completed_count;
    $conversion    = $total_started > 0 ? ($completed_count / $total_started * 100) : 0;
    ?>
    <?php modir_render_date_filter('modir-accounting', '', 'funnel'); ?>
    <p class="modir-jalali-range">بازه انتخابی: <?php echo esc_html(modir_format_jalali($range['from']) . ' تا ' . modir_format_jalali($range['to'])); ?></p>

    <div class="modir-summary-cards">
        <div class="modir-card">
            <span class="modir-card-label">شروع‌شده (پرداخت‌نشده)</span>
            <span class="modir-card-value"><?php echo number_format_i18n(count($abandoned)); ?></span>
        </div>
        <div class="modir-card modir-card-success">
            <span class="modir-card-label">تکمیل‌شده</span>
            <span class="modir-card-value"><?php echo number_format_i18n($completed_count); ?></span>
        </div>
        <div class="modir-card <?php echo $conversion >= 50 ? 'modir-card-success' : 'modir-card-danger'; ?>">
            <span class="modir-card-label">نرخ تبدیل</span>
            <span class="modir-card-value"><?php echo number_format_i18n($conversion, 1); ?>٪</span>
        </div>
    </div>

    <h2 class="modir-subtitle">سبدهای رهاشده برای پیگیری</h2>
    <?php if (empty($abandoned)) : ?>
        <p class="modir-empty">سبد رهاشده‌ای در این بازه پیدا نشد.</p>
    <?php else : ?>
        <table class="widefat striped modir-table">
            <thead>
                <tr>
                    <th>مشتری</th>
                    <th>تماس</th>
                    <th>مبلغ</th>
                    <th>وضعیت</th>
                    <th>تاریخ</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $all_statuses = wc_get_order_statuses();
                foreach (array_slice($abandoned, 0, 200) as $order) :
                    if (!is_a($order, 'WC_Order')) {
                        continue;
                    }
                    $label = $all_statuses['wc-' . $order->get_status()] ?? $order->get_status();
                    ?>
                    <tr>
                        <td><?php echo esc_html(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name())) ?: '—'; ?></td>
                        <td class="modir-text-muted"><?php echo esc_html($order->get_billing_phone() ?: $order->get_billing_email()); ?></td>
                        <td><?php echo wp_kses_post(wc_price($order->get_total())); ?></td>
                        <td><span class="modir-stat-badge is-bad"><?php echo esc_html($label); ?></span></td>
                        <td><?php echo esc_html($order->get_date_created() ? modir_format_jalali($order->get_date_created()->date('Y-m-d')) : '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
}

/* ======================================================================
 * ۳. پرفروش‌های همراه هم (Cross-sell)
 * ====================================================================== */

function modir_cross_sell_gather($from, $to, $limit_pairs = 25) {
    $statuses = array_values(array_diff(modir_get_default_paid_statuses(), array('cancelled', 'refunded')));

    $orders = wc_get_orders(array(
        'status'       => $statuses,
        'type'         => 'shop_order',
        'date_created' => $from . '...' . $to . ' 23:59:59',
        'limit'        => -1,
        'return'       => 'objects',
    ));

    $pair_counts = array();
    $names       = array();

    foreach ($orders as $order) {
        if (!is_a($order, 'WC_Order')) {
            continue;
        }
        $pids = array();
        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            if ($product) {
                $pid          = $product->get_id();
                $pids[]       = $pid;
                $names[$pid]  = $item->get_name();
            }
        }
        $pids = array_values(array_unique($pids));
        sort($pids);
        $n = count($pids);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $key = $pids[$i] . '-' . $pids[$j];
                if (!isset($pair_counts[$key])) {
                    $pair_counts[$key] = 0;
                }
                $pair_counts[$key]++;
            }
        }
    }

    arsort($pair_counts);
    $result = array();
    foreach ($pair_counts as $key => $count) {
        if ($count < 2) {
            continue;
        }
        list($a, $b) = explode('-', $key);
        $result[] = array('a' => $names[$a] ?? ('#' . $a), 'b' => $names[$b] ?? ('#' . $b), 'count' => $count);
        if (count($result) >= $limit_pairs) {
            break;
        }
    }

    return $result;
}

function modir_render_cross_sell_tab_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $range = modir_get_date_range();
    $pairs = modir_cross_sell_gather($range['from'], $range['to']);
    ?>
    <?php modir_render_date_filter('modir-accounting', '', 'cross_sell'); ?>
    <p class="modir-jalali-range">بازه انتخابی: <?php echo esc_html(modir_format_jalali($range['from']) . ' تا ' . modir_format_jalali($range['to'])); ?></p>
    <div class="modir-alert">فقط جفت‌کالاهایی نشان داده می‌شوند که حداقل ۲ بار با هم در یک سفارش خریداری شده‌اند.</div>

    <?php if (empty($pairs)) : ?>
        <p class="modir-empty">الگوی قابل‌توجهی از خرید هم‌زمان دو کالا در این بازه پیدا نشد.</p>
    <?php else : ?>
        <table class="widefat striped modir-table">
            <thead>
                <tr>
                    <th>کالای اول</th>
                    <th>کالای دوم</th>
                    <th>تعداد سفارش مشترک</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pairs as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row['a']); ?></td>
                        <td><?php echo esc_html($row['b']); ?></td>
                        <td><span class="modir-stat-badge is-good"><?php echo number_format_i18n($row['count']); ?> سفارش</span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">پیشنهاد: این جفت‌ها را در پاپ‌آپ محصول یا صفحه محصول به‌عنوان «معمولاً با هم خریداری می‌شوند» نمایش بده.</p>
    <?php endif; ?>
    <?php
}

/* ======================================================================
 * ۴. نقشه حرارتی فروش (روز هفته × بازه ساعتی)
 * ====================================================================== */

function modir_render_heatmap_tab_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $range    = modir_get_date_range();
    $statuses = array_values(array_diff(modir_get_default_paid_statuses(), array('cancelled', 'refunded')));

    $orders = wc_get_orders(array(
        'status'       => $statuses,
        'type'         => 'shop_order',
        'date_created' => $range['from'] . '...' . $range['to'] . ' 23:59:59',
        'limit'        => -1,
        'return'       => 'objects',
    ));

    $day_labels   = array('شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه');
    $day_map      = array(6 => 0, 0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6);
    $hour_buckets = array('۰-۳', '۴-۷', '۸-۱۱', '۱۲-۱۵', '۱۶-۱۹', '۲۰-۲۳');

    $grid      = array_fill(0, 7, array_fill(0, 6, 0));
    $max_value = 0;

    foreach ($orders as $order) {
        if (!is_a($order, 'WC_Order') || !$order->get_date_created()) {
            continue;
        }
        $dt   = $order->get_date_created();
        $w    = (int) $dt->date('w');
        $hour = (int) $dt->date('G');
        $row  = $day_map[$w];
        $col  = intdiv($hour, 4);

        $grid[$row][$col]++;
        $max_value = max($max_value, $grid[$row][$col]);
    }
    ?>
    <?php modir_render_date_filter('modir-accounting', '', 'heatmap'); ?>
    <p class="modir-jalali-range">بازه انتخابی: <?php echo esc_html(modir_format_jalali($range['from']) . ' تا ' . modir_format_jalali($range['to'])); ?> — <?php echo number_format_i18n(count($orders)); ?> سفارش</p>

    <?php if (empty($orders) || 0 === $max_value) : ?>
        <p class="modir-empty">سفارشی در این بازه پیدا نشد.</p>
    <?php else : ?>
        <table class="widefat modir-table" style="text-align:center;">
            <thead>
                <tr>
                    <th>روز / ساعت</th>
                    <?php foreach ($hour_buckets as $hb) : ?>
                        <th><?php echo esc_html($hb); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($day_labels as $row_idx => $label) : ?>
                    <tr>
                        <th style="text-align:right;"><?php echo esc_html($label); ?></th>
                        <?php foreach ($grid[$row_idx] as $value) :
                            $intensity = $max_value > 0 ? ($value / $max_value) : 0;
                            $bg    = $value > 0 ? 'color-mix(in srgb, var(--modir-primary) ' . round($intensity * 80 + 10) . '%, #fff)' : 'transparent';
                            $color = $intensity > 0.5 ? '#fff' : 'var(--modir-text)';
                            ?>
                            <td style="background:<?php echo esc_attr($bg); ?>;color:<?php echo esc_attr($color); ?>;font-weight:600;"><?php echo number_format_i18n($value); ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">هرچه رنگ پررنگ‌تر، تعداد سفارش در آن بازه‌ی زمانی بیشتر بوده — مناسب برای زمان‌بندی پیامک/تبلیغات.</p>
    <?php endif; ?>
    <?php
}

/* ======================================================================
 * ۵. گزارش فروش جغرافیایی
 * ====================================================================== */

function modir_render_geo_tab_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $range    = modir_get_date_range();
    $statuses = array_values(array_diff(modir_get_default_paid_statuses(), array('cancelled', 'refunded')));

    $orders = wc_get_orders(array(
        'status'       => $statuses,
        'type'         => 'shop_order',
        'date_created' => $range['from'] . '...' . $range['to'] . ' 23:59:59',
        'limit'        => -1,
        'return'       => 'objects',
    ));

    $states = array();
    $cities = array();

    foreach ($orders as $order) {
        if (!is_a($order, 'WC_Order')) {
            continue;
        }
        $state_code = $order->get_shipping_state() ?: $order->get_billing_state();
        $city       = $order->get_shipping_city() ?: $order->get_billing_city();
        $total      = (float) $order->get_total();

        $state_label = $state_code;
        if ($state_code && function_exists('WC') && WC()->countries) {
            $country    = $order->get_shipping_country() ?: $order->get_billing_country() ?: 'IR';
            $all_states = WC()->countries->get_states($country);
            if (!empty($all_states[$state_code])) {
                $state_label = $all_states[$state_code];
            }
        }
        $state_label = $state_label ?: 'نامشخص';
        $city        = $city ?: 'نامشخص';

        if (!isset($states[$state_label])) {
            $states[$state_label] = array('count' => 0, 'total' => 0);
        }
        $states[$state_label]['count']++;
        $states[$state_label]['total'] += $total;

        if (!isset($cities[$city])) {
            $cities[$city] = array('count' => 0, 'total' => 0);
        }
        $cities[$city]['count']++;
        $cities[$city]['total'] += $total;
    }

    uasort($states, function ($a, $b) { return $b['total'] <=> $a['total']; });
    uasort($cities, function ($a, $b) { return $b['total'] <=> $a['total']; });
    ?>
    <?php modir_render_date_filter('modir-accounting', '', 'geo'); ?>
    <p class="modir-jalali-range">بازه انتخابی: <?php echo esc_html(modir_format_jalali($range['from']) . ' تا ' . modir_format_jalali($range['to'])); ?></p>

    <?php if (empty($states)) : ?>
        <p class="modir-empty">سفارشی در این بازه پیدا نشد.</p>
    <?php else : ?>
        <h2 class="modir-subtitle">فروش به تفکیک استان</h2>
        <table class="widefat striped modir-table">
            <thead><tr><th>استان</th><th>تعداد سفارش</th><th>جمع فروش</th></tr></thead>
            <tbody>
                <?php foreach ($states as $name => $s) : ?>
                    <tr><td><?php echo esc_html($name); ?></td><td><?php echo number_format_i18n($s['count']); ?></td><td><?php echo wp_kses_post(wc_price($s['total'])); ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2 class="modir-subtitle">پرفروش‌ترین شهرها</h2>
        <table class="widefat striped modir-table">
            <thead><tr><th>شهر</th><th>تعداد سفارش</th><th>جمع فروش</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($cities, 0, 15, true) as $name => $c) : ?>
                    <tr><td><?php echo esc_html($name); ?></td><td><?php echo number_format_i18n($c['count']); ?></td><td><?php echo wp_kses_post(wc_price($c['total'])); ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
}

/* ======================================================================
 * ۶. سودآوری دسته‌بندی‌ها
 * ====================================================================== */

function modir_render_category_profit_tab_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $range    = modir_get_date_range();
    $statuses = array_values(array_diff(modir_get_default_paid_statuses(), array('cancelled', 'refunded')));

    $orders = wc_get_orders(array(
        'status'       => $statuses,
        'type'         => 'shop_order',
        'date_created' => $range['from'] . '...' . $range['to'] . ' 23:59:59',
        'limit'        => -1,
        'return'       => 'objects',
    ));

    $cats = array();

    foreach ($orders as $order) {
        if (!is_a($order, 'WC_Order')) {
            continue;
        }
        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            $terms     = get_the_terms($product->get_id(), 'product_cat');
            $cat_names = ($terms && !is_wp_error($terms)) ? wp_list_pluck($terms, 'name') : array('بدون دسته‌بندی');
            $qty       = (float) $item->get_quantity();
            $total     = (float) $item->get_total();
            $cost      = (float) $product->get_meta('_modir_purchase_price') * $qty;

            foreach ($cat_names as $cat_name) {
                if (!isset($cats[$cat_name])) {
                    $cats[$cat_name] = array('name' => $cat_name, 'revenue' => 0, 'cost' => 0, 'qty' => 0);
                }
                $cats[$cat_name]['revenue'] += $total;
                $cats[$cat_name]['cost']    += $cost;
                $cats[$cat_name]['qty']     += $qty;
            }
        }
    }

    foreach ($cats as &$c) {
        $c['profit'] = $c['revenue'] - $c['cost'];
        $c['margin'] = $c['revenue'] > 0 ? ($c['profit'] / $c['revenue'] * 100) : 0;
    }
    unset($c);

    uasort($cats, function ($a, $b) { return $b['profit'] <=> $a['profit']; });
    ?>
    <?php modir_render_date_filter('modir-accounting', '', 'category_profit'); ?>
    <p class="modir-jalali-range">بازه انتخابی: <?php echo esc_html(modir_format_jalali($range['from']) . ' تا ' . modir_format_jalali($range['to'])); ?></p>
    <div class="modir-alert">هزینه هر ردیف بر پایه «قیمت خرید» ثبت‌شده برای آن محصول محاسبه می‌شود. دسته‌بندی‌هایی که قیمت خریدشان ثبت نشده، سود واقعی‌شان دقیق نیست.</div>

    <?php if (empty($cats)) : ?>
        <p class="modir-empty">داده‌ای برای این بازه پیدا نشد.</p>
    <?php else : ?>
        <table class="widefat striped modir-table">
            <thead>
                <tr><th>دسته‌بندی</th><th>تعداد فروش</th><th>فروش</th><th>هزینه خرید</th><th>سود</th><th>حاشیه سود</th></tr>
            </thead>
            <tbody>
                <?php foreach ($cats as $c) : ?>
                    <tr>
                        <td><?php echo esc_html($c['name']); ?></td>
                        <td><?php echo number_format_i18n($c['qty']); ?></td>
                        <td><?php echo wp_kses_post(wc_price($c['revenue'])); ?></td>
                        <td class="modir-text-muted"><?php echo wp_kses_post(wc_price($c['cost'])); ?></td>
                        <td class="<?php echo $c['profit'] >= 0 ? 'modir-amount-positive' : 'modir-amount-negative'; ?>"><?php echo wp_kses_post(wc_price($c['profit'])); ?></td>
                        <td><span class="modir-stat-badge <?php echo $c['margin'] >= 0 ? 'is-good' : 'is-bad'; ?>"><?php echo number_format_i18n($c['margin'], 1); ?>٪</span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
}
