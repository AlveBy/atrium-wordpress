<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Unit;

use Atrium\WordPress\Parity\Audit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * When a value in the database is the value WordPress had: the databases
 * give booleans back as 0 and 1, JSON as text with its keys in their own
 * order and dates with a time.
 */
final class AuditTest extends TestCase
{
    private function equal(mixed $expected, mixed $actual): bool
    {
        $audit = (new \ReflectionClass(Audit::class))->newInstanceWithoutConstructor();

        return (new ReflectionMethod($audit, 'equal'))->invoke($audit, $expected, $actual);
    }

    /**
     * @return array<string,array{0:mixed,1:mixed}>
     */
    public static function equalValues(): array
    {
        return [
            'null and null' => [null, null],
            'null and an empty text' => [null, ''],
            'an empty text and null' => ['', null],
            'null and an empty list' => [null, []],
            'an empty list and an empty text' => [[], ''],
            'null and an empty JSON list' => [null, '[]'],
            'an empty list and an empty JSON list' => [[], '[]'],
            'an empty JSON object for null' => [null, '{}'],
            'true and 1' => [true, 1],
            'true and "1"' => [true, '1'],
            'true and true' => [true, true],
            'false and 0' => [false, 0],
            'false and "0"' => [false, '0'],
            'false and false' => [false, false],
            'an int and a text' => [5, '5'],
            'an int and a decimal text' => [5, '5.00'],
            'a float and a text' => [4.5, '4.5'],
            'a float and a float' => [4.5, 4.5],
            'a float within the precision' => [4.5, 4.5000001],
            'a float and a smaller one within the precision' => [4.5, 4.4999999],
            'zero and "0"' => [0, '0'],
            'zero and 0.0' => [0, 0.0],
            'a negative number' => [-3, '-3'],
            'a date and the same with a time' => ['2024-01-15', '2024-01-15 00:00:00'],
            'a date and the same as ISO time' => ['2024-01-15', '2024-01-15T10:00:00.000000Z'],
            'a date and itself' => ['2024-01-15', '2024-01-15'],
            'a date with a time and itself' => ['2024-01-15 10:30:00', '2024-01-15 10:30:00'],
            'a date with a time and the ISO one' => ['2024-01-15 10:30:00', '2024-01-15T10:30:00.000000Z'],
            'a text and itself' => ['abc', 'abc'],
            'a text and a number' => ['12', 12],
            'a text and a stringable' => ['1.5', 1.5],
            'a list and itself' => [['a', 'b'], ['a', 'b']],
            'a map with its keys in another order' => [['a' => 1, 'b' => 'x'], ['b' => 'x', 'a' => 1]],
            'a map and the JSON of it' => [['en' => 'x', 'ru' => 'y'], '{"ru":"y","en":"x"}'],
            'a list and the JSON of it' => [[1, 2], '[1,2]'],
            'a nested map and its JSON' => [['a' => ['b' => true]], '{"a":{"b":1}}'],
            'blank values inside' => [['a' => null, 'b' => []], ['a' => '', 'b' => '']],
            'numbers inside' => [['a' => 1.5], ['a' => '1.5']],
            'numbers inside a JSON' => [['a' => 1.5], '{"a":"1.5"}'],
        ];
    }

    #[DataProvider('equalValues')]
    public function test_values_that_are_the_same_as_stored(mixed $expected, mixed $actual): void
    {
        $this->assertTrue($this->equal($expected, $actual));
    }

    /**
     * @return array<string,array{0:mixed,1:mixed}>
     */
    public static function differentValues(): array
    {
        return [
            'null and a text' => [null, 'x'],
            'a text and null' => ['x', null],
            'an empty text and a text' => ['', 'x'],
            'a text and an empty list' => ['x', []],
            'a list and null' => [['a'], null],
            'null and a JSON object that is not empty' => [null, '{"a":1}'],
            'null and a JSON list that is not empty' => [null, '[1]'],
            'false and null' => [false, null],
            'zero and null' => [0, null],
            'true and 0' => [true, 0],
            'true and "0"' => [true, '0'],
            'false and 1' => [false, 1],
            'false and "yes"' => [true, 'no'],
            'a number and another' => [5, 6],
            'a float and a far one' => [4.5, 4.502],
            'a float and one beyond the precision' => [4.5, 4.4999],
            'a float and one a bit larger' => [4.5, 4.500002],
            'a number and a text' => [5, 'five'],
            'a float and a text' => [4.5, 'x'],
            'zero and a text' => [0, 'zero'],
            'a date and another day' => ['2024-01-15', '2024-01-16'],
            'a date with a time and another second' => ['2024-01-15 10:30:00', '2024-01-15 10:30:01'],
            'a date with a time and the date alone' => ['2024-01-15 10:30:00', '2024-01-15'],
            'a date and a text that has it in the middle' => ['2024-01-15', 'on 2024-01-15'],
            'a date and null' => ['2024-01-15', null],
            'a text that only starts like a date' => ['x2024-01-15', 'x2024-01-15 tail'],
            'a text with a date and a tail' => ['2024-01-15 tail', '2024-01-15 tail and more'],
            'a date with a year of two digits' => ['24-01-15', '24-01-15 10:00:00'],
            'a date with no zeros' => ['2024-1-5', '2024-1-5 10:00:00'],
            'a time without seconds' => ['2024-01-15 10:30', '2024-01-15 10:30:00'],
            'a text and another' => ['abc', 'abd'],
            'a text and its upper case' => ['abc', 'ABC'],
            'a list and another' => [['a', 'b'], ['b', 'a']],
            'a list and a longer one' => [['a'], ['a', 'b']],
            'a list and a shorter one' => [['a', 'b'], ['a']],
            'a map and one with another key' => [['a' => 1], ['b' => 1]],
            'a map and one with an extra key' => [['a' => 1], ['a' => 1, 'b' => 2]],
            'a map and one with a key less' => [['a' => 1, 'b' => 2], ['a' => 1]],
            'a map with another value' => [['a' => 1], ['a' => 2]],
            'a nested map with another value' => [['a' => ['b' => 1]], ['a' => ['b' => 2]]],
            'a list and a text' => [['a'], 'a'],
            'a list and a number' => [[1], 1],
            'a list and a text that is not JSON' => [['a'], 'abc'],
            'a list and a text that starts like JSON but is not' => [['a'], '{abc'],
            'a map and the JSON of another' => [['en' => 'x'], '{"en":"y"}'],
            'a map and a JSON with a key more' => [['en' => 'x'], '{"en":"x","ru":"y"}'],
            'a JSON text and a text' => ['{"a":1}', '{"a":1}x'],
            'a list and an object that is not' => [['a'], '[1'],
        ];
    }

    #[DataProvider('differentValues')]
    public function test_values_that_are_not_the_same_as_stored(mixed $expected, mixed $actual): void
    {
        $this->assertFalse($this->equal($expected, $actual));
    }

    public function test_a_text_that_is_not_the_expected_array_is_not_decoded_when_a_scalar_is_expected(): void
    {
        // The JSON of a text field stays text: only arrays and null are read from JSON.
        $this->assertTrue($this->equal('{"a":1}', '{"a":1}'));
        $this->assertFalse($this->equal('{"a":1}', '{"a": 1}'));
    }
}
