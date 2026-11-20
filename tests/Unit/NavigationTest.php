<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Unit;

use Atrium\WordPress\Import\Navigation;
use Atrium\WordPress\Import\Records;
use Atrium\WordPress\Plan\Type;
use Atrium\WordPress\Tests\Concerns\ImportsWordPress;
use Atrium\WordPress\Tests\Fixtures\SyntheticWordPress as Wp;
use Atrium\WordPress\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The menus of the made-up WordPress site as trees: which items there are,
 * in what order and where each leads, in which language a menu is and which
 * menus are translations of each other, and what the old site's theme
 * locations became. Every item that cannot keep its place or its link is
 * told in the notes of the records.
 */
final class NavigationTest extends TestCase
{
    use ImportsWordPress;

    /** A post of an invented type that the config leaves behind. */
    private const EVENT = 5002;

    /** A post with a title and no translation in the default language. */
    private const ONLY_RUSSIAN = 5001;

    private Records $records;

    private function navigation(): Navigation
    {
        $this->records = $this->records();

        return new Navigation($this->records);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function menus(): array
    {
        return $this->navigation()->menus();
    }

    /**
     * @param  array<int,array<string,mixed>>  $menus
     * @return array<string,mixed>
     */
    private function menu(array $menus, int $id): array
    {
        foreach ($menus as $menu) {
            if ($menu['id'] === $id) {
                return $menu;
            }
        }

        $this->fail("There is no menu {$id}.");
    }

    /**
     * The items of one menu.
     *
     * @return array<int,array<string,mixed>>
     */
    private function items(int $menu): array
    {
        return $this->menu($this->menus(), $menu)['items'];
    }

    /**
     * The notes the menus gave, without the ones of the records they read on the way.
     *
     * @return array<int,string>
     */
    private function notes(): array
    {
        return array_values(array_filter(
            $this->records->notes(),
            static fn (string $note): bool => str_starts_with($note, 'menu item') || str_starts_with($note, 'the theme location'),
        ));
    }

    /**
     * Item ID => the ID of its children, as deep as the tree goes.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,mixed>
     */
    private function outline(array $items): array
    {
        $outline = [];

        foreach ($items as $item) {
            $outline[$item['id']] = $this->outline($item['children']);
        }

        return $outline;
    }

    /**
     * One field of every item, as deep as the tree goes: item ID => value.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,mixed>
     */
    private function field(array $items, string $key): array
    {
        $values = [];

        foreach ($items as $item) {
            $values[$item['id']] = $item[$key];
            $values += $this->field($item['children'], $key);
        }

        return $values;
    }

    /**
     * An item as the menus must give it.
     *
     * @param  array<string,mixed>|null  $link
     * @param  array<int,array<string,mixed>>  $children
     * @return array<string,mixed>
     */
    private function expected(int $id, string $title, ?array $link, bool $active = true, ?string $target = null, array $children = []): array
    {
        return ['id' => $id, 'title' => $title, 'link' => $link, 'target' => $target, 'active' => $active, 'children' => $children];
    }

    private function heading(int $id, string $type, string $object, int $target): string
    {
        return "menu item {$id} links to {$type} {$object} {$target}, which does not move; it became a heading";
    }

    private function located(string $location, string $menu, string $code): string
    {
        return "the theme location \"{$location}\" showed the menu \"{$menu}\"; Atrium has no locations, the theme draws it by its code: <x-atrium-menu code=\"{$code}\" />";
    }

    /**
     * WPML ties the menu into a group of translations.
     */
    private function translate(int $menu, int $trid, string $language): void
    {
        $this->wp()->table('wp_icl_translations')->insert([
            'element_type' => 'tax_nav_menu',
            'element_id' => $menu,
            'trid' => $trid,
            'language_code' => $language,
            'source_language_code' => $language === 'en' ? null : 'en',
        ]);
    }

    private function translatePost(int $post, int $trid, string $language): void
    {
        $this->wp()->table('wp_icl_translations')->insert([
            'element_type' => 'post_post',
            'element_id' => $post,
            'trid' => $trid,
            'language_code' => $language,
            'source_language_code' => null,
        ]);
    }

    /**
     * @param  array<string,mixed>  $polylang  settings of Polylang besides the default language
     */
    private function polylang(array $polylang): void
    {
        $this->option('polylang', serialize(['default_lang' => 'en', 'force_lang' => 1, ...$polylang]));
    }

    // The menus and their order.

    public function test_a_site_without_menus_has_none_and_remembers_it(): void
    {
        $this->site();
        $this->wp()->table('wp_term_taxonomy')->where('taxonomy', 'nav_menu')->delete();
        $navigation = $this->navigation();
        $this->wp()->enableQueryLog();
        $this->wp()->flushQueryLog();

        $this->assertSame([], $navigation->menus());
        $this->assertSame([], $this->notes());

        // Nothing but the terms is read when there is no menu.
        foreach ($this->wp()->getQueryLog() as $query) {
            $this->assertStringNotContainsString('wp_options', $query['query']);
            $this->assertStringNotContainsString('wp_posts', $query['query']);
        }

        // Read once: a menu that comes later is not seen by the same object.
        $this->wpMenu(300, 'Late menu');
        $this->assertSame([], $navigation->menus());
        $this->assertCount(1, (new Navigation($this->records()))->menus());
    }

    public function test_the_menus_are_read_once(): void
    {
        $this->site();
        $this->wpMenu(300, 'Primary navigation');
        $navigation = $this->navigation();

        $first = $navigation->menus();
        $this->wp()->table('wp_terms')->where('term_id', 300)->update(['name' => 'Renamed']);
        $this->wpMenuItem(3001, 300, 'Late item', ['type' => 'custom', 'url' => '/late']);

        $this->assertSame($first, $navigation->menus());
        $this->assertSame('Primary navigation', $this->menu($navigation->menus(), 300)['name']);
        $this->assertSame([], $this->menu($navigation->menus(), 300)['items']);
    }

    /**
     * @return array<string,array{0:string,1:?string}>
     */
    public static function pluginsOfTheFixture(): array
    {
        return [
            'wpml: a menu outside any translation group is in the default language' => ['wpml', 'en'],
            'polylang: a menu outside any location has no language' => ['polylang', null],
            'no plugin: no language' => ['none', null],
        ];
    }

    #[DataProvider('pluginsOfTheFixture')]
    public function test_the_menu_of_the_fixture_is_a_menu_without_items(string $plugin, ?string $language): void
    {
        $this->site($plugin);

        $this->assertSame([
            ['id' => 109, 'name' => 'Main menu', 'code' => 'main-menu', 'language' => $language, 'items' => []],
        ], $this->menus());
        $this->assertSame([], $this->notes());
    }

    public function test_the_terms_of_other_taxonomies_are_not_menus(): void
    {
        $this->site();
        $this->wpMenu(300, 'Primary navigation');

        $this->assertSame([109, 300], array_column($this->menus(), 'id'));
    }

    public function test_menus_come_in_the_order_of_their_ids_whatever_their_translation_groups(): void
    {
        $this->site();
        $this->wpMenu(300, 'First', 'first');
        $this->wpMenu(301, 'Second', 'second');
        $this->wpMenu(302, 'Third', 'third');
        // 300 and 302 are translations of each other, 301 stands alone.
        $this->translate(300, 7001, 'en');
        $this->translate(302, 7001, 'ru');

        $menus = $this->menus();

        $this->assertSame([0, 1, 2, 3], array_keys($menus));
        $this->assertSame([109, 300, 301, 302], array_column($menus, 'id'));
        $this->assertSame(['main-menu', 'first', 'second', 'first'], array_column($menus, 'code'));
    }

    // The tree of items.

    public function test_items_nest_and_are_ordered_by_menu_order_then_by_id(): void
    {
        $this->site();
        $this->wpMenu(300, 'Primary navigation');
        // Added in an order of their own: the order of the menu is what sorts them.
        $this->wpMenuItem(3001, 300, 'Home', ['type' => 'custom', 'url' => '/'], 3);
        $this->wpMenuItem(3007, 300, 'Blog', ['type' => 'custom', 'url' => '/blog'], 1);
        $this->wpMenuItem(3005, 300, 'Careers', ['type' => 'custom', 'url' => '/careers', 'menu_item_parent' => 3002], 1);
        $this->wpMenuItem(3003, 300, 'Team', ['type' => 'post_type', 'object' => 'page', 'object_id' => Wp::TEAM, 'menu_item_parent' => 3002], 2);
        $this->wpMenuItem(3002, 300, 'About us', ['type' => 'post_type', 'object' => 'page', 'object_id' => Wp::ABOUT, 'menu_item_parent' => 0], 1);
        $this->wpMenuItem(3008, 300, 'Pinned', ['type' => 'custom', 'url' => '/pinned'], -1);
        $this->wpMenuItem(3006, 300, 'Hello', ['type' => 'post_type', 'object' => 'post', 'object_id' => Wp::HELLO, 'menu_item_parent' => 3004], 0, ['post_status' => 'draft']);
        $this->wpMenuItem(3004, 300, 'Company', ['type' => 'post_type', 'object' => 'page', 'object_id' => Wp::HISTORY, 'menu_item_parent' => 3002, 'target' => '_blank'], 1);

        $items = $this->items(300);

        $this->assertSame([
            $this->expected(3008, 'Pinned', ['kind' => 'url', 'url' => '/pinned']),
            $this->expected(3002, 'About us', ['kind' => 'post', 'id' => Wp::ABOUT], true, null, [
                $this->expected(3004, 'Company', ['kind' => 'post', 'id' => Wp::HISTORY], true, '_blank', [
                    $this->expected(3006, 'Hello', ['kind' => 'post', 'id' => Wp::HELLO], false),
                ]),
                $this->expected(3005, 'Careers', ['kind' => 'url', 'url' => '/careers']),
                $this->expected(3003, 'Team', ['kind' => 'post', 'id' => Wp::TEAM]),
            ]),
            $this->expected(3007, 'Blog', ['kind' => 'url', 'url' => '/blog']),
            $this->expected(3001, 'Home', ['kind' => 'url', 'url' => '/']),
        ], $items);
        $this->assertSame([], $this->notes());
    }

    public function test_items_of_the_same_order_go_by_their_ids(): void
    {
        $this->site();
        $this->wpMenu(300, 'Primary navigation');
        $this->wpMenuItem(3003, 300, 'Third', ['type' => 'custom', 'url' => '/3'], 0);
        $this->wpMenuItem(3001, 300, 'First', ['type' => 'custom', 'url' => '/1'], 0);
        $this->wpMenuItem(3002, 300, 'Second', ['type' => 'custom', 'url' => '/2'], 0);

        $this->assertSame([3001, 3002, 3003], array_column($this->items(300), 'id'));
    }

    public function test_every_menu_has_its_own_items(): void
    {
        $this->site();
        $this->wpMenu(300, 'Primary navigation');
        $this->wpMenu(301, 'Footer');
        $this->wpMenuItem(3001, 300, 'Home', ['type' => 'custom', 'url' => '/']);
        $this->wpMenuItem(3002, 301, 'Terms', ['type' => 'custom', 'url' => '/terms']);
        $this->wpMenuItem(3003, 301, 'Privacy', ['type' => 'custom', 'url' => '/privacy'], 1);

        $menus = $this->menus();

        $this->assertSame([], $this->menu($menus, 109)['items']);
        $this->assertSame([3001], array_column($this->menu($menus, 300)['items'], 'id'));
        $this->assertSame([3002, 3003], array_column($this->menu($menus, 301)['items'], 'id'));
    }

    /**
     * @return array<string,array{0:string,1:?bool}>
     */
    public static function statuses(): array
    {
        return [
            'published is active' => ['publish', true],
            'a draft is not' => ['draft', false],
            'a pending one is not' => ['pending', false],
            'a private one is not' => ['private', false],
            'a scheduled one is not part of the menu' => ['future', null],
            'the trash is not' => ['trash', null],
            'an auto-draft is not' => ['auto-draft', null],
            'a revision is not' => ['inherit', null],
        ];
    }

    #[DataProvider('statuses')]
    public function test_the_status_of_an_item_is_its_activity_or_its_absence(string $status, ?bool $active): void
    {
        $this->site();
        $this->wpMenu(300, 'Primary navigation');
        $this->wpMenuItem(3001, 300, 'Always there', ['type' => 'custom', 'url' => '/'], 0);
        $this->wpMenuItem(3002, 300, 'Subject', ['type' => 'custom', 'url' => '/subject'], 1, ['post_status' => $status]);

        $items = $this->items(300);

        $this->assertSame($active === null ? [3001] : [3001, 3002], array_column($items, 'id'));
        $this->assertSame($active === null ? [3001 => true] : [3001 => true, 3002 => $active], $this->field($items, 'active'));
    }

    public function test_only_items_of_the_menu_are_in_it(): void
    {
        $this->site();
        $this->wpMenu(300, 'Primary navigation');
        $this->wpMenuItem(3001, 300, 'Home', ['type' => 'custom', 'url' => '/']);
        // A post filed under the menu is not an item of it.
        $this->wp()->table('wp_term_relationships')->insert(['object_id' => Wp::HELLO, 'term_taxonomy_id' => 300, 'term_order' => 0]);
        $this->wp()->table('wp_term_relationships')->insert(['object_id' => Wp::ABOUT, 'term_taxonomy_id' => 300, 'term_order' => 0]);
        // A menu whose term is gone is no menu, and its items are nobody's.
        $this->wp()->table('wp_term_taxonomy')->insert(['term_taxonomy_id' => 399, 'term_id' => 399, 'taxonomy' => 'nav_menu', 'description' => '', 'parent' => 0, 'count' => 0]);
        $this->wpPost(3099, 'nav_menu_item', 'Nowhere');
        $this->wpMeta(3099, ['_menu_item_type' => 'custom', '_menu_item_url' => '/nowhere']);
        $this->wp()->table('wp_term_relationships')->insert(['object_id' => 3099, 'term_taxonomy_id' => 399, 'term_order' => 0]);

        $menus = $this->menus();

        $this->assertSame([109, 300], array_column($menus, 'id'));
        $this->assertSame([3001], array_column($this->menu($menus, 300)['items'], 'id'));
    }

    // Parents that are not there.

    public function test_an_item_whose_parent_is_not_in_its_menu_or_is_in_a_loop_goes_to_the_top_and_is_told(): void
    {
        $this->site();
        $this->wpMenu(300, 'Primary navigation');
        $this->wpMenu(301, 'Footer');
        $this->wpMenuItem(3001, 300, 'Root', ['type' => 'custom', 'url' => '/']);
        $this->wpMenuItem(3002, 300, 'Lost', ['type' => 'custom', 'url' => '/lost', 'menu_item_parent' => 9999]);
        // Its parent is fine: it follows the parent that moved.
        $this->wpMenuItem(3003, 300, 'Child of lost', ['type' => 'custom', 'url' => '/child', 'menu_item_parent' => 3002]);
        $this->wpMenuItem(3004, 300, 'Loop A', ['type' => 'custom', 'url' => '/a', 'menu_item_parent' => 3005]);
        $this->wpMenuItem(3005, 300, 'Loop B', ['type' => 'custom', 'url' => '/b', 'menu_item_parent' => 3004]);
        $this->wpMenuItem(3006, 300, 'Own parent', ['type' => 'custom', 'url' => '/own', 'menu_item_parent' => 3006]);
        $this->wpMenuItem(3007, 300, 'Elsewhere child', ['type' => 'custom', 'url' => '/elsewhere', 'menu_item_parent' => 4001]);
        $this->wpMenuItem(3008, 300, 'Orphan of the trash', ['type' => 'custom', 'url' => '/trash', 'menu_item_parent' => 3009]);
        $this->wpMenuItem(3009, 300, 'Thrown away', ['type' => 'custom', 'url' => '/thrown'], 0, ['post_status' => 'trash']);
        $this->wpMenuItem(4001, 301, 'Footer root', ['type' => 'custom', 'url' => '/footer']);

        $menus = $this->menus();

        $this->assertSame([
            3001 => [],
            3002 => [3003 => []],
            3004 => [],
            3005 => [],
            3006 => [],
            3007 => [],
            3008 => [],
        ], $this->outline($this->menu($menus, 300)['items']));
        $this->assertSame([4001 => []], $this->outline($this->menu($menus, 301)['items']));

        $lost = static fn (int $id): string => "menu item {$id} has a parent that is not in its menu, or a loop of parents; it moved to the top level";
        $this->assertEqualsCanonicalizing([$lost(3002), $lost(3004), $lost(3005), $lost(3006), $lost(3007), $lost(3008)], $this->notes());
    }

    public function test_an_item_that_lost_its_parent_and_its_link_is_told_of_both(): void
    {
        $this->site();
        $this->wpMenu(300, 'Primary navigation');
        $this->wpMenuItem(3001, 300, 'Both', ['type' => 'gizmo', 'menu_item_parent' => 9999]);
        $this->menus();

        $this->assertSame([
            'menu item 3001 has a parent that is not in its menu, or a loop of parents; it moved to the top level',
            $this->heading(3001, 'gizmo', '', 0),
        ], $this->notes());
    }

    private function lost(int $id): string
    {
        return "menu item {$id} has a parent that is not in its menu, or a loop of parents; it moved to the top level";
    }

    public function test_an_item_that_hangs_from_a_loop_keeps_its_place_and_only_the_loop_moves(): void
    {
        $this->site();
        $this->wpMenu(300, 'Primary navigation');
        $this->wpMenuItem(3001, 300, 'Loop A', ['type' => 'custom', 'url' => '/a', 'menu_item_parent' => 3002]);
        $this->wpMenuItem(3002, 300, 'Loop B', ['type' => 'custom', 'url' => '/b', 'menu_item_parent' => 3001]);
        // Hangs from A, which is in the loop: it is not in the loop itself.
        $this->wpMenuItem(3003, 300, 'Hangs from A', ['type' => 'custom', 'url' => '/c', 'menu_item_parent' => 3001]);
        $this->wpMenuItem(3004, 300, 'Hangs from C', ['type' => 'custom', 'url' => '/d', 'menu_item_parent' => 3003]);

        $this->assertSame([
            3001 => [3003 => [3004 => []]],
            3002 => [],
        ], $this->outline($this->items(300)));
        $this->assertEqualsCanonicalizing([$this->lost(3001), $this->lost(3002)], $this->notes());
    }

    public function test_a_loop_of_three_with_a_tail_moves_only_the_three(): void
    {
        $this->site();
        $this->wpMenu(300, 'Primary navigation');
        // A to C to B to A, with D under B and E under D.
        $this->wpMenuItem(3001, 300, 'A', ['type' => 'custom', 'url' => '/a', 'menu_item_parent' => 3003]);
        $this->wpMenuItem(3002, 300, 'B', ['type' => 'custom', 'url' => '/b', 'menu_item_parent' => 3001]);
        $this->wpMenuItem(3003, 300, 'C', ['type' => 'custom', 'url' => '/c', 'menu_item_parent' => 3002]);
        $this->wpMenuItem(3004, 300, 'D', ['type' => 'custom', 'url' => '/d', 'menu_item_parent' => 3002]);
        $this->wpMenuItem(3005, 300, 'E', ['type' => 'custom', 'url' => '/e', 'menu_item_parent' => 3004]);

        $this->assertSame([
            3001 => [],
            3002 => [3004 => [3005 => []]],
            3003 => [],
        ], $this->outline($this->items(300)));
        $this->assertEqualsCanonicalizing([$this->lost(3001), $this->lost(3002), $this->lost(3003)], $this->notes());
    }

    public function test_an_item_that_is_its_own_parent_is_moved_and_its_children_stay(): void
    {
        $this->site();
        $this->wpMenu(300, 'Primary navigation');
        $this->wpMenuItem(3001, 300, 'Own parent', ['type' => 'custom', 'url' => '/own', 'menu_item_parent' => 3001]);
        $this->wpMenuItem(3002, 300, 'Its child', ['type' => 'custom', 'url' => '/child', 'menu_item_parent' => 3001]);

        $this->assertSame([3001 => [3002 => []]], $this->outline($this->items(300)));
        $this->assertSame([$this->lost(3001)], $this->notes());
    }

    public function test_a_parent_that_is_in_the_menu_is_not_told(): void
    {
        $this->site();
        $this->wpMenu(300, 'Primary navigation');
        $this->wpMenuItem(3001, 300, 'Root', ['type' => 'custom', 'url' => '/']);
        $this->wpMenuItem(3002, 300, 'Child', ['type' => 'custom', 'url' => '/child', 'menu_item_parent' => 3001]);
        $this->wpMenuItem(3003, 300, 'Grandchild', ['type' => 'custom', 'url' => '/grandchild', 'menu_item_parent' => 3002]);

        $this->assertSame([3001 => [3002 => [3003 => []]]], $this->outline($this->items(300)));
        $this->assertSame([], $this->notes());
    }

    // Where an item leads.

    /**
     * @return array<string,array{0:?string,1:?array<string,string>}>
     */
    public static function customUrls(): array
    {
        return [
            'an address' => ['https://example.org/contact', ['kind' => 'url', 'url' => 'https://example.org/contact']],
            'an address of the site' => ['/contact', ['kind' => 'url', 'url' => '/contact']],
            'an address with spaces around' => ['  https://example.org/contact  ', ['kind' => 'url', 'url' => 'https://example.org/contact']],
            'a hash is a heading' => ['#', null],
            'a hash with spaces around is a heading' => [' # ', null],
            'an empty address is a heading' => ['', null],
            'only spaces is a heading' => ['   ', null],
            'no address is a heading' => [null, null],
            'a file of the old uploads' => ['https://old.example.com/wp-content/uploads/menu/brochure.pdf', ['kind' => 'url', 'url' => '/wp-content/uploads/menu/brochure.pdf']],
            'a file of the uploads by its path' => ['/wp-content/uploads/menu/brochure.pdf', ['kind' => 'url', 'url' => '/wp-content/uploads/menu/brochure.pdf']],
            'a page of the old site is kept as it is' => ['https://old.example.com/about/', ['kind' => 'url', 'url' => 'https://old.example.com/about/']],
            'a hash with a name is an address' => ['#team', ['kind' => 'url', 'url' => '#team']],
        ];
    }

    /**
     * @param  array<string,string>|null  $link
     */
    #[DataProvider('customUrls')]
    public function test_a_custom_link_is_an_address_or_a_heading(?string $url, ?array $link): void
    {
        $this->site();
        $this->wpMenu(300, 'Primary navigation');
        $this->wpMenuItem(3001, 300, 'Custom', $url === null ? ['type' => 'custom'] : ['type' => 'custom', 'url' => $url]);

        $this->assertSame([3001 => $link], $this->field($this->items(300), 'link'));
        // Nothing is wrong with a heading that was one on purpose.
        $this->assertSame([], $this->notes());
    }

    public function test_an_address_of_the_uploads_on_a_site_with_a_port_is_the_new_address(): void
    {
        $this->site();
        $this->option('home', 'http://localhost:18081');
        $this->option('siteurl', 'http://localhost:18081');
        $this->wpMenu(300, 'Primary navigation');
        $this->wpMenuItem(3001, 300, 'Same port', ['type' => 'custom', 'url' => 'http://localhost:18081/wp-content/uploads/a.jpg'], 0);
        $this->wpMenuItem(3002, 300, 'Other port', ['type' => 'custom', 'url' => 'http://localhost:18082/wp-content/uploads/a.jpg'], 1);

        $this->assertSame([
            3001 => ['kind' => 'url', 'url' => '/wp-content/uploads/a.jpg'],
            3002 => ['kind' => 'url', 'url' => 'http://localhost:18082/wp-content/uploads/a.jpg'],
        ], $this->field($this->items(300), 'link'));
    }

    public function test_a_post_that_moved_is_linked_by_the_post_of_its_group(): void
    {
        $this->site();
        $this->wpPost(self::ONLY_RUSSIAN, 'post', 'Только по-русски');
        $this->translatePost(self::ONLY_RUSSIAN, 7001, 'ru');
        $this->wpMenu(300, 'Primary navigation');
        $links = [
            3001 => ['post', Wp::HELLO, Wp::HELLO],
            3002 => ['post', Wp::HELLO_RU, Wp::HELLO],
            3003 => ['page', Wp::ABOUT, Wp::ABOUT],
            3004 => ['page', Wp::ABOUT_RU, Wp::ABOUT],
            3005 => ['recipe', Wp::BORSCHT, Wp::BORSCHT],
            3006 => ['recipe', Wp::BORSCHT_RU, Wp::BORSCHT],
            3007 => ['post', Wp::DRAFT_IDEA, Wp::DRAFT_IDEA],
            3008 => ['post', Wp::SCHEDULED, Wp::SCHEDULED],
            3009 => ['post', Wp::PRIVATE_NOTE, Wp::PRIVATE_NOTE],
            3010 => ['post', self::ONLY_RUSSIAN, self::ONLY_RUSSIAN],
            3011 => ['recipe', Wp::PIZZA, Wp::PIZZA],
        ];

        foreach ($links as $id => [$type, $post]) {
            $this->wpMenuItem($id, 300, 'Item '.$id, ['type' => 'post_type', 'object' => $type, 'object_id' => $post], $id);
        }

        $expected = [];

        foreach ($links as $id => [, , $main]) {
            $expected[$id] = ['kind' => 'post', 'id' => $main];
        }

        $this->assertSame($expected, $this->field($this->items(300), 'link'));
        $this->assertSame([], $this->notes());
    }

    public function test_a_post_that_did_not_move_makes_a_heading_and_is_told(): void
    {
        config()->set('atrium-wordpress.post_types', ['event' => 'skip']);
        $this->site();
        $this->wpPost(self::EVENT, 'event', 'Concert');
        $this->wpMenu(300, 'Primary navigation');
        $items = [
            3001 => ['post', Wp::TRASHED],
            3002 => ['post', Wp::AUTO_DRAFT],
            3003 => ['revision', Wp::REVISION],
            3004 => ['attachment', Wp::MANUAL],
            3005 => ['event', self::EVENT],
            3006 => ['post', Wp::NO_SUCH_POST],
        ];

        foreach ($items as $id => [$type, $post]) {
            $this->wpMenuItem($id, 300, 'Item '.$id, ['type' => 'post_type', 'object' => $type, 'object_id' => $post], $id);
        }

        $this->assertSame(array_fill_keys(array_keys($items), null), $this->field($this->items(300), 'link'));

        $expected = [];

        foreach ($items as $id => [$type, $post]) {
            $expected[] = $this->heading($id, 'post_type', $type, $post);
        }

        $this->assertSame($expected, $this->notes());
    }

    public function test_a_term_that_moved_is_linked_by_the_term_of_its_group(): void
    {
        $this->site();
        $this->wpMenu(300, 'Primary navigation');
        $links = [
            3001 => ['category', Wp::TERM_UNCATEGORIZED, 101],
            3002 => ['category', Wp::TERM_NEWS, 102],
            3003 => ['category', Wp::TERM_NOVOSTI, 102],
            3004 => ['category', Wp::TERM_LOCAL, 103],
            3005 => ['post_tag', Wp::TERM_LARAVEL, 105],
            3006 => ['cuisine', Wp::TERM_ITALIAN, 107],
        ];

        foreach ($links as $id => [$taxonomy, $term]) {
            $this->wpMenuItem($id, 300, 'Item '.$id, ['type' => 'taxonomy', 'object' => $taxonomy, 'object_id' => $term], $id);
        }

        $expected = [];

        foreach ($links as $id => [$taxonomy, , $record]) {
            $expected[$id] = ['kind' => 'term', 'id' => $record, 'taxonomy' => $taxonomy];
        }

        $this->assertSame($expected, $this->field($this->items(300), 'link'));
        $this->assertSame([], $this->notes());
    }

    public function test_a_term_that_did_not_move_makes_a_heading_and_is_told(): void
    {
        $this->site();
        // Posts that have the IDs of the terms: a term is not looked for among them.
        $this->wpPost(Wp::TERM_NEWS, 'post', 'Same number as the news');
        $this->wpPost(Wp::TERM_LARAVEL, 'post', 'Same number as Laravel');
        $this->wpMenu(300, 'Primary navigation');
        $items = [
            3001 => ['nav_menu', Wp::TERM_MAIN_MENU],
            3002 => ['category', 9999],
            // The ID of a term of another taxonomy.
            3003 => ['post_tag', Wp::TERM_NEWS],
            3004 => ['category', Wp::TERM_LARAVEL],
            3005 => ['', Wp::TERM_NEWS],
        ];

        foreach ($items as $id => [$taxonomy, $term]) {
            $this->wpMenuItem($id, 300, 'Item '.$id, ['type' => 'taxonomy', 'object' => $taxonomy, 'object_id' => $term], $id);
        }

        $this->assertSame(array_fill_keys(array_keys($items), null), $this->field($this->items(300), 'link'));

        $expected = [];

        foreach ($items as $id => [$taxonomy, $term]) {
            $expected[] = $this->heading($id, 'taxonomy', $taxonomy, $term);
        }

        $this->assertSame($expected, $this->notes());
    }

    public function test_the_archive_of_a_type_that_moved_is_its_address_in_the_language_of_the_menu(): void
    {
        $this->site();
        $this->wpMenu(300, 'Main', 'main');
        $this->wpMenu(301, 'Principal', 'principal');
        $this->translate(300, 7001, 'en');
        $this->translate(301, 7001, 'ru');
        $this->wpMenuItem(3001, 300, 'News', ['type' => 'post_type_archive', 'object' => 'post'], 0);
        $this->wpMenuItem(3002, 300, 'Recipes', ['type' => 'post_type_archive', 'object' => 'recipe'], 1);
        $this->wpMenuItem(3101, 301, 'Новости', ['type' => 'post_type_archive', 'object' => 'post'], 0);
        $this->wpMenuItem(3102, 301, 'Рецепты', ['type' => 'post_type_archive', 'object' => 'recipe'], 1);

        $menus = $this->menus();

        $this->assertSame([
            3001 => ['kind' => 'url', 'url' => '/news/'],
            3002 => ['kind' => 'url', 'url' => '/recipe/'],
        ], $this->field($this->menu($menus, 300)['items'], 'link'));
        $this->assertSame([
            3101 => ['kind' => 'url', 'url' => '/ru/news/'],
            3102 => ['kind' => 'url', 'url' => '/ru/recipe/'],
        ], $this->field($this->menu($menus, 301)['items'], 'link'));
        $this->assertSame([], $this->notes());
    }

    public function test_the_archive_on_a_site_without_a_language_plugin_is_in_the_language_of_the_site(): void
    {
        // The site is in Russian: its own language has no prefix.
        $this->site('none');
        $this->wpMenu(300, 'Main', 'main');
        $this->wpMenuItem(3001, 300, 'News', ['type' => 'post_type_archive', 'object' => 'post']);

        $this->assertSame('ru', $this->records()->plan->languages->default());
        $this->assertSame([3001 => ['kind' => 'url', 'url' => '/news/']], $this->field($this->items(300), 'link'));
    }

    public function test_the_archive_follows_the_language_polylang_gave_the_menu(): void
    {
        $this->site('polylang');
        $this->wpMenu(300, 'Main', 'main');
        $this->wpMenu(301, 'Principal', 'principal');
        $this->option('stylesheet', 'sitetheme');
        $this->polylang(['nav_menus' => ['sitetheme' => ['primary' => ['en' => 300, 'ru' => 301]]]]);
        $this->wpMenuItem(3001, 300, 'News', ['type' => 'post_type_archive', 'object' => 'post']);
        $this->wpMenuItem(3101, 301, 'Новости', ['type' => 'post_type_archive', 'object' => 'post']);

        $menus = $this->menus();

        $this->assertSame([3001 => ['kind' => 'url', 'url' => '/news/']], $this->field($this->menu($menus, 300)['items'], 'link'));
        $this->assertSame([3101 => ['kind' => 'url', 'url' => '/ru/news/']], $this->field($this->menu($menus, 301)['items'], 'link'));
    }

    public function test_the_archive_of_a_type_that_did_not_move_makes_a_heading_and_is_told(): void
    {
        config()->set('atrium-wordpress.post_types', ['event' => 'skip']);
        $this->site();
        $this->wpPost(self::EVENT, 'event', 'Concert');
        $this->wpMenu(300, 'Primary navigation');
        $this->wpMenuItem(3001, 300, 'Events', ['type' => 'post_type_archive', 'object' => 'event'], 0);
        $this->wpMenuItem(3002, 300, 'Gizmos', ['type' => 'post_type_archive', 'object' => 'gizmo'], 1);

        $this->assertSame([3001 => null, 3002 => null], $this->field($this->items(300), 'link'));
        $this->assertSame([
            $this->heading(3001, 'post_type_archive', 'event', 0),
            $this->heading(3002, 'post_type_archive', 'gizmo', 0),
        ], $this->notes());
    }

    public function test_an_item_of_an_unknown_type_is_a_heading_whatever_it_names(): void
    {
        $this->site();
        $this->wpPost(Wp::TERM_NEWS, 'post', 'Same number as the news');
        $this->wpMenu(300, 'Primary navigation');
        // Each names something that would be a link if the type were a known one.
        $this->wpMenuItem(3001, 300, 'Post', ['type' => 'gizmo', 'object' => 'post', 'object_id' => Wp::HELLO], 0);
        $this->wpMenuItem(3002, 300, 'Term', ['type' => 'gizmo', 'object' => 'category', 'object_id' => Wp::TERM_NEWS], 1);
        $this->wpMenuItem(3003, 300, 'Archive', ['type' => 'gizmo', 'object' => 'post'], 2);
        $this->wpMenuItem(3004, 300, 'Address', ['type' => 'gizmo', 'url' => 'https://example.org/'], 3);
        // A type with its object of another kind.
        $this->wpMenuItem(3005, 300, 'Post of a term', ['type' => 'post_type', 'object' => 'category', 'object_id' => Wp::TERM_LOCAL], 4);
        $this->wpMenuItem(3006, 300, 'Term of a post', ['type' => 'taxonomy', 'object' => 'post', 'object_id' => Wp::HELLO], 5);
        $this->wpMenuItem(3007, 300, 'Archive of a term', ['type' => 'post_type_archive', 'object' => 'category', 'object_id' => Wp::TERM_NEWS], 6);

        $this->assertSame(array_fill_keys(range(3001, 3007), null), $this->field($this->items(300), 'link'));
        $this->assertSame([
            $this->heading(3001, 'gizmo', 'post', Wp::HELLO),
            $this->heading(3002, 'gizmo', 'category', Wp::TERM_NEWS),
            $this->heading(3003, 'gizmo', 'post', 0),
            $this->heading(3004, 'gizmo', '', 0),
            $this->heading(3005, 'post_type', 'category', Wp::TERM_LOCAL),
            $this->heading(3006, 'taxonomy', 'post', Wp::HELLO),
            $this->heading(3007, 'post_type_archive', 'category', Wp::TERM_NEWS),
        ], $this->notes());
    }

    public function test_an_item_without_any_meta_is_a_heading_and_is_told(): void
    {
        $this->site();
        $this->wpMenu(300, 'Primary navigation');
        $this->wpMenuItem(3001, 300, 'Bare', []);

        $this->assertSame([
            $this->expected(3001, 'Bare', null),
        ], $this->items(300));
        $this->assertSame(['menu item 3001 links to   0, which does not move; it became a heading'], $this->notes());
    }

    public function test_the_target_is_blank_or_nothing(): void
    {
        $this->site();
        $this->wpMenu(300, 'Primary navigation');
        $this->wpMenuItem(3001, 300, 'Blank', ['type' => 'custom', 'url' => '/a', 'target' => '_blank'], 0);
        $this->wpMenuItem(3002, 300, 'Empty', ['type' => 'custom', 'url' => '/b', 'target' => ''], 1);
        $this->wpMenuItem(3003, 300, 'Self', ['type' => 'custom', 'url' => '/c', 'target' => '_self'], 2);
        $this->wpMenuItem(3004, 300, 'Loud', ['type' => 'custom', 'url' => '/d', 'target' => '_BLANK'], 3);
        $this->wpMenuItem(3005, 300, 'None', ['type' => 'custom', 'url' => '/e'], 4);

        $this->assertSame([3001 => '_blank', 3002 => null, 3003 => null, 3004 => null, 3005 => null], $this->field($this->items(300), 'target'));
    }

    // The title.

    public function test_an_item_has_its_own_title_cleaned_up_or_the_one_of_what_it_links_to(): void
    {
        $this->site();
        $this->wpPost(5003, 'post', '');
        // An item that names no object must not find the post 1 or -1.
        $this->wpPost(1, 'post', 'Post one');
        $this->wpPost(-1, 'post', 'Post minus one');
        $this->wpMenu(300, 'Primary navigation');
        $items = [
            // [title, meta]
            3001 => ['  Contact us  ', ['type' => 'custom', 'url' => '/contact']],
            3002 => [Wp::encodingTitle(), ['type' => 'custom', 'url' => '/encoding']],
            3003 => ['Own title', ['type' => 'post_type', 'object' => 'post', 'object_id' => Wp::HELLO]],
            3004 => ['', ['type' => 'post_type', 'object' => 'post', 'object_id' => Wp::HELLO]],
            3005 => ['   ', ['type' => 'post_type', 'object' => 'page', 'object_id' => Wp::ABOUT]],
            // The title of the translation it names, not of the main post.
            3006 => ['', ['type' => 'post_type', 'object' => 'post', 'object_id' => Wp::HELLO_RU]],
            3007 => ['', ['type' => 'post_type', 'object' => 'post', 'object_id' => Wp::NO_SUCH_POST]],
            3008 => ['', ['type' => 'post_type', 'object' => 'post', 'object_id' => 5003]],
            3009 => ['', ['type' => 'taxonomy', 'object' => 'category', 'object_id' => Wp::TERM_NEWS]],
            3010 => ['', ['type' => 'taxonomy', 'object' => 'category', 'object_id' => Wp::TERM_NOVOSTI]],
            3011 => ['', ['type' => 'taxonomy', 'object' => 'post_tag', 'object_id' => Wp::TERM_NEWS]],
            3012 => ['', ['type' => 'taxonomy', 'object_id' => Wp::TERM_NEWS]],
            // The ID of a post is not the ID of a term.
            3013 => ['', ['type' => 'taxonomy', 'object' => 'category', 'object_id' => Wp::HELLO]],
            // A post is not looked for by the ID of a term.
            3014 => ['', ['type' => 'taxonomy', 'object' => 'post_tag', 'object_id' => Wp::TERM_LARAVEL]],
            3015 => ['', ['type' => 'custom', 'url' => '/nameless']],
            3016 => ['', ['type' => 'custom', 'url' => '/nameless', 'object_id' => Wp::HELLO]],
            3017 => ['', ['type' => 'post_type_archive', 'object' => 'post', 'object_id' => Wp::HELLO]],
            3018 => ['', []],
            // Only an item of a taxonomy takes the name of a term.
            3019 => ['', ['type' => 'custom', 'url' => '/nameless', 'object' => 'category', 'object_id' => Wp::TERM_NEWS]],
            3020 => ['', ['type' => 'post_type_archive', 'object' => 'category', 'object_id' => Wp::TERM_NEWS]],
            3021 => ['', ['type' => 'post_type', 'object' => 'post']],
        ];

        foreach ($items as $id => [$title, $meta]) {
            $this->wpMenuItem($id, 300, $title, $meta, $id);
        }

        $this->assertSame([
            3001 => 'Contact us',
            3002 => Wp::ENCODING_TITLE,
            3003 => 'Own title',
            3004 => 'Hello World',
            3005 => 'About',
            3006 => 'Привет, мир',
            3007 => 'Untitled 3007',
            3008 => 'Untitled 3008',
            3009 => 'News',
            3010 => 'Новости',
            3011 => 'Untitled 3011',
            3012 => 'Untitled 3012',
            3013 => 'Untitled 3013',
            3014 => 'Laravel',
            3015 => 'Untitled 3015',
            3016 => 'Untitled 3016',
            3017 => 'Untitled 3017',
            3018 => 'Untitled 3018',
            3019 => 'Untitled 3019',
            3020 => 'Untitled 3020',
            3021 => 'Untitled 3021',
        ], $this->field($this->items(300), 'title'));
    }

    public function test_ids_that_come_as_strings_are_read_as_numbers(): void
    {
        $this->site('polylang');
        $this->wpMenu(300, 'Primary', 'primary');
        $this->wpMenu(301, 'Principal', 'principal');
        $this->option('stylesheet', 'sitetheme');
        $this->option('theme_mods_sitetheme', serialize(['nav_menu_locations' => ['top' => 300]]));
        $this->polylang(['nav_menus' => ['sitetheme' => ['primary' => ['en' => 300, 'ru' => 301]]]]);
        // "10" comes after "9" whatever the strings say.
        $this->wpMenuItem(3001, 300, 'Ten', ['type' => 'custom', 'url' => '/ten'], 10);
        $this->wpMenuItem(3002, 300, 'Nine', ['type' => 'custom', 'url' => '/nine'], 9);
        $this->wpMenuItem(3003, 300, '', ['type' => 'taxonomy', 'object' => 'category', 'object_id' => Wp::TERM_NEWS], 8);
        $this->wp()->getPdo()->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);

        $menus = $this->menus();

        $this->assertSame([109 => null, 300 => 'en', 301 => 'ru'], array_column($menus, 'language', 'id'));
        $this->assertSame([109 => 'main-menu', 300 => 'primary', 301 => 'primary'], array_column($menus, 'code', 'id'));
        $this->assertSame([3003, 3002, 3001], array_column($this->menu($menus, 300)['items'], 'id'));
        $this->assertSame('News', $this->menu($menus, 300)['items'][0]['title']);
        $this->assertSame([
            $this->located('top', 'Primary', 'primary'),
            $this->located('primary', 'Primary', 'primary'),
            $this->located('primary', 'Principal', 'primary'),
        ], $this->notes());
    }

    public function test_an_item_without_a_title_keeps_the_title_of_a_post_that_did_not_move(): void
    {
        $this->site();
        $this->wpMenu(300, 'Primary navigation');
        // WordPress shows the title of the post even where the post has gone to the trash.
        $this->wpMenuItem(3001, 300, '', ['type' => 'post_type', 'object' => 'post', 'object_id' => Wp::TRASHED]);

        $this->assertSame([3001 => 'Thrown away'], $this->field($this->items(300), 'title'));
    }

    // The code and the name of a menu.

    /**
     * @return array<string,array{0:string,1:string,2:string,3:string}>
     */
    public static function names(): array
    {
        $long = 'q'.str_repeat('x', 99);

        return [
            'the slug' => ['Primary navigation', 'primary-navigation', 'primary-navigation', 'Primary navigation'],
            'a slug that is not Latin is spelled out' => ['Меню', '%d0%bc%d0%b5%d0%bd%d1%8e', 'meniu', 'Меню'],
            'the name when there is no slug' => ['Footer links', '', 'footer-links', 'Footer links'],
            'a made-up code when neither says anything' => ['!!!', '', 'menu-300', '!!!'],
            'the code for a name that is nothing' => ['', 'unnamed', 'unnamed', 'unnamed'],
            'a made-up code for a name of spaces and no slug' => ['   ', '', 'menu-300', 'menu-300'],
            'a name that is trimmed' => ['  Spaced name  ', 'spaced', 'spaced', 'Spaced name'],
            'sixty characters of the slug' => ['Long', $long, 'q'.str_repeat('x', 59), 'Long'],
            'a slug of exactly sixty characters' => ['Sixty', str_repeat('y', 60), str_repeat('y', 60), 'Sixty'],
            'a slug of fifty nine characters' => ['Fifty-nine', str_repeat('y', 59), str_repeat('y', 59), 'Fifty-nine'],
        ];
    }

    #[DataProvider('names')]
    public function test_the_code_comes_from_the_slug_and_the_name_has_a_fallback(string $name, string $slug, string $code, string $expected): void
    {
        $this->site();
        $this->wpMenu(300, $name, $slug === '' ? 'placeholder' : $slug);
        // The helper makes a slug of the name; the old site had none.
        $this->wp()->table('wp_terms')->where('term_id', 300)->update(['name' => $name, 'slug' => $slug]);

        $menu = $this->menu($this->menus(), 300);

        $this->assertSame($code, $menu['code']);
        $this->assertSame($expected, $menu['name']);
    }

    public function test_menus_of_the_same_slug_get_codes_of_their_own(): void
    {
        $this->site();
        $this->wpMenu(300, 'Twin', 'twin');
        $this->wpMenu(301, 'Twin', 'twin');
        $this->wpMenu(302, 'Twin', 'twin');
        $this->wpMenu(303, 'Another main', 'main-menu');
        $this->wpMenu(304, 'Twin again', 'twin-2');

        $this->assertSame([
            109 => 'main-menu',
            300 => 'twin',
            301 => 'twin-2',
            302 => 'twin-3',
            303 => 'main-menu-2',
            // Its own slug is taken by the second twin: the number goes on.
            304 => 'twin-2-2',
        ], array_column($this->menus(), 'code', 'id'));
    }

    public function test_the_number_of_a_code_comes_after_the_sixty_characters(): void
    {
        $this->site();
        $long = str_repeat('lng', 34);
        $this->wpMenu(300, 'Long one', $long);
        $this->wpMenu(301, 'Long two', $long);

        $this->assertSame([109 => 'main-menu', 300 => str_repeat('lng', 20), 301 => str_repeat('lng', 20).'-2'], array_column($this->menus(), 'code', 'id'));
    }

    // Languages.

    public function test_without_a_language_plugin_no_menu_has_a_language_and_each_is_its_own_group(): void
    {
        $this->site('none');
        $this->wpMenu(300, 'Primary navigation', 'primary');
        $this->wpMenu(301, 'Other', 'other');

        $menus = $this->menus();

        $this->assertSame([109 => null, 300 => null, 301 => null], array_column($menus, 'language', 'id'));
        $this->assertSame([109 => 'main-menu', 300 => 'primary', 301 => 'other'], array_column($menus, 'code', 'id'));
    }

    public function test_wpml_gives_a_menu_its_language_and_translations_share_the_code_of_the_default_one(): void
    {
        $this->site();
        $this->wpMenu(300, 'Principal', 'principal');
        $this->wpMenu(301, 'Main', 'main');
        $this->wpMenu(302, 'Hauptmenü', 'hauptmenu');
        $this->wpMenu(303, 'Alone', 'alone');
        $this->translate(300, 7001, 'ru');
        $this->translate(301, 7001, 'en');
        $this->translate(302, 7001, 'de');
        $this->wpMenuItem(3001, 300, 'Главная', ['type' => 'custom', 'url' => '/ru']);
        $this->wpMenuItem(3002, 301, 'Home', ['type' => 'custom', 'url' => '/en']);

        $menus = $this->menus();

        // The code is the slug of the menu in the default language, though it is not the first.
        $this->assertSame([
            ['id' => 109, 'name' => 'Main menu', 'code' => 'main-menu', 'language' => 'en', 'items' => []],
            ['id' => 300, 'name' => 'Principal', 'code' => 'main', 'language' => 'ru', 'items' => [$this->expected(3001, 'Главная', ['kind' => 'url', 'url' => '/ru'])]],
            ['id' => 301, 'name' => 'Main', 'code' => 'main', 'language' => 'en', 'items' => [$this->expected(3002, 'Home', ['kind' => 'url', 'url' => '/en'])]],
            ['id' => 302, 'name' => 'Hauptmenü', 'code' => 'main', 'language' => 'de', 'items' => []],
            ['id' => 303, 'name' => 'Alone', 'code' => 'alone', 'language' => 'en', 'items' => []],
        ], $menus);
    }

    public function test_translations_without_one_in_the_default_language_share_the_code_of_the_lowest_one(): void
    {
        $this->site();
        $this->wpMenu(300, 'Zwei', 'zwei');
        $this->wpMenu(301, 'Eins', 'eins');
        $this->wpMenu(302, 'Drei', 'drei');
        $this->translate(300, 7001, 'ru');
        $this->translate(301, 7001, 'de');
        $this->translate(302, 7001, 'fr');

        $this->assertSame([109 => 'main-menu', 300 => 'zwei', 301 => 'zwei', 302 => 'zwei'], array_column($this->menus(), 'code', 'id'));
    }

    public function test_translations_of_the_lowest_i_d_do_not_take_the_code_from_the_default_one_after_it(): void
    {
        $this->site();
        $this->wpMenu(300, 'Russian', 'russian');
        $this->wpMenu(301, 'English', 'english');
        $this->translate(300, 7001, 'ru');
        $this->translate(301, 7001, 'en');

        $this->assertSame([109 => 'main-menu', 300 => 'english', 301 => 'english'], array_column($this->menus(), 'code', 'id'));
    }

    public function test_the_default_menu_of_a_group_comes_before_the_ones_that_are_not(): void
    {
        $this->site();
        $this->wpMenu(300, 'English', 'english');
        $this->wpMenu(301, 'Russian', 'russian');
        $this->translate(300, 7001, 'en');
        $this->translate(301, 7001, 'ru');

        $this->assertSame([109 => 'main-menu', 300 => 'english', 301 => 'english'], array_column($this->menus(), 'code', 'id'));
    }

    public function test_translated_menus_that_have_the_same_slug_as_another_group_get_a_number_each_group(): void
    {
        $this->site();
        $this->wpMenu(300, 'Menu', 'menu');
        $this->wpMenu(301, 'Menu ru', 'menu');
        $this->wpMenu(302, 'Menu again', 'menu');
        $this->translate(300, 7001, 'en');
        $this->translate(301, 7001, 'ru');

        $this->assertSame([109 => 'main-menu', 300 => 'menu', 301 => 'menu', 302 => 'menu-2'], array_column($this->menus(), 'code', 'id'));
    }

    public function test_polylang_gives_a_menu_the_language_of_its_location_and_groups_the_menus_of_a_location(): void
    {
        $this->site('polylang');
        $this->wpMenu(300, 'Primary', 'primary');
        $this->wpMenu(301, 'Principal', 'principal');
        $this->wpMenu(302, 'Footer', 'footer');
        $this->wpMenu(303, 'Brazil', 'brazil');
        $this->wpMenu(304, 'Unplaced', 'unplaced');
        $this->wpMenu(305, 'Side russian', 'side-russian');
        $this->wpMenu(306, 'Side english', 'side-english');
        $this->option('stylesheet', 'sitetheme');
        $this->polylang(['nav_menus' => ['sitetheme' => [
            'primary' => ['en' => 300, 'ru' => 301],
            'footer' => ['en' => 302],
            'regional' => ['pt_BR' => 303],
            // The default language comes last, not first, and is the one that names the code.
            'side' => ['ru' => 305, 'en' => 306],
        ]]]);

        $menus = $this->menus();

        $this->assertSame([
            109 => null,
            300 => 'en',
            301 => 'ru',
            302 => 'en',
            303 => 'pt-br',
            304 => null,
            305 => 'ru',
            306 => 'en',
        ], array_column($menus, 'language', 'id'));
        $this->assertSame([
            109 => 'main-menu',
            300 => 'primary',
            301 => 'primary',
            302 => 'footer',
            303 => 'brazil',
            304 => 'unplaced',
            305 => 'side-english',
            306 => 'side-english',
        ], array_column($menus, 'code', 'id'));
    }

    public function test_polylang_menus_of_a_location_without_the_default_language_share_the_lowest_code(): void
    {
        $this->site('polylang');
        $this->wpMenu(300, 'Zwei', 'zwei');
        $this->wpMenu(301, 'Eins', 'eins');
        $this->option('stylesheet', 'sitetheme');
        $this->polylang(['nav_menus' => ['sitetheme' => ['side' => ['ru' => 300, 'de' => 301]]]]);

        $this->assertSame([109 => 'main-menu', 300 => 'zwei', 301 => 'zwei'], array_column($this->menus(), 'code', 'id'));
    }

    public function test_a_polylang_location_named_like_a_term_does_not_group_with_that_term(): void
    {
        $this->site('polylang');
        $this->wpMenu(300, 'Placed', 'placed');
        $this->wpMenu(301, 'Alone', 'alone');
        $this->option('stylesheet', 'sitetheme');
        $this->polylang(['nav_menus' => ['sitetheme' => ['term:301' => ['en' => 300]]]]);

        $this->assertSame([109 => 'main-menu', 300 => 'placed', 301 => 'alone'], array_column($this->menus(), 'code', 'id'));
    }

    public function test_polylang_menus_of_another_theme_have_no_language(): void
    {
        $this->site('polylang');
        $this->wpMenu(300, 'Primary', 'primary');
        $this->wpMenu(301, 'Principal', 'principal');
        $this->option('stylesheet', 'sitetheme');
        $this->polylang(['nav_menus' => ['othertheme' => ['primary' => ['en' => 300, 'ru' => 301]]]]);

        $menus = $this->menus();

        $this->assertSame([109 => null, 300 => null, 301 => null], array_column($menus, 'language', 'id'));
        $this->assertSame([109 => 'main-menu', 300 => 'primary', 301 => 'principal'], array_column($menus, 'code', 'id'));
    }

    public function test_the_theme_polylang_reads_is_the_child_theme_or_else_its_parent(): void
    {
        $this->site('polylang');
        $this->wpMenu(300, 'Primary', 'primary');
        $this->polylang(['nav_menus' => ['parenttheme' => ['primary' => ['ru' => 300]]]]);

        // No theme at all: the menus of a theme that is not named are nobody's.
        $this->assertSame(null, $this->menu($this->menus(), 300)['language']);

        $this->option('template', 'parenttheme');
        $this->assertSame('ru', $this->menu($this->menus(), 300)['language']);

        // An empty child theme is no child theme.
        $this->option('stylesheet', '');
        $this->assertSame('ru', $this->menu($this->menus(), 300)['language']);

        // The child theme wins when there is one.
        $this->option('stylesheet', 'childtheme');
        $this->assertSame(null, $this->menu($this->menus(), 300)['language']);
    }

    // The theme locations.

    public function test_a_theme_location_is_told_with_the_code_that_replaces_it(): void
    {
        $this->site();
        $this->option('stylesheet', 'sitetheme');
        $this->option('theme_mods_sitetheme', serialize(['custom_logo' => 12, 'nav_menu_locations' => [
            'primary' => Wp::TERM_MAIN_MENU,
            // The ID of a term can be kept as text.
            'footer' => (string) Wp::TERM_MAIN_MENU,
            'ghost' => 999,
            // A location without a menu is kept as 0.
            'unused' => 0,
        ]]));

        $this->menus();

        $this->assertSame([
            $this->located('primary', 'Main menu', 'main-menu'),
            $this->located('footer', 'Main menu', 'main-menu'),
        ], $this->notes());
    }

    public function test_the_locations_of_translated_menus_are_told_with_the_code_they_share(): void
    {
        $this->site();
        $this->wpMenu(300, 'Principal', 'principal');
        $this->wpMenu(301, 'Main', 'main');
        $this->translate(300, 7001, 'ru');
        $this->translate(301, 7001, 'en');
        $this->option('stylesheet', 'sitetheme');
        $this->option('theme_mods_sitetheme', serialize(['nav_menu_locations' => ['primary' => 301, 'footer' => 300]]));

        $this->menus();

        $this->assertSame([
            $this->located('footer', 'Principal', 'main'),
            $this->located('primary', 'Main', 'main'),
        ], $this->notes());
    }

    public function test_the_locations_of_polylang_are_told_once_though_the_theme_names_them_too(): void
    {
        $this->site('polylang');
        $this->wpMenu(300, 'Primary', 'primary');
        $this->wpMenu(301, 'Principal', 'principal');
        $this->option('stylesheet', 'sitetheme');
        $this->option('theme_mods_sitetheme', serialize(['nav_menu_locations' => ['primary' => 300]]));
        $this->polylang(['nav_menus' => ['sitetheme' => ['primary' => ['en' => 300, 'ru' => 301]]]]);

        $this->menus();

        $this->assertSame([
            $this->located('primary', 'Primary', 'primary'),
            $this->located('primary', 'Principal', 'primary'),
        ], $this->notes());
    }

    public function test_a_menu_in_two_locations_of_polylang_is_told_in_both(): void
    {
        $this->site('polylang');
        $this->wpMenu(300, 'Shared', 'shared');
        $this->option('stylesheet', 'sitetheme');
        $this->polylang(['nav_menus' => ['sitetheme' => ['header' => ['en' => 300], 'footer' => ['en' => 300]]]]);

        $this->menus();

        $this->assertSame([
            $this->located('header', 'Shared', 'shared'),
            $this->located('footer', 'Shared', 'shared'),
        ], $this->notes());
    }

    public function test_the_locations_of_the_child_theme_win_over_the_ones_of_its_parent(): void
    {
        $this->site();
        $this->option('stylesheet', 'childtheme');
        $this->option('template', 'parenttheme');
        $this->option('theme_mods_childtheme', serialize(['nav_menu_locations' => ['primary' => Wp::TERM_MAIN_MENU]]));
        $this->option('theme_mods_parenttheme', serialize(['nav_menu_locations' => ['secondary' => Wp::TERM_MAIN_MENU]]));

        $this->menus();

        $this->assertSame([$this->located('primary', 'Main menu', 'main-menu')], $this->notes());
    }

    public function test_the_locations_of_the_parent_theme_are_used_when_there_is_no_child_theme(): void
    {
        $this->site();
        $this->option('template', 'parenttheme');
        $this->option('theme_mods_parenttheme', serialize(['nav_menu_locations' => ['secondary' => Wp::TERM_MAIN_MENU]]));
        $this->option('theme_mods_', serialize(['nav_menu_locations' => ['nameless' => Wp::TERM_MAIN_MENU]]));

        $this->menus();

        $this->assertSame([$this->located('secondary', 'Main menu', 'main-menu')], $this->notes());

        // An empty child theme is no child theme.
        $this->option('stylesheet', '');
        $this->menus();
        $this->assertSame([$this->located('secondary', 'Main menu', 'main-menu')], $this->notes());
    }

    public function test_a_site_without_theme_mods_has_no_locations(): void
    {
        $this->site();
        $this->option('stylesheet', 'sitetheme');
        $this->wpMenuItem(3001, Wp::TERM_MAIN_MENU + 100, 'Home', ['type' => 'custom', 'url' => '/']);

        $this->assertSame([3001], array_column($this->menu($this->menus(), 109)['items'], 'id'));
        $this->assertSame([], $this->notes());
    }

    public function test_options_that_are_not_what_they_should_be_are_read_as_nothing(): void
    {
        $this->site('polylang');
        $this->wpMenu(300, 'Primary', 'primary');
        $this->option('stylesheet', 'sitetheme');

        foreach ([
            // [theme_mods, polylang nav_menus]
            ['garbage', 'garbage'],
            [serialize(['nav_menu_locations' => 'garbage']), 'garbage'],
            [serialize(['nav_menu_locations' => 5]), ['sitetheme' => 'garbage']],
            [serialize(['nav_menu_locations' => []]), ['sitetheme' => ['primary' => 'garbage']]],
            [serialize(['nav_menu_locations' => []]), ['sitetheme' => ['primary' => 7]]],
        ] as [$mods, $menus]) {
            $this->option('theme_mods_sitetheme', $mods);
            $this->polylang(['nav_menus' => $menus]);

            $menu = $this->menu($this->menus(), 300);

            $this->assertNull($menu['language']);
            $this->assertSame([], $this->notes());
        }
    }

    public function test_a_broken_part_of_the_options_does_not_hide_the_rest(): void
    {
        $this->site('polylang');
        $this->wpMenu(300, 'Primary', 'primary');
        $this->wpMenu(301, 'Footer', 'footer');
        $this->option('stylesheet', 'sitetheme');
        $this->option('theme_mods_sitetheme', serialize(['nav_menu_locations' => ['primary' => 300]]));
        $this->polylang(['nav_menus' => ['sitetheme' => ['broken' => 'garbage', 'footer' => ['en' => 301]]]]);

        $menus = $this->menus();

        $this->assertSame([109 => null, 300 => null, 301 => 'en'], array_column($menus, 'language', 'id'));
        $this->assertSame([
            $this->located('primary', 'Primary', 'primary'),
            $this->located('footer', 'Footer', 'footer'),
        ], $this->notes());
    }

    public function test_the_locations_of_a_term_are_found_by_the_id_of_the_term_not_of_its_taxonomy(): void
    {
        $this->site();
        $this->option('stylesheet', 'sitetheme');
        // The menu of the fixture is the term 9 of the taxonomy 109: the theme names the term.
        $this->option('theme_mods_sitetheme', serialize(['nav_menu_locations' => ['primary' => Wp::TERM_MAIN_MENU, 'wrong' => Wp::TERM_MAIN_MENU + 100]]));

        $this->menus();

        $this->assertSame([$this->located('primary', 'Main menu', 'main-menu')], $this->notes());
    }

    // What the records give the menus.

    public function test_a_post_record_names_its_type_and_the_main_post_of_its_group(): void
    {
        $this->site();
        $this->wpPost(self::ONLY_RUSSIAN, 'post', 'Только по-русски');
        $this->translatePost(self::ONLY_RUSSIAN, 7001, 'ru');
        $records = $this->records();

        foreach ([
            [Wp::HELLO, 'post', Wp::HELLO],
            [Wp::HELLO_RU, 'post', Wp::HELLO],
            [Wp::ABOUT_RU, 'page', Wp::ABOUT],
            [Wp::TEAM, 'page', Wp::TEAM],
            [Wp::BORSCHT_RU, 'recipe', Wp::BORSCHT],
            [Wp::PIZZA, 'recipe', Wp::PIZZA],
            [Wp::DRAFT_IDEA, 'post', Wp::DRAFT_IDEA],
            [self::ONLY_RUSSIAN, 'post', self::ONLY_RUSSIAN],
        ] as [$id, $type, $main]) {
            $record = $records->postRecord($id);

            $this->assertNotNull($record, "post {$id}");
            $this->assertSame(['type', 'main'], array_keys($record));
            $this->assertSame($records->plan->type($type), $record['type'], "post {$id}");
            $this->assertSame($main, $record['main'], "post {$id}");
        }
    }

    /**
     * @return array<string,array{0:int}>
     */
    public static function postsThatDoNotMove(): array
    {
        return [
            'one that is gone' => [Wp::NO_SUCH_POST],
            'one in the trash' => [Wp::TRASHED],
            'an auto-draft' => [Wp::AUTO_DRAFT],
            'a revision, which is not a type of the plan' => [Wp::REVISION],
            'a file, which is not a type of the plan' => [Wp::PHOTO],
            'a menu item, which is not a type of the plan' => [3001],
            'one of a type the config skips' => [self::EVENT],
        ];
    }

    #[DataProvider('postsThatDoNotMove')]
    public function test_a_post_that_does_not_move_has_no_record(int $id): void
    {
        config()->set('atrium-wordpress.post_types', ['event' => 'skip']);
        $this->site();
        $this->wpPost(self::EVENT, 'event', 'Concert');
        $this->wpMenu(300, 'Primary navigation');
        $this->wpMenuItem(3001, 300, 'Home', ['type' => 'custom', 'url' => '/']);
        $records = $this->records();

        $this->assertInstanceOf(Type::class, $records->plan->type('event'));
        $this->assertNull($records->postRecord($id));
    }

    public function test_a_term_is_found_by_its_own_id_and_taxonomy(): void
    {
        $this->site();
        $records = $this->records();

        $this->assertSame(101, $records->termRecordByTerm(Wp::TERM_UNCATEGORIZED, 'category'));
        $this->assertSame(102, $records->termRecordByTerm(Wp::TERM_NEWS, 'category'));
        // A translation is its group's record.
        $this->assertSame(102, $records->termRecordByTerm(Wp::TERM_NOVOSTI, 'category'));
        $this->assertSame(103, $records->termRecordByTerm(Wp::TERM_LOCAL, 'category'));
        $this->assertSame(105, $records->termRecordByTerm(Wp::TERM_LARAVEL, 'post_tag'));
        $this->assertSame(106, $records->termRecordByTerm(Wp::TERM_PHP, 'post_tag'));
        $this->assertSame(107, $records->termRecordByTerm(Wp::TERM_ITALIAN, 'cuisine'));
        // Asked again: the same.
        $this->assertSame(102, $records->termRecordByTerm(Wp::TERM_NEWS, 'category'));
    }

    /**
     * @return array<string,array{0:int,1:string}>
     */
    public static function termsThatDoNotMove(): array
    {
        return [
            'a term that is not there' => [9999, 'category'],
            'the ID of a term of another taxonomy' => [Wp::TERM_NEWS, 'post_tag'],
            'the ID of another taxonomy, the other way round' => [Wp::TERM_LARAVEL, 'category'],
            'a taxonomy that is not there' => [Wp::TERM_NEWS, 'gizmo'],
            'a menu, whose taxonomy is bookkeeping' => [Wp::TERM_MAIN_MENU, 'nav_menu'],
            'an empty taxonomy' => [Wp::TERM_NEWS, ''],
        ];
    }

    #[DataProvider('termsThatDoNotMove')]
    public function test_a_term_that_did_not_move_has_no_record(int $term, string $taxonomy): void
    {
        $this->site();

        $this->assertNull($this->records()->termRecordByTerm($term, $taxonomy));
    }

    public function test_the_notes_keep_the_last_word_of_a_key(): void
    {
        $this->site();
        $records = $this->records();

        $records->note('first', 'one');
        $records->note('second', 'two');
        $records->note('first', 'three');

        $this->assertSame(['three', 'two'], $records->notes());
    }
}
