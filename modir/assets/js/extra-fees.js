jQuery(function ($) {
    if (typeof ModirExtraFees === 'undefined') {
        return;
    }

    // چون ووکامرس بعد از هر به‌روزرسانی جمع‌کل، کل جدول سفارش را دوباره
    // می‌سازد، از رویدادِ نمایندگی‌شده (delegated) روی body استفاده می‌کنیم
    // تا چک‌باکس‌های تازه‌ساخته‌شده هم همیشه کار کنند.
    $(document.body).on('change', '.modir-extra-fee-checkbox', function () {
        var $checkbox = $(this);

        $.post(ModirExtraFees.ajaxUrl, {
            action: 'modir_extra_fee_toggle',
            nonce: ModirExtraFees.nonce,
            fee_id: $checkbox.data('fee-id'),
            checked: $checkbox.is(':checked') ? 1 : 0
        }).always(function () {
            $(document.body).trigger('update_checkout');
        });
    });
});
