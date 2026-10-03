/**
 * Gift card product page: show the custom amount field when "Other amount" is
 * chosen, and count the characters of the message.
 *
 * Plain DOM, no build step. Without this script the form still works: the
 * server validates everything, and the custom field is only hidden once the
 * script has run.
 */
(function () {
    'use strict';

    document.querySelectorAll('[data-store-balance-gift-card]').forEach(function (root) {
        var custom = root.querySelector('[data-store-balance-custom]');
        var radios = root.querySelectorAll('input[type="radio"][name="store_balance_amount"]');
        var input = root.querySelector('#store_balance_custom_amount');

        function sync(focus) {
            if (!custom || !radios.length) {
                return;
            }

            var chosen = root.querySelector('input[name="store_balance_amount"]:checked');
            var isCustom = !!chosen && chosen.value === 'custom';

            custom.hidden = !isCustom;

            if (input) {
                input.required = isCustom;

                if (isCustom && focus) {
                    input.focus();
                }
            }
        }

        radios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                sync(true);
            });
        });

        sync(false);

        var textarea = root.querySelector('[data-store-balance-count]');
        var counter = root.querySelector('[data-store-balance-counter]');

        if (textarea && counter && counter.dataset.template) {
            var update = function () {
                if (!textarea.value.length) {
                    return;
                }

                counter.textContent = counter.dataset.template
                    .replace('%1$s', String(textarea.value.length))
                    .replace('%2$s', String(textarea.maxLength));
            };

            textarea.addEventListener('input', update);
            update();
        }
    });
})();
