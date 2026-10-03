<?php

namespace GeneroWP\StoreBalance\Tests\Unit;

use GeneroWP\StoreBalance\Allocator;
use PHPUnit\Framework\TestCase;

class AllocatorTest extends TestCase
{
    /**
     * What would lapse first is spent first, so the customer never loses a
     * balance they did not have to.
     */
    public function test_the_card_that_expires_soonest_comes_first(): void
    {
        $sorted = Allocator::sort([
            ['id' => 1, 'expires' => 3000],
            ['id' => 2, 'expires' => 1000],
            ['id' => 3, 'expires' => 2000],
        ]);

        $this->assertSame([2, 3, 1], array_column($sorted, 'id'));
    }

    public function test_a_card_that_never_expires_goes_last(): void
    {
        $sorted = Allocator::sort([
            ['id' => 1, 'expires' => null],
            ['id' => 2, 'expires' => 5000],
        ]);

        $this->assertSame([2, 1], array_column($sorted, 'id'));
    }

    public function test_the_oldest_card_wins_a_tie(): void
    {
        $sorted = Allocator::sort([
            ['id' => 9, 'expires' => 1000],
            ['id' => 4, 'expires' => 1000],
            ['id' => 7, 'expires' => null],
            ['id' => 5, 'expires' => null],
        ]);

        $this->assertSame([4, 9, 5, 7], array_column($sorted, 'id'));
    }

    public function test_one_card_covers_the_amount(): void
    {
        $this->assertSame([1 => 30.0], Allocator::allocate(30, [1 => 50.0, 2 => 50.0]));
    }

    public function test_the_amount_spills_over_to_the_next_card(): void
    {
        $this->assertSame([1 => 50.0, 2 => 25.5], Allocator::allocate(75.5, [1 => 50.0, 2 => 50.0]));
    }

    public function test_it_never_takes_more_than_the_cards_hold(): void
    {
        $taken = Allocator::allocate(500, [1 => 50.0, 2 => 20.0]);

        $this->assertSame([1 => 50.0, 2 => 20.0], $taken);
        $this->assertSame(70.0, array_sum($taken));
    }

    public function test_empty_cards_are_left_out(): void
    {
        $this->assertSame([2 => 10.0], Allocator::allocate(10, [1 => 0.0, 2 => 50.0]));
    }

    public function test_nothing_is_taken_for_a_zero_amount(): void
    {
        $this->assertSame([], Allocator::allocate(0, [1 => 50.0]));
    }

    /**
     * 0.1 + 0.2 is not 0.3 in floating point. The allocation must still add
     * up to exactly what was asked for.
     */
    public function test_floating_point_dust_does_not_leak_into_the_result(): void
    {
        $taken = Allocator::allocate(0.3, [1 => 0.1, 2 => 0.2, 3 => 5.0]);

        $this->assertSame([1 => 0.1, 2 => 0.2], $taken);
    }

    public function test_currencies_without_decimals_allocate_whole_units(): void
    {
        $this->assertSame([1 => 1000.0, 2 => 500.0], Allocator::allocate(1500, [1 => 1000.4, 2 => 800.0], 0));
    }
}
