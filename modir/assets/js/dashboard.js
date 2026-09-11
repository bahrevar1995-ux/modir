jQuery(document).ready(function ($) {
    if (typeof ModirDashboard === 'undefined') {
        return;
    }

    var svg = document.getElementById('modir-dash-chart');
    if (!svg) {
        return;
    }
    var $tooltip = $('#modir-dash-tooltip');
    var $chartWrap = $('.modir-chart-wrap');
    var currentPoints = [];
    var currentLabels = [];
    var currentValues = [];

    var W = 640, H = 220, PAD = 28;
    var primaryColor = getComputedStyle(document.documentElement).getPropertyValue('--modir-primary').trim() || '#16a34a';

    function buildSmoothPath(points) {
        if (points.length < 2) {
            return '';
        }
        var d = 'M ' + points[0].x + ' ' + points[0].y;
        for (var i = 0; i < points.length - 1; i++) {
            var p0 = points[i];
            var p1 = points[i + 1];
            var midX = (p0.x + p1.x) / 2;
            d += ' C ' + midX + ' ' + p0.y + ', ' + midX + ' ' + p1.y + ', ' + p1.x + ' ' + p1.y;
        }
        return d;
    }

    function renderChart(labels, values) {
        var max = Math.max.apply(null, values.concat([1]));
        var n = values.length;
        var stepX = (W - PAD * 2) / Math.max(1, n - 1);

        var points = values.map(function (v, i) {
            var x = PAD + i * stepX;
            var y = H - PAD - ((v / max) * (H - PAD * 2));
            return { x: x, y: y };
        });

        currentPoints = points;
        currentLabels = labels;
        currentValues = values;

        var linePath = buildSmoothPath(points);
        var areaPath = linePath + ' L ' + points[n - 1].x + ' ' + (H - PAD) + ' L ' + points[0].x + ' ' + (H - PAD) + ' Z';

        var labelEls = points.map(function (p, i) {
            if (n > 15 && i % Math.ceil(n / 8) !== 0) {
                return '';
            }
            return '<text x="' + p.x + '" y="' + (H - 6) + '" font-size="10" fill="var(--modir-text-light, #8891a0)" text-anchor="middle">' + labels[i] + '</text>';
        }).join('');

        svg.innerHTML =
            '<defs><linearGradient id="modirDashGradient" x1="0" y1="0" x2="0" y2="1">' +
            '<stop offset="0%" stop-color="' + primaryColor + '" stop-opacity="0.35"/>' +
            '<stop offset="100%" stop-color="' + primaryColor + '" stop-opacity="0"/>' +
            '</linearGradient></defs>' +
            '<path d="' + areaPath + '" fill="url(#modirDashGradient)" stroke="none"></path>' +
            '<path d="' + linePath + '" fill="none" stroke="' + primaryColor + '" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></path>' +
            labelEls +
            '<circle id="modir-dash-hover-dot" r="4.5" fill="' + primaryColor + '" stroke="#fff" stroke-width="1.5" style="display:none;"></circle>';
    }

    function formatMoney(v) {
        return Math.round(v).toLocaleString('fa-IR');
    }

    $chartWrap.on('mousemove', function (e) {
        if (!currentPoints.length) {
            return;
        }
        var rect = svg.getBoundingClientRect();
        var relX = (e.clientX - rect.left) / rect.width * W;

        var nearest = 0;
        var minDist = Infinity;
        currentPoints.forEach(function (p, i) {
            var d = Math.abs(p.x - relX);
            if (d < minDist) {
                minDist = d;
                nearest = i;
            }
        });

        var p = currentPoints[nearest];
        var dot = document.getElementById('modir-dash-hover-dot');
        if (dot) {
            dot.setAttribute('cx', p.x);
            dot.setAttribute('cy', p.y);
            dot.style.display = 'block';
        }

        var wrapRect = $chartWrap[0].getBoundingClientRect();
        var pxRatio = wrapRect.width / W;
        $tooltip
            .css({ display: 'block', right: (wrapRect.width - (p.x * pxRatio) - 10), top: Math.max(0, (p.y * pxRatio) - 34) })
            .html('<strong>' + formatMoney(currentValues[nearest]) + '</strong> تومان<br><span>' + currentLabels[nearest] + '</span>');
    });

    $chartWrap.on('mouseleave', function () {
        $tooltip.hide();
        var dot = document.getElementById('modir-dash-hover-dot');
        if (dot) {
            dot.style.display = 'none';
        }
    });

    function loadChart(period) {
        $.post(ModirDashboard.ajaxUrl, {
            action: 'modir_dashboard_chart',
            nonce: ModirDashboard.nonce,
            period: period
        }).done(function (res) {
            if (res && res.success) {
                renderChart(res.data.labels, res.data.values);
            }
        });
    }

    $('#modir-dash-period-toggle button').on('click', function () {
        $('#modir-dash-period-toggle button').removeClass('is-active');
        $(this).addClass('is-active');
        loadChart($(this).data('period'));
    });

    loadChart('weekly');
});
