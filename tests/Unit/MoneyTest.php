<?php

namespace GeneroWP\StoreBalance\Tests\Unit;

use GeneroWP\StoreBalance\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_rounding_removes_floating_point_dust(): void
    {
        $this->assertSame(0.3, Money::round(0.1 + 0.2));
        $this->assertSame(63.85, Money::round(189 - 125.15));
    }

    public function test_the_sql_literal_never_uses_a_comma_or_an_exponent(): void
    {
        $this->assertSame('1234.5000', Money::sql(1234.5));
        $this->assertSame('0.0000', Money::sql(0.00001));
        $this->assertSame('100000000.0000', Money::sql(1e8));
    }

    /**
     * @dataProvider typedAmounts
     */
    public function test_an_amount_is_read_the_way_people_type_it(string $typed, float $expected): void
    {
        $this->assertSame($expected, Money::parse($typed));
    }

    /**
     * @return array<string, array{string, float}>
     */
    public static function typedAmounts(): array
    {
        return [
            'whole' => ['50', 50.0],
            'decimal point' => ['50.50', 50.5],
            'decimal comma' => ['50,50', 50.5],
            'spaces around' => [' 50 ', 50.0],
            'thousands with a space' => ['1 000,50', 1000.5],
            'thousands with a non-breaking space' => ["1\u{00A0}000", 1000.0],
            'continental thousands' => ['1.000,50', 1000.5],
            'english thousands' => ['1,000.50', 1000.5],
        ];
    }

    /**
     * @dataProvider notAmounts
     */
    public function test_anything_that_is_not_a_positive_number_is_refused(mixed $typed): void
    {
        $this->assertNull(Money::parse($typed));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notAmounts(): array
    {
        return [
            'empty' => [''],
            'zero' => ['0'],
            'negative' => ['-5'],
            'words' => ['fifty'],
            'with a currency sign' => ['€50'],
            'scientific notation' => ['1e3'],
            'an array' => [['50']],
            'null' => [null],
            'negative number' => [-5],
        ];
    }
}
