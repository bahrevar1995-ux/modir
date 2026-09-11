jQuery(document).ready(function ($) {
    $('.modir-color-picker').wpColorPicker();

    $('.modir-bulk-target-radio').on('change', function () {
        var val = $(this).val();
        $('.modir-target-select').hide();
        $('.modir-target-select-' + val).show();
    });

    // دکمه‌های عقب/جلوی نوار تب (به‌جای اسکرول ریلی با ماوس/لمس)
    $('.modir-tabs-nav-arrow').on('click', function () {
        var dir = parseFloat($(this).data('scroll-dir')) || 1;
        var $rail = $(this).siblings('.modir-scroll-rail');
        $rail.animate({ scrollLeft: $rail.scrollLeft() + (dir * 160) }, 200);
    });

    /* ---------------- هدر یکپارچه: انتخاب رنگ + حالت روشن/تیره ---------------- */

    if (typeof ModirHeader !== 'undefined') {
        function applyModirColor(color) {
            document.documentElement.style.setProperty('--modir-primary', color);
            $('.modir-color-swatch-dot').css('background', color);
        }

        $('#modir-color-swatch-btn').on('click', function (e) {
            e.stopPropagation();
            $('#modir-color-swatch-popup').toggleClass('is-open');
        });
        $(document).on('click', function () {
            $('#modir-color-swatch-popup').removeClass('is-open');
        });
        $('#modir-color-swatch-popup').on('click', function (e) {
            e.stopPropagation();
        });

        function saveColor(color) {
            applyModirColor(color);
            $.post(ModirHeader.ajaxUrl, {
                action: 'modir_set_color',
                nonce: ModirHeader.nonce,
                color: color
            });
        }

        $('.modir-color-preset').on('click', function () {
            saveColor($(this).data('color'));
        });
        $('#modir-color-custom').on('input', function () {
            saveColor($(this).val());
        });

        $('#modir-theme-toggle-btn').on('click', function () {
            var $btn = $(this);
            var current = $btn.data('theme') === 'dark' ? 'dark' : 'light';
            var next = current === 'dark' ? 'light' : 'dark';

            $btn.data('theme', next);
            $('body').toggleClass('modir-dark-mode', next === 'dark');

            $.post(ModirHeader.ajaxUrl, {
                action: 'modir_set_theme',
                nonce: ModirHeader.nonce,
                theme: next
            });
        });

        // تمام‌صفحه: منوی وردپرس مخفی می‌شود، وضعیت در همین مرورگر به یاد سپرده می‌شود
        if (localStorage.getItem('modir_fullscreen') === '1') {
            $('body').addClass('modir-fullscreen-mode');
        }
        $('#modir-fullscreen-btn').on('click', function () {
            var isOn = $('body').toggleClass('modir-fullscreen-mode').hasClass('modir-fullscreen-mode');
            localStorage.setItem('modir_fullscreen', isOn ? '1' : '0');
        });
    }
});
