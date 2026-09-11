<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «وضعیت‌های سفارش»: طراحی وضعیت سفارش سفارشی که مستقیم در دراپ‌داون
 * «وضعیت سفارش» صفحه ویرایش سفارش ووکامرس ظاهر می‌شود و قابل انتخاب است.
 * ==========================================================================
 */

define('MODIR_ORDER_STATUS_SLUG_MAXLEN', 17);

function modir_order_statuses_init() {
    add_action('init', 'modir_order_statuses_register', 20);
    add_filter('wc_order_statuses', 'modir_order_statuses_inject');

    add_action('admin_init', 'modir_order_statuses_maybe_save');
    add_action('admin_init', 'modir_order_statuses_maybe_delete');

    add_action('admin_enqueue_scripts', 'modir_order_statuses_admin_css');

    add_filter('bulk_actions-edit-shop_order', 'modir_order_statuses_bulk_actions');
    add_filter('bulk_actions-woocommerce_page_wc-orders', 'modir_order_statuses_bulk_actions');
    add_filter('handle_bulk_actions-edit-shop_order', 'modir_order_statuses_handle_bulk', 10, 3);
    add_filter('handle_bulk_actions-woocommerce_page_wc-orders', 'modir_order_statuses_handle_bulk', 10, 3);
    add_action('admin_notices', 'modir_order_statuses_bulk_notice');
}

/* -------------------------------------------------------------------- */
/* داده‌ها                                                               */
/* -------------------------------------------------------------------- */

function modir_order_statuses_get_all() {
    $v = get_option('modir_custom_order_statuses', array());
    return is_array($v) ? $v : array();
}

/* -------------------------------------------------------------------- */
/* ثبت وضعیت‌ها در وردپرس/ووکامرس                                        */
/* -------------------------------------------------------------------- */

function modir_order_statuses_register() {
    foreach (modir_order_statuses_get_all() as $status) {
        if (empty($status['slug']) || empty($status['label'])) {
            continue;
        }
        register_post_status('wc-' . $status['slug'], array(
            'label'                     => $status['label'],
            'public'                    => false,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                $status['label'] . ' <span class="count">(%s)</span>',
                $status['label'] . ' <span class="count">(%s)</span>'
            ),
        ));
    }
}

function modir_order_statuses_inject($statuses) {
    foreach (modir_order_statuses_get_all() as $status) {
        if (empty($status['slug']) || empty($status['label'])) {
            continue;
        }
        $statuses['wc-' . $status['slug']] = $status['label'];
    }
    return $statuses;
}

/* -------------------------------------------------------------------- */
/* رنگ نشان (badge) وضعیت سفارشی در صفحه سفارش‌ها                          */
/* -------------------------------------------------------------------- */

function modir_order_statuses_admin_css($hook) {
    $is_orders_screen = ('edit.php' === $hook && isset($_GET['post_type']) && 'shop_order' === $_GET['post_type'])
        || 'woocommerce_page_wc-orders' === $hook
        || 'post.php' === $hook
        || 'post-new.php' === $hook;

    if (!$is_orders_screen) {
        return;
    }

    $statuses = modir_order_statuses_get_all();
    if (empty($statuses)) {
        return;
    }

    $css = '';
    foreach ($statuses as $status) {
        if (empty($status['slug']) || empty($status['color'])) {
            continue;
        }
        $slug  = sanitize_html_class($status['slug']);
        $color = sanitize_hex_color($status['color']);
        if (!$color) {
            continue;
        }
        $css .= "mark.order-status.status-{$slug}, mark.order-status.status-{$slug} span { background: {$color} !important; color: #fff !important; }";
    }

    if ($css) {
        wp_register_style('modir-order-statuses-inline', false, array(), MODIR_VERSION);
        wp_enqueue_style('modir-order-statuses-inline');
        wp_add_inline_style('modir-order-statuses-inline', $css);
    }
}

/* -------------------------------------------------------------------- */
/* اقدامات دسته‌جمعی: تغییر وضعیت چند سفارش با هم به یک وضعیت سفارشی        */
/* -------------------------------------------------------------------- */

function modir_order_statuses_bulk_actions($actions) {
    foreach (modir_order_statuses_get_all() as $status) {
        if (empty($status['slug']) || empty($status['label'])) {
            continue;
        }
        $actions['modir_mark_' . $status['slug']] = 'تغییر وضعیت به: ' . $status['label'];
    }
    return $actions;
}

function modir_order_statuses_handle_bulk($redirect_to, $action, $ids) {
    if (0 !== strpos($action, 'modir_mark_') || empty($ids)) {
        return $redirect_to;
    }

    $slug   = substr($action, strlen('modir_mark_'));
    $exists = false;
    foreach (modir_order_statuses_get_all() as $status) {
        if ($status['slug'] === $slug) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        return $redirect_to;
    }

    $count = 0;
    foreach ($ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            continue;
        }
        $order->set_status($slug);
        $order->save();
        $count++;
    }

    return add_query_arg(array('modir_status_bulk_changed' => $count, 'modir_status_bulk_slug' => $slug), $redirect_to);
}

function modir_order_statuses_bulk_notice() {
    if (empty($_GET['modir_status_bulk_changed'])) {
        return;
    }
    $count = (int) $_GET['modir_status_bulk_changed'];
    $slug  = isset($_GET['modir_status_bulk_slug']) ? sanitize_text_field(wp_unslash($_GET['modir_status_bulk_slug'])) : '';

    $label = $slug;
    foreach (modir_order_statuses_get_all() as $status) {
        if ($status['slug'] === $slug) {
            $label = $status['label'];
            break;
        }
    }

    echo '<div class="notice notice-success is-dismissible"><p>' . (int) $count . ' سفارش به وضعیت «' . esc_html($label) . '» تغییر کرد.</p></div>';
}

/* -------------------------------------------------------------------- */
/* ذخیره/حذف وضعیت سفارشی                                                */
/* -------------------------------------------------------------------- */

function modir_order_statuses_maybe_save() {
    if (empty($_POST['modir_order_status_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_order_status_nonce'])), 'modir_order_status_save')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $label = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '';
    if (!$label) {
        wp_safe_redirect(add_query_arg(array('page' => 'modir-orders', 'tab' => 'statuses', 'error' => 'label'), admin_url('admin.php')));
        exit;
    }

    $id = isset($_POST['status_id']) ? sanitize_text_field(wp_unslash($_POST['status_id'])) : '';

    $slug_input = isset($_POST['slug']) && $_POST['slug'] ? sanitize_title(wp_unslash($_POST['slug'])) : sanitize_title($label);
    $slug       = substr($slug_input, 0, MODIR_ORDER_STATUS_SLUG_MAXLEN);
    if (!$slug) {
        wp_safe_redirect(add_query_arg(array('page' => 'modir-orders', 'tab' => 'statuses', 'error' => 'slug'), admin_url('admin.php')));
        exit;
    }

    $color = !empty($_POST['color']) ? sanitize_hex_color(wp_unslash($_POST['color'])) : '#6b7280';
    if (!$color) {
        $color = '#6b7280';
    }

    $statuses = modir_order_statuses_get_all();

    $default_slugs = array('pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft', 'trash');
    if (in_array($slug, $default_slugs, true)) {
        wp_safe_redirect(add_query_arg(array('page' => 'modir-orders', 'tab' => 'statuses', 'error' => 'reserved'), admin_url('admin.php')));
        exit;
    }
    foreach ($statuses as $existing) {
        if ($existing['slug'] === $slug && $existing['id'] !== $id) {
            wp_safe_redirect(add_query_arg(array('page' => 'modir-orders', 'tab' => 'statuses', 'error' => 'duplicate'), admin_url('admin.php')));
            exit;
        }
    }

    if (!$id) {
        $id = 'os_' . uniqid();
    }

    $status_data = array('id' => $id, 'label' => $label, 'slug' => $slug, 'color' => $color);

    $index = null;
    foreach ($statuses as $k => $existing) {
        if ($existing['id'] === $id) {
            $index = $k;
            break;
        }
    }
    if (null !== $index) {
        $statuses[$index] = $status_data;
    } else {
        $statuses[] = $status_data;
    }

    update_option('modir_custom_order_statuses', $statuses);

    wp_safe_redirect(add_query_arg(array('page' => 'modir-orders', 'tab' => 'statuses', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

function modir_order_statuses_maybe_delete() {
    if (empty($_GET['modir_order_status_action']) || 'delete' !== $_GET['modir_order_status_action']) {
        return;
    }
    if (empty($_GET['status_id']) || empty($_GET['_wpnonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'modir_order_status_delete')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $id       = sanitize_text_field(wp_unslash($_GET['status_id']));
    $statuses = array_values(array_filter(modir_order_statuses_get_all(), function ($s) use ($id) {
        return $s['id'] !== $id;
    }));
    update_option('modir_custom_order_statuses', $statuses);

    wp_safe_redirect(add_query_arg(array('page' => 'modir-orders', 'tab' => 'statuses', 'deleted' => '1'), admin_url('admin.php')));
    exit;
}

/* ======================================================================
 * صفحه ادمین
 * ====================================================================== */

function modir_order_statuses_render_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $action = isset($_GET['modir_status_form']) ? sanitize_key(wp_unslash($_GET['modir_status_form'])) : '';
    $error  = isset($_GET['error']) ? sanitize_key(wp_unslash($_GET['error'])) : '';
    ?>
    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>وضعیت با موفقیت ذخیره شد و از همین حالا در فرم ویرایش سفارش قابل انتخاب است.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])) : ?>
        <div class="notice notice-success is-dismissible"><p>وضعیت حذف شد.</p></div>
    <?php endif; ?>
    <?php if ('label' === $error) : ?>
        <div class="notice notice-error is-dismissible"><p>عنوان وضعیت را وارد کن.</p></div>
    <?php endif; ?>
    <?php if ('slug' === $error) : ?>
        <div class="notice notice-error is-dismissible"><p>اسلاگ نامعتبر است.</p></div>
    <?php endif; ?>
    <?php if ('reserved' === $error) : ?>
        <div class="notice notice-error is-dismissible"><p>این اسلاگ مخصوص وضعیت‌های استاندارد ووکامرس است؛ اسلاگ دیگری انتخاب کن.</p></div>
    <?php endif; ?>
    <?php if ('duplicate' === $error) : ?>
        <div class="notice notice-error is-dismissible"><p>وضعیت دیگری با همین اسلاگ از قبل وجود دارد.</p></div>
    <?php endif; ?>

    <?php
    if ('new' === $action || 'edit' === $action) {
        modir_order_statuses_render_form($action);
    } else {
        modir_order_statuses_render_list();
    }
    ?>
    <?php
}

/**
 * برای لینک‌های قدیمی/بوکمارک‌شده: مستقیم صفحه سفارشات را با تب «وضعیت‌های
 * سفارش» فعال نمایش می‌دهد، چون این ماژول به آنجا منتقل شده است.
 */
function modir_render_order_statuses_page() {
    $_GET['tab'] = 'statuses';
    modir_render_orders_list_page();
}

function modir_order_statuses_render_list() {
    $statuses = modir_order_statuses_get_all();
    $new_url  = add_query_arg(array('page' => 'modir-orders', 'tab' => 'statuses', 'modir_status_form' => 'new'), admin_url('admin.php'));
    ?>
    <p class="description">وضعیت‌هایی که اینجا بسازی، مستقیماً در دراپ‌داون «وضعیت سفارش» صفحه ویرایش هر سفارش اضافه می‌شن و می‌تونی سفارش‌هات رو روشون قرار بدی — دقیقاً مثل وضعیت‌های پیش‌فرض ووکامرس.</p>
    <p><a href="<?php echo esc_url($new_url); ?>" class="button button-primary modir-btn">+ افزودن وضعیت جدید</a></p>

    <?php if (empty($statuses)) : ?>
        <p class="modir-empty">هنوز وضعیت سفارشی‌ای نساخته‌ای.</p>
    <?php else : ?>
        <table class="widefat striped modir-table">
            <thead>
                <tr>
                    <th>نمونه نشان</th>
                    <th>عنوان</th>
                    <th>اسلاگ (wc-...)</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statuses as $status) :
                    $edit_url   = add_query_arg(array('page' => 'modir-orders', 'tab' => 'statuses', 'modir_status_form' => 'edit', 'status_id' => $status['id']), admin_url('admin.php'));
                    $delete_url = wp_nonce_url(add_query_arg(array('page' => 'modir-orders', 'tab' => 'statuses', 'modir_order_status_action' => 'delete', 'status_id' => $status['id']), admin_url('admin.php')), 'modir_order_status_delete');
                    ?>
                    <tr>
                        <td><span class="modir-status-sample" style="background:<?php echo esc_attr($status['color']); ?>;"><?php echo esc_html($status['label']); ?></span></td>
                        <td><?php echo esc_html($status['label']); ?></td>
                        <td><code>wc-<?php echo esc_html($status['slug']); ?></code></td>
                        <td>
                            <a href="<?php echo esc_url($edit_url); ?>">ویرایش</a> |
                            <a href="<?php echo esc_url($delete_url); ?>" class="modir-link-danger" onclick="return confirm('حذف شود؟ سفارش‌هایی که الان روی این وضعیت هستند حذف نمی‌شوند ولی برچسبشان دیگر در لیست‌ها به‌درستی نمایش داده نمی‌شود.');">حذف</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">بعد از افزودن یا حذف یک وضعیت، بهتره یک‌بار صفحه سفارش‌ها رو رفرش کنی تا لیست کامل به‌روز بشه.</p>
    <?php endif; ?>
    <?php
}

function modir_order_statuses_render_form($action) {
    $status = array('id' => '', 'label' => '', 'slug' => '', 'color' => '#6b7280');

    if ('edit' === $action && !empty($_GET['status_id'])) {
        $id = sanitize_text_field(wp_unslash($_GET['status_id']));
        foreach (modir_order_statuses_get_all() as $existing) {
            if ($existing['id'] === $id) {
                $status = $existing;
                break;
            }
        }
    }

    $back_url = add_query_arg(array('page' => 'modir-orders', 'tab' => 'statuses'), admin_url('admin.php'));
    ?>
    <p><a href="<?php echo esc_url($back_url); ?>">&rarr; بازگشت به لیست</a></p>

    <form method="post">
        <?php wp_nonce_field('modir_order_status_save', 'modir_order_status_nonce'); ?>
        <input type="hidden" name="status_id" value="<?php echo esc_attr($status['id']); ?>">

        <table class="form-table">
            <tr>
                <th scope="row">عنوان وضعیت</th>
                <td><input type="text" name="label" class="regular-text" value="<?php echo esc_attr($status['label']); ?>" placeholder="مثلاً: در حال بسته‌بندی" required></td>
            </tr>
            <tr>
                <th scope="row">اسلاگ (انگلیسی، اختیاری)</th>
                <td>
                    <input type="text" name="slug" class="regular-text" value="<?php echo esc_attr($status['slug']); ?>" placeholder="خالی بگذار تا خودکار از عنوان ساخته شود" maxlength="<?php echo (int) MODIR_ORDER_STATUS_SLUG_MAXLEN; ?>">
                    <p class="description">فقط حروف انگلیسی، عدد و خط‌تیره؛ حداکثر <?php echo (int) MODIR_ORDER_STATUS_SLUG_MAXLEN; ?> کاراکتر (محدودیت فنی وردپرس).</p>
                </td>
            </tr>
            <tr>
                <th scope="row">رنگ نشان</th>
                <td><input type="text" name="color" value="<?php echo esc_attr($status['color']); ?>" class="modir-color-picker" data-default-color="#6b7280"></td>
            </tr>
        </table>

        <?php submit_button('ذخیره وضعیت', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>
    <?php
}
