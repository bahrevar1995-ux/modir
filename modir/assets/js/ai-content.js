jQuery(document).ready(function ($) {
    var $btn    = $('#modir-ai-content-generate-btn');
    var $result = $('#modir-ai-content-result');

    if (!$btn.length || typeof ModirAiContent === 'undefined') {
        return;
    }

    $btn.on('click', function () {
        $btn.prop('disabled', true).text('در حال تولید مقاله... (ممکنه چند ده ثانیه طول بکشه)');
        $result.text('');

        $.post(ModirAiContent.ajaxUrl, {
            action: 'modir_ai_content_generate',
            nonce: ModirAiContent.nonce
        }).done(function (res) {
            $btn.prop('disabled', false).text('تولید مقاله همین الان');
            if (res && res.success) {
                var msg = 'مقاله «' + res.data.title + '» منتشر شد — <a href="' + res.data.edit_url + '" target="_blank">ویرایش</a>';
                if (res.data.image_pending) {
                    msg += '<br><span style="color:#6b7280;">تصویر شاخص داره جدا و در پس‌زمینه ساخته می‌شه؛ چند دقیقه دیگه تو همین لیست پایین چک کن.</span>';
                }
                $result.css('color', '#15803d').html(msg);
                setTimeout(function () { window.location.reload(); }, 2500);
            } else {
                $result.css('color', '#dc2626').text((res && res.data && res.data.message) || 'خطا در تولید مقاله.');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('تولید مقاله همین الان');
            $result.css('color', '#dc2626').text('خطا در ارتباط با سرور.');
        });
    });
});
