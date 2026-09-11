<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * صفحه «بازاریابی»: نوار نوتیف، دستیار گفتگو، و مرجوعی‌ها را زیر یک منو
 * جمع می‌کند (هرکدام با تب‌های داخلی خودشان دست‌نخورده باقی می‌مانند).
 * ==========================================================================
 */

function modir_render_marketing_page() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $sub_tabs = array(
        'notifbar'   => 'نوار نوتیف',
        'chat'       => 'دستیار گفتگو',
        'returns'    => 'مرجوعی‌ها',
        'ai_content' => 'تولید مقاله با هوش مصنوعی',
    );
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'notifbar';
    if (!isset($sub_tabs[$tab])) {
        $tab = 'notifbar';
    }
    ?>
    <div class="wrap modir-wrap">
        <?php modir_render_top_header('megaphone', 'بازاریابی', 'نوار نوتیف، دستیار گفتگو، و مدیریت مرجوعی‌ها'); ?>
        <?php modir_settings_tabs_nav('modir-marketing'); ?>
        <?php modir_render_sub_tabs_nav('modir-marketing', $sub_tabs, $tab); ?>

        <div class="modir-tab-panel">
            <?php
            switch ($tab) {
                case 'chat':
                    modir_chat_render_content();
                    break;
                case 'returns':
                    modir_returns_render_content();
                    break;
                case 'ai_content':
                    modir_ai_content_render_content();
                    break;
                default:
                    modir_notifbar_render_content();
                    break;
            }
            ?>
        </div>
    </div>
    <?php
}
