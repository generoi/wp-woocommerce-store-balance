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

    function show($root, message, type) {
        $root.find('[data-store-balance-message]').text(message).attr('data-type', type).prop('hidden', !message);
    }

    function post(endpoint, data, $root) {
        var $controls = $root.find('button, input').prop('disabled', true);

        return $.post(config.url.replace('%%endpoint%%', endpoint), $.extend({ security: config.nonce }, data))
            .done(function (response) {
                if (!response || !response.success) {
                    show($root, (response && response.data && response.data.message) || config.error, 'error');
                    $controls.prop('disabled', false);
                    $root.find('[data-store-balance-code]').trigger('focus');

                    return;
                }

                if (!$('form.checkout').length) {
                    // The cart has no partial refresh that includes this form.
                    window.location.reload();

                    return;
                }

                // WooCommerce redraws the totals; the form is ours to redraw.
                if (response.data && response.data.html) {
                    var $fresh = $(response.data.html);

                    $root.replaceWith($fresh);
                    show($fresh, response.data.message || '', 'success');
                    $fresh.find('[data-store-balance-code]').trigger('focus');
                } else {
                    $controls.prop('disabled', false);
                }

                $(document.body).trigger('update_checkout');
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
