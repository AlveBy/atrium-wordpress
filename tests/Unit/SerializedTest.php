<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Unit;

use Atrium\WordPress\Support\Serialized;
use Atrium\WordPress\Tests\Fixtures\SyntheticWordPress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SerializedTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:bool}>
     */
    public static function candidates(): array
    {
        return [
            'null' => ['N;', true],
            'array' => ['a:1:{i:0;s:1:"a";}', true],
            'empty array' => ['a:0:{}', true],
            'string' => ['s:3:"abc";', true],
            'integer' => ['i:5;', true],
            'negative integer' => ['i:-5;', true],
            'float' => ['d:1.5;', true],
            'float with an exponent' => ['d:1.5E+3;', true],
            'float with a lowercase exponent' => ['d:2e-3;', true],
            'true' => ['b:1;', true],
            'false' => ['b:0;', true],
            'object' => ['O:8:"stdClass":0:{}', true],
            'empty' => ['', false],
            'plain text' => ['hello', false],
            'plain number' => ['42', false],
            'array without braces' => ['a:1:', false],
            'array without a count' => ['a:{', false],
            'string without a length' => ['s::"abc";', false],
            'integer without digits' => ['i:;', false],
            'float with letters' => ['d:abc;', false],
            'boolean 2' => ['b:2;', false],
            'object without a name' => ['O:3:x', false],
            'serialized after some text' => ['text i:5;', false],
            'serialized after a space' => [' i:5;', false],
            'a JSON array' => ['["a"]', false],
            'null without a semicolon' => ['N', false],
            'another letter' => ['x:1;', false],
        ];
    }

    #[DataProvider('candidates')]
    public function test_it_tells_serialized_values_from_text(string $value, bool $expected): void
    {
        $this->assertSame($expected, Serialized::looksSerialized($value));
    }

    public function test_a_valid_value_is_decoded(): void
    {
        $this->assertSame(['a', 'b' => ['c' => 1]], Serialized::decode(serialize(['a', 'b' => ['c' => 1]])));
        $this->assertSame(5, Serialized::decode('i:5;'));
        $this->assertSame(-5, Serialized::decode('i:-5;'));
        $this->assertSame(1.5, Serialized::decode('d:1.5;'));
        $this->assertTrue(Serialized::decode('b:1;'));
        $this->assertNull(Serialized::decode('N;'));
        $this->assertSame('abc', Serialized::decode('s:3:"abc";'));
    }

    public function test_false_is_a_value_not_a_failure(): void
    {
        $this->assertFalse(Serialized::decode('b:0;'));
        $this->assertFalse(Serialized::isBroken('b:0;'));
        $this->assertFalse(Serialized::wasRepaired('b:0;'));
        $this->assertSame([false], Serialized::toArray('b:0;'));
    }

    public function test_a_valid_value_is_not_repaired(): void
    {
        // The text inside holds a quote and a semicolon followed by a letter: a repair would cut it there.
        $this->assertSame('ab";cd', Serialized::decode('s:6:"ab";cd";'));
        $this->assertSame(['ab";cd'], Serialized::decode('a:1:{i:0;s:6:"ab";cd";}'));
        $this->assertFalse(Serialized::wasRepaired('s:6:"ab";cd";'));
    }

    public function test_a_broken_value_raises_no_notice(): void
    {
        $before = [ini_get('display_errors'), ini_get('html_errors'), error_reporting(E_ALL)];
        ini_set('display_errors', '1');
        ini_set('html_errors', '0');
        ob_start();

        try {
            Serialized::decode('a:1:{garbage');
            Serialized::decode('s:5:"abc');
            Serialized::isBroken('a:1:{garbage');
        } finally {
            $shown = (string) ob_get_clean();
            ini_set('display_errors', (string) $before[0]);
            ini_set('html_errors', (string) $before[1]);
            error_reporting($before[2]);
        }

        $this->assertSame('', $shown);
    }

    public function test_text_that_is_not_serialized_comes_back_as_it_is(): void
    {
        $this->assertNull(Serialized::decode(null));
        $this->assertSame('', Serialized::decode(''));
        $this->assertSame('hello', Serialized::decode('hello'));
        $this->assertSame('42', Serialized::decode('42'));
    }

    public function test_lengths_broken_by_a_search_and_replace_are_counted_again(): void
    {
        $this->assertSame(['vegan', 'quick'], Serialized::decode(SyntheticWordPress::BROKEN_LABELS));
        $this->assertSame(SyntheticWordPress::attachmentMetadata()['sizes'], Serialized::decode(SyntheticWordPress::brokenAttachmentMetadata())['sizes'] ?? null);
        // Multibyte text: the length is in bytes.
        $this->assertSame(['привет'], Serialized::decode('a:1:{i:0;s:3:"привет";}'));
    }

    public function test_the_repair_counts_the_bytes_of_every_string(): void
    {
        $this->assertSame('a:1:{i:0;s:5:"vegan";}', Serialized::repair('a:1:{i:0;s:6:"vegan";}'));
        $this->assertSame('a:1:{i:0;s:12:"привет";}', Serialized::repair('a:1:{i:0;s:2:"привет";}'));
        $this->assertSame('a:2:{s:1:"a";s:2:"xx";s:1:"b";s:1:"y";}', Serialized::repair('a:2:{s:1:"a";s:9:"xx";s:1:"b";s:5:"y";}'));
        // A text with a quote and a semicolon inside stays whole.
        $this->assertSame('s:8:"say "x";";', Serialized::repair('s:2:"say "x";";'));
        $this->assertSame('i:5;', Serialized::repair('i:5;'));
    }

    public function test_something_beyond_repair_is_left_alone(): void
    {
        $this->assertSame('a:1:{garbage', Serialized::decode('a:1:{garbage'));
        $this->assertSame('s:5:"abc', Serialized::decode('s:5:"abc'));
    }

    public function test_it_knows_a_value_is_broken_beyond_repair(): void
    {
        $this->assertTrue(Serialized::isBroken('a:1:{garbage'));
        $this->assertFalse(Serialized::isBroken(serialize(['a'])));
        $this->assertFalse(Serialized::isBroken(SyntheticWordPress::BROKEN_LABELS), 'a repaired value is not broken');
        $this->assertFalse(Serialized::isBroken('plain text'));
        $this->assertFalse(Serialized::isBroken(null));
        $this->assertFalse(Serialized::isBroken(''));
    }

    public function test_it_knows_a_value_needed_the_repair(): void
    {
        $this->assertTrue(Serialized::wasRepaired(SyntheticWordPress::BROKEN_LABELS));
        $this->assertTrue(Serialized::wasRepaired(SyntheticWordPress::brokenAttachmentMetadata()));
        $this->assertFalse(Serialized::wasRepaired(serialize(['vegan', 'quick'])), 'a valid value did not');
        $this->assertFalse(Serialized::wasRepaired('a:1:{garbage'), 'a lost one could not be repaired');
        $this->assertFalse(Serialized::wasRepaired('plain text'));
        $this->assertFalse(Serialized::wasRepaired(null));
        $this->assertFalse(Serialized::wasRepaired(''));
    }

    public function test_no_object_is_ever_made(): void
    {
        $value = Serialized::decode(serialize(new Sleeper));

        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $value);
        $this->assertNotInstanceOf(Sleeper::class, $value);
        $this->assertFalse(Sleeper::$woke, '__wakeup must not run on data from a database');
    }

    public function test_a_list_comes_from_any_value(): void
    {
        $this->assertSame(['a', 'b'], Serialized::toArray(serialize(['a', 'b'])));
        $this->assertSame(['x' => 1], Serialized::toArray(serialize(['x' => 1])));
        $this->assertSame(['vegan', 'quick'], Serialized::toArray(SyntheticWordPress::BROKEN_LABELS));
        $this->assertSame(['text'], Serialized::toArray('text'));
        $this->assertSame([5], Serialized::toArray('i:5;'));
        $this->assertSame(['0'], Serialized::toArray('0'), 'a zero is a value');
        $this->assertSame([], Serialized::toArray(null));
        $this->assertSame([], Serialized::toArray(''));
        $this->assertSame([], Serialized::toArray('N;'));
        $this->assertSame([], Serialized::toArray('a:0:{}'));
    }
}

final class Sleeper
{
    public static bool $woke = false;

    public function __wakeup(): void
    {
        self::$woke = true;
    }
}
