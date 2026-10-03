<?php

namespace GeneroWP\StoreBalance\Tests\Unit;

use GeneroWP\StoreBalance\Modules\GiftCardProduct;
use PHPUnit\Framework\TestCase;

/**
 * The list of amounts a shop owner types for a gift card product.
 *
 * A comma is a decimal separator in half the world and a list separator in
 * the other half, and the same field has to survive being saved, shown again
 * and saved again without the amounts changing.
 */
class AmountListTest extends TestCase
{
    /**
     * @dataProvider lists
     *
     * @param  float[]  $expected
     */
    public function test_a_list_is_read_the_way_it_was_meant(string $typed, array $expected): void
    {
        $this->assertSame($expected, GiftCardProduct::parseAmounts($typed)['amounts']);
    }

    /**
     * @return array<string, array{string, float[]}>
     */
    public static function lists(): array
    {
        return [
            'semicolons' => ['25; 50; 100', [25.0, 50.0, 100.0]],
            'semicolons with a decimal comma' => ['12,50; 20', [12.5, 20.0]],
            'comma and space' => ['25, 50, 100', [25.0, 50.0, 100.0]],
            'commas only' => ['25,50,100', [25.0, 50.0, 100.0]],
            'one amount with a decimal comma' => ['12,50', [12.5]],
            'one amount with a decimal point' => ['12.50', [12.5]],
            'one per line' => ["25\n50\n100", [25.0, 50.0, 100.0]],
            'messy' => ['25; 50 ;100;', [25.0, 50.0, 100.0]],
            'unsorted with a duplicate' => ['100; 25; 25', [25.0, 100.0]],
            'empty' => ['', []],
        ];
    }

    public function test_what_cannot_be_read_is_reported_not_guessed(): void
    {
        $result = GiftCardProduct::parseAmounts('25; abc; -5; 50');

        $this->assertSame([25.0, 50.0], $result['amounts']);
        $this->assertSame(['abc', '-5'], $result['rejected']);
    }
}
