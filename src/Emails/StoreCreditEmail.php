<?php

namespace GeneroWP\StoreBalance\Emails;

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

    public function get_default_additional_content(): string
    {
        return '';
    }

    protected function accountUrl(): string
    {
        return wc_get_account_endpoint_url(Account::ENDPOINT_STORE_CREDIT);
    }
}
