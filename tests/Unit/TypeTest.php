<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Unit;

use Atrium\WordPress\Plan\Field;
use Atrium\WordPress\Plan\Taxonomy;
use Atrium\WordPress\Plan\Type;
use PHPUnit\Framework\TestCase;

final class TypeTest extends TestCase
{
    private function type(string $target, array $fields = []): Type
    {
        return new Type('recipe', $target, 'recipes', 'App\\Models\\Recipe', 'recipes', 'Recipes', false, 3, $fields);
    }

    public function test_a_type_moves_unless_it_is_skipped(): void
    {
        $this->assertTrue($this->type(Type::NEWS)->moves());
        $this->assertTrue($this->type(Type::PAGES)->moves());
        $this->assertTrue($this->type(Type::GENERATE)->moves());
        $this->assertFalse($this->type(Type::SKIP)->moves());
    }

    public function test_only_a_generated_type_has_code_to_generate(): void
    {
        $this->assertTrue($this->type(Type::GENERATE)->generated());
        $this->assertFalse($this->type(Type::NEWS)->generated());
        $this->assertFalse($this->type(Type::PAGES)->generated());
        $this->assertFalse($this->type(Type::SKIP)->generated());
    }

    public function test_a_field_is_found_by_its_name(): void
    {
        $time = new Field('cooking_time', 'cooking_time', 'Cooking time', 'number');
        $type = $this->type(Type::GENERATE, [$time, new Field('spicy', 'spicy', 'Spicy', 'true_false')]);

        $this->assertSame($time, $type->field('cooking_time'));
        $this->assertSame('spicy', $type->field('spicy')?->name);
        $this->assertNull($type->field('nothing'));
        $this->assertNull($this->type(Type::GENERATE)->field('spicy'));
    }

    public function test_a_type_keeps_what_it_was_made_with(): void
    {
        $type = new Type('recipe', Type::GENERATE, 'recipes', 'App\\Models\\Recipe', 'cook', 'Recipes', true, 3, [], ['cuisine'], 'because');

        $this->assertSame('recipe', $type->type);
        $this->assertSame('recipes', $type->table);
        $this->assertSame('App\\Models\\Recipe', $type->model);
        $this->assertSame('cook', $type->prefix);
        $this->assertSame('Recipes', $type->label);
        $this->assertTrue($type->hierarchical);
        $this->assertSame(3, $type->count);
        $this->assertSame(['cuisine'], $type->taxonomies);
        $this->assertSame('because', $type->reason);
        $this->assertNull($this->type(Type::NEWS)->reason);
    }

    public function test_a_taxonomys_link_table_is_named_after_both_sides(): void
    {
        $taxonomy = new Taxonomy('cuisine', 'cuisines', 'App\\Models\\Cuisine', 'cuisine', 'Cuisines', false, 2, ['recipe']);

        $this->assertSame('recipes_cuisines', $taxonomy->pivot($this->type(Type::GENERATE)));
        $this->assertSame(['recipe'], $taxonomy->types);
        $this->assertSame(2, $taxonomy->count);
        $this->assertFalse($taxonomy->hierarchical);
    }
}
