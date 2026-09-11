jQuery(document).ready(function ($) {
    if (typeof ModirImport === 'undefined') {
        return;
    }

    var DELAY_BETWEEN_MS = 900; // فاصله بین درخواست‌ها؛ برای محترمانه بودن به سرور مقصد (به‌خصوص دیجی‌کالا)

    function sleep(ms) {
        return new Promise(function (resolve) {
            setTimeout(resolve, ms);
        });
    }

    function extractUrls(raw) {
        return raw
            .split(/\r?\n/)
            .map(function (line) { return line.trim(); })
            .filter(function (line) { return /^https?:\/\//i.test(line); });
    }

    function addOneProduct(url) {
        return $.post(ModirImport.ajaxUrl, {
            action: 'modir_import_auto_add',
            nonce: ModirImport.nonce,
            url: url,
            category_id: $('#modir-import-category').val() || '',
            price: $('#modir-import-price').val() || '',
            stock: $('#modir-import-stock').val() || ''
        });
    }

    function rowHtml(url, index) {
        return '<div class="modir-import-row" id="modir-import-row-' + index + '">' +
            '<span class="modir-import-row-status modir-import-row-pending">در انتظار...</span>' +
            '<span class="modir-import-row-url">' + url + '</span>' +
            '</div>';
    }

    function updateRow(index, statusClass, text, editUrl) {
        var $row = $('#modir-import-row-' + index);
        var $status = $row.find('.modir-import-row-status');
        $status.removeClass('modir-import-row-pending modir-import-row-success modir-import-row-error')
            .addClass(statusClass)
            .text(text);
        if (editUrl) {
            $row.find('.modir-import-row-url').html('<a href="' + editUrl + '" target="_blank">' + $row.find('.modir-import-row-url').text() + '</a>');
        }
    }

    async function processAll(urls) {
        var $progress = $('#modir-import-progress');
        var $btn = $('#modir-import-auto-btn');
        var originalText = $btn.text();

        $btn.prop('disabled', true).text(ModirImport.i18n.working);
        $progress.empty().show();

        urls.forEach(function (url, i) {
            $progress.append(rowHtml(url, i));
        });

        var successCount = 0;
        var errorCount = 0;

        for (var i = 0; i < urls.length; i++) {
            updateRow(i, 'modir-import-row-pending', 'در حال پردازش...');

            try {
                var res = await addOneProduct(urls[i]);
                if (res && res.success) {
                    successCount++;
                    var d = res.data;
                    var label = 'اضافه شد: ' + d.title + (d.image_count ? ' (' + d.image_count + ' تصویر)' : '');
                    updateRow(i, 'modir-import-row-success', label, d.edit_url);
                } else {
                    errorCount++;
                    var msg = (res && res.data && res.data.message) || ModirImport.i18n.error;
                    updateRow(i, 'modir-import-row-error', 'خطا: ' + msg);
                }
            } catch (e) {
                errorCount++;
                updateRow(i, 'modir-import-row-error', ModirImport.i18n.error);
            }

            if (i < urls.length - 1) {
                await sleep(DELAY_BETWEEN_MS);
            }
        }

        $btn.prop('disabled', false).text(originalText);

        var summary = successCount + ' محصول با موفقیت اضافه شد';
        if (errorCount > 0) {
            summary += '، ' + errorCount + ' مورد با خطا مواجه شد';
        }
        summary += '.';
        $('#modir-import-status').html('<div class="notice notice-' + (errorCount ? 'warning' : 'success') + ' inline"><p>' + summary + '</p></div>');
    }

    $('#modir-import-auto-btn').on('click', function () {
        var raw = $('#modir-import-urls').val();
        var urls = extractUrls(raw);
        var $status = $('#modir-import-status');

        if (!urls.length) {
            $status.html('<div class="notice notice-error inline"><p>حداقل یک لینک معتبر (با http:// یا https://) وارد کن.</p></div>');
            return;
        }

        $status.empty();
        processAll(urls);
    });
});
