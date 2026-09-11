<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «حمل و نقل پیشرفته»: تعریف چند روش حمل‌ونقل با قیمت پایه + قیمت هر
 * آیتم + شرط تعداد/وزن/رایگان‌شدن بالای مبلغ خاص. این‌ها به‌عنوان گزینه‌های
 * قابل‌انتخاب واقعی در برگه تسویه‌حساب ووکامرس ظاهر می‌شوند.
 * ==========================================================================
 */

function modir_shipping_init() {
    add_filter('woocommerce_shipping_methods', 'modir_shipping_register_method');

    add_action('admin_init', 'modir_shipping_maybe_save_settings');
    add_action('admin_init', 'modir_shipping_maybe_save_rule');
    add_action('admin_init', 'modir_shipping_maybe_delete_rule');
    add_action('admin_init', 'modir_shipping_maybe_sync_zones');
}

function modir_shipping_register_method($methods) {
    if (!class_exists('Modir_Shipping_Method')) {
        require_once MODIR_PATH . 'includes/class-modir-shipping-method.php';
    }
    $methods['modir_shipping'] = 'Modir_Shipping_Method';
    return $methods;
}

/* -------------------------------------------------------------------- */
/* تنظیمات و قوانین                                                      */
/* -------------------------------------------------------------------- */

function modir_shipping_get_settings() {
    return wp_parse_args(get_option('modir_shipping_settings', array()), array('enabled' => 0));
}

function modir_shipping_get_rules() {
    $rules = get_option('modir_shipping_rules', array());
    return is_array($rules) ? $rules : array();
}

function modir_shipping_new_rule_defaults() {
    return array(
        'id'                  => 'sr_' . uniqid(),
        'label'               => '',
        'enabled'             => 1,
        'base_price'          => 0,
        'per_item_price'      => 0,
        'min_items'           => 0,
        'max_items'           => 0,
        'free_threshold'      => 0,
        'weight_threshold'    => 0,
        'weight_extra_price'  => 0,
        'estimated_days'      => '',
    );
}

/* -------------------------------------------------------------------- */
/* افزودن خودکار روش به مناطق حمل‌ونقل ووکامرس                             */
/* -------------------------------------------------------------------- */

function modir_shipping_sync_all_zones() {
    if (!class_exists('WC_Shipping_Zones')) {
        return 0;
    }

    $added     = 0;
    $zone_ids  = array(0); // «مناطقی که پوشش داده نشده‌اند»
    $all_zones = WC_Shipping_Zones::get_zones();
    foreach ($all_zones as $z) {
        $zone_ids[] = $z['id'];
    }

    foreach ($zone_ids as $zone_id) {
        $zone = new WC_Shipping_Zone($zone_id);
        $has  = false;
        foreach ($zone->get_shipping_methods() as $m) {
            if ('modir_shipping' === $m->id) {
                $has = true;
                break;
            }
        }
        if (!$has) {
            $zone->add_shipping_method('modir_shipping');
            $added++;
        }
    }

    return $added;
}

function modir_shipping_maybe_sync_zones() {
    if (empty($_GET['modir_shipping_action']) || 'sync_zones' !== $_GET['modir_shipping_action']) {
        return;
    }
    if (empty($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'modir_shipping_sync_zones')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $added = modir_shipping_sync_all_zones();

    wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'tab' => 'shipping', 'stab' => 'settings', 'synced' => $added), admin_url('admin.php')));
    exit;
}

/* -------------------------------------------------------------------- */
/* ذخیره تنظیمات                                                        */
/* -------------------------------------------------------------------- */

function modir_shipping_maybe_save_settings() {
    if (empty($_POST['modir_shipping_settings_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_shipping_settings_nonce'])), 'modir_shipping_save_settings')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $was_enabled = !empty(modir_shipping_get_settings()['enabled']);
    $now_enabled = !empty($_POST['enabled']);

    update_option('modir_shipping_settings', array('enabled' => $now_enabled ? 1 : 0));

    // وقتی برای اولین بار فعال می‌شود، خودکار به همه مناطق اضافه شود
    if ($now_enabled && !$was_enabled) {
        modir_shipping_sync_all_zones();
    }

    wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'tab' => 'shipping', 'stab' => 'settings', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

/* -------------------------------------------------------------------- */
/* ذخیره/حذف قانون                                                       */
/* -------------------------------------------------------------------- */

function modir_shipping_maybe_save_rule() {
    if (empty($_POST['modir_shipping_rule_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_shipping_rule_nonce'])), 'modir_shipping_save_rule')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $rules = modir_shipping_get_rules();

    $id = isset($_POST['rule_id']) ? sanitize_text_field(wp_unslash($_POST['rule_id'])) : '';
    if (!$id) {
        $id = 'sr_' . uniqid();
    }

    $label = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '';
    if (!$label) {
        wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'tab' => 'shipping', 'stab' => 'rules', 'error' => '1'), admin_url('admin.php')));
        exit;
    }

    $rule = array(
        'id'                 => $id,
        'label'              => $label,
        'enabled'            => !empty($_POST['enabled']) ? 1 : 0,
        'base_price'         => isset($_POST['base_price']) ? wc_format_decimal(wp_unslash($_POST['base_price'])) : 0,
        'per_item_price'     => isset($_POST['per_item_price']) ? wc_format_decimal(wp_unslash($_POST['per_item_price'])) : 0,
        'min_items'          => isset($_POST['min_items']) ? absint($_POST['min_items']) : 0,
        'max_items'          => isset($_POST['max_items']) ? absint($_POST['max_items']) : 0,
        'free_threshold'     => isset($_POST['free_threshold']) ? wc_format_decimal(wp_unslash($_POST['free_threshold'])) : 0,
        'weight_threshold'   => isset($_POST['weight_threshold']) ? wc_format_decimal(wp_unslash($_POST['weight_threshold'])) : 0,
        'weight_extra_price' => isset($_POST['weight_extra_price']) ? wc_format_decimal(wp_unslash($_POST['weight_extra_price'])) : 0,
        'estimated_days'     => isset($_POST['estimated_days']) ? sanitize_text_field(wp_unslash($_POST['estimated_days'])) : '',
    );

    $index = null;
    foreach ($rules as $k => $existing) {
        if ($existing['id'] === $id) {
            $index = $k;
            break;
        }
    }
    if (null !== $index) {
        $rules[$index] = $rule;
    } else {
        $rules[] = $rule;
    }

    update_option('modir_shipping_rules', $rules);

    wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'tab' => 'shipping', 'stab' => 'rules', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

function modir_shipping_maybe_delete_rule() {
    if (empty($_GET['modir_shipping_action']) || 'delete_rule' !== $_GET['modir_shipping_action']) {
        return;
    }
    if (empty($_GET['rule_id']) || empty($_GET['_wpnonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'modir_shipping_delete_rule')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $id    = sanitize_text_field(wp_unslash($_GET['rule_id']));
    $rules = array_values(array_filter(modir_shipping_get_rules(), function ($r) use ($id) {
        return $r['id'] !== $id;
    }));
    update_option('modir_shipping_rules', $rules);

    wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'tab' => 'shipping', 'stab' => 'rules', 'deleted' => '1'), admin_url('admin.php')));
    exit;
}

/* ======================================================================
 * صفحه ادمین
 * ====================================================================== */

function modir_shipping_render_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $tabs = array(
        'rules'    => 'روش‌های حمل و نقل',
        'settings' => 'تنظیمات',
    );
    $tab = isset($_GET['stab']) ? sanitize_key(wp_unslash($_GET['stab'])) : 'rules';
    if (!isset($tabs[$tab])) {
        $tab = 'rules';
    }
    ?>
    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>ذخیره شد.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])) : ?>
        <div class="notice notice-error is-dismissible"><p>لطفاً عنوان روش را وارد کن.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['synced'])) : ?>
        <div class="notice notice-success is-dismissible"><p>روش حمل‌ونقل مدیر به <?php echo (int) $_GET['synced']; ?> منطقه جدید اضافه شد (مناطقی که از قبل داشتند دست‌نخورده ماندند).</p></div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper modir-tabs">
        <?php foreach ($tabs as $key => $label) :
            $url    = add_query_arg(array('page' => 'modir-settings', 'tab' => 'shipping', 'stab' => $key), admin_url('admin.php'));
            $active = ($tab === $key) ? ' nav-tab-active' : '';
            ?>
            <a href="<?php echo esc_url($url); ?>" class="nav-tab<?php echo esc_attr($active); ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="modir-tab-panel">
        <?php
        if ('settings' === $tab) {
            modir_shipping_render_settings_tab();
        } else {
            modir_shipping_render_rules_tab();
        }
        ?>
    </div>
    <?php
}

/**
 * برای لینک‌های قدیمی/بوکمارک‌شده: مستقیم صفحه تنظیمات را با تب «حمل و نقل
 * پیشرفته» فعال نمایش می‌دهد، چون این ماژول به آنجا منتقل شده است.
 */
function modir_render_shipping_page() {
    $_GET['tab'] = 'shipping';
    modir_render_settings_page();
}

function modir_shipping_render_settings_tab() {
    $s = modir_shipping_get_settings();
    $sync_url = wp_nonce_url(add_query_arg(array('page' => 'modir-settings', 'tab' => 'shipping', 'modir_shipping_action' => 'sync_zones'), admin_url('admin.php')), 'modir_shipping_sync_zones');
    ?>
    <form method="post">
        <?php wp_nonce_field('modir_shipping_save_settings', 'modir_shipping_settings_nonce'); ?>
        <table class="form-table">
            <tr>
                <th scope="row">فعال‌سازی ماژول</th>
                <td>
                    <label class="modir-toggle">
                        <input type="checkbox" name="enabled" value="1" <?php checked(!empty($s['enabled'])); ?>>
                        روش‌های حمل‌ونقل تعریف‌شده در برگه تسویه‌حساب نمایش داده شوند
                    </label>
                    <p class="description">وقتی این تیک برای اولین بار فعال بشه، افزونه خودش «حمل و نقل مدیر» رو به همه مناطق حمل‌ونقل ووکامرس (Shipping Zones) اضافه می‌کنه.</p>
                </td>
            </tr>
        </table>
        <?php submit_button('ذخیره', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>

    <h2 class="modir-subtitle">همگام‌سازی دستی مناطق</h2>
    <p class="description">اگه بعداً یه منطقه حمل‌ونقل جدید تو ووکامرس ساختی، با این دکمه روش «حمل و نقل مدیر» رو بهش اضافه کن.</p>
    <a href="<?php echo esc_url($sync_url); ?>" class="button modir-btn">افزودن به همه مناطق</a>
    <?php
}

function modir_shipping_render_rules_tab() {
    $action = isset($_GET['modir_shipping_form']) ? sanitize_key(wp_unslash($_GET['modir_shipping_form'])) : '';

    if ('new' === $action || 'edit' === $action) {
        modir_shipping_render_rule_form($action);
        return;
    }

    $rules   = modir_shipping_get_rules();
    $new_url = add_query_arg(array('page' => 'modir-settings', 'tab' => 'shipping', 'stab' => 'rules', 'modir_shipping_form' => 'new'), admin_url('admin.php'));
    ?>
    <p><a href="<?php echo esc_url($new_url); ?>" class="button button-primary modir-btn">+ افزودن روش حمل و نقل</a></p>

    <?php if (empty($rules)) : ?>
        <p class="modir-empty">هنوز روشی تعریف نکرده‌ای.</p>
    <?php else : ?>
        <table class="widefat striped modir-table">
            <thead>
                <tr>
                    <th>عنوان</th>
                    <th>قیمت پایه</th>
                    <th>قیمت هر آیتم</th>
                    <th>محدوده تعداد</th>
                    <th>رایگان بالای</th>
                    <th>زمان تخمینی</th>
                    <th>وضعیت</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rules as $rule) :
                    $edit_url   = add_query_arg(array('page' => 'modir-settings', 'tab' => 'shipping', 'stab' => 'rules', 'modir_shipping_form' => 'edit', 'rule_id' => $rule['id']), admin_url('admin.php'));
                    $delete_url = wp_nonce_url(add_query_arg(array('page' => 'modir-settings', 'tab' => 'shipping', 'modir_shipping_action' => 'delete_rule', 'rule_id' => $rule['id']), admin_url('admin.php')), 'modir_shipping_delete_rule');
                    $range      = '';
                    if ($rule['min_items']) {
                        $range .= 'حداقل ' . $rule['min_items'];
                    }
                    if ($rule['max_items']) {
                        $range .= ($range ? ' — ' : '') . 'حداکثر ' . $rule['max_items'];
                    }
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($rule['label']); ?></strong></td>
                        <td><?php echo wp_kses_post(wc_price($rule['base_price'])); ?></td>
                        <td><?php echo wp_kses_post(wc_price($rule['per_item_price'])); ?></td>
                        <td><?php echo esc_html($range ?: '—'); ?></td>
                        <td><?php echo $rule['free_threshold'] ? wp_kses_post(wc_price($rule['free_threshold'])) : '—'; ?></td>
                        <td><?php echo esc_html($rule['estimated_days'] ?: '—'); ?></td>
                        <td>
                            <span class="modir-status-badge <?php echo !empty($rule['enabled']) ? 'is-active' : 'is-inactive'; ?>">
                                <?php echo !empty($rule['enabled']) ? 'فعال' : 'غیرفعال'; ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($edit_url); ?>">ویرایش</a> |
                            <a href="<?php echo esc_url($delete_url); ?>" class="modir-link-danger" onclick="return confirm('حذف شود؟');">حذف</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
}

function modir_shipping_render_rule_form($action) {
    $rule = modir_shipping_new_rule_defaults();

    if ('edit' === $action && !empty($_GET['rule_id'])) {
        $id = sanitize_text_field(wp_unslash($_GET['rule_id']));
        foreach (modir_shipping_get_rules() as $existing) {
            if ($existing['id'] === $id) {
                $rule = $existing;
                break;
            }
        }
    }

    $back_url = add_query_arg(array('page' => 'modir-settings', 'tab' => 'shipping', 'stab' => 'rules'), admin_url('admin.php'));
    ?>
    <p><a href="<?php echo esc_url($back_url); ?>">&rarr; بازگشت به لیست</a></p>

    <form method="post">
        <?php wp_nonce_field('modir_shipping_save_rule', 'modir_shipping_rule_nonce'); ?>
        <input type="hidden" name="rule_id" value="<?php echo esc_attr($rule['id']); ?>">

        <table class="form-table">
            <tr>
                <th scope="row">عنوان روش</th>
                <td><input type="text" name="label" class="regular-text" value="<?php echo esc_attr($rule['label']); ?>" placeholder="مثلاً: پست پیشتاز" required></td>
            </tr>
            <tr>
                <th scope="row">فعال باشد</th>
                <td><label class="modir-toggle"><input type="checkbox" name="enabled" value="1" <?php checked(!empty($rule['enabled'])); ?>> در برگه تسویه‌حساب نمایش داده شود</label></td>
            </tr>
            <tr>
                <th scope="row">قیمت پایه</th>
                <td><input type="number" step="0.01" min="0" name="base_price" value="<?php echo esc_attr($rule['base_price']); ?>" style="width:150px;"></td>
            </tr>
            <tr>
                <th scope="row">قیمت هر آیتم اضافه</th>
                <td>
                    <input type="number" step="0.01" min="0" name="per_item_price" value="<?php echo esc_attr($rule['per_item_price']); ?>" style="width:150px;">
                    <p class="description">هزینه نهایی = قیمت پایه + (این مقدار × تعداد کل آیتم‌های سبد خرید)</p>
                </td>
            </tr>
            <tr>
                <th scope="row">محدوده تعداد آیتم</th>
                <td>
                    <label>حداقل <input type="number" min="0" name="min_items" value="<?php echo esc_attr($rule['min_items']); ?>" style="width:90px;"></label>
                    &nbsp;&nbsp;
                    <label>حداکثر <input type="number" min="0" name="max_items" value="<?php echo esc_attr($rule['max_items']); ?>" style="width:90px;"></label>
                    <p class="description">صفر یعنی بدون محدودیت. این روش فقط وقتی تعداد آیتم‌های سبد در این بازه باشد نمایش داده می‌شود.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">رایگان بالای مبلغ</th>
                <td>
                    <input type="number" step="0.01" min="0" name="free_threshold" value="<?php echo esc_attr($rule['free_threshold']); ?>" style="width:150px;">
                    <p class="description">اگر جمع سبد خرید بالاتر از این مبلغ باشد، هزینه این روش صفر می‌شود. صفر یعنی غیرفعال.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">هزینه اضافه بر اساس وزن</th>
                <td>
                    <label>آستانه وزن (کیلوگرم) <input type="number" step="0.01" min="0" name="weight_threshold" value="<?php echo esc_attr($rule['weight_threshold']); ?>" style="width:120px;"></label>
                    &nbsp;&nbsp;
                    <label>هزینه هر کیلوگرم اضافه <input type="number" step="0.01" min="0" name="weight_extra_price" value="<?php echo esc_attr($rule['weight_extra_price']); ?>" style="width:120px;"></label>
                    <p class="description">اگر وزن کل سبد خرید از آستانه بیشتر شود، به ازای هر کیلوگرم اضافه، این مبلغ به هزینه افزوده می‌شود.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">زمان تحویل تخمینی</th>
                <td><input type="text" name="estimated_days" value="<?php echo esc_attr($rule['estimated_days']); ?>" placeholder="مثلاً: ۲ تا ۳ روز کاری"></td>
            </tr>
        </table>

        <?php submit_button('ذخیره روش', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>
    <?php
}
