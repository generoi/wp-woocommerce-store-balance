<?php

namespace GeneroWP\StoreBalance\Tests\Unit;

use GeneroWP\StoreBalance\Code;
use PHPUnit\Framework\TestCase;

class CodeTest extends TestCase
{
    public function test_a_generated_code_is_valid(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue(Code::isValid(Code::generate()));
        }
    }

    /**
     * The code is read off an email and typed by hand.
     */
    public function test_a_generated_code_has_no_ambiguous_characters(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertDoesNotMatchRegularExpression('/[IO01]/', Code::generate());
        }
    }

    public function test_codes_are_not_repeated(): void
    {
        $codes = [];

        for ($i = 0; $i < 500; $i++) {
            $codes[] = Code::generate();
        }

        $this->assertCount(500, array_unique($codes));
    }

    /**
     * @dataProvider typedCodes
     */
    public function test_a_code_is_read_however_it_was_typed(string $typed): void
    {
        $this->assertSame('ABCDEFGH23456789', Code::normalize($typed));
        $this->assertTrue(Code::isValid($typed));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function typedCodes(): array
    {
        return [
            'as printed' => ['ABCD-EFGH-2345-6789'],
            'lowercase' => ['abcd-efgh-2345-6789'],
            'without dashes' => ['ABCDEFGH23456789'],
            'with spaces' => ['ABCD EFGH 2345 6789'],
            'pasted with whitespace around it' => ["  ABCD-EFGH-2345-6789\n"],
        ];
    }

    /**
     * @dataProvider invalidCodes
     */
    public function test_anything_else_is_not_a_code(string $typed): void
    {
        $this->assertFalse(Code::isValid($typed));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCodes(): array
    {
        return [
            'empty' => [''],
            'too short' => ['ABCD-EFGH-2345'],
            'too long' => ['ABCD-EFGH-2345-6789-AAAA'],
            'a character outside the alphabet' => ['ABCD-EFGH-2345-678O'],
            'sql' => ["' OR 1=1 --"],
        ];
    }

    public function test_it_formats_in_groups_of_four(): void
    {
        $this->assertSame('ABCD-EFGH-2345-6789', Code::format('abcdefgh23456789'));
    }

    public function test_the_mask_shows_only_the_last_four(): void
    {
        $mask = Code::mask('ABCD-EFGH-2345-6789');

        $this->assertStringEndsWith('6789', $mask);
        $this->assertStringNotContainsString('ABCD', $mask);
        $this->assertStringNotContainsString('2345', $mask);
    }
}
