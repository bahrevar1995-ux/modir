jQuery(document).ready(function ($) {

    /* ------------------------------------------------------------ */
    /* دانش‌پایه: ردیف‌های تکرارشونده                                    */
    /* ------------------------------------------------------------ */

    var $wrap = $('#modir-chat-entries-wrap');
    if ($wrap.length) {
        var rowIndex = $wrap.find('.modir-chat-entry-row').length;

        $wrap.on('click', '.modir-item-remove', function () {
            $(this).closest('.modir-chat-entry-row').remove();
        });

        $('#modir-chat-add-entry').on('click', function () {
            var template = $('#modir-chat-entry-template').html();
            var html = template.split('__INDEX__').join(rowIndex);
            $wrap.append(html);
            rowIndex++;
        });
    }

    /* ------------------------------------------------------------ */
    /* تنظیمات: نمایش/مخفی‌سازی فیلد آدرس سفارشی                        */
    /* ------------------------------------------------------------ */

    var $provider = $('#modir-chat-provider');
    if ($provider.length) {
        $provider.on('change', function () {
            if ('openai_compatible' === $(this).val()) {
                $('#modir-chat-baseurl-row').show();
            } else {
                $('#modir-chat-baseurl-row').hide();
            }
        });
    }

    /* ------------------------------------------------------------ */
    /* دکمه تست اتصال                                                   */
    /* ------------------------------------------------------------ */

    $('#modir-chat-test-btn').on('click', function () {
        if (typeof ModirChatAdmin === 'undefined') {
            return;
        }

        var $btn    = $(this);
        var $result = $('#modir-chat-test-result');
        var originalText = $btn.text();

        $btn.prop('disabled', true).text('در حال بررسی...');
        $result.removeClass('is-success is-error').text('');

        $.post(ModirChatAdmin.ajaxUrl, {
            action: 'modir_chat_test',
            nonce: ModirChatAdmin.nonce,
            provider: $('#modir-chat-provider').val(),
            model: $('#modir-chat-model').val(),
            base_url: $('#modir-chat-baseurl-row input[name="base_url"]').val(),
            api_key: $('#modir-chat-api-key').val()
        }).done(function (res) {
            $btn.prop('disabled', false).text(originalText);
            if (res && res.success) {
                $result.addClass('is-success').text(res.data.message);
            } else {
                $result.addClass('is-error').text((res && res.data && res.data.message) || 'خطای نامشخص');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text(originalText);
            $result.addClass('is-error').text('خطا در ارتباط با سرور.');
        });
    });

    // انتخاب آیکون سفارشی دستیار گفتگو از کتابخانه رسانه وردپرس
    var chatIconFrame;
    $('#modir-chat-icon-pick').on('click', function (e) {
        e.preventDefault();
        if (chatIconFrame) {
            chatIconFrame.open();
            return;
        }
        chatIconFrame = wp.media({
            title: 'انتخاب آیکون دستیار گفتگو',
            button: { text: 'استفاده از این تصویر' },
            multiple: false
        });
        chatIconFrame.on('select', function () {
            var attachment = chatIconFrame.state().get('selection').first().toJSON();
            $('#modir-chat-icon-url').val(attachment.url);
            $('#modir-chat-icon-preview').attr('src', attachment.url).show();
            $('#modir-chat-icon-remove').show();
        });
        chatIconFrame.open();
    });

    $('#modir-chat-icon-remove').on('click', function (e) {
        e.preventDefault();
        $('#modir-chat-icon-url').val('');
        $('#modir-chat-icon-preview').hide();
        $(this).hide();
    });
});
