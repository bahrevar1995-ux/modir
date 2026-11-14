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
    add_action('wp_ajax_modir_import_from_json', 'modir_import_ajax_from_json');
    add_action('admin_init', 'modir_import_maybe_save_proxy');
    add_action('http_api_curl', 'modir_import_apply_proxy', 10, 3);
}

/* -------------------------------------------------------------------- */
/* پروکسی اختیاری برای درخواست‌های دیجی‌کالا                              */
/* (وقتی دیجی‌کالا IP سرور هاست را مسدود کرده باشد)                       */
/* -------------------------------------------------------------------- */

function modir_import_get_proxy() {
    return trim((string) get_option('modir_import_proxy', ''));
}

function modir_import_apply_proxy($handle, $args, $url) {
    $proxy = modir_import_get_proxy();
    if (!$proxy || !is_string($url)) {
        return;
    }
    $host = (string) wp_parse_url($url, PHP_URL_HOST);
    if (!preg_match('/(^|\.)(digikala\.com|dkstatics\.com|digikala\.dev)$/i', $host) && false === stripos($host, 'dkstatics')) {
        return;
    }
    $p = wp_parse_url($proxy);
    if (empty($p['host'])) {
        return;
    }
    $scheme = isset($p['scheme']) ? strtolower($p['scheme']) : 'http';
    $port   = isset($p['port']) ? (int) $p['port'] : 8080;
    curl_setopt($handle, CURLOPT_PROXY, $p['host']);
    curl_setopt($handle, CURLOPT_PROXYPORT, $port);
    if (0 === strpos($scheme, 'socks5')) {
        curl_setopt($handle, CURLOPT_PROXYTYPE, defined('CURLPROXY_SOCKS5_HOSTNAME') ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_SOCKS5);
    }
    if (!empty($p['user'])) {
        curl_setopt($handle, CURLOPT_PROXYUSERPWD, rawurldecode($p['user']) . ':' . rawurldecode(isset($p['pass']) ? $p['pass'] : ''));
    }
}

function modir_import_maybe_save_proxy() {
    if (empty($_POST['modir_import_proxy_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_import_proxy_nonce'])), 'modir_import_proxy_save')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $proxy = isset($_POST['modir_import_proxy']) ? trim(sanitize_text_field(wp_unslash($_POST['modir_import_proxy']))) : '';
    if ($proxy && !preg_match('#^(https?|socks5h?)://#i', $proxy)) {
        $proxy = 'http://' . $proxy;
    }
    update_option('modir_import_proxy', $proxy, false);

    $args = array('page' => 'modir', 'tab' => 'import', 'proxy_saved' => '1');
    if (!empty($_POST['modir_import_proxy_test'])) {
        $r = wp_remote_get('https://www.digikala.com/', array('timeout' => 20, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36'));
        $args['proxy_test'] = is_wp_error($r) ? 'err' : (int) wp_remote_retrieve_response_code($r);
    }
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
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
/* AJAX: ساخت محصول از داده‌ای که بوکمارکلت در مرورگر کاربر خوانده است       */
/* (چون سیستم ضدربات دیجی‌کالا درخواست‌های سرور را قبول نمی‌کند)            */
/* -------------------------------------------------------------------- */

function modir_import_ajax_from_json() {
    check_ajax_referer('modir_import_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }

    $raw     = isset($_POST['payload']) ? (string) wp_unslash($_POST['payload']) : '';
    $payload = json_decode($raw, true);
    $overrides_pre = null;
    if (is_array($payload) && isset($payload['data']) && is_array($payload['data'])) {
        // پاسخ خام API دیجی‌کالا (کپی‌شده از DevTools ← Network ← Response)
        $mapped = modir_import_digikala_map($payload, '');
        if (!is_wp_error($mapped)) {
            $payload = array(
                'title'       => $mapped['title'],
                'description' => $mapped['description'],
                'images'      => array_merge(array($mapped['image']), $mapped['gallery']),
                'url'         => isset($_POST['page_url']) ? esc_url_raw(wp_unslash($_POST['page_url'])) : '',
                '_price'      => $mapped['price'],
                '_attrs'      => $mapped['attributes'],
            );
        }
    } elseif (!is_array($payload)) {
        $payload = modir_import_parse_page_html($raw);
    }
    if (!is_array($payload) || (empty($payload['title']) && empty($payload['images']))) {
        wp_send_json_error(array('message' => 'اطلاعات محصولی در متن Paste‌شده پیدا نشد. مطمئن شو پاسخ (Response) درخواست اطلاعات محصول را از DevTools ← Network کپی کرده‌ای.'));
    }

    // فقط تصاویر از دامنه‌های خود دیجی‌کالا پذیرفته می‌شوند (جلوگیری از دانلود از آدرس دلخواه)
    $images = array();
    foreach ((array) ($payload['images'] ?? array()) as $img) {
        $img  = esc_url_raw(is_string($img) ? $img : '');
        $host = (string) wp_parse_url($img, PHP_URL_HOST);
        if ($img && 'https' === wp_parse_url($img, PHP_URL_SCHEME) && preg_match('/(^|\.)(digikala\.com|dkstatics[a-z0-9-]*\.com)$/i', $host)) {
            $images[] = $img;
        }
    }
    $images = array_values(array_unique($images));

    $title = sanitize_text_field((string) ($payload['title'] ?? ''));
    $title = preg_replace('/^\s*خرید\s*و\s*قیمت\s*/u', '', $title);
    $title = trim(preg_replace('/\s*[|\-–]\s*دیجی.?کالا\s*$/u', '', $title));

    $data = array(
        'title'       => $title,
        'description' => wp_kses_post((string) ($payload['description'] ?? '')),
        'price'       => isset($payload['_price']) ? (string) $payload['_price'] : '',
        'image'       => !empty($images[0]) ? $images[0] : '',
        'gallery'     => array_slice($images, 1),
        'attributes'  => isset($payload['_attrs']) && is_array($payload['_attrs']) ? $payload['_attrs'] : array(),
        'source_url'  => esc_url_raw((string) ($payload['url'] ?? '')),
        'source'      => 'digikala-browser',
    );

    $overrides = array(
        'category_id' => isset($_POST['category_id']) ? absint($_POST['category_id']) : 0,
        'price'       => isset($_POST['price']) ? sanitize_text_field(wp_unslash($_POST['price'])) : '',
        'stock'       => isset($_POST['stock']) ? sanitize_key($_POST['stock']) : '',
    );

    $result = modir_import_create_product($data, $overrides);
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }
    wp_send_json_success($result);
}

/**
 * کد HTML صفحه محصول (که کاربر از مرورگر خودش با Ctrl+U کپی کرده) را می‌خواند:
 * اول Schema.org JSON-LD، بعد متاتگ‌های Open Graph. فقط اگر هیچ‌کدام عکسی
 * نداشت، از آدرس تصاویر محصول داخل متن صفحه استفاده می‌شود.
 */
function modir_import_parse_page_html($html) {
    if (!is_string($html) || strlen($html) < 200 || false === strpos($html, '<')) {
        return null;
    }

    $title = '';
    $desc  = '';
    $imgs  = array();

    if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks)) {
        foreach ($blocks[1] as $block) {
            $j = json_decode(html_entity_decode(trim($block), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (!is_array($j)) {
                $j = json_decode(trim($block), true);
            }
            if (!is_array($j)) {
                continue;
            }
            $nodes = isset($j['@graph']) ? $j['@graph'] : (isset($j[0]) ? $j : array($j));
            foreach ($nodes as $o) {
                if (!is_array($o) || empty($o['@type'])) {
                    continue;
                }
                $types = (array) $o['@type'];
                if (!in_array('Product', $types, true)) {
                    continue;
                }
                if (!$title && !empty($o['name'])) {
                    $title = (string) $o['name'];
                }
                if (!$desc && !empty($o['description'])) {
                    $desc = (string) $o['description'];
                }
                foreach ((array) ($o['image'] ?? array()) as $im) {
                    $imgs[] = is_array($im) ? (string) ($im['url'] ?? '') : (string) $im;
                }
            }
        }
    }

    $meta = function ($prop) use ($html) {
        if (preg_match('#<meta[^>]+(?:property|name)=["\']' . preg_quote($prop, '#') . '["\'][^>]*content=["\']([^"\']*)["\']#i', $html, $m)
            || preg_match('#<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']' . preg_quote($prop, '#') . '["\']#i', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    };

    if (!$title) {
        $title = $meta('og:title');
    }
    if (!$desc) {
        $desc = $meta('og:description') ?: $meta('description');
    }
    $og_img = $meta('og:image');
    if ($og_img) {
        $imgs[] = $og_img;
    }

    if (empty(array_filter($imgs)) && preg_match_all('#https://dkstatics-public\.digikala\.com/digikala-products/[A-Za-z0-9._/\-]+\.(?:jpe?g|png|webp)#i', $html, $mm)) {
        $imgs = array_slice(array_values(array_unique($mm[0])), 0, 6);
    }

    $imgs = array_values(array_unique(array_filter(array_map(function ($u) {
        return preg_replace('/\?.*$/', '', (string) $u);
    }, $imgs))));

    $url = '';
    if (preg_match('#<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']#i', $html, $m)) {
        $url = $m[1];
    }

    return array('title' => $title, 'description' => $desc, 'images' => array_slice($imgs, 0, 8), 'url' => $url);
}

function modir_import_bookmarklet_code() {
    $js = <<<'JS'
(function(){try{var t=function(s){return (s||'').toString().trim()};var ld=null;document.querySelectorAll('script[type="application/ld+json"]').forEach(function(s){try{var j=JSON.parse(s.textContent);var a=Array.isArray(j)?j:(j['@graph']||[j]);a.forEach(function(o){if(o&&!ld&&(o['@type']==='Product'||(Array.isArray(o['@type'])&&o['@type'].indexOf('Product')>-1)))ld=o})}catch(e){}});var m=function(p){var e=document.querySelector('meta[property="'+p+'"],meta[name="'+p+'"]');return e?e.content:''};var title=t(ld&&ld.name)||t(m('og:title'))||t((document.querySelector('h1')||{}).textContent);var imgs=[];var add=function(u){if(u&&typeof u==='string'){u=u.split('?')[0];if(imgs.indexOf(u)<0)imgs.push(u)}};if(ld&&ld.image){[].concat(ld.image).forEach(function(i){add(typeof i==='string'?i:(i&&i.url))})}add(m('og:image'));document.querySelectorAll('img').forEach(function(i){var s=i.currentSrc||i.src||'';if(/dkstatics-public\.digikala\.com\/digikala-products\//.test(s))add(s)});var d=JSON.stringify({title:title,description:t(ld&&ld.description)||t(m('og:description')||m('description')),images:imgs.slice(0,8),url:location.href});if(!title&&!imgs.length){alert('اطلاعات محصول در این صفحه پیدا نشد. مطمئن شو روی صفحه خود محصول هستی.');return}navigator.clipboard.writeText(d).then(function(){alert('اطلاعات محصول کپی شد ('+imgs.length+' تصویر). حالا در مدیر Paste کن.')},function(){prompt('این متن را کپی کن:',d)})}catch(e){alert('خطا: '+e.message)}})();
JS;
    return 'javascript:' . rawurlencode(trim($js));
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

    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    // دیجی‌کالا مستند رسمی ندارد و گاهی مسیر/محدودیت API را تغییر می‌دهد، پس
    // چند آدرس با هدرهای شبیه مرورگر امتحان می‌شود و نتیجه هر کدام ثبت می‌شود.
    $endpoints = array(
        'https://api.digikala.com/v2/product/' . $product_id . '/',
        'https://api.digikala.com/v2/product/' . $product_id,
        'https://api.digikala.com/v1/product/' . $product_id . '/',
    );
    $headers = array(
        'Accept'          => 'application/json, text/plain, */*',
        'Accept-Language' => 'fa-IR,fa;q=0.9,en;q=0.8',
        'Origin'          => 'https://www.digikala.com',
        'Referer'         => 'https://www.digikala.com/product/dkp-' . $product_id . '/',
        'x-web-optimize-response' => '1',
        'x-web-client'    => 'desktop',
        'x-web-client-id' => 'web',
    );

    $attempts      = array();
    $first_snippet = '';
    $code          = 0;
    $body     = '';
    foreach ($endpoints as $api_url) {
        $response = wp_remote_get($api_url, array(
            'timeout'    => 25,
            'user-agent' => $ua,
            'headers'    => $headers,
        ));
        if (is_wp_error($response)) {
            $attempts[] = 'خطای اتصال: ' . $response->get_error_message();
            continue;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $attempts[] = $code;
        if (!$first_snippet && $code >= 400) {
            $first_snippet = mb_substr(trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($body))), 0, 160);
        }
        if ($code >= 200 && $code < 300 && $body) {
            break;
        }
        $body = '';
    }

    if (!$body) {
        // راه دوم: خود صفحه محصول (داده‌های ساخت‌یافته Schema.org / Open Graph)
        $page = modir_import_fetch_from_generic_site($url);
        // فقط وقتی پذیرفته می‌شود که واقعاً اطلاعات «همین محصول» باشد: هم عکس
        // داشته باشد و هم عنوانش عنوان کلی سایت دیجی‌کالا (صفحه مسدودسازی/اصلی)
        // نباشد؛ وگرنه محصول خراب و بی‌عکس ساخته می‌شود.
        $page_ok = false;
        if (!is_wp_error($page) && !empty($page['image']) && !empty($page['title'])) {
            $generic = (false !== mb_stripos($page['title'], 'فروشگاه اینترنتی دیجی') && false === mb_stripos($page['title'], 'خرید'))
                || trim($page['title']) === 'دیجی‌کالا';
            $page_ok = !$generic;
        }
        if ($page_ok) {
            $page['source'] = 'digikala';
            return $page;
        }
        $page_info = is_wp_error($page) ? $page->get_error_message() : 'صفحه‌ای که دریافت شد اطلاعات این محصول را نداشت (احتمالاً صفحه محدودیت دسترسی)';

        return new WP_Error(
            'fetch_status',
            'دیجی‌کالا اطلاعات این محصول را نداد (کدهای API: ' . implode('، ', $attempts) . '). صفحه محصول هم خوانده نشد: ' . $page_info
            . ($first_snippet ? ' [متن پاسخ دیجی‌کالا: ' . $first_snippet . ']' : '')
            . ' — بررسی کن لینک محصول هنوز در دیجی‌کالا باز می‌شود؛ اگر باز می‌شود، احتمالاً دیجی‌کالا دسترسی IP سرور شما را محدود کرده (معمولاً سرورهای خارج از ایران) و باید از هاست ایران یا پروکسی استفاده شود.'
        );
    }

    $json = json_decode($body, true);
    if (!$json) {
        return new WP_Error('bad_json', 'پاسخ دریافتی از دیجی‌کالا قابل خواندن نبود.');
    }

    return modir_import_digikala_map($json, $url);
}

/**
 * پاسخ JSON خام API دیجی‌کالا را به داده‌ی محصول تبدیل می‌کند.
 */
function modir_import_digikala_map($json, $url = '') {
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
    <?php if (isset($_GET['proxy_saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>تنظیم پروکسی ذخیره شد.
            <?php if (isset($_GET['proxy_test'])) :
                $t = sanitize_text_field(wp_unslash($_GET['proxy_test'])); ?>
                نتیجه تست اتصال به دیجی‌کالا: <strong><?php echo ('err' === $t) ? 'عدم اتصال' : 'کد ' . esc_html($t); ?></strong>
                <?php echo ('200' === $t) ? '— اتصال برقرار است.' : '— اتصال موفق نیست.'; ?>
            <?php endif; ?>
        </p></div>
    <?php endif; ?>
    <details style="margin-bottom:14px;">
        <summary style="cursor:pointer;font-weight:600;">تنظیم پروکسی برای دیجی‌کالا (فقط اگر خطای ۴۰۴ یا ۴۰۳ می‌گیری)</summary>
        <form method="post" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <?php wp_nonce_field('modir_import_proxy_save', 'modir_import_proxy_nonce'); ?>
            <input type="text" name="modir_import_proxy" value="<?php echo esc_attr(modir_import_get_proxy()); ?>" class="regular-text" style="direction:ltr;" placeholder="http://user:pass@host:port  یا  socks5://host:port">
            <button type="submit" class="button modir-btn">ذخیره</button>
            <button type="submit" name="modir_import_proxy_test" value="1" class="button modir-btn">ذخیره و تست اتصال</button>
        </form>
        <p class="description">فقط درخواست‌های مربوط به دیجی‌کالا از این پروکسی عبور می‌کنند. برای حذف، فیلد را خالی ذخیره کن.</p>
    </details>
    <div class="modir-import-form" style="margin-bottom:18px;padding:14px;border:1px solid #e2e4e7;border-radius:12px;background:#fff;">
        <strong>ایمپورت از دیجی‌کالا (وقتی ایمپورت با لینک خطا می‌دهد)</strong>
        <p style="margin:8px 0;"><b>روش کپی از مرورگر:</b> صفحه‌ی محصول را در دیجی‌کالا باز کن، <code>F12</code> ← تب <code>Network</code> ← فیلتر <code>Fetch/XHR</code> ← صفحه را Refresh کن. روی درخواستی که آدرسش با شماره محصول (مثلاً <code>490390/?_rch=…</code>) تمام می‌شود و حجمش حدود ۸ کیلوبایت یا بیشتر است کلیک کن ← تب <code>Response</code> ← <code>Ctrl+A</code> و <code>Ctrl+C</code> و در کادر پایین Paste کن. (کوکی یا هدر کپی نکن؛ فقط Response.)</p>
        <details style="margin:0 0 8px;"><summary style="cursor:pointer;">روش دوم: بوکمارکلت (اختیاری)</summary>
        <ol style="margin:8px 0 10px 0;">
            <li>این دکمه را بگیر و روی نوار بوکمارک مرورگرت رها کن:
                <a class="button" href="<?php echo esc_attr(modir_import_bookmarklet_code()); ?>" onclick="alert('این دکمه را با ماوس بگیر و روی نوار بوکمارک مرورگر بینداز؛ کلیک کردن روی آن اینجا کاری نمی‌کند.');return false;">📌 ایمپورت به مدیر</a>
            </li>
            <li>صفحه‌ی محصول را در دیجی‌کالا باز کن و روی بوکمارک بزن؛ اطلاعات محصول کپی می‌شود.</li>
            <li>همین‌جا در کادر زیر Paste کن و دکمه را بزن.</li>
        </ol></details>
        <textarea id="modir-import-paste" rows="4" class="large-text" style="direction:ltr;" placeholder="اینجا Paste کن (کد کامل صفحه محصول، یا خروجی بوکمارکلت)"></textarea>
        <p class="description" style="margin-top:4px;">دسته‌بندی، قیمت ثابت و موجودی که پایین‌تر انتخاب می‌کنی روی این محصول هم اعمال می‌شود.</p>
        <p style="margin:8px 0 0;"><button type="button" class="button button-primary modir-btn" id="modir-import-paste-btn">ساخت محصول از اطلاعات Paste‌شده</button></p>
        <div id="modir-import-paste-status"></div>
        <p class="description">قیمت از صفحه خوانده نمی‌شود (واحدش مطمئن نیست)؛ آن را در «قیمت ثابت» وارد کن یا بعداً در لیست محصولات بنویس. محصول همیشه پیش‌نویس ساخته می‌شود.</p>
    </div>
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
