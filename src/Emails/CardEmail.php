<?php

namespace GeneroWP\StoreBalance\Emails;

use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\Money;
use GeneroWP\StoreBalance\Plugin;
use WC_Email;

/**
 * What the two card emails share: they are about a Card rather than an order,
 * and they are written in the recipient's language rather than the site's.
 */
abstract class CardEmail extends WC_Email
{
    public ?Card $card = null;

    public function __construct()
    {
        $this->customer_email = true;
        $this->template_base = Plugin::path('templates/');

        parent::__construct();
    }

    public function trigger(Card $card): bool
    {
        $this->card = $card;
        $this->object = $card;
        $this->recipient = $card->recipientEmail;

        /**
         * Filters the locale a card email is written in.
         *
         * A gift card carries the locale the buyer was shopping in; store
         * credit carries the customer's own. A multilingual plugin can map
         * either to the language the recipient should get.
         */
        $locale = (string) apply_filters('wc_store_balance_email_locale', $card->locale, $card);

        $switched = $locale !== '' && $locale !== determine_locale() && switch_to_locale($locale);

        $this->placeholders = array_merge($this->placeholders, [
            // Plain text: a subject line is not HTML, and "50,00&nbsp;&euro;"
            // would be shown exactly like that.
            '{amount}' => Money::plain($card->initialAmount, $card->currency),
            '{sender}' => $card->senderName,
        ]);

        $sent = false;

        if ($this->is_enabled() && $this->get_recipient()) {
            $sent = (bool) $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
        }

        if ($switched) {
            restore_previous_locale();
        }

        return $sent;
    }

    /**
     * @return array<string, mixed>
     */
    protected function templateArgs(bool $plain): array
    {
        // WooCommerce's email preview renders the template without ever
        // calling trigger(), so there is no card. Show a sample one.
        $card = $this->card ?? $this->sampleCard();

        return [
            'card' => $card,
            'amount' => wc_price($card->balance, ['currency' => $card->currency]),
            'expires' => $card->expiresAt ? wp_date(wc_date_format(), $card->expiresAt) : '',
            'shop_url' => wc_get_page_permalink('shop'),
            'account_url' => $this->accountUrl(),
            'email_heading' => $this->get_heading(),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin' => false,
            'plain_text' => $plain,
            'email' => $this,
        ];
    }

    abstract protected function accountUrl(): string;

    abstract protected function sampleCard(): Card;

    public function get_content_html(): string
    {
        return wc_get_template_html($this->template_html, $this->templateArgs(false), 'woocommerce/store-balance/', $this->template_base);
    }

    public function get_content_plain(): string
    {
        return wc_get_template_html($this->template_plain, $this->templateArgs(true), 'woocommerce/store-balance/', $this->template_base);
    }
}
