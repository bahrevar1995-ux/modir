<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «ویرایش دسته‌جمعی»: یک فیلد (قیمت اصلی/ویژه/موجودی/قیمت خرید) را
 * برای محصولاتی که از لیست پایین صفحه انتخاب می‌کنی، با افزایش/کاهش
 * درصدی یا مبلغ ثابت، یا تنظیم مستقیم روی یک مقدار مشخص، تغییر می‌دهد.
 * ==========================================================================
 */

function modir_bulk_edit_init() {
    add_action('admin_enqueue_scripts', 'modir_bulk_edit_admin_assets');
    add_action('wp_ajax_modir_bulk_list', 'modir_bulk_edit_ajax_list');
    add_action('wp_ajax_modir_bulk_apply', 'modir_bulk_edit_ajax_apply');
}

function modir_bulk_edit_admin_assets($hook) {
    if (strpos($hook, 'modir') === false) {
        return;
    }
    wp_enqueue_script('modir-bulk-edit-js', MODIR_URL . 'assets/js/bulk-edit.js', array('jquery'), MODIR_VERSION, true);
    wp_localize_script('modir-bulk-edit-js', 'ModirBulkEdit', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('modir_bulk_edit_nonce'),
    ));
}

/* -------------------------------------------------------------------- */
/* دسترسی عمومی به مقدار هر فیلد قابل‌تغییر                                */
/* -------------------------------------------------------------------- */

function modir_bulk_edit_field_options() {
    $fields = array(
        'price'          => 'قیمت اصلی',
        'sale_price'     => 'قیمت ویژه',
        'stock'          => 'موجودی (عدد)',
        'stock_status'   => 'وضعیت موجودی',
        'purchase_price' => 'قیمت خرید',
        'needs_check'    => 'چک قبل از ارسال',
    );
    if (function_exists('modir_wholesale_is_enabled') && modir_wholesale_is_enabled()) {
        $fields['wholesale_price'] = 'قیمت عمده';
    }
    return $fields;
}

function modir_bulk_edit_operation_options() {
    return array(
        'increase_percent' => 'افزایش به میزان (درصد)',
        'decrease_percent' => 'کاهش به میزان (درصد)',
        'increase_fixed'   => 'افزایش به میزان (مبلغ ثابت)',
        'decrease_fixed'   => 'کاهش به میزان (مبلغ ثابت)',
        'set_value'        => 'تنظیم روی مقدار مشخص',
    );
}

/**
 * عملیات مخصوص فیلد «وضعیت موجودی» — چون این فیلد عددی نیست و منطق
 * افزایش/کاهش/محاسبه برایش معنا ندارد.
 */
function modir_bulk_edit_stock_status_operations() {
    return array(
        'set_instock'    => 'موجود کردن',
        'set_outofstock' => 'ناموجود کردن',
    );
}

/**
 * عملیات مخصوص فیلد «چک قبل از ارسال» — این هم مثل وضعیت موجودی یک فیلد
 * درست/غلط (boolean) است، نه عددی.
 */
function modir_bulk_edit_needs_check_operations() {
    return array(
        'set_needs_check_yes' => 'علامت‌گذاری به‌عنوان نیازمند چک',
        'set_needs_check_no'  => 'حذف علامت نیازمند چک',
    );
}

function modir_bulk_edit_get_field_value($product, $field) {
    switch ($field) {
        case 'price':
            return $product->get_regular_price();
        case 'sale_price':
            return $product->get_sale_price();
        case 'stock':
            return $product->get_stock_quantity();
        case 'stock_status':
            return $product->get_stock_status();
        case 'purchase_price':
            return $product->get_meta('_modir_purchase_price');
        case 'wholesale_price':
            return $product->get_meta('_modir_wholesale_price');
        case 'needs_check':
            return $product->get_meta('_modir_needs_check');
        default:
            return '';
    }
}

function modir_bulk_edit_set_field_value($product, $field, $value) {
    switch ($field) {
        case 'price':
            $product->set_regular_price(wc_format_decimal($value));
            break;
        case 'sale_price':
            $product->set_sale_price('' === $value ? '' : wc_format_decimal($value));
            break;
        case 'stock':
            $qty = max(0, (int) $value);
            $product->set_manage_stock(true);
            $product->set_stock_quantity($qty);
            $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
            break;
        case 'purchase_price':
            $product->update_meta_data('_modir_purchase_price', wc_format_decimal($value));
            break;
        case 'wholesale_price':
            $product->update_meta_data('_modir_wholesale_price', wc_format_decimal($value));
            break;
        case 'needs_check':
            $product->update_meta_data('_modir_needs_check', $value ? '1' : '');
            break;
    }
}

function modir_bulk_edit_calc($old_value, $operation, $amount) {
    $old_value = ('' === $old_value || null === $old_value) ? 0 : (float) $old_value;
    $amount    = (float) $amount;

    switch ($operation) {
        case 'increase_percent':
            return max(0, $old_value + ($old_value * $amount / 100));
        case 'decrease_percent':
            return max(0, $old_value - ($old_value * $amount / 100));
        case 'increase_fixed':
            return max(0, $old_value + $amount);
        case 'decrease_fixed':
            return max(0, $old_value - $amount);
        case 'set_value':
            return max(0, $amount);
        default:
            return $old_value;
    }
}

/* -------------------------------------------------------------------- */
/* AJAX: لیست محصولات قابل‌انتخاب (با جست‌وجو/دسته‌بندی/بارگذاری بیشتر)      */
/* -------------------------------------------------------------------- */

function modir_bulk_edit_render_rows($search = '', $category = '', $stock = '', $offset = 0, $per_page = 25) {
    $query_args = array(
        'status'  => array('publish', 'draft', 'private'),
        'limit'   => $per_page,
        'offset'  => $offset,
        'orderby' => 'title',
        'order'   => 'ASC',
        'return'  => 'objects',
    );
    if ($search) {
        $query_args['s'] = $search;
    }
    if ($category) {
        $query_args['category'] = array($category);
    }
    if ($stock) {
        $query_args['stock_status'] = $stock;
    }

    $products = wc_get_products($query_args);

    ob_start();
    foreach ($products as $p) :
        $img = $p->get_image(array('width' => 24, 'height' => 24));
        ?>
        <tr>
            <td><input type="checkbox" class="modir-bulk-row-checkbox" value="<?php echo esc_attr($p->get_id()); ?>"></td>
            <td><?php echo wp_kses_post($img); ?></td>
            <td><?php echo esc_html($p->get_name()); ?></td>
            <td><?php echo wp_kses_post(wc_price($p->get_price())); ?></td>
            <td><?php echo esc_html($p->get_stock_quantity()); ?></td>
            <td><span class="modir-stat-badge <?php echo $p->is_in_stock() ? 'is-good' : 'is-bad'; ?>"><?php echo $p->is_in_stock() ? 'موجود' : 'ناموجود'; ?></span></td>
        </tr>
    <?php endforeach;

    return array('html' => ob_get_clean(), 'count' => count($products), 'has_more' => (count($products) === $per_page));
}

function modir_bulk_edit_ajax_list() {
    check_ajax_referer('modir_bulk_edit_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }

    $result = modir_bulk_edit_render_rows(
        isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '',
        isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : '',
        isset($_POST['stock']) ? sanitize_key($_POST['stock']) : '',
        isset($_POST['offset']) ? absint($_POST['offset']) : 0
    );

    wp_send_json_success($result);
}

/* -------------------------------------------------------------------- */
/* AJAX: اجرای عملیات روی محصولات منتخب                                   */
/* -------------------------------------------------------------------- */

function modir_bulk_edit_ajax_apply() {
    check_ajax_referer('modir_bulk_edit_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }

    $field     = isset($_POST['field']) ? sanitize_key($_POST['field']) : '';
    $operation = isset($_POST['operation']) ? sanitize_key($_POST['operation']) : '';
    $amount    = isset($_POST['amount']) ? (float) wp_unslash($_POST['amount']) : 0;
    $apply_all = !empty($_POST['apply_all']);

    $valid_fields = modir_bulk_edit_field_options();
    if (!array_key_exists($field, $valid_fields)) {
        wp_send_json_error(array('message' => 'فیلد نامعتبر است.'));
    }

    $is_stock_status_field = ('stock_status' === $field);
    $is_needs_check_field  = ('needs_check' === $field);
    $is_boolean_field      = $is_stock_status_field || $is_needs_check_field;

    if ($is_stock_status_field) {
        $valid_ops = modir_bulk_edit_stock_status_operations();
    } elseif ($is_needs_check_field) {
        $valid_ops = modir_bulk_edit_needs_check_operations();
    } else {
        $valid_ops = modir_bulk_edit_operation_options();
    }
    if (!array_key_exists($operation, $valid_ops)) {
        wp_send_json_error(array('message' => 'عملیات نامعتبر است.'));
    }

    if ($apply_all) {
        // اعمال روی همه محصولاتی که با فیلترهای فعلی (جست‌وجو/دسته‌بندی/موجودی) مطابقت دارند
        $query_args = array('status' => array('publish', 'draft', 'private'), 'limit' => -1, 'return' => 'ids');
        $search   = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $category = isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : '';
        $stock    = isset($_POST['stock']) ? sanitize_key($_POST['stock']) : '';
        if ($search) {
            $query_args['s'] = $search;
        }
        if ($category) {
            $query_args['category'] = array($category);
        }
        if ($stock) {
            $query_args['stock_status'] = $stock;
        }
        $ids = wc_get_products($query_args);
    } else {
        $ids = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? array_map('absint', wp_unslash($_POST['product_ids'])) : array();
    }

    if (empty($ids)) {
        wp_send_json_error(array('message' => 'هیچ محصولی برای اعمال پیدا نشد.'));
    }

    $count = 0;
    foreach ($ids as $pid) {
        $product = wc_get_product($pid);
        if (!$product) {
            continue;
        }

        if ($product->is_type('variable') && in_array($field, array('price', 'sale_price', 'stock', 'stock_status'), true)) {
            continue; // قیمت/موجودی محصول متغیر از تنوع‌ها می‌آید، نه از خود محصول
        }

        if ($is_stock_status_field) {
            if ('set_instock' === $operation) {
                $product->set_stock_status('instock');
            } elseif ('set_outofstock' === $operation) {
                $product->set_stock_status('outofstock');
            } else {
                continue;
            }
        } elseif ($is_needs_check_field) {
            if ('set_needs_check_yes' === $operation) {
                $product->update_meta_data('_modir_needs_check', '1');
            } elseif ('set_needs_check_no' === $operation) {
                $product->update_meta_data('_modir_needs_check', '');
            } else {
                continue;
            }
        } else {
            $old_value = modir_bulk_edit_get_field_value($product, $field);
            $new_value = modir_bulk_edit_calc($old_value, $operation, $amount);
            modir_bulk_edit_set_field_value($product, $field, $new_value);
        }

        $product->save();
        $count++;
    }

    wp_send_json_success(array('count' => $count, 'message' => $count . ' محصول به‌روزرسانی شد.'));
}

/* ======================================================================
 * صفحه ادمین
 * ====================================================================== */

function modir_bulk_edit_render_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $categories       = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
    $fields           = modir_bulk_edit_field_options();
    $operations       = modir_bulk_edit_operation_options();
    $stock_operations = modir_bulk_edit_stock_status_operations();
    $check_operations = modir_bulk_edit_needs_check_operations();
    ?>
    <div class="modir-bulk-stage-card">
        <div class="modir-bulk-stage">
            <span class="modir-bulk-stage-label">فیلد</span>
            <select id="modir-bulk-field" class="modir-pill-select">
                <?php foreach ($fields as $key => $label) : ?>
                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <span class="dashicons dashicons-arrow-left-alt2 modir-bulk-stage-arrow"></span>
        <div class="modir-bulk-stage">
            <span class="modir-bulk-stage-label">عملیات</span>
            <select id="modir-bulk-operation" class="modir-pill-select">
                <?php foreach ($operations as $key => $label) : ?>
                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <span class="dashicons dashicons-arrow-left-alt2 modir-bulk-stage-arrow"></span>
        <div class="modir-bulk-stage" id="modir-bulk-amount-wrap">
            <span class="modir-bulk-stage-label">مقدار</span>
            <input type="number" step="0.01" min="0" id="modir-bulk-amount" class="modir-grid-mini-input" style="max-width:140px;" placeholder="مقدار جدید...">
        </div>
    </div>

    <script type="application/json" id="modir-bulk-stock-operations"><?php echo wp_json_encode($stock_operations); ?></script>
    <script type="application/json" id="modir-bulk-check-operations"><?php echo wp_json_encode($check_operations); ?></script>
    <script type="application/json" id="modir-bulk-numeric-operations"><?php echo wp_json_encode($operations); ?></script>

    <div class="modir-alert" id="modir-bulk-warning">
        از لیست پایین، محصولات مورد نظر را انتخاب کنید، یا تیک «همه محصولات مطابق فیلتر» را بزنید تا دکمه اجرا فعال شود.
    </div>

    <label class="modir-toggle" style="display:block;margin-bottom:10px;">
        <input type="checkbox" id="modir-bulk-apply-all">
        همه محصولاتِ مطابق با فیلتر فعلی (نه فقط تیک‌خورده‌ها) شامل این تغییر شوند
    </label>

    <button type="button" class="button button-primary modir-btn" id="modir-bulk-apply-btn" style="width:100%;" disabled>
        اجرای عملیات روی <span id="modir-bulk-selected-count">۰</span> محصول منتخب
    </button>
    <p id="modir-bulk-result" style="margin-top:10px;"></p>

    <h2 class="modir-subtitle">انتخاب محصولات</h2>

    <div class="modir-toolbar">
        <div class="modir-toolbar-group">
            <select class="modir-pill-select" id="modir-bulk-category-filter">
                <option value="">همه دسته‌بندی‌ها</option>
                <?php foreach ($categories as $cat) : ?>
                    <option value="<?php echo esc_attr($cat->slug); ?>"><?php echo esc_html($cat->name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="modir-toolbar-group">
            <select class="modir-pill-select" id="modir-bulk-stock-filter">
                <option value="">همه (موجود و ناموجود)</option>
                <option value="instock">فقط موجود</option>
                <option value="outofstock">فقط ناموجود</option>
            </select>
        </div>
        <div style="flex:1;"></div>
        <input type="text" class="modir-search-input" id="modir-bulk-search" placeholder="جست‌وجوی سریع محصولات (نام یا SKU)...">
        <button type="button" class="modir-search-btn" id="modir-bulk-search-btn"><span class="dashicons dashicons-search"></span></button>
    </div>

    <table class="widefat modir-table modir-product-table modir-products-grid">
        <thead>
            <tr>
                <th style="width:36px;"><input type="checkbox" id="modir-bulk-select-all"></th>
                <th style="width:40px;"></th>
                <th>نام محصول</th>
                <th style="width:120px;">قیمت</th>
                <th style="width:90px;">موجودی</th>
                <th style="width:90px;">وضعیت</th>
            </tr>
        </thead>
        <tbody id="modir-bulk-product-rows"></tbody>
    </table>

    <p style="text-align:center;margin-top:16px;">
        <button type="button" class="button modir-btn" id="modir-bulk-load-more">بارگذاری محصولات بیشتر</button>
    </p>
    <?php
}

/**
 * برای لینک‌های قدیمی/بوکمارک‌شده: مستقیم صفحه محصولات را با تب «ویرایش
 * دسته‌جمعی» فعال نمایش می‌دهد، چون این ماژول به آنجا منتقل شده است.
 */
function modir_render_bulk_edit_page() {
    $_GET['tab'] = 'bulk';
    modir_render_products_page();
}
