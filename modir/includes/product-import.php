<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «افزودن محصول»: کاملاً خودکار — بدون پرسیدن هیچ سوالی از کاربر.
 *
 * برای دیجی‌کالا: از API عمومی خود دیجی‌کالا (api.digikala.com) استفاده
 * می‌شود که همان داده‌ای را برمی‌گرداند که اپ/سایت دیجی‌کالا از آن استفاده
 * می‌کند — چون خواندن مستقیم HTML صفحه دیجی‌کالا معمولاً با محافظت ضدربات
 * (Cloudflare و غیره) مسدود می‌شود و کار نمی‌کند.
 *
 * برای بقیه سایت‌ها (وردپرسی/ووکامرسی): همان روش قبلی، خواندن داده‌های
 * استاندارد Schema.org JSON-LD / Open Graph از HTML صفحه.
 * ==========================================================================
 */

function modir_import_init() {
    add_action('admin_enqueue_scripts', 'modir_import_admin_assets');
    add_action('wp_ajax_modir_import_auto_add', 'modir_import_ajax_auto_add');
}

function modir_import_admin_assets($hook) {
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    $tab  = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
    $is_new_url    = ('modir' === $page && 'import' === $tab);
    $is_legacy_url = ('modir-import' === $page);
    if (!$is_new_url && !$is_legacy_url) {
        return;
    }
    wp_enqueue_style('modir-import-css', MODIR_URL . 'assets/css/import.css', array(), MODIR_VERSION);
    wp_enqueue_script('modir-import-js', MODIR_URL . 'assets/js/import.js', array('jquery'), MODIR_VERSION, true);
    wp_localize_script('modir-import-js', 'ModirImport', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('modir_import_nonce'),
        'i18n'    => array(
            'working' => 'در حال دریافت اطلاعات و افزودن محصول...',
            'error'   => 'خطایی رخ داد.',
        ),
    ));
}

/* -------------------------------------------------------------------- */
/* AJAX: کل فرایند در یک مرحله — دریافت اطلاعات + ساخت محصول             */
/* -------------------------------------------------------------------- */

function modir_import_ajax_auto_add() {
    check_ajax_referer('modir_import_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }

    $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
    if (!$url || !wp_http_validate_url($url)) {
        wp_send_json_error(array('message' => 'لینک وارد شده معتبر نیست.'));
    }

    $overrides = array(
        'category_id' => isset($_POST['category_id']) ? absint($_POST['category_id']) : 0,
        'price'       => isset($_POST['price']) ? sanitize_text_field(wp_unslash($_POST['price'])) : '',
        'stock'       => isset($_POST['stock']) ? sanitize_key($_POST['stock']) : '',
    );

    $data = modir_import_fetch_product_data($url);
    if (is_wp_error($data)) {
        wp_send_json_error(array('message' => $data->get_error_message()));
    }

    $result = modir_import_create_product($data, $overrides);
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success($result);
}

/* -------------------------------------------------------------------- */
/* تشخیص منبع و مسیر مناسب واکشی                                        */
/* -------------------------------------------------------------------- */

function modir_import_fetch_product_data($url) {
    $host = wp_parse_url($url, PHP_URL_HOST);

    if ($host && false !== stripos($host, 'digikala.com')) {
        return modir_import_fetch_from_digikala($url);
    }

    return modir_import_fetch_from_generic_site($url);
}

/* -------------------------------------------------------------------- */
/* دیجی‌کالا: استفاده از API عمومی خود دیجی‌کالا                          */
/* -------------------------------------------------------------------- */

function modir_import_fetch_from_digikala($url) {
    if (!preg_match('/dkp-(\d+)/i', $url, $m)) {
        if (!preg_match('#/product/(\d+)#i', $url, $m)) {
            return new WP_Error('bad_url', 'شناسه محصول در این لینک دیجی‌کالا پیدا نشد. لینک باید شبیه این باشد: digikala.com/product/dkp-1234567/...');
        }
    }
    $product_id = $m[1];

    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36';

    $api_url  = 'https://api.digikala.com/v2/product/' . $product_id . '/';
    $response = wp_remote_get($api_url, array(
        'timeout'    => 25,
        'user-agent' => $ua,
        'headers'    => array('Accept' => 'application/json'),
    ));

    if (is_wp_error($response)) {
        return new WP_Error('fetch_failed', 'اتصال به سرویس دیجی‌کالا ناموفق بود: ' . $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($code < 200 || $code >= 300 || !$body) {
        $api_url  = 'https://api.digikala.com/v1/product/' . $product_id . '/';
        $response = wp_remote_get($api_url, array(
            'timeout'    => 25,
            'user-agent' => $ua,
            'headers'    => array('Accept' => 'application/json'),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('fetch_failed', 'اتصال به سرویس دیجی‌کالا ناموفق بود: ' . $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
    }

    if ($code < 200 || $code >= 300 || !$body) {
        return new WP_Error('fetch_status', 'دیجی‌کالا پاسخ معتبری برنگرداند (کد ' . $code . '). ممکن است دسترسی سرور شما به دیجی‌کالا موقتاً محدود شده باشد.');
    }

    $json = json_decode($body, true);
    if (!$json) {
        return new WP_Error('bad_json', 'پاسخ دریافتی از دیجی‌کالا قابل خواندن نبود.');
    }

    $product = $json['data']['product'] ?? ($json['data'] ?? null);
    if (!$product) {
        return new WP_Error('no_product', 'اطلاعات محصولی در پاسخ دیجی‌کالا پیدا نشد.');
    }

    $title = $product['title_fa'] ?? ($product['title'] ?? '');

    $description = '';
    if (!empty($product['review']['description'])) {
        $description = $product['review']['description'];
    } elseif (!empty($product['review']['expert_reviews'][0]['title'])) {
        $description = $product['review']['expert_reviews'][0]['title'];
    } elseif (!empty($product['title_en'])) {
        $description = $product['title_en'];
    }

    $images = array();
    if (!empty($product['images']['main']['url']) && is_array($product['images']['main']['url'])) {
        $images = array_merge($images, $product['images']['main']['url']);
    } elseif (!empty($product['image']['url']) && is_array($product['image']['url'])) {
        $images = array_merge($images, $product['image']['url']);
    }
    if (!empty($product['images']['list']) && is_array($product['images']['list'])) {
        foreach ($product['images']['list'] as $img) {
            if (!empty($img['url']) && is_array($img['url'])) {
                $images = array_merge($images, $img['url']);
            }
        }
    }
    $images = array_values(array_unique(array_filter($images)));

    $price = '';
    if (!empty($product['default_variant']['price']['selling_price'])) {
        $price = $product['default_variant']['price']['selling_price'];
    } elseif (!empty($product['default_variant']['price']['rrp_price'])) {
        $price = $product['default_variant']['price']['rrp_price'];
    }

    $attributes = array();
    if (!empty($product['review']['attributes']) && is_array($product['review']['attributes'])) {
        foreach ($product['review']['attributes'] as $group) {
            if (empty($group['attributes']) || !is_array($group['attributes'])) {
                continue;
            }
            foreach ($group['attributes'] as $attr) {
                if (!empty($attr['title']) && !empty($attr['values'])) {
                    $attributes[] = array(
                        'name'  => wp_strip_all_tags($attr['title']),
                        'value' => wp_strip_all_tags(implode('، ', (array) $attr['values'])),
                    );
                }
            }
        }
    }

    if (!$title && empty($images)) {
        return new WP_Error('empty_data', 'اطلاعات قابل‌استفاده‌ای از این محصول دیجی‌کالا دریافت نشد.');
    }

    return array(
        'title'       => wp_strip_all_tags($title),
        'description' => is_string($description) ? $description : '',
        'price'       => $price ? preg_replace('/[^0-9.]/', '', (string) $price) : '',
        'image'       => !empty($images[0]) ? $images[0] : '',
        'gallery'     => array_slice($images, 1),
        'attributes'  => $attributes,
        'source_url'  => $url,
        'source'      => 'digikala',
    );
}

/* -------------------------------------------------------------------- */
/* سایت‌های عمومی (وردپرسی/ووکامرسی): Schema.org JSON-LD + Open Graph     */
/* -------------------------------------------------------------------- */

function modir_import_fetch_from_generic_site($url) {
    $response = wp_remote_get($url, array(
        'timeout'     => 25,
        'redirection' => 5,
        'user-agent'  => 'Mozilla/5.0 (compatible; ModirImportBot/1.0; +https://wordpress.org)',
        'headers'     => array('Accept-Language' => 'fa-IR,fa;q=0.9,en;q=0.8'),
    ));

    if (is_wp_error($response)) {
        return new WP_Error('fetch_failed', 'اتصال به سایت مقصد ناموفق بود: ' . $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('fetch_status', 'سایت مقصد پاسخ نامعتبر داد (کد ' . $code . ').');
    }

    $html = wp_remote_retrieve_body($response);
    if (!$html) {
        return new WP_Error('empty_body', 'محتوایی از این لینک دریافت نشد.');
    }

    if (!class_exists('DOMDocument')) {
        return new WP_Error('no_dom', 'کتابخانه DOMDocument در سرور شما فعال نیست؛ این قابلیت قابل استفاده نیست.');
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $result = array(
        'title' => '', 'description' => '', 'price' => '', 'image' => '',
        'gallery' => array(), 'attributes' => array(), 'source_url' => $url, 'source' => 'generic',
    );

    $ldjson_nodes = $xpath->query('//script[@type="application/ld+json"]');
    foreach ($ldjson_nodes as $node) {
        $json = json_decode($node->textContent, true);
        if (!$json) {
            continue;
        }
        $candidates = isset($json['@graph']) && is_array($json['@graph']) ? $json['@graph'] : (isset($json[0]) ? $json : array($json));

        foreach ($candidates as $item) {
            if (!is_array($item) || empty($item['@type'])) {
                continue;
            }
            $type = is_array($item['@type']) ? implode(',', $item['@type']) : $item['@type'];
            if (false === stripos($type, 'Product')) {
                continue;
            }

            if (!empty($item['name'])) {
                $result['title'] = wp_strip_all_tags($item['name']);
            }
            if (!empty($item['description'])) {
                $result['description'] = wp_strip_all_tags($item['description']);
            }
            if (!empty($item['image'])) {
                $imgs = is_array($item['image']) ? $item['image'] : array($item['image']);
                foreach ($imgs as $img) {
                    $img_url = is_array($img) ? ($img['url'] ?? '') : $img;
                    if ($img_url) {
                        if (!$result['image']) {
                            $result['image'] = $img_url;
                        } else {
                            $result['gallery'][] = $img_url;
                        }
                    }
                }
            }
            $offers = $item['offers'] ?? null;
            if ($offers) {
                $offer = isset($offers[0]) ? $offers[0] : $offers;
                if (!empty($offer['price'])) {
                    $result['price'] = preg_replace('/[^0-9.]/', '', (string) $offer['price']);
                }
            }
            if (!empty($item['additionalProperty']) && is_array($item['additionalProperty'])) {
                foreach ($item['additionalProperty'] as $prop) {
                    if (!empty($prop['name']) && isset($prop['value'])) {
                        $result['attributes'][] = array('name' => wp_strip_all_tags($prop['name']), 'value' => wp_strip_all_tags((string) $prop['value']));
                    }
                }
            }
            break 2;
        }
    }

    if (!$result['title']) {
        $result['title'] = modir_import_get_meta($xpath, 'og:title');
    }
    if (!$result['title']) {
        $titles = $xpath->query('//title');
        if ($titles->length) {
            $result['title'] = trim($titles->item(0)->textContent);
        }
    }
    if (!$result['description']) {
        $result['description'] = modir_import_get_meta($xpath, 'og:description');
    }
    if (!$result['description']) {
        $result['description'] = modir_import_get_meta($xpath, 'description', 'name');
    }
    if (!$result['image']) {
        $og_images = modir_import_get_meta_all($xpath, 'og:image');
        if (!empty($og_images)) {
            $result['image']   = array_shift($og_images);
            $result['gallery'] = array_merge($result['gallery'], $og_images);
        }
    }

    if (empty($result['attributes'])) {
        $rows = $xpath->query('//table[contains(@class,"woocommerce-product-attributes") or contains(@class,"shop_attributes")]//tr');
        foreach ($rows as $row) {
            $th = $xpath->query('.//th', $row);
            $td = $xpath->query('.//td', $row);
            if ($th->length && $td->length) {
                $name  = trim(wp_strip_all_tags($th->item(0)->textContent));
                $value = trim(wp_strip_all_tags($td->item(0)->textContent));
                if ($name && $value) {
                    $result['attributes'][] = array('name' => $name, 'value' => $value);
                }
            }
        }
    }

    $result['gallery']     = array_values(array_unique(array_filter($result['gallery'])));
    $result['title']       = trim($result['title']);
    $result['description'] = trim($result['description']);

    if (!$result['title'] && !$result['image'] && empty($result['gallery'])) {
        return new WP_Error('no_data', 'هیچ اطلاعات ساختاریافته‌ای (Schema.org/Open Graph) در این صفحه پیدا نشد.');
    }

    return $result;
}

function modir_import_get_meta($xpath, $key, $attr = 'property') {
    $nodes = $xpath->query('//meta[@' . $attr . '="' . $key . '"]/@content');
    return $nodes->length ? trim($nodes->item(0)->nodeValue) : '';
}

function modir_import_get_meta_all($xpath, $key, $attr = 'property') {
    $nodes = $xpath->query('//meta[@' . $attr . '="' . $key . '"]/@content');
    $out   = array();
    foreach ($nodes as $n) {
        $out[] = trim($n->nodeValue);
    }
    return array_values(array_unique(array_filter($out)));
}

/* -------------------------------------------------------------------- */
/* ساخت محصول ووکامرس — همیشه با همه بخش‌های موجود، بدون پرسیدن سوال       */
/* -------------------------------------------------------------------- */

function modir_import_create_product($data, $overrides = array()) {
    if (empty($data['title']) && empty($data['image']) && empty($data['gallery'])) {
        return new WP_Error('empty', 'اطلاعات کافی برای ساخت محصول دریافت نشد.');
    }

    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $title = !empty($data['title']) ? $data['title'] : 'محصول وارد شده';

    $product = new WC_Product_Simple();
    $product->set_name($title);
    $product->set_status('draft'); // برای بازبینی قبل از انتشار؛ همیشه پیش‌نویس ساخته می‌شود

    if (!empty($data['description'])) {
        $product->set_description(wp_kses_post($data['description']));
    }

    // قیمت: اگر کاربر عدد ثابتی وارد کرده، همیشه آن اولویت دارد؛ وگرنه همان
    // قیمت دریافتی از سایت مبدأ (اگر معتبر بود) استفاده می‌شود.
    if (!empty($overrides['price']) && is_numeric($overrides['price'])) {
        $product->set_regular_price(wc_format_decimal($overrides['price']));
    } elseif (!empty($data['price']) && is_numeric($data['price'])) {
        $product->set_regular_price(wc_format_decimal($data['price']));
    }

    // وضعیت موجودی دلخواه
    if (!empty($overrides['stock']) && in_array($overrides['stock'], array('instock', 'outofstock'), true)) {
        $product->set_manage_stock(false);
        $product->set_stock_status($overrides['stock']);
    }

    if (!empty($data['source_url'])) {
        $product->update_meta_data('_modir_import_source_url', esc_url_raw($data['source_url']));
    }

    // دسته‌بندی دلخواه
    if (!empty($overrides['category_id'])) {
        $product->set_category_ids(array((int) $overrides['category_id']));
    }

    $product_id = $product->save();
    if (!$product_id) {
        return new WP_Error('save_failed', 'ساخت محصول ناموفق بود.');
    }

    $warnings    = array();
    $image_count = 0;

    if (!empty($data['image'])) {
        $att_id = modir_import_sideload_image($data['image'], $product_id, $title);
        if (is_wp_error($att_id)) {
            $warnings[] = 'تصویر شاخص دانلود نشد.';
        } else {
            set_post_thumbnail($product_id, $att_id);
            $image_count++;
        }
    }

    if (!empty($data['gallery'])) {
        $gallery_ids = array();
        foreach ($data['gallery'] as $img_url) {
            $att_id = modir_import_sideload_image($img_url, $product_id, $title);
            if (is_wp_error($att_id)) {
                continue;
            }
            $gallery_ids[] = $att_id;
            $image_count++;
        }
        if (!empty($gallery_ids)) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
        }
    }

    if (!empty($data['attributes'])) {
        $wc_attributes = array();
        $position      = 0;
        foreach ($data['attributes'] as $attr) {
            if (empty($attr['name']) || !isset($attr['value'])) {
                continue;
            }
            $a = new WC_Product_Attribute();
            $a->set_id(0);
            $a->set_name(sanitize_text_field($attr['name']));
            $a->set_options(array(sanitize_text_field($attr['value'])));
            $a->set_position($position++);
            $a->set_visible(true);
            $a->set_variation(false);
            $wc_attributes[] = $a;
        }
        if (!empty($wc_attributes)) {
            $product = wc_get_product($product_id);
            $product->set_attributes($wc_attributes);
            $product->save();
        }
    }

    return array(
        'product_id'  => $product_id,
        'edit_url'    => get_edit_post_link($product_id, ''),
        'title'       => $title,
        'image_count' => $image_count,
        'has_price'   => !empty($data['price']),
        'warnings'    => $warnings,
        'source'      => $data['source'] ?? 'generic',
    );
}

function modir_import_sideload_image($image_url, $post_id, $desc = '') {
    if (!$image_url) {
        return new WP_Error('no_url', 'لینک تصویر خالی است.');
    }

    $tmp = download_url($image_url, 20);
    if (is_wp_error($tmp)) {
        return $tmp;
    }

    $file_array = array(
        'name'     => sanitize_file_name(basename(wp_parse_url($image_url, PHP_URL_PATH)) ?: 'image.jpg'),
        'tmp_name' => $tmp,
    );
    if (!pathinfo($file_array['name'], PATHINFO_EXTENSION)) {
        $file_array['name'] .= '.jpg';
    }

    $att_id = media_handle_sideload($file_array, $post_id, $desc);
    if (is_wp_error($att_id)) {
        @unlink($tmp);
        return $att_id;
    }

    return $att_id;
}

/* ======================================================================
 * صفحه ادمین — یک فیلد لینک، یک دکمه، بدون هیچ سوال دیگری
 * ====================================================================== */

function modir_import_render_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $categories = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
    ?>
    <div class="modir-import-form">
        <textarea id="modir-import-urls" rows="6" class="large-text" placeholder="https://www.digikala.com/product/dkp-.../&#10;https://www.digikala.com/product/dkp-.../&#10;https://example-shop.com/product/..."></textarea>

        <div class="modir-filter-row" style="margin-top:12px;">
            <div class="modir-filter-field">
                <label>دسته‌بندی (اختیاری)</label>
                <select id="modir-import-category" class="modir-pill-select">
                    <option value="">بدون دسته‌بندی</option>
                    <?php foreach ($categories as $cat) : ?>
                        <option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modir-filter-field">
                <label>قیمت ثابت برای همه (اختیاری)</label>
                <input type="number" step="0.01" min="0" id="modir-import-price" placeholder="خالی = قیمت دریافتی از سایت مبدأ" style="width:220px;">
            </div>
            <div class="modir-filter-field">
                <label>وضعیت موجودی (اختیاری)</label>
                <select id="modir-import-stock" class="modir-pill-select">
                    <option value="">پیش‌فرض (موجود)</option>
                    <option value="instock">موجود</option>
                    <option value="outofstock">ناموجود</option>
                </select>
            </div>
        </div>
        <p class="description">این سه گزینه دلخواه هستند و روی همه‌ی لینک‌های این دسته اعمال می‌شوند؛ اگر خالی بگذاری، همان مقدار دریافتی از سایت مبدأ (یا پیش‌فرض ووکامرس) استفاده می‌شود.</p>

        <button type="button" class="button button-primary modir-btn" id="modir-import-auto-btn">افزودن محصولات به فروشگاه</button>
    </div>

    <div id="modir-import-progress" class="modir-import-progress" style="display:none;"></div>
    <div id="modir-import-status"></div>
    <?php
}

/**
 * برای لینک‌های قدیمی/بوکمارک‌شده: مستقیم صفحه محصولات را با تب «افزودن
 * محصول» فعال نمایش می‌دهد، چون این ماژول به آنجا منتقل شده است.
 */
function modir_render_import_page() {
    $_GET['tab'] = 'import';
    modir_render_products_page();
}
