<?php

namespace GeneroWP\StoreBalance;

/**
 * One row of the cards table. Gift cards and store credit are the same thing
 * with a different type: a balance in one currency that is spent like money.
 */
class Card
{
    public const TYPE_GIFT_CARD = 'giftcard';

    public const TYPE_STORE_CREDIT = 'store_credit';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public int $id;

    public string $code;

    public string $type;

    public string $currency;

    public float $initialAmount;

    public float $balance;

    public int $customerId;

    public string $recipientEmail;

    public string $senderName;

    public string $message;

    public string $locale;

    public int $orderId;

    public int $orderItemId;

    public string $status;

    public ?int $deliverAt;

    public ?int $deliveredAt;

    public ?int $redeemedAt;

    public ?int $expiresAt;

    public int $createdAt;

    /**
     * @param  object|array<string, mixed>  $row
     */
    public function __construct(object|array $row)
    {
        $row = (array) $row;

        $this->id = (int) ($row['id'] ?? 0);
        $this->code = (string) ($row['code'] ?? '');
        $this->type = (string) ($row['type'] ?? self::TYPE_GIFT_CARD);
        $this->currency = (string) ($row['currency'] ?? '');
        $this->initialAmount = (float) ($row['initial_amount'] ?? 0);
        $this->balance = (float) ($row['balance'] ?? 0);
        $this->customerId = (int) ($row['customer_id'] ?? 0);
        $this->recipientEmail = (string) ($row['recipient_email'] ?? '');
        $this->senderName = (string) ($row['sender_name'] ?? '');
        $this->message = (string) ($row['message'] ?? '');
        $this->locale = (string) ($row['locale'] ?? '');
        $this->orderId = (int) ($row['order_id'] ?? 0);
        $this->orderItemId = (int) ($row['order_item_id'] ?? 0);
        $this->status = (string) ($row['status'] ?? self::STATUS_ACTIVE);
        $this->deliverAt = self::timestamp($row['deliver_at'] ?? null);
        $this->deliveredAt = self::timestamp($row['delivered_at'] ?? null);
        $this->redeemedAt = self::timestamp($row['redeemed_at'] ?? null);
        $this->expiresAt = self::timestamp($row['expires_at'] ?? null);
        $this->createdAt = self::timestamp($row['created_at'] ?? null) ?? 0;
    }

    /**
     * @return string[]
     */
    public static function types(): array
    {
        return [self::TYPE_GIFT_CARD, self::TYPE_STORE_CREDIT];
    }

    public function isGiftCard(): bool
    {
        return $this->type === self::TYPE_GIFT_CARD;
    }

    public function isStoreCredit(): bool
    {
        return $this->type === self::TYPE_STORE_CREDIT;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isExpired(?int $now = null): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= ($now ?? time());
    }

    /**
     * Bound to a customer account. Store credit always is; a gift card is once
     * its owner has added it to their account.
     */
    public function isRedeemed(): bool
    {
        return $this->customerId > 0;
    }

    /**
     * Can money be taken from it right now — leaving the currency aside, which
     * depends on the cart.
     */
    public function isUsable(?int $now = null): bool
    {
        return $this->isActive() && ! $this->isExpired($now) && Money::isPositive($this->balance);
    }

    /**
     * How the card is referred to where its code must not be shown: the masked
     * code of a gift card, the number of a store credit (which has no code
     * anyone ever sees).
     */
    public function reference(): string
    {
        return $this->isStoreCredit() ? '#'.$this->id : $this->maskedCode();
    }

    public function formattedCode(): string
    {
        return Code::format($this->code);
    }

    public function maskedCode(): string
    {
        return Code::mask($this->code);
    }

    /**
     * Stored as UTC datetimes; handled as timestamps.
     */
    protected static function timestamp(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        $time = strtotime($value.' UTC');

        return $time === false ? null : $time;
    }
}
