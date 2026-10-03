<?php

namespace GeneroWP\StoreBalance\Tests\Unit;

use GeneroWP\StoreBalance\Input;
use PHPUnit\Framework\TestCase;

class InputTest extends TestCase
{
    /**
     * `field[]=x` in a request turns any field into an array. Cast to a string
     * that is a warning, and a warning in a checkout is an error page.
     */
    public function test_an_array_where_a_string_is_expected_is_an_empty_string(): void
    {
        $this->assertSame('', Input::text(['50']));
        $this->assertSame('', Input::text(null));
        $this->assertSame('', Input::text(new \stdClass));
    }

    public function test_scalars_come_through_as_strings(): void
    {
        $this->assertSame('50', Input::text(50));
        $this->assertSame('abc', Input::text('abc'));
        $this->assertSame('1', Input::text(true));
    }

    /**
     * A right-to-left override makes "gift from: knaB ruoY" read as something
     * else entirely in the recipient's email.
     */
    public function test_invisible_direction_and_zero_width_characters_are_removed(): void
    {
        $this->assertSame('abcdef', Input::text("abc\u{202E}def"));
        $this->assertSame('abcdef', Input::text("abc\u{200B}def"));
        $this->assertSame('abcdef', Input::text("\u{FEFF}abc\u{2066}def\u{2069}"));
    }

    public function test_ordinary_text_in_any_script_is_left_alone(): void
    {
        $this->assertSame('Hyvää syntymäpäivää 🎉 مرحبا', Input::text('Hyvää syntymäpäivää 🎉 مرحبا'));
    }

    public function test_limit_cuts_on_a_character_not_in_the_middle_of_one(): void
    {
        $this->assertSame('ääk', Input::limit('ääkkönen', 3));
        $this->assertSame('short', Input::limit('short', 100));
    }
}
