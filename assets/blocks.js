/**
 * Gift cards and store credit in the cart and checkout blocks.
 *
 * Two pieces, both rendered into the discounts slot of the order summary, next
 * to the coupon form: a form to enter a gift card code, and a summary of what
 * the balance pays for.
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
    var INPUT_ID = 'wc-store-balance-code';

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

    function speak(message, assertive) {
        if (wp.a11y && wp.a11y.speak && message) {
            wp.a11y.speak(message, assertive ? 'assertive' : 'polite');
        }
    }

    function int(value) {
        return parseInt(value, 10) || 0;
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
        var code = String((error && error.code) || '');

        // Only what this plugin said. Anything else — a gateway timeout, a
        // proxy's error page — comes with a message written for developers
        // ("The response is not a valid JSON response.").
        if (code.indexOf('wc_store_balance') !== 0) {
            message = '';
        }

        // The server sends messages with entities encoded.
        if (message) {
            var area = document.createElement('textarea');
            area.innerHTML = message;

            return area.value;
        }

        return t('genericError');
    }

    /**
     * After the cart has been redrawn. Nothing in the form is ever `disabled`
     * while a request runs — a disabled element drops keyboard focus to the
     * top of the page — so the field is still there to return to.
     */
    function focusInput() {
        window.setTimeout(function () {
            var field = document.getElementById(INPUT_ID);

            if (field) {
                field.focus();
            }
        }, 0);
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

        // The message is rendered with role="alert", which announces it.
        function fail(message) {
            error[1](message);
            focusInput();
        }

        function apply(event) {
            event.preventDefault();

            if (busy[0]) {
                return;
            }

            if (!code[0].trim()) {
                fail(t('emptyCode'));

                return;
            }

            busy[1](true);
            error[1]('');

            update({ action: 'apply', code: code[0] })
                .then(function () {
                    code[1]('');
                    speak(t('applied'));
                    focusInput();
                })
                .catch(function (e) {
                    fail(errorMessage(e));
                })
                .finally(function () {
                    busy[1](false);
                });
        }

        function remove(id) {
            if (busy[0]) {
                return;
            }

            busy[1](true);

            update({ action: 'remove', id: id })
                .then(function () {
                    speak(t('removed'));
                    focusInput();
                })
                .catch(function (e) {
                    fail(errorMessage(e));
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
                            id: INPUT_ID,
                            className: 'wc-block-components-totals-coupon__input wc-store-balance-form__input' + (error[0] ? ' has-error' : ''),
                            label: t('inputLabel'),
                            value: code[0],
                            autoComplete: 'off',
                            autoCapitalize: 'characters',
                            ariaDescribedBy: error[0] ? 'wc-store-balance-error' : undefined,
                            'aria-invalid': error[0] ? 'true' : undefined,
                            onChange: setCode,
                        })
                        : el('input', {
                            id: INPUT_ID,
                            type: 'text',
                            className: 'wc-store-balance-form__input',
                            value: code[0],
                            'aria-label': t('inputLabel'),
                            'aria-invalid': error[0] ? 'true' : undefined,
                            placeholder: t('inputLabel'),
                            autoComplete: 'off',
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
                                'aria-disabled': busy[0] ? 'true' : undefined,
                            },
                            busy[0] ? t('applying') : t('apply')
                        )
                        : el('button', { type: 'submit', className: 'wp-element-button', 'aria-disabled': busy[0] ? 'true' : undefined }, t('apply'))
                ),
            error[0]
                ? el('p', { id: 'wc-store-balance-error', className: 'wc-store-balance__error', role: 'alert' }, error[0])
                : null,
            codes.length
                ? el(
                    'ul',
                    { className: 'wc-store-balance-form__codes' },
                    codes.map(function (line) {
                        var used = int(line.amount);
                        var available = int(line.available);
                        var detail = line.reason
                            ? line.reason
                            : used > 0
                                ? sprintf(t('usedLeft'), balance.format(used), balance.format(available - used))
                                : t('notUsed');

                        return el(
                            'li',
                            { key: line.id, className: 'wc-store-balance-form__code' + (line.reason ? ' has-reason' : '') },
                            el(
                                'span',
                                { className: 'wc-store-balance-form__code-text' },
                                el('span', { className: 'wc-store-balance-form__code-name' }, sprintf(t('giftCard'), line.masked)),
                                el('span', { className: 'wc-store-balance-form__code-detail' }, detail)
                            ),
                            el(
                                'button',
                                {
                                    type: 'button',
                                    className: 'wc-store-balance-form__remove',
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
     * amounts taken off, above the total they reduce.
     */
    function Summary(props) {
        var balance = useBalance(props);
        var data = balance.data;
        var busy = useState(false);

        if (!data) {
            return null;
        }

        var account = data.account || {};
        var available = int(account.available);
        var used = int(account.used);
        var giftCards = int(account.gift_cards);
        var storeCredit = int(account.store_credit);
        var applied = int(data.applied_total);
        var excluded = int(data.excluded_total);
        var toPay = int(balance.totals.total_price);
        var other = account.other_currencies || [];
        var rows = data.rows || [];
        var children = [];

        function toggle(checked) {
            if (busy[0]) {
                return;
            }

            busy[1](true);

            update({ action: 'use_balance', value: checked })
                .then(function () {
                    speak(checked ? t('balanceOn') : t('balanceOff'));
                })
                .finally(function () {
                    busy[1](false);
                });
        }

        if (available > 0 && !data.only_gift_cards) {
            var details = [];

            if (giftCards > 0 && storeCredit > 0) {
                details.push(sprintf(t('breakdown'), balance.format(giftCards), balance.format(storeCredit)));
            }

            if (data.use_balance && used > 0) {
                details.push(sprintf(t('accountUsedLeft'), balance.format(used), balance.format(available - used)));
            } else if (data.use_balance) {
                details.push(t('accountNotNeeded'));
            } else {
                details.push(t('accountSaved'));
            }

            var label = el(
                'span',
                null,
                el('span', { className: 'wc-store-balance-summary__toggle-label' }, sprintf(t('useBalance'), balance.format(available))),
                details.map(function (detail, index) {
                    return el('span', { key: index, className: 'wc-store-balance-summary__detail' }, detail);
                })
            );

            children.push(
                el(
                    'div',
                    { key: 'account', className: 'wc-store-balance-summary__account' },
                    CheckboxControl
                        ? el(
                            CheckboxControl,
                            {
                                id: 'wc-store-balance-use',
                                className: 'wc-store-balance-summary__toggle',
                                checked: !!data.use_balance,
                                onChange: toggle,
                            },
                            label
                        )
                        : el(
                            'label',
                            { className: 'wc-store-balance-summary__toggle' },
                            el('input', {
                                type: 'checkbox',
                                checked: !!data.use_balance,
                                onChange: function (event) {
                                    toggle(event.target.checked);
                                },
                            }),
                            label
                        )
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

        // One row per kind of balance, so a gift card and store credit used
        // together are not folded into a figure nobody can check.
        rows.forEach(function (row, index) {
            var amount = int(row.amount);

            children.push(
                TotalsItem
                    ? el(TotalsItem, {
                        key: 'row-' + index,
                        className: 'wc-store-balance-summary__applied',
                        label: row.label,
                        value: -amount,
                        currency: balance.currency,
                    })
                    : el(
                        'div',
                        { key: 'row-' + index, className: 'wc-block-components-totals-item wc-store-balance-summary__applied' },
                        el('span', { className: 'wc-block-components-totals-item__label' }, row.label),
                        el('span', { className: 'wc-block-components-totals-item__value' }, '−' + balance.format(amount))
                    )
            );
        });

        if (applied > 0 && excluded > 0 && !data.only_gift_cards) {
            children.push(
                el(
                    'div',
                    { key: 'excluded', className: 'wc-block-components-totals-item wc-store-balance__note' },
                    el('span', null, sprintf(t('excluded'), balance.format(excluded)))
                )
            );
        }

        if (applied > 0 && toPay === 0) {
            children.push(
                el(
                    'div',
                    { key: 'covered', className: 'wc-block-components-totals-item wc-store-balance__note is-success' },
                    el('span', null, t('covered_' + (data.kind || 'both')) || t('covered_both'))
                )
            );
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

    /**
     * The total is no longer the price of the order once a balance has paid
     * part of it: it is what is left, and after what. Said so wherever the
     * total is printed — including the summary at the top of the mobile
     * checkout, which has no slot for the rows above and would otherwise show
     * a subtotal and a total that do not add up.
     */
    var registerFilters = checkout.registerCheckoutFilters || checkout.__experimentalRegisterCheckoutFilters;

    if (registerFilters) {
        registerFilters(NAMESPACE, {
            totalLabel: function (label, extensions) {
                var data = extensions && extensions[NAMESPACE];

                return data && int(data.applied_total) > 0 ? t('toPay_' + (data.kind || 'both')) || t('toPay_both') : label;
            },
        });
    }
})(window.wp, window.wc);
