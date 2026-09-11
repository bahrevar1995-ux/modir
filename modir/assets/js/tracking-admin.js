jQuery(document).ready(function ($) {
    $('#modir-sms-test-btn').on('click', function () {
        if (typeof ModirTrackingAdmin === 'undefined') {
            return;
        }

        var $btn = $(this);
        var $result = $('#modir-sms-test-result');
        var phone = $('#modir-sms-test-phone').val().trim();

        if (!phone) {
            $result.text('شماره موبایل را وارد کن.').css('color', '#b91c1c');
            return;
        }

        var originalText = $btn.text();
        $btn.prop('disabled', true).text('در حال ارسال...');
        $result.text('');

        $.post(ModirTrackingAdmin.ajaxUrl, {
            action: 'modir_tracking_test_sms',
            nonce: ModirTrackingAdmin.nonce,
            phone: phone,
            username: $('#modir-sms-username').val(),
            password: $('#modir-sms-password').val(),
            body_id: $('#modir-sms-bodyid').val()
        }).done(function (res) {
            $btn.prop('disabled', false).text(originalText);
            if (res && res.success) {
                $result.text(res.data.message).css('color', '#15803d');
            } else {
                $result.text((res && res.data && res.data.message) || 'خطای نامشخص').css('color', '#b91c1c');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text(originalText);
            $result.text('خطا در ارتباط با سرور.').css('color', '#b91c1c');
        });
    });
});
