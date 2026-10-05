<?php

namespace GeneroWP\StoreBalance\Emails;

use GeneroWP\StoreBalance\Card;
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

    /**
     * A card with no sender was bought by the person receiving it, or created
     * by the shop. "You have received a gift card from yourself" is not what
     * either of them should read.
     */
    public function get_default_subject(): string
    {
        return $this->card && $this->card->senderName === ''
            ? __('Your {amount} gift card from {site_title}', 'wp-woocommerce-store-balance')
            : __('You have received a {amount} gift card from {sender}', 'wp-woocommerce-store-balance');
    }

    public function get_default_heading(): string
    {
        return $this->card && $this->card->senderName === ''
            ? __('Your gift card is ready', 'wp-woocommerce-store-balance')
            : __('A gift card for you', 'wp-woocommerce-store-balance');
    }

    protected function sampleCard(): Card
    {
        return new Card([
            'code' => 'GIFT0000CARD0001', // Has 0, 1 and I in it: never a real code.
            'type' => Card::TYPE_GIFT_CARD,
            'currency' => get_woocommerce_currency(),
            'initial_amount' => 50,
            'balance' => 50,
            'sender_name' => 'Anna',
            'message' => __('Happy birthday!', 'wp-woocommerce-store-balance'),
            'recipient_email' => 'recipient@example.com',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + YEAR_IN_SECONDS),
        ]);
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
        // After the "#", not as a query argument: a code in the query string
        // ends up in access logs, browser history sync and analytics.
        return wc_get_account_endpoint_url(Account::ENDPOINT_GIFT_CARDS).'#code='.rawurlencode($this->cardOrSample()->formattedCode());
    }
}
