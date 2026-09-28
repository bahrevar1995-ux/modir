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

    /* ---- داده‌های کارت «کارهای امروز شما» (طرح رابط کاربری جدید) ---- */
    $needs_check_count = count(wc_get_products(array(
        'meta_query' => array(array('key' => '_modir_needs_check', 'value' => '1', 'compare' => '=')),
        'limit'      => -1,
        'return'     => 'ids',
    )));

    $unresolved_support = 0;
    foreach (get_option('modir_chat_log', array()) as $row) {
        if (!empty($row['needs_support']) && empty($row['resolved'])) {
            $unresolved_support++;
        }
    }

    $new_leads_count = 0;
    foreach (get_option('modir_chat_leads', array()) as $lead) {
        if ('new' === ($lead['status'] ?? '')) {
            $new_leads_count++;
        }
    }

    $tasks = array(
        array(
            'text'  => $unresolved_support > 0
                ? 'پاسخ به ' . number_format_i18n($unresolved_support) . ' گفتگوی پشتیبانی در انتظار'
                : 'هیچ گفتگوی پشتیبانی در انتظار پاسخ نیست',
            'done'  => ($unresolved_support === 0),
            'flag'  => $unresolved_support > 0 ? 'فوری' : '',
            'flag_class' => 'is-urgent',
            'roles' => 'seller manager',
            'url'   => admin_url('admin.php?page=modir-marketing&tab=chat&ctab=log'),
        ),
        array(
            'text'  => $pending_count > 0
                ? number_format_i18n($pending_count) . ' سفارش جدید نیاز به تأیید و آماده‌سازی دارند'
                : 'سفارش در انتظار تأیید وجود ندارد',
            'done'  => ($pending_count === 0),
            'flag'  => $pending_count > 0 ? 'فوری' : '',
            'flag_class' => 'is-urgent',
            'roles' => 'seller warehouse manager',
            'url'   => admin_url('admin.php?page=modir-orders'),
        ),
        array(
            'text'  => $needs_check_count > 0
                ? number_format_i18n($needs_check_count) . ' محصول با پرچم «چک شود» قبل از ارسال'
                : 'محصولی با پرچم «چک شود» باقی نمانده',
            'done'  => ($needs_check_count === 0),
            'flag'  => $needs_check_count > 0 ? 'بررسی‌نشده' : '',
            'flag_class' => 'is-warn',
            'roles' => 'warehouse manager',
            'url'   => admin_url('admin.php?page=modir&needs_check=yes'),
        ),
        array(
            'text'  => $new_leads_count > 0
                ? number_format_i18n($new_leads_count) . ' لید جدید از دستیار گفتگو نیاز به تماس دارند'
                : 'لید جدید بدون تماس وجود ندارد',
            'done'  => ($new_leads_count === 0),
            'flag'  => $new_leads_count > 0 ? 'جدید' : '',
            'flag_class' => 'is-new',
            'roles' => 'seller manager',
            'url'   => admin_url('admin.php?page=modir-marketing&tab=chat&ctab=leads'),
        ),
    );
    $tasks_done_count = count(array_filter($tasks, function ($t) { return $t['done']; }));

    $pending_orders = wc_get_orders(array(
        'status'  => array('pending', 'on-hold', 'processing'),
        'type'    => 'shop_order',
        'orderby' => 'date',
        'order'   => 'DESC',
        'limit'   => 5,
        'return'  => 'objects',
    ));
    $all_statuses = wc_get_order_statuses();
    ?>
    <div class="wrap modir-wrap">
        <?php modir_render_top_header('dashboard', 'داشبورد', 'خلاصه وضعیت فروشگاه شما'); ?>
        <?php modir_settings_tabs_nav('modir-dashboard'); ?>

        <div class="modir-tab-panel">

            <div class="modir-role-switch" id="modir-role-switch">
                <button type="button" class="is-active" data-role="seller">فروشنده</button>
                <button type="button" data-role="warehouse">انباردار</button>
                <button type="button" data-role="manager">مدیر فروش</button>
            </div>

            <div class="modir-dash-grid">
                <div class="modir-dash-main">

                    <div class="modir-task-card">
                        <div class="modir-task-card-head">
                            <span>کارهای امروز شما</span>
                            <span class="modir-task-progress"><?php echo number_format_i18n($tasks_done_count); ?> از <?php echo number_format_i18n(count($tasks)); ?> انجام‌شده</span>
                        </div>
                        <ul class="modir-task-list">
                            <?php foreach ($tasks as $task) : ?>
                                <li class="modir-task-item<?php echo $task['done'] ? ' is-done' : ''; ?>" data-role="<?php echo esc_attr($task['roles']); ?>">
                                    <a href="<?php echo esc_url($task['url']); ?>" class="modir-task-check" aria-hidden="true"><span class="dashicons dashicons-yes"></span></a>
                                    <a href="<?php echo esc_url($task['url']); ?>" class="modir-task-text"><?php echo esc_html($task['text']); ?></a>
                                    <?php if ($task['flag']) : ?>
                                        <span class="modir-task-flag <?php echo esc_attr($task['flag_class']); ?>"><?php echo esc_html($task['flag']); ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="modir-task-card">
                        <div class="modir-task-card-head"><span>سفارشات در انتظار شما</span></div>
                        <?php if (empty($pending_orders)) : ?>
                            <p class="modir-empty" style="margin:0;">سفارش در انتظاری وجود ندارد.</p>
                        <?php else : ?>
                            <div class="modir-pending-orders-list">
                                <?php foreach ($pending_orders as $o) :
                                    if (!is_a($o, 'WC_Order')) {
                                        continue;
                                    }
                                    $status_slug  = $o->get_status();
                                    $status_label = $all_statuses['wc-' . $status_slug] ?? $status_slug;
                                    $status_color = function_exists('modir_orders_list_status_color') ? modir_orders_list_status_color($status_slug) : '#9ca3af';
                                    $order_url    = method_exists($o, 'get_edit_order_url') ? $o->get_edit_order_url() : admin_url('post.php?post=' . $o->get_id() . '&action=edit');
                                    $name         = trim($o->get_formatted_billing_full_name());
                                    $item_count   = count($o->get_items());
                                    ?>
                                    <a href="<?php echo esc_url($order_url); ?>" class="modir-pending-order-row">
                                        <span class="modir-po-num">سفارش #<?php echo esc_html($o->get_order_number()); ?></span>
                                        <span class="modir-po-meta"><?php echo esc_html($name ?: 'مهمان'); ?> · <?php echo number_format_i18n($item_count); ?> قلم کالا</span>
                                        <span class="modir-po-badge" style="background:<?php echo esc_attr($status_color); ?>22;color:<?php echo esc_attr($status_color); ?>;"><?php echo esc_html($status_label); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

                <div class="modir-dash-side">
                    <div class="modir-side-card modir-side-card-primary">
                        <div class="modir-side-label">فروش امروز</div>
                        <div class="modir-side-value"><?php echo wp_kses_post(wc_price($today_sales)); ?></div>
                        <div class="modir-side-foot"><?php echo number_format_i18n(count($today_orders)); ?> سفارش · <?php echo number_format_i18n(count($today_products)); ?> کالای مختلف</div>
                    </div>

                    <div class="modir-side-card">
                        <div class="modir-side-label">دستیار گفتگو</div>
                        <div class="modir-side-sub">گفتگوهای باز نیازمند پشتیبانی انسانی</div>
                        <div class="modir-side-big<?php echo $unresolved_support > 0 ? ' is-danger' : ''; ?>"><?php echo number_format_i18n($unresolved_support); ?></div>
                    </div>

                    <div class="modir-side-card">
                        <div class="modir-side-label">میانبرها</div>
                        <div class="modir-side-shortcuts">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=modir&tab=import')); ?>">+ افزودن محصول</a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=modir-label')); ?>">چاپ لیبل سفارشات</a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=modir-marketing&tab=chat&ctab=log')); ?>">مشاهده گفتگوها</a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=modir-bulk-edit')); ?>">ویرایش گروهی</a>
                        </div>
                    </div>
                </div>
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
