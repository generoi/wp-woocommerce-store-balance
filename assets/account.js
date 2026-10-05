/*
 * The link in a gift card email carries the code after the "#", where it is
 * never sent to a server: not into access logs, not into analytics. This
 * picks it up, keeps it across the login form, and puts it in the "add a
 * gift card" field.
 */
(function () {
    var KEY = 'wc_store_balance_code';
    var match = /^#code=([A-Za-z0-9-]{4,24})$/.exec(window.location.hash || '');
    var code = '';

    try {
        if (match) {
            window.sessionStorage.setItem(KEY, match[1]);
            window.history.replaceState(null, '', window.location.pathname + window.location.search);
        }

        code = window.sessionStorage.getItem(KEY) || '';
    } catch (e) {
        code = match ? match[1] : '';
    }

    if (!code) {
        return;
    }

    var show = function () {
        var field = document.getElementById('store_balance_redeem_code');

        if (field) {
            if (!field.value) {
                field.value = code.toUpperCase();
            }

            try {
                window.sessionStorage.removeItem(KEY);
            } catch (e) {
                // Nothing to clear.
            }

            return;
        }

        var notice = document.querySelector('[data-store-balance-login-notice]');

        if (notice) {
            notice.hidden = false;
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', show);
    } else {
        show();
    }
})();
