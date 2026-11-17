<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Unit;

use Atrium\WordPress\Plan\Plan;
use Atrium\WordPress\Plan\Taxonomy;
use Atrium\WordPress\Plan\Type;
use Atrium\WordPress\Site\Languages;
use Atrium\WordPress\Tests\Fixtures\ArraySource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlanTest extends TestCase
{
    private function type(string $name, string $target): Type
    {
        return new Type($name, $target, $name.'s', 'App\\Models\\'.ucfirst($name), $name, ucfirst($name).'s', false, 1);
    }

    /**
     * @param  array<string,string>  $options
     */
    private function plan(array $options = []): Plan
    {
        $source = new ArraySource($options);

        return new Plan(
            $source,
            new Languages($source),
            ['post' => $this->type('post', Type::NEWS), 'recipe' => $this->type('recipe', Type::GENERATE), 'book' => $this->type('book', Type::SKIP), 'dish' => $this->type('dish', Type::GENERATE)],
            ['cuisine' => new Taxonomy('cuisine', 'cuisines', 'App\\Models\\Cuisine', 'cuisine', 'Cuisines', false, 2, ['recipe'])],
            'authors',
            'App\\Models\\Author',
        );
    }

    public function test_the_types_that_move_are_all_but_the_skipped(): void
    {
        $this->assertSame(['post', 'recipe', 'dish'], array_keys($this->plan()->moving()));
    }

    public function test_the_types_to_generate_are_those_without_a_module(): void
    {
        $this->assertSame(['recipe', 'dish'], array_keys($this->plan()->generated()));
    }

    public function test_a_type_and_a_taxonomy_are_found_by_name(): void
    {
        $plan = $this->plan();

        $this->assertSame('recipes', $plan->type('recipe')?->table);
        $this->assertNull($plan->type('nothing'));
        $this->assertSame('cuisines', $plan->taxonomy('cuisine')?->table);
        $this->assertNull($plan->taxonomy('nothing'));
    }

    public function test_the_author_link_table_is_named_after_the_module_and_the_authors(): void
    {
        $plan = $this->plan();

        $this->assertSame('recipes_authors', $plan->authorPivot($plan->types['recipe']));
        $this->assertSame('news_authors', $plan->authorPivot(new Type('post', Type::NEWS, 'news', 'N', 'news', 'Posts', false, 1)));
    }

    /**
     * @return array<string,array{0:array<string,string>,1:string}>
     */
    public static function homes(): array
    {
        return [
            'home' => [['home' => 'https://a.test', 'siteurl' => 'https://b.test'], 'https://a.test'],
            'siteurl when home is empty' => [['home' => '', 'siteurl' => 'https://b.test'], 'https://b.test'],
            'siteurl alone' => [['siteurl' => 'https://b.test'], 'https://b.test'],
            'slashes at the end go' => [['home' => 'https://a.test/blog//'], 'https://a.test/blog'],
            'nothing' => [[], ''],
        ];
    }

    /**
     * @param  array<string,string>  $options
     */
    #[DataProvider('homes')]
    public function test_the_old_address_has_no_slash_at_the_end(array $options, string $home): void
    {
        $this->assertSame($home, $this->plan($options)->home());
    }

    /**
     * @return array<string,array{0:?string,1:array<int,string>}>
     */
    public static function plugins(): array
    {
        return [
            'none' => [null, []],
            'nothing active' => [serialize([]), []],
            'Yoast' => [serialize(['wordpress-seo/wp-seo.php']), ['yoast']],
            'Yoast Premium' => [serialize(['wordpress-seo-premium/wp-seo-premium.php']), ['yoast']],
            'Rank Math' => [serialize(['seo-by-rank-math/rank-math.php']), ['rankmath']],
            'both, Rank Math first' => [serialize(['seo-by-rank-math/rank-math.php', 'wordpress-seo/wp-seo.php']), ['yoast', 'rankmath']],
            'others' => [serialize(['akismet/akismet.php', 'polylang/polylang.php']), []],
            'not a list' => ['garbage', []],
        ];
    }

    /**
     * @param  array<int,string>  $expected
     */
    #[DataProvider('plugins')]
    public function test_the_seo_plugins_are_read_from_the_active_ones(?string $active, array $expected): void
    {
        $this->assertSame($expected, $this->plan($active === null ? [] : ['active_plugins' => $active])->seoPlugins());
    }
}
