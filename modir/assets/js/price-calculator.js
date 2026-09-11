jQuery(document).ready(function ($) {
    if (typeof ModirPriceCalc === 'undefined') {
        return;
    }

    var $rows     = $('#modir-pc-rows');
    var $search   = $('#modir-pc-search');
    var $loadMore = $('#modir-pc-load-more');
    var offset    = 0;
    var searchTimer;

    function loadRows(reset) {
        if (reset) {
            offset = 0;
            $rows.empty();
        }
        $loadMore.prop('disabled', true).text('در حال بارگذاری...');

        $.post(ModirPriceCalc.ajaxUrl, {
            action: 'modir_price_calc_list',
            nonce: ModirPriceCalc.nonce,
            search: $search.val() || '',
            offset: offset
        }).done(function (res) {
            $loadMore.prop('disabled', false).text('بارگذاری محصولات بیشتر');
            if (res && res.success) {
                $rows.append(res.data.html);
                offset += res.data.count;
                $loadMore.toggle(!!res.data.has_more);
            }
        }).fail(function () {
            $loadMore.prop('disabled', false).text('بارگذاری محصولات بیشتر');
        });
    }

    $search.on('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { loadRows(true); }, 400);
    });
    $('#modir-pc-search-btn').on('click', function () { loadRows(true); });
    $loadMore.on('click', function () { loadRows(false); });

    $rows.on('change', '.modir-pc-purchase, .modir-pc-margin', function () {
        var $row       = $(this).closest('tr');
        var pid        = $row.data('product-id');
        var purchase   = $row.find('.modir-pc-purchase').val();
        var margin     = $row.find('.modir-pc-margin').val();
        var $suggested = $row.find('.modir-pc-suggested');
        var $status    = $row.find('.modir-pc-status');

        $suggested.html('<span class="modir-text-muted">در حال محاسبه...</span>');

        $.post(ModirPriceCalc.ajaxUrl, {
            action: 'modir_price_calc_save',
            nonce: ModirPriceCalc.nonce,
            product_id: pid,
            purchase_price: purchase,
            margin: margin
        }).done(function (res) {
            if (res && res.success) {
                $suggested.html(res.data.suggested ? '<strong>' + res.data.suggested + '</strong>' : '<span class="modir-text-muted">—</span>');
                $status.html(res.data.synced ? '<span class="modir-stat-badge is-good">همگام با ووکامرس</span>' : '');
            }
        });
    });

    $('#modir-pc-bulk-apply').on('click', function () {
        if (!confirm('قیمت پیشنهادی روی همه محصولاتی که قیمت خرید ثبت‌شده دارند اعمال می‌شود. ادامه می‌دهی؟')) {
            return;
        }
        var $btn = $(this);
        var $result = $('#modir-pc-bulk-result');
        $btn.prop('disabled', true).text('در حال اعمال...');

        $.post(ModirPriceCalc.ajaxUrl, {
            action: 'modir_price_calc_bulk_apply',
            nonce: ModirPriceCalc.nonce
        }).done(function (res) {
            $btn.prop('disabled', false).text('اعمال قیمت پیشنهادی روی همه محصولات دارای قیمت خرید');
            if (res && res.success) {
                $result.css('color', '#15803d').text(res.data.message);
                loadRows(true);
            } else {
                $result.css('color', '#dc2626').text('خطا در اجرای عملیات.');
            }
        });
    });

    loadRows(true);
});
