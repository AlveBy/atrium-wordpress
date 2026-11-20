<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Import;

use Atrium\Menus\Models\Menu;
use Atrium\WordPress\Site\Languages;
use Atrium\WordPress\Support\Serialized;
use Illuminate\Support\Facades\Schema;

/**
 * The menus of a WordPress site as Atrium's menus module keeps them: a menu
 * per language with a code, and its items as a tree. WordPress keeps a menu
 * as a term of the nav_menu taxonomy and each item as a post of its own
 * (nav_menu_item) with the link in its meta. Nothing here writes: the
 * Importer does, when the menus module is there.
 */
final class Navigation
{
    /** Items in the trash or an auto-draft are not part of any menu. */
    private const STATUSES = ['publish', 'draft', 'pending', 'private'];

    /** @var array<int,array{id:int,name:string,code:string,language:?string,items:array<int,array<string,mixed>>}>|null */
    private ?array $menus = null;

    public function __construct(private readonly Records $records) {}

    /**
     * Whether the menus module is there to move the menus into: its class,
     * which a site without it lacks, and its tables, which a site that has
     * not run its migration lacks.
     */
    public static function installed(): bool
    {
        return class_exists(Menu::class) && Schema::hasTable('menus') && Schema::hasTable('menu_items');
    }

    /**
     * @return array<int,array{id:int,name:string,code:string,language:?string,items:array<int,array<string,mixed>>}>
     */
    public function menus(): array
    {
        if ($this->menus !== null) {
            return $this->menus;
        }

        $source = $this->records->plan->source;
        $terms = [];

        foreach ($source->terms() as $term) {
            if ($term->taxonomy === 'nav_menu') {
                $terms[(int) $term->term_taxonomy_id] = $term;
            }
        }

        if ($terms === []) {
            return $this->menus = [];
        }

        $placed = $this->placements();
        $items = $this->itemsByMenu($terms, $placed);
        $groups = [];

        foreach ($terms as $id => $term) {
            $groups[$this->group($term, $placed)][$id] = $term;
        }

        $menus = [];
        $used = [];

        foreach ($groups as $members) {
            $default = $this->records->plan->languages->default();
            $main = null;

            foreach ($members as $term) {
                if ($this->language($term, $placed) === $default) {
                    $main = $term;
                }
            }

            $main ??= $members[min(array_keys($members))];
            $code = $this->code($main, $used);

            foreach ($members as $id => $term) {
                $menus[$id] = [
                    'id' => $id,
                    'name' => $this->records->content->text($term->name) ?: $code,
                    'code' => $code,
                    'language' => $this->language($term, $placed),
                    'items' => $items[$id] ?? [],
                ];

                $this->locations($term, $code, $placed);
            }
        }

        ksort($menus);

        return $this->menus = array_values($menus);
    }

    /**
     * The items of every menu as trees: menu term_taxonomy_id => items.
     *
     * @param  array<int,object>  $menus
     * @param  array{locations:array<int,array<int,string>>,languages:array<int,string>,groups:array<int,string>}  $placed
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function itemsByMenu(array $menus, array $placed): array
    {
        $source = $this->records->plan->source;
        $menuOf = array_filter($source->objectsInTaxonomy('nav_menu'), static fn (int $menu): bool => isset($menus[$menu]));
        $posts = array_filter(
            $source->posts(array_keys($menuOf)),
            static fn (object $post): bool => $post->post_type === 'nav_menu_item' && in_array($post->post_status, self::STATUSES, true),
        );
        $meta = $source->meta(array_keys($posts));
        $flat = [];

        foreach ($posts as $id => $post) {
            $flat[$menuOf[$id]][$id] = [
                'post' => $post,
                'meta' => $meta[$id] ?? [],
                'parent' => (int) ($meta[$id]['_menu_item_menu_item_parent'] ?? 0),
            ];
        }

        $trees = [];

        foreach ($flat as $menu => $items) {
            $trees[$menu] = $this->tree($items, $this->language($menus[$menu], $placed));
        }

        return $trees;
    }

    /**
     * @param  array<int,array{post:object,meta:array<string,string>,parent:int}>  $items
     * @return array<int,array<string,mixed>>
     */
    private function tree(array $items, ?string $language): array
    {
        $children = [];

        foreach ($items as $id => $item) {
            $parent = $item['parent'];

            if ($parent !== 0 && (! isset($items[$parent]) || $this->loops($items, $id))) {
                $this->records->note("menu-item-parent:{$id}", "menu item {$id} has a parent that is not in its menu, or a loop of parents; it moved to the top level");
                $parent = 0;
            }

            $children[$parent][] = $id;
        }

        return $this->branch($items, $children, 0, $language);
    }

    /**
     * Whether the parents of an item lead back to it.
     *
     * @param  array<int,array{post:object,meta:array<string,string>,parent:int}>  $items
     */
    private function loops(array $items, int $id): bool
    {
        $seen = [];

        for ($parent = $items[$id]['parent']; $parent !== 0 && isset($items[$parent]); $parent = $items[$parent]['parent']) {
            if ($parent === $id) {
                return true;
            }

            // A loop of other items, which this one only hangs from.
            if (isset($seen[$parent])) {
                return false;
            }

            $seen[$parent] = true;
        }

        return false;
    }

    /**
     * @param  array<int,array{post:object,meta:array<string,string>,parent:int}>  $items
     * @param  array<int,array<int,int>>  $children
     * @return array<int,array<string,mixed>>
     */
    private function branch(array $items, array $children, int $parent, ?string $language): array
    {
        $ids = $children[$parent] ?? [];
        usort($ids, static fn (int $a, int $b): int => [(int) $items[$a]['post']->menu_order, $a] <=> [(int) $items[$b]['post']->menu_order, $b]);

        return array_map(fn (int $id): array => [
            'id' => $id,
            'title' => $this->title($id, $items[$id]),
            'link' => $this->link($id, $items[$id]['meta'], $language),
            'target' => ($items[$id]['meta']['_menu_item_target'] ?? '') === '_blank' ? '_blank' : null,
            'active' => $items[$id]['post']->post_status === 'publish',
            'children' => $this->branch($items, $children, $id, $language),
        ], $ids);
    }

    /**
     * The item's own title; WordPress shows the linked post's or term's
     * title for an item without one.
     *
     * @param  array{post:object,meta:array<string,string>,parent:int}  $item
     */
    private function title(int $id, array $item): string
    {
        $title = $this->records->content->text($item['post']->post_title);

        if ($title !== '') {
            return $title;
        }

        $object = (int) ($item['meta']['_menu_item_object_id'] ?? 0);

        if (($item['meta']['_menu_item_type'] ?? '') === 'post_type') {
            $title = $this->records->content->text($this->records->plan->source->posts([$object])[$object]->post_title ?? '');
        } elseif (($item['meta']['_menu_item_type'] ?? '') === 'taxonomy') {
            foreach ($this->records->plan->source->terms() as $term) {
                if ((int) $term->term_id === $object && $term->taxonomy === ($item['meta']['_menu_item_object'] ?? '')) {
                    $title = $this->records->content->text($term->name);
                }
            }
        }

        return $title !== '' ? $title : 'Untitled '.$id;
    }

    /**
     * Where an item goes: an address, a record that moved, or nowhere (a
     * heading for the items below it, as WordPress's "#" is).
     *
     * @param  array<string,string>  $meta
     * @return array<string,mixed>|null
     */
    private function link(int $id, array $meta, ?string $language): ?array
    {
        $type = $meta['_menu_item_type'] ?? '';
        $object = $meta['_menu_item_object'] ?? '';
        $target = (int) ($meta['_menu_item_object_id'] ?? 0);

        if ($type === 'custom') {
            $url = trim($meta['_menu_item_url'] ?? '');

            return $url === '' || $url === '#' ? null : ['kind' => 'url', 'url' => $this->records->content->uploadUrl($url)];
        }

        if ($type === 'post_type' && ($record = $this->records->postRecord($target)) !== null) {
            return ['kind' => 'post', 'id' => $record['main']];
        }

        if ($type === 'taxonomy' && ($record = $this->records->termRecordByTerm($target, $object)) !== null) {
            return ['kind' => 'term', 'id' => $record, 'taxonomy' => $object];
        }

        if ($type === 'post_type_archive' && ($archive = $this->records->plan->type($object)) !== null && $archive->moves()) {
            return ['kind' => 'url', 'url' => $this->records->path($archive->prefix, '', $language ?? $this->records->plan->languages->default())];
        }

        $this->records->note("menu-item:{$id}", "menu item {$id} links to {$type} {$object} {$target}, which does not move; it became a heading");

        return null;
    }

    /**
     * Theme location => the menu's term_id, and the language of a menu that
     * Polylang gave its own location.
     *
     * @return array{locations:array<int,array<int,string>>,languages:array<int,string>,groups:array<int,string>}
     */
    private function placements(): array
    {
        $source = $this->records->plan->source;
        $theme = (string) ($source->option('stylesheet') ?: $source->option('template'));
        $mods = Serialized::toArray($source->option('theme_mods_'.$theme));
        $placed = ['locations' => [], 'languages' => [], 'groups' => []];

        foreach ($this->array($mods['nav_menu_locations'] ?? null) as $location => $term) {
            $placed['locations'][(int) $term][] = (string) $location;
        }

        $polylang = Serialized::toArray($source->option('polylang'));

        foreach ($this->array($this->array($polylang['nav_menus'] ?? null)[$theme] ?? null) as $location => $byLanguage) {
            foreach ($this->array($byLanguage) as $language => $term) {
                $placed['locations'][(int) $term][] = (string) $location;
                $placed['languages'][(int) $term] = Languages::code((string) $language);
                $placed['groups'][(int) $term] = 'location:'.$location;
            }
        }

        return $placed;
    }

    /**
     * What a value read from an option is, as an array: the values inside
     * an unserialized option are arrays already.
     *
     * @return array<array-key,mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param  array{locations:array<int,array<int,string>>,languages:array<int,string>,groups:array<int,string>}  $placed
     */
    private function language(object $term, array $placed): ?string
    {
        $languages = $this->records->plan->languages;

        return match ($languages->plugin()) {
            'wpml' => $languages->ofTerm($term),
            'polylang' => $placed['languages'][(int) $term->term_id] ?? null,
            default => null,
        };
    }

    /**
     * Menus that are translations of each other share a code, so that the
     * theme asks for one and gets the language it shows.
     *
     * @param  array{locations:array<int,array<int,string>>,languages:array<int,string>,groups:array<int,string>}  $placed
     */
    private function group(object $term, array $placed): string
    {
        $languages = $this->records->plan->languages;

        return match ($languages->plugin()) {
            'wpml' => $languages->termGroup($term),
            'polylang' => $placed['groups'][(int) $term->term_id] ?? 'term:'.$term->term_taxonomy_id,
            default => 'term:'.$term->term_taxonomy_id,
        };
    }

    /**
     * @param  array<string,true>  $used
     */
    private function code(object $term, array &$used): string
    {
        $base = substr(Content::slug((string) $term->slug, (string) $term->name, 'menu-'.$term->term_taxonomy_id), 0, 60);
        $code = $base;

        for ($number = 2; isset($used[$code]); $number++) {
            $code = $base.'-'.$number;
        }

        $used[$code] = true;

        return $code;
    }

    /**
     * Atrium has no theme locations: a theme asks for a menu by its code.
     *
     * @param  array{locations:array<int,array<int,string>>,languages:array<int,string>,groups:array<int,string>}  $placed
     */
    private function locations(object $term, string $code, array $placed): void
    {
        foreach ($placed['locations'][(int) $term->term_id] ?? [] as $location) {
            $this->records->note("menu-location:{$location}:{$term->term_taxonomy_id}", "the theme location \"{$location}\" showed the menu \"{$term->name}\"; Atrium has no locations, the theme draws it by its code: <x-atrium-menu code=\"{$code}\" />");
        }
    }
}
