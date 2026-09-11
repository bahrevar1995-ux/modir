(function () {
    'use strict';

    /* ---------------- تبدیل میلادی/شمسی (همان الگوریتم نسخه PHP) ---------------- */

    function div(a, b) {
        return Math.floor(a / b);
    }

    function gregorianToJalali(gy, gm, gd) {
        var g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        var gy2 = (gm > 2) ? (gy + 1) : gy;
        var days = 355666 + (365 * gy) + div(gy2 + 3, 4) - div(gy2 + 99, 100) + div(gy2 + 399, 400) + gd + g_d_m[gm - 1];
        var jy = -1595 + (33 * div(days, 12053));
        days %= 12053;
        jy += 4 * div(days, 1461);
        days %= 1461;
        if (days > 365) {
            jy += div(days - 1, 365);
            days = (days - 1) % 365;
        }
        var jm, jd;
        if (days < 186) {
            jm = 1 + div(days, 31);
            jd = 1 + (days % 31);
        } else {
            jm = 7 + div(days - 186, 30);
            jd = 1 + ((days - 186) % 30);
        }
        return [jy, jm, jd];
    }

    function jalaliToGregorian(jy, jm, jd) {
        jy += 1595;
        var days = -355668 + (365 * jy) + (div(jy, 33) * 8) + div(((jy % 33) + 3), 4) + jd + ((jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
        var gy = 400 * div(days, 146097);
        days %= 146097;
        if (days > 36524) {
            days -= 1;
            gy += 100 * div(days, 36524);
            days %= 36524;
            if (days >= 365) {
                days += 1;
            }
        }
        gy += 4 * div(days, 1461);
        days %= 1461;
        if (days > 365) {
            gy += div(days - 1, 365);
            days = (days - 1) % 365;
        }
        var gd = days + 1;
        var isLeap = ((gy % 4 === 0) && (gy % 100 !== 0)) || (gy % 400 === 0);
        var sal_a = [0, 31, isLeap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        var gm = 1;
        for (; gm <= 12 && gd > sal_a[gm]; gm++) {
            gd -= sal_a[gm];
        }
        return [gy, gm, gd];
    }

    function jalaliMonthLength(jy, jm) {
        var nextJy = jy, nextJm = jm + 1;
        if (nextJm > 12) {
            nextJm = 1;
            nextJy++;
        }
        var g = jalaliToGregorian(nextJy, nextJm, 1);
        var d = new Date(Date.UTC(g[0], g[1] - 1, g[2]));
        d.setUTCDate(d.getUTCDate() - 1);
        var j = gregorianToJalali(d.getUTCFullYear(), d.getUTCMonth() + 1, d.getUTCDate());
        return j[2];
    }

    function jalaliWeekday(jy, jm, jd) {
        // خروجی: 0 = شنبه ... 6 = جمعه
        var g = jalaliToGregorian(jy, jm, jd);
        var d = new Date(Date.UTC(g[0], g[1] - 1, g[2]));
        var dow = d.getUTCDay(); // 0=یکشنبه ... 6=شنبه (استاندارد جاوااسکریپت)
        return (dow + 1) % 7;
    }

    var faDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    function toFa(n) {
        return String(n).replace(/[0-9]/g, function (d) {
            return faDigits[d];
        });
    }
    function toEn(str) {
        var enDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        var s = String(str);
        for (var i = 0; i < 10; i++) {
            s = s.split(enDigits[i]).join(i);
        }
        return s;
    }

    var monthNames = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    var weekDays = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];

    function todayJalali() {
        var now = new Date();
        return gregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
    }

    function parseInputValue(val) {
        val = toEn(val || '').trim();
        var m = val.match(/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/);
        if (m) {
            return [parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10)];
        }
        return todayJalali();
    }

    /* ---------------- ساخت پاپ‌آپ تقویم ---------------- */

    var activePopup = null;
    var activeInput = null;

    function closePopup() {
        if (activePopup) {
            activePopup.remove();
            activePopup = null;
            activeInput = null;
        }
    }

    document.addEventListener('click', function (e) {
        if (activePopup && !activePopup.contains(e.target) && e.target !== activeInput) {
            closePopup();
        }
    });

    function buildCalendar(input) {
        closePopup();

        var current = parseInputValue(input.value);
        var viewJy = current[0], viewJm = current[1];

        var popup = document.createElement('div');
        popup.className = 'modir-jdp-popup';

        function render() {
            popup.innerHTML = '';

            var header = document.createElement('div');
            header.className = 'modir-jdp-header';

            var prevBtn = document.createElement('button');
            prevBtn.type = 'button';
            prevBtn.className = 'modir-jdp-nav';
            prevBtn.textContent = '›';
            prevBtn.addEventListener('click', function () {
                viewJm--;
                if (viewJm < 1) { viewJm = 12; viewJy--; }
                render();
            });

            var nextBtn = document.createElement('button');
            nextBtn.type = 'button';
            nextBtn.className = 'modir-jdp-nav';
            nextBtn.textContent = '‹';
            nextBtn.addEventListener('click', function () {
                viewJm++;
                if (viewJm > 12) { viewJm = 1; viewJy++; }
                render();
            });

            var title = document.createElement('span');
            title.className = 'modir-jdp-title';
            title.textContent = monthNames[viewJm] + ' ' + toFa(viewJy);

            header.appendChild(prevBtn);
            header.appendChild(title);
            header.appendChild(nextBtn);
            popup.appendChild(header);

            var weekRow = document.createElement('div');
            weekRow.className = 'modir-jdp-weekdays';
            weekDays.forEach(function (w) {
                var el = document.createElement('span');
                el.textContent = w;
                weekRow.appendChild(el);
            });
            popup.appendChild(weekRow);

            var grid = document.createElement('div');
            grid.className = 'modir-jdp-grid';

            var firstWeekday = jalaliWeekday(viewJy, viewJm, 1);
            for (var i = 0; i < firstWeekday; i++) {
                var empty = document.createElement('span');
                empty.className = 'modir-jdp-empty';
                grid.appendChild(empty);
            }

            var daysInMonth = jalaliMonthLength(viewJy, viewJm);
            var t = todayJalali();

            for (var d = 1; d <= daysInMonth; d++) {
                (function (day) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'modir-jdp-day';
                    btn.textContent = toFa(day);
                    if (viewJy === current[0] && viewJm === current[1] && day === current[2]) {
                        btn.classList.add('is-selected');
                    }
                    if (viewJy === t[0] && viewJm === t[1] && day === t[2]) {
                        btn.classList.add('is-today');
                    }
                    btn.addEventListener('click', function () {
                        var val = viewJy + '/' + String(viewJm).padStart(2, '0') + '/' + String(day).padStart(2, '0');
                        input.value = toFa(val);
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                        closePopup();
                    });
                    grid.appendChild(btn);
                })(d);
            }

            popup.appendChild(grid);

            var footer = document.createElement('div');
            footer.className = 'modir-jdp-footer';
            var todayBtn = document.createElement('button');
            todayBtn.type = 'button';
            todayBtn.textContent = 'امروز';
            todayBtn.addEventListener('click', function () {
                var t2 = todayJalali();
                var val = t2[0] + '/' + String(t2[1]).padStart(2, '0') + '/' + String(t2[2]).padStart(2, '0');
                input.value = toFa(val);
                input.dispatchEvent(new Event('change', { bubbles: true }));
                closePopup();
            });
            var clearBtn = document.createElement('button');
            clearBtn.type = 'button';
            clearBtn.textContent = 'پاک کردن';
            clearBtn.addEventListener('click', function () {
                input.value = '';
                input.dispatchEvent(new Event('change', { bubbles: true }));
                closePopup();
            });
            footer.appendChild(todayBtn);
            footer.appendChild(clearBtn);
            popup.appendChild(footer);
        }

        render();
        document.body.appendChild(popup);

        // جلوگیری از بسته‌شدن پاپ‌آپ توسط شنونده «کلیک بیرون»: چون render()
        // دکمه‌های قبل/بعد را با innerHTML='' از نو می‌سازد، بدون این خط
        // کلیک روی آن‌ها به document می‌رسید و چون هدف کلیک (دکمه قدیمی) دیگر
        // در صفحه نبود، همان لحظه پاپ‌آپِ تازه‌رندرشده را می‌بست.
        popup.addEventListener('click', function (e) {
            e.stopPropagation();
        });

        var rect = input.getBoundingClientRect();
        popup.style.position = 'absolute';
        popup.style.top = (window.scrollY + rect.bottom + 4) + 'px';
        popup.style.left = (window.scrollX + rect.left) + 'px';

        activePopup = popup;
        activeInput = input;
    }

    function bindInput(input) {
        if (input.dataset.modirJdpBound) {
            return;
        }
        input.dataset.modirJdpBound = '1';
        input.setAttribute('readonly', 'readonly');
        input.setAttribute('autocomplete', 'off');
        input.style.cursor = 'pointer';
        input.addEventListener('click', function (e) {
            e.stopPropagation();
            buildCalendar(input);
        });
    }

    function initAll() {
        document.querySelectorAll('.modir-jalali-date-input').forEach(bindInput);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    // برای فرم‌هایی که بعداً به‌صورت پویا/AJAX ساخته می‌شوند
    window.modirInitJalaliDatepickers = initAll;
})();
