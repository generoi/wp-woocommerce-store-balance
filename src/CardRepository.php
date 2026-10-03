<?php

namespace GeneroWP\StoreBalance;

use RuntimeException;

/**
 * The only place that reads or writes the two tables.
 *
 * Every change to a balance writes a transaction row in the same call, so the
 * ledger cannot drift from the balance it explains.
 */
class CardRepository
{
    public const TX_ISSUE = 'issue';

    public const TX_REDEEM = 'redeem';

    public const TX_DEBIT = 'debit';

    /** Money returned to the card it was taken from: a cancelled or failed order. */
    public const TX_RELEASE = 'release';

    public const TX_REFUND = 'refund';

    public const TX_ADJUST = 'adjust';

    public const TX_DISABLE = 'disable';

    public const TX_ENABLE = 'enable';

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException
     */
    public function create(array $data): Card
    {
        global $wpdb;

        $amount = Money::round($data['amount'] ?? 0);
        $type = (string) ($data['type'] ?? Card::TYPE_GIFT_CARD);
        $currency = strtoupper((string) ($data['currency'] ?? ''));

        if ($amount <= 0) {
            throw new RuntimeException('A card needs a positive amount.');
        }

        if (! in_array($type, Card::types(), true)) {
            throw new RuntimeException(sprintf('Unknown card type "%s".', $type));
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuntimeException(sprintf('Invalid currency "%s".', $currency));
        }

        $now = self::now();
        $row = [
            'type' => $type,
            'currency' => $currency,
            'initial_amount' => Money::sql($amount),
            'balance' => Money::sql($amount),
            'customer_id' => absint($data['customer_id'] ?? 0),
            'recipient_email' => Input::limit(sanitize_email(Input::text($data['recipient_email'] ?? '')), 200),
            'sender_name' => Input::limit(sanitize_text_field(Input::text($data['sender_name'] ?? '')), 200),
            'message' => sanitize_textarea_field(Input::text($data['message'] ?? '')),
            'locale' => sanitize_text_field((string) ($data['locale'] ?? '')),
            'order_id' => absint($data['order_id'] ?? 0),
            'order_item_id' => absint($data['order_item_id'] ?? 0),
            'status' => Card::STATUS_ACTIVE,
            'deliver_at' => self::datetime($data['deliver_at'] ?? null),
            'expires_at' => self::datetime($data['expires_at'] ?? null),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($row['customer_id'] > 0) {
            $row['redeemed_at'] = $now;
        }

        // The UNIQUE key is what guarantees uniqueness; a collision on 80 bits
        // is not expected, but the insert is retried rather than assumed.
        $id = 0;
        $suppress = $wpdb->suppress_errors(true);

        for ($attempt = 0; $attempt < 5 && ! $id; $attempt++) {
            $row['code'] = Code::generate();

            if ($wpdb->insert(Install::cardsTable(), array_filter($row, static fn ($value) => $value !== null))) {
                $id = (int) $wpdb->insert_id;
            }
        }

        $wpdb->suppress_errors($suppress);

        if (! $id) {
            throw new RuntimeException('Could not create the card: '.$wpdb->last_error);
        }

        $this->addTransaction($id, self::TX_ISSUE, $amount, [
            'order_id' => $row['order_id'],
            'note' => (string) ($data['note'] ?? ''),
        ]);

        $card = $this->find($id);

        if (! $card) {
            throw new RuntimeException('The card was created but could not be read back.');
        }

        /**
         * Fires when a gift card or store credit has been created.
         */
        do_action('wc_store_balance_card_created', $card, $data);

        return $card;
    }

    public function find(int $id): ?Card
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', Install::cardsTable(), $id));

        return $row ? new Card($row) : null;
    }

    public function findByCode(string $code): ?Card
    {
        global $wpdb;

        if (! Code::isValid($code)) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE code = %s', Install::cardsTable(), Code::normalize($code)));

        return $row ? new Card($row) : null;
    }

    /**
     * Every card bound to a customer account, soonest expiry first.
     *
     * @return Card[]
     */
    public function forCustomer(int $customerId, ?string $type = null): array
    {
        if ($customerId <= 0) {
            return [];
        }

        return $this->query(['customer_id' => $customerId, 'type' => $type, 'limit' => 500, 'orderby' => 'expiry']);
    }

    /**
     * @return Card[]
     */
    public function forOrder(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        return $this->query(['order_id' => $orderId, 'limit' => 500]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return Card[]
     */
    public function query(array $args = []): array
    {
        global $wpdb;

        [$where, $values] = $this->where($args);

        $order = ($args['orderby'] ?? '') === 'expiry'
            ? 'ORDER BY (expires_at IS NULL) ASC, expires_at ASC, id ASC'
            : 'ORDER BY id DESC';

        $values[] = max(1, min(500, (int) ($args['limit'] ?? 20)));
        $values[] = max(0, (int) ($args['offset'] ?? 0));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE {$where} {$order} LIMIT %d OFFSET %d", Install::cardsTable(), ...$values));

        return array_map(static fn ($row) => new Card($row), $rows ?: []);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function count(array $args = []): int
    {
        global $wpdb;

        [$where, $values] = $this->where($args);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$where}", Install::cardsTable(), ...$values));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected function where(array $args): array
    {
        global $wpdb;

        $where = ['1 = 1'];
        $values = [];

        foreach (['customer_id' => '%d', 'order_id' => '%d', 'type' => '%s', 'status' => '%s', 'currency' => '%s'] as $column => $format) {
            if (isset($args[$column]) && $args[$column] !== '') {
                $where[] = "{$column} = {$format}";
                $values[] = $args[$column];
            }
        }

        // What staff mean by a status, which is more than the status column:
        // a card can be active and still unusable because it is spent or expired.
        $now = self::now();

        switch ((string) ($args['state'] ?? '')) {
            case 'usable':
                $where[] = 'status = %s AND balance > 0 AND (expires_at IS NULL OR expires_at > %s)';
                array_push($values, Card::STATUS_ACTIVE, $now);
                break;
            case 'spent':
                $where[] = 'status = %s AND balance <= 0';
                $values[] = Card::STATUS_ACTIVE;
                break;
            case 'expired':
                $where[] = 'status = %s AND balance > 0 AND expires_at IS NOT NULL AND expires_at <= %s';
                array_push($values, Card::STATUS_ACTIVE, $now);
                break;
            case 'disabled':
                $where[] = 'status = %s';
                $values[] = Card::STATUS_DISABLED;
                break;
        }

        if (! empty($args['search'])) {
            $search = (string) $args['search'];
            $like = '%'.$wpdb->esc_like($search).'%';
            $code = Code::normalize($search);

            $clause = 'recipient_email LIKE %s OR sender_name LIKE %s';
            $values[] = $like;
            $values[] = $like;

            if ($code !== '') {
                $clause .= ' OR code LIKE %s';
                $values[] = '%'.$wpdb->esc_like($code).'%';
            }

            // "#64" or "64": the number a store credit goes by.
            if (preg_match('/^#?(\d+)$/', trim($search), $match)) {
                $clause .= ' OR id = %d';
                $values[] = (int) $match[1];
            }

            $users = get_users(['search' => '*'.$search.'*', 'search_columns' => ['user_email', 'user_login', 'display_name'], 'fields' => 'ID', 'number' => 50]);

            if ($users) {
                $clause .= ' OR customer_id IN ('.implode(',', array_map('absint', $users)).')';
            }

            $where[] = "({$clause})";
        }

        return [implode(' AND ', $where), $values];
    }

    /**
     * Take money from a card.
     *
     * One UPDATE that only matches while the balance covers the amount, so two
     * checkouts racing for the same card cannot both succeed. False means the
     * card could not cover it — or was disabled or expired in the meantime.
     *
     * @param  array<string, mixed>  $context
     */
    public function debit(int $id, float $amount, array $context = []): bool
    {
        global $wpdb;

        $amount = Money::round($amount);

        if ($amount <= 0) {
            return false;
        }

        $now = self::now();

        // The new balance is captured by the same statement that changes it.
        // Read in a second query it could already include someone else's
        // debit, and the ledger would not add up.
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE %i SET balance = (@wc_sb_balance := balance - %s), updated_at = %s
            WHERE id = %d AND status = %s AND balance >= %s AND (expires_at IS NULL OR expires_at > %s)',
            Install::cardsTable(),
            Money::sql($amount),
            $now,
            $id,
            Card::STATUS_ACTIVE,
            Money::sql($amount),
            $now
        ));

        if ($updated !== 1) {
            return false;
        }

        $this->addTransaction($id, self::TX_DEBIT, -$amount, $context + ['balance_after' => $wpdb->get_var('SELECT @wc_sb_balance')]);

        return true;
    }

    /**
     * Put money back on a card.
     *
     * Works on a disabled or expired card too: money that was taken from it and
     * is being returned belongs there regardless.
     *
     * Money returned to a card that has expired, or is about to, would be
     * returned to nowhere. The card gets a grace period instead, long enough
     * to spend what came back.
     *
     * @param  array<string, mixed>  $context
     */
    public function credit(int $id, float $amount, string $type = self::TX_RELEASE, array $context = []): bool
    {
        global $wpdb;

        $amount = Money::round($amount);

        if ($amount <= 0) {
            return false;
        }

        /**
         * Filters how many days a card stays valid, at least, after a balance
         * has been returned to it.
         */
        $grace = gmdate('Y-m-d H:i:s', time() + max(0, (int) apply_filters('wc_store_balance_returned_balance_grace_days', 30)) * DAY_IN_SECONDS);

        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE %i SET balance = (@wc_sb_balance := balance + %s), updated_at = %s,
            expires_at = CASE WHEN expires_at IS NOT NULL AND expires_at < %s THEN %s ELSE expires_at END
            WHERE id = %d',
            Install::cardsTable(),
            Money::sql($amount),
            self::now(),
            $grace,
            $grace,
            $id
        ));

        if ($updated !== 1) {
            return false;
        }

        $this->addTransaction($id, $type, $amount, $context + ['balance_after' => $wpdb->get_var('SELECT @wc_sb_balance')]);

        return true;
    }

    /**
     * Set the balance to an exact figure. Admin corrections only.
     */
    public function adjust(int $id, float $balance, string $note = ''): bool
    {
        global $wpdb;

        $balance = Money::round($balance);

        if ($balance < 0 || ! $this->find($id)) {
            return false;
        }

        // The old balance is read by the statement that replaces it, so the
        // ledger row is the true difference even if a checkout debits the
        // card at the same moment.
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE %i SET balance = %s, updated_at = %s WHERE id = %d AND (@wc_sb_old := balance) IS NOT NULL',
            Install::cardsTable(),
            Money::sql($balance),
            self::now(),
            $id
        ));

        if ($updated === false) {
            return false;
        }

        $old = (float) $wpdb->get_var('SELECT @wc_sb_old');

        $this->addTransaction($id, self::TX_ADJUST, Money::round($balance - $old), ['note' => $note, 'balance_after' => $balance]);

        return true;
    }

    /**
     * Bind a gift card to a customer account.
     *
     * Only matches an unbound card, so a code cannot be claimed twice.
     */
    public function redeem(int $id, int $customerId): bool
    {
        global $wpdb;

        if ($customerId <= 0) {
            return false;
        }

        $now = self::now();

        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE %i SET customer_id = %d, redeemed_at = %s, updated_at = %s WHERE id = %d AND customer_id = 0 AND type = %s',
            Install::cardsTable(),
            $customerId,
            $now,
            $now,
            $id,
            Card::TYPE_GIFT_CARD
        ));

        if ($updated !== 1) {
            return false;
        }

        $this->addTransaction($id, self::TX_REDEEM, 0, ['user_id' => $customerId]);

        return true;
    }

    public function setStatus(int $id, string $status, string $note = ''): bool
    {
        global $wpdb;

        if (! in_array($status, [Card::STATUS_ACTIVE, Card::STATUS_DISABLED], true)) {
            return false;
        }

        $updated = $wpdb->update(Install::cardsTable(), ['status' => $status, 'updated_at' => self::now()], ['id' => $id]);

        if (! $updated) {
            return false;
        }

        $this->addTransaction($id, $status === Card::STATUS_ACTIVE ? self::TX_ENABLE : self::TX_DISABLE, 0, ['note' => $note]);

        return true;
    }

    public function markDelivered(int $id): void
    {
        global $wpdb;

        $wpdb->update(Install::cardsTable(), ['delivered_at' => self::now(), 'updated_at' => self::now()], ['id' => $id]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function addTransaction(int $cardId, string $type, float $amount, array $context = []): void
    {
        global $wpdb;

        $balance = $context['balance_after'] ?? $wpdb->get_var($wpdb->prepare('SELECT balance FROM %i WHERE id = %d', Install::cardsTable(), $cardId));

        $wpdb->insert(Install::transactionsTable(), [
            'card_id' => $cardId,
            'type' => $type,
            'amount' => Money::sql($amount),
            'balance_after' => Money::sql((float) $balance),
            'order_id' => absint($context['order_id'] ?? 0),
            'user_id' => absint($context['user_id'] ?? get_current_user_id()),
            'note' => sanitize_textarea_field((string) ($context['note'] ?? '')),
            'created_at' => self::now(),
        ]);
    }

    /**
     * @param  int[]  $cardIds
     * @return array<int, object>
     */
    public function transactions(array $cardIds, int $limit = 50, int $offset = 0): array
    {
        global $wpdb;

        $cardIds = array_values(array_filter(array_map('absint', $cardIds)));

        if (! $cardIds) {
            return [];
        }

        $in = implode(',', $cardIds);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE card_id IN ({$in}) ORDER BY id DESC LIMIT %d OFFSET %d", Install::transactionsTable(), $limit, $offset)) ?: [];
    }

    /**
     * What the store owes, per type and currency. Expired and disabled cards
     * are not owed.
     *
     * @return array<int, object>
     */
    public function outstanding(): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT type, currency, COUNT(*) AS cards, SUM(balance) AS balance FROM %i
            WHERE status = %s AND balance > 0 AND (expires_at IS NULL OR expires_at > %s)
            GROUP BY type, currency ORDER BY type, currency',
            Install::cardsTable(),
            Card::STATUS_ACTIVE,
            self::now()
        )) ?: [];
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    protected static function datetime(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 0) {
            return null;
        }

        $time = is_numeric($value) ? (int) $value : strtotime((string) $value);

        return $time ? gmdate('Y-m-d H:i:s', $time) : null;
    }
}
