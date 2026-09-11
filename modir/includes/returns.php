<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «مرجوعی‌ها»: ثبت درخواست عودت سفارش/کالا در پنل کاربری + مدیریت در ادمین
 * ==========================================================================
 */

define('MODIR_RETURNS_ENDPOINT', 'modir-returns');

function modir_returns_init() {
    add_action('init', 'modir_returns_add_endpoint');
    add_action('init', 'modir_returns_maybe_flush_rewrite', 20);

    add_filter('woocommerce_account_menu_items', 'modir_returns_account_menu_items');
    add_filter('woocommerce_endpoint_' . MODIR_RETURNS_ENDPOINT . '_title', 'modir_returns_endpoint_title');
    add_action('woocommerce_account_' . MODIR_RETURNS_ENDPOINT . '_endpoint', 'modir_returns_render_account_content');

    add_action('wp_loaded', 'modir_returns_maybe_handle_submit');
    add_action('wp_enqueue_scripts', 'modir_returns_frontend_assets');

    add_action('admin_init', 'modir_returns_maybe_change_status');
    add_action('admin_init', 'modir_returns_maybe_save_settings');
}

function modir_returns_add_endpoint() {
    add_rewrite_endpoint(MODIR_RETURNS_ENDPOINT, EP_ROOT | EP_PAGES);
}

function modir_returns_maybe_flush_rewrite() {
    if (!get_option('modir_returns_rewrite_flushed')) {
        flush_rewrite_rules();
        update_option('modir_returns_rewrite_flushed', 1);
    }
}

function modir_returns_endpoint_title($title) {
    return 'مرجوعی‌ها';
}

function modir_returns_account_menu_items($items) {
    $settings = modir_returns_get_settings();
    if (empty($settings['enabled'])) {
        return $items;
    }

    $logout = isset($items['customer-logout']) ? $items['customer-logout'] : null;
    if ($logout) {
        unset($items['customer-logout']);
    }
    $items[MODIR_RETURNS_ENDPOINT] = 'مرجوعی‌ها';
    if ($logout) {
        $items['customer-logout'] = $logout;
    }

    return $items;
}

/* -------------------------------------------------------------------- */
/* تنظیمات                                                              */
/* -------------------------------------------------------------------- */

function modir_returns_default_settings() {
    return array(
        'enabled'     => 0,
        'allow_image' => 1,
        'reasons'     => array(
            'کالای معیوب یا آسیب‌دیده',
            'ارسال کالای اشتباه',
            'عدم تطابق با توضیحات محصول',
            'سایز یا رنگ نامناسب',
            'انصراف از خرید',
            'سایر',
        ),
    );
}

function modir_returns_get_settings() {
    return wp_parse_args(get_option('modir_returns_settings', array()), modir_returns_default_settings());
}

function modir_returns_maybe_save_settings() {
    if (empty($_POST['modir_returns_settings_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_returns_settings_nonce'])), 'modir_returns_save_settings')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $defaults = modir_returns_default_settings();

    $reasons_raw = isset($_POST['reasons_text']) ? sanitize_textarea_field(wp_unslash($_POST['reasons_text'])) : '';
    $reasons     = array_values(array_filter(array_map('trim', explode("\n", $reasons_raw))));
    if (empty($reasons)) {
        $reasons = $defaults['reasons'];
    }

    update_option('modir_returns_settings', array(
        'enabled'     => !empty($_POST['enabled']) ? 1 : 0,
        'allow_image' => !empty($_POST['allow_image']) ? 1 : 0,
        'reasons'     => $reasons,
    ));

    wp_safe_redirect(add_query_arg(array('page' => 'modir-marketing', 'tab' => 'returns', 'rtab' => 'settings', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

/* -------------------------------------------------------------------- */
/* داده‌ها                                                               */
/* -------------------------------------------------------------------- */

function modir_returns_get_all() {
    $data = get_option('modir_returns', array());
    return is_array($data) ? $data : array();
}

function modir_returns_status_labels() {
    return array(
        'pending'   => 'در انتظار بررسی',
        'approved'  => 'تایید شده',
        'rejected'  => 'رد شده',
        'completed' => 'تکمیل شده',
    );
}

function modir_returns_status_badge($status) {
    $labels = modir_returns_status_labels();
    $label  = isset($labels[$status]) ? $labels[$status] : $status;
    return '<span class="modir-status-badge modir-badge-' . esc_attr($status) . '">' . esc_html($label) . '</span>';
}

/* -------------------------------------------------------------------- */
/* پردازش ارسال فرم مشتری                                                */
/* -------------------------------------------------------------------- */

function modir_returns_redirect_back($success = null) {
    $url = wc_get_account_endpoint_url(MODIR_RETURNS_ENDPOINT);
    wp_safe_redirect($url);
    exit;
}

function modir_returns_maybe_handle_submit() {
    if (empty($_POST['modir_return_submit']) || empty($_POST['modir_return_nonce'])) {
        return;
    }
    if (!is_user_logged_in()) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_return_nonce'])), 'modir_return_submit')) {
        return;
    }

    $settings = modir_returns_get_settings();
    if (empty($settings['enabled'])) {
        return;
    }

    $user_id  = get_current_user_id();
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $order    = $order_id ? wc_get_order($order_id) : false;

    if (!$order || (int) $order->get_customer_id() !== $user_id) {
        wc_add_notice('سفارش انتخابی معتبر نیست.', 'error');
        modir_returns_redirect_back();
    }

    $item_key  = isset($_POST['item_key']) ? sanitize_text_field(wp_unslash($_POST['item_key'])) : '';
    $item_name = 'کل سفارش';
    if ($item_key) {
        $item = $order->get_item((int) $item_key);
        if ($item) {
            $item_name = $item->get_name();
        } else {
            $item_key = '';
        }
    }

    $reason       = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';
    $description  = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
    $quantity     = isset($_POST['quantity']) ? max(1, absint($_POST['quantity'])) : 1;
    $request_type = isset($_POST['request_type']) && in_array($_POST['request_type'], array('refund', 'replace', 'other'), true)
        ? sanitize_key($_POST['request_type']) : 'refund';

    if (!$reason || !$description) {
        wc_add_notice('لطفاً دلیل و توضیحات درخواست را کامل کنید.', 'error');
        modir_returns_redirect_back();
    }

    $image_url = '';
    if (!empty($settings['allow_image']) && !empty($_FILES['image']['name'])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $upload = wp_handle_upload($_FILES['image'], array('test_form' => false));
        if (!empty($upload['url']) && empty($upload['error'])) {
            $image_url = esc_url_raw($upload['url']);
        }
    }

    $returns = modir_returns_get_all();
    $id      = 'ret_' . uniqid();

    $returns[$id] = array(
        'id'           => $id,
        'order_id'     => $order_id,
        'item_key'     => $item_key,
        'item_name'    => $item_name,
        'customer_id'  => $user_id,
        'reason'       => $reason,
        'description'  => $description,
        'quantity'     => $quantity,
        'request_type' => $request_type,
        'status'       => 'pending',
        'image_url'    => $image_url,
        'created'      => current_time('mysql'),
    );

    update_option('modir_returns', $returns, false);

    wc_add_notice('درخواست مرجوعی شما با موفقیت ثبت شد و به‌زودی بررسی می‌شود.', 'success');
    modir_returns_redirect_back();
}

/* -------------------------------------------------------------------- */
/* رندر محتوای تب «مرجوعی‌ها» در پنل کاربری                                */
/* -------------------------------------------------------------------- */

function modir_returns_frontend_assets() {
    if (!function_exists('is_account_page') || !is_account_page()) {
        return;
    }
    $settings = modir_returns_get_settings();
    if (empty($settings['enabled'])) {
        return;
    }
    wp_enqueue_style('modir-returns-css', MODIR_URL . 'assets/css/returns.css', array(), MODIR_VERSION);
    wp_enqueue_script('modir-returns-js', MODIR_URL . 'assets/js/returns.js', array(), MODIR_VERSION, true);
}

function modir_returns_render_account_content() {
    $settings = modir_returns_get_settings();

    if (empty($settings['enabled'])) {
        echo '<p>این بخش در حال حاضر توسط مدیر فروشگاه فعال نشده است.</p>';
        return;
    }

    $user_id = get_current_user_id();
    $all     = modir_returns_get_all();
    $mine    = array_filter($all, function ($r) use ($user_id) {
        return isset($r['customer_id']) && (int) $r['customer_id'] === $user_id;
    });
    usort($mine, function ($a, $b) {
        return strtotime($b['created']) <=> strtotime($a['created']);
    });

    echo '<div class="modir-returns-wrap">';

    if (!empty($mine)) {
        echo '<h3>درخواست‌های قبلی شما</h3>';
        echo '<table class="woocommerce-table shop_table modir-returns-table"><thead><tr>';
        echo '<th>تاریخ</th><th>سفارش</th><th>مورد</th><th>وضعیت</th>';
        echo '</tr></thead><tbody>';
        foreach ($mine as $r) {
            echo '<tr>';
            echo '<td>' . esc_html(modir_format_jalali($r['created'])) . '</td>';
            echo '<td>#' . esc_html($r['order_id']) . '</td>';
            echo '<td>' . esc_html($r['item_name']) . '</td>';
            echo '<td>' . modir_returns_status_badge($r['status']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '<h3>ثبت درخواست مرجوعی جدید</h3>';
    modir_returns_render_form($user_id, $settings);

    echo '</div>';
}

function modir_returns_render_form($user_id, $settings) {
    $status_keys = array_map(function ($k) {
        return str_replace('wc-', '', $k);
    }, array_keys(wc_get_order_statuses()));

    $orders = wc_get_orders(array(
        'customer_id' => $user_id,
        'type'        => 'shop_order',
        'limit'       => 15,
        'orderby'     => 'date',
        'order'       => 'DESC',
        'status'      => $status_keys,
        'return'      => 'objects',
    ));

    if (empty($orders)) {
        echo '<p>شما هنوز سفارشی برای ثبت درخواست مرجوعی ندارید.</p>';
        return;
    }
    ?>
    <form method="post" enctype="multipart/form-data" class="modir-returns-form">
        <?php wp_nonce_field('modir_return_submit', 'modir_return_nonce'); ?>

        <p class="form-row form-row-wide">
            <label>سفارش مورد نظر</label>
            <select name="order_id" id="modir-return-order" required>
                <option value="">انتخاب کنید...</option>
                <?php foreach ($orders as $order) : ?>
                    <option value="<?php echo esc_attr($order->get_id()); ?>">
                        #<?php echo esc_html($order->get_order_number()); ?> —
                        <?php echo esc_html(modir_format_jalali($order->get_date_created())); ?> —
                        <?php echo wp_strip_all_tags($order->get_formatted_order_total()); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>

        <div id="modir-return-items-wrap">
            <?php foreach ($orders as $order) : ?>
                <div class="modir-return-items-group" data-order="<?php echo esc_attr($order->get_id()); ?>" style="display:none;">
                    <p class="form-row form-row-wide">
                        <label>مورد مرجوعی</label><br>
                        <label class="modir-return-item-option">
                            <input type="radio" name="item_key" value=""> کل سفارش
                        </label><br>
                        <?php foreach ($order->get_items() as $item_id => $item) : ?>
                            <label class="modir-return-item-option">
                                <input type="radio" name="item_key" value="<?php echo esc_attr($item_id); ?>">
                                <?php echo esc_html($item->get_name()); ?> (<?php echo esc_html($item->get_quantity()); ?> عدد)
                            </label><br>
                        <?php endforeach; ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>

        <p class="form-row form-row-first">
            <label>تعداد</label>
            <input type="number" name="quantity" min="1" value="1">
        </p>

        <p class="form-row form-row-last">
            <label>نوع درخواست</label>
            <select name="request_type">
                <option value="refund">بازگشت وجه</option>
                <option value="replace">تعویض کالا</option>
                <option value="other">سایر</option>
            </select>
        </p>

        <p class="form-row form-row-wide">
            <label>دلیل مرجوعی</label>
            <select name="reason" required>
                <option value="">انتخاب کنید...</option>
                <?php foreach ($settings['reasons'] as $reason) : ?>
                    <option value="<?php echo esc_attr($reason); ?>"><?php echo esc_html($reason); ?></option>
                <?php endforeach; ?>
            </select>
        </p>

        <p class="form-row form-row-wide">
            <label>توضیحات</label>
            <textarea name="description" rows="4" required></textarea>
        </p>

        <?php if (!empty($settings['allow_image'])) : ?>
            <p class="form-row form-row-wide">
                <label>تصویر کالا (اختیاری)</label>
                <input type="file" name="image" accept="image/*">
            </p>
        <?php endif; ?>

        <p>
            <button type="submit" name="modir_return_submit" value="1" class="button modir-btn">ثبت درخواست</button>
        </p>
    </form>
    <?php
}

/* ======================================================================
 * صفحه ادمین
 * ====================================================================== */

function modir_returns_maybe_change_status() {
    if (empty($_GET['modir_returns_action']) || 'status' !== $_GET['modir_returns_action']) {
        return;
    }
    if (empty($_GET['id']) || empty($_GET['new_status']) || empty($_GET['_wpnonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'modir_returns_change_status')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $id     = sanitize_text_field(wp_unslash($_GET['id']));
    $status = sanitize_key(wp_unslash($_GET['new_status']));
    if (!array_key_exists($status, modir_returns_status_labels())) {
        return;
    }

    $returns = modir_returns_get_all();
    if (isset($returns[$id])) {
        $returns[$id]['status'] = $status;
        update_option('modir_returns', $returns, false);
    }

    wp_safe_redirect(add_query_arg(array('page' => 'modir-marketing', 'tab' => 'returns', 'rtab' => 'requests'), admin_url('admin.php')));
    exit;
}

function modir_returns_render_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $tabs = array(
        'requests' => 'درخواست‌ها',
        'settings' => 'تنظیمات',
    );
    $tab = isset($_GET['rtab']) ? sanitize_key(wp_unslash($_GET['rtab'])) : 'requests';
    if (!isset($tabs[$tab])) {
        $tab = 'requests';
    }
    ?>
    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>تغییرات با موفقیت ذخیره شد.</p></div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper modir-tabs">
        <?php foreach ($tabs as $key => $label) :
            $url    = add_query_arg(array('page' => 'modir-marketing', 'tab' => 'returns', 'rtab' => $key), admin_url('admin.php'));
            $active = ($tab === $key) ? ' nav-tab-active' : '';
            ?>
            <a href="<?php echo esc_url($url); ?>" class="nav-tab<?php echo esc_attr($active); ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="modir-tab-panel">
        <?php
        if ('settings' === $tab) {
            modir_returns_render_settings_tab();
        } else {
            modir_returns_render_requests_tab();
        }
        ?>
    </div>
    <?php
}

/**
 * برای لینک‌های قدیمی/بوکمارک‌شده: مستقیم صفحه بازاریابی را با تب
 * «مرجوعی‌ها» فعال نمایش می‌دهد، چون این ماژول به آنجا منتقل شده است.
 */
function modir_render_returns_page() {
    $_GET['tab'] = 'returns';
    modir_render_marketing_page();
}

function modir_returns_render_requests_tab() {
    $all = modir_returns_get_all();
    usort($all, function ($a, $b) {
        return strtotime($b['created']) <=> strtotime($a['created']);
    });

    if (empty($all)) {
        echo '<p class="modir-empty">هنوز هیچ درخواست مرجوعی ثبت نشده است.</p>';
        return;
    }

    $labels = modir_returns_status_labels();
    ?>
    <table class="widefat striped modir-table">
        <thead>
            <tr>
                <th>تاریخ</th>
                <th>سفارش</th>
                <th>مشتری</th>
                <th>مورد</th>
                <th>نوع درخواست</th>
                <th>دلیل</th>
                <th>توضیحات</th>
                <th>وضعیت</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($all as $r) :
                $customer   = get_userdata($r['customer_id']);
                $order_url  = admin_url('post.php?post=' . absint($r['order_id']) . '&action=edit');
                $order_edit = function_exists('wc_get_order') ? wc_get_order($r['order_id']) : false;
                if ($order_edit && method_exists($order_edit, 'get_edit_order_url')) {
                    $order_url = $order_edit->get_edit_order_url();
                }
                $type_labels = array('refund' => 'بازگشت وجه', 'replace' => 'تعویض کالا', 'other' => 'سایر');
                ?>
                <tr>
                    <td><?php echo esc_html(modir_format_jalali($r['created'], true)); ?></td>
                    <td><a href="<?php echo esc_url($order_url); ?>" target="_blank">#<?php echo esc_html($r['order_id']); ?></a></td>
                    <td><?php echo esc_html($customer ? $customer->display_name : '—'); ?></td>
                    <td><?php echo esc_html($r['item_name']); ?> <?php echo $r['quantity'] > 1 ? '(' . esc_html($r['quantity']) . ' عدد)' : ''; ?></td>
                    <td><?php echo esc_html($type_labels[$r['request_type']] ?? $r['request_type']); ?></td>
                    <td><?php echo esc_html($r['reason']); ?></td>
                    <td>
                        <?php echo esc_html(mb_substr($r['description'], 0, 60)) . (mb_strlen($r['description']) > 60 ? '…' : ''); ?>
                        <?php if (!empty($r['image_url'])) : ?>
                            <br><a href="<?php echo esc_url($r['image_url']); ?>" target="_blank">مشاهده تصویر</a>
                        <?php endif; ?>
                    </td>
                    <td><?php echo modir_returns_status_badge($r['status']); ?></td>
                    <td>
                        <?php foreach ($labels as $status_key => $status_label) :
                            if ($status_key === $r['status']) continue;
                            $url = wp_nonce_url(add_query_arg(array('page' => 'modir-marketing', 'tab' => 'returns', 'modir_returns_action' => 'status', 'id' => $r['id'], 'new_status' => $status_key), admin_url('admin.php')), 'modir_returns_change_status');
                            ?>
                            <a href="<?php echo esc_url($url); ?>" class="modir-return-action-link"><?php echo esc_html($status_label); ?></a>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

function modir_returns_render_settings_tab() {
    $s = modir_returns_get_settings();
    ?>
    <form method="post">
        <?php wp_nonce_field('modir_returns_save_settings', 'modir_returns_settings_nonce'); ?>
        <table class="form-table">
            <tr>
                <th scope="row">فعال‌سازی ماژول</th>
                <td>
                    <label class="modir-toggle">
                        <input type="checkbox" name="enabled" value="1" <?php checked($s['enabled'], 1); ?>>
                        تب «مرجوعی‌ها» در پنل کاربری (مای‌اکانت) نمایش داده شود
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">آپلود تصویر</th>
                <td>
                    <label class="modir-toggle">
                        <input type="checkbox" name="allow_image" value="1" <?php checked($s['allow_image'], 1); ?>>
                        امکان پیوست تصویر کالا در فرم درخواست فعال باشد
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">لیست دلایل مرجوعی</th>
                <td>
                    <textarea name="reasons_text" rows="6" class="large-text"><?php echo esc_textarea(implode("\n", $s['reasons'])); ?></textarea>
                    <p class="description">هر دلیل در یک خط جداگانه.</p>
                </td>
            </tr>
        </table>
        <?php submit_button('ذخیره تنظیمات', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>
    <?php
}
