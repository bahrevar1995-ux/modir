jQuery(document).ready(function ($) {
    if (typeof ModirBulkEdit === 'undefined') {
        return;
    }

    var $rows       = $('#modir-bulk-product-rows');
    var $search     = $('#modir-bulk-search');
    var $category   = $('#modir-bulk-category-filter');
    var $stockFilter = $('#modir-bulk-stock-filter');
    var $loadMore   = $('#modir-bulk-load-more');
    var $applyBtn   = $('#modir-bulk-apply-btn');
    var $count      = $('#modir-bulk-selected-count');
    var $result     = $('#modir-bulk-result');
    var $field      = $('#modir-bulk-field');
    var $operation  = $('#modir-bulk-operation');
    var $amountWrap = $('#modir-bulk-amount-wrap');
    var $applyAll   = $('#modir-bulk-apply-all');
    var offset      = 0;
    var searchTimer;

    var numericOps = {};
    var stockOps   = {};
    var checkOps   = {};
    try { numericOps = JSON.parse($('#modir-bulk-numeric-operations').text()); } catch (e) {}
    try { stockOps   = JSON.parse($('#modir-bulk-stock-operations').text()); } catch (e) {}
    try { checkOps   = JSON.parse($('#modir-bulk-check-operations').text()); } catch (e) {}

    function isBooleanField(field) {
        return field === 'stock_status' || field === 'needs_check';
    }

    function toFa(n) {
        var fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        return String(n).replace(/[0-9]/g, function (d) { return fa[d]; });
    }

    function refreshOperationOptions() {
        var field = $field.val();
        var isBoolean = isBooleanField(field);
        var opts = (field === 'stock_status') ? stockOps : (field === 'needs_check') ? checkOps : numericOps;

        $operation.empty();
        $.each(opts, function (key, label) {
            $operation.append($('<option></option>').attr('value', key).text(label));
        });

        $amountWrap.toggle(!isBoolean);
    }

    function updateApplyButtonState() {
        if ($applyAll.is(':checked')) {
            $applyBtn.prop('disabled', false);
            $count.text('همه');
        } else {
            var n = $rows.find('.modir-bulk-row-checkbox:checked').length;
            $count.text(toFa(n));
            $applyBtn.prop('disabled', n === 0);
        }
    }

    function loadRows(reset) {
        if (reset) {
            offset = 0;
            $rows.empty();
        }
        $loadMore.prop('disabled', true).text('در حال بارگذاری...');

        $.post(ModirBulkEdit.ajaxUrl, {
            action: 'modir_bulk_list',
            nonce: ModirBulkEdit.nonce,
            search: $search.val() || '',
            category: $category.val() || '',
            stock: $stockFilter.val() || '',
            offset: offset
        }).done(function (res) {
            $loadMore.prop('disabled', false).text('بارگذاری محصولات بیشتر');
            if (res && res.success) {
                $rows.append(res.data.html);
                offset += res.data.count;
                $loadMore.toggle(!!res.data.has_more);
                updateApplyButtonState();
            }
        }).fail(function () {
            $loadMore.prop('disabled', false).text('بارگذاری محصولات بیشتر');
        });
    }

    $field.on('change', refreshOperationOptions);
    refreshOperationOptions();

    $applyAll.on('change', function () {
        var checked = $(this).is(':checked');
        $rows.closest('table').css('opacity', checked ? '0.5' : '1');
        $('#modir-bulk-select-all, .modir-bulk-row-checkbox').prop('disabled', checked);
        updateApplyButtonState();
    });

    $search.on('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { loadRows(true); }, 400);
    });
    $('#modir-bulk-search-btn').on('click', function () { loadRows(true); });
    $category.on('change', function () { loadRows(true); });
    $stockFilter.on('change', function () { loadRows(true); });
    $loadMore.on('click', function () { loadRows(false); });

    $rows.on('change', '.modir-bulk-row-checkbox', updateApplyButtonState);

    $('#modir-bulk-select-all').on('change', function () {
        $rows.find('.modir-bulk-row-checkbox').prop('checked', $(this).is(':checked'));
        updateApplyButtonState();
    });

    $applyBtn.on('click', function () {
        var applyAll  = $applyAll.is(':checked');
        var ids       = applyAll ? [] : $rows.find('.modir-bulk-row-checkbox:checked').map(function () { return $(this).val(); }).get();
        var field     = $field.val();
        var operation = $operation.val();
        var amount    = $('#modir-bulk-amount').val();
        var isStockStatus = isBooleanField(field);

        if (!applyAll && !ids.length) {
            return;
        }
        if (!isStockStatus && !amount) {
            $result.css('color', '#dc2626').text('مقدار را وارد کن.');
            return;
        }

        var confirmMsg = applyAll
            ? 'این عملیات روی همه محصولاتِ مطابق فیلتر فعلی اعمال می‌شود و قابل بازگشت خودکار نیست. ادامه می‌دهی؟'
            : 'این عملیات روی ' + ids.length + ' محصول اعمال می‌شود و قابل بازگشت خودکار نیست. ادامه می‌دهی؟';

        if (!confirm(confirmMsg)) {
            return;
        }

        $applyBtn.prop('disabled', true).text('در حال اجرا...');
        $result.text('');

        $.post(ModirBulkEdit.ajaxUrl, {
            action: 'modir_bulk_apply',
            nonce: ModirBulkEdit.nonce,
            product_ids: ids,
            apply_all: applyAll ? 1 : 0,
            search: $search.val() || '',
            category: $category.val() || '',
            stock: $stockFilter.val() || '',
            field: field,
            operation: operation,
            amount: amount
        }).done(function (res) {
            $applyBtn.prop('disabled', false);
            if (res && res.success) {
                $result.css('color', '#15803d').text(res.data.message);
                loadRows(true);
            } else {
                $result.css('color', '#dc2626').text((res && res.data && res.data.message) || 'خطا در اجرای عملیات.');
                updateApplyButtonState();
            }
        }).fail(function () {
            $applyBtn.prop('disabled', false);
            $result.css('color', '#dc2626').text('خطا در ارتباط با سرور.');
            updateApplyButtonState();
        });
    });

    loadRows(true);
});
