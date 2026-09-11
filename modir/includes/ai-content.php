<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «تولید مقاله با هوش مصنوعی»: با استفاده از همان سرویس هوش مصنوعی
 * که برای دستیار گفتگو وصل شده، مقاله می‌نویسد، منتشر می‌کند، و در صورت
 * امکان (فقط OpenAI) یک تصویر شاخص هم خودکار تولید و بارگذاری می‌کند.
 * ==========================================================================
 */

function modir_ai_content_init() {
    add_action('admin_init', 'modir_ai_content_maybe_save_settings');
    add_action('wp_ajax_modir_ai_content_generate', 'modir_ai_content_ajax_generate');
    add_action('modir_ai_content_cron_hook', 'modir_ai_content_generate_article');
    add_action('modir_ai_content_bg_image_hook', 'modir_ai_content_generate_image_background', 10, 2);
    add_action('admin_enqueue_scripts', 'modir_ai_content_admin_assets');
}

function modir_ai_content_admin_assets($hook) {
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    $tab  = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
    if ('modir-marketing' !== $page || 'ai_content' !== $tab) {
        return;
    }
    wp_enqueue_script('modir-ai-content-js', MODIR_URL . 'assets/js/ai-content.js', array('jquery'), MODIR_VERSION, true);
    wp_localize_script('modir-ai-content-js', 'ModirAiContent', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('modir_ai_content_nonce'),
    ));
}

/* -------------------------------------------------------------------- */
/* تنظیمات                                                               */
/* -------------------------------------------------------------------- */

function modir_ai_content_get_settings() {
    return wp_parse_args(get_option('modir_ai_content_settings', array()), array(
        'enabled'        => 0,
        'topics'         => '',
        'tone'           => 'دوستانه و قابل‌فهم، مثل یک متخصص که ساده توضیح می‌دهد',
        'rules'          => 'از عنوان‌بندی (H2, H3) استفاده کن. مقاله باید برای سئو مناسب باشد. ادعای پزشکی یا تضمین‌شده نکن.',
        'length_words'   => 600,
        'category_id'    => 0,
        'publish_status' => 'draft',
        'generate_image' => 1,
        'schedule'       => 'manual',
    ));
}

function modir_ai_content_maybe_save_settings() {
    if (empty($_POST['modir_ai_content_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_ai_content_nonce'])), 'modir_ai_content_save')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $settings = array(
        'enabled'        => !empty($_POST['enabled']) ? 1 : 0,
        'topics'         => isset($_POST['topics']) ? sanitize_textarea_field(wp_unslash($_POST['topics'])) : '',
        'tone'           => isset($_POST['tone']) ? sanitize_textarea_field(wp_unslash($_POST['tone'])) : '',
        'rules'          => isset($_POST['rules']) ? sanitize_textarea_field(wp_unslash($_POST['rules'])) : '',
        'length_words'   => isset($_POST['length_words']) ? max(150, min(3000, absint($_POST['length_words']))) : 600,
        'category_id'    => isset($_POST['category_id']) ? absint($_POST['category_id']) : 0,
        'publish_status' => ('publish' === ($_POST['publish_status'] ?? '')) ? 'publish' : 'draft',
        'generate_image' => !empty($_POST['generate_image']) ? 1 : 0,
        'schedule'       => in_array($_POST['schedule'] ?? '', array('manual', 'daily', 'weekly'), true) ? $_POST['schedule'] : 'manual',
    );

    update_option('modir_ai_content_settings', $settings);
    modir_ai_content_reschedule($settings['schedule']);

    wp_safe_redirect(add_query_arg(array('page' => 'modir-marketing', 'tab' => 'ai_content', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

function modir_ai_content_reschedule($schedule) {
    wp_clear_scheduled_hook('modir_ai_content_cron_hook');
    if ('daily' === $schedule) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'modir_ai_content_cron_hook');
    } elseif ('weekly' === $schedule) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'weekly', 'modir_ai_content_cron_hook');
    }
}

/* -------------------------------------------------------------------- */
/* انتخاب موضوع                                                          */
/* -------------------------------------------------------------------- */

function modir_ai_content_pick_topic($topics_raw) {
    $lines = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $topics_raw))));
    if (count($lines) > 1) {
        return $lines[array_rand($lines)];
    }
    return $topics_raw;
}

/* -------------------------------------------------------------------- */
/* تولید تصویر شاخص (فقط برای OpenAI پشتیبانی می‌شود)                     */
/* -------------------------------------------------------------------- */

function modir_ai_content_generate_image_data($chat_settings, $api_key, $prompt) {
    if ('openai' !== $chat_settings['provider']) {
        return array('type' => null, 'data' => null, 'error' => 'تولید تصویر فقط وقتی کار می‌کند که provider دستیار گفتگو روی OpenAI باشد (الان: ' . $chat_settings['provider'] . ').');
    }

    $base     = !empty($chat_settings['base_url']) ? rtrim($chat_settings['base_url'], '/') : 'https://api.openai.com/v1';
    $response = wp_remote_post($base . '/images/generations', array(
        'headers' => array('Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'),
        // مدل‌های جدید تولید تصویر OpenAI: dall-e-3 در ۴ مارس ۲۰۲۶ بازنشسته
        // شد؛ جایگزین رسمی آن سری gpt-image است.
        'body'    => wp_json_encode(array('model' => 'gpt-image-1', 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024')),
        'timeout' => 90,
    ));

    if (is_wp_error($response)) {
        return array('type' => null, 'data' => null, 'error' => 'خطای شبکه هنگام تولید تصویر: ' . $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300) {
        $msg = $data['error']['message'] ?? ('کد پاسخ ' . $code);
        return array('type' => null, 'data' => null, 'error' => 'خطای سرویس تولید تصویر: ' . $msg);
    }

    // مدل‌های سری gpt-image همیشه داده‌ی تصویر را به‌صورت base64 برمی‌گردانند
    // (نه لینک)؛ برای سازگاری با مدل‌های قدیمی‌تر یا سرویس‌های سازگار با
    // OpenAI که ممکن است هنوز لینک برگردانند، هر دو حالت پشتیبانی می‌شود.
    if (!empty($data['data'][0]['b64_json'])) {
        return array('type' => 'b64', 'data' => $data['data'][0]['b64_json'], 'error' => '');
    }
    if (!empty($data['data'][0]['url'])) {
        return array('type' => 'url', 'data' => $data['data'][0]['url'], 'error' => '');
    }

    return array('type' => null, 'data' => null, 'error' => 'سرویس تولید تصویر پاسخ معتبری برنگرداند.');
}

function modir_ai_content_attach_featured_image($post_id, $image_result, $desc) {
    if (empty($image_result['type']) || empty($image_result['data'])) {
        return array('ok' => false, 'error' => $image_result['error'] ?: 'داده‌ی تصویری برای بارگذاری وجود نداشت.');
    }

    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    if ('url' === $image_result['type']) {
        $attachment_id = media_sideload_image($image_result['data'], $post_id, $desc, 'id');
        if (is_wp_error($attachment_id)) {
            return array('ok' => false, 'error' => 'خطا در بارگذاری تصویر روی سایت: ' . $attachment_id->get_error_message());
        }
    } else {
        // حالت base64 (مدل‌های جدید gpt-image): چون لینکی وجود ندارد، خود
        // داده‌ی تصویر را مستقیم به‌عنوان فایل رسانه ذخیره می‌کنیم.
        $image_binary = base64_decode($image_result['data']);
        if (!$image_binary) {
            return array('ok' => false, 'error' => 'داده‌ی تصویر base64 نامعتبر بود.');
        }

        $filename = 'modir-ai-' . time() . '.png';
        $upload   = wp_upload_bits($filename, null, $image_binary);
        if (!empty($upload['error'])) {
            return array('ok' => false, 'error' => 'خطا در ذخیره‌ی فایل تصویر: ' . $upload['error']);
        }

        $filetype   = wp_check_filetype($filename, null);
        $attach_id  = wp_insert_attachment(array(
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name($filename),
            'post_status'    => 'inherit',
        ), $upload['file'], $post_id);

        if (is_wp_error($attach_id)) {
            return array('ok' => false, 'error' => 'خطا در ثبت تصویر: ' . $attach_id->get_error_message());
        }

        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);
        $attachment_id = $attach_id;
    }

    set_post_thumbnail($post_id, $attachment_id);
    // متن جایگزین تصویر برای سئو و دسترس‌پذیری
    update_post_meta($attachment_id, '_wp_attachment_image_alt', wp_strip_all_tags($desc));

    return array('ok' => true, 'error' => '');
}

/* -------------------------------------------------------------------- */
/* گردآوری لینک‌های داخلی مرتبط برای درج طبیعی داخل متن مقاله               */
/* -------------------------------------------------------------------- */

function modir_ai_content_gather_internal_links($topic) {
    $links = array();

    if (function_exists('wc_get_products')) {
        $products = wc_get_products(array('status' => 'publish', 'limit' => 5, 's' => $topic, 'return' => 'objects'));
        if (empty($products)) {
            $products = wc_get_products(array('status' => 'publish', 'limit' => 5, 'orderby' => 'popularity', 'return' => 'objects'));
        }
        foreach ($products as $p) {
            $links[] = '- [محصول] ' . $p->get_name() . ' → ' . get_permalink($p->get_id());
        }
    }

    $recent_posts = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 5, 'orderby' => 'date', 'order' => 'DESC'));
    foreach ($recent_posts as $p) {
        $links[] = '- [مقاله دیگر] ' . $p->post_title . ' → ' . get_permalink($p->ID);
    }

    if (function_exists('get_permalink')) {
        $shop_page_id = function_exists('wc_get_page_id') ? wc_get_page_id('shop') : 0;
        if ($shop_page_id > 0) {
            $links[] = '- [صفحه فروشگاه] → ' . get_permalink($shop_page_id);
        }
    }

    return implode("\n", $links);
}

/* -------------------------------------------------------------------- */
/* تشخیص افزونه سئوی فعال و ذخیره متادیتای سئو                            */
/* -------------------------------------------------------------------- */

function modir_ai_content_save_seo_meta($post_id, $meta_description, $focus_keyword) {
    if (!$meta_description && !$focus_keyword) {
        return;
    }

    // Yoast SEO
    if (defined('WPSEO_VERSION')) {
        if ($meta_description) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_description);
        }
        if ($focus_keyword) {
            update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_keyword);
        }
        return;
    }

    // Rank Math
    if (class_exists('RankMath')) {
        if ($meta_description) {
            update_post_meta($post_id, 'rank_math_description', $meta_description);
        }
        if ($focus_keyword) {
            update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keyword);
        }
        return;
    }

    // All in One SEO
    if (defined('AIOSEO_VERSION') && $meta_description) {
        update_post_meta($post_id, '_aioseo_description', $meta_description);
    }
}

/* -------------------------------------------------------------------- */
/* تولید و انتشار مقاله                                                  */
/* -------------------------------------------------------------------- */

function modir_ai_content_generate_article() {
    $settings = modir_ai_content_get_settings();
    if (empty($settings['topics'])) {
        return array('ok' => false, 'error' => 'اول باید موضوعات مقاله رو تو تنظیمات وارد کنی.');
    }
    if (!function_exists('modir_chat_get_settings') || !function_exists('modir_chat_call_ai')) {
        return array('ok' => false, 'error' => 'ماژول دستیار گفتگو (که تنظیمات API از آن استفاده می‌شود) در دسترس نیست.');
    }

    $chat_settings = modir_chat_get_settings();
    $api_key       = modir_chat_get_api_key($chat_settings);
    if (!$api_key) {
        return array('ok' => false, 'error' => 'اول باید از تنظیمات دستیار گفتگو، یک کلید API معتبر وارد کنی.');
    }

    $topic          = modir_ai_content_pick_topic($settings['topics']);
    $internal_links = modir_ai_content_gather_internal_links($topic);

    $system_prompt = "تو یک نویسنده‌ی محتوای حرفه‌ای فارسی و متخصص سئو هستی. یک مقاله کامل و سئو-محور درباره‌ی موضوع داده‌شده بنویس.\n"
        . "لحن نوشتار: " . $settings['tone'] . "\n"
        . "قوانین: " . $settings['rules'] . "\n"
        . "طول تقریبی: " . $settings['length_words'] . " کلمه.\n\n"
        . "قوانین سئو (این‌ها را دقیق رعایت کن):\n"
        . "- کلمه کلیدی اصلی (همان موضوع یا نزدیک به آن) باید در عنوان، در جمله‌ی اول مقاله، و حداقل در یکی از تیترهای H2 بیاید.\n"
        . "- از تیترهای H2 و H3 برای بخش‌بندی منطقی استفاده کن (حداقل ۳ تیتر H2).\n"
        . "- پاراگراف‌ها کوتاه و خوانا باشند (۲ تا ۴ جمله).\n"
        . "- در انتها یک نتیجه‌گیری کوتاه بیاور.\n\n"
        . "لینک‌دهی داخلی: این‌ها لینک‌های واقعی داخل همین سایت هستند؛ حداقل ۲ و حداکثر ۴ مورد از آن‌ها را که واقعاً به متن مربوطند، به‌صورت طبیعی و در دل جمله‌ها (نه به‌شکل یک لیست جدا در انتها) با تگ <a href=\"آدرس\">متن لینک مرتبط با متن</a> در مقاله جای بده. اگر هیچ‌کدام واقعاً مرتبط نبودند، هیچ‌کدام را اضافه نکن:\n"
        . $internal_links . "\n\n"
        . "خروجی را دقیقاً به این فرمت بده، بدون هیچ متن اضافه قبل یا بعدش:\n"
        . "عنوان: [عنوان جذاب و سئو-محور مقاله]\n"
        . "---\n"
        . "توضیحات متا: [یک خلاصه ۱۵۰ تا ۱۶۰ کاراکتری شامل کلمه کلیدی اصلی، برای متا توضیحات گوگل]\n"
        . "---\n"
        . "کلمه کلیدی: [عبارت کلیدی اصلی مقاله، فقط ۲ تا ۴ کلمه]\n"
        . "---\n"
        . "[کل متن مقاله، با تگ‌های HTML ساده مثل <p>, <h2>, <h3>, <ul>, <li>, <a> برای ساختاردهی و لینک‌دهی]";

    $result = modir_chat_call_ai($chat_settings, $api_key, $system_prompt, array(), 'موضوع مقاله: ' . $topic);
    if (empty($result['ok'])) {
        return array('ok' => false, 'error' => $result['error'] ?? 'خطای نامشخص از سرویس هوش مصنوعی.');
    }

    $reply            = trim($result['reply']);
    $title            = $topic;
    $meta_description = '';
    $focus_keyword    = '';
    $content          = $reply;

    $parts = preg_split('/[\r\n]*-{2,}[\r\n]*/', $reply);
    if (count($parts) >= 4) {
        $title            = preg_replace('/^عنوان:\s*/u', '', trim($parts[0]));
        $meta_description = preg_replace('/^توضیحات متا:\s*/u', '', trim($parts[1]));
        $focus_keyword    = preg_replace('/^کلمه کلیدی:\s*/u', '', trim($parts[2]));
        $content          = trim(implode("\n", array_slice($parts, 3)));
    } elseif (preg_match('/^عنوان:\s*(.+?)\s*[\r\n]+-{2,}\s*[\r\n]+(.*)$/us', $reply, $m)) {
        // سازگاری با پاسخ‌های قدیمی‌تر بدون بخش سئو
        $title   = trim($m[1]);
        $content = trim($m[2]);
    }

    $post_id = wp_insert_post(array(
        'post_title'    => wp_strip_all_tags($title),
        'post_content'  => wp_kses_post($content),
        'post_excerpt'  => wp_strip_all_tags($meta_description),
        'post_status'   => $settings['publish_status'],
        'post_type'     => 'post',
        'post_category' => $settings['category_id'] ? array($settings['category_id']) : array(),
    ), true);

    if (is_wp_error($post_id)) {
        return array('ok' => false, 'error' => $post_id->get_error_message());
    }

    modir_ai_content_save_seo_meta($post_id, wp_strip_all_tags($meta_description), wp_strip_all_tags($focus_keyword));

    // تولید تصویر جدا و در پس‌زمینه انجام می‌شود (نه همین‌جا و بلافاصله)، چون
    // ممکن است طول بکشد و باعث timeout همین درخواست شود؛ این‌طوری مقاله
    // همیشه فوری منتشر می‌شود و تصویر هر وقت آماده شد به آن اضافه می‌شود.
    $image_pending = false;
    if (!empty($settings['generate_image'])) {
        wp_schedule_single_event(time() + 2, 'modir_ai_content_bg_image_hook', array($post_id, $title));
        $image_pending = true;
    }

    modir_ai_content_log_add(array(
        'time'          => current_time('mysql'),
        'post_id'       => $post_id,
        'title'         => $title,
        'status'        => $settings['publish_status'],
        'has_image'     => false,
        'image_error'   => '',
        'image_status'  => $image_pending ? 'pending' : 'skipped',
    ));

    return array(
        'ok'            => true,
        'post_id'       => $post_id,
        'title'         => $title,
        'edit_url'      => get_edit_post_link($post_id, ''),
        'view_url'      => get_permalink($post_id),
        'image_pending' => $image_pending,
    );
}

/**
 * تولید و پیوست تصویر شاخص، در یک درخواست کاملاً جداگانه (از طریق
 * wp-cron)، تا هیچ‌وقت باعث timeout شدن درخواست اصلی انتشار مقاله نشود.
 */
function modir_ai_content_generate_image_background($post_id, $title) {
    if (!function_exists('modir_chat_get_settings')) {
        return;
    }

    $chat_settings = modir_chat_get_settings();
    $api_key       = modir_chat_get_api_key($chat_settings);
    if (!$api_key) {
        modir_ai_content_log_update_image($post_id, false, 'کلید API در دسترس نیست.');
        return;
    }

    $image_prompt = 'یک تصویر شاخص حرفه‌ای، تمیز و بدون متن نوشتاری، مرتبط با مقاله‌ای درباره: ' . $title;
    $image_result = modir_ai_content_generate_image_data($chat_settings, $api_key, $image_prompt);

    if (empty($image_result['data'])) {
        modir_ai_content_log_update_image($post_id, false, $image_result['error']);
        return;
    }

    $attach_result = modir_ai_content_attach_featured_image($post_id, $image_result, $title);
    modir_ai_content_log_update_image($post_id, !empty($attach_result['ok']), $attach_result['error'] ?? '');
}

function modir_ai_content_log_add($entry) {
    $log = get_option('modir_ai_content_log', array());
    array_unshift($log, $entry);
    $log = array_slice($log, 0, 50);
    update_option('modir_ai_content_log', $log, false);
}

/**
 * وقتی تولید تصویر در پس‌زمینه تمام شد، همان ردیف لاگ مقاله (بر اساس
 * شناسه‌ی پست) را با نتیجه‌ی نهایی به‌روزرسانی می‌کند.
 */
function modir_ai_content_log_update_image($post_id, $has_image, $image_error) {
    $log = get_option('modir_ai_content_log', array());
    foreach ($log as &$entry) {
        if ((int) $entry['post_id'] === (int) $post_id) {
            $entry['has_image']   = $has_image;
            $entry['image_error'] = $image_error;
            $entry['image_status'] = 'done';
            break;
        }
    }
    unset($entry);
    update_option('modir_ai_content_log', $log, false);
}

function modir_ai_content_ajax_generate() {
    check_ajax_referer('modir_ai_content_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز.'));
    }

    $result = modir_ai_content_generate_article();
    if (empty($result['ok'])) {
        wp_send_json_error(array('message' => $result['error']));
    }
    wp_send_json_success($result);
}

/* ======================================================================
 * رندر تب
 * ====================================================================== */

function modir_ai_content_render_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $s   = modir_ai_content_get_settings();
    $log = get_option('modir_ai_content_log', array());
    ?>
    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div>
    <?php endif; ?>

    <div class="modir-alert">
        این ماژول از همون سرویس و کلید هوش مصنوعی که برای «دستیار گفتگو» وصل کردی استفاده می‌کنه. تولید تصویر شاخص فقط وقتی کار می‌کنه که provider دستیار گفتگو رو OpenAI گذاشته باشی (چون فقط OpenAI سرویس تولید تصویر داره) — اگه شکست بخوره، دلیلش رو تو جدول پایین یا کنار دکمه‌ی تولید می‌بینی.
        <br>هر مقاله خودکار به ۲ تا ۴ محصول یا مقاله‌ی مرتبط سایتت لینک می‌ده، و اگه افزونه‌ی سئوی Yoast، Rank Math یا All in One SEO نصب باشه، توضیحات متا و کلمه کلیدی اصلی هم خودکار توش تنظیم می‌شه.
    </div>

    <form method="post">
        <?php wp_nonce_field('modir_ai_content_save', 'modir_ai_content_nonce'); ?>
        <table class="form-table">
            <tr>
                <th scope="row">فعال‌سازی</th>
                <td><label class="modir-toggle"><input type="checkbox" name="enabled" value="1" <?php checked(!empty($s['enabled'])); ?>> این ماژول فعال باشد</label></td>
            </tr>
            <tr>
                <th scope="row">موضوعات مقاله</th>
                <td>
                    <textarea name="topics" rows="4" class="large-text" placeholder="هر خط یک موضوع (یکی تصادفی انتخاب می‌شود)، یا یک توضیح کلی درباره حوزه سایتت"><?php echo esc_textarea($s['topics']); ?></textarea>
                </td>
            </tr>
            <tr>
                <th scope="row">لحن نوشتار</th>
                <td><textarea name="tone" rows="2" class="large-text"><?php echo esc_textarea($s['tone']); ?></textarea></td>
            </tr>
            <tr>
                <th scope="row">قوانین مقاله</th>
                <td><textarea name="rules" rows="3" class="large-text"><?php echo esc_textarea($s['rules']); ?></textarea></td>
            </tr>
            <tr>
                <th scope="row">طول تقریبی (کلمه)</th>
                <td><input type="number" min="150" max="3000" name="length_words" value="<?php echo esc_attr($s['length_words']); ?>" style="width:120px;"></td>
            </tr>
            <tr>
                <th scope="row">دسته‌بندی انتشار</th>
                <td>
                    <?php wp_dropdown_categories(array('name' => 'category_id', 'selected' => $s['category_id'], 'show_option_none' => 'بدون دسته‌بندی خاص', 'hide_empty' => false)); ?>
                </td>
            </tr>
            <tr>
                <th scope="row">وضعیت انتشار</th>
                <td>
                    <label><input type="radio" name="publish_status" value="draft" <?php checked($s['publish_status'], 'draft'); ?>> پیش‌نویس (قبل از انتشار بازبینی کنم)</label>
                    &nbsp;&nbsp;
                    <label><input type="radio" name="publish_status" value="publish" <?php checked($s['publish_status'], 'publish'); ?>> مستقیم منتشر شود</label>
                </td>
            </tr>
            <tr>
                <th scope="row">تصویر شاخص خودکار</th>
                <td><label class="modir-toggle"><input type="checkbox" name="generate_image" value="1" <?php checked(!empty($s['generate_image'])); ?>> تولید و بارگذاری خودکار تصویر (فقط OpenAI)</label></td>
            </tr>
            <tr>
                <th scope="row">زمان‌بندی</th>
                <td>
                    <select name="schedule" class="modir-pill-select">
                        <option value="manual" <?php selected($s['schedule'], 'manual'); ?>>فقط دستی (با دکمه)</option>
                        <option value="daily" <?php selected($s['schedule'], 'daily'); ?>>روزانه خودکار</option>
                        <option value="weekly" <?php selected($s['schedule'], 'weekly'); ?>>هفتگی خودکار</option>
                    </select>
                </td>
            </tr>
        </table>
        <?php submit_button('ذخیره تنظیمات', 'primary', 'submit', true, array('class' => 'modir-btn')); ?>
    </form>

    <p>
        <button type="button" class="button button-primary modir-btn" id="modir-ai-content-generate-btn">تولید مقاله همین الان</button>
        <span id="modir-ai-content-result" style="margin-inline-start:10px;"></span>
    </p>

    <h2 class="modir-subtitle">مقاله‌های تولیدشده</h2>
    <?php if (empty($log)) : ?>
        <p class="modir-empty">هنوز مقاله‌ای تولید نشده است.</p>
    <?php else : ?>
        <table class="widefat striped modir-table">
            <thead><tr><th style="width:140px;">زمان</th><th>عنوان</th><th style="width:100px;">وضعیت</th><th style="width:80px;">تصویر</th><th style="width:100px;"></th></tr></thead>
            <tbody>
                <?php foreach ($log as $entry) : ?>
                    <tr>
                        <td class="modir-text-muted"><?php echo esc_html(modir_format_jalali($entry['time'], true)); ?></td>
                        <td><?php echo esc_html($entry['title']); ?></td>
                        <td><span class="modir-stat-badge <?php echo ('publish' === $entry['status']) ? 'is-good' : 'is-neutral'; ?>"><?php echo ('publish' === $entry['status']) ? 'منتشرشده' : 'پیش‌نویس'; ?></span></td>
                        <td>
                            <?php if (!empty($entry['has_image'])) : ?>
                                ✅
                            <?php elseif ('pending' === ($entry['image_status'] ?? '')) : ?>
                                <span class="modir-stat-badge is-neutral">در حال ساخت...</span>
                            <?php elseif (!empty($entry['image_error'])) : ?>
                                <span class="modir-stat-badge is-bad" title="<?php echo esc_attr($entry['image_error']); ?>">خطا</span>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><a href="<?php echo esc_url(get_edit_post_link($entry['post_id'])); ?>">ویرایش</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
}
