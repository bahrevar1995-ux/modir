<?php
if (!defined('ABSPATH')) exit;

/**
 * صفحه حسابداری: گزارش فروش کالاها، گزارش کامل فروش، وضعیت سفارشات، و
 * سود و زیان، همه به‌صورت تب در یک صفحه واحد.
 */
function modir_render_reports_page() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $tabs = array(
        'products'         => 'فروش کالاها',
        'sales'            => 'گزارش کامل فروش',
        'status'           => 'وضعیت سفارشات',
        'pl'               => 'سود و زیان',
        'gateways'         => 'درگاه‌ها',
        'warehouse'        => 'انبار',
        'expenses'         => 'هزینه کرد',
        'sales_alert'      => 'آلارم فروش',
        'price_calc'       => 'ماشین‌حساب قیمت',
        'rfm'              => 'تحلیل مشتریان (RFM)',
        'funnel'           => 'قیف فروش',
        'cross_sell'       => 'پرفروش‌های همراه',
        'heatmap'          => 'نقشه حرارتی فروش',
        'geo'              => 'گزارش جغرافیایی',
        'category_profit'  => 'سودآوری دسته‌بندی‌ها',
    );

    $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'products';
    if (!isset($tabs[$tab])) {
        $tab = 'products';
    }
    ?>
    <div class="wrap modir-wrap">
        <?php modir_render_top_header('chart-area', 'حسابداری', 'گزارش فروش، عملکرد سفارش‌ها، و سود و زیان فروشگاه'); ?>
        <?php modir_settings_tabs_nav('modir-accounting'); ?>

        <div class="modir-tabs-nav-wrap">
            <button type="button" class="modir-tabs-nav-arrow" data-scroll-dir="1" aria-label="نمایش تب‌های راست‌تر">
                <span class="dashicons dashicons-arrow-right-alt2"></span>
            </button>
            <nav class="nav-tab-wrapper modir-tabs modir-scroll-rail">
                <?php foreach ($tabs as $key => $label) :
                    $url    = add_query_arg(array('page' => 'modir-accounting', 'tab' => $key), admin_url('admin.php'));
                    $active = ($tab === $key) ? ' nav-tab-active' : '';
                    ?>
                    <a href="<?php echo esc_url($url); ?>" class="nav-tab<?php echo esc_attr($active); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <button type="button" class="modir-tabs-nav-arrow" data-scroll-dir="-1" aria-label="نمایش تب‌های چپ‌تر">
                <span class="dashicons dashicons-arrow-left-alt2"></span>
            </button>
        </div>

        <div class="modir-tab-panel">
            <?php
            try {
                switch ($tab) {
                    case 'sales':
                        modir_render_full_sales_report_content();
                        break;
                    case 'status':
                        modir_render_order_status_report_content();
                        break;
                    case 'pl':
                        modir_pl_render_content();
                        break;
                    case 'gateways':
                        modir_render_gateways_tab_content();
                        break;
                    case 'warehouse':
                        modir_render_warehouse_tab_content();
                        break;
                    case 'expenses':
                        modir_render_expenses_tab_content();
                        break;
                    case 'sales_alert':
                        modir_render_sales_alert_tab_content();
                        break;
                    case 'price_calc':
                        modir_render_price_calc_tab_content();
                        break;
                    case 'rfm':
                        modir_render_rfm_tab_content();
                        break;
                    case 'funnel':
                        modir_render_funnel_tab_content();
                        break;
                    case 'cross_sell':
                        modir_render_cross_sell_tab_content();
                        break;
                    case 'heatmap':
                        modir_render_heatmap_tab_content();
                        break;
                    case 'geo':
                        modir_render_geo_tab_content();
                        break;
                    case 'category_profit':
                        modir_render_category_profit_tab_content();
                        break;
                    default:
                        modir_render_product_report_content();
                        break;
                }
            } catch (\Throwable $e) {
                echo '<div class="notice notice-error"><p>مشکلی در بارگذاری این تب پیش آمد. لطفاً صفحه را رفرش کنید؛ اگر مشکل ادامه داشت، این پیام را به توسعه‌دهنده اطلاع دهید: ' . esc_html($e->getMessage()) . ' (فایل: ' . esc_html(basename($e->getFile())) . '، خط: ' . (int) $e->getLine() . ')</p></div>';
            }
            ?>
        </div>
    </div>
    <?php
}
