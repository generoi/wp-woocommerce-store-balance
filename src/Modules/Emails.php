<?php

namespace GeneroWP\StoreBalance\Modules;

use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\Emails\GiftCardEmail;
use GeneroWP\StoreBalance\Emails\StoreCreditEmail;
use GeneroWP\StoreBalance\Logger;
use GeneroWP\StoreBalance\Module;
use GeneroWP\StoreBalance\Plugin;
use Throwable;

class Emails implements Module
{
    public const ACTION_DELIVER = 'wc_store_balance_deliver_card';

    public function register(): void
    {
        add_filter('woocommerce_email_classes', [$this, 'classes']);
        add_action(self::ACTION_DELIVER, [$this, 'deliverScheduled']);
    }

    /**
     * @param  array<string, \WC_Email>  $emails
     * @return array<string, \WC_Email>
     */
    public function classes($emails)
    {
        $emails['WC_Store_Balance_Gift_Card'] = new GiftCardEmail;
        $emails['WC_Store_Balance_Store_Credit'] = new StoreCreditEmail;

        return $emails;
    }

    /**
     * Send now, or on the day the buyer chose.
     */
    public function deliver(Card $card): void
    {
        if ($card->deliverAt && $card->deliverAt > time() && function_exists('as_schedule_single_action')) {
            if (! as_next_scheduled_action(self::ACTION_DELIVER, [$card->id], 'wc-store-balance')) {
                as_schedule_single_action($card->deliverAt, self::ACTION_DELIVER, [$card->id], 'wc-store-balance');
            }

            return;
        }

        $this->send($card);
    }

    public function deliverScheduled($cardId): void
    {
        $card = Plugin::getInstance()->cards()->find((int) $cardId);

        // Withdrawn in the meantime — the order was cancelled or refunded.
        if (! $card || ! $card->isActive()) {
            return;
        }

        $this->send($card);
    }

    /**
     * Email the card to its recipient. True when the mail was handed over.
     */
    public function send(Card $card): bool
    {
        try {
            $emails = WC()->mailer()->get_emails();
            $email = $emails[$card->isStoreCredit() ? 'WC_Store_Balance_Store_Credit' : 'WC_Store_Balance_Gift_Card'] ?? null;

            if (! $email || ! method_exists($email, 'trigger')) {
                Logger::error('The email class for a card is not registered', ['card_id' => $card->id, 'type' => $card->type]);

                return false;
            }

            $sent = (bool) $email->trigger($card);
        } catch (Throwable $e) {
            Logger::exception($e, 'Sending a card email', ['card_id' => $card->id]);

            return false;
        }

        if ($sent) {
            Plugin::getInstance()->cards()->markDelivered($card->id);
        } else {
            Logger::warning('A card email was not sent', ['card_id' => $card->id, 'recipient' => $card->recipientEmail]);
        }

        return $sent;
    }
}
