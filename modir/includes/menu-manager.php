<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «مدیریت منو»: فعال/غیرفعال‌سازی منوهای پیشخوان و نوار بالا،
 * و افزودن آیتم‌های سفارشی با آیکون دلخواه.
 * ==========================================================================
 */

function modir_menu_init() {
    add_action('admin_bar_menu', 'modir_add_toolbar_item', 999);
    add_action('admin_init', 'modir_menu_maybe_save_visibility');
    add_action('admin_init', 'modir_menu_maybe_save_minimal');
    add_action('admin_init', 'modir_menu_maybe_save_custom_item');
    add_action('admin_init', 'modir_menu_maybe_delete_custom_item');

    // اضافه‌کردن آیتم‌های سفارشی سایدبار: زودتر از حذف‌ها، اما بعد از این‌که
    // بقیه افزونه‌ها منوهای خودشان را ثبت کرده‌اند.
    add_action('admin_menu', 'modir_menu_add_custom_sidebar_items', 90);
    // حذف/پنهان‌سازی آیتم‌ها: آخرین اولویت تا مطمئن شویم همه منوها ثبت شده‌اند.
    add_action('admin_menu', 'modir_menu_apply_hidden', PHP_INT_MAX);

    add_action('admin_bar_menu', 'modir_menu_admin_bar_handler', 999);
    add_action('admin_footer', 'modir_menu_custom_link_target_script');
}

/* -------------------------------------------------------------------- */
/* تنظیمات و داده‌ها                                                     */
/* -------------------------------------------------------------------- */

function modir_menu_get_module_settings() {
    return wp_parse_args(get_option('modir_menu_settings', array()), array('enabled' => 0));
}

function modir_menu_minimal_settings() {
    return wp_parse_args(get_option('modir_menu_minimal', array()), array('enabled' => 0, 'keep' => array()));
}

function modir_menu_hidden_admin() {
    $v = get_option('modir_menu_hidden_admin', array());
    return is_array($v) ? $v : array();
}

function modir_menu_hidden_submenu() {
    $v = get_option('modir_menu_hidden_submenu', array());
    return is_array($v) ? $v : array();
}

function modir_menu_hidden_toolbar() {
    $v = get_option('modir_menu_hidden_toolbar', array());
    return is_array($v) ? $v : array();
}

function modir_menu_custom_items() {
    $v = get_option('modir_menu_custom_items', array());
    return is_array($v) ? $v : array();
}

function modir_menu_toolbar_cache() {
    $v = get_option('modir_menu_toolbar_cache', array());
    return is_array($v) ? $v : array();
}

/* -------------------------------------------------------------------- */
/* اعمال پنهان‌سازی منوهای سایدبار                                        */
/* -------------------------------------------------------------------- */

function modir_menu_apply_hidden() {
    $minimal = modir_menu_minimal_settings();

    if (!empty($minimal['enabled'])) {
        global $menu;
        if (is_array($menu)) {
            foreach ($menu as $row) {
                if (empty($row[2])) {
                    continue;
                }
                $slug = $row[2];
                if ('modir' === $slug) {
                    continue; // برای جلوگیری از قفل‌شدن، منوی خود افزونه هرگز پنهان نمی‌شود
                }
                if (in_array($slug, $minimal['keep'], true)) {
                    continue;
                }
                remove_menu_page($slug);
            }
        }
        return; // حالت مینیمال کاملاً مستقل است؛ نیازی به روشن‌بودن سوییچ زیر نیست
    }

    $s = modir_menu_get_module_settings();
    if (empty($s['enabled'])) {
        return;
    }

    foreach (modir_menu_hidden_admin() as $slug) {
        if ('modir' === $slug) {
            continue; // برای جلوگیری از قفل‌شدن، منوی خود افزونه هرگز پنهان نمی‌شود
        }
        remove_menu_page($slug);
    }

    foreach (modir_menu_hidden_submenu() as $pair) {
        $parts = explode('|', $pair, 2);
        if (2 === count($parts) && $parts[0] && $parts[1]) {
            remove_submenu_page($parts[0], $parts[1]);
        }
    }
}

/* -------------------------------------------------------------------- */
/* نوار بالا: کش‌کردن نودهای فعلی + حذف موارد غیرفعال + افزودن آیتم سفارشی */
/* -------------------------------------------------------------------- */

function modir_menu_admin_bar_handler($wp_admin_bar) {
    // کش لیست نودهای فعلی (برای نمایش در صفحه تنظیمات، مستقل از فعال/غیرفعال بودن ماژول)
    $nodes = $wp_admin_bar->get_nodes();
    if ($nodes) {
        $cache = array();
        foreach ($nodes as $node) {
            if (empty($node->id)) {
                continue;
            }
            $title = is_string($node->title) ? trim(wp_strip_all_tags($node->title)) : '';
            $cache[$node->id] = $title ? $title : $node->id;
        }
        update_option('modir_menu_toolbar_cache', $cache, false);
    }

    $s = modir_menu_get_module_settings();
    if (empty($s['enabled'])) {
        return;
    }

    foreach (modir_menu_custom_items() as $item) {
        if ('toolbar' !== $item['location']) {
            continue;
        }

        $meta = array();
        if (!empty($item['new_tab'])) {
            $meta['target'] = '_blank';
            $meta['rel']    = 'noopener';
        }

        $wp_admin_bar->add_node(array(
            'id'    => 'modir_custom_' . $item['id'],
            'title' => modir_menu_render_icon_html($item) . esc_html($item['label']),
            'href'  => $item['url'],
            'meta'  => $meta,
        ));
    }

    foreach (modir_menu_hidden_toolbar() as $node_id) {
        $wp_admin_bar->remove_node($node_id);
    }
}

function modir_menu_render_icon_html($item) {
    $icon = !empty($item['icon']) ? $item['icon'] : '';
    if (!$icon) {
        return '';
    }
    if (0 === strpos($icon, 'dashicons-')) {
        return '<span class="ab-icon dashicons ' . esc_attr($icon) . '" style="margin-top:2px"></span> ';
    }
    return '<img src="' . esc_url($icon) . '" style="width:16px;height:16px;vertical-align:middle;margin-inline-end:4px;border-radius:3px;"> ';
}

/* -------------------------------------------------------------------- */
/* افزودن آیتم‌های سفارشی به سایدبار                                       */
/* -------------------------------------------------------------------- */

function modir_menu_add_custom_sidebar_items() {
    $s = modir_menu_get_module_settings();
    if (empty($s['enabled'])) {
        return;
    }

    global $menu;

    foreach (modir_menu_custom_items() as $item) {
        if ('menu' !== $item['location']) {
            continue;
        }

        $cap  = ('admins' === $item['visibility']) ? 'manage_options' : 'read';
        $icon = !empty($item['icon']) ? $item['icon'] : 'dashicons-admin-links';
        $slug = 'modir_custom_' . $item['id'];

        add_menu_page($item['label'], $item['label'], $cap, $slug, '__return_null', $icon, null);

        // add_menu_page همیشه یک لینک داخلی (admin.php?page=...) می‌سازد؛
        // اینجا آن را با URL دلخواه جایگزین می‌کنیم.
        if (is_array($menu)) {
            foreach ($menu as $key => $row) {
                if (isset($row[2]) && $slug === $row[2]) {
                    $menu[$key][2] = esc_url($item['url']);
                    break;
                }
            }
        }
    }
}

/**
 * چون لینک‌های سایدبار امکان تنظیم target ندارند، برای بازشدن در تب جدید
 * یک اسکریپت کوچک، فقط attribute مربوطه را روی همان لینک مشخص تنظیم می‌کند.
 */
function modir_menu_custom_link_target_script() {
    $s = modir_menu_get_module_settings();
    if (empty($s['enabled'])) {
        return;
    }

    $items = array_values(array_filter(modir_menu_custom_items(), function ($i) {
        return 'menu' === $i['location'] && !empty($i['new_tab']);
    }));

    if (empty($items)) {
        return;
    }
    ?>
    <script>
    (function () {
        var links = <?php echo wp_json_encode(array_map(function ($i) { return $i['url']; }, $items)); ?>;
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('#adminmenu a').forEach(function (a) {
                if (links.indexOf(a.getAttribute('href')) !== -1) {
                    a.setAttribute('target', '_blank');
                    a.setAttribute('rel', 'noopener');
                }
            });
        });
    })();
    </script>
    <?php
}

/* -------------------------------------------------------------------- */
/* ذخیره «حالت مینیمال» (لیست منوهایی که باید بمانند)                       */
/* -------------------------------------------------------------------- */

function modir_menu_maybe_save_minimal() {
    if (empty($_POST['modir_menu_minimal_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_menu_minimal_nonce'])), 'modir_menu_save_minimal')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $keep = isset($_POST['keep']) ? array_map('sanitize_text_field', wp_unslash($_POST['keep'])) : array();

    update_option('modir_menu_minimal', array(
        'enabled' => !empty($_POST['minimal_enabled']) ? 1 : 0,
        'keep'    => array_values($keep),
    ));

    wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'tab' => 'menu', 'mtab' => 'visibility', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

/* -------------------------------------------------------------------- */
/* ذخیره تنظیمات نمایش/عدم‌نمایش (سایدبار + نوار بالا)                      */
/* -------------------------------------------------------------------- */

function modir_menu_maybe_save_visibility() {
    if (empty($_POST['modir_menu_visibility_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_menu_visibility_nonce'])), 'modir_menu_save_visibility')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    update_option('modir_menu_settings', array('enabled' => !empty($_POST['enabled']) ? 1 : 0));

    $all_admin     = isset($_POST['all_admin_slugs']) ? array_map('sanitize_text_field', wp_unslash($_POST['all_admin_slugs'])) : array();
    $visible_admin = isset($_POST['visible_admin']) ? array_map('sanitize_text_field', wp_unslash($_POST['visible_admin'])) : array();
    update_option('modir_menu_hidden_admin', array_values(array_diff($all_admin, $visible_admin)));

    $all_sub     = isset($_POST['all_submenu_pairs']) ? array_map('sanitize_text_field', wp_unslash($_POST['all_submenu_pairs'])) : array();
    $visible_sub = isset($_POST['visible_submenu']) ? array_map('sanitize_text_field', wp_unslash($_POST['visible_submenu'])) : array();
    update_option('modir_menu_hidden_submenu', array_values(array_diff($all_sub, $visible_sub)));

    $all_toolbar     = isset($_POST['all_toolbar_ids']) ? array_map('sanitize_text_field', wp_unslash($_POST['all_toolbar_ids'])) : array();
    $visible_toolbar = isset($_POST['visible_toolbar']) ? array_map('sanitize_text_field', wp_unslash($_POST['visible_toolbar'])) : array();
    update_option('modir_menu_hidden_toolbar', array_values(array_diff($all_toolbar, $visible_toolbar)));

    wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'tab' => 'menu', 'mtab' => 'visibility', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

/* -------------------------------------------------------------------- */
/* ذخیره/حذف آیتم‌های سفارشی                                              */
/* -------------------------------------------------------------------- */

function modir_menu_maybe_save_custom_item() {
    if (empty($_POST['modir_menu_item_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_menu_item_nonce'])), 'modir_menu_save_item')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $items = modir_menu_custom_items();

    $id = isset($_POST['item_id']) ? sanitize_text_field(wp_unslash($_POST['item_id'])) : '';
    if (!$id) {
        $id = 'mi_' . uniqid();
    }

    $label = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '';
    $url   = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';

    if (!$label || !$url) {
        wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'tab' => 'menu', 'mtab' => 'custom', 'error' => '1'), admin_url('admin.php')));
        exit;
    }

    $icon_raw = isset($_POST['icon']) ? trim(wp_unslash($_POST['icon'])) : '';
    if (0 === strpos($icon_raw, 'dashicons-')) {
        $icon = sanitize_html_class($icon_raw);
    } else {
        $icon = esc_url_raw($icon_raw);
    }

    $index          = null;
    foreach ($items as $k => $existing) {
        if ($existing['id'] === $id) {
            $index = $k;
            break;
        }
    }

    $item = array(
        'id'         => $id,
        'label'      => $label,
        'url'        => $url,
        'icon'       => $icon,
        'location'   => (isset($_POST['location']) && 'toolbar' === $_POST['location']) ? 'toolbar' : 'menu',
        'new_tab'    => !empty($_POST['new_tab']) ? 1 : 0,
        'visibility' => (isset($_POST['visibility']) && 'admins' === $_POST['visibility']) ? 'admins' : 'all',
    );

    if (null !== $index) {
        $items[$index] = $item;
    } else {
        $items[] = $item;
    }

    update_option('modir_menu_custom_items', $items);

    wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'tab' => 'menu', 'mtab' => 'custom', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

function modir_menu_maybe_delete_custom_item() {
    if (empty($_GET['modir_menu_action']) || 'delete_item' !== $_GET['modir_menu_action']) {
        return;
    }
    if (empty($_GET['item_id']) || empty($_GET['_wpnonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'modir_menu_delete_item')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $id    = sanitize_text_field(wp_unslash($_GET['item_id']));
    $items = array_values(array_filter(modir_menu_custom_items(), function ($i) use ($id) {
        return $i['id'] !== $id;
    }));
    update_option('modir_menu_custom_items', $items);

    wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'tab' => 'menu', 'mtab' => 'custom', 'deleted' => '1'), admin_url('admin.php')));
    exit;
}

/* ======================================================================
 * صفحه ادمین
 * ====================================================================== */

function modir_menu_render_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $tabs = array(
        'visibility' => 'نمایش منوها',
        'custom'     => 'آیتم‌های سفارشی',
    );
    $tab = isset($_GET['mtab']) ? sanitize_key(wp_unslash($_GET['mtab'])) : 'visibility';
    if (!isset($tabs[$tab])) {
        $tab = 'visibility';
    }
    ?>
    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>تغییرات ذخیره شد.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])) : ?>
        <div class="notice notice-error is-dismissible"><p>لطفاً عنوان و لینک آیتم را کامل وارد کنید.</p></div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper modir-tabs">
        <?php foreach ($tabs as $key => $label) :
            $url    = add_query_arg(array('page' => 'modir-settings', 'tab' => 'menu', 'mtab' => $key), admin_url('admin.php'));
            $active = ($tab === $key) ? ' nav-tab-active' : '';
            ?>
            <a href="<?php echo esc_url($url); ?>" class="nav-tab<?php echo esc_attr($active); ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="modir-tab-panel">
        <?php
        if ('custom' === $tab) {
            modir_menu_render_custom_tab();
        } else {
            modir_menu_render_visibility_tab();
        }
        ?>
    </div>
    <?php
}

/**
 * برای لینک‌های قدیمی/بوکمارک‌شده: مستقیم صفحه تنظیمات را با تب «مدیریت
 * منو» فعال نمایش می‌دهد، چون این ماژول به آنجا منتقل شده است.
 */
function modir_render_menu_page() {
    $_GET['tab'] = 'menu';
    modir_render_settings_page();
}

function modir_menu_render_visibility_tab() {
    $s             = modir_menu_get_module_settings();
    $minimal       = modir_menu_minimal_settings();
    $hidden_admin  = modir_menu_hidden_admin();
    $hidden_sub    = modir_menu_hidden_submenu();
    $hidden_bar    = modir_menu_hidden_toolbar();
    $toolbar_cache = modir_menu_toolbar_cache();

    global $menu, $submenu;
    ?>
    <h2 class="modir-subtitle">حالت مینیمال (سریع)</h2>
    <p class="description">به‌جای تیک‌زدن تک‌تک منوهایی که می‌خوای مخفی بشن، این‌جا فقط همون چندتایی که می‌خوای <strong>بمونن</strong> رو تیک بزن — بقیه خودکار مخفی می‌شن. مناسب وقتی می‌خوای همین تنظیم رو سریع رو چند سایت تکرار کنی.</p>

    <form method="post">
        <?php wp_nonce_field('modir_menu_save_minimal', 'modir_menu_minimal_nonce'); ?>

        <table class="form-table">
            <tr>
                <th scope="row">فعال‌سازی حالت مینیمال</th>
                <td>
                    <label class="modir-toggle">
                        <input type="checkbox" name="minimal_enabled" value="1" <?php checked(!empty($minimal['enabled'])); ?>>
                        فقط منوهای تیک‌خورده زیر نمایش داده شوند؛ بقیه مخفی شوند
                    </label>
                    <p class="description">مستقل از تنظیمات پایین صفحه کار می‌کند؛ فقط همین یک تیک کافی است.</p>
                </td>
            </tr>
        </table>

        <div class="modir-menu-check-list">
            <?php if (is_array($menu)) : ?>
                <?php foreach ($menu as $row) :
                    if (empty($row[2]) || empty($row[0])) {
                        continue;
                    }
                    $slug = $row[2];
                    if ('modir' === $slug) {
                        continue; // همیشه نمایان است، نیازی به انتخاب نیست
                    }
                    $title = trim(wp_strip_all_tags($row[0]));
                    if (!$title) {
                        continue;
                    }
                    ?>
                    <label class="modir-menu-check-parent">
                        <input type="checkbox" name="keep[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $minimal['keep'], true)); ?>>
                        <?php echo esc_html($title); ?>
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php submit_button('ذخیره حالت مینیمال', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>

    <hr style="margin:34px 0;border:none;border-top:1px solid var(--modir-border);">

    <form method="post">
        <?php wp_nonce_field('modir_menu_save_visibility', 'modir_menu_visibility_nonce'); ?>

        <table class="form-table">
            <tr>
                <th scope="row">فعال‌سازی ماژول</th>
                <td>
                    <label class="modir-toggle">
                        <input type="checkbox" name="enabled" value="1" <?php checked(!empty($s['enabled'])); ?>>
                        امکان پنهان‌سازی منوها/نوار بالا و افزودن آیتم سفارشی فعال باشد
                    </label>
                    <p class="description">اگر این تیک خاموش باشد، همه منوها به حالت عادی وردپرس نمایش داده می‌شوند و آیتم‌های سفارشی هم اضافه نمی‌شوند.</p>
                </td>
            </tr>
        </table>

        <h2 class="modir-subtitle">منوی سایدبار پیشخوان</h2>
        <div class="modir-menu-check-list">
            <?php if (is_array($menu)) : ?>
                <?php foreach ($menu as $row) :
                    if (empty($row[2]) || empty($row[0])) {
                        continue;
                    }
                    $slug = $row[2];
                    if ('modir' === $slug) {
                        continue; // منوی خود افزونه همیشه ثابت است
                    }
                    $title = trim(wp_strip_all_tags($row[0]));
                    if (!$title) {
                        continue;
                    }
                    ?>
                    <div class="modir-menu-check-group">
                        <label class="modir-menu-check-parent">
                            <input type="hidden" name="all_admin_slugs[]" value="<?php echo esc_attr($slug); ?>">
                            <input type="checkbox" name="visible_admin[]" value="<?php echo esc_attr($slug); ?>" <?php checked(!in_array($slug, $hidden_admin, true)); ?>>
                            <?php echo esc_html($title); ?>
                        </label>

                        <?php if (!empty($submenu[$slug])) : ?>
                            <div class="modir-menu-check-children">
                                <?php foreach ($submenu[$slug] as $sub_row) :
                                    if (empty($sub_row[2]) || empty($sub_row[0])) {
                                        continue;
                                    }
                                    $sub_slug  = $sub_row[2];
                                    $sub_title = trim(wp_strip_all_tags($sub_row[0]));
                                    if (!$sub_title) {
                                        continue;
                                    }
                                    $pair = $slug . '|' . $sub_slug;
                                    ?>
                                    <label class="modir-menu-check-child">
                                        <input type="hidden" name="all_submenu_pairs[]" value="<?php echo esc_attr($pair); ?>">
                                        <input type="checkbox" name="visible_submenu[]" value="<?php echo esc_attr($pair); ?>" <?php checked(!in_array($pair, $hidden_sub, true)); ?>>
                                        <?php echo esc_html($sub_title); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <h2 class="modir-subtitle">نوار بالای پیشخوان (Toolbar)</h2>
        <?php if (empty($toolbar_cache)) : ?>
            <p class="description">لیست هنوز خالی است. یک‌بار هر صفحه دیگری از پیشخوان را باز کن تا لیست آیتم‌های نوار بالا خودش پر بشه، بعد به این صفحه برگرد.</p>
        <?php else : ?>
            <div class="modir-menu-check-list">
                <?php foreach ($toolbar_cache as $id => $title) : ?>
                    <label class="modir-menu-check-parent">
                        <input type="hidden" name="all_toolbar_ids[]" value="<?php echo esc_attr($id); ?>">
                        <input type="checkbox" name="visible_toolbar[]" value="<?php echo esc_attr($id); ?>" <?php checked(!in_array($id, $hidden_bar, true)); ?>>
                        <?php echo esc_html($title); ?> <code style="font-size:10px;color:#9ca3af;">(<?php echo esc_html($id); ?>)</code>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php submit_button('ذخیره تغییرات', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>
    <?php
}

function modir_menu_render_custom_tab() {
    $action = isset($_GET['modir_menu_form']) ? sanitize_key(wp_unslash($_GET['modir_menu_form'])) : '';

    if ('new' === $action || 'edit' === $action) {
        modir_menu_render_item_form($action);
        return;
    }

    $items    = modir_menu_custom_items();
    $new_url  = add_query_arg(array('page' => 'modir-settings', 'tab' => 'menu', 'mtab' => 'custom', 'modir_menu_form' => 'new'), admin_url('admin.php'));
    $loc_lbl  = array('menu' => 'سایدبار', 'toolbar' => 'نوار بالا');
    $vis_lbl  = array('all' => 'همه کاربران', 'admins' => 'فقط مدیران');
    ?>
    <p><a href="<?php echo esc_url($new_url); ?>" class="button button-primary modir-btn">+ افزودن آیتم جدید</a></p>

    <?php if (empty($items)) : ?>
        <p class="modir-empty">هنوز آیتم سفارشی‌ای اضافه نکرده‌ای.</p>
    <?php else : ?>
        <table class="widefat striped modir-table">
            <thead>
                <tr>
                    <th>آیکون</th>
                    <th>برچسب</th>
                    <th>لینک</th>
                    <th>محل نمایش</th>
                    <th>قابل مشاهده برای</th>
                    <th>تب جدید</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) :
                    $edit_url   = add_query_arg(array('page' => 'modir-settings', 'tab' => 'menu', 'mtab' => 'custom', 'modir_menu_form' => 'edit', 'item_id' => $item['id']), admin_url('admin.php'));
                    $delete_url = wp_nonce_url(add_query_arg(array('page' => 'modir-settings', 'tab' => 'menu', 'modir_menu_action' => 'delete_item', 'item_id' => $item['id']), admin_url('admin.php')), 'modir_menu_delete_item');
                    ?>
                    <tr>
                        <td><?php echo modir_menu_render_icon_html($item); ?></td>
                        <td><?php echo esc_html($item['label']); ?></td>
                        <td><a href="<?php echo esc_url($item['url']); ?>" target="_blank"><?php echo esc_html($item['url']); ?></a></td>
                        <td><?php echo esc_html($loc_lbl[$item['location']] ?? $item['location']); ?></td>
                        <td><?php echo esc_html($vis_lbl[$item['visibility']] ?? $item['visibility']); ?></td>
                        <td><?php echo !empty($item['new_tab']) ? 'بله' : 'خیر'; ?></td>
                        <td>
                            <a href="<?php echo esc_url($edit_url); ?>">ویرایش</a> |
                            <a href="<?php echo esc_url($delete_url); ?>" class="modir-link-danger" onclick="return confirm('حذف شود؟');">حذف</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">این آیتم‌ها فقط وقتی «فعال‌سازی ماژول» در تب «نمایش منوها» روشن باشد نمایش داده می‌شوند.</p>
    <?php endif; ?>
    <?php
}

function modir_menu_render_item_form($action) {
    $item = array('id' => '', 'label' => '', 'url' => '', 'icon' => '', 'location' => 'menu', 'new_tab' => 0, 'visibility' => 'all');

    if ('edit' === $action && !empty($_GET['item_id'])) {
        $id = sanitize_text_field(wp_unslash($_GET['item_id']));
        foreach (modir_menu_custom_items() as $existing) {
            if ($existing['id'] === $id) {
                $item = $existing;
                break;
            }
        }
    }

    $back_url = add_query_arg(array('page' => 'modir-settings', 'tab' => 'menu', 'mtab' => 'custom'), admin_url('admin.php'));
    ?>
    <p><a href="<?php echo esc_url($back_url); ?>">&rarr; بازگشت به لیست</a></p>

    <form method="post">
        <?php wp_nonce_field('modir_menu_save_item', 'modir_menu_item_nonce'); ?>
        <input type="hidden" name="item_id" value="<?php echo esc_attr($item['id']); ?>">

        <table class="form-table">
            <tr>
                <th scope="row">برچسب</th>
                <td><input type="text" name="label" class="regular-text" value="<?php echo esc_attr($item['label']); ?>" required></td>
            </tr>
            <tr>
                <th scope="row">لینک (URL)</th>
                <td><input type="url" name="url" class="regular-text" value="<?php echo esc_attr($item['url']); ?>" placeholder="https://" required></td>
            </tr>
            <tr>
                <th scope="row">آیکون</th>
                <td>
                    <input type="text" name="icon" class="regular-text" value="<?php echo esc_attr($item['icon']); ?>" placeholder="dashicons-star-filled یا آدرس تصویر">
                    <p class="description">نام Dashicon (مثل <code>dashicons-star-filled</code>) یا آدرس تصویر — <a href="https://developer.wordpress.org/resource/dashicons/" target="_blank">لیست آیکون‌ها</a></p>
                </td>
            </tr>
            <tr>
                <th scope="row">محل نمایش</th>
                <td>
                    <label><input type="radio" name="location" value="menu" <?php checked($item['location'], 'menu'); ?>> منوی سایدبار</label>
                    &nbsp;&nbsp;
                    <label><input type="radio" name="location" value="toolbar" <?php checked($item['location'], 'toolbar'); ?>> نوار بالا</label>
                </td>
            </tr>
            <tr>
                <th scope="row">باز شدن در تب جدید</th>
                <td><label class="modir-toggle"><input type="checkbox" name="new_tab" value="1" <?php checked(!empty($item['new_tab'])); ?>> بله</label></td>
            </tr>
            <tr>
                <th scope="row">قابل مشاهده برای</th>
                <td>
                    <label><input type="radio" name="visibility" value="all" <?php checked($item['visibility'], 'all'); ?>> همه کاربران دارای دسترسی پیشخوان</label>
                    &nbsp;&nbsp;
                    <label><input type="radio" name="visibility" value="admins" <?php checked($item['visibility'], 'admins'); ?>> فقط مدیران</label>
                </td>
            </tr>
        </table>

        <?php submit_button('ذخیره آیتم', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>
    <?php
}

/**
 * آیتم نوار بالای وردپرس (تولبار): یه نقطه‌ی رنگی هم‌رنگ افزونه با یه موج،
 * کنار نام کاربر — لینک مستقیم به صفحه اصلی مدیر.
 */
function modir_add_toolbar_item($wp_admin_bar) {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $color = function_exists('modir_get_primary_color') ? modir_get_primary_color() : '#16a34a';
    $icon  = '<svg width="14" height="14" viewBox="0 0 24 24" style="vertical-align:-2px;margin-inline-end:4px;"><circle cx="12" cy="12" r="11" fill="' . esc_attr($color) . '"/><path d="M4.5 12h2.2l1.4-4.3 2.6 8.2 1.4-4.3h4.1" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    $wp_admin_bar->add_node(array(
        'id'    => 'modir-toolbar-item',
        'title' => $icon . 'مدیر',
        'href'  => admin_url('admin.php?page=modir'),
        'meta'  => array('title' => 'مدیر'),
    ));
}
