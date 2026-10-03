/**
 * Gift card code and account balance on the classic (shortcode) cart and
 * checkout. Each action posts to a wc-ajax endpoint and then asks WooCommerce
 * to redraw its totals.
 */
jQuery(function ($) {
    'use strict';

    var config = window.wcStoreBalance;

    if (!config) {
        return;
    }

    function refresh() {
        if ($('form.checkout').length) {
            $(document.body).trigger('update_checkout');
        } else {
            // The cart has no partial refresh that includes this form.
            window.location.reload();
        }
    }

    function show($root, message, type) {
        $root.find('[data-store-balance-message]').text(message).attr('data-type', type).prop('hidden', !message);
    }

    function post(endpoint, data, $root) {
        var $controls = $root.find('button, input').prop('disabled', true);

        return $.post(config.url.replace('%%endpoint%%', endpoint), $.extend({ security: config.nonce }, data))
            .done(function (response) {
                if (response && response.success) {
                    refresh();
                } else {
                    show($root, (response && response.data && response.data.message) || config.error, 'error');
                    $controls.prop('disabled', false);
                }
            })
            .fail(function () {
                show($root, config.error, 'error');
                $controls.prop('disabled', false);
            });
    }

    $(document.body)
        .on('click', '[data-store-balance-apply]', function () {
            var $root = $(this).closest('[data-store-balance-checkout]');

            post('store_balance_apply', { code: $root.find('[data-store-balance-code]').val() }, $root);
        })
        .on('keydown', '[data-store-balance-code]', function (event) {
            // Enter would otherwise submit the whole checkout form.
            if (event.key === 'Enter') {
                event.preventDefault();
                $(this).closest('[data-store-balance-checkout]').find('[data-store-balance-apply]').trigger('click');
            }
        })
        .on('click', '[data-store-balance-remove]', function () {
            var $root = $(this).closest('[data-store-balance-checkout]');

            post('store_balance_remove', { id: $(this).data('store-balance-remove') }, $root);
        })
        .on('change', '[data-store-balance-use]', function () {
            var $root = $(this).closest('[data-store-balance-checkout]');

            post('store_balance_use_balance', { value: this.checked ? 1 : 0 }, $root);
        });
});
