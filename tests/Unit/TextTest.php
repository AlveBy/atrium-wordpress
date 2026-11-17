<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Unit;

use Atrium\WordPress\Support\Text;
use Atrium\WordPress\Tests\Fixtures\SyntheticWordPress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    /**
     * @return array<string,array{0:?string,1:string}>
     */
    public static function fine(): array
    {
        return [
            'null' => [null, ''],
            'empty' => ['', ''],
            'English' => ['Hello, world', 'Hello, world'],
            'Russian' => ['Привет, мир', 'Привет, мир'],
            'accented Latin' => ['café crème', 'café crème'],
            'Chinese' => ['花园椅', '花园椅'],
            'emoji' => ['Chair 🪑', 'Chair 🪑'],
            'HTML entities are left as WordPress saved them' => ['Tom &amp; Jerry', 'Tom &amp; Jerry'],
            'spaces are kept' => ['  padded  ', '  padded  '],
            'a capital D with a stroke, no more' => ['Ð text', 'Ð text'],
            'a capital A tilde alone' => ['Ã', 'Ã'],
            'a capital A tilde before ASCII' => ['Ãbc', 'Ãbc'],
            'mojibake mixed with real Cyrillic is not undone' => ['Ã© Привет', 'Ã© Привет'],
        ];
    }

    #[DataProvider('fine')]
    public function test_valid_text_is_left_alone(?string $value, string $expected): void
    {
        $this->assertSame($expected, Text::clean($value));
    }

    public function test_windows_1251_becomes_utf8(): void
    {
        $this->assertSame(SyntheticWordPress::ENCODING_TITLE, Text::clean(SyntheticWordPress::encodingTitle()));
        $this->assertSame('Привет, мир!', Text::clean(mb_convert_encoding('Привет, мир!', 'Windows-1251', 'UTF-8')));
        $this->assertSame('<p>Съешь же ещё этих мягких булок</p>', Text::clean(mb_convert_encoding('<p>Съешь же ещё этих мягких булок</p>', 'Windows-1251', 'UTF-8')));
    }

    public function test_windows_1252_becomes_utf8(): void
    {
        $this->assertSame('café', Text::clean("caf\xE9"));
        $this->assertSame('naïve résumé', Text::clean("na\xEFve r\xE9sum\xE9"));
        // The curly quotes live in 0x80–0x9F of Windows-1252.
        $this->assertSame('“quoted”', Text::clean("\x93quoted\x94"));
    }

    public function test_text_with_few_cyrillic_looking_bytes_is_western(): void
    {
        // Three letters in a row would be Cyrillic in 1251, but the rest of the high bytes are punctuation.
        $bytes = "\x93\x94\x93\x94\x93\x94 \xE9\xE8\xE0";

        $this->assertSame(mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252'), Text::clean($bytes));
        $this->assertStringStartsWith('“”“”“”', Text::clean($bytes));
    }

    public function test_high_bytes_with_no_run_of_letters_are_western(): void
    {
        // Two letters in a row are not enough to call it Russian.
        $this->assertSame('Äà', Text::clean("\xC4\xE0"));
    }

    public function test_half_of_the_high_bytes_being_letters_is_still_western(): void
    {
        // Four letters in a row and four quotes: exactly half, which is not "most".
        $bytes = "\xE0\xE1\xE2\xE3\x93\x94\x93\x94";

        $this->assertSame('àáâã“”“”', Text::clean($bytes));
    }

    public function test_utf8_read_as_latin1_and_saved_again_is_undone(): void
    {
        $this->assertSame(SyntheticWordPress::ENCODING_CONTENT, Text::clean(SyntheticWordPress::encodingContent()));
        $this->assertSame('Привет', Text::clean(mb_convert_encoding('Привет', 'UTF-8', 'ISO-8859-1')));
        $this->assertSame('café', Text::clean('cafÃ©'));
    }

    public function test_only_the_accents_and_cyrillic_read_wrongly_are_undone(): void
    {
        // A curly apostrophe read as Windows-1252 starts with "â", which a real text may have: it stays.
        $this->assertSame('â€™', Text::clean('â€™'));
    }

    public function test_utf8_read_as_windows_1252_is_undone(): void
    {
        // The second byte of "Р" is 0x80, a euro sign in Windows-1252 and nothing in Latin-1.
        $this->assertSame('Привет', Text::clean(mb_convert_encoding('Привет', 'UTF-8', 'Windows-1252')));
        $this->assertSame('«ёлка»', Text::clean(mb_convert_encoding('«ёлка»', 'UTF-8', 'Windows-1252')));
    }
}
