<?php

namespace GeneroWP\StoreBalance\Emails;

use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\Modules\Account;

class StoreCreditEmail extends CardEmail
{
    public function __construct()
    {
        $this->id = 'store_balance_store_credit';
        $this->title = __('Store credit', 'wp-woocommerce-store-balance');
        $this->description = __('Sent to a customer when store credit is added to their account. It carries no code: the credit is tied to the account.', 'wp-woocommerce-store-balance');
        $this->template_html = 'emails/store-credit.php';
        $this->template_plain = 'emails/plain/store-credit.php';
        $this->placeholders = ['{amount}' => '', '{sender}' => ''];

        parent::__construct();
    }

    public function get_default_subject(): string
    {
        return __('You have {amount} in store credit at {site_title}', 'wp-woocommerce-store-balance');
    }

    public function get_default_heading(): string
    {
        return __('You have store credit', 'wp-woocommerce-store-balance');
    }

    protected function sampleCard(): Card
    {
        return new Card([
            'code' => 'GIFT0000CARD0001', // Has 0, 1 and I in it: never a real code.
            'type' => Card::TYPE_STORE_CREDIT,
            'currency' => get_woocommerce_currency(),
            'initial_amount' => 25,
            'balance' => 25,
            'customer_id' => 1,
            'recipient_email' => 'customer@example.com',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + YEAR_IN_SECONDS),
        ]);
    }

    public function get_default_additional_content(): string
    {
        return '';
    }

    protected function accountUrl(): string
    {
        return $this->accountEndpointUrl(Account::ENDPOINT_STORE_CREDIT);
    }
}
