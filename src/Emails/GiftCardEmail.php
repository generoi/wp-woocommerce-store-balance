<?php

namespace GeneroWP\StoreBalance\Emails;

use GeneroWP\StoreBalance\Modules\Account;

class GiftCardEmail extends CardEmail
{
    public function __construct()
    {
        $this->id = 'store_balance_gift_card';
        $this->title = __('Gift card', 'wp-woocommerce-store-balance');
        $this->description = __('Sent to the recipient of a gift card, with the code.', 'wp-woocommerce-store-balance');
        $this->template_html = 'emails/gift-card.php';
        $this->template_plain = 'emails/plain/gift-card.php';
        $this->placeholders = ['{amount}' => '', '{sender}' => ''];

        parent::__construct();
    }

    public function get_default_subject(): string
    {
        return __('You have received a {amount} gift card from {sender}', 'wp-woocommerce-store-balance');
    }

    public function get_default_heading(): string
    {
        return __('A gift card for you', 'wp-woocommerce-store-balance');
    }

    public function get_default_additional_content(): string
    {
        return '';
    }

    /**
     * Opens the gift card page with the code filled in. Adding it to the
     * account still takes a click: a link that redeemed on its own would bind
     * the card to whoever happened to be logged in when it was opened.
     */
    protected function accountUrl(): string
    {
        return $this->card
            ? add_query_arg('code', $this->card->formattedCode(), wc_get_account_endpoint_url(Account::ENDPOINT_GIFT_CARDS))
            : '';
    }
}
