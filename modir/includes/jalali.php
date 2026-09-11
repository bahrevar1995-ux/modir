<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================================
 * تبدیل تاریخ میلادی/شمسی — پیاده‌سازی مستقل، بدون وابستگی به کتابخانه خارجی
 * ==========================================================================
 */

function modir_gregorian_to_jalali($gy, $gm, $gd) {
    $g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
    $gy2   = ($gm > 2) ? ($gy + 1) : $gy;

    $days = 355666
        + (365 * $gy)
        + intdiv(($gy2 + 3), 4)
        - intdiv(($gy2 + 99), 100)
        + intdiv(($gy2 + 399), 400)
        + $gd
        + $g_d_m[$gm - 1];

    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;

    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;

    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }

    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }

    return array($jy, $jm, $jd);
}

function modir_jalali_to_gregorian($jy, $jm, $jd) {
    $jy += 1595;

    $days = -355668
        + (365 * $jy)
        + (intdiv($jy, 33) * 8)
        + intdiv((($jy % 33) + 3), 4)
        + $jd
        + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);

    $gy = 400 * intdiv($days, 146097);
    $days %= 146097;

    if ($days > 36524) {
        $gy += 100 * intdiv(--$days, 36524);
        $days %= 36524;
        if ($days >= 365) {
            $days++;
        }
    }

    $gy += 4 * intdiv($days, 1461);
    $days %= 1461;

    if ($days > 365) {
        $gy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }

    $gd = $days + 1;

    $is_leap = ((($gy % 4) === 0) && (($gy % 100) !== 0)) || (($gy % 400) === 0);
    $sal_a   = array(0, 31, $is_leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);

    $gm = 1;
    for (; $gm <= 12 && $gd > $sal_a[$gm]; $gm++) {
        $gd -= $sal_a[$gm];
    }

    return array($gy, $gm, $gd);
}

function modir_jalali_month_names() {
    return array(
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
        5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
        9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
    );
}

function modir_to_persian_digits($string) {
    $en = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
    $fa = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
    return str_replace($en, $fa, (string) $string);
}

function modir_to_english_digits($string) {
    $fa = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
    $en = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
    return str_replace($fa, $en, (string) $string);
}

/**
 * رشته تاریخ شمسی به فرمت 1404/05/01 یا 1404-05-01 (اعداد فارسی یا انگلیسی)
 * را می‌گیرد و در صورت معتبر بودن، معادل میلادی آن را به‌شکل Y-m-d برمی‌گرداند.
 */
function modir_parse_jalali_date($str) {
    $str = trim(modir_to_english_digits($str));
    if (!preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $str, $m)) {
        return false;
    }
    $jy = (int) $m[1];
    $jm = (int) $m[2];
    $jd = (int) $m[3];
    if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) {
        return false;
    }
    list($gy, $gm, $gd) = modir_jalali_to_gregorian($jy, $jm, $jd);
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/**
 * تاریخ میلادی Y-m-d را به رشته شمسی عددی (برای پرکردن فیلد ورودی) تبدیل می‌کند.
 */
function modir_gregorian_to_jalali_string($gregorian_ymd, $persian_digits = true) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $gregorian_ymd, $m)) {
        return '';
    }
    list($jy, $jm, $jd) = modir_gregorian_to_jalali((int) $m[1], (int) $m[2], (int) $m[3]);
    $str = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    return $persian_digits ? modir_to_persian_digits($str) : $str;
}

/**
 * تاریخ ورودی (شیء DateTime/WC_DateTime، تایم‌استمپ، یا رشته Y-m-d[ H:i:s]) را
 * به رشته شمسی خوانا («۱۴ مرداد ۱۴۰۴») تبدیل می‌کند.
 */
function modir_format_jalali($date, $with_time = false) {
    $gy = $gm = $gd = null;
    $gh = $gi = null;

    if (is_object($date) && method_exists($date, 'format')) {
        $gy = (int) $date->format('Y');
        $gm = (int) $date->format('n');
        $gd = (int) $date->format('j');
        $gh = $date->format('H');
        $gi = $date->format('i');
    } elseif (is_numeric($date)) {
        $gy = (int) wp_date('Y', (int) $date);
        $gm = (int) wp_date('n', (int) $date);
        $gd = (int) wp_date('j', (int) $date);
        $gh = wp_date('H', (int) $date);
        $gi = wp_date('i', (int) $date);
    } else {
        $str = (string) $date;
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $str, $m)) {
            $gy = (int) $m[1];
            $gm = (int) $m[2];
            $gd = (int) $m[3];
        }
        if (preg_match('/(\d{1,2}):(\d{2})/', $str, $m2)) {
            $gh = $m2[1];
            $gi = $m2[2];
        }
    }

    if (!$gy) {
        return '';
    }

    list($jy, $jm, $jd) = modir_gregorian_to_jalali($gy, $gm, $gd);
    $months = modir_jalali_month_names();

    $out = modir_to_persian_digits($jd) . ' ' . $months[$jm] . ' ' . modir_to_persian_digits($jy);

    if ($with_time && null !== $gh) {
        $out .= ' - ' . modir_to_persian_digits($gh . ':' . $gi);
    }

    return $out;
}

/**
 * تاریخ امروز به شمسی: array($jy, $jm, $jd)
 */
function modir_jalali_today() {
    $gy = (int) current_time('Y');
    $gm = (int) current_time('n');
    $gd = (int) current_time('j');
    return modir_gregorian_to_jalali($gy, $gm, $gd);
}

/**
 * بازه معادل میلادیِ «ماه شمسی جاری» را برمی‌گرداند، برای استفاده در فیلتر
 * date_created کوئری‌های ووکامرس. خروجی: [start_mysql, end_mysql, jy, jm]
 */
function modir_jalali_month_gregorian_range() {
    list($jy, $jm, $jd) = modir_jalali_today();
    list($gy1, $gm1, $gd1) = modir_jalali_to_gregorian($jy, $jm, 1);

    $start = sprintf('%04d-%02d-%02d 00:00:00', $gy1, $gm1, $gd1);
    $end   = current_time('Y-m-d') . ' 23:59:59';

    return array($start, $end, $jy, $jm);
}
