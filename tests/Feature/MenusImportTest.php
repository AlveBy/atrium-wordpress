<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Feature;

use Atrium\Menus\Models\Menu;
use Atrium\Menus\Models\MenuItem;
use Atrium\News\Models\News;
use Atrium\Pages\Models\Page;
use Atrium\WordPress\Import\Importer;
use Atrium\WordPress\Import\Navigation;
use Atrium\WordPress\Import\Records;
use Atrium\WordPress\Parity\Audit;
use Atrium\WordPress\Plan\Plan;
use Atrium\WordPress\Source\Source;
use Atrium\WordPress\Tests\Concerns\ImportsWordPress;
use Atrium\WordPress\Tests\Fixtures\SyntheticWordPress as Wp;
use Atrium\WordPress\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The menus of the old site in the menus module: each one as a Menu with its
 * items as a tree, the links to the records that moved, the codes, the
 * languages, a second run, the prune, the audit and the analysis.
 */
final class MenusImportTest extends TestCase
{
    use ImportsWordPress;

    private const RECIPE = TestCase::NAMESPACE.'\\Recipe';

    private const CATEGORY = TestCase::NAMESPACE.'\\Category';

    private function prepare(string $plugin = 'wpml'): void
    {
        // A scope that hides every menu from a query that does not ask for all of them (as the one
        // of the site does for a request kept to another site): the importer, the audit and the
        // tests below look at the menus of every site.
        Menu::addGlobalScope('hidden', static fn (Builder $query) => $query->whereRaw('1 = 0'));
        $this->site($plugin);
        $this->generateAndMigrate();
    }

    private function importer(): Importer
    {
        return new Importer($this->records());
    }

    /**
     * @return array{counts:array<string,int>,missing_files:array<int,string>,notes:array<int,string>}
     */
    private function move(bool $onlyChanged = false): array
    {
        return $this->importer()->run($onlyChanged);
    }

    /**
     * @return array{summary:array<string,array{wordpress:int,atrium:int,problems:int}>,problems:array<int,array<string,mixed>>,warnings:array<int,string>,notes:array<int,string>}
     */
    private function audit(): array
    {
        return (new Audit($this->records()))->run();
    }

    private function menu(int $wp): Menu
    {
        $map = $this->mapRow('menu', $wp);
        $this->assertNotNull($map, "menu {$wp} has no row in wordpress_map");

        return Menu::query()->withoutGlobalScopes()->findOrFail($map->target_id);
    }

    /**
     * The items of a menu in the order they were written (parents first), a
     * parent named by its title.
     *
     * @return array<int,array<string,mixed>>
     */
    private function items(int $wp): array
    {
        $rows = DB::table('menu_items')->where('menu_id', $this->menu($wp)->id)->orderBy('id')->get();
        $titles = $rows->pluck('title', 'id')->all();

        return $rows->map(static fn (object $row): array => [
            'title' => $row->title,
            'parent' => $row->parent_id === null ? null : $titles[$row->parent_id],
            'sort' => (int) $row->sort,
            'url' => $row->url,
            'type' => $row->linkable_type,
            'id' => $row->linkable_id === null ? null : (int) $row->linkable_id,
            'target' => $row->target,
            'active' => (bool) $row->active,
        ])->all();
    }

    /**
     * @return array<int,string>
     */
    private function titles(int $wp): array
    {
        return array_column($this->items($wp), 'title');
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<int,string>
     */
    private function notesWith(array $result, string $text): array
    {
        return array_values(array_filter($result['notes'], static fn (string $note): bool => str_contains($note, $text)));
    }

    /**
     * @return array<string,string|int>
     */
    private function postLink(int $id, string $object = 'post'): array
    {
        return ['type' => 'post_type', 'object' => $object, 'object_id' => $id];
    }

    /**
     * @return array<string,string|int>
     */
    private function termLink(int $term, string $taxonomy = 'category'): array
    {
        return ['type' => 'taxonomy', 'object' => $taxonomy, 'object_id' => $term];
    }

    /**
     * @return array<string,string>
     */
    private function custom(string $url): array
    {
        return ['type' => 'custom', 'object' => 'custom', 'url' => $url];
    }

    /**
     * @return array<string,string|int>
     */
    private function child(int $parent, array $meta): array
    {
        return ['menu_item_parent' => $parent, ...$meta];
    }

    /**
     * A menu 200 with a tree of items and every kind of link.
     */
    private function nested(): void
    {
        $this->wpMenu(200, 'Primary');
        $this->wpMenuItem(301, 200, 'Blog', $this->postLink(Wp::HELLO_RU), 3);
        $this->wpMenuItem(302, 200, 'About', $this->postLink(Wp::ABOUT, 'page'), 1);
        $this->wpMenuItem(303, 200, 'Shop', [...$this->custom('https://shop.example.net/?a=1&b=2'), 'target' => '_blank'], 2);
        $this->wpMenuItem(304, 200, 'Team', $this->child(302, $this->postLink(Wp::TEAM, 'page')), 2);
        $this->wpMenuItem(305, 200, 'History', $this->child(302, $this->postLink(Wp::HISTORY, 'page')), 1, ['post_status' => 'draft']);
        $this->wpMenuItem(306, 200, 'Cooks', $this->child(304, $this->custom('#')), 0, ['post_status' => 'private']);
        $this->wpMenuItem(307, 200, 'Latest', $this->child(301, $this->custom('/latest')), 0);
        $this->wpMenuItem(308, 200, 'Old', $this->custom('/old'), 0, ['post_status' => 'trash']);
        $this->wpMenuItem(309, 200, 'Same A', $this->custom('/a'), 4);
        $this->wpMenuItem(310, 200, 'Same B', $this->custom('/b'), 4);
    }

    /**
     * A menu of six levels: the items below the fourth sit on it. The items
     * are 1 to 6 of the menu ($base + n).
     */
    private function chain(int $menu, string $name, int $base, int $levels = 6): void
    {
        $this->wpMenu($menu, $name);

        for ($level = 1; $level <= $levels; $level++) {
            $this->wpMenuItem($base + $level, $menu, "L{$level}", $level === 1 ? $this->custom('/l1') : $this->child($base + $level - 1, $this->custom("/l{$level}")));
        }
    }

    private function shopSite(): int
    {
        DB::table('admin_sites')->insert(['code' => 'shop', 'name' => 'Shop', 'active' => true, 'is_default' => false, 'sort' => 200, 'created_at' => now(), 'updated_at' => now()]);

        return (int) DB::table('admin_sites')->where('code', 'shop')->value('id');
    }

    private function mainSite(): int
    {
        return (int) DB::table('admin_sites')->where('is_default', true)->value('id');
    }

    private function menuRow(string $code, ?string $language, ?int $site = null, string $name = 'Made by hand'): int
    {
        return DB::table('menus')->insertGetId(['site_id' => $site ?? $this->mainSite(), 'language' => $language, 'code' => $code, 'name' => $name, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function translateMenu(int $menu, string $language, int $trid): void
    {
        $this->wp()->table('wp_icl_translations')->insert([
            'element_type' => 'tax_nav_menu', 'element_id' => $menu, 'trid' => $trid,
            'language_code' => $language, 'source_language_code' => $language === 'en' ? null : 'en',
        ]);
    }

    private function onlySqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('A deadlock is retried only outside the transaction of the test, whose rows MySQL and PostgreSQL would keep.');
        }
    }

    // The menu and its items.

    public function test_a_menu_is_written_with_its_items_as_a_tree(): void
    {
        $this->prepare();
        $this->nested();

        $result = $this->move();

        $about = $this->target('post', Wp::ABOUT)->id;
        $team = $this->target('post', Wp::TEAM)->id;
        $history = $this->target('post', Wp::HISTORY)->id;
        $hello = $this->target('post', Wp::HELLO)->id;
        $menu = DB::table('menus')->find($this->menu(200)->id);
        $this->assertSame($this->mainSite(), (int) $menu->site_id);
        $this->assertSame('en', $menu->language);
        $this->assertSame('primary', $menu->code);
        $this->assertSame('Primary', $menu->name);
        $this->assertTrue((bool) $menu->active);
        $this->assertEquals([
            ['title' => 'About', 'parent' => null, 'sort' => 10, 'url' => null, 'type' => Page::class, 'id' => $about, 'target' => null, 'active' => true],
            ['title' => 'History', 'parent' => 'About', 'sort' => 10, 'url' => null, 'type' => Page::class, 'id' => $history, 'target' => null, 'active' => false],
            ['title' => 'Team', 'parent' => 'About', 'sort' => 20, 'url' => null, 'type' => Page::class, 'id' => $team, 'target' => null, 'active' => true],
            ['title' => 'Cooks', 'parent' => 'Team', 'sort' => 10, 'url' => null, 'type' => null, 'id' => null, 'target' => null, 'active' => false],
            ['title' => 'Shop', 'parent' => null, 'sort' => 20, 'url' => 'https://shop.example.net/?a=1&b=2', 'type' => null, 'id' => null, 'target' => '_blank', 'active' => true],
            ['title' => 'Blog', 'parent' => null, 'sort' => 30, 'url' => null, 'type' => News::class, 'id' => $hello, 'target' => null, 'active' => true],
            ['title' => 'Latest', 'parent' => 'Blog', 'sort' => 10, 'url' => '/latest', 'type' => null, 'id' => null, 'target' => null, 'active' => true],
            ['title' => 'Same A', 'parent' => null, 'sort' => 40, 'url' => '/a', 'type' => null, 'id' => null, 'target' => null, 'active' => true],
            ['title' => 'Same B', 'parent' => null, 'sort' => 50, 'url' => '/b', 'type' => null, 'id' => null, 'target' => null, 'active' => true],
        ], $this->items(200));
        $this->assertSame(9, DB::table('menu_items')->count());
        $this->assertSame(['menus' => 2, 'menu items' => 9], array_intersect_key($result['counts'], ['menus' => 0, 'menu items' => 0]));
        $this->assertSame([], $this->notesWith($result, 'menu item'));
    }

    public function test_the_menu_is_in_the_map_as_a_nav_menu(): void
    {
        $this->prepare();
        $this->nested();

        $this->move();

        $row = $this->mapRow('menu', 200);
        $this->assertSame('nav_menu', $row->wp_type);
        $this->assertSame(Menu::class, $row->target_type);
        $this->assertSame($this->menu(200)->id, (int) $row->target_id);
        $this->assertNull($row->paths);
        $this->assertSame(2, DB::table('wordpress_map')->where('kind', 'menu')->count());
        $this->assertTrue($this->moved('menu', 109));
    }

    public function test_an_empty_menu_is_a_menu_without_items(): void
    {
        $this->prepare();

        $result = $this->move();

        $menu = DB::table('menus')->find($this->menu(Wp::TERM_MAIN_MENU + Wp::TT_OFFSET)->id);
        $this->assertSame('main-menu', $menu->code);
        $this->assertSame('Main menu', $menu->name);
        $this->assertSame(0, DB::table('menu_items')->count());
        $this->assertSame(1, $result['counts']['menus']);
        $this->assertSame(0, $result['counts']['menu items']);
    }

    public function test_a_site_without_menus_writes_nothing(): void
    {
        $this->prepare();
        $this->wp()->table('wp_term_taxonomy')->where('taxonomy', 'nav_menu')->delete();

        $result = $this->move();

        $this->assertArrayNotHasKey('menus', $result['counts']);
        $this->assertArrayNotHasKey('menu items', $result['counts']);
        $this->assertSame(8, $result['counts']['posts:post']);
        $this->assertSame(0, DB::table('menus')->count());
        $this->assertSame(0, DB::table('wordpress_map')->where('kind', 'menu')->count());
        $this->assertSame([], $this->notesWith($result, 'menu'));
    }

    public function test_the_menus_cache_is_flushed_after_the_menus_are_written(): void
    {
        $this->prepare();
        $this->nested();
        // Saving an item empties the cache too, so the last save of the run puts the sentinel back and only the flush after it can take it away.
        Event::listen('eloquent.saved: '.Menu::class, static fn () => Cache::forever('atrium-menus:version', 'while the menus are written'));
        Event::listen('eloquent.saved: '.MenuItem::class, static fn () => Cache::forever('atrium-menus:version', 'while the menus are written'));

        $this->move();

        $this->assertNotSame('while the menus are written', Cache::get('atrium-menus:version'));
        $this->assertNotNull(Cache::get('atrium-menus:version'));
    }

    public function test_the_counts_of_menus_and_items_add_up_over_menus(): void
    {
        $this->prepare();
        $this->nested();
        $this->wpMenu(201, 'Footer');
        $this->wpMenuItem(330, 201, 'Terms', $this->custom('/terms'));
        $this->wpMenuItem(331, 201, 'Privacy', $this->custom('/privacy'));

        $result = $this->move();

        $this->assertSame(3, $result['counts']['menus']);
        $this->assertSame(11, $result['counts']['menu items']);
        $this->assertSame(['Terms', 'Privacy'], $this->titles(201));
        $this->assertCount(9, $this->items(200), 'the items of the first menu are not lost to the second');
    }

    public function test_the_counts_are_in_the_table_of_the_migrate_command(): void
    {
        $this->prepare();
        $this->nested();

        [$exit, $output] = $this->cli('atrium:wordpress:migrate:data');

        $this->assertSame(0, $exit, $output);
        $this->assertMatchesRegularExpression('/\| menus\s+\| 2\s+\|/', $output);
        $this->assertMatchesRegularExpression('/\| menu items\s+\| 9\s+\|/', $output);
    }

    public function test_names_and_titles_are_cut_at_255_characters(): void
    {
        $this->prepare();
        $this->wpMenu(200, str_repeat('я', 300), 'long');
        $this->wpMenuItem(301, 200, str_repeat('ы', 300), $this->custom('/long'));

        $this->move();

        $this->assertSame(str_repeat('я', 255), $this->menu(200)->name);
        $this->assertSame([str_repeat('ы', 255)], $this->titles(200));
    }

    public function test_a_name_and_a_title_of_255_characters_stay_whole(): void
    {
        $this->prepare();
        $this->wpMenu(200, str_repeat('я', 255), 'long');
        $this->wpMenuItem(301, 200, str_repeat('ы', 255), $this->custom('/long'));

        $this->move();

        $this->assertSame(str_repeat('я', 255), $this->menu(200)->name);
        $this->assertSame([str_repeat('ы', 255)], $this->titles(200));
    }

    public function test_a_custom_address_is_kept_and_one_into_the_uploads_is_made_relative(): void
    {
        $this->prepare();
        $this->option('home', 'http://localhost:18081');
        $this->wpMenu(200, 'Files');
        $this->wpMenuItem(301, 200, 'Guide', $this->custom('http://localhost:18081/wp-content/uploads/2024/guide.pdf'), 1);
        $this->wpMenuItem(302, 200, 'Other port', $this->custom('http://localhost:18082/wp-content/uploads/2024/guide.pdf'), 2);
        $this->wpMenuItem(303, 200, 'Elsewhere', $this->custom('https://cdn.example.net/a.pdf'), 3);

        $this->move();

        $this->assertSame([
            '/wp-content/uploads/2024/guide.pdf',
            'http://localhost:18082/wp-content/uploads/2024/guide.pdf',
            'https://cdn.example.net/a.pdf',
        ], array_column($this->items(200), 'url'));
    }

    // Links.

    public function test_links_to_pages_and_posts_go_to_the_record_of_the_module(): void
    {
        $this->prepare();
        $this->wpMenu(200, 'Links');
        $this->wpMenuItem(301, 200, 'A page', $this->postLink(Wp::ABOUT_RU, 'page'), 1);
        $this->wpMenuItem(302, 200, 'A post', $this->postLink(Wp::GUTENBERG), 2);

        $result = $this->move();

        $this->assertEquals([
            ['type' => Page::class, 'id' => $this->target('post', Wp::ABOUT)->id, 'url' => null],
            ['type' => News::class, 'id' => $this->target('post', Wp::GUTENBERG)->id, 'url' => null],
        ], array_map(static fn (array $item): array => array_intersect_key($item, ['type' => 0, 'id' => 0, 'url' => 0]), $this->items(200)));
        $this->assertSame([], $this->notesWith($result, 'record that was not moved'));
    }

    public function test_a_term_goes_to_the_record_when_its_module_has_pages(): void
    {
        config()->set('atrium.public_urls.'.self::CATEGORY, ['prefix' => 'category']);
        $this->prepare();
        $this->wpMenu(200, 'Terms');
        $this->wpMenuItem(301, 200, 'News', $this->termLink(Wp::TERM_NEWS), 1);
        $this->wpMenuItem(302, 200, 'Novosti', $this->termLink(Wp::TERM_NOVOSTI), 2);

        $this->move();

        $news = $this->target('term', Wp::TERM_NEWS + Wp::TT_OFFSET)->id;
        $this->assertEquals([
            ['type' => self::CATEGORY, 'id' => $news, 'url' => null],
            ['type' => self::CATEGORY, 'id' => $news, 'url' => null],
        ], array_map(static fn (array $item): array => array_intersect_key($item, ['type' => 0, 'id' => 0, 'url' => 0]), $this->items(200)));
    }

    public function test_a_record_of_a_module_without_pages_is_linked_by_its_new_address(): void
    {
        $this->prepare();
        $this->wpMenu(200, 'Without pages');
        $this->wpMenuItem(301, 200, 'News', $this->termLink(Wp::TERM_NEWS), 1);
        $this->wpMenuItem(302, 200, 'Borscht', $this->postLink(Wp::BORSCHT_RU, 'recipe'), 2);
        $this->wpMenuItem(303, 200, 'Pizza', $this->postLink(Wp::PIZZA, 'recipe'), 3);

        $result = $this->move();

        $this->assertEquals([
            ['url' => '/category/news', 'type' => null, 'id' => null],
            ['url' => '/recipe/borscht', 'type' => null, 'id' => null],
            ['url' => '/recipe/pizza', 'type' => null, 'id' => null],
        ], array_map(static fn (array $item): array => array_intersect_key($item, ['type' => 0, 'id' => 0, 'url' => 0]), $this->items(200)));
        $this->assertSame([], $this->notesWith($result, 'record that was not moved'));
    }

    public function test_a_record_in_the_language_of_the_menu_is_linked_by_the_address_of_that_language(): void
    {
        $this->prepare();
        $this->wpMenu(200, 'Primary');
        $this->wpMenu(201, 'Главное меню', 'glavnoe-menyu');
        $this->translateMenu(200, 'en', 5001);
        $this->translateMenu(201, 'ru', 5001);
        $this->wpMenuItem(301, 200, 'Borscht', $this->postLink(Wp::BORSCHT, 'recipe'), 1);
        $this->wpMenuItem(302, 200, 'News', $this->termLink(Wp::TERM_NEWS), 2);
        $this->wpMenuItem(303, 201, 'Борщ', $this->postLink(Wp::BORSCHT_RU, 'recipe'), 1);
        $this->wpMenuItem(304, 201, 'Новости', $this->termLink(Wp::TERM_NOVOSTI), 2);
        $this->wpMenuItem(305, 201, 'Пицца', $this->postLink(Wp::PIZZA, 'recipe'), 3);
        $this->wpMenuItem(306, 200, 'Pizza', $this->postLink(Wp::PIZZA, 'recipe'), 3);

        $result = $this->move();

        $this->assertSame(['/recipe/borscht', '/category/news', '/recipe/pizza'], array_column($this->items(200), 'url'));
        $this->assertSame(['/ru/recipe/borshch', '/ru/category/news', null], array_column($this->items(201), 'url'));
        $this->assertSame(['menu item 305 links to a record that was not moved; it became a heading'], $this->notesWith($result, 'record that was not moved'));
    }

    public function test_an_address_of_a_language_with_a_region_is_found(): void
    {
        $this->prepare();
        $this->wpPost(43, 'recipe', 'Pizza pt', ['post_name' => 'pizza-pt']);
        $this->wp()->table('wp_icl_translations')->insert([
            'element_type' => 'post_recipe', 'element_id' => 43, 'trid' => $this->wp()->table('wp_icl_translations')->where('element_id', Wp::PIZZA)->where('element_type', 'post_recipe')->value('trid'),
            'language_code' => 'pt-br', 'source_language_code' => 'en',
        ]);
        $this->wpMenu(200, 'Menu pt');
        $this->translateMenu(200, 'pt-br', 5002);
        $this->wpMenuItem(301, 200, 'Pizza', $this->postLink(43, 'recipe'));

        $this->move();

        $this->assertSame('pt-br', $this->menu(200)->language);
        $this->assertSame(['/pt-br/recipe/pizza-pt'], array_column($this->items(200), 'url'));
    }

    public function test_a_first_segment_that_is_not_a_language_is_not_taken_for_one(): void
    {
        config()->set('atrium-wordpress.post_types', ['recipe' => ['prefix' => 'ab']]);
        $this->prepare();
        $this->wpMenu(200, 'Primary');
        $this->wpMenu(201, 'Главное меню', 'glavnoe-menyu');
        $this->translateMenu(200, 'en', 5001);
        $this->translateMenu(201, 'ru', 5001);
        $this->wpMenuItem(301, 200, 'Borscht', $this->postLink(Wp::BORSCHT, 'recipe'));
        $this->wpMenuItem(302, 201, 'Борщ', $this->postLink(Wp::BORSCHT_RU, 'recipe'));

        $this->move();

        $this->assertSame(['/ab/borscht'], array_column($this->items(200), 'url'));
        $this->assertSame(['/ru/ab/borshch'], array_column($this->items(201), 'url'));
    }

    public function test_a_language_in_the_middle_of_an_address_is_not_the_language_of_it(): void
    {
        config()->set('atrium-wordpress.post_types', ['recipe' => ['prefix' => 'x/ru']]);
        $this->prepare();
        $this->wpMenu(200, 'Primary');
        $this->wpMenu(201, 'Главное меню', 'glavnoe-menyu');
        $this->translateMenu(200, 'en', 5001);
        $this->translateMenu(201, 'ru', 5001);
        $this->wpMenuItem(301, 200, 'Borscht', $this->postLink(Wp::BORSCHT, 'recipe'));
        $this->wpMenuItem(302, 201, 'Борщ', $this->postLink(Wp::BORSCHT_RU, 'recipe'));

        $this->move();

        $this->assertSame(['/x/ru/borscht'], array_column($this->items(200), 'url'));
        $this->assertSame(['/ru/x/ru/borshch'], array_column($this->items(201), 'url'));
    }

    public function test_a_menu_of_no_language_is_linked_by_the_addresses_of_the_default_one(): void
    {
        $this->prepare('none');
        $this->wpMenu(200, 'Primary');
        $this->wpMenuItem(301, 200, 'Pizza', $this->postLink(Wp::PIZZA, 'recipe'));

        $this->move();

        $this->assertNull($this->menu(200)->language);
        $this->assertSame(['/recipe/pizza'], array_column($this->items(200), 'url'));
    }

    public function test_a_record_without_an_address_becomes_a_heading_with_a_note(): void
    {
        $this->prepare();
        $this->wpPost(44, 'recipe', 'Secret recipe', ['post_status' => 'draft']);
        $this->wpMenu(200, 'Primary');
        $this->wpMenuItem(301, 200, 'Secret', $this->postLink(44, 'recipe'));

        $result = $this->move();

        $this->assertNull($this->mapRow('post', 44)->paths);
        $this->assertEquals([['title' => 'Secret', 'parent' => null, 'sort' => 10, 'url' => null, 'type' => null, 'id' => null, 'target' => null, 'active' => true]], $this->items(200));
        $this->assertSame(['menu item 301 links to a record that was not moved; it became a heading'], $this->notesWith($result, 'record that was not moved'));
    }

    public function test_a_record_that_is_not_in_the_map_becomes_a_heading_with_a_note(): void
    {
        $this->prepare();
        $this->wpMenu(200, 'Primary');
        $this->wpMenuItem(301, 200, 'About', $this->postLink(Wp::ABOUT, 'page'), 1);
        $this->wpMenuItem(302, 200, 'Team', $this->postLink(Wp::TEAM, 'page'), 2);
        $this->wpMenuItem(303, 200, 'News', $this->termLink(Wp::TERM_NEWS), 3);
        $this->wpMenuItem(304, 200, 'Elsewhere', $this->custom('/elsewhere'), 4);
        Event::listen('eloquent.saved: '.Menu::class, function (): void {
            DB::table('wordpress_map')->where('kind', 'post')->where('wp_id', Wp::ABOUT)->delete();
            DB::table('wordpress_map')->where('kind', 'term')->where('wp_id', Wp::TERM_NEWS + Wp::TT_OFFSET)->delete();
        });

        $result = $this->move();

        $items = $this->items(200);
        $this->assertSame([null, Page::class, null, null], array_column($items, 'type'));
        $this->assertSame([null, $this->target('post', Wp::TEAM)->id, null, null], array_column($items, 'id'));
        $this->assertSame([null, null, null, '/elsewhere'], array_column($items, 'url'));
        $this->assertSame([
            'menu item 301 links to a record that was not moved; it became a heading',
            'menu item 303 links to a record that was not moved; it became a heading',
        ], $this->notesWith($result, 'record that was not moved'));
    }

    public function test_a_link_to_a_post_that_does_not_move_is_a_heading_that_the_navigation_explains(): void
    {
        $this->prepare();
        $this->wpMenu(200, 'Primary');
        $this->wpMenuItem(301, 200, 'Gone', $this->postLink(Wp::TRASHED));
        $this->wpMenuItem(302, 200, 'Plain heading', $this->custom('#'));

        $result = $this->move();

        $this->assertSame([null, null], array_column($this->items(200), 'url'));
        $this->assertSame([null, null], array_column($this->items(200), 'type'));
        $this->assertSame([], $this->notesWith($result, 'record that was not moved'));
        $this->assertCount(1, $this->notesWith($result, 'menu item 301 links to post_type post 15, which does not move; it became a heading'));
    }

    // Depth.

    public function test_the_items_below_the_fourth_level_move_up_to_it(): void
    {
        $this->prepare();
        $this->chain(210, 'Deep', 400);
        $this->wpMenuItem(450, 210, 'L4b', $this->child(403, $this->custom('/l4b')), 9);
        $this->wpMenuItem(451, 210, 'L1b', $this->custom('/l1b'), 9);

        $result = $this->move();

        $this->assertEquals([
            ['title' => 'L1', 'parent' => null, 'sort' => 10, 'url' => '/l1', 'type' => null, 'id' => null, 'target' => null, 'active' => true],
            ['title' => 'L2', 'parent' => 'L1', 'sort' => 10, 'url' => '/l2', 'type' => null, 'id' => null, 'target' => null, 'active' => true],
            ['title' => 'L3', 'parent' => 'L2', 'sort' => 10, 'url' => '/l3', 'type' => null, 'id' => null, 'target' => null, 'active' => true],
            ['title' => 'L4', 'parent' => 'L3', 'sort' => 10, 'url' => '/l4', 'type' => null, 'id' => null, 'target' => null, 'active' => true],
            ['title' => 'L5', 'parent' => 'L3', 'sort' => 20, 'url' => '/l5', 'type' => null, 'id' => null, 'target' => null, 'active' => true],
            ['title' => 'L6', 'parent' => 'L3', 'sort' => 30, 'url' => '/l6', 'type' => null, 'id' => null, 'target' => null, 'active' => true],
            ['title' => 'L4b', 'parent' => 'L3', 'sort' => 40, 'url' => '/l4b', 'type' => null, 'id' => null, 'target' => null, 'active' => true],
            ['title' => 'L1b', 'parent' => null, 'sort' => 20, 'url' => '/l1b', 'type' => null, 'id' => null, 'target' => null, 'active' => true],
        ], $this->items(210));
        $this->assertSame(['the menu "Deep" is deeper than 4 levels: the items below the last level moved up to it'], $this->notesWith($result, 'deeper than'));
    }

    public function test_every_deep_menu_has_a_note_of_its_own(): void
    {
        $this->prepare();
        $this->chain(210, 'Deep', 400);
        $this->chain(211, 'Deeper', 410, 8);

        $result = $this->move();

        $this->assertEqualsCanonicalizing([
            'the menu "Deep" is deeper than 4 levels: the items below the last level moved up to it',
            'the menu "Deeper" is deeper than 4 levels: the items below the last level moved up to it',
        ], $this->notesWith($result, 'deeper than'));
        $this->assertSame([null, 'L1', 'L2', 'L3', 'L3', 'L3', 'L3', 'L3'], array_column($this->items(211), 'parent'));
        $this->assertSame([10, 10, 10, 10, 20, 30, 40, 50], array_column($this->items(211), 'sort'));
    }

    public function test_a_fifth_level_is_the_first_that_is_too_deep(): void
    {
        $this->prepare();
        $this->chain(213, 'Five', 430, 5);

        $result = $this->move();

        $this->assertSame([null, 'L1', 'L2', 'L3', 'L3'], array_column($this->items(213), 'parent'));
        $this->assertSame([10, 10, 10, 10, 20], array_column($this->items(213), 'sort'));
        $this->assertSame(['the menu "Five" is deeper than 4 levels: the items below the last level moved up to it'], $this->notesWith($result, 'deeper than'));
    }

    public function test_four_levels_are_not_too_deep(): void
    {
        $this->prepare();
        $this->chain(212, 'Four', 420, 4);

        $result = $this->move();

        $this->assertSame([null, 'L1', 'L2', 'L3'], array_column($this->items(212), 'parent'));
        $this->assertSame([], $this->notesWith($result, 'deeper than'));
    }

    // Languages.

    public function test_translated_menus_share_a_code_in_wpml(): void
    {
        $this->prepare();
        $this->wpMenu(200, 'Главное меню', 'glavnoe-menyu');
        $this->wpMenu(201, 'Primary', 'primary');
        $this->translateMenu(200, 'ru', 5001);
        $this->translateMenu(201, 'en', 5001);
        $this->wpMenu(202, 'Footer');

        $result = $this->move();

        $ru = $this->menu(200);
        $en = $this->menu(201);
        $this->assertSame(['ru', 'primary', 'Главное меню'], [$ru->language, $ru->code, $ru->name]);
        $this->assertSame(['en', 'primary', 'Primary'], [$en->language, $en->code, $en->name]);
        $this->assertSame(['en', 'footer'], [$this->menu(202)->language, $this->menu(202)->code]);
        $this->assertNotSame($ru->id, $en->id);
        $this->assertSame([], $this->notesWith($result, 'is called'));
    }

    public function test_translated_menus_share_a_code_in_polylang(): void
    {
        $this->prepare('polylang');
        $this->option('stylesheet', 'twentytest');
        $this->option('polylang', serialize(['default_lang' => 'en', 'force_lang' => 1, 'nav_menus' => ['twentytest' => ['primary' => ['en' => 200, 'ru' => 201]]]]));
        $this->wpMenu(200, 'Primary');
        $this->wpMenu(201, 'Главное меню', 'glavnoe-menyu');
        $this->wpMenu(202, 'Footer');

        $result = $this->move();

        $this->assertSame(['en', 'primary'], [$this->menu(200)->language, $this->menu(200)->code]);
        $this->assertSame(['ru', 'primary'], [$this->menu(201)->language, $this->menu(201)->code]);
        $this->assertSame([null, 'footer'], [$this->menu(202)->language, $this->menu(202)->code]);
        $this->assertSame([], $this->notesWith($result, 'is called'));
    }

    public function test_a_site_without_a_language_plugin_has_menus_of_no_language(): void
    {
        $this->prepare('none');
        $this->wpMenu(200, 'Primary');

        $this->move();

        $this->assertNull($this->menu(200)->language);
        $this->assertSame('primary', $this->menu(200)->code);
    }

    // Codes.

    public function test_a_code_that_is_taken_in_the_language_gets_a_number_and_a_note(): void
    {
        $this->prepare();
        $hand = $this->menuRow('primary', 'en');
        $this->wpMenu(200, 'Primary');

        $result = $this->move();

        $this->assertSame('primary-2', $this->menu(200)->code);
        $this->assertSame('primary', DB::table('menus')->find($hand)->code);
        $this->assertSame(['the menu "Primary" is called primary-2: a menu with the code primary is already there'], $this->notesWith($result, 'is called'));
    }

    public function test_the_number_goes_up_until_the_code_is_free(): void
    {
        $this->prepare();
        $this->menuRow('primary', 'en');
        $this->menuRow('primary-2', 'en');
        $this->wpMenu(200, 'Primary');

        $result = $this->move();

        $this->assertSame('primary-3', $this->menu(200)->code);
        $this->assertSame(['the menu "Primary" is called primary-3: a menu with the code primary is already there'], $this->notesWith($result, 'is called'));
    }

    public function test_every_menu_that_cannot_keep_its_code_has_a_note_of_its_own(): void
    {
        $this->prepare();
        $this->menuRow('primary', 'en');
        $this->menuRow('footer', 'en');
        $this->wpMenu(200, 'Primary');
        $this->wpMenu(201, 'Footer');

        $result = $this->move();

        $this->assertCount(2, $this->notesWith($result, 'is called'));
    }

    public function test_a_code_is_free_in_another_language_or_when_it_is_of_a_menu_for_all(): void
    {
        $this->prepare();
        $this->menuRow('primary', 'ru');
        $this->menuRow('footer', null);
        $this->wpMenu(200, 'Primary');
        $this->wpMenu(201, 'Footer');

        $result = $this->move();

        $this->assertSame('primary', $this->menu(200)->code);
        $this->assertSame('footer', $this->menu(201)->code);
        $this->assertSame([], $this->notesWith($result, 'is called'));
    }

    public function test_a_menu_of_no_language_conflicts_only_with_one_of_no_language(): void
    {
        $this->prepare('none');
        $this->menuRow('primary', 'ru');
        $this->menuRow('footer', null);
        $this->wpMenu(200, 'Primary');
        $this->wpMenu(201, 'Footer');

        $result = $this->move();

        $this->assertSame('primary', $this->menu(200)->code);
        $this->assertSame('footer-2', $this->menu(201)->code);
        $this->assertSame(['the menu "Footer" is called footer-2: a menu with the code footer is already there'], $this->notesWith($result, 'is called'));
    }

    public function test_a_menu_of_another_site_does_not_take_the_code(): void
    {
        $shop = $this->shopSite();
        $this->prepare();
        $this->menuRow('primary', 'en', $shop);
        $this->wpMenu(200, 'Primary');

        $result = $this->move();

        $this->assertSame('primary', $this->menu(200)->code);
        $this->assertSame($this->mainSite(), (int) $this->menu(200)->site_id);
        $this->assertSame([], $this->notesWith($result, 'is called'));
    }

    public function test_the_menus_go_to_the_site_of_the_config(): void
    {
        $shop = $this->shopSite();
        config()->set('atrium-wordpress.site', 'shop');
        $this->prepare();
        $this->menuRow('primary', 'en');
        $this->menuRow('footer', 'en', $shop);
        $this->wpMenu(200, 'Primary');
        $this->wpMenu(201, 'Footer');
        $this->wpMenuItem(301, 200, 'Home', $this->custom('/'));

        $first = $this->move();
        $primary = $this->menu(200)->id;
        $second = $this->move();

        $this->assertSame($shop, (int) $this->menu(200)->site_id);
        $this->assertSame('primary', $this->menu(200)->code);
        $this->assertSame('footer-2', $this->menu(201)->code);
        $this->assertSame($primary, $this->menu(200)->id, 'the second run finds the menu of the other site');
        $this->assertSame(1, DB::table('menus')->where('site_id', $shop)->where('code', 'primary')->count());
        $this->assertSame('footer-2', $this->menu(201)->code, 'a menu does not take its own code for a conflict');
        $this->assertCount(1, $this->notesWith($second, 'is called'));
        $this->assertCount(1, $this->notesWith($first, 'is called'));
    }

    // A second run.

    public function test_a_second_run_updates_the_same_menu_and_writes_its_items_again(): void
    {
        $this->prepare();
        $this->nested();
        $this->move();
        $menu = $this->menu(200)->id;
        $maps = DB::table('wordpress_map')->where('kind', 'menu')->count();
        $this->wp()->table('wp_terms')->where('term_id', 200)->update(['name' => 'Primary menu']);
        $this->wp()->table('wp_posts')->where('ID', 302)->update(['post_title' => 'About us']);
        $this->wp()->table('wp_posts')->where('ID', 303)->delete();
        $this->wp()->table('wp_term_relationships')->where('object_id', 303)->delete();
        $this->wpMenuItem(311, 200, 'Contacts', $this->custom('/contacts'), 5);

        $result = $this->move();

        $this->assertSame($menu, $this->menu(200)->id);
        $this->assertSame($maps, DB::table('wordpress_map')->where('kind', 'menu')->count());
        $this->assertSame('Primary menu', $this->menu(200)->name);
        $this->assertSame('primary', $this->menu(200)->code);
        $this->assertSame(['About us', 'History', 'Team', 'Cooks', 'Blog', 'Latest', 'Same A', 'Same B', 'Contacts'], $this->titles(200));
        $this->assertSame([10, 20, 30, 40, 50], array_column(array_filter($this->items(200), static fn (array $item): bool => $item['parent'] === null), 'sort'));
        $this->assertSame(9, DB::table('menu_items')->count());
        $this->assertSame(2, DB::table('menus')->count());
        $this->assertSame(['menus' => 2, 'menu items' => 9], array_intersect_key($result['counts'], ['menus' => 0, 'menu items' => 0]));
    }

    public function test_a_second_run_changes_nothing_when_nothing_changed(): void
    {
        $this->prepare();
        $this->nested();
        $this->move();
        $items = $this->items(200);

        $this->move();

        $this->assertEquals($items, $this->items(200));
        $this->assertSame(9, DB::table('menu_items')->count());
        $this->assertSame('primary', $this->menu(200)->code);
    }

    public function test_a_second_run_keeps_the_number_of_a_code_that_was_taken(): void
    {
        $this->prepare();
        $this->menuRow('primary', 'en');
        $this->wpMenu(200, 'Primary');
        $this->move();

        $second = $this->move();

        $this->assertSame('primary-2', $this->menu(200)->code);
        $this->assertSame(['the menu "Primary" is called primary-2: a menu with the code primary is already there'], $this->notesWith($second, 'is called'));
    }

    public function test_a_second_run_makes_the_menu_what_wordpress_says_again(): void
    {
        $this->prepare('none');
        $this->wpMenu(200, 'Primary');
        $this->move();
        DB::table('menus')->where('id', $this->menu(200)->id)->update(['active' => false, 'language' => 'xx', 'site_id' => null, 'name' => 'Edited']);

        $this->move();

        $menu = $this->menu(200);
        $this->assertTrue($menu->active);
        $this->assertNull($menu->language);
        $this->assertSame($this->mainSite(), (int) $menu->site_id);
        $this->assertSame('Primary', $menu->name);
    }

    public function test_a_menu_that_was_deleted_in_atrium_is_written_again(): void
    {
        $this->prepare();
        $this->nested();
        $this->move();
        $old = $this->menu(200);
        $old->delete();
        $this->assertSame(0, DB::table('menu_items')->count());

        $this->move();

        $new = $this->menu(200);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('primary', $new->code);
        $this->assertSame(2, DB::table('menus')->count());
        $this->assertSame(9, DB::table('menu_items')->count());
        $this->assertSame(1, DB::table('wordpress_map')->where('kind', 'menu')->where('wp_id', 200)->count());
    }

    public function test_only_changed_does_not_skip_the_menus(): void
    {
        $this->prepare();
        $this->nested();
        $this->move();
        $menu = $this->menu(200)->id;
        $this->wp()->table('wp_posts')->where('ID', 302)->update(['post_title' => 'About us']);
        $this->wp()->table('wp_terms')->where('term_id', 200)->update(['name' => 'Primary menu']);

        $result = $this->move(true);

        $this->assertSame(0, $result['counts']['posts:post']);
        $this->assertSame(2, $result['counts']['menus']);
        $this->assertSame(9, $result['counts']['menu items']);
        $this->assertSame($menu, $this->menu(200)->id);
        $this->assertSame('Primary menu', $this->menu(200)->name);
        $this->assertSame('About us', $this->titles(200)[0]);
    }

    public function test_a_menu_that_was_deleted_and_made_again_in_wordpress_is_a_new_menu(): void
    {
        $this->prepare();
        $this->wpMenu(200, 'Primary');
        $this->wpMenuItem(301, 200, 'Old item', $this->custom('/old'));
        $this->move();
        $old = $this->menu(200);
        $this->wp()->table('wp_terms')->where('term_id', 200)->delete();
        $this->wp()->table('wp_term_taxonomy')->where('term_id', 200)->delete();
        $this->wp()->table('wp_term_relationships')->where('object_id', 301)->delete();
        $this->wpMenu(210, 'Primary');
        $this->wpMenuItem(311, 210, 'New item', $this->custom('/new'));

        $result = $this->move();

        $this->assertSame('primary-2', $this->menu(210)->code);
        $this->assertNotSame($old->id, $this->menu(210)->id);
        $this->assertSame(['New item'], $this->titles(210));
        $this->assertSame(['Old item'], $this->titles(200));
        $this->assertCount(1, $this->notesWith($result, 'is called primary-2'));

        $this->assertSame(1, $this->importer()->prune());

        $this->assertFalse($this->moved('menu', 200));
        $this->assertNull(DB::table('menus')->find($old->id));
        $this->assertSame(0, DB::table('menu_items')->where('menu_id', $old->id)->count());
        $this->assertSame(['New item'], $this->titles(210));
        $this->assertSame('primary-2', $this->menu(210)->code);
    }

    // Without the menus module.

    /**
     * @return array<string,array{0:array<int,string>}>
     */
    public static function missingTables(): array
    {
        return [
            'both tables' => [['menu_items', 'menus']],
            'the table of the items' => [['menu_items']],
            'the table of the menus' => [['menus']],
        ];
    }

    /**
     * @param  array<int,string>  $drop
     */
    private function withoutTables(array $drop): void
    {
        foreach ($drop as $table) {
            Schema::drop($table);
        }

        $this->resumeTransaction();
    }

    /**
     * @param  array<int,string>  $drop
     */
    #[DataProvider('missingTables')]
    public function test_menus_are_left_behind_with_a_note_when_the_module_has_no_tables(array $drop): void
    {
        $this->prepare();
        $this->nested();
        $this->withoutTables($drop);

        $result = $this->move();

        $this->assertSame(['2 menus were not moved: install the menus module (alveby/atrium-menus), run its migration and move again'], $this->notesWith($result, 'menus were not moved'));
        $this->assertArrayNotHasKey('menus', $result['counts']);
        $this->assertArrayNotHasKey('menu items', $result['counts']);
        $this->assertSame(8, $result['counts']['posts:post']);
        $this->assertSame(5, $result['counts']['posts:page']);
        $this->assertSame(0, DB::table('wordpress_map')->where('kind', 'menu')->count());
        $this->assertSame(8, DB::table('news')->count());
    }

    /**
     * @param  array<int,string>  $drop
     */
    #[DataProvider('missingTables')]
    public function test_the_audit_warns_when_the_module_has_no_tables(array $drop): void
    {
        $this->prepare();
        $this->nested();
        $this->move();
        $this->withoutTables($drop);

        $report = $this->audit();

        $this->assertSame(['2 menus are not moved: the menus module is not installed'], array_values(array_filter($report['warnings'], static fn (string $warning): bool => str_contains($warning, 'menus'))));
        $this->assertArrayNotHasKey('menus', $report['summary']);
        $this->assertArrayNotHasKey('menu items', $report['summary']);
        $this->assertSame([], $report['problems']);
    }

    public function test_the_audit_has_no_word_to_say_about_a_site_without_menus_and_without_the_module(): void
    {
        $this->prepare();
        $this->wp()->table('wp_term_taxonomy')->where('taxonomy', 'nav_menu')->delete();
        $this->withoutTables(['menu_items', 'menus']);

        $report = $this->audit();

        $this->assertSame([], array_values(array_filter($report['warnings'], static fn (string $warning): bool => str_contains($warning, 'menus'))));
        $this->assertArrayNotHasKey('menus', $report['summary']);
    }

    // Prune.

    public function test_prune_leaves_the_map_of_a_menu_alone_when_the_module_has_no_tables(): void
    {
        $this->prepare();
        $this->nested();
        $this->move();
        $this->wp()->table('wp_posts')->where('ID', Wp::GUTENBERG)->delete();
        $this->withoutTables(['menu_items', 'menus']);
        DB::table('wordpress_map')->insert(['kind' => 'menu', 'wp_id' => 999, 'wp_type' => 'nav_menu', 'target_type' => Menu::class, 'target_id' => 777, 'paths' => null]);

        $this->assertSame(1, $this->importer()->prune());

        $this->assertFalse($this->moved('post', Wp::GUTENBERG));
        $this->assertSame(3, DB::table('wordpress_map')->where('kind', 'menu')->count());
        $this->assertSame(777, (int) $this->mapRow('menu', 999)->target_id);
    }

    public function test_the_prune_command_counts_only_the_posts_when_the_module_has_no_tables(): void
    {
        $this->prepare();
        $this->nested();
        $this->move();
        $this->withoutTables(['menu_items', 'menus']);
        DB::table('wordpress_map')->insert(['kind' => 'menu', 'wp_id' => 999, 'wp_type' => 'nav_menu', 'target_type' => Menu::class, 'target_id' => 777, 'paths' => null]);

        [$exit, $output] = $this->cli('atrium:wordpress:sync', ['--prune' => true]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Deleted: 0', $output);
        $this->assertTrue($this->moved('menu', 999));
    }

    public function test_prune_takes_away_a_menu_that_is_gone_with_its_items_and_its_row_of_the_map(): void
    {
        $this->prepare();
        $this->nested();
        $this->wpMenu(201, 'Footer');
        $this->wpMenuItem(330, 201, 'Terms', $this->custom('/terms'));
        $this->move();
        $gone = $this->menu(200);
        $kept = $this->menu(201);
        $this->wp()->table('wp_term_taxonomy')->where('term_taxonomy_id', 200)->delete();

        $count = $this->importer()->prune();

        $this->assertSame(1, $count);
        $this->assertNull(DB::table('menus')->find($gone->id));
        $this->assertSame(0, DB::table('menu_items')->where('menu_id', $gone->id)->count());
        $this->assertFalse($this->moved('menu', 200));
        $this->assertNotNull(DB::table('menus')->find($kept->id));
        $this->assertSame(['Terms'], $this->titles(201));
        $this->assertTrue($this->moved('menu', 201));
        $this->assertTrue($this->moved('menu', 109));
        $this->assertSame(2, DB::table('menus')->count());
        $this->assertSame(1, DB::table('menu_items')->count());
    }

    public function test_prune_adds_the_menus_to_the_posts_it_takes_away(): void
    {
        $this->prepare();
        $this->nested();
        $this->move();
        $this->wp()->table('wp_posts')->where('ID', Wp::GUTENBERG)->delete();
        $this->wp()->table('wp_term_taxonomy')->where('taxonomy', 'nav_menu')->delete();

        $this->assertSame(3, $this->importer()->prune());
    }

    public function test_prune_leaves_every_menu_that_is_still_there(): void
    {
        $this->prepare();
        $this->nested();
        $this->move();

        $this->assertSame(0, $this->importer()->prune());

        $this->assertSame(2, DB::table('menus')->count());
        $this->assertSame(9, DB::table('menu_items')->count());
        $this->assertSame(2, DB::table('wordpress_map')->where('kind', 'menu')->count());
    }

    public function test_prune_does_not_mind_a_menu_somebody_deleted_in_atrium(): void
    {
        $this->prepare();
        $this->nested();
        $this->move();
        $this->menu(200)->delete();
        $this->wp()->table('wp_term_taxonomy')->where('term_taxonomy_id', 200)->delete();

        $this->assertSame(1, $this->importer()->prune());

        $this->assertFalse($this->moved('menu', 200));
        $this->assertTrue($this->moved('menu', 109));
    }

    public function test_prune_without_the_menus_module_takes_away_only_the_posts(): void
    {
        $this->prepare();
        $this->nested();
        $this->move();
        $this->wp()->table('wp_posts')->where('ID', Wp::GUTENBERG)->delete();
        $this->withoutTables(['menu_items', 'menus']);

        $this->assertSame(1, $this->importer()->prune());

        $this->assertFalse($this->moved('post', Wp::GUTENBERG));
        $this->assertSame(2, DB::table('wordpress_map')->where('kind', 'menu')->count());
    }

    public function test_prune_takes_away_the_menu_of_the_site_in_the_config(): void
    {
        DB::table('admin_sites')->insert(['code' => 'shop', 'name' => 'Shop', 'active' => true, 'is_default' => false, 'sort' => 200, 'created_at' => now(), 'updated_at' => now()]);
        config()->set('atrium-wordpress.site', 'shop');
        $this->prepare();
        $this->wpMenu(200, 'Primary');
        $this->wpMenuItem(301, 200, 'Home', $this->custom('/'));
        $this->move();
        $menu = $this->menu(200)->id;
        $this->wp()->table('wp_term_taxonomy')->where('term_taxonomy_id', 200)->delete();

        $this->assertSame(1, $this->importer()->prune());

        $this->assertNull(DB::table('menus')->find($menu));
        $this->assertSame(0, DB::table('menu_items')->count());
    }

    public function test_the_sync_command_reports_the_menus_it_took_away(): void
    {
        $this->prepare();
        $this->nested();
        $this->move();
        $this->wp()->table('wp_term_taxonomy')->where('term_taxonomy_id', 200)->delete();

        [$exit, $output] = $this->cli('atrium:wordpress:sync', ['--prune' => true]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Deleted: 1', $output);
        $this->assertFalse($this->moved('menu', 200));
    }

    // A deadlock.

    private function deadlock(): QueryException
    {
        return new QueryException('testing', 'update', [], new \PDOException('database table is locked'));
    }

    public function test_a_menu_is_tried_three_times_in_a_deadlock(): void
    {
        $this->onlySqlite();
        $this->prepare();
        $this->nested();
        DB::connection()->commit();
        $tries = 0;
        Event::listen('eloquent.saving: '.Menu::class, function () use (&$tries): void {
            if (++$tries < 3) {
                throw $this->deadlock();
            }
        });

        $result = $this->move();

        $this->assertGreaterThanOrEqual(3, $tries);
        $this->assertSame(2, $result['counts']['menus']);
        $this->assertSame(['About', 'History', 'Team', 'Cooks', 'Shop', 'Blog', 'Latest', 'Same A', 'Same B'], $this->titles(200));
    }

    public function test_a_menu_that_deadlocks_every_time_stops_the_move(): void
    {
        $this->onlySqlite();
        $this->prepare();
        $this->nested();
        DB::connection()->commit();
        $tries = 0;
        Event::listen('eloquent.saving: '.Menu::class, function () use (&$tries): void {
            $tries++;

            throw $this->deadlock();
        });

        try {
            $this->move();
            $this->fail('A deadlock that does not go away must stop the move.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(3, $tries);
    }

    public function test_the_prune_of_menus_is_tried_three_times_in_a_deadlock(): void
    {
        $this->onlySqlite();
        $this->prepare();
        $this->nested();
        $this->move();
        $this->wp()->table('wp_term_taxonomy')->where('term_taxonomy_id', 200)->delete();
        DB::connection()->commit();
        $tries = 0;
        // The menu's own deleting hook answers first and ends the event: the next one is the deleted one.
        Event::listen('eloquent.deleted: '.Menu::class, function () use (&$tries): void {
            if (++$tries < 3) {
                throw $this->deadlock();
            }
        });

        $count = $this->importer()->prune();

        $this->assertSame(3, $tries);
        $this->assertSame(1, $count);
        $this->assertFalse($this->moved('menu', 200));
    }

    public function test_the_prune_of_menus_gives_up_on_a_deadlock_that_does_not_go_away(): void
    {
        $this->onlySqlite();
        $this->prepare();
        $this->nested();
        $this->move();
        $this->wp()->table('wp_term_taxonomy')->where('term_taxonomy_id', 200)->delete();
        DB::connection()->commit();
        $tries = 0;
        // The menu's own deleting hook answers first and ends the event: the next one is the deleted one.
        Event::listen('eloquent.deleted: '.Menu::class, function () use (&$tries): void {
            $tries++;

            throw $this->deadlock();
        });

        try {
            $this->importer()->prune();
            $this->fail('A deadlock that does not go away must stop the prune.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(3, $tries);
        $this->assertTrue($this->moved('menu', 200));
    }

    // The audit.

    private function menuProblem(int $id, string $field, mixed $expected, mixed $actual): array
    {
        return ['scope' => 'menus', 'wp_id' => $id, 'field' => $field, 'expected' => $expected, 'actual' => $actual];
    }

    /**
     * Menu 200: About (Team below it), Shop and Blog, as the audit sees them.
     */
    private function simpleMenu(): void
    {
        $this->wpMenu(200, 'Primary');
        $this->wpMenuItem(301, 200, 'About', $this->postLink(Wp::ABOUT, 'page'), 1);
        $this->wpMenuItem(302, 200, 'Team', $this->child(301, $this->postLink(Wp::TEAM, 'page')), 1);
        $this->wpMenuItem(303, 200, 'Shop', $this->custom('/shop'), 2);
        $this->wpMenuItem(304, 200, 'Blog', $this->postLink(Wp::HELLO), 3);
    }

    /**
     * @return array<int,array{title:string,depth:int}>
     */
    private function flat(): array
    {
        return [
            ['title' => 'About', 'depth' => 1],
            ['title' => 'Team', 'depth' => 2],
            ['title' => 'Shop', 'depth' => 1],
            ['title' => 'Blog', 'depth' => 1],
        ];
    }

    public function test_the_audit_is_clean_after_a_move_of_menus(): void
    {
        $this->prepare();
        $this->nested();
        $this->chain(210, 'Deep', 400);
        $this->wpMenu(213, str_repeat('я', 300), 'long');
        $this->wpMenuItem(460, 213, str_repeat('ы', 300), $this->custom('/long'));
        $this->move();

        $report = $this->audit();

        $this->assertSame([], $report['problems']);
        $this->assertSame(['wordpress' => 4, 'atrium' => 4, 'problems' => 0], $report['summary']['menus']);
        $this->assertSame(['wordpress' => 16, 'atrium' => 16, 'problems' => 0], $report['summary']['menu items']);
        $this->assertSame(['menus', 'menu items'], array_slice(array_keys($report['summary']), -2));
    }

    public function test_the_audit_is_clean_for_a_site_in_one_language_and_for_a_translated_menu(): void
    {
        $this->prepare();
        $this->wpMenu(200, 'Primary');
        $this->wpMenu(201, 'Главное меню', 'glavnoe-menyu');
        $this->translateMenu(200, 'en', 5001);
        $this->translateMenu(201, 'ru', 5001);
        $this->wpMenuItem(301, 200, 'Borscht', $this->postLink(Wp::BORSCHT, 'recipe'));
        $this->wpMenuItem(302, 201, 'Борщ', $this->postLink(Wp::BORSCHT_RU, 'recipe'));
        $this->move();

        $this->assertSame([], $this->audit()['problems']);
    }

    public function test_the_audit_finds_the_menus_of_the_site_in_the_config(): void
    {
        DB::table('admin_sites')->insert(['code' => 'shop', 'name' => 'Shop', 'active' => true, 'is_default' => false, 'sort' => 200, 'created_at' => now(), 'updated_at' => now()]);
        config()->set('atrium-wordpress.site', 'shop');
        $this->prepare();
        $this->simpleMenu();
        $this->move();

        $report = $this->audit();

        $this->assertSame([], $report['problems']);
        $this->assertSame(['wordpress' => 2, 'atrium' => 2, 'problems' => 0], $report['summary']['menus']);
    }

    public function test_the_audit_of_a_site_without_menus_has_no_row_for_them(): void
    {
        $this->prepare();
        $this->wp()->table('wp_term_taxonomy')->where('taxonomy', 'nav_menu')->delete();
        $this->move();

        $report = $this->audit();

        $this->assertSame([], $report['problems']);
        $this->assertArrayNotHasKey('menus', $report['summary']);
        $this->assertArrayNotHasKey('menu items', $report['summary']);
        $this->assertSame([], array_values(array_filter($report['warnings'], static fn (string $warning): bool => str_contains($warning, 'menus'))));
    }

    public function test_the_audit_says_menus_that_were_never_moved_are_not_there(): void
    {
        $this->prepare();
        $this->simpleMenu();

        $report = $this->audit();

        $this->assertSame([
            $this->menuProblem(109, 'record', 'moved', null),
            $this->menuProblem(200, 'record', 'moved', null),
        ], array_values(array_filter($report['problems'], static fn (array $problem): bool => $problem['scope'] === 'menus')));
        $this->assertSame(['wordpress' => 2, 'atrium' => 0, 'problems' => 2], $report['summary']['menus']);
        $this->assertSame(['wordpress' => 4, 'atrium' => 0, 'problems' => 0], $report['summary']['menu items']);
    }

    public function test_the_audit_finds_a_menu_that_was_deleted(): void
    {
        $this->prepare();
        $this->simpleMenu();
        $this->move();
        $this->menu(200)->delete();

        $report = $this->audit();

        $this->assertSame([$this->menuProblem(200, 'record', 'moved', null)], $report['problems']);
        $this->assertSame(['wordpress' => 2, 'atrium' => 1, 'problems' => 1], $report['summary']['menus']);
        $this->assertSame(['wordpress' => 4, 'atrium' => 0, 'problems' => 0], $report['summary']['menu items']);
    }

    public function test_the_audit_finds_a_menu_that_was_renamed(): void
    {
        $this->prepare();
        $this->simpleMenu();
        $this->move();
        DB::table('menus')->where('id', $this->menu(200)->id)->update(['name' => 'Renamed']);

        $report = $this->audit();

        $this->assertSame([$this->menuProblem(200, 'name', 'Primary', 'Renamed')], $report['problems']);
        $this->assertSame(['wordpress' => 2, 'atrium' => 2, 'problems' => 1], $report['summary']['menus']);
        $this->assertSame(['wordpress' => 4, 'atrium' => 4, 'problems' => 0], $report['summary']['menu items']);
    }

    public function test_the_audit_finds_a_menu_of_another_language(): void
    {
        $this->prepare();
        $this->simpleMenu();
        $this->move();
        DB::table('menus')->where('id', $this->menu(200)->id)->update(['language' => 'ru']);

        $this->assertSame([$this->menuProblem(200, 'language', 'en', 'ru')], $this->audit()['problems']);
    }

    public function test_the_audit_finds_a_language_that_was_given_to_a_menu_of_none(): void
    {
        $this->prepare('none');
        $this->simpleMenu();
        $this->move();
        DB::table('menus')->where('id', $this->menu(200)->id)->update(['language' => 'ru']);

        $this->assertSame([$this->menuProblem(200, 'language', null, 'ru')], $this->audit()['problems']);
    }

    public function test_the_audit_finds_an_item_that_was_deleted(): void
    {
        $this->prepare();
        $this->simpleMenu();
        $this->move();
        DB::table('menu_items')->where('menu_id', $this->menu(200)->id)->where('title', 'Shop')->delete();

        $report = $this->audit();

        $this->assertSame([$this->menuProblem(200, 'items', $this->flat(), [
            ['title' => 'About', 'depth' => 1],
            ['title' => 'Team', 'depth' => 2],
            ['title' => 'Blog', 'depth' => 1],
        ])], $report['problems']);
        $this->assertSame(['wordpress' => 2, 'atrium' => 2, 'problems' => 1], $report['summary']['menus']);
        $this->assertSame(['wordpress' => 4, 'atrium' => 3, 'problems' => 1], $report['summary']['menu items']);
    }

    public function test_the_audit_finds_an_item_that_was_renamed(): void
    {
        $this->prepare();
        $this->simpleMenu();
        $this->move();
        DB::table('menu_items')->where('menu_id', $this->menu(200)->id)->where('title', 'Shop')->update(['title' => 'Store']);

        $this->assertSame([$this->menuProblem(200, 'items', $this->flat(), [
            ['title' => 'About', 'depth' => 1],
            ['title' => 'Team', 'depth' => 2],
            ['title' => 'Store', 'depth' => 1],
            ['title' => 'Blog', 'depth' => 1],
        ])], $this->audit()['problems']);
    }

    public function test_the_audit_finds_items_in_another_order(): void
    {
        $this->prepare();
        $this->simpleMenu();
        $this->move();
        $menu = $this->menu(200)->id;
        DB::table('menu_items')->where('menu_id', $menu)->where('title', 'About')->update(['sort' => 25]);

        $this->assertSame([$this->menuProblem(200, 'items', $this->flat(), [
            ['title' => 'Shop', 'depth' => 1],
            ['title' => 'About', 'depth' => 1],
            ['title' => 'Team', 'depth' => 2],
            ['title' => 'Blog', 'depth' => 1],
        ])], $this->audit()['problems']);
    }

    public function test_the_audit_finds_an_item_with_another_parent(): void
    {
        $this->prepare();
        $this->simpleMenu();
        $this->move();
        DB::table('menu_items')->where('menu_id', $this->menu(200)->id)->where('title', 'Team')->update(['parent_id' => null]);

        $this->assertSame([$this->menuProblem(200, 'items', $this->flat(), [
            ['title' => 'About', 'depth' => 1],
            ['title' => 'Team', 'depth' => 1],
            ['title' => 'Shop', 'depth' => 1],
            ['title' => 'Blog', 'depth' => 1],
        ])], $this->audit()['problems']);
    }

    public function test_the_audit_counts_the_problems_of_a_menu_and_of_its_items_apart(): void
    {
        $this->prepare();
        $this->simpleMenu();
        $this->wpMenu(201, 'Footer');
        $this->wpMenuItem(330, 201, 'Terms', $this->custom('/terms'));
        $this->move();
        $menu = $this->menu(200)->id;
        DB::table('menus')->where('id', $menu)->update(['name' => 'Renamed', 'language' => 'ru']);
        DB::table('menu_items')->where('menu_id', $menu)->where('title', 'Shop')->delete();

        $report = $this->audit();

        $this->assertSame([
            $this->menuProblem(200, 'name', 'Primary', 'Renamed'),
            $this->menuProblem(200, 'language', 'en', 'ru'),
            $this->menuProblem(200, 'items', $this->flat(), [
                ['title' => 'About', 'depth' => 1],
                ['title' => 'Team', 'depth' => 2],
                ['title' => 'Blog', 'depth' => 1],
            ]),
        ], $report['problems']);
        $this->assertSame(['wordpress' => 3, 'atrium' => 3, 'problems' => 3], $report['summary']['menus']);
        $this->assertSame(['wordpress' => 5, 'atrium' => 4, 'problems' => 1], $report['summary']['menu items']);
    }

    public function test_the_audit_of_a_menu_without_items_that_got_some(): void
    {
        $this->prepare();
        $this->move();
        $menu = $this->menu(Wp::TERM_MAIN_MENU + Wp::TT_OFFSET)->id;
        DB::table('menu_items')->insert(['menu_id' => $menu, 'parent_id' => null, 'sort' => 10, 'title' => 'Added by hand', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $report = $this->audit();

        $this->assertSame([$this->menuProblem(109, 'items', [], [['title' => 'Added by hand', 'depth' => 1]])], $report['problems']);
        $this->assertSame(['wordpress' => 0, 'atrium' => 1, 'problems' => 1], $report['summary']['menu items']);
    }

    // The records.

    public function test_a_term_is_found_by_its_own_id_when_the_source_gives_the_ids_as_text(): void
    {
        $this->site();
        $plan = $this->plan();
        $records = new Records(new Plan(new TextTermsSource($plan->source), $plan->languages, $plan->types, $plan->taxonomies, $plan->authorsTable, $plan->authorsModel));

        $this->assertSame(102, $records->termRecordByTerm(Wp::TERM_NEWS, 'category'));
        $this->assertSame(105, $records->termRecordByTerm(Wp::TERM_LARAVEL, 'post_tag'));
        $this->assertNull($records->termRecordByTerm(Wp::TERM_NEWS, 'post_tag'));
    }

    // The analysis.

    public function test_the_analysis_says_how_many_menus_go_to_the_module(): void
    {
        $this->site();
        $this->wpMenu(200, 'Primary');

        [$exit, $output] = $this->cli('atrium:wordpress:analyze');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("\nMenus: 2 go to the menus module, with their items\nAuthors go to the table authors;", $output);
    }

    public function test_the_analysis_counts_one_menu(): void
    {
        $this->site();

        [, $output] = $this->cli('atrium:wordpress:analyze');

        $this->assertStringContainsString("\nMenus: 1 go to the menus module, with their items\n", $output);
    }

    public function test_the_analysis_says_when_there_are_no_menus(): void
    {
        $this->site();
        $this->wp()->table('wp_term_taxonomy')->where('taxonomy', 'nav_menu')->delete();

        [, $output] = $this->cli('atrium:wordpress:analyze');

        $this->assertStringContainsString("\nMenus: none\nAuthors go to the table authors;", $output);
        $this->assertStringNotContainsString('go to the menus module', $output);
    }

    /**
     * @param  array<int,string>  $drop
     */
    #[DataProvider('missingTables')]
    public function test_the_analysis_says_the_menus_wait_for_the_module_when_it_has_no_tables(array $drop): void
    {
        $this->prepare();
        $this->wpMenu(200, 'Primary');
        $this->withoutTables($drop);

        [$exit, $output] = $this->cli('atrium:wordpress:analyze');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("\nMenus: 2 are not moved until the menus module (alveby/atrium-menus) is installed\nAuthors go to the table authors;", $output);
        $this->assertStringNotContainsString('go to the menus module', $output);
    }

    public function test_the_analysis_says_there_are_no_menus_even_when_the_module_has_no_tables(): void
    {
        $this->prepare();
        $this->wp()->table('wp_term_taxonomy')->where('taxonomy', 'nav_menu')->delete();
        $this->withoutTables(['menu_items', 'menus']);

        [, $output] = $this->cli('atrium:wordpress:analyze');

        $this->assertStringContainsString("\nMenus: none\nAuthors go to the table authors;", $output);
        $this->assertStringNotContainsString('are not moved until', $output);
    }

    // The module check.

    public function test_the_module_is_installed_when_its_class_and_both_tables_are_there(): void
    {
        $this->prepare();

        $this->assertTrue(Navigation::installed());
    }

    /**
     * @param  array<int,string>  $drop
     */
    #[DataProvider('missingTables')]
    public function test_the_module_is_not_installed_when_a_table_is_missing(array $drop): void
    {
        $this->prepare();
        $this->withoutTables($drop);

        $this->assertFalse(Navigation::installed());
    }

    public function test_the_json_of_the_analysis_does_not_mention_menus(): void
    {
        $this->site();

        [, $output] = $this->cli('atrium:wordpress:analyze', ['--json' => true]);

        $this->assertStringNotContainsString('Menus:', $output);
    }
}

/**
 * A source that gives the numbers of the terms as text, as a driver that
 * does not turn them into integers does; everything else is the inner one's.
 */
final class TextTermsSource implements Source
{
    public function __construct(private readonly Source $inner) {}

    public function option(string $name): ?string
    {
        return $this->inner->option($name);
    }

    public function postTypeCounts(array $statuses = self::STATUSES): array
    {
        return $this->inner->postTypeCounts($statuses);
    }

    public function postIds(string $type, array $statuses = self::STATUSES): array
    {
        return $this->inner->postIds($type, $statuses);
    }

    public function parents(string $type): array
    {
        return $this->inner->parents($type);
    }

    public function metaKeys(string $type): array
    {
        return $this->inner->metaKeys($type);
    }

    public function posts(array $ids): array
    {
        return $this->inner->posts($ids);
    }

    public function meta(array $ids): array
    {
        return $this->inner->meta($ids);
    }

    public function terms(): array
    {
        return array_map(static function (object $term): object {
            $copy = clone $term;
            $copy->term_id = (string) $term->term_id;
            $copy->term_taxonomy_id = (string) $term->term_taxonomy_id;

            return $copy;
        }, $this->inner->terms());
    }

    public function termMeta(): array
    {
        return $this->inner->termMeta();
    }

    public function relationships(array $ids): array
    {
        return $this->inner->relationships($ids);
    }

    public function objectsInTaxonomy(string $taxonomy): array
    {
        return $this->inner->objectsInTaxonomy($taxonomy);
    }

    public function users(array $ids): array
    {
        return $this->inner->users($ids);
    }

    public function wpmlTranslations(): ?array
    {
        return $this->inner->wpmlTranslations();
    }

    public function permalink(int $id): ?string
    {
        return $this->inner->permalink($id);
    }
}
