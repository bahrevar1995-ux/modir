jQuery(document).ready(function ($) {
    var $wrap = $('#modir-popup-items-wrap');
    if (!$wrap.length) {
        return; // فقط در صفحه فرم گروه اجرا شود
    }

    var rowIndex = $wrap.find('.modir-item-row').length;

    function toggleRowFields($row) {
        var type = $row.find('.modir-item-type-select').val();
        if ('product' === type) {
            $row.find('.modir-item-fee-only').hide();
            $row.find('.modir-item-product-only').show();
        } else {
            $row.find('.modir-item-fee-only').show();
            $row.find('.modir-item-product-only').hide();
        }
    }

    // مقداردهی اولیه ردیف‌های موجود
    $wrap.find('.modir-item-row').each(function () {
        toggleRowFields($(this));
    });

    $wrap.on('change', '.modir-item-type-select', function () {
        toggleRowFields($(this).closest('.modir-item-row'));
    });

    $wrap.on('click', '.modir-item-remove', function () {
        $(this).closest('.modir-item-row').remove();
    });

    $('#modir-popup-add-item').on('click', function () {
        var template = $('#modir-popup-item-template').html();
        var html = template.split('__INDEX__').join(rowIndex);
        var $newRow = $(html);

        $wrap.append($newRow);
        toggleRowFields($newRow);

        $(document.body).trigger('wc-enhanced-select-init');

        rowIndex++;
    });

    /* ------------------------------------------------------------ */
    /* تعویض نوع هدف گروه (محصول/دسته/برچسب)                          */
    /* ------------------------------------------------------------ */

    $('.modir-target-type-radio').on('change', function () {
        var val = $(this).val();
        $('.modir-target-select').hide();
        $('.modir-target-select-' + val).show();
    });
});
