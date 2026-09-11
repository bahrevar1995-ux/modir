<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «عیب‌یابی»: بدون نیاز به دسترسی فایل سرور یا wp-config.php.
 * ۱) وقتی «حالت عیب‌یابی» روشن باشد، هر خطای فاتال واقعی PHP (در هر صفحه‌ای
 *    از سایت) گرفته و در یک گزینه ذخیره می‌شود تا همین‌جا دیده شود.
 * ۲) دکمه «اجرای تست فوری» مستقیم و بدون نیاز به رفتن به صفحه دیگر، همان
 *    هوک‌های حساس صفحات محصولات/سفارشات ووکامرس را با try/catch اجرا
 *    می‌کند و هر خطایی را بلافاصله نشان می‌دهد.
 * ==========================================================================
 */

/**
 * لیست ماژول‌هایی که می‌توان از تنظیمات ← عیب‌یابی، بدون نیاز به دسترسی
 * فایل سرور، موقتاً خاموش کرد تا مشخص شود کدام ماژول باعث یک مشکل خاص
 * می‌شود. کلید‌ها دقیقاً همان‌هایی هستند که در modir.php چک می‌شوند.
 */
function modir_diag_module_list() {
    return array(
        'orders_columns'    => 'ستون‌ها و فیلترهای لیست سفارشات ووکامرس',
        'label'             => 'لیبل آدرس (دکمه‌ها/اکشن‌های دسته‌جمعی روی لیست سفارشات)',
        'order_statuses'    => 'وضعیت‌های سفارش سفارشی',
        'tracking'          => 'پیگیری سفارش',
        'menu'              => 'مدیریت منو (پنهان‌سازی آیتم‌ها + آیتم نوار بالا)',
        'shipping'          => 'حمل و نقل پیشرفته',
        'notifbar'          => 'نوار نوتیف (فرانت سایت)',
        'popup'             => 'پاپ‌آپ محصول (فرانت سایت)',
        'chat'              => 'دستیار گفتگو (فرانت سایت)',
        'returns'           => 'مرجوعی‌ها (فرانت سایت)',
        'vpn'               => 'تشخیص VPN (فرانت سایت)',
        'dashboard_widgets' => 'ابزارک‌های داشبورد وردپرس',
        'import'            => 'افزودن محصول از لینک',
        'bulk_edit'         => 'ویرایش دسته‌جمعی',
        'pl'                => 'سود و زیان',
        'warehouse'         => 'انبار',
        'expenses'          => 'هزینه کرد',
        'products'          => 'صفحه محصولات مدیر',
        'price_calc'        => 'ماشین‌حساب قیمت',
        'orders_list'       => 'صفحه سفارشات مدیر',
        'dashboard'         => 'داشبورد مدیر',
        'suppliers'         => 'تأمین‌کنندگان',
        'wholesale'         => 'عمده‌فروشی',
        'extra_fees'        => 'هزینه‌های اضافی',
        'ai_content'        => 'تولید مقاله با هوش مصنوعی',
    );
}

function modir_diag_module_disabled($key) {
    $disabled = get_option('modir_diag_disabled_modules', array());
    return in_array($key, (array) $disabled, true);
}

function modir_diagnostics_init() {
    add_action('admin_init', 'modir_diagnostics_maybe_save_mode');
    add_action('admin_init', 'modir_diagnostics_maybe_clear_log');
    add_action('admin_init', 'modir_diagnostics_maybe_save_modules');
    add_action('admin_enqueue_scripts', 'modir_diagnostics_admin_assets');
    add_action('wp_ajax_modir_diagnostics_run_test', 'modir_diagnostics_ajax_run_test');

    if (get_option('modir_debug_mode', 0)) {
        register_shutdown_function('modir_diagnostics_capture_fatal');
    }
}

function modir_diagnostics_admin_assets($hook) {
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    $tab  = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
    if ('modir-settings' !== $page || 'diagnostics' !== $tab) {
        return;
    }
    wp_enqueue_script('modir-diagnostics-js', MODIR_URL . 'assets/js/diagnostics.js', array('jquery'), MODIR_VERSION, true);
    wp_localize_script('modir-diagnostics-js', 'ModirDiagnostics', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('modir_diagnostics_nonce'),
    ));
}

/* -------------------------------------------------------------------- */
/* گرفتن خطای فاتال واقعی، هر جای سایت که رخ بدهد                          */
/* -------------------------------------------------------------------- */

function modir_diagnostics_capture_fatal() {
    $error = error_get_last();
    if (!$error) {
        return;
    }

    $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);
    if (!in_array($error['type'], $fatal_types, true)) {
        return;
    }

    $log = get_option('modir_error_log', array());
    array_unshift($log, array(
        'time'    => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'),
        'url'     => isset($_SERVER['REQUEST_URI']) ? esc_url_raw($_SERVER['REQUEST_URI']) : '',
        'message' => $error['message'],
        'file'    => $error['file'],
        'line'    => $error['line'],
    ));
    $log = array_slice($log, 0, 30);
    update_option('modir_error_log', $log, false);
}

/* -------------------------------------------------------------------- */
/* ذخیره/پاک‌کردن                                                        */
/* -------------------------------------------------------------------- */

function modir_diagnostics_maybe_save_mode() {
    if (empty($_POST['modir_diagnostics_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_diagnostics_nonce'])), 'modir_diagnostics_save')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    update_option('modir_debug_mode', !empty($_POST['debug_mode']) ? 1 : 0);
    wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'tab' => 'diagnostics', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

function modir_diagnostics_maybe_clear_log() {
    if (empty($_GET['modir_diagnostics_action']) || 'clear_log' !== $_GET['modir_diagnostics_action']) {
        return;
    }
    if (empty($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'modir_diagnostics_clear')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    update_option('modir_error_log', array());
    wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'tab' => 'diagnostics', 'cleared' => '1'), admin_url('admin.php')));
    exit;
}

function modir_diagnostics_maybe_save_modules() {
    if (empty($_POST['modir_diag_modules_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_diag_modules_nonce'])), 'modir_diag_save_modules')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $submitted = isset($_POST['disabled_modules']) && is_array($_POST['disabled_modules']) ? array_map('sanitize_key', wp_unslash($_POST['disabled_modules'])) : array();
    $valid_keys = array_keys(modir_diag_module_list());
    $disabled   = array_values(array_intersect($submitted, $valid_keys));

    update_option('modir_diag_disabled_modules', $disabled);
    wp_safe_redirect(add_query_arg(array('page' => 'modir-settings', 'tab' => 'diagnostics', 'modules_saved' => '1'), admin_url('admin.php')));
    exit;
}

/* -------------------------------------------------------------------- */
/* تست فوری AJAX — همان چیزی که ما با کد شبیه‌سازی کردیم، اینجا روی سایت    */
/* واقعی خودت اجرا می‌شود                                                */
/* -------------------------------------------------------------------- */

function modir_diagnostics_run_probe($label, $callback) {
    $ob_level_before = ob_get_level();
    try {
        $info = $callback();
        return array('label' => $label, 'ok' => true, 'message' => is_string($info) ? $info : '');
    } catch (\Throwable $e) {
        return array(
            'label'   => $label,
            'ok'      => false,
            'message' => get_class($e) . ': ' . $e->getMessage() . ' — فایل: ' . $e->getFile() . '، خط: ' . $e->getLine(),
        );
    } finally {
        // اگر خود تابع تست‌شده ob_start() باز کرده باشد و به‌خاطر خطا هرگز
        // نبسته باشد، این بافر باز روی بقیه‌ی درخواست اثر می‌گذارد؛ برای
        // امنیت، هر بافر اضافه‌ای که این پروب باز گذاشته را می‌بندیم.
        while (ob_get_level() > $ob_level_before) {
            ob_end_clean();
        }
    }
}

function modir_diagnostics_ajax_run_test() {
    check_ajax_referer('modir_diagnostics_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }

    $results = array();

    $results[] = modir_diagnostics_run_probe('خواندن لیست محصولات (wc_get_products)', function () {
        $ids = wc_get_products(array('limit' => 5, 'return' => 'ids'));
        return count($ids) . ' محصول پیدا شد.';
    });

    $results[] = modir_diagnostics_run_probe('خواندن لیست سفارش‌ها (wc_get_orders)', function () {
        $ids = wc_get_orders(array('limit' => 5, 'type' => 'shop_order', 'return' => 'ids'));
        return count($ids) . ' سفارش پیدا شد.';
    });

    $results[] = modir_diagnostics_run_probe('شمارش کل سفارش‌ها با WP_Query خام (بدون واسطه ووکامرس)', function () {
        $q = new WP_Query(array('post_type' => 'shop_order', 'post_status' => 'any', 'posts_per_page' => 5, 'fields' => 'ids'));
        return $q->found_posts . ' سفارش (از نوع پست) پیدا شد.';
    });

    // توجه: عمداً از do_action('admin_menu')/('admin_init')/('admin_bar_menu')
    // استفاده نمی‌کنیم، چون این کار هوک‌های همه‌ی افزونه‌های دیگر سایت را هم
    // دوباره اجرا می‌کند (نه فقط مدیر) و ممکن است خودش باعث خطای تازه‌ای
    // بشود که ربطی به مدیر ندارد. به‌جایش مستقیم فقط توابع خود مدیر را
    // به‌صورت مجزا صدا می‌زنیم.

    $sample_order_obj = null;
    $sample_orders_for_columns = wc_get_orders(array('limit' => 1, 'type' => 'shop_order', 'return' => 'objects'));
    if (!empty($sample_orders_for_columns) && is_a($sample_orders_for_columns[0], 'WC_Order')) {
        $sample_order_obj = $sample_orders_for_columns[0];
    }

    if ($sample_order_obj && function_exists('modir_orders_add_columns')) {
        $columns_to_render = array_keys(modir_orders_add_columns(array()));

        if (function_exists('modir_orders_render_column')) {
            foreach ($columns_to_render as $col) {
                $results[] = modir_diagnostics_run_probe('نمایش مقدار ستون «' . $col . '» (حالت سنتی، سفارش #' . $sample_order_obj->get_id() . ')', function () use ($col, $sample_order_obj) {
                    ob_start();
                    modir_orders_render_column($col, $sample_order_obj->get_id());
                    ob_end_clean();
                });
            }
        }

        if (function_exists('modir_orders_render_column_hpos')) {
            foreach ($columns_to_render as $col) {
                $results[] = modir_diagnostics_run_probe('نمایش مقدار ستون «' . $col . '» (حالت HPOS، سفارش #' . $sample_order_obj->get_id() . ')', function () use ($col, $sample_order_obj) {
                    ob_start();
                    modir_orders_render_column_hpos($col, $sample_order_obj);
                    ob_end_clean();
                });
            }
        }
    }

    if (function_exists('modir_add_toolbar_item') || function_exists('modir_menu_admin_bar_handler')) {
        $fake_bar = new class {
            private $nodes = array();
            public function get_nodes() { return $this->nodes; }
            public function add_node($args) { $this->nodes[] = (object) $args; }
            public function remove_node($id) {}
        };

        if (function_exists('modir_add_toolbar_item')) {
            $results[] = modir_diagnostics_run_probe('آیتم نوار بالای وردپرس (تابع مدیر)', function () use ($fake_bar) {
                modir_add_toolbar_item($fake_bar);
            });
        }
        if (function_exists('modir_menu_admin_bar_handler')) {
            $results[] = modir_diagnostics_run_probe('پنهان‌سازی آیتم‌های نوار بالا (تابع مدیر)', function () use ($fake_bar) {
                modir_menu_admin_bar_handler($fake_bar);
            });
        }
    }

    if (function_exists('modir_orders_add_columns')) {
        $results[] = modir_diagnostics_run_probe('ستون‌های سفارشات (تابع مدیر)', function () {
            modir_orders_add_columns(array('cb' => '', 'order_number' => 'سفارش'));
        });
    }

    if (function_exists('modir_orders_render_filters_common')) {
        $results[] = modir_diagnostics_run_probe('فیلترهای بالای لیست سفارشات (تابع مدیر)', function () {
            ob_start();
            modir_orders_render_filters_common();
            ob_end_clean();
        });
    }

    if (function_exists('modir_label_add_row_action')) {
        $sample_orders = wc_get_orders(array('limit' => 1, 'type' => 'shop_order', 'return' => 'objects'));
        if (!empty($sample_orders) && is_a($sample_orders[0], 'WC_Order')) {
            $results[] = modir_diagnostics_run_probe('دکمه چاپ لیبل در لیست سفارشات (تابع مدیر)', function () use ($sample_orders) {
                modir_label_add_row_action(array(), $sample_orders[0]);
            });
        }
    }

    if (function_exists('modir_orders_sync_shipping_meta')) {
        $results[] = modir_diagnostics_run_probe('همگام‌سازی متادیتای حمل‌ونقل (تابع مدیر)', function () {
            modir_orders_sync_shipping_meta(0);
        });
    }

    if (function_exists('modir_order_statuses_bulk_actions')) {
        $results[] = modir_diagnostics_run_probe('اکشن‌های دسته‌جمعی وضعیت سفارش (تابع مدیر)', function () {
            modir_order_statuses_bulk_actions(array());
        });
    }

    if (function_exists('modir_menu_apply_hidden')) {
        $results[] = modir_diagnostics_run_probe('مخفی‌سازی منوها (تابع مدیر)', function () {
            global $menu;
            $backup = $menu;
            modir_menu_apply_hidden();
            $menu = $backup;
        });
    }

    $has_error = false;
    foreach ($results as $r) {
        if (!$r['ok']) {
            $has_error = true;
            break;
        }
    }

    wp_send_json_success(array('results' => $results, 'has_error' => $has_error));
}

/* ======================================================================
 * رندر تب
 * ====================================================================== */

function modir_diagnostics_render_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $debug_mode = get_option('modir_debug_mode', 0);
    $error_log  = get_option('modir_error_log', array());
    $disabled_modules = get_option('modir_diag_disabled_modules', array());
    ?>
    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>ذخیره شد.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['cleared'])) : ?>
        <div class="notice notice-success is-dismissible"><p>لاگ خطاها پاک شد.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['modules_saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>وضعیت ماژول‌ها ذخیره شد.</p></div>
    <?php endif; ?>

    <h2 class="modir-subtitle">خاموش‌کردن موقت ماژول‌ها (برای پیداکردن مقصر بدون دسترسی به فایل)</h2>
    <div class="modir-alert">
        اگه با غیرفعال‌کردن کل افزونه مشکلی حل شد، اینجا می‌تونی به‌جای غیرفعال‌کردن کل مدیر، فقط یکی‌یکی ماژول‌های زیر رو خاموش کنی و صفحه‌ی مشکل‌دار رو دوباره چک کنی — همینجوری، بدون نیاز به دسترسی فایل، مشخص می‌شه دقیقاً کدوم بخش مقصره.
    </div>
    <form method="post" class="modir-filter-form">
        <?php wp_nonce_field('modir_diag_save_modules', 'modir_diag_modules_nonce'); ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:6px;margin-bottom:14px;">
            <?php foreach (modir_diag_module_list() as $key => $label) : ?>
                <label class="modir-toggle">
                    <input type="checkbox" name="disabled_modules[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $disabled_modules, true)); ?>>
                    <?php echo esc_html($label); ?>
                </label>
            <?php endforeach; ?>
        </div>
        <?php submit_button('اعمال (خاموش/روشن‌کردن ماژول‌های تیک‌خورده)', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>

    <h2 class="modir-subtitle">تست فوری (پیشنهاد می‌شود اول این را بزنید)</h2>
    <div class="modir-alert">
        این دکمه، دقیقاً همان بخش‌هایی از ووکامرس که باعث صفحه‌ی سفید می‌شوند (ستون‌ها و فیلترهای صفحه محصولات/سفارشات) را همین الان، روی سایت خودت، امتحان می‌کند و اگر جایی خطا بدهد، بلافاصله همین‌جا نشانت می‌دهد — بدون نیاز به رفتن به جای دیگری.
    </div>
    <button type="button" class="button button-primary modir-btn" id="modir-diag-run-test">اجرای تست فوری</button>
    <div id="modir-diag-test-results" style="margin-top:16px;"></div>

    <h2 class="modir-subtitle">ثبت خودکار خطاهای فاتال</h2>
    <form method="post" class="modir-filter-form">
        <?php wp_nonce_field('modir_diagnostics_save', 'modir_diagnostics_nonce'); ?>
        <label class="modir-toggle">
            <input type="checkbox" name="debug_mode" value="1" <?php checked($debug_mode); ?>>
            وقتی روشن باشد، هر خطای فاتال واقعی PHP در هر صفحه‌ای از سایت (نه فقط مدیر) خودکار در جدول پایین ثبت می‌شود
        </label>
        <p class="description">این را روشن کن، دوباره برو سراغ صفحه‌ای که سفید می‌شود، بعد برگرد همین‌جا — خطا باید تو جدول پایین ظاهر شده باشد. توصیه می‌شود بعد از پیدا کردن مشکل، دوباره خاموشش کنی.</p>
        <?php submit_button('ذخیره', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>

    <h2 class="modir-subtitle">خطاهای ثبت‌شده</h2>
    <?php if (empty($error_log)) : ?>
        <p class="modir-empty">هنوز خطایی ثبت نشده است.</p>
    <?php else : ?>
        <p>
            <?php
            $clear_url = wp_nonce_url(add_query_arg(array('page' => 'modir-settings', 'tab' => 'diagnostics', 'modir_diagnostics_action' => 'clear_log'), admin_url('admin.php')), 'modir_diagnostics_clear');
            ?>
            <a href="<?php echo esc_url($clear_url); ?>" class="modir-link-danger" onclick="return confirm('همه خطاهای ثبت‌شده پاک شوند؟');">پاک‌کردن لاگ</a>
        </p>
        <table class="widefat striped modir-table">
            <thead>
                <tr>
                    <th style="width:140px;">زمان</th>
                    <th>پیام خطا</th>
                    <th style="width:220px;">فایل / خط</th>
                    <th style="width:200px;">آدرس صفحه</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($error_log as $entry) : ?>
                    <tr>
                        <td class="modir-text-muted"><?php echo esc_html($entry['time']); ?></td>
                        <td><?php echo esc_html($entry['message']); ?></td>
                        <td class="modir-text-muted"><?php echo esc_html(basename($entry['file'])); ?>:<?php echo esc_html($entry['line']); ?></td>
                        <td class="modir-text-muted"><?php echo esc_html($entry['url']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2 class="modir-subtitle">اطلاعات سیستم</h2>
    <table class="widefat modir-table">
        <tbody>
            <tr><td>نسخه وردپرس</td><td><?php echo esc_html(get_bloginfo('version')); ?></td></tr>
            <tr><td>نسخه ووکامرس</td><td><?php echo esc_html(defined('WC_VERSION') ? WC_VERSION : 'نامشخص'); ?></td></tr>
            <tr><td>نسخه PHP</td><td><?php echo esc_html(PHP_VERSION); ?></td></tr>
            <tr><td>نسخه مدیر</td><td><?php echo esc_html(defined('MODIR_VERSION') ? MODIR_VERSION : 'نامشخص'); ?></td></tr>
        </tbody>
    </table>
    <?php
}
