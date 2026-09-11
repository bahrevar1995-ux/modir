jQuery(document).ready(function ($) {
    if (typeof ModirOrdersList === 'undefined') {
        return;
    }

    var $rows         = $('#modir-orders-rows');
    var $statusFilter = $('#modir-orders-status-filter');
    var $paidFilter   = $('#modir-orders-paid-filter');
    var $search       = $('#modir-orders-search');
    var $loadMore     = $('#modir-orders-load-more');
    var offset        = 0;
    var searchTimer;

    function loadRows(reset) {
        if (reset) {
            offset = 0;
            $rows.empty();
        }
        $loadMore.prop('disabled', true).text('در حال بارگذاری...');

        $.post(ModirOrdersList.ajaxUrl, {
            action: 'modir_orders_list',
            nonce: ModirOrdersList.nonce,
            status: $statusFilter.val(),
            paid: $paidFilter.val(),
            search: $search.val(),
            offset: offset
        }).done(function (res) {
            $loadMore.prop('disabled', false).text('بارگذاری سفارش‌های بیشتر');
            if (res && res.success) {
                $rows.append(res.data.html);
                offset += res.data.count;
                $loadMore.toggle(!!res.data.has_more);
            }
        }).fail(function () {
            $loadMore.prop('disabled', false).text('بارگذاری سفارش‌های بیشتر');
        });
    }

    $statusFilter.on('change', function () { loadRows(true); });
    $paidFilter.on('change', function () { loadRows(true); });
    $search.on('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { loadRows(true); }, 400);
    });
    $('#modir-orders-search-btn').on('click', function () { loadRows(true); });
    $loadMore.on('click', function () { loadRows(false); });

    $rows.on('change', '.modir-orders-tracking-input', function () {
        var $input = $(this);
        var oid    = $input.closest('tr').data('order-id');
        $input.prop('disabled', true);

        $.post(ModirOrdersList.ajaxUrl, {
            action: 'modir_orders_save_tracking',
            nonce: ModirOrdersList.nonce,
            order_id: oid,
            code: $input.val()
        }).done(function () {
            $input.prop('disabled', false);
        }).fail(function () {
            $input.prop('disabled', false);
        });
    });

    $rows.on('change', '.modir-orders-status-select', function () {
        var $select = $(this);
        var oid     = $select.closest('tr').data('order-id');
        $select.prop('disabled', true);

        $.post(ModirOrdersList.ajaxUrl, {
            action: 'modir_orders_save_status',
            nonce: ModirOrdersList.nonce,
            order_id: oid,
            status: $select.val()
        }).done(function () {
            $select.prop('disabled', false);
        }).fail(function () {
            $select.prop('disabled', false);
        });
    });

    $('#modir-orders-default-btn').on('click', function (e) {
        e.stopPropagation();
        $('#modir-orders-default-popup').toggleClass('is-open');
    });
    $(document).on('click', function () {
        $('#modir-orders-default-popup').removeClass('is-open');
    });
    $('#modir-orders-default-popup').on('click', function (e) {
        e.stopPropagation();
    });

    $('#modir-orders-select-all').on('change', function () {
        $rows.find('.modir-orders-row-select').prop('checked', $(this).is(':checked'));
    });

    $('#modir-orders-bulk-print').on('click', function () {
        var ids = $rows.find('.modir-orders-row-select:checked').map(function () {
            return $(this).closest('tr').data('order-id');
        }).get();

        if (!ids.length) {
            alert('اول حداقل یک سفارش را از لیست تیک بزن.');
            return;
        }

        var url = ModirOrdersList.printUrl + '&orders=' + ids.join(',');
        window.open(url, '_blank');
    });

    $('#modir-orders-bulk-apply').on('click', function () {
        var ids = $rows.find('.modir-orders-row-select:checked').map(function () {
            return $(this).closest('tr').data('order-id');
        }).get();

        if (!ids.length) {
            return;
        }

        var status = $('#modir-orders-bulk-status').val();
        if (!confirm('وضعیت ' + ids.length + ' سفارش تغییر می‌کند. ادامه می‌دهی؟')) {
            return;
        }

        var $btn    = $(this);
        var $result = $('#modir-orders-bulk-result');
        $btn.prop('disabled', true).text('در حال اعمال...');

        $.post(ModirOrdersList.ajaxUrl, {
            action: 'modir_orders_bulk_status',
            nonce: ModirOrdersList.nonce,
            order_ids: ids,
            status: status
        }).done(function (res) {
            $btn.prop('disabled', false).text('اعمال');
            if (res && res.success) {
                $result.css('color', '#15803d').text(res.data.message);
                loadRows(true);
            } else {
                $result.css('color', '#dc2626').text('خطا در اجرای عملیات.');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('اعمال');
            $result.css('color', '#dc2626').text('خطا در ارتباط با سرور.');
        });
    });

    loadRows(true);
});
