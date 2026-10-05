<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\Emails\CardEmail;
use GeneroWP\StoreBalance\Modules\Emails;
use GeneroWP\StoreBalance\Money;
use GeneroWP\StoreBalance\Plugin;

class EmailsTest extends TestCase
{
    protected function email(string $key): CardEmail
    {
        $email = WC()->mailer()->get_emails()[$key];
        // The mailer keeps one object per email; an earlier test may have
        // left its card on it.
        $email->card = null;
        $email->object = null;
        // ...or the subject and heading it was first asked for.
        $email->init_settings();

        return $email;
    }

    /**
     * The plain-text email as it is sent: WooCommerce strips tags from the
     * template's output and turns the entities back into characters.
     */
    protected function plain(CardEmail $email): string
    {
        $email->email_type = 'plain';
        $content = $email->get_content();
        $email->email_type = 'html';

        return $content;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function emails(): array
    {
        return [
            'gift card' => ['WC_Store_Balance_Gift_Card'],
            'store credit' => ['WC_Store_Balance_Store_Credit'],
        ];
    }

    public function test_both_emails_are_registered_with_woocommerce(): void
    {
        $emails = WC()->mailer()->get_emails();

        $this->assertInstanceOf(CardEmail::class, $emails['WC_Store_Balance_Gift_Card']);
        $this->assertInstanceOf(CardEmail::class, $emails['WC_Store_Balance_Store_Credit']);
        $this->assertTrue($emails['WC_Store_Balance_Gift_Card']->is_customer_email());
    }

    /**
     * WooCommerce → Settings → Emails draws a preview of every email without
     * there being a card. On a site that turns warnings into errors a
     * template that assumes one takes the settings screen down.
     *
     * @dataProvider emails
     */
    public function test_the_preview_renders_without_a_card(string $key): void
    {
        $email = $this->email($key);

        $html = $email->get_content_html();
        $plain = $this->plain($email);

        $this->assertStringContainsString('</', $html);
        $this->assertNotSame('', trim($email->get_content_plain()));
        $this->assertNotSame('', trim($plain));
        $this->assertStringNotContainsString('<', $plain);
        // A sample amount, not a gap where the amount should be.
        $this->assertMatchesRegularExpression('/\d+[.,]\d{2}/', wp_strip_all_tags($html));
        $this->assertMatchesRegularExpression('/\d+[.,]\d{2}/', $plain);
    }

    /**
     * The settings screen also shows the subject, filled in the way
     * WooCommerce's preview fills it: with whatever the email offers through
     * woocommerce_email_preview_placeholders. "You have  in store credit" is
     * what the shop owner would otherwise proofread.
     *
     * @dataProvider emails
     */
    public function test_the_preview_subject_and_heading_have_no_gaps(string $key): void
    {
        $email = $this->email($key);
        $before = $email->placeholders;

        $email->placeholders = array_merge($email->placeholders, (array) apply_filters('woocommerce_email_preview_placeholders', [], get_class($email), null));
        $texts = [$email->get_subject(), $email->get_heading()];
        $email->placeholders = $before;

        foreach ($texts as $text) {
            $this->assertStringNotContainsString('{', $text);
            $this->assertStringNotContainsString('  ', $text);
            $this->assertSame(trim($text), $text);
        }
    }

    public function test_the_gift_card_email_shows_the_card_it_was_given(): void
    {
        $card = $this->giftCard(75, ['sender_name' => 'Aino', 'message' => "Onnea <3\nja halauksia", 'recipient_email' => 'friend@example.org', 'expires_at' => strtotime('2031-05-17 12:00:00 UTC')]);
        $email = $this->email('WC_Store_Balance_Gift_Card');
        $email->card = $card;

        $html = $email->get_content_html();
        $plain = $this->plain($email);

        foreach ([$html, $plain] as $body) {
            $this->assertStringContainsString($card->formattedCode(), $body);
            $this->assertStringContainsString('Aino', $body);
            $this->assertStringContainsString('2031', $body);
            $this->assertStringContainsString('75', $body);
            $this->assertStringContainsString('ja halauksia', $body);
        }

        $this->assertStringContainsString('Onnea &lt;3', $html);
        $this->assertStringContainsString(rawurlencode($card->formattedCode()), rawurlencode($html));
        // Plain text is not HTML: nothing in it is escaped.
        $this->assertStringContainsString('Onnea <3', $plain);
        $this->assertDoesNotMatchRegularExpression('/&[a-z#0-9]+;/i', $plain);
    }

    public function test_the_store_credit_email_shows_the_amount_and_never_a_code(): void
    {
        $card = $this->storeCredit($this->customer(), 12.5, ['expires_at' => strtotime('2031-05-17 12:00:00 UTC')]);
        $email = $this->email('WC_Store_Balance_Store_Credit');
        $email->card = $card;

        foreach ([$email->get_content_html(), $email->get_content_plain()] as $body) {
            $this->assertMatchesRegularExpression('/12[.,]50/', $body);
            $this->assertStringContainsString('2031', $body);
            $this->assertStringContainsString('store-credit', $body);
            $this->assertStringNotContainsString($card->formattedCode(), $body);
            $this->assertStringNotContainsString($card->code, $body);
            $this->assertStringNotContainsString(substr($card->code, -4), $body);
        }

        $this->assertDoesNotMatchRegularExpression('/&[a-z#0-9]+;/i', $this->plain($email));
    }

    /**
     * "You have received a gift card from yourself" — or from nobody — is
     * not what someone who bought a card for themselves should read.
     */
    public function test_a_card_bought_for_oneself_is_announced_as_ready_not_as_a_gift(): void
    {
        $emails = Plugin::getInstance()->module(Emails::class);

        // One request, two cards: an order with a present for a friend and
        // a card for oneself.
        $this->email('WC_Store_Balance_Gift_Card');
        $emails->send($this->giftCard(50, ['recipient_email' => 'gifted@example.org', 'sender_name' => 'Aino']));
        $emails->send($this->giftCard(50, ['recipient_email' => 'self@example.org', 'sender_name' => '']));

        $self = $this->emailsTo('self@example.org')[0];
        $gifted = $this->emailsTo('gifted@example.org')[0];

        $this->assertStringStartsWith('Your ', $self->subject);
        $this->assertStringNotContainsString('received', $self->subject);
        $this->assertStringContainsString('Your gift card is ready', $self->body);
        $this->assertStringNotContainsString('has sent you', $self->body);

        $this->assertStringContainsString('from Aino', $gifted->subject);
        $this->assertStringContainsString('A gift card for you', $gifted->body);
        $this->assertStringNotContainsString('Your gift card is ready', $gifted->body);
    }

    /**
     * The other way round: the card for oneself first, the present second.
     * Whichever is sent first must not decide the wording of the next.
     */
    public function test_a_gift_sent_after_a_self_bought_card_in_the_same_request_is_still_a_gift(): void
    {
        $emails = Plugin::getInstance()->module(Emails::class);

        $this->email('WC_Store_Balance_Gift_Card');
        $emails->send($this->giftCard(50, ['recipient_email' => 'self@example.org', 'sender_name' => '']));
        $emails->send($this->giftCard(25, ['recipient_email' => 'gifted@example.org', 'sender_name' => 'Aino']));
        $emails->send($this->giftCard(10, ['recipient_email' => 'self2@example.org', 'sender_name' => '']));

        $subjects = array_map(fn (string $to) => $this->emailsTo($to)[0]->subject, ['self@example.org', 'gifted@example.org', 'self2@example.org']);
        $bodies = array_map(fn (string $to) => $this->emailsTo($to)[0]->body, ['self@example.org', 'gifted@example.org', 'self2@example.org']);

        $this->assertStringStartsWith('Your ', $subjects[0]);
        $this->assertStringContainsString('50', $subjects[0]);
        $this->assertStringContainsString('from Aino', $subjects[1]);
        $this->assertStringContainsString('25', $subjects[1]);
        $this->assertStringStartsWith('Your ', $subjects[2]);
        $this->assertStringContainsString('10', $subjects[2]);

        $this->assertStringContainsString('Your gift card is ready', $bodies[0]);
        $this->assertStringContainsString('A gift card for you', $bodies[1]);
        $this->assertStringContainsString('Your gift card is ready', $bodies[2]);
    }

    /**
     * A subject or heading the shop owner wrote themselves is used for every
     * card, gifted or not.
     */
    public function test_a_subject_saved_in_the_settings_wins_over_both_defaults(): void
    {
        $email = $this->email('WC_Store_Balance_Gift_Card');
        update_option($email->get_option_key(), ['enabled' => 'yes', 'subject' => 'Lahjakortti {amount}', 'heading' => 'Oma otsikko']);
        $email->init_settings();

        $emails = Plugin::getInstance()->module(Emails::class);
        $emails->send($this->giftCard(50, ['recipient_email' => 'self@example.org', 'sender_name' => '']));
        $emails->send($this->giftCard(50, ['recipient_email' => 'gifted@example.org', 'sender_name' => 'Aino']));

        foreach (['self@example.org', 'gifted@example.org'] as $to) {
            $mail = $this->emailsTo($to)[0];

            $this->assertStringStartsWith('Lahjakortti ', $mail->subject);
            $this->assertStringContainsString('50', $mail->subject);
            $this->assertStringContainsString('Oma otsikko', $mail->body);
        }
    }

    /**
     * A subject line is plain text. An entity in it arrives as an entity.
     */
    public function test_the_subject_is_plain_text(): void
    {
        update_option('woocommerce_currency_pos', 'right_space');

        Plugin::getInstance()->module(Emails::class)->send($this->giftCard(50, ['recipient_email' => 'gifted@example.org', 'sender_name' => 'Aino & Co']));

        $subject = $this->emailsTo('gifted@example.org')[0]->subject;

        $this->assertStringContainsString('€', $subject);
        $this->assertStringContainsString('Aino & Co', $subject);
        $this->assertDoesNotMatchRegularExpression('/&[a-z#0-9]+;|</i', $subject);
    }

    /**
     * The email is about the card as it was given, whatever has been spent
     * from it by the time a copy is sent again.
     */
    public function test_a_card_can_be_emailed_in_the_plain_text_format(): void
    {
        update_option('woocommerce_store_balance_gift_card_settings', ['enabled' => 'yes', 'email_type' => 'plain']);
        WC()->mailer()->get_emails()['WC_Store_Balance_Gift_Card']->init_settings();
        WC()->mailer()->get_emails()['WC_Store_Balance_Gift_Card']->email_type = 'plain';

        $card = $this->giftCard(50, ['recipient_email' => 'plain@example.org', 'sender_name' => 'Aino', 'message' => 'A "quoted" & plain message']);
        $sent = Plugin::getInstance()->module(Emails::class)->send($card);

        WC()->mailer()->get_emails()['WC_Store_Balance_Gift_Card']->email_type = 'html';

        $body = $this->emailsTo('plain@example.org')[0]->body;

        $this->assertTrue($sent);
        $this->assertStringContainsString($card->formattedCode(), $body);
        $this->assertStringContainsString('A "quoted" & plain message', $body);
        $this->assertStringNotContainsString('<', $body);
    }

    public function test_a_disabled_email_is_not_sent_and_the_card_is_not_marked_delivered(): void
    {
        $email = WC()->mailer()->get_emails()['WC_Store_Balance_Gift_Card'];
        $email->enabled = 'no';

        $card = $this->giftCard(50, ['recipient_email' => 'off@example.org']);
        $sent = Plugin::getInstance()->module(Emails::class)->send($card);

        $email->enabled = 'yes';

        $this->assertFalse($sent);
        $this->assertCount(0, $this->emailsTo('off@example.org'));
        $this->assertNull($this->cards->find($card->id)->deliveredAt);
    }

    /**
     * wc_price() returns HTML. In an order note, a subject or a log line
     * "50,00&nbsp;&euro;" is shown exactly like that.
     */
    public function test_a_plain_price_has_no_markup_and_no_entities(): void
    {
        update_option('woocommerce_currency_pos', 'right_space');
        update_option('woocommerce_price_decimal_sep', ',');

        foreach ([Money::plain(50), Money::plain(1234.5, 'SEK'), Money::plain('0.1', 'USD'), Money::plain(99.99, 'GBP')] as $price) {
            $this->assertDoesNotMatchRegularExpression('/&[a-z#0-9]+;|[<>]/i', $price);
            $this->assertSame(trim($price), $price);
        }

        $this->assertSame("50,00\u{a0}€", Money::plain(50));
        // Another currency than the shop's is named by its code, not by a
        // symbol a currency switcher may have replaced.
        $this->assertStringEndsWith('SEK', Money::plain(1234.5, 'SEK'));
        $this->assertStringEndsWith('GBP', Money::plain(99.99, 'GBP'));
    }

    public function test_the_log_and_the_order_notes_use_plain_prices(): void
    {
        [$order] = $this->orderPaidPartlyWithStoreCredit(50);
        $order->update_status('cancelled');

        $notes = implode("\n", array_map(static fn ($note) => $note->content, wc_get_order_notes(['order_id' => $order->get_id()])));

        $this->assertStringContainsString('returned to', $notes);
        $this->assertDoesNotMatchRegularExpression('/&nbsp;|&euro;|&#8364;|<span/i', $notes);
    }

    /**
     * Store credit has a code column like every card. It is not a Card the
     * gift card email may ever be sent for.
     */
    public function test_each_kind_of_card_gets_its_own_email(): void
    {
        $customerId = $this->customer('dev3-kind@example.org');
        $emails = Plugin::getInstance()->module(Emails::class);

        $credit = $this->storeCredit($customerId, 10, ['recipient_email' => 'dev3-kind@example.org']);
        $emails->send($credit);

        $mail = $this->emailsTo('dev3-kind@example.org')[0];

        $this->assertStringContainsString('store credit', $mail->subject);
        $this->assertStringNotContainsString($credit->formattedCode(), $mail->body);
        $this->assertSame(Card::TYPE_STORE_CREDIT, $credit->type);
    }

    /**
     * A code in the query string is written to access logs and sent to
     * analytics. After the "#" it never leaves the browser.
     */
    public function test_the_link_in_the_gift_card_email_keeps_the_code_out_of_the_query_string(): void
    {
        $card = $this->giftCard(50, ['recipient_email' => 'friend@example.org']);
        Plugin::getInstance()->module(Emails::class)->send($card);

        $mail = $this->emailsTo('friend@example.org')[0];
        $body = (string) $mail->body;

        $this->assertStringContainsString('#code='.$card->formattedCode(), $body);
        $this->assertStringNotContainsString('?code=', $body);
        $this->assertStringNotContainsString('&code=', $body);
        $this->assertStringNotContainsString('&amp;code=', $body);
    }
}
