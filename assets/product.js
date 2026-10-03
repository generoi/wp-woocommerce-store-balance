/**
 * Gift card product page: show the custom amount field when "Other amount" is
 * chosen, check the amount before the page reloads, count the characters of
 * the message, and bring the result of "Add to cart" into view.
 *
 * Plain DOM, no build step. Without this script the form still works: the
 * server validates everything, and the custom field is only hidden once the
 * script has run.
 */
(function () {
    'use strict';

    /**
     * The same reading of a typed amount as the server: spaces and a currency
     * sign are ignored, a comma is a decimal point.
     */
    function parseAmount(value, signs) {
        var cleaned = String(value).replace(/[\s\u00A0\u202F]/g, '');

        // The shop's own currency sign or code, before or after the number.
        // Only its own: "$50" in a euro shop is not fifty euros.
        (signs || []).forEach(function (sign) {
            var lower = cleaned.toLowerCase();
            var s = String(sign || '').toLowerCase();

            if (s && lower.indexOf(s) === 0) {
                cleaned = cleaned.slice(s.length);
            } else if (s && lower.length > s.length && lower.lastIndexOf(s) === lower.length - s.length) {
                cleaned = cleaned.slice(0, -s.length);
            }
        });

        cleaned = cleaned.replace(',', '.');

        return /^\d+(\.\d{0,2})?$/.test(cleaned) ? parseFloat(cleaned) : NaN;
    }

    document.querySelectorAll('[data-store-balance-gift-card]').forEach(function (root) {
        var custom = root.querySelector('[data-store-balance-custom]');
        var radios = root.querySelectorAll('input[type="radio"][name="store_balance_amount"]');
        var input = root.querySelector('#store_balance_custom_amount');

        function isCustom() {
            if (!radios.length) {
                return !!input;
            }

            var chosen = root.querySelector('input[name="store_balance_amount"]:checked');

            return !!chosen && chosen.value === 'custom';
        }

        function check() {
            if (!input) {
                return;
            }

            var message = '';

            if (isCustom() && input.value.trim() !== '') {
                var amount = parseAmount(input.value, [input.dataset.currency, input.dataset.symbol]);

                if (isNaN(amount)) {
                    message = input.dataset.numberMessage || '';
                } else if (amount < parseFloat(input.dataset.min) || amount > parseFloat(input.dataset.max)) {
                    message = input.dataset.rangeMessage || '';
                }
            }

            input.setCustomValidity(message);
        }

        function sync() {
            if (custom && radios.length) {
                custom.hidden = !isCustom();
            }

            if (input) {
                input.required = isCustom();
            }

            check();
        }

        radios.forEach(function (radio) {
            radio.addEventListener('change', sync);

            // Focus moves to the field on a click only. Arrow keys also fire
            // "change", and moving focus then would throw a keyboard user out
            // of the radio group mid-way through it.
            radio.addEventListener('click', function (event) {
                if (radio.value === 'custom' && input && event.detail > 0) {
                    input.focus();
                }
            });
        });

        if (input) {
            input.addEventListener('input', check);
        }

        sync();

        var textarea = root.querySelector('[data-store-balance-count]');
        var counter = root.querySelector('[data-store-balance-counter]');

        if (textarea && counter && counter.dataset.template) {
            var update = function () {
                counter.textContent = textarea.value.length
                    ? counter.dataset.template
                        .replace('%1$s', String(textarea.value.length))
                        .replace('%2$s', String(textarea.maxLength))
                    : counter.dataset.empty || '';
            };

            textarea.addEventListener('input', update);
            update();
        }

        // The page has just reloaded after "Add to cart" and starts at the
        // top; the answer is down here, next to the form.
        var notices = root.querySelector('[data-store-balance-notices]');

        if (notices) {
            var invalid = root.querySelector('[aria-invalid="true"]');

            notices.scrollIntoView({ block: 'center' });

            // After WooCommerce's own script, which moves focus to the notice
            // half a second after the page loads. The notice says what is
            // wrong; the field is where it gets put right.
            window.setTimeout(function () {
                (invalid || notices).focus({ preventScroll: true });
            }, 650);

            // A gift card was added. The form came back empty; the quantity
            // should too, or the next one is bought twice by accident.
            if (!invalid) {
                var form = root.closest('form');
                var quantity = form ? form.querySelector('input.qty') : null;

                if (quantity) {
                    quantity.value = quantity.min || '1';
                }
            }
        }
    });
})();
