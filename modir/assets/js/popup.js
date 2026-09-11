(function ($) {
    'use strict';

    if (typeof ModirPopup === 'undefined') {
        return;
    }

    var $modalRoot = null;

    function ensureModalRoot() {
        if ($modalRoot) {
            return $modalRoot;
        }

        $modalRoot = $(
            '<div id="modir-popup-overlay" class="modir-popup-overlay" style="display:none;">' +
                '<div class="modir-popup-box">' +
                    '<button type="button" class="modir-popup-close" aria-label="' + ModirPopup.i18n.close + '">&times;</button>' +
                    '<div class="modir-popup-content"></div>' +
                '</div>' +
            '</div>'
        );
        $('body').append($modalRoot);

        $modalRoot.on('click', function (e) {
            if (e.target === $modalRoot[0]) {
                closeModal();
            }
        });
        $modalRoot.find('.modir-popup-close').on('click', closeModal);

        return $modalRoot;
    }

    function closeModal() {
        if ($modalRoot) {
            $modalRoot.hide();
        }
        $('form.cart').find('button[type="submit"], .single_add_to_cart_button').prop('disabled', false);
    }

    function openModal(html, productId, variationId, quantity, $sourceForm) {
        var $root = ensureModalRoot();
        $root.find('.modir-popup-content').html(html);
        $root.show();

        var $modalForm = $root.find('.modir-popup-form');
        $modalForm.find('input[name="product_id"]').val(productId);
        $modalForm.find('input[name="variation_id"]').val(variationId);
        $modalForm.find('input[name="quantity"]').val(quantity);

        if ($sourceForm && $sourceForm.length) {
            $sourceForm.find('[name^="attribute_"]').each(function () {
                var name = $(this).attr('name');
                var val  = $(this).val();
                $modalForm.append('<input type="hidden" name="variation[' + name + ']" value="' + String(val).replace(/"/g, '&quot;') + '">');
            });
        }

        $root.find('.modir-popup-confirm').off('click').on('click', function () {
            submitAddToCart($root);
        });
    }

    function submitAddToCart($root) {
        var $btn = $root.find('.modir-popup-confirm');
        var originalText = $btn.text();
        $btn.prop('disabled', true).text(ModirPopup.i18n.adding);

        var data = $root.find('.modir-popup-form').serializeArray();
        data.push({ name: 'nonce', value: ModirPopup.nonce });

        $.post(ModirPopup.ajaxUrl, $.param(data)).done(function (res) {
            if (res && res.success) {
                $(document.body).trigger('added_to_cart', [res.data.fragments, res.data.cart_hash, $btn]);

                if (res.data.redirect) {
                    window.location = res.data.redirect;
                    return;
                }
                closeModal();
                $btn.prop('disabled', false).text(originalText);
            } else {
                window.alert((res && res.data && res.data.message) || ModirPopup.i18n.error);
                $btn.prop('disabled', false).text(originalText);
            }
        }).fail(function () {
            window.alert(ModirPopup.i18n.error);
            $btn.prop('disabled', false).text(originalText);
        });
    }

    /* ------------------------------------------------------------ */
    /* رهگیری فرم افزودن به سبد خرید در صفحه محصول                     */
    /* ------------------------------------------------------------ */

    if (ModirPopup.hasGroups) {
        $(document.body).on('submit', 'form.cart', function (e) {
            var formEl = this;
            var $form  = $(this);

            e.preventDefault();

            var productId   = $form.find('input[name="add-to-cart"], button[name="add-to-cart"]').val() || $form.data('product_id') || 0;
            var variationId = $form.find('input[name="variation_id"]').val() || 0;
            var quantity    = $form.find('input[name="quantity"]').val() || 1;

            $form.find('button[type="submit"], .single_add_to_cart_button').prop('disabled', true);

            $.post(ModirPopup.ajaxUrl, {
                action: 'modir_popup_get_popup',
                nonce: ModirPopup.nonce,
                product_id: productId,
                variation_id: variationId,
                quantity: quantity
            }).done(function (res) {
                if (res && res.success) {
                    openModal(res.data.html, productId, variationId, quantity, $form);
                } else {
                    // گروهی برای این محصول یافت نشد؛ ارسال عادی فرم
                    formEl.submit();
                }
            }).fail(function () {
                $form.find('button[type="submit"], .single_add_to_cart_button').prop('disabled', false);
                formEl.submit();
            });
        });
    }

    /* ------------------------------------------------------------ */
    /* حذف مستقیم یک هزینه اختیاری از صفحه سبد خرید                    */
    /* ------------------------------------------------------------ */

    $(document.body).on('click', '.modir-cart-addon-remove', function (e) {
        e.preventDefault();
        var $link = $(this);

        if ($link.data('busy')) {
            return;
        }
        $link.data('busy', true).css({ opacity: 0.4, pointerEvents: 'none' });

        $.post(ModirPopup.ajaxUrl, {
            action: 'modir_popup_remove_addon',
            nonce: ModirPopup.nonce,
            cart_item_key: $link.data('cart-item-key'),
            item_id: $link.data('item-id')
        }).done(function (res) {
            if (res && res.success) {
                window.location.reload();
            } else {
                $link.data('busy', false).css({ opacity: 1, pointerEvents: 'auto' });
                window.alert(ModirPopup.i18n.error);
            }
        }).fail(function () {
            $link.data('busy', false).css({ opacity: 1, pointerEvents: 'auto' });
            window.alert(ModirPopup.i18n.error);
        });
    });
})(jQuery);
