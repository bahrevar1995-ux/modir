jQuery(document).ready(function ($) {
    if (typeof ModirProducts === 'undefined') {
        return;
    }

    var $container   = $('#modir-products-grid-container');
    var $search      = $('#modir-products-search');
    var $perPage     = $('#modir-products-per-page');
    var $stockFilter = $('#modir-products-stock-filter');
    var $catFilter   = $('#modir-products-category-filter');
    var $checkFilter = $('#modir-products-check-filter');
    var $viewToggle  = $('#modir-products-view-toggle');
    var searchTimer;
    var currentView  = 'excel';

    function loadList(page) {
        $container.css('opacity', '0.5');
        $.post(ModirProducts.ajaxUrl, {
            action: 'modir_products_list',
            nonce: ModirProducts.nonce,
            search: $search.val() || '',
            paged: page || 1,
            per_page: $perPage.val(),
            view: currentView,
            stock: $stockFilter.val(),
            category: $catFilter.val(),
            needs_check: $checkFilter.val()
        }).done(function (res) {
            $container.css('opacity', '1');
            if (res && res.success) {
                $container.html(res.data.html);
            }
        }).fail(function () {
            $container.css('opacity', '1');
        });
    }

    $search.on('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { loadList(1); }, 400);
    });
    $('#modir-products-search-btn').on('click', function () { loadList(1); });
    $perPage.on('change', function () { loadList(1); });
    $stockFilter.on('change', function () { loadList(1); });
    $catFilter.on('change', function () { loadList(1); });
    $checkFilter.on('change', function () { loadList(1); });

    $viewToggle.on('click', 'button', function () {
        $viewToggle.find('button').removeClass('is-active');
        $(this).addClass('is-active');
        currentView = $(this).data('view');
        loadList(1);
    });

    $container.on('click', '.modir-page-btn', function () {
        loadList($(this).data('page'));
        $('html, body').animate({ scrollTop: $container.offset().top - 40 }, 200);
    });

    // آکاردئون موجودی: کلیک روی بج باز/بسته می‌شود؛ کلیک روی یکی از دو
    // گزینه («موجود»/«ناموجود») همان مقدار صریح را ذخیره می‌کند.
    $container.on('click', '.modir-stock-accordion-toggle', function (e) {
        e.stopPropagation();
        var $accordion = $(this).closest('.modir-stock-accordion');
        var isOpen = $accordion.hasClass('is-open');
        $container.find('.modir-stock-accordion').removeClass('is-open');
        if (!isOpen) {
            $accordion.addClass('is-open');
        }
    });

    $(document).on('click', function () {
        $container.find('.modir-stock-accordion').removeClass('is-open');
    });

    $container.on('click', '.modir-stock-option', function (e) {
        e.stopPropagation();
        var $option    = $(this);
        var $accordion = $option.closest('.modir-stock-accordion');
        var $toggleBtn = $accordion.find('.modir-stock-accordion-toggle');
        var pid        = $accordion.data('product-id');
        var status     = $option.data('status');

        $accordion.removeClass('is-open').css('opacity', '0.5');

        $.post(ModirProducts.ajaxUrl, {
            action: 'modir_products_toggle_stock',
            nonce: ModirProducts.nonce,
            product_id: pid,
            status: status
        }).done(function (res) {
            $accordion.css('opacity', '1');
            if (res && res.success) {
                $toggleBtn.removeClass('is-good is-bad').addClass(res.data.in_stock ? 'is-good' : 'is-bad');
                $toggleBtn.contents().first().replaceWith(res.data.label + ' ');
            }
        }).fail(function () {
            $accordion.css('opacity', '1');
        });
    });

    $container.on('click', '.modir-suggest-category-btn', function () {
        var $btn  = $(this);
        var pid   = $btn.data('product-id');
        var $row  = $btn.closest('tr');
        var $select = $row.find('.modir-grid-input-category');
        var $status = $row.find('.modir-grid-status');
        if (!$status.length) {
            $status = $('<span class="modir-grid-status"></span>');
            $row.find('td').last().append($status);
        }

        $btn.css('opacity', '0.4');
        $status.text('در حال حدس زدن...').removeClass('is-error is-success');

        $.post(ModirProducts.ajaxUrl, {
            action: 'modir_products_suggest_category',
            nonce: ModirProducts.nonce,
            product_id: pid
        }).done(function (res) {
            $btn.css('opacity', '1');
            if (res && res.success) {
                $select.val(res.data.category_id).trigger('change');
                var via = res.data.source === 'ai' ? ' (با هوش مصنوعی)' : '';
                $status.text('پیشنهاد: ' + res.data.category_name + via).addClass('is-success');
                setTimeout(function () { $status.text(''); }, 2500);
            } else {
                $status.text((res && res.data && res.data.message) || 'پیشنهادی پیدا نشد.').addClass('is-error');
                setTimeout(function () { $status.text(''); }, 3000);
            }
        }).fail(function () {
            $btn.css('opacity', '1');
            $status.text('خطا در ارتباط.').addClass('is-error');
        });
    });

    $container.on('click', '.modir-variation-toggle', function () {
        var pid = $(this).data('product-id');
        var $parentRow = $container.find('tr[data-product-id="' + pid + '"][data-variable="1"]');
        var $existing = $container.find('tr.modir-variation-row[data-parent-id="' + pid + '"]');

        if ($existing.length) {
            $existing.remove();
            $parentRow.find('.modir-variation-toggle .dashicons').removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
            return;
        }

        $.post(ModirProducts.ajaxUrl, {
            action: 'modir_products_variations',
            nonce: ModirProducts.nonce,
            product_id: pid,
            view: currentView
        }).done(function (res) {
            if (res && res.success) {
                $parentRow.after(res.data.html);
                $parentRow.find('.modir-variation-toggle .dashicons').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
            }
        });
    });

    $container.on('click', '.modir-select-all', function () {
        var checked = $(this).is(':checked');
        $container.find('.modir-row-select').prop('checked', checked);
    });

    function saveField($input, field, value, $row) {
        var pid     = $row.data('product-id');
        var $status = $row.find('.modir-grid-status');
        if (!$status.length) {
            $status = $('<span class="modir-grid-status"></span>');
            $row.find('td').last().append($status);
        }

        $status.text('در حال ذخیره...').removeClass('is-error is-success');
        $input.prop('disabled', true);

        $.post(ModirProducts.ajaxUrl, {
            action: 'modir_products_save_field',
            nonce: ModirProducts.nonce,
            product_id: pid,
            field: field,
            value: value
        }).done(function (res) {
            $input.prop('disabled', false);
            if (res && res.success) {
                $status.text('✓').addClass('is-success');
                setTimeout(function () { $status.text(''); }, 1200);

                if (res.data.stock_status_label) {
                    var $stockBadge = $row.find('td').eq(5).find('.modir-stat-badge');
                    if ($stockBadge.length) {
                        $stockBadge.removeClass('is-good is-bad').addClass(res.data.in_stock ? 'is-good' : 'is-bad');
                        $stockBadge.text(res.data.stock_status_label);
                    }
                }
            } else {
                $status.text((res && res.data && res.data.message) || 'خطا').addClass('is-error');
                setTimeout(function () { $status.text(''); }, 3000);
            }
        }).fail(function () {
            $input.prop('disabled', false);
            $status.text('خطا در ارتباط').addClass('is-error');
        });
    }

    $container.on('change', '.modir-grid-input', function () {
        var $input = $(this);
        var $row   = $input.closest('tr');
        saveField($input, $input.data('field'), $input.val(), $row);
    });

    $container.on('change', '.modir-switch-input', function () {
        var $input = $(this);
        var $row   = $input.closest('tr');
        saveField($input, $input.data('field'), $input.is(':checked') ? '1' : '0', $row);
    });

    $container.on('keydown', '.modir-grid-input', function (e) {
        if (13 === e.which) {
            e.preventDefault();
            $(this).trigger('change').trigger('blur');
        }
    });
});
