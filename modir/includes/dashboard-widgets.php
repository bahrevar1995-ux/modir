<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «ابزارک‌های پیشخوان»: چند ابزارک آماری در Dashboard وردپرس
 * ==========================================================================
 */

function modir_widgets_init() {
    add_action('wp_dashboard_setup', 'modir_widgets_register');
    add_action('admin_enqueue_scripts', 'modir_widgets_admin_assets');
}

function modir_widgets_admin_assets($hook) {
    if ('index.php' !== $hook) {
        return;
    }
    wp_enqueue_style('modir-widgets-css', MODIR_URL . 'assets/css/widgets.css', array(), MODIR_VERSION);
}

/* -------------------------------------------------------------------- */
/* تنظیمات ابزارک‌ها                                                     */
/* -------------------------------------------------------------------- */

function modir_widgets_default_settings() {
    return array(
        'today_sales'        => 1,
        'month_sales'        => 1,
        'processing_orders'  => 1,
        'processing_status'  => 'processing',
        'status_counts'      => 1,
        'status_counts_list' => array(),
        'returns_stats'      => 1,
        'returns_recent'     => 1,
        'chat_support'       => 1,
        'chat_overview'      => 1,
    );
}

function modir_widgets_get_settings() {
    $saved    = get_option('modir_widgets_settings', null);
    $defaults = modir_widgets_default_settings();

    if (null === $saved) {
        // نصب تازه: پیش‌فرض همه وضعیت‌های سفارش انتخاب‌شده باشند
        $defaults['status_counts_list'] = modir_widgets_all_status_keys();
        return $defaults;
    }

    return wp_parse_args($saved, $defaults);
}

function modir_widgets_all_status_keys() {
    return array_map(function ($k) {
        return str_replace('wc-', '', $k);
    }, array_keys(wc_get_order_statuses()));
}

/* -------------------------------------------------------------------- */
/* ثبت ابزارک‌ها                                                         */
/* -------------------------------------------------------------------- */

function modir_widgets_register() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $s = modir_widgets_get_settings();

    if (!empty($s['today_sales'])) {
        wp_add_dashboard_widget('modir_widget_today_sales', '<span class="dashicons dashicons-chart-bar" style="font-size:16px;"></span> فروش امروز', 'modir_widget_render_today_sales');
    }
    if (!empty($s['month_sales'])) {
        wp_add_dashboard_widget('modir_widget_month_sales', '<span class="dashicons dashicons-calendar-alt" style="font-size:16px;"></span> فروش ماه جاری (شمسی)', 'modir_widget_render_month_sales');
    }
    if (!empty($s['processing_orders'])) {
        wp_add_dashboard_widget('modir_widget_processing', '<span class="dashicons dashicons-archive" style="font-size:16px;"></span> سفارش‌های در حال انجام', 'modir_widget_render_processing');
    }
    if (!empty($s['status_counts'])) {
        wp_add_dashboard_widget('modir_widget_status_counts', '<span class="dashicons dashicons-chart-line" style="font-size:16px;"></span> تعداد سفارش‌ها بر اساس وضعیت', 'modir_widget_render_status_counts');
    }
    if (!empty($s['returns_stats'])) {
        wp_add_dashboard_widget('modir_widget_returns_stats', '↩️ آمار مرجوعی‌ها', 'modir_widget_render_returns_stats');
    }
    if (!empty($s['returns_recent'])) {
        wp_add_dashboard_widget('modir_widget_returns_recent', '<span class="dashicons dashicons-backup" style="font-size:16px;"></span> آخرین درخواست‌های مرجوعی', 'modir_widget_render_returns_recent');
    }
    if (!empty($s['chat_support']) && function_exists('modir_chat_entry_id')) {
        wp_add_dashboard_widget('modir_widget_chat_support', '<span class="dashicons dashicons-phone" style="font-size:16px;"></span> درخواست‌های تماس از دستیار گفتگو', 'modir_widget_render_chat_support');
    }
    if (!empty($s['chat_overview']) && function_exists('modir_chat_entry_id')) {
        wp_add_dashboard_widget('modir_widget_chat_overview', '<span class="dashicons dashicons-format-chat" style="font-size:16px;"></span> گزارش کلی دستیار گفتگو', 'modir_widget_render_chat_overview');
    }
}

/* -------------------------------------------------------------------- */
/* ابزارک ۱: فروش امروز                                                  */
/* -------------------------------------------------------------------- */

function modir_widget_render_today_sales() {
    $start = current_time('Y-m-d') . ' 00:00:00';
    $end   = current_time('Y-m-d') . ' 23:59:59';

    $orders = wc_get_orders(array(
        'status'       => modir_get_default_paid_statuses(),
        'type'         => 'shop_order',
        'date_created' => $start . '...' . $end,
        'limit'        => -1,
        'return'       => 'objects',
    ));

    $count = count($orders);
    $sum   = 0;
    foreach ($orders as $o) {
        $sum += (float) $o->get_total();
    }

    list($jy, $jm, $jd) = modir_jalali_today();
    $months = modir_jalali_month_names();
    ?>
    <div class="modir-w-wrap">
        <p class="modir-w-date"><?php echo esc_html(modir_to_persian_digits($jd) . ' ' . $months[$jm] . ' ' . modir_to_persian_digits($jy)); ?></p>
        <div class="modir-w-cards">
            <div class="modir-w-card modir-w-card-indigo">
                <span class="modir-w-card-label">سفارشات امروز</span>
                <span class="modir-w-card-value"><?php echo esc_html(number_format_i18n($count)); ?></span>
            </div>
            <div class="modir-w-card modir-w-card-peach">
                <span class="modir-w-card-label">فروش امروز</span>
                <span class="modir-w-card-value"><?php echo wp_kses_post(wc_price($sum)); ?></span>
            </div>
        </div>
    </div>
    <?php
}

/* -------------------------------------------------------------------- */
/* ابزارک ۲: فروش ماه جاری شمسی                                          */
/* -------------------------------------------------------------------- */

function modir_widget_render_month_sales() {
    list($start, $end, $jy, $jm) = modir_jalali_month_gregorian_range();
    $months = modir_jalali_month_names();

    $orders = wc_get_orders(array(
        'status'       => modir_get_default_paid_statuses(),
        'type'         => 'shop_order',
        'date_created' => $start . '...' . $end,
        'limit'        => -1,
        'return'       => 'objects',
    ));

    $count = count($orders);
    $sum   = 0;
    foreach ($orders as $o) {
        $sum += (float) $o->get_total();
    }
    $avg = $count > 0 ? $sum / $count : 0;
    ?>
    <div class="modir-w-wrap">
        <p class="modir-w-date"><?php echo esc_html($months[$jm] . ' ' . modir_to_persian_digits($jy)); ?></p>
        <div class="modir-w-cards">
            <div class="modir-w-card modir-w-card-indigo">
                <span class="modir-w-card-label">تعداد سفارش</span>
                <span class="modir-w-card-value"><?php echo esc_html(number_format_i18n($count)); ?></span>
            </div>
            <div class="modir-w-card modir-w-card-peach">
                <span class="modir-w-card-label">جمع فروش ماه</span>
                <span class="modir-w-card-value"><?php echo wp_kses_post(wc_price($sum)); ?></span>
            </div>
        </div>
        <p class="modir-w-sub">میانگین هر سفارش: <?php echo wp_kses_post(wc_price($avg)); ?></p>
    </div>
    <?php
}

/* -------------------------------------------------------------------- */
/* ابزارک ۳: سفارش‌های در حال انجام (تصویر + نام + مبلغ)                    */
/* -------------------------------------------------------------------- */

function modir_widget_render_processing() {
    $s      = modir_widgets_get_settings();
    $status = !empty($s['processing_status']) ? $s['processing_status'] : 'processing';

    $orders = wc_get_orders(array(
        'status'  => array($status),
        'type'    => 'shop_order',
        'limit'   => 8,
        'orderby' => 'date',
        'order'   => 'DESC',
        'return'  => 'objects',
    ));

    $all_statuses = wc_get_order_statuses();
    $status_label = isset($all_statuses['wc-' . $status]) ? $all_statuses['wc-' . $status] : $status;

    if (empty($orders)) {
        echo '<div class="modir-w-wrap"><p class="modir-w-empty">در حال حاضر سفارشی در وضعیت «' . esc_html($status_label) . '» وجود ندارد.</p></div>';
        return;
    }
    ?>
    <div class="modir-w-wrap">
        <div class="modir-w-order-list">
            <?php foreach ($orders as $order) :
                $items = $order->get_items();
                $first_item = reset($items);
                $extra_count = count($items) - 1;

                $name  = $first_item ? $first_item->get_name() : '—';
                if ($extra_count > 0) {
                    $name .= ' و ' . $extra_count . ' مورد دیگر';
                }

                $image_html = '';
                if ($first_item) {
                    $product = $first_item->get_product();
                    if ($product) {
                        $image_html = $product->get_image('thumbnail');
                    }
                }
                if (!$image_html) {
                    $image_html = wc_placeholder_img('thumbnail');
                }

                $order_url = method_exists($order, 'get_edit_order_url') ? $order->get_edit_order_url() : admin_url('post.php?post=' . $order->get_id() . '&action=edit');
                ?>
                <a href="<?php echo esc_url($order_url); ?>" class="modir-w-order-row">
                    <span class="modir-w-order-thumb"><?php echo wp_kses_post($image_html); ?></span>
                    <span class="modir-w-order-info">
                        <span class="modir-w-order-number">#<?php echo esc_html($order->get_order_number()); ?></span>
                        <span class="modir-w-order-name"><?php echo esc_html($name); ?></span>
                    </span>
                    <span class="modir-w-order-price"><?php echo wp_kses_post(wc_price($order->get_total())); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

/* -------------------------------------------------------------------- */
/* ابزارک ۴: تعداد سفارش‌ها بر اساس وضعیت (قابل انتخاب)                    */
/* -------------------------------------------------------------------- */

function modir_widget_render_status_counts() {
    $s          = modir_widgets_get_settings();
    $selected   = !empty($s['status_counts_list']) ? $s['status_counts_list'] : modir_widgets_all_status_keys();
    $all_labels = wc_get_order_statuses();

    echo '<div class="modir-w-wrap"><div class="modir-w-status-list">';

    foreach ($selected as $status_key) {
        $label = isset($all_labels['wc-' . $status_key]) ? $all_labels['wc-' . $status_key] : $status_key;

        $orders = wc_get_orders(array(
            'status' => array($status_key),
            'type'   => 'shop_order',
            'limit'  => -1,
            'return' => 'ids',
        ));
        $count = count($orders);

        echo '<div class="modir-w-status-row">';
        echo '<span class="modir-w-status-label">' . esc_html($label) . '</span>';
        echo '<span class="modir-w-status-count">' . esc_html(number_format_i18n($count)) . '</span>';
        echo '</div>';
    }

    echo '</div>';
    echo '<p class="modir-w-sub"><a href="' . esc_url(add_query_arg(array('page' => 'modir-settings'), admin_url('admin.php'))) . '">انتخاب وضعیت‌های نمایشی از تنظیمات افزونه</a></p>';
    echo '</div>';
}

/* -------------------------------------------------------------------- */
/* ابزارک ۵: آمار مرجوعی‌ها                                               */
/* -------------------------------------------------------------------- */

function modir_widget_render_returns_stats() {
    if (!function_exists('modir_returns_get_all')) {
        echo '<p class="modir-w-empty">ماژول مرجوعی‌ها بارگذاری نشده است.</p>';
        return;
    }

    $all    = modir_returns_get_all();
    $labels = modir_returns_status_labels();
    $counts = array_fill_keys(array_keys($labels), 0);

    foreach ($all as $r) {
        if (isset($counts[$r['status']])) {
            $counts[$r['status']]++;
        }
    }

    echo '<div class="modir-w-wrap"><div class="modir-w-status-list">';
    foreach ($labels as $key => $label) {
        echo '<div class="modir-w-status-row">';
        echo '<span class="modir-w-status-label">' . esc_html($label) . '</span>';
        echo '<span class="modir-w-status-count">' . esc_html(number_format_i18n($counts[$key])) . '</span>';
        echo '</div>';
    }
    echo '</div></div>';
}

/* -------------------------------------------------------------------- */
/* ابزارک ۶: آخرین درخواست‌های مرجوعی                                     */
/* -------------------------------------------------------------------- */

function modir_widget_render_returns_recent() {
    if (!function_exists('modir_returns_get_all')) {
        echo '<p class="modir-w-empty">ماژول مرجوعی‌ها بارگذاری نشده است.</p>';
        return;
    }

    $all = modir_returns_get_all();
    usort($all, function ($a, $b) {
        return strtotime($b['created']) <=> strtotime($a['created']);
    });
    $all = array_slice($all, 0, 6);

    if (empty($all)) {
        echo '<div class="modir-w-wrap"><p class="modir-w-empty">هنوز درخواست مرجوعی ثبت نشده است.</p></div>';
        return;
    }

    echo '<div class="modir-w-wrap"><div class="modir-w-returns-list">';
    foreach ($all as $r) {
        $url = add_query_arg(array('page' => 'modir-marketing', 'tab' => 'returns'), admin_url('admin.php'));
        echo '<a href="' . esc_url($url) . '" class="modir-w-returns-row">';
        echo '<span class="modir-w-returns-main">';
        echo '<span class="modir-w-returns-order">#' . esc_html($r['order_id']) . '</span> ';
        echo '<span class="modir-w-returns-item">' . esc_html($r['item_name']) . '</span>';
        echo '</span>';
        echo modir_returns_status_badge($r['status']);
        echo '</a>';
    }
    echo '</div></div>';
}

/* -------------------------------------------------------------------- */
/* ابزارک ۷: درخواست‌های تماس از دستیار گفتگو                              */
/* -------------------------------------------------------------------- */

function modir_widget_render_chat_support() {
    if (!function_exists('modir_chat_entry_id')) {
        echo '<p class="modir-w-empty">ماژول دستیار گفتگو بارگذاری نشده است.</p>';
        return;
    }

    $log = array_reverse(get_option('modir_chat_log', array()));
    $pending = array_values(array_filter($log, function ($r) {
        return !empty($r['needs_support']) && empty($r['resolved']);
    }));

    $log_url = add_query_arg(array('page' => 'modir-marketing', 'tab' => 'chat', 'ctab' => 'log', 'only_support' => '1'), admin_url('admin.php'));

    if (empty($pending)) {
        echo '<div class="modir-w-wrap"><p class="modir-w-empty">فعلاً هیچ مشتری‌ای درخواست تماس نداده 👍</p></div>';
        return;
    }

    echo '<div class="modir-w-wrap"><div class="modir-w-returns-list">';
    foreach (array_slice($pending, 0, 6) as $row) {
        $resolve_url = wp_nonce_url(add_query_arg(array('page' => 'modir-marketing', 'tab' => 'chat', 'ctab' => 'log', 'modir_chat_resolve' => modir_chat_entry_id($row)), admin_url('admin.php')), 'modir_chat_resolve');
        echo '<div class="modir-w-returns-row" style="cursor:default;">';
        echo '<span class="modir-w-returns-main">';
        echo '<span class="modir-w-returns-order">' . esc_html($row['phone'] ?: 'بدون شماره') . '</span> ';
        echo '<span class="modir-w-returns-item">' . esc_html(mb_substr($row['user'], 0, 40)) . '</span>';
        echo '</span>';
        echo '<a href="' . esc_url($resolve_url) . '" class="modir-stat-badge is-good" style="text-decoration:none;">رسیدگی شد</a>';
        echo '</div>';
    }
    echo '</div>';
    echo '<p style="margin:10px 0 0;"><a href="' . esc_url($log_url) . '">مشاهده همه (' . number_format_i18n(count($pending)) . ' مورد) ←</a></p>';
    echo '</div>';
}

/* -------------------------------------------------------------------- */
/* ابزارک ۸: گزارش کلی دستیار گفتگو                                       */
/* -------------------------------------------------------------------- */

function modir_widget_render_chat_overview() {
    if (!function_exists('modir_chat_entry_id')) {
        echo '<p class="modir-w-empty">ماژول دستیار گفتگو بارگذاری نشده است.</p>';
        return;
    }

    $log   = get_option('modir_chat_log', array());
    $leads = get_option('modir_chat_leads', array());

    $today       = current_time('Y-m-d');
    $today_count = 0;
    foreach ($log as $row) {
        if (substr($row['time'], 0, 10) === $today) {
            $today_count++;
        }
    }

    $needs_support_unresolved = count(array_filter($log, function ($r) {
        return !empty($r['needs_support']) && empty($r['resolved']);
    }));
    $leads_new = count(array_filter($leads, function ($l) {
        return 'new' === ($l['status'] ?? 'new');
    }));

    $log_url = add_query_arg(array('page' => 'modir-marketing', 'tab' => 'chat', 'ctab' => 'log'), admin_url('admin.php'));

    $cells = array(
        array('label' => 'مکالمه امروز', 'value' => $today_count, 'danger' => false),
        array('label' => 'کل مکالمه‌ها', 'value' => count($log), 'danger' => false),
        array('label' => 'نیاز به تماس', 'value' => $needs_support_unresolved, 'danger' => $needs_support_unresolved > 0),
        array('label' => 'لید جدید', 'value' => $leads_new, 'danger' => $leads_new > 0),
    );

    echo '<div class="modir-w-wrap">';
    echo '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:14px;">';
    foreach ($cells as $cell) {
        $color = $cell['danger'] ? '#dc2626' : 'inherit';
        echo '<div style="text-align:center;padding:12px 8px;background:rgba(0,0,0,0.03);border-radius:10px;">';
        echo '<div style="font-size:22px;font-weight:700;color:' . esc_attr($color) . ';">' . number_format_i18n($cell['value']) . '</div>';
        echo '<div style="font-size:11px;color:#6b7280;margin-top:2px;">' . esc_html($cell['label']) . '</div>';
        echo '</div>';
    }
    echo '</div>';
    echo '<a href="' . esc_url($log_url) . '" class="button button-primary modir-btn" style="width:100%;text-align:center;display:block;">مشاهده همه گفتگوها</a>';
    echo '</div>';
}
