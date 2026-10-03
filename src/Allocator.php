<?php

namespace GeneroWP\StoreBalance;

/**
 * Decides which cards pay for how much. No WordPress in here, so the rules can
 * be tested on their own.
 */
class Allocator
{
    /**
     * Soonest expiry first, then oldest. A card that never expires goes last.
     *
     * The customer loses nothing they did not have to: what would lapse first is
     * spent first. It also makes a separate rule between gift cards and store
     * credit unnecessary.
     *
     * @param  array<int, array{id: int, expires: int|null}>  $cards
     * @return array<int, array{id: int, expires: int|null}>
     */
    public static function sort(array $cards): array
    {
        usort($cards, static function (array $a, array $b): int {
            $aExpires = $a['expires'] ?? PHP_INT_MAX;
            $bExpires = $b['expires'] ?? PHP_INT_MAX;

            return [$aExpires, $a['id']] <=> [$bExpires, $b['id']];
        });

        return $cards;
    }

    /**
     * Spread an amount over cards, in the order given.
     *
     * @param  array<int, float>  $available  card id => what it can cover
     * @return array<int, float> card id => what is taken from it; cards that
     *                           contribute nothing are left out
     */
    public static function allocate(float $amount, array $available, int $decimals = 2): array
    {
        $remaining = round($amount, $decimals);
        $taken = [];

        foreach ($available as $id => $balance) {
            if ($remaining <= 0) {
                break;
            }

            $take = round(min($remaining, max(0, round($balance, $decimals))), $decimals);

            if ($take <= 0) {
                continue;
            }

            $taken[$id] = $take;
            $remaining = round($remaining - $take, $decimals);
        }

        return $taken;
    }
}
