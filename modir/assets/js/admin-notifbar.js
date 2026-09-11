jQuery(document).ready(function ($) {
    var $form    = $('#modir-notifbar-form');
    var $preview = $('.modir-notif-preview-frame .modir-notifbar');

    if (!$form.length || !$preview.length) {
        return;
    }

    /* ------------------------------------------------------------ */
    /* انتخاب تصویر با رسانه وردپرس                                    */
    /* ------------------------------------------------------------ */
    var frame;
    $('#modir-notif-choose-image').on('click', function (e) {
        e.preventDefault();

        if (frame) {
            frame.open();
            return;
        }

        frame = wp.media({
            title: 'انتخاب تصویر برای نوار نوتیف',
            button: { text: 'استفاده از این تصویر' },
            multiple: false
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#modir-notif-image-id').val(attachment.id).trigger('change');

            var thumbUrl = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
            $('#modir-notif-image-preview').html('<img src="' + thumbUrl + '" alt="">');
            $('#modir-notif-remove-image').show();

            updatePreviewImage(attachment.url);
        });

        frame.open();
    });

    $('#modir-notif-remove-image').on('click', function (e) {
        e.preventDefault();
        $('#modir-notif-image-id').val('').trigger('change');
        $('#modir-notif-image-preview').empty();
        $(this).hide();
        updatePreviewImage('');
    });

    function updatePreviewImage(url) {
        var $img = $preview.find('.modir-notif-image');
        if (url) {
            if (!$img.length) {
                $preview.find('.modir-notif-inner').prepend('<img class="modir-notif-image" src="' + url + '" alt="">');
            } else {
                $img.attr('src', url);
            }
        } else {
            $img.remove();
        }
    }

    /* ------------------------------------------------------------ */
    /* کارت‌های انتخاب طرح                                             */
    /* ------------------------------------------------------------ */
    $('.modir-style-card input[type="radio"]').on('change', function () {
        $('.modir-style-card').removeClass('is-selected');
        $(this).closest('.modir-style-card').addClass('is-selected');

        $preview.removeClass(function (i, cls) {
            return (cls.match(/(^|\s)modir-notif-style-\S+/g) || []).join(' ');
        });
        $preview.addClass('modir-notif-style-' + $(this).val());
    });

    /* ------------------------------------------------------------ */
    /* پیش‌نمایش زنده برای بقیه فیلدها                                  */
    /* ------------------------------------------------------------ */
    $form.on('input change', '[data-preview]', function () {
        var field = $(this).data('preview');
        var val   = $(this).val();

        switch (field) {
            case 'bg_color':
                $preview.css('--modir-notif-bg', val);
                break;
            case 'text_color':
                $preview.css('--modir-notif-text', val);
                break;
            case 'btn_bg':
                $preview.css('--modir-notif-btn-bg', val);
                break;
            case 'btn_text_color':
                $preview.css('--modir-notif-btn-text', val);
                break;
            case 'message':
                $preview.find('.modir-notif-text').text(val);
                break;
            case 'btn_label':
                $preview.find('.modir-notif-btn').text(val || '');
                break;
            case 'btn_url':
                if ($preview.find('.modir-notif-btn').length) {
                    $preview.find('.modir-notif-btn').attr('href', val);
                }
                break;
            case 'enabled':
                $preview.closest('.modir-notif-preview-frame').css('opacity', $(this).is(':checked') ? 1 : 0.4);
                break;
        }
    });

    // نکته: مقداردهی اولیه wp-color-picker توسط assets/js/admin.js انجام می‌شود
    // (که در همه صفحات مدیر بارگذاری می‌شود). خود آن هنگام تغییر رنگ، رویداد
    // change را روی input اصلی صادر می‌کند که هندلر بالا آن را می‌گیرد.
});
