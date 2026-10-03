/**
 * Gift cards and store credit in the cart and checkout blocks.
 *
 * Two pieces, both rendered into slots WooCommerce provides in the order
 * summary: a form to enter a gift card code, next to the coupon form, and a
 * summary of what the balance pays for, above the total.
 *
 * Written against the globals WooCommerce ships (wp.element, wc.blocksCheckout)
 * so there is no build step. `el` is React.createElement.
 */
(function (wp, wc) {
    'use strict';

    if (!wp || !wc || !wc.blocksCheckout || !wp.plugins || !wp.element) {
        return;
    }

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var checkout = wc.blocksCheckout;
    var components = wc.blocksComponents || {};
    var Panel = checkout.Panel || components.Panel;
    var TextInput = checkout.TextInput || components.TextInput;
    var Button = checkout.Button || components.Button;
    var TotalsItem = checkout.TotalsItem || components.TotalsItem;
    var CheckboxControl = checkout.CheckboxControl || components.CheckboxControl;
    var settings = (wc.wcSettings && wc.wcSettings.getSetting('wc-store-balance_data', {})) || {};
    var strings = settings.strings || {};
    var NAMESPACE = settings.namespace || 'wc-store-balance';

    function t(key) {
        return strings[key] || '';
    }

    function sprintf(template) {
        var args = Array.prototype.slice.call(arguments, 1);
        var index = 0;

        return String(template).replace(/%(\d+\$)?s/g, function (match, position) {
            var i = position ? parseInt(position, 10) - 1 : index++;

            return args[i] !== undefined ? args[i] : '';
        });
    }

    function speak(message) {
        if (wp.a11y && wp.a11y.speak && message) {
            wp.a11y.speak(message, 'polite');
        }
    }

    /**
     * The balance state the server attached to the cart, and a formatter for
     * its amounts in the cart's currency.
     */
    function useBalance(props) {
        var cart = props.cart || {};
        var data = (props.extensions && props.extensions[NAMESPACE]) || (cart.extensions && cart.extensions[NAMESPACE]) || null;
        var totals = cart.cartTotals || cart.totals || {};
        var currency = wc.priceFormat.getCurrencyFromPriceResponse(totals);

        return {
            data: data,
            totals: totals,
            currency: currency,
            format: function (amount) {
                return wc.priceFormat.formatPrice(amount, currency);
            },
        };
    }

    function update(data) {
        return checkout.extensionCartUpdate({ namespace: NAMESPACE, data: data });
    }

    function errorMessage(error) {
        var message = error && (error.message || (error.data && error.data.message));

        // The server sends messages with entities encoded.
        if (message) {
            var area = document.createElement('textarea');
            area.innerHTML = message;

            return area.value;
        }

        return t('genericError');
    }

    /**
     * The code form. Sits with the coupon form, because that is where a
     * customer with a code in hand looks.
     */
    function CodeForm(props) {
        var balance = useBalance(props);
        var data = balance.data;
        var code = useState('');
        var busy = useState(false);
        var error = useState('');

        if (!data) {
            return null;
        }

        var codes = data.codes || [];

        function setCode(value) {
            code[1](value);

            if (error[0]) {
                error[1]('');
            }
        }

        function focusInput() {
            var field = document.getElementById('wc-store-balance-code');

            if (field) {
                field.focus();
            }
        }

        function apply(event) {
            event.preventDefault();

            if (busy[0]) {
                return;
            }

            if (!code[0].trim()) {
                error[1](t('emptyCode'));

                focusInput();

                return;
            }

            busy[1](true);
            error[1]('');

            update({ action: 'apply', code: code[0] })
                .then(function () {
                    code[1]('');
                    speak(t('applied'));
                })
                .catch(function (e) {
                    error[1](errorMessage(e));

                    focusInput();
                })
                .finally(function () {
                    busy[1](false);
                });
        }

        function remove(id) {
            busy[1](true);

            update({ action: 'remove', id: id })
                .then(function () {
                    speak(t('removed'));
                })
                .catch(function (e) {
                    error[1](errorMessage(e));
                })
                .finally(function () {
                    busy[1](false);
                });
        }

        var body = el(
            'div',
            { className: 'wc-store-balance-form__body' },
            data.only_gift_cards
                ? el('p', { className: 'wc-store-balance__note' }, t('onlyGiftCards'))
                : el(
                    'form',
                    { className: 'wc-block-components-totals-coupon__form wc-store-balance-form__row', onSubmit: apply, noValidate: true },
                    TextInput
                        ? el(TextInput, {
                            id: 'wc-store-balance-code',
                            className: 'wc-block-components-totals-coupon__input wc-store-balance-form__input' + (error[0] ? ' has-error' : ''),
                            label: t('inputLabel'),
                            value: code[0],
                            disabled: busy[0],
                            autoComplete: 'off',
                            autoCapitalize: 'characters',
                            ariaDescribedBy: error[0] ? 'wc-store-balance-error' : undefined,
                            onChange: setCode,
                        })
                        : el('input', {
                            id: 'wc-store-balance-code',
                            type: 'text',
                            className: 'wc-store-balance-form__input',
                            value: code[0],
                            'aria-label': t('inputLabel'),
                            placeholder: t('inputLabel'),
                            autoComplete: 'off',
                            disabled: busy[0],
                            onChange: function (event) {
                                setCode(event.target.value);
                            },
                        }),
                    Button
                        ? el(
                            Button,
                            {
                                className: 'wc-block-components-totals-coupon__button wc-store-balance-form__button',
                                type: 'submit',
                                disabled: busy[0],
                            },
                            busy[0] ? t('applying') : t('apply')
                        )
                        : el('button', { type: 'submit', className: 'wp-element-button', disabled: busy[0] }, t('apply'))
                ),
            error[0]
                ? el('p', { id: 'wc-store-balance-error', className: 'wc-store-balance__error', role: 'alert' }, error[0])
                : null,
            codes.length
                ? el(
                    'ul',
                    { className: 'wc-store-balance-form__codes' },
                    codes.map(function (line) {
                        var used = parseInt(line.amount, 10) || 0;
                        var available = parseInt(line.available, 10) || 0;
                        var detail = line.reason
                            ? line.reason
                            : used > 0
                                ? used < available
                                    ? sprintf(t('remaining'), balance.format(available - used))
                                    : ''
                                : t('notUsed');

                        return el(
                            'li',
                            { key: line.id, className: 'wc-store-balance-form__code' + (line.reason ? ' has-reason' : '') },
                            el(
                                'span',
                                { className: 'wc-store-balance-form__code-text' },
                                el('span', { className: 'wc-store-balance-form__code-name' }, sprintf(t('giftCard'), line.masked)),
                                detail ? el('span', { className: 'wc-store-balance-form__code-detail' }, detail) : null
                            ),
                            el(
                                'button',
                                {
                                    type: 'button',
                                    className: 'wc-store-balance-form__remove',
                                    disabled: busy[0],
                                    'aria-label': sprintf(t('remove'), line.masked),
                                    onClick: function () {
                                        remove(line.id);
                                    },
                                },
                                t('removeShort')
                            )
                        );
                    })
                )
                : null
        );

        if (!Panel) {
            return el('div', { className: 'wc-block-components-totals-wrapper wc-store-balance-form' }, el('p', null, t('panelTitle')), body);
        }

        // Open from the start when there is something in it to see.
        return el(
            'div',
            { className: 'wc-block-components-totals-wrapper wc-store-balance-form' },
            el(
                Panel,
                {
                    className: 'wc-block-components-totals-coupon wc-store-balance-form__panel',
                    initialOpen: codes.length > 0,
                    hasBorder: false,
                    title: t('panelTitle'),
                },
                body
            )
        );
    }

    /**
     * What the balance does to this order: the account balance switch, and the
     * amount taken off, right above the total it reduces.
     */
    function Summary(props) {
        var balance = useBalance(props);
        var data = balance.data;
        var busy = useState(false);

        if (!data) {
            return null;
        }

        var account = data.account || {};
        var available = parseInt(account.available, 10) || 0;
        var giftCards = parseInt(account.gift_cards, 10) || 0;
        var storeCredit = parseInt(account.store_credit, 10) || 0;
        var applied = parseInt(data.applied_total, 10) || 0;
        var toPay = parseInt(balance.totals.total_price, 10) || 0;
        var other = account.other_currencies || [];
        var children = [];

        if (available > 0 && !data.only_gift_cards) {
            children.push(
                el(
                    'div',
                    { key: 'account', className: 'wc-store-balance-summary__account' },
                    (function () {
                        var label = el(
                            'span',
                            null,
                            el('span', { className: 'wc-store-balance-summary__toggle-label' }, sprintf(t('useBalance'), balance.format(available))),
                            giftCards > 0 && storeCredit > 0
                                ? el(
                                    'span',
                                    { className: 'wc-store-balance-summary__detail' },
                                    sprintf(t('breakdown'), balance.format(giftCards), balance.format(storeCredit))
                                )
                                : null
                        );

                        function toggle(checked) {
                            busy[1](true);

                            update({ action: 'use_balance', value: checked }).finally(function () {
                                busy[1](false);
                            });
                        }

                        if (CheckboxControl) {
                            return el(
                                CheckboxControl,
                                {
                                    id: 'wc-store-balance-use',
                                    className: 'wc-store-balance-summary__toggle',
                                    checked: !!data.use_balance,
                                    disabled: busy[0],
                                    onChange: toggle,
                                },
                                label
                            );
                        }

                        return el(
                            'label',
                            { className: 'wc-store-balance-summary__toggle' },
                            el('input', {
                                type: 'checkbox',
                                checked: !!data.use_balance,
                                disabled: busy[0],
                                onChange: function (event) {
                                    toggle(event.target.checked);
                                },
                            }),
                            label
                        );
                    })()
                )
            );
        }

        if (other.length) {
            children.push(
                el(
                    'div',
                    { key: 'other', className: 'wc-block-components-totals-item wc-store-balance__note' },
                    el('span', null, sprintf(t('otherCurrencies'), other.join(' + ')))
                )
            );
        }

        if (applied > 0) {
            children.push(
                TotalsItem
                    ? el(TotalsItem, {
                        key: 'applied',
                        className: 'wc-store-balance-summary__applied',
                        label: data.label,
                        value: -applied,
                        currency: balance.currency,
                    })
                    : el(
                        'div',
                        { key: 'applied', className: 'wc-block-components-totals-item wc-store-balance-summary__applied' },
                        el('span', { className: 'wc-block-components-totals-item__label' }, data.label),
                        el('span', { className: 'wc-block-components-totals-item__value' }, '\u2212' + balance.format(applied))
                    )
            );

            if (toPay === 0) {
                children.push(
                    el('div', { key: 'covered', className: 'wc-block-components-totals-item wc-store-balance__note is-success' }, el('span', null, t('fullyCovered')))
                );
            }
        }

        if (!children.length) {
            return null;
        }

        return el('div', { className: 'wc-block-components-totals-wrapper wc-store-balance-summary' }, children);
    }

    wp.plugins.registerPlugin('wc-store-balance', {
        scope: 'woocommerce-checkout',
        render: function () {
            // Both go in the discounts slot, which sits above the total. The
            // order slot is below it, and an amount taken off the total has to
            // be read before the total it explains.
            return el(checkout.ExperimentalDiscountsMeta, null, el(CodeForm), el(Summary));
        },
    });
})(window.wp, window.wc);
