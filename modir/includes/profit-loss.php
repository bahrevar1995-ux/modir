<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «سود و زیان»: دفتر ماهانه (شمسی).
 * فروش هر کالا = مبلغ کالا + سهم متناسب از هزینه ارسال آن سفارش (چون این
 * پول واقعاً از مشتری دریافت شده)، و کارمزد درگاه از همین مجموع کسر می‌شود.
 * هزینه خرید کل = (قیمت خرید واحد + هزینه ارسال واحد) × تعداد.
 * ==========================================================================
 */

define('MODIR_PL_DEFAULT_SHIPPING_COST_FALLBACK', 180000);

function modir_pl_init() {
    add_action('admin_init', 'modir_pl_maybe_save');
}

/**
 * قیمت پیش‌فرض ارسال هر واحد کالا (قابل تغییر از تنظیمات عمومی افزونه).
 */
function modir_get_default_shipping_cost() {
    $v = get_option('modir_default_shipping_cost', null);
    return (null === $v || '' === $v) ? MODIR_PL_DEFAULT_SHIPPING_COST_FALLBACK : (float) $v;
}

/* -------------------------------------------------------------------- */
/* داده‌های ذخیره‌شده                                                     */
/* -------------------------------------------------------------------- */

function modir_pl_get_all() {
    $v = get_option('modir_pl_data', array());
    return is_array($v) ? $v : array();
}

function modir_pl_get_month_manual($key) {
    $all = modir_pl_get_all();
    return wp_parse_args(isset($all[$key]) ? $all[$key] : array(), array(
        'products'       => array(),
        'other_expenses' => 0,
    ));
}

function modir_pl_last_known_cost($product_id) {
    $all = modir_pl_get_all();
    krsort($all);
    foreach ($all as $month) {
        if (isset($month['products'][$product_id]['cost'])) {
            return (float) $month['products'][$product_id]['cost'];
        }
    }
    return 0;
}

function modir_pl_last_known_shipping($product_id) {
    $all = modir_pl_get_all();
    krsort($all);
    foreach ($all as $month) {
        if (isset($month['products'][$product_id]['shipping'])) {
            return (float) $month['products'][$product_id]['shipping'];
        }
    }
    return modir_get_default_shipping_cost();
}

/* -------------------------------------------------------------------- */
/* بازه ماه شمسی به میلادی                                               */
/* -------------------------------------------------------------------- */

function modir_pl_month_range($jy, $jm) {
    list($gy1, $gm1, $gd1) = modir_jalali_to_gregorian($jy, $jm, 1);
    $start = sprintf('%04d-%02d-%02d 00:00:00', $gy1, $gm1, $gd1);

    $next_jy = $jy;
    $next_jm = $jm + 1;
    if ($next_jm > 12) {
        $next_jm = 1;
        $next_jy++;
    }
    list($gy2, $gm2, $gd2) = modir_jalali_to_gregorian($next_jy, $next_jm, 1);
    $end = date('Y-m-d 23:59:59', strtotime(sprintf('%04d-%02d-%02d -1 day', $gy2, $gm2, $gd2)));

    return array($start, $end);
}

/* -------------------------------------------------------------------- */
/* جمع‌آوری خودکار فروش/کارمزد از سفارش‌های واقعی                          */
/* -------------------------------------------------------------------- */

function modir_pl_gather_sales($jy, $jm) {
    list($start, $end) = modir_pl_month_range($jy, $jm);

    $statuses = array_values(array_diff(modir_get_default_paid_statuses(), array('cancelled', 'refunded')));

    $orders = wc_get_orders(array(
        'status'       => $statuses,
        'type'         => 'shop_order',
        'date_created' => $start . '...' . $end,
        'limit'        => -1,
        'return'       => 'objects',
    ));

    $products = array();

    foreach ($orders as $order) {
        if (!is_a($order, 'WC_Order')) {
            continue; // رکوردهای مرجوعی (Refund) متدهای درگاه پرداخت ندارند
        }

        $order_shipping = (float) $order->get_shipping_total();
        $gateway_id      = $order->get_payment_method();
        $gateway_title   = $order->get_payment_method_title() ?: ($gateway_id ?: 'نامشخص');
        $commission_cfg  = function_exists('modir_orders_get_commission') ? modir_orders_get_commission($gateway_id) : array('percent' => 0, 'fixed' => 0);

        $line_items = array();
        $items_sum  = 0;
        foreach ($order->get_items('line_item') as $item) {
            $line_total   = (float) $item->get_total();
            $line_items[] = array('item' => $item, 'total' => $line_total);
            $items_sum   += $line_total;
        }

        foreach ($line_items as $li) {
            $item       = $li['item'];
            $line_total = $li['total'];
            $product    = $item->get_product();
            $pid        = $product ? $product->get_id() : 0;
            $key        = $pid ?: 'name-' . $item->get_name();
            $qty        = (float) $item->get_quantity();

            $share          = $items_sum > 0 ? ($line_total / $items_sum) : 0;
            $shipping_share = $share * $order_shipping;
            $combined_sales = $line_total + $shipping_share;

            $line_commission = ($combined_sales * ((float) $commission_cfg['percent']) / 100) + ((float) $commission_cfg['fixed'] * $share);

            if (!isset($products[$key])) {
                $products[$key] = array(
                    'name'       => $item->get_name(),
                    'product_id' => $pid,
                    'qty'        => 0,
                    'sales'      => 0, // فروش کل: کالا + سهم حمل‌ونقل
                    'commission' => 0,
                    'gateways'   => array(),
                );
            }

            $products[$key]['qty']        += $qty;
            $products[$key]['sales']      += $combined_sales;
            $products[$key]['commission'] += $line_commission;

            if (!isset($products[$key]['gateways'][$gateway_title])) {
                $products[$key]['gateways'][$gateway_title] = 0;
            }
            $products[$key]['gateways'][$gateway_title] += $qty;
        }
    }

    uasort($products, function ($a, $b) {
        return $b['sales'] <=> $a['sales'];
    });

    return array('products' => $products, 'orders_count' => count($orders));
}

/* -------------------------------------------------------------------- */
/* ذخیره ورودی‌های دستی                                                  */
/* -------------------------------------------------------------------- */

function modir_pl_maybe_save() {
    if (empty($_POST['modir_pl_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_pl_nonce'])), 'modir_pl_save')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $jy = absint($_POST['jy'] ?? 0);
    $jm = absint($_POST['jm'] ?? 0);
    if (!$jy || !$jm || $jm > 12) {
        return;
    }

    $key = $jy . '-' . str_pad($jm, 2, '0', STR_PAD_LEFT);

    $all   = modir_pl_get_all();
    $month = isset($all[$key]) ? $all[$key] : array();

    $costs     = !empty($_POST['cost']) && is_array($_POST['cost']) ? wp_unslash($_POST['cost']) : array();
    $shippings = !empty($_POST['shipping']) && is_array($_POST['shipping']) ? wp_unslash($_POST['shipping']) : array();

    $products_in = array();
    $all_pids    = array_unique(array_merge(array_keys($costs), array_keys($shippings)));
    foreach ($all_pids as $pid) {
        $pid = sanitize_text_field($pid);
        $products_in[$pid] = array(
            'cost'     => isset($costs[$pid]) ? (float) $costs[$pid] : 0,
            'shipping' => isset($shippings[$pid]) ? (float) $shippings[$pid] : modir_get_default_shipping_cost(),
        );
    }

    $month['products']       = $products_in;
    $month['other_expenses'] = isset($_POST['other_expenses']) ? (float) wp_unslash($_POST['other_expenses']) : 0;

    $all[$key] = $month;
    update_option('modir_pl_data', $all);

    wp_safe_redirect(add_query_arg(array('page' => 'modir-accounting', 'tab' => 'pl', 'jy' => $jy, 'jm' => $jm, 'saved' => '1'), admin_url('admin.php')));
    exit;
}

/* ======================================================================
 * صفحه ادمین
 * ====================================================================== */

function modir_render_pl_page() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    ?>
    <div class="wrap modir-wrap">
        <?php modir_render_top_header('chart-line', 'سود و زیان'); ?>
        <?php modir_settings_tabs_nav('modir-accounting'); ?>
        <div class="modir-tab-panel">
            <?php modir_pl_render_content(); ?>
        </div>
    </div>
    <?php
}

function modir_pl_render_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    list($cur_jy, $cur_jm) = modir_jalali_today();
    $jy = isset($_GET['jy']) ? absint($_GET['jy']) : $cur_jy;
    $jm = isset($_GET['jm']) ? absint($_GET['jm']) : $cur_jm;
    if (!$jy) {
        $jy = $cur_jy;
    }
    if (!$jm || $jm > 12) {
        $jm = $cur_jm;
    }

    $key    = $jy . '-' . str_pad($jm, 2, '0', STR_PAD_LEFT);
    $sales  = modir_pl_gather_sales($jy, $jm);
    $manual = modir_pl_get_month_manual($key);
    $months = modir_jalali_month_names();

    $total_sales      = 0;
    $total_commission = 0;
    $total_cost       = 0;

    foreach ($sales['products'] as $pkey => $row) {
        $pid      = $row['product_id'] ?: $pkey;
        $cost     = isset($manual['products'][$pid]['cost']) ? (float) $manual['products'][$pid]['cost'] : modir_pl_last_known_cost($pid);
        $shipping = isset($manual['products'][$pid]['shipping']) ? (float) $manual['products'][$pid]['shipping'] : modir_pl_last_known_shipping($pid);

        $total_sales      += $row['sales'];
        $total_commission += $row['commission'];
        $total_cost       += ($cost + $shipping) * $row['qty'];
    }

    $other_expenses = (float) $manual['other_expenses'];
    $net_profit     = $total_sales - $total_commission - $total_cost - $other_expenses;

    $history = array_keys(modir_pl_get_all());
    rsort($history);
    ?>
        <?php if (isset($_GET['saved'])) : ?>
            <div class="notice notice-success is-dismissible"><p>اطلاعات این ماه ذخیره شد.</p></div>
        <?php endif; ?>

        <div>
            <form method="get" class="modir-filter-form">
                <input type="hidden" name="page" value="modir-accounting">
                <input type="hidden" name="tab" value="pl">
                <div class="modir-filter-row">
                    <div class="modir-filter-field">
                        <label>سال شمسی</label>
                        <select name="jy">
                            <?php for ($y = $cur_jy - 5; $y <= $cur_jy + 1; $y++) : ?>
                                <option value="<?php echo esc_attr($y); ?>" <?php selected($jy, $y); ?>><?php echo esc_html(modir_to_persian_digits($y)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="modir-filter-field">
                        <label>ماه شمسی</label>
                        <select name="jm">
                            <?php foreach ($months as $num => $mname) : ?>
                                <option value="<?php echo esc_attr($num); ?>" <?php selected($jm, $num); ?>><?php echo esc_html($mname); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="modir-filter-field modir-filter-submit">
                        <button type="submit" class="button button-primary modir-btn">نمایش این ماه</button>
                    </div>
                </div>
            </form>

            <p class="modir-jalali-range">دفتر ماه: <?php echo esc_html($months[$jm] . ' ' . modir_to_persian_digits($jy)); ?> — <?php echo number_format_i18n($sales['orders_count']); ?> سفارش بررسی شد.</p>

            <div class="modir-summary-cards">
                <div class="modir-card">
                    <span class="modir-card-label">جمع فروش (کالا + ارسال)</span>
                    <span class="modir-card-value"><?php echo wp_kses_post(wc_price($total_sales)); ?></span>
                </div>
                <div class="modir-card modir-card-danger">
                    <span class="modir-card-label">کارمزد درگاه‌ها</span>
                    <span class="modir-card-value"><?php echo wp_kses_post(wc_price($total_commission)); ?></span>
                </div>
                <div class="modir-card modir-card-danger">
                    <span class="modir-card-label">هزینه خرید کل (کالا + ارسال)</span>
                    <span class="modir-card-value"><?php echo wp_kses_post(wc_price($total_cost)); ?></span>
                </div>
                <div class="modir-card modir-card-danger">
                    <span class="modir-card-label">سایر هزینه‌ها</span>
                    <span class="modir-card-value"><?php echo wp_kses_post(wc_price($other_expenses)); ?></span>
                </div>
                <div class="modir-card <?php echo $net_profit >= 0 ? 'modir-card-success' : 'modir-card-danger'; ?>">
                    <span class="modir-card-label">سود خالص ماه</span>
                    <span class="modir-card-value"><?php echo wp_kses_post(wc_price($net_profit)); ?></span>
                </div>
            </div>

            <form method="post">
                <?php wp_nonce_field('modir_pl_save', 'modir_pl_nonce'); ?>
                <input type="hidden" name="jy" value="<?php echo esc_attr($jy); ?>">
                <input type="hidden" name="jm" value="<?php echo esc_attr($jm); ?>">

                <?php if (empty($sales['products'])) : ?>
                    <p class="modir-empty">در این ماه فروشی ثبت نشده است.</p>
                <?php else : ?>
                <table class="widefat modir-table modir-product-table">
                    <thead>
                        <tr>
                            <th>کالا</th>
                            <th>تعداد</th>
                            <th>تفکیک درگاه</th>
                            <th>فروش (کالا+ارسال)</th>
                            <th>کارمزد</th>
                            <th>قیمت خرید واحد</th>
                            <th>ارسال (واحد)</th>
                            <th>هزینه خرید کل</th>
                            <th>سود این کالا</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sales['products'] as $pkey => $row) :
                            $pid          = $row['product_id'] ?: $pkey;
                            $cost         = isset($manual['products'][$pid]['cost']) ? (float) $manual['products'][$pid]['cost'] : modir_pl_last_known_cost($pid);
                            $ship_cost    = isset($manual['products'][$pid]['shipping']) ? (float) $manual['products'][$pid]['shipping'] : modir_pl_last_known_shipping($pid);
                            $cost_total   = ($cost + $ship_cost) * $row['qty'];
                            $item_profit  = $row['sales'] - $row['commission'] - $cost_total;
                            $product      = $row['product_id'] ? wc_get_product($row['product_id']) : false;
                            $img          = $product ? $product->get_image(array('width' => 36, 'height' => 36)) : wc_placeholder_img(array('width' => 36, 'height' => 36));
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
                                        <?php foreach ($row['gateways'] as $gw => $gw_qty) : ?>
                                            <span class="modir-gateway-chip">
                                                <span class="modir-gateway-chip-name"><?php echo esc_html($gw); ?></span>
                                                <span class="modir-gateway-chip-qty"><?php echo number_format_i18n($gw_qty); ?></span>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td><?php echo wp_kses_post(wc_price($row['sales'])); ?></td>
                                <td class="modir-text-muted"><?php echo wp_kses_post(wc_price($row['commission'])); ?></td>
                                <td>
                                    <input type="number" step="0.01" min="0" name="cost[<?php echo esc_attr($pid); ?>]" value="<?php echo esc_attr($cost); ?>" style="width:100px;">
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0" name="shipping[<?php echo esc_attr($pid); ?>]" value="<?php echo esc_attr($ship_cost); ?>" style="width:100px;">
                                </td>
                                <td class="modir-text-muted"><?php echo wp_kses_post(wc_price($cost_total)); ?></td>
                                <td class="<?php echo $item_profit >= 0 ? 'modir-amount-positive' : 'modir-amount-negative'; ?>"><?php echo wp_kses_post(wc_price($item_profit)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description">ستون «ارسال (واحد)» هزینه‌ی تخمینی حمل هر واحد از این کالا تا مشتریه (پیش‌فرض ۱۸۰,۰۰۰ تومان)؛ هر وقت خواستی برای هر محصول جدا تغییرش بده.</p>
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">سایر هزینه‌ها</th>
                        <td>
                            <input type="number" step="0.01" min="0" name="other_expenses" value="<?php echo esc_attr($manual['other_expenses']); ?>" style="width:160px;">
                            <p class="description">بسته‌بندی، تبلیغات، یا هر هزینه دیگری برای این ماه که به کالای خاصی مربوط نیست.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('ذخیره اطلاعات این ماه', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
            </form>

            <?php if (!empty($history)) : ?>
                <h2 class="modir-subtitle">تاریخچه ماه‌های ثبت‌شده</h2>
                <div class="modir-pl-history">
                    <?php foreach ($history as $h_key) :
                        $parts = array_map('intval', explode('-', $h_key));
                        $h_jy  = $parts[0] ?? 0;
                        $h_jm  = $parts[1] ?? 0;
                        if (!$h_jy || !$h_jm) {
                            continue;
                        }
                        $url = add_query_arg(array('page' => 'modir-accounting', 'tab' => 'pl', 'jy' => $h_jy, 'jm' => $h_jm), admin_url('admin.php'));
                        ?>
                        <a href="<?php echo esc_url($url); ?>" class="modir-pl-history-item <?php echo ($h_key === $key) ? 'is-current' : ''; ?>"><?php echo esc_html($months[$h_jm] . ' ' . modir_to_persian_digits($h_jy)); ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php
}
