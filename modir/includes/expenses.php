<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * ماژول «هزینه کرد»: ثبت هزینه‌های فروشگاه (عنوان، تاریخ، مبلغ، توضیح)
 * ==========================================================================
 */

function modir_expenses_init() {
    add_action('admin_init', 'modir_expenses_maybe_save');
    add_action('admin_init', 'modir_expenses_maybe_delete');
}

/* -------------------------------------------------------------------- */
/* داده‌ها                                                               */
/* -------------------------------------------------------------------- */

function modir_expenses_get_all() {
    $v = get_option('modir_expenses', array());
    return is_array($v) ? $v : array();
}

/* -------------------------------------------------------------------- */
/* ذخیره/حذف                                                            */
/* -------------------------------------------------------------------- */

function modir_expenses_maybe_save() {
    if (empty($_POST['modir_expense_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['modir_expense_nonce'])), 'modir_expense_save')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $title       = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    $date_jalali = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
    $amount      = isset($_POST['amount']) ? (float) wp_unslash($_POST['amount']) : 0;
    $desc        = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';

    $date_gregorian = function_exists('modir_parse_jalali_date') ? modir_parse_jalali_date($date_jalali) : false;
    if (!$date_gregorian) {
        $date_gregorian = current_time('Y-m-d');
    }

    if (!$title || !$amount) {
        wp_safe_redirect(add_query_arg(array('page' => 'modir-accounting', 'tab' => 'expenses', 'error' => '1'), admin_url('admin.php')));
        exit;
    }

    $expenses   = modir_expenses_get_all();
    $expenses[] = array(
        'id'          => 'exp_' . uniqid(),
        'title'       => $title,
        'date'        => $date_gregorian,
        'amount'      => $amount,
        'description' => $desc,
        'created'     => current_time('mysql'),
    );
    update_option('modir_expenses', $expenses);

    wp_safe_redirect(add_query_arg(array('page' => 'modir-accounting', 'tab' => 'expenses', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

function modir_expenses_maybe_delete() {
    if (empty($_GET['modir_expense_action']) || 'delete' !== $_GET['modir_expense_action']) {
        return;
    }
    if (empty($_GET['expense_id']) || empty($_GET['_wpnonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'modir_expense_delete')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $id       = sanitize_text_field(wp_unslash($_GET['expense_id']));
    $expenses = array_values(array_filter(modir_expenses_get_all(), function ($e) use ($id) {
        return $e['id'] !== $id;
    }));
    update_option('modir_expenses', $expenses);

    wp_safe_redirect(add_query_arg(array('page' => 'modir-accounting', 'tab' => 'expenses', 'deleted' => '1'), admin_url('admin.php')));
    exit;
}

/* ======================================================================
 * رندر تب
 * ====================================================================== */

function modir_render_expenses_tab_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $range = modir_get_date_range();

    $all      = modir_expenses_get_all();
    $filtered = array_filter($all, function ($e) use ($range) {
        return $e['date'] >= $range['from'] && $e['date'] <= $range['to'];
    });
    usort($filtered, function ($a, $b) {
        return strtotime($b['date']) <=> strtotime($a['date']);
    });

    $total = 0;
    foreach ($filtered as $e) {
        $total += (float) $e['amount'];
    }
    ?>
    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>هزینه ثبت شد.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])) : ?>
        <div class="notice notice-error is-dismissible"><p>عنوان و مبلغ را کامل وارد کن.</p></div>
    <?php endif; ?>

    <h2 class="modir-subtitle">ثبت هزینه جدید</h2>
    <form method="post" class="modir-filter-form">
        <?php wp_nonce_field('modir_expense_save', 'modir_expense_nonce'); ?>
        <div class="modir-filter-row">
            <div class="modir-filter-field" style="min-width:180px;">
                <label>عنوان</label>
                <input type="text" name="title" placeholder="مثلاً: اجاره انبار">
            </div>
            <div class="modir-filter-field">
                <label>تاریخ (شمسی)</label>
                <input type="text" name="date" class="modir-jalali-date-input" placeholder="۱۴۰۴/۰۵/۰۱" dir="ltr">
            </div>
            <div class="modir-filter-field">
                <label>مبلغ</label>
                <input type="number" step="0.01" min="0" name="amount" style="width:140px;">
            </div>
            <div class="modir-filter-field modir-filter-submit">
                <button type="submit" class="button button-primary modir-btn">ثبت هزینه</button>
            </div>
        </div>
        <div class="modir-filter-row" style="margin-top:10px;">
            <div class="modir-filter-field" style="flex:1;">
                <label>توضیح (اختیاری)</label>
                <textarea name="description" rows="2" class="large-text"></textarea>
            </div>
        </div>
    </form>

    <h2 class="modir-subtitle">لیست هزینه‌ها</h2>
    <?php modir_render_date_filter('modir', '', 'expenses'); ?>
    <p class="modir-jalali-range">بازه انتخابی: <?php echo esc_html(modir_format_jalali($range['from']) . ' تا ' . modir_format_jalali($range['to'])); ?></p>

    <div class="modir-summary-cards">
        <div class="modir-card modir-card-danger">
            <span class="modir-card-label">جمع هزینه‌های این بازه</span>
            <span class="modir-card-value"><?php echo wp_kses_post(wc_price($total)); ?></span>
        </div>
        <div class="modir-card">
            <span class="modir-card-label">تعداد ردیف</span>
            <span class="modir-card-value"><?php echo number_format_i18n(count($filtered)); ?></span>
        </div>
    </div>

    <?php if (empty($filtered)) : ?>
        <p class="modir-empty">هزینه‌ای در این بازه ثبت نشده است.</p>
    <?php else : ?>
        <table class="widefat striped modir-table">
            <thead>
                <tr>
                    <th>تاریخ</th>
                    <th>عنوان</th>
                    <th>توضیح</th>
                    <th>مبلغ</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filtered as $e) :
                    $delete_url = wp_nonce_url(add_query_arg(array('page' => 'modir-accounting', 'tab' => 'expenses', 'modir_expense_action' => 'delete', 'expense_id' => $e['id']), admin_url('admin.php')), 'modir_expense_delete');
                    ?>
                    <tr>
                        <td><?php echo esc_html(modir_format_jalali($e['date'])); ?></td>
                        <td><?php echo esc_html($e['title']); ?></td>
                        <td class="modir-text-muted"><?php echo esc_html($e['description']); ?></td>
                        <td><?php echo wp_kses_post(wc_price($e['amount'])); ?></td>
                        <td><a href="<?php echo esc_url($delete_url); ?>" class="modir-link-danger" onclick="return confirm('حذف شود؟');">حذف</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
}
