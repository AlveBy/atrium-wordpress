<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Unit;

use Atrium\WordPress\Plan\Field;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FieldTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:string,2:bool,3:bool}> kind => storage, translatable, json
     */
    public static function kinds(): array
    {
        return [
            'text' => ['text', 'json', true, false],
            'textarea' => ['textarea', 'json', true, false],
            'wysiwyg' => ['wysiwyg', 'json', true, false],
            'plain meta' => ['meta', 'json', true, false],
            'checkbox' => ['checkbox', 'json', false, true],
            'gallery' => ['gallery', 'json', false, true],
            'post object' => ['post_object', 'json', false, true],
            'page link' => ['page_link', 'json', false, true],
            'relationship' => ['relationship', 'json', false, true],
            'taxonomy' => ['taxonomy', 'json', false, true],
            'user' => ['user', 'json', false, true],
            'repeater' => ['repeater', 'json', false, true],
            'group' => ['group', 'json', false, true],
            'flexible content' => ['flexible_content', 'json', false, true],
            'link' => ['link', 'json', false, true],
            'google map' => ['google_map', 'json', false, true],
            'number' => ['number', 'decimal', false, false],
            'range' => ['range', 'decimal', false, false],
            'true or false' => ['true_false', 'boolean', false, false],
            'date' => ['date_picker', 'date', false, false],
            'date and time' => ['date_time_picker', 'datetime', false, false],
            'select' => ['select', 'string', false, false],
            'radio' => ['radio', 'string', false, false],
            'button group' => ['button_group', 'string', false, false],
            'image' => ['image', 'string', false, false],
            'file' => ['file', 'string', false, false],
            'email' => ['email', 'string', false, false],
            'url' => ['url', 'string', false, false],
            'color' => ['color_picker', 'string', false, false],
            'time' => ['time_picker', 'string', false, false],
            'oembed' => ['oembed', 'text', false, false],
            'something unknown' => ['made_up', 'text', false, false],
        ];
    }

    #[DataProvider('kinds')]
    public function test_a_kind_is_stored_the_way_its_value_looks(string $kind, string $storage, bool $translatable, bool $json): void
    {
        $field = new Field('name', 'name', 'Name', $kind);

        $this->assertSame($storage, $field->storage());
        $this->assertSame($translatable, $field->translatable());
        $this->assertSame($json, $field->isJson());
    }

    public function test_a_select_that_takes_several_values_is_a_list(): void
    {
        $single = new Field('a', 'a', 'A', 'select', ['x' => 'X']);
        $several = new Field('a', 'a', 'A', 'select', ['x' => 'X'], true);

        $this->assertFalse($single->isJson());
        $this->assertSame('string', $single->storage());
        $this->assertTrue($several->isJson());
        $this->assertSame('json', $several->storage());
    }

    public function test_several_values_make_a_list_of_a_select_only(): void
    {
        $radio = new Field('a', 'a', 'A', 'radio', [], true);
        $number = new Field('a', 'a', 'A', 'number', [], true);

        $this->assertFalse($radio->isJson());
        $this->assertSame('string', $radio->storage());
        $this->assertSame('decimal', $number->storage());
    }

    public function test_a_field_keeps_what_it_was_made_with(): void
    {
        $field = new Field('steps', 'steps_2', 'Steps', 'repeater', [], false, ['text', 'photo']);

        $this->assertSame('steps', $field->name);
        $this->assertSame('steps_2', $field->column);
        $this->assertSame('Steps', $field->label);
        $this->assertSame(['text', 'photo'], $field->subFields);
        $this->assertSame([], $field->choices);
        $this->assertFalse($field->multiple);
    }
}
