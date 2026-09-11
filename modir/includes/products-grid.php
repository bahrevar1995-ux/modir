<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «محصولات»: جدول قابل‌ویرایش زنده (اکسلی) برای عنوان، قیمت اصلی/ویژه،
 * مدیریت موجودی، موجودی، قیمت خرید و حاشیه سود — همه با AJAX، بدون رفرش.
 * ==========================================================================
 */

function modir_products_init() {
    add_action('admin_enqueue_scripts', 'modir_products_admin_assets');
    add_action('wp_ajax_modir_products_list', 'modir_products_ajax_list');
    add_action('wp_ajax_modir_products_save_field', 'modir_products_ajax_save_field');
    add_action('wp_ajax_modir_products_variations', 'modir_products_ajax_variations');
    add_action('wp_ajax_modir_products_toggle_stock', 'modir_products_ajax_toggle_stock');
    add_action('wp_ajax_modir_products_suggest_category', 'modir_products_ajax_suggest_category');
}

function modir_products_admin_assets($hook) {
    if (strpos($hook, 'modir') === false) {
        return;
    }
    wp_enqueue_script('modir-products-js', MODIR_URL . 'assets/js/products-grid.js', array('jquery'), MODIR_VERSION, true);
    wp_localize_script('modir-products-js', 'ModirProducts', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('modir_products_nonce'),
    ));
}

/* -------------------------------------------------------------------- */
/* کمکی: حاشیه سود                                                       */
/* -------------------------------------------------------------------- */

function modir_products_margin($price, $purchase_price) {
    $price = (float) $price;
    if ($price <= 0) {
        return null;
    }
    return (($price - (float) $purchase_price) / $price) * 100;
}

/* -------------------------------------------------------------------- */
/* رندر جدول (هم برای بار اول صفحه، هم برای پاسخ AJAX)                     */
/* -------------------------------------------------------------------- */

function modir_products_render_table($args = array()) {
    $defaults = array(
        'search'      => '',
        'paged'       => 1,
        'per_page'    => 25,
        'view'        => 'excel',
        'stock'       => '',
        'category'    => '',
        'needs_check' => '',
    );
    $args = wp_parse_args($args, $defaults);

    $query_args = array(
        'status'   => array('publish', 'draft', 'private'),
        'limit'    => $args['per_page'],
        'page'     => max(1, (int) $args['paged']),
        'orderby'  => 'title',
        'order'    => 'ASC',
        'paginate' => true,
        'return'   => 'objects',
    );
    if ($args['search']) {
        $query_args['s'] = $args['search'];
    }
    if ($args['stock']) {
        $query_args['stock_status'] = $args['stock'];
    }
    if ($args['category']) {
        $query_args['category'] = array($args['category']);
    }
    if ('yes' === $args['needs_check']) {
        $query_args['meta_query'] = array(array('key' => '_modir_needs_check', 'value' => '1', 'compare' => '='));
    } elseif ('no' === $args['needs_check']) {
        $query_args['meta_query'] = array(
            'relation' => 'OR',
            array('key' => '_modir_needs_check', 'compare' => 'NOT EXISTS'),
            array('key' => '_modir_needs_check', 'value' => '1', 'compare' => '!='),
        );
    }

    $result    = wc_get_products($query_args);
    $products  = $result->products;
    $max_pages = (int) $result->max_num_pages;
    $is_excel  = ('excel' === $args['view']);
    $wholesale_enabled = function_exists('modir_wholesale_is_enabled') && modir_wholesale_is_enabled();

    $all_categories = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));

    ob_start();
    ?>
    <?php if (empty($products)) : ?>
        <p class="modir-empty">محصولی پیدا نشد.</p>
    <?php else : ?>
    <table class="widefat modir-table modir-product-table modir-products-grid">
        <thead>
            <tr>
                <th style="width:36px;"><input type="checkbox" class="modir-select-all"></th>
                <th style="width:50px;"></th>
                <th style="width:auto;">نام محصول</th>
                <th style="width:170px;">دسته‌بندی</th>
                <th style="width:130px;">قیمت اصلی</th>
                <?php if ($is_excel) : ?>
                    <th style="width:130px;">قیمت ویژه</th>
                    <th style="width:100px;">وضعیت موجودی</th>
                <?php endif; ?>
                <th style="width:100px;">موجودی</th>
                <th style="width:90px;">چک قبل از ارسال</th>
                <?php if ($is_excel) : ?>
                    <th style="width:120px;">قیمت خرید</th>
                    <th style="width:150px;">تأمین‌کننده</th>
                    <?php if ($wholesale_enabled) : ?>
                        <th style="width:120px;">قیمت عمده</th>
                    <?php endif; ?>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $p) :
                $is_variable      = $p->is_type('variable');
                $variation_count  = $is_variable ? count($p->get_children()) : 0;
                $img              = $p->get_image(array('width' => 24, 'height' => 24));
                $purchase_price   = $p->get_meta('_modir_purchase_price');
                $in_stock         = $p->is_in_stock();
                $edit_url         = get_edit_post_link($p->get_id(), '');
                $view_url         = $p->get_permalink();
                ?>
                <tr data-product-id="<?php echo esc_attr($p->get_id()); ?>" <?php echo $is_variable ? 'data-variable="1"' : ''; ?>>
                    <td><input type="checkbox" class="modir-row-select"></td>
                    <td><?php echo wp_kses_post($img); ?></td>
                    <td>
                        <div class="modir-product-cell modir-product-cell-wide">
                            <?php if ($is_variable) : ?>
                                <button type="button" class="modir-variation-toggle" data-product-id="<?php echo esc_attr($p->get_id()); ?>" title="نمایش تنوع‌ها">
                                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                                </button>
                            <?php endif; ?>
                            <input type="text" class="modir-grid-input modir-grid-input-title" data-field="title" value="<?php echo esc_attr($p->get_name()); ?>">
                            <div class="modir-product-icon-links">
                                <a href="<?php echo esc_url($view_url); ?>" target="_blank" class="modir-icon-link" title="نمایش محصول"><span class="dashicons dashicons-visibility"></span></a>
                                <a href="<?php echo esc_url($edit_url); ?>" target="_blank" class="modir-icon-link" title="ویرایش در ووکامرس"><span class="dashicons dashicons-edit"></span></a>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="display:flex;align-items:center;gap:4px;">
                            <select class="modir-grid-input modir-grid-input-category" data-field="category" style="flex:1;">
                                <option value="0">بدون دسته‌بندی</option>
                                <?php
                                $product_cat_ids = $p->get_category_ids();
                                foreach ($all_categories as $cat) :
                                    ?>
                                    <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected(in_array($cat->term_id, $product_cat_ids, true)); ?>><?php echo esc_html($cat->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="modir-icon-link modir-suggest-category-btn" data-product-id="<?php echo esc_attr($p->get_id()); ?>" title="پیشنهاد هوشمند دسته‌بندی بر اساس نام محصول">
                                <span class="dashicons dashicons-lightbulb"></span>
                            </button>
                        </div>
                    </td>
                    <td>
                        <?php if ($is_variable) : ?>
                            <span class="modir-stat-badge is-neutral modir-variation-toggle" data-product-id="<?php echo esc_attr($p->get_id()); ?>"><?php echo number_format_i18n($variation_count); ?> تنوع</span>
                        <?php else : ?>
                            <input type="number" step="0.01" min="0" class="modir-grid-input" data-field="price" value="<?php echo esc_attr($p->get_regular_price()); ?>">
                        <?php endif; ?>
                    </td>

                    <?php if ($is_excel) : ?>
                        <td>
                            <?php if ($is_variable) : ?>
                                <span class="modir-text-muted">—</span>
                            <?php else : ?>
                                <input type="number" step="0.01" min="0" class="modir-grid-input" data-field="sale_price" value="<?php echo esc_attr($p->get_sale_price()); ?>">
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo modir_products_render_stock_accordion($p->get_id(), $in_stock); ?>
                        </td>
                    <?php endif; ?>

                    <td>
                        <?php if ($is_variable) : ?>
                            <span class="modir-text-muted">—</span>
                        <?php else : ?>
                            <input type="number" step="1" min="0" class="modir-grid-input" data-field="stock" value="<?php echo esc_attr($p->get_stock_quantity()); ?>">
                        <?php endif; ?>
                    </td>

                    <td style="text-align:center;">
                        <input type="checkbox" class="modir-switch-input" data-field="needs_check" <?php checked($p->get_meta('_modir_needs_check'), '1'); ?> title="این محصول قبل از ارسال باید توسط فروشگاه سلامتش چک شود">
                    </td>

                    <?php if ($is_excel) : ?>
                        <td>
                            <input type="number" step="0.01" min="0" class="modir-grid-input" data-field="purchase_price" value="<?php echo esc_attr($purchase_price); ?>" placeholder="ثبت نشده">
                        </td>
                        <td>
                            <select class="modir-grid-input" data-field="supplier">
                                <option value="">—</option>
                                <?php
                                $current_supplier = $p->get_meta('_modir_supplier_id');
                                foreach (function_exists('modir_suppliers_get_all') ? modir_suppliers_get_all() : array() as $sup) :
                                    ?>
                                    <option value="<?php echo esc_attr($sup['id']); ?>" <?php selected($current_supplier, $sup['id']); ?>><?php echo esc_html($sup['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <?php if ($wholesale_enabled) : ?>
                            <td>
                                <input type="number" step="0.01" min="0" class="modir-grid-input" data-field="wholesale_price" value="<?php echo esc_attr($p->get_meta('_modir_wholesale_price')); ?>" placeholder="ثبت نشده">
                            </td>
                        <?php endif; ?>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($max_pages > 1) : ?>
        <div class="modir-products-pagination">
            <?php for ($i = 1; $i <= $max_pages; $i++) : ?>
                <button type="button" class="modir-page-btn <?php echo ($i === (int) $args['paged']) ? 'is-active' : ''; ?>" data-page="<?php echo esc_attr($i); ?>"><?php echo number_format_i18n($i); ?></button>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

/* -------------------------------------------------------------------- */
/* ردیف‌های شاخه‌ای تنوع‌های یک محصول متغیر                                */
/* -------------------------------------------------------------------- */

function modir_products_render_variation_rows($parent_id, $view = 'excel') {
    $parent = wc_get_product($parent_id);
    if (!$parent || !$parent->is_type('variable')) {
        return '';
    }

    $is_excel = ('excel' === $view);

    ob_start();
    foreach ($parent->get_children() as $variation_id) {
        $variation = wc_get_product($variation_id);
        if (!$variation) {
            continue;
        }

        $attr_labels = array();
        foreach ($variation->get_variation_attributes() as $attr_name => $attr_value) {
            if ('' === $attr_value) {
                continue;
            }
            $taxonomy      = str_replace('attribute_', '', $attr_name);
            $attr_labels[] = wc_attribute_label($taxonomy) . ': ' . $attr_value;
        }
        $label    = $attr_labels ? implode('، ', $attr_labels) : ('تنوع #' . $variation_id);
        $img      = $variation->get_image(array('width' => 20, 'height' => 20));
        $in_stock = $variation->is_in_stock();
        ?>
        <tr class="modir-variation-row" data-product-id="<?php echo esc_attr($variation_id); ?>" data-parent-id="<?php echo esc_attr($parent_id); ?>">
            <td></td>
            <td><?php echo wp_kses_post($img); ?></td>
            <td>
                <div class="modir-variation-label">
                    <span class="dashicons dashicons-arrow-left-alt2 modir-variation-branch-icon"></span>
                    <?php echo esc_html($label); ?>
                </div>
            </td>
            <td><span class="modir-text-muted">—</span></td>
            <td><input type="number" step="0.01" min="0" class="modir-grid-input" data-field="price" value="<?php echo esc_attr($variation->get_regular_price()); ?>"></td>
            <?php if ($is_excel) : ?>
                <td><input type="number" step="0.01" min="0" class="modir-grid-input" data-field="sale_price" value="<?php echo esc_attr($variation->get_sale_price()); ?>"></td>
                <td><?php echo modir_products_render_stock_accordion($variation_id, $in_stock); ?></td>
            <?php endif; ?>
            <td><input type="number" step="1" min="0" class="modir-grid-input" data-field="stock" value="<?php echo esc_attr($variation->get_stock_quantity()); ?>"></td>
            <td style="text-align:center;">
                <input type="checkbox" class="modir-switch-input" data-field="needs_check" <?php checked($variation->get_meta('_modir_needs_check'), '1'); ?> title="این تنوع قبل از ارسال باید توسط فروشگاه سلامتش چک شود">
            </td>
            <?php if ($is_excel) : ?>
                <td><span class="modir-text-muted">—</span></td>
                <td><span class="modir-text-muted">—</span></td>
                <?php if ($wholesale_enabled) : ?>
                    <td><span class="modir-text-muted">—</span></td>
                <?php endif; ?>
            <?php endif; ?>
        </tr>
        <?php
    }

    return ob_get_clean();
}

function modir_products_ajax_variations() {
    check_ajax_referer('modir_products_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }

    $pid  = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $view = (isset($_POST['view']) && 'simple' === $_POST['view']) ? 'simple' : 'excel';

    $html = modir_products_render_variation_rows($pid, $view);
    wp_send_json_success(array('html' => $html));
}

/* -------------------------------------------------------------------- */
/* AJAX: لیست/جست‌وجو/صفحه‌بندی/فیلتر                                      */
/* -------------------------------------------------------------------- */

function modir_products_ajax_list() {
    check_ajax_referer('modir_products_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }

    $html = modir_products_render_table(array(
        'search'      => isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '',
        'paged'       => isset($_POST['paged']) ? absint($_POST['paged']) : 1,
        'per_page'    => isset($_POST['per_page']) ? absint($_POST['per_page']) : 25,
        'view'        => (isset($_POST['view']) && 'simple' === $_POST['view']) ? 'simple' : 'excel',
        'stock'       => isset($_POST['stock']) ? sanitize_key($_POST['stock']) : '',
        'category'    => isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : '',
        'needs_check' => isset($_POST['needs_check']) ? sanitize_key($_POST['needs_check']) : '',
    ));

    wp_send_json_success(array('html' => $html));
}

/* -------------------------------------------------------------------- */
/* AJAX: ذخیره یک فیلد از یک محصول (بدون رفرش)                            */
/* -------------------------------------------------------------------- */

function modir_products_ajax_save_field() {
    check_ajax_referer('modir_products_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }

    $pid   = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $field = isset($_POST['field']) ? sanitize_key($_POST['field']) : '';
    $value = isset($_POST['value']) ? wp_unslash($_POST['value']) : '';

    $product = $pid ? wc_get_product($pid) : false;
    if (!$product) {
        wp_send_json_error(array('message' => 'محصول پیدا نشد.'));
    }

    switch ($field) {
        case 'title':
            $value = sanitize_text_field($value);
            if (!$value) {
                wp_send_json_error(array('message' => 'عنوان نمی‌تواند خالی باشد.'));
            }
            $product->set_name($value);
            break;

        case 'price':
            if ($product->is_type('variable')) {
                wp_send_json_error(array('message' => 'این محصول دارای تنوع است.'));
            }
            $product->set_regular_price(wc_format_decimal($value));
            break;

        case 'sale_price':
            if ($product->is_type('variable')) {
                wp_send_json_error(array('message' => 'این محصول دارای تنوع است.'));
            }
            $product->set_sale_price('' === $value ? '' : wc_format_decimal($value));
            break;

        case 'stock':
            if ($product->is_type('variable')) {
                wp_send_json_error(array('message' => 'این محصول دارای تنوع است.'));
            }
            $qty = max(0, (int) $value);
            $product->set_manage_stock(true);
            $product->set_stock_quantity($qty);
            $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
            break;

        case 'manage_stock':
            $product->set_manage_stock('1' === $value || 'true' === $value);
            break;

        case 'purchase_price':
            $product->update_meta_data('_modir_purchase_price', wc_format_decimal($value));
            break;

        case 'category':
            $cat_id = absint($value);
            $product->set_category_ids($cat_id ? array($cat_id) : array());
            break;

        case 'supplier':
            $product->update_meta_data('_modir_supplier_id', sanitize_text_field($value));
            break;

        case 'wholesale_price':
            $product->update_meta_data('_modir_wholesale_price', wc_format_decimal($value));
            break;

        case 'needs_check':
            $product->update_meta_data('_modir_needs_check', ('1' === $value || 1 === $value) ? '1' : '');
            break;

        default:
            wp_send_json_error(array('message' => 'فیلد نامعتبر.'));
    }

    $product->save();

    $margin = modir_products_margin($product->get_regular_price(), $product->get_meta('_modir_purchase_price'));

    wp_send_json_success(array(
        'message'            => 'ذخیره شد.',
        'margin'             => (null === $margin) ? null : round($margin, 1),
        'stock_status_label' => $product->is_in_stock() ? 'موجود' : 'ناموجود',
        'in_stock'           => $product->is_in_stock(),
    ));
}

/* -------------------------------------------------------------------- */
/* AJAX: کلیک روی بج موجودی برای برعکس‌کردن سریع وضعیت آن                   */
/* -------------------------------------------------------------------- */

function modir_products_ajax_toggle_stock() {
    check_ajax_referer('modir_products_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }

    $pid     = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $product = $pid ? wc_get_product($pid) : false;
    if (!$product) {
        wp_send_json_error(array('message' => 'محصول پیدا نشد.'));
    }

    // اگر گزینه‌ی صریح از آکاردئون انتخاب شده باشد همان استفاده می‌شود؛
    // در غیر این صورت (برای سازگاری با کدهای قدیمی) وضعیت برعکس می‌شود.
    $requested = isset($_POST['status']) ? sanitize_key($_POST['status']) : '';
    if (in_array($requested, array('instock', 'outofstock'), true)) {
        $new_status = $requested;
    } else {
        $new_status = $product->is_in_stock() ? 'outofstock' : 'instock';
    }

    if ($product->is_type('variable')) {
        // برای محصول متغیر، وضعیت همه تنوع‌ها با هم عوض می‌شود تا واقعاً کل
        // محصول موجود/ناموجود شود (نه فقط یک فیلد ظاهری در والد).
        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                continue;
            }
            $variation->set_stock_status($new_status);
            $variation->save();
        }
        $product = wc_get_product($pid); // بارگذاری دوباره برای گرفتن وضعیت تجمیعی صحیح
    } else {
        $product->set_stock_status($new_status);
        $product->save();
    }

    wp_send_json_success(array(
        'in_stock' => $product->is_in_stock(),
        'label'    => $product->is_in_stock() ? 'موجود' : 'ناموجود',
    ));
}

/* -------------------------------------------------------------------- */
/* پیشنهاد هوشمند دسته‌بندی بر اساس نام محصول                              */
/* -------------------------------------------------------------------- */

/**
 * روش اول (همیشه در دسترس، بدون نیاز به هوش مصنوعی): اگر اسم دسته‌بندی
 * (یا یکی از کلمات آن) داخل نام محصول دیده شود، همان دسته پیشنهاد می‌شود.
 */
function modir_products_suggest_category_by_keyword($product_name, $categories) {
    $name_normalized = mb_strtolower(trim($product_name));
    $best            = null;
    $best_score      = 0;

    foreach ($categories as $cat) {
        $words = preg_split('/\s+/u', mb_strtolower($cat->name));
        $score = 0;
        foreach ($words as $w) {
            if (mb_strlen($w) >= 2 && false !== mb_strpos($name_normalized, $w)) {
                $score++;
            }
        }
        if ($score > $best_score) {
            $best_score = $score;
            $best       = $cat;
        }
    }

    return ($best && $best_score > 0) ? $best : null;
}

/**
 * روش دوم (اگر کلیدواژه چیزی پیدا نکرد): از همان سرویس هوش مصنوعیِ
 * پیکربندی‌شده برای دستیار گفتگو کمک می‌گیرد تا نزدیک‌ترین دسته را حدس بزند.
 */
function modir_products_suggest_category_by_ai($product_name, $categories) {
    if (!function_exists('modir_chat_get_settings') || !function_exists('modir_chat_call_ai')) {
        return null;
    }

    $settings = modir_chat_get_settings();
    $api_key  = modir_chat_get_api_key($settings);
    if (!$api_key) {
        return null; // دستیار گفتگو هنوز پیکربندی نشده؛ به‌آرامی نادیده گرفته می‌شود
    }

    $cat_list = array();
    foreach ($categories as $cat) {
        $cat_list[] = $cat->term_id . ': ' . $cat->name;
    }

    $prompt = "نام یک محصول فروشگاهی و لیست دسته‌بندی‌های موجود را می‌بینی. فقط و فقط عدد شناسه (id) نزدیک‌ترین دسته‌بندی را برگردان، بدون هیچ توضیح اضافه. اگر هیچ‌کدام مناسب نبود، عدد 0 را برگردان.\n\nنام محصول: " . $product_name . "\n\nدسته‌بندی‌ها:\n" . implode("\n", $cat_list);

    $result = modir_chat_call_ai($settings, $api_key, 'تو فقط با یک عدد پاسخ می‌دهی، بدون هیچ متن دیگری.', array(), $prompt);
    if (empty($result['ok'])) {
        return null;
    }

    preg_match('/\d+/', $result['reply'], $matches);
    $suggested_id = isset($matches[0]) ? (int) $matches[0] : 0;
    if (!$suggested_id) {
        return null;
    }

    foreach ($categories as $cat) {
        if ((int) $cat->term_id === $suggested_id) {
            return $cat;
        }
    }
    return null;
}

function modir_products_ajax_suggest_category() {
    check_ajax_referer('modir_products_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }

    $pid     = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $product = $pid ? wc_get_product($pid) : false;
    if (!$product) {
        wp_send_json_error(array('message' => 'محصول پیدا نشد.'));
    }

    $categories = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
    if (empty($categories) || is_wp_error($categories)) {
        wp_send_json_error(array('message' => 'هیچ دسته‌بندی‌ای در فروشگاه ثبت نشده است.'));
    }

    $suggested = modir_products_suggest_category_by_keyword($product->get_name(), $categories);
    $source    = 'keyword';

    if (!$suggested) {
        $suggested = modir_products_suggest_category_by_ai($product->get_name(), $categories);
        $source    = 'ai';
    }

    if (!$suggested) {
        wp_send_json_error(array('message' => 'نتونستم دسته‌بندی مناسبی حدس بزنم؛ خودت انتخاب کن.'));
    }

    wp_send_json_success(array(
        'category_id'   => $suggested->term_id,
        'category_name' => $suggested->name,
        'source'        => $source,
    ));
}

/**
 * بج موجودی به‌صورت آکاردئونی: کلیک روی آن یک لیست کوچک با دو گزینه‌ی
 * «موجود» و «ناموجود» باز می‌کند تا انتخاب صریح انجام شود (به‌جای برعکس‌شدن
 * فوری و بدون تأیید با یک کلیک ساده).
 */
function modir_products_render_stock_accordion($product_id, $in_stock) {
    ob_start();
    ?>
    <div class="modir-stock-accordion" data-product-id="<?php echo esc_attr($product_id); ?>">
        <button type="button" class="modir-stat-badge modir-stock-accordion-toggle <?php echo $in_stock ? 'is-good' : 'is-bad'; ?>">
            <?php echo $in_stock ? 'موجود' : 'ناموجود'; ?>
            <span class="dashicons dashicons-arrow-down-alt2"></span>
        </button>
        <div class="modir-stock-accordion-options">
            <button type="button" class="modir-stock-option is-good" data-status="instock">موجود</button>
            <button type="button" class="modir-stock-option is-bad" data-status="outofstock">ناموجود</button>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/* ======================================================================
 * صفحه ادمین (به‌همراه زیرمجموعه‌ها: افزودن محصول، ویرایش دسته‌جمعی، پاپ‌آپ)
 * ====================================================================== */

function modir_products_render_main_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $categories = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
    ?>
    <div class="modir-toolbar">
        <div class="modir-toolbar-group">
            <label class="modir-text-muted" style="font-size:11px;">ردیف</label>
            <select class="modir-pill-select" id="modir-products-per-page">
                <option value="25">۲۵ ردیف</option>
                <option value="50">۵۰ ردیف</option>
                <option value="100">۱۰۰ ردیف</option>
            </select>
        </div>

        <div class="modir-toolbar-sep"></div>

        <div class="modir-view-toggle" id="modir-products-view-toggle">
            <button type="button" class="is-active" data-view="excel">اکسلی</button>
            <button type="button" data-view="simple">ساده</button>
        </div>

        <div class="modir-toolbar-sep"></div>

        <div class="modir-toolbar-group">
            <select class="modir-pill-select" id="modir-products-stock-filter">
                <option value="">وضعیت موجودی</option>
                <option value="instock">موجود</option>
                <option value="outofstock">ناموجود</option>
            </select>
        </div>

        <div class="modir-toolbar-group">
            <select class="modir-pill-select" id="modir-products-category-filter">
                <option value="">همه دسته‌بندی‌ها</option>
                <?php foreach ($categories as $cat) : ?>
                    <option value="<?php echo esc_attr($cat->slug); ?>"><?php echo esc_html($cat->name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="modir-toolbar-group">
            <select class="modir-pill-select" id="modir-products-check-filter">
                <option value="">چک قبل از ارسال</option>
                <option value="yes">فقط نیازمند چک</option>
                <option value="no">فقط بدون نیاز به چک</option>
            </select>
        </div>

        <div style="flex:1;"></div>

        <input type="text" class="modir-search-input" id="modir-products-search" placeholder="جست‌وجوی پیشرفته...">
        <button type="button" class="modir-search-btn" id="modir-products-search-btn"><span class="dashicons dashicons-search"></span></button>
    </div>

    <div id="modir-products-grid-container">
        <?php echo modir_products_render_table(); ?>
    </div>
    <?php
}

function modir_render_products_page() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $sub_tabs = array(
        'main'      => 'محصولات',
        'import'    => 'افزودن محصول',
        'bulk'      => 'ویرایش دسته‌جمعی',
        'popup'     => 'پاپ‌آپ محصول',
        'suppliers' => 'تأمین‌کنندگان',
    );
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'main';
    if (!isset($sub_tabs[$tab])) {
        $tab = 'main';
    }
    ?>
    <div class="wrap modir-wrap">
        <?php modir_render_top_header('cart', 'محصولات', 'ویرایش زنده قیمت، موجودی، سود، و ابزارهای مربوط به محصول'); ?>
        <?php modir_settings_tabs_nav('modir'); ?>
        <?php modir_render_sub_tabs_nav('modir', $sub_tabs, $tab); ?>

        <div class="modir-tab-panel">
            <?php
            switch ($tab) {
                case 'import':
                    modir_import_render_content();
                    break;
                case 'bulk':
                    modir_bulk_edit_render_content();
                    break;
                case 'popup':
                    modir_popup_render_content();
                    break;
                case 'suppliers':
                    modir_suppliers_render_content();
                    break;
                default:
                    modir_products_render_main_content();
                    break;
            }
            ?>
        </div>
    </div>
    <?php
}
