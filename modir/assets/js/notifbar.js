(function () {
    'use strict';

    if (typeof ModirNotifBar === 'undefined') {
        return;
    }

    var bar = document.getElementById('modir-notifbar');
    if (!bar) {
        return;
    }

    var STORAGE_KEY = 'modir_notifbar_dismissed';

    function isDismissed() {
        try {
            return window.localStorage.getItem(STORAGE_KEY) === ModirNotifBar.dismissKey;
        } catch (e) {
            return false;
        }
    }

    function setDismissed() {
        try {
            window.localStorage.setItem(STORAGE_KEY, ModirNotifBar.dismissKey);
        } catch (e) {
            /* localStorage در دسترس نیست، نادیده گرفته می‌شود */
        }
    }

    function adjustBodyOffset() {
        if (bar.style.display === 'none') {
            document.body.style.marginTop = '';
            return;
        }
        document.body.style.marginTop = bar.offsetHeight + 'px';
    }

    function hideBar() {
        bar.style.display = 'none';
        document.body.style.marginTop = '';
    }

    function track(type) {
        var data = new FormData();
        data.append('action', 'modir_notifbar_track');
        data.append('type', type);
        data.append('nonce', ModirNotifBar.nonce);

        if (navigator.sendBeacon) {
            navigator.sendBeacon(ModirNotifBar.ajaxUrl, data);
        } else {
            fetch(ModirNotifBar.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' });
        }
    }

    if (isDismissed()) {
        hideBar();
    } else {
        adjustBodyOffset();
        window.addEventListener('resize', adjustBodyOffset);
        track('impression');
    }

    var closeBtn = bar.querySelector('.modir-notif-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            setDismissed();
            hideBar();
        });
    }

    var ctaBtn = bar.querySelector('.modir-notif-btn');
    if (ctaBtn) {
        ctaBtn.addEventListener('click', function () {
            track('click');
        });
    }
})();
