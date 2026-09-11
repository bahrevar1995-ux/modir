jQuery(document).ready(function ($) {
    var $btn     = $('#modir-diag-run-test');
    var $results = $('#modir-diag-test-results');

    if (!$btn.length || typeof ModirDiagnostics === 'undefined') {
        return;
    }

    $btn.on('click', function () {
        $btn.prop('disabled', true).text('در حال اجرای تست...');
        $results.html('');

        $.post(ModirDiagnostics.ajaxUrl, {
            action: 'modir_diagnostics_run_test',
            nonce: ModirDiagnostics.nonce
        }).done(function (res) {
            $btn.prop('disabled', false).text('اجرای تست فوری');
            if (!res || !res.success) {
                $results.html('<div class="notice notice-error"><p>اجرای تست با خطا مواجه شد.</p></div>');
                return;
            }

            var html = '';
            if (res.data.has_error) {
                html += '<div class="notice notice-error"><p><strong>یک یا چند مورد خطا داد — دقیقاً همینه که باعث صفحه سفید می‌شه:</strong></p></div>';
            } else {
                html += '<div class="notice notice-success"><p>همه‌ی تست‌ها بدون خطا اجرا شدند. مشکل احتمالاً جای دیگری غیر از این هوک‌هاست.</p></div>';
            }

            html += '<table class="widefat striped modir-table"><thead><tr><th>مورد تست</th><th>نتیجه</th></tr></thead><tbody>';
            res.data.results.forEach(function (r) {
                var badge = r.ok
                    ? '<span class="modir-stat-badge is-good">بدون خطا' + (r.message ? ' — ' + r.message : '') + '</span>'
                    : '<span class="modir-stat-badge is-bad">' + r.message + '</span>';
                html += '<tr><td>' + r.label + '</td><td>' + badge + '</td></tr>';
            });
            html += '</tbody></table>';

            $results.html(html);
        }).fail(function (jqXHR, textStatus) {
            $btn.prop('disabled', false).text('اجرای تست فوری');
            var rawBody = (jqXHR.responseText || '').substring(0, 800);
            var html = '<div class="notice notice-error"><p><strong>خود درخواستِ تست هم اجرا نشد.</strong> این خودش یه سرنخه؛ جزئیات زیر رو برام بفرست:</p>' +
                '<p>کد وضعیت HTTP: <code>' + jqXHR.status + '</code> (' + textStatus + ')</p>' +
                '<p>متن خام پاسخ سرور:</p>' +
                '<pre style="background:#1f2937;color:#f3f4f6;padding:12px;border-radius:8px;white-space:pre-wrap;font-size:11px;max-height:240px;overflow:auto;">' +
                (rawBody ? rawBody.replace(/</g, '&lt;') : '(پاسخی از سرور دریافت نشد)') +
                '</pre></div>';
            $results.html(html);
        });
    });
});
