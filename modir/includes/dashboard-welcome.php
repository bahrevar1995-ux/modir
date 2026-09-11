<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * صفحه «داشبورد»: کارت خوش‌آمدگویی + میان‌برهای رنگی + کارت‌های آماری کامل‌تر
 * + نمودار خطی نرم و سایه‌دار فروش با انتخاب بازه هفتگی/ماهانه/سالانه.
 * ==========================================================================
 */

function modir_dashboard_init() {
    add_action('admin_enqueue_scripts', 'modir_dashboard_admin_assets');
    add_action('wp_ajax_modir_dashboard_chart', 'modir_dashboard_ajax_chart');
}

function modir_dashboard_admin_assets($hook) {
    if (strpos($hook, 'modir') === false) {
        return;
    }
    wp_enqueue_script('modir-dashboard-js', MODIR_URL . 'assets/js/dashboard.js', array('jquery'), MODIR_VERSION, true);
    wp_localize_script('modir-dashboard-js', 'ModirDashboard', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('modir_dashboard_nonce'),
    ));
}

/* -------------------------------------------------------------------- */
/* داده‌های نمودار فروش                                                  */
/* -------------------------------------------------------------------- */

function modir_dashboard_day_sales($day_ymd) {
    $statuses = array_values(array_diff(modir_get_default_paid_statuses(), array('cancelled', 'refunded')));
    $orders   = wc_get_orders(array('status' => $statuses, 'type' => 'shop_order', 'date_created' => $day_ymd . ' 00:00:00...' . $day_ymd . ' 23:59:59', 'limit' => -1, 'return' => 'objects'));
    $sum = 0;
    foreach ($orders as $o) {
        if (is_a($o, 'WC_Order')) {
            $sum += (float) $o->get_total();
        }
    }
    return $sum;
}

/**
 * تاریخ اولین سفارش موفق سایت (برای بازه «از ابتدا»)
 */
function modir_dashboard_first_order_date() {
    $statuses = array_values(array_diff(modir_get_default_paid_statuses(), array('cancelled', 'refunded')));
    $orders   = wc_get_orders(array('status' => $statuses, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'ASC', 'limit' => 1, 'return' => 'objects'));
    if (empty($orders) || !is_a($orders[0], 'WC_Order') || !$orders[0]->get_date_created()) {
        return null;
    }
    return $orders[0]->get_date_created();
}

function modir_dashboard_chart_data($period) {
    $labels = array();
    $values = array();
    $now_ts = current_time('timestamp');

    if ('all' === $period) {
        $first = modir_dashboard_first_order_date();
        if (!$first) {
            return array('labels' => array(), 'values' => array());
        }
        list($start_jy, $start_jm) = modir_gregorian_to_jalali((int) $first->date('Y'), (int) $first->date('n'), (int) $first->date('j'));
        list($cur_jy, $cur_jm)     = modir_jalali_today();
        $months     = modir_jalali_month_names();
        $months_span = (($cur_jy - $start_jy) * 12) + ($cur_jm - $start_jm);
        $months_span = max(0, min(120, $months_span)); // سقف منطقی ۱۰ سال، برای جلوگیری از کوئری بیش‌ازحد

        for ($i = $months_span; $i >= 0; $i--) {
            list($jy, $jm) = modir_shift_jalali_month($cur_jy, $cur_jm, -$i);
            list($start, $end) = modir_pl_month_range($jy, $jm);
            $statuses = array_values(array_diff(modir_get_default_paid_statuses(), array('cancelled', 'refunded')));
            $orders   = wc_get_orders(array('status' => $statuses, 'type' => 'shop_order', 'date_created' => $start . '...' . $end, 'limit' => -1, 'return' => 'objects'));
            $sum = 0;
            foreach ($orders as $o) {
                if (is_a($o, 'WC_Order')) {
                    $sum += (float) $o->get_total();
                }
            }
            $labels[] = $months[$jm] . ' ' . modir_to_persian_digits(substr($jy, -2));
            $values[] = $sum;
        }
    } elseif ('yearly' === $period) {
        list($cur_jy, $cur_jm) = modir_jalali_today();
        $months = modir_jalali_month_names();
        for ($i = 11; $i >= 0; $i--) {
            list($jy, $jm) = modir_shift_jalali_month($cur_jy, $cur_jm, -$i);
            list($start, $end) = modir_pl_month_range($jy, $jm);
            $statuses = array_values(array_diff(modir_get_default_paid_statuses(), array('cancelled', 'refunded')));
            $orders   = wc_get_orders(array('status' => $statuses, 'type' => 'shop_order', 'date_created' => $start . '...' . $end, 'limit' => -1, 'return' => 'objects'));
            $sum = 0;
            foreach ($orders as $o) {
                if (is_a($o, 'WC_Order')) {
                    $sum += (float) $o->get_total();
                }
            }
            $labels[] = $months[$jm];
            $values[] = $sum;
        }
    } else {
        $days = ('monthly' === $period) ? 29 : 6;
        for ($i = $days; $i >= 0; $i--) {
            $day_ts = $now_ts - ($i * DAY_IN_SECONDS);
            $day    = date('Y-m-d', $day_ts);
            list($jy, $jm, $jd) = modir_gregorian_to_jalali((int) date('Y', $day_ts), (int) date('n', $day_ts), (int) date('j', $day_ts));
            $labels[] = modir_to_persian_digits($jd);
            $values[] = modir_dashboard_day_sales($day);
        }
    }

    return array('labels' => $labels, 'values' => $values);
}

function modir_dashboard_ajax_chart() {
    check_ajax_referer('modir_dashboard_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error();
    }
    $period = isset($_POST['period']) ? sanitize_key($_POST['period']) : 'weekly';
    if (!in_array($period, array('weekly', 'monthly', 'yearly', 'all'), true)) {
        $period = 'weekly';
    }
    wp_send_json_success(modir_dashboard_chart_data($period));
}

/* ======================================================================
 * صفحه ادمین
 * ====================================================================== */

function modir_render_dashboard_page() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $today    = current_time('Y-m-d');
    $statuses = array_values(array_diff(modir_get_default_paid_statuses(), array('cancelled', 'refunded')));

    $today_orders = wc_get_orders(array('status' => $statuses, 'type' => 'shop_order', 'date_created' => $today . ' 00:00:00...' . $today . ' 23:59:59', 'limit' => -1, 'return' => 'objects'));
    $today_sales  = 0;
    $today_products = array();
    $all_statuses   = wc_get_order_statuses();
    foreach ($today_orders as $o) {
        if (is_a($o, 'WC_Order')) {
            $today_sales += (float) $o->get_total();
            $status_slug  = $o->get_status();
            $status_label = $all_statuses['wc-' . $status_slug] ?? $status_slug;
            $status_color = function_exists('modir_orders_list_status_color') ? modir_orders_list_status_color($status_slug) : '#9ca3af';

            foreach ($o->get_items('line_item') as $item) {
                $product = $item->get_product();
                $key     = $product ? $product->get_id() : $item->get_name();
                if (!isset($today_products[$key])) {
                    $today_products[$key] = array(
                        'name'  => $item->get_name(),
                        'image' => $product ? $product->get_image(array('width' => 24, 'height' => 24)) : '',
                        'qty'   => 0,
                        'status_label' => $status_label,
                        'status_color' => $status_color,
                    );
                }
                $today_products[$key]['qty'] += (float) $item->get_quantity();
            }
        }
    }

    list($cur_jy, $cur_jm) = modir_jalali_today();
    list($m_start, $m_end) = modir_pl_month_range($cur_jy, $cur_jm);
    $month_orders = wc_get_orders(array('status' => $statuses, 'type' => 'shop_order', 'date_created' => $m_start . '...' . $m_end, 'limit' => -1, 'return' => 'objects'));
    $month_sales  = 0;
    $month_cost   = 0;
    foreach ($month_orders as $o) {
        if (!is_a($o, 'WC_Order')) {
            continue;
        }
        $month_sales += (float) $o->get_total();
        foreach ($o->get_items('line_item') as $item) {
            $product = $item->get_product();
            if ($product) {
                $month_cost += (float) $product->get_meta('_modir_purchase_price') * (float) $item->get_quantity();
            }
        }
    }
    $month_profit = $month_sales - $month_cost;

    $pending_count  = count(wc_get_orders(array('status' => array('pending', 'on-hold'), 'type' => 'shop_order', 'limit' => -1, 'return' => 'ids')));
    $total_products = (int) wp_count_posts('product')->publish;
    $out_of_stock   = count(wc_get_products(array('stock_status' => 'outofstock', 'limit' => -1, 'return' => 'ids')));
    ?>
    <div class="wrap modir-wrap">
        <?php modir_render_top_header('dashboard', 'داشبورد', 'خلاصه وضعیت فروشگاه شما'); ?>
        <?php modir_settings_tabs_nav('modir-dashboard'); ?>

        <div class="modir-tab-panel">
            <div class="modir-welcome-hero modir-today-sales-hero">
                <div class="modir-today-sales-header">
                    <div>
                        <p class="modir-today-sales-title">فروش امروز</p>
                        <p class="modir-today-sales-subtitle"><?php echo number_format_i18n(count($today_products)); ?> کالای مختلف — <?php echo wp_kses_post(wc_price($today_sales)); ?></p>
                    </div>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=modir-orders')); ?>" class="button modir-btn">مشاهده سفارشات</a>
                </div>
                <?php if (empty($today_products)) : ?>
                    <p class="modir-empty" style="margin:0;">هنوز امروز فروشی ثبت نشده است.</p>
                <?php else : ?>
                    <div class="modir-today-sales-list">
                        <?php foreach (array_slice($today_products, 0, 6) as $row) : ?>
                            <div class="modir-today-sales-item">
                                <?php echo wp_kses_post($row['image']); ?>
                                <span class="modir-today-sales-name"><?php echo esc_html($row['name']); ?></span>
                                <span class="modir-today-sales-qty">×<?php echo number_format_i18n($row['qty']); ?></span>
                                <span class="modir-stat-badge" style="background:<?php echo esc_attr($row['status_color']); ?>22;color:<?php echo esc_attr($row['status_color']); ?>;"><?php echo esc_html($row['status_label']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="modir-quick-links">
                <a href="<?php echo esc_url(admin_url('admin.php?page=modir-accounting&tab=rfm')); ?>" class="modir-quick-link">
                    <span class="modir-icon-badge is-yellow"><span class="dashicons dashicons-groups"></span></span>
                    تحلیل مشتریان
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=modir-accounting&tab=sales_alert')); ?>" class="modir-quick-link">
                    <span class="modir-icon-badge is-green"><span class="dashicons dashicons-heart"></span></span>
                    سلامت محصول
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=modir-bulk-edit')); ?>" class="modir-quick-link">
                    <span class="modir-icon-badge is-blue"><span class="dashicons dashicons-edit"></span></span>
                    ویرایش گروهی
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=modir-orders')); ?>" class="modir-quick-link">
                    <span class="modir-icon-badge is-purple"><span class="dashicons dashicons-cart"></span></span>
                    مدیریت سفارشات
                </a>
            </div>

            <div class="modir-summary-cards">
                <div class="modir-card">
                    <span class="modir-card-label">فروش امروز</span>
                    <span class="modir-card-value"><?php echo wp_kses_post(wc_price($today_sales)); ?></span>
                </div>
                <div class="modir-card">
                    <span class="modir-card-label">سفارش‌های امروز</span>
                    <span class="modir-card-value"><?php echo number_format_i18n(count($today_orders)); ?> عدد</span>
                </div>
                <div class="modir-card">
                    <span class="modir-card-label">فروش ماه جاری</span>
                    <span class="modir-card-value"><?php echo wp_kses_post(wc_price($month_sales)); ?></span>
                </div>
                <div class="modir-card <?php echo $month_profit >= 0 ? 'modir-card-success' : 'modir-card-danger'; ?>">
                    <span class="modir-card-label">سود ماه جاری</span>
                    <span class="modir-card-value"><?php echo wp_kses_post(wc_price($month_profit)); ?></span>
                </div>
                <div class="modir-card">
                    <span class="modir-card-label">سفارشات در انتظار پرداخت</span>
                    <span class="modir-card-value"><?php echo number_format_i18n($pending_count); ?> عدد</span>
                </div>
                <div class="modir-card modir-card-danger">
                    <span class="modir-card-label">محصولات ناموجود</span>
                    <span class="modir-card-value"><?php echo number_format_i18n($out_of_stock); ?> عدد</span>
                </div>
                <div class="modir-card">
                    <span class="modir-card-label">کل محصولات</span>
                    <span class="modir-card-value"><?php echo number_format_i18n($total_products); ?> عدد</span>
                </div>
            </div>

            <h2 class="modir-subtitle">روند فروش</h2>
            <div class="modir-view-toggle" id="modir-dash-period-toggle" style="margin-bottom:14px;">
                <button type="button" data-period="weekly" class="is-active">هفتگی</button>
                <button type="button" data-period="monthly">ماهانه</button>
                <button type="button" data-period="yearly">سالانه</button>
                <button type="button" data-period="all">از ابتدا</button>
            </div>
            <div class="modir-chart-wrap">
                <svg id="modir-dash-chart" viewBox="0 0 640 220" preserveAspectRatio="none"></svg>
                <div id="modir-dash-tooltip" class="modir-chart-tooltip" style="display:none;"></div>
            </div>
        </div>
    </div>
    <?php
}
