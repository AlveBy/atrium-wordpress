<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Import;

use Atrium\Admin\Models\Language;
use Atrium\Admin\Models\MediaCollection;
use Atrium\Admin\Models\MediaItem;
use Atrium\Admin\Support\RecordUrls;
use Atrium\Admin\Support\Sites;
use Atrium\Menus\Menus;
use Atrium\Menus\Models\Menu;
use Atrium\Menus\Models\MenuItem;
use Atrium\WordPress\Plan\Taxonomy;
use Atrium\WordPress\Plan\Type;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes what Records says into Atrium: languages, authors, terms, files,
 * then the records of every post type with their links, SEO and old
 * addresses, then the menus when the menus module is there. Every chunk is one transaction, retried on a deadlock; files
 * are copied before, never inside one. Running it again updates what it
 * wrote before instead of adding copies.
 */
final class Importer
{
    /** Extensions the media library shows as pictures. */
    private const IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'bmp', 'ico'];

    /** @var array<string,int> what was written, by kind */
    private array $counts = [];

    /** @var array<string,array<int,int>> map kind:type => wp_id => target id, filled as records are written */
    private array $targets = [];

    /** @var array<int,string> */
    private array $missingFiles = [];

    private ?int $site = null;

    /**
     * @param  \Closure(string):void|null  $progress
     */
    public function __construct(private readonly Records $records, private readonly ?\Closure $progress = null) {}

    /**
     * Moves everything; with $onlyChanged, only posts modified in WordPress
     * since the last run and the ones never moved.
     *
     * @return array{counts:array<string,int>,missing_files:array<int,string>,notes:array<int,string>}
     */
    public function run(bool $onlyChanged = false): array
    {
        $plan = $this->records->plan;
        $this->counts = [];
        $this->missingFiles = [];
        $this->site = $this->site();

        $this->languages();
        $this->authors();

        foreach ($plan->taxonomies as $taxonomy) {
            $this->terms($taxonomy);
        }

        $this->files();

        foreach ($plan->moving() as $type) {
            $this->type($type, $onlyChanged);
        }

        $this->menus();

        return ['counts' => $this->counts, 'missing_files' => $this->missingFiles, 'notes' => $this->records->notes()];
    }

    /**
     * Deletes the records whose posts are no longer in WordPress (deleted,
     * trashed, turned into another type), with their links and SEO.
     */
    public function prune(): int
    {
        $plan = $this->records->plan;
        $keep = [];

        foreach ($plan->moving() as $type) {
            foreach ($this->records->records($type) as $records) {
                $keep += array_fill_keys(array_keys($records), true);
            }
        }

        $gone = DB::table('wordpress_map')->where('kind', 'post')->get()->filter(static fn (object $row): bool => ! isset($keep[$row->wp_id]));

        foreach ($gone->chunk($this->chunkSize()) as $chunk) {
            DB::transaction(function () use ($chunk, $plan): void {
                foreach ($chunk as $row) {
                    $model = $row->target_type;

                    if (class_exists($model)) {
                        // Any pivot of the model goes, not only those of the taxonomies its other posts still use.
                        $table = (new $model)->getTable();

                        foreach ([...array_column($plan->taxonomies, 'table'), $plan->authorsTable] as $related) {
                            if (Schema::hasTable($table.'_'.$related)) {
                                DB::table($table.'_'.$related)->where('record_id', $row->target_id)->delete();
                            }
                        }

                        // Not every model deletes the SEO it was given; the model moves its children up.
                        DB::table('seo_meta')->where(['seoable_type' => $model, 'seoable_id' => $row->target_id])->delete();
                        $model::query()->find($row->target_id)?->delete();
                    }

                    DB::table('wordpress_map')->where('id', $row->id)->delete();
                }
            }, attempts: 3);
        }

        return $gone->count() + $this->pruneMenus();
    }

    /**
     * Deletes the menus that are no longer in WordPress, with their items.
     */
    private function pruneMenus(): int
    {
        if (! Navigation::installed()) {
            return 0;
        }

        $keep = array_column((new Navigation($this->records))->menus(), 'id');
        $gone = DB::table('wordpress_map')->where('kind', 'menu')->whereNotIn('wp_id', $keep)->get();

        DB::transaction(function () use ($gone): void {
            foreach ($gone as $row) {
                Menu::query()->withoutGlobalScopes()->find($row->target_id)?->delete();
                DB::table('wordpress_map')->where('id', $row->id)->delete();
            }
        }, attempts: 3);

        return $gone->count();
    }

    private function chunkSize(): int
    {
        return max(1, (int) config('atrium-wordpress.chunk_size', 200));
    }

    private function site(): ?int
    {
        $code = config('atrium-wordpress.site');
        $sites = app(Sites::class);

        if ($code === null || $code === '') {
            return $sites->default()->id;
        }

        return $sites->findByCode((string) $code)->id ?? throw new RuntimeException("There is no Atrium site with the code \"{$code}\" (atrium-wordpress.site).");
    }

    /**
     * Every language of the old site is a content language of Atrium, and
     * its default language is Atrium's: the texts are filed under it.
     */
    private function languages(): void
    {
        $languages = $this->records->plan->languages;

        DB::transaction(function () use ($languages): void {
            $sort = 100;

            foreach ($languages->names() as $code => $name) {
                $language = Language::query()->firstOrNew(['code' => $code]);
                $language->name = $language->exists ? $language->name : $name;
                $language->active = true;
                $language->sort = $language->exists ? $language->sort : $sort;
                // The default comes first: saving it takes the mark off the old one.
                $language->is_default = $code === $languages->default();
                $language->save();
                $sort += 100;
            }
        }, attempts: 3);

        $this->counts['languages'] = count($languages->names());
    }

    private function authors(): void
    {
        $plan = $this->records->plan;
        $model = $plan->authorsModel;

        DB::transaction(function () use ($model): void {
            foreach ($this->records->authors() as $id => $record) {
                $author = $this->find($model, 'author', $id) ?? new $model;
                $this->fill($author, ['wordpress_id' => $id, ...$record['values']]);
                $this->remember('author', 'author', $id, $author, [], null);
            }
        }, attempts: 3);

        $this->counts['authors'] = count($this->targets['author'] ?? []);
    }

    private function terms(Taxonomy $taxonomy): void
    {
        $records = $this->records->terms($taxonomy);

        foreach (array_chunk($records, $this->chunkSize(), true) as $chunk) {
            DB::transaction(function () use ($chunk, $taxonomy): void {
                foreach ($chunk as $id => $record) {
                    $term = $this->find($taxonomy->model, 'term', $id) ?? new $taxonomy->model;
                    $this->fill($term, ['wordpress_id' => $id, 'site_id' => $this->site, ...$record['values']]);
                    $this->seo($term, $record['seo']);
                    $this->remember('term', $taxonomy->taxonomy, $id, $term, $this->pairs($record), null);
                }
            }, attempts: 3);
        }

        if ($taxonomy->hierarchical) {
            DB::transaction(function () use ($records, $taxonomy): void {
                foreach ($records as $id => $record) {
                    $parent = $record['parent'] === null ? null : ($this->targets['term'][$record['parent']] ?? null);
                    $taxonomy->model::query()->whereKey($this->targets['term'][$id])->update(['parent_id' => $parent]);
                }
            }, attempts: 3);
        }

        $this->counts['terms:'.$taxonomy->taxonomy] = count($records);
    }

    /**
     * Copies the files of the attachments and their sizes, then lists them
     * in the media library under "WordPress".
     */
    private function files(): void
    {
        $attachments = $this->records->attachments();
        $present = [];

        foreach ($attachments as $id => $attachment) {
            foreach ([$attachment['file'], ...$attachment['sizes']] as $file) {
                if ($this->copy($file) && $file === $attachment['file']) {
                    $present[$id] = $attachment;
                }
            }
        }

        foreach (array_chunk($present, 200, true) as $chunk) {
            DB::transaction(function () use ($chunk): void {
                $collection = MediaCollection::query()->firstOrCreate(['name' => 'WordPress', 'parent_id' => null]);

                foreach ($chunk as $id => $attachment) {
                    $extension = strtolower(pathinfo($attachment['file'], PATHINFO_EXTENSION));
                    $item = MediaItem::query()->updateOrCreate(['url' => $attachment['url']], [
                        'collection_id' => $collection->id,
                        'name' => mb_substr($attachment['title'], 0, 255),
                        'description' => $attachment['alt'] ?: ($attachment['description'] ?: null),
                        'disk' => null,
                        'path' => null,
                        'extension' => mb_substr($extension, 0, 16) ?: null,
                        'mime_type' => $attachment['mime'] ?: null,
                        'size' => @filesize($this->target($attachment['file'])) ?: null,
                        'is_image' => in_array($extension, self::IMAGES, true),
                    ]);
                    $this->remember('attachment', 'attachment', $id, $item, [], null);
                }
            }, attempts: 3);
        }

        $this->counts['files'] = count($present);
    }

    private function type(Type $type, bool $onlyChanged): void
    {
        $written = 0;
        $all = [];

        foreach ($this->records->records($type) as $records) {
            // Files the texts link to beyond the attachments: sizes, files uploaded by hand.
            foreach ($records as $record) {
                foreach ($record['files'] as $file) {
                    $this->copy($file);
                }
            }

            $changed = $onlyChanged ? $this->changed($type, $records) : $records;

            DB::transaction(function () use ($type, $changed): void {
                foreach ($changed as $id => $record) {
                    $this->write($type, $id, $record);
                }
            }, attempts: 3);

            $written += count($changed);

            foreach ($records as $id => $record) {
                $all[$id] = $record['parent'];
            }

            $this->say("{$type->type}: {$written}");
        }

        if ($type->target === Type::PAGES || $type->hierarchical) {
            foreach (array_chunk($all, 500, true) as $chunk) {
                DB::transaction(function () use ($chunk, $type): void {
                    foreach ($chunk as $id => $parent) {
                        $target = $this->targetOf('post', $id);
                        $parentTarget = $parent === null ? null : $this->targetOf('post', $parent);

                        if ($target !== null) {
                            $type->model::query()->whereKey($target)->update(['parent_id' => $parentTarget]);
                        }
                    }
                }, attempts: 3);
            }
        }

        $this->counts['posts:'.$type->type] = $written;
    }

    /**
     * The menus, and their items as trees, into the menus module. Without
     * it they are left behind with a note, not a failure. A menu moved before
     * gets its items written again: WordPress is still the master until the
     * switch.
     */
    private function menus(): void
    {
        $menus = (new Navigation($this->records))->menus();

        if ($menus === []) {
            return;
        }

        if (! Navigation::installed()) {
            $this->records->note('menus', count($menus).' menus were not moved: install the menus module (alveby/atrium-menus), run its migration and move again');

            return;
        }

        $items = 0;

        foreach ($menus as $menu) {
            DB::transaction(function () use ($menu, &$items): void {
                $items += $this->writeMenu($menu);
            }, attempts: 3);
        }

        // Pages draw the menus from a cache that only a save of a menu empties.
        app(Menus::class)->flush();

        $this->counts['menus'] = count($menus);
        $this->counts['menu items'] = $items;
    }

    /**
     * @param  array{id:int,name:string,code:string,language:?string,items:array<int,array<string,mixed>>}  $menu
     */
    private function writeMenu(array $menu): int
    {
        $target = $this->targetOf('menu', $menu['id']);
        $model = ($target === null ? null : Menu::query()->withoutGlobalScopes()->find($target)) ?? new Menu;
        $code = $this->menuCode($menu['code'], $menu['language'], $model);

        if ($code !== $menu['code']) {
            $this->records->note("menu-code:{$menu['id']}", "the menu \"{$menu['name']}\" is called {$code}: a menu with the code {$menu['code']} is already there");
        }

        $this->fill($model, ['site_id' => $this->site, 'language' => $menu['language'], 'code' => $code, 'name' => mb_substr($menu['name'], 0, 255), 'active' => true]);
        MenuItem::query()->where('menu_id', $model->getKey())->delete();
        $this->remember('menu', 'nav_menu', $menu['id'], $model, [], null);

        $sorts = [];

        return $this->writeItems((int) $model->getKey(), $menu['items'], null, 1, $menu, $sorts);
    }

    /**
     * A menu's code is unique among the menus of its language, and of every
     * language when it has none.
     */
    private function menuCode(string $base, ?string $language, Model $own): string
    {
        $code = $base;

        for ($number = 2; $this->codeTaken($code, $language, $own); $number++) {
            $code = $base.'-'.$number;
        }

        return $code;
    }

    private function codeTaken(string $code, ?string $language, Model $own): bool
    {
        return Menu::query()->withoutGlobalScopes()->where('site_id', $this->site)->where('code', $code)
            ->when($language === null, static fn ($query) => $query->whereNull('language'), static fn ($query) => $query->where('language', $language))
            ->when($own->exists, static fn ($query) => $query->whereKeyNot($own->getKey()))
            ->exists();
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array{id:int,name:string,code:string,language:?string,items:array<int,array<string,mixed>>}  $menu
     * @param  array<int,int>  $sorts  the last sort of each parent's items
     */
    private function writeItems(int $menuId, array $items, ?int $parent, int $depth, array $menu, array &$sorts): int
    {
        $written = 0;

        foreach ($items as $item) {
            $link = $this->menuLink($item['link'], $menu['language']);

            if ($item['link'] !== null && $link === null) {
                $this->records->note("menu-item-record:{$item['id']}", "menu item {$item['id']} links to a record that was not moved; it became a heading");
            }

            $row = MenuItem::query()->create([
                'menu_id' => $menuId,
                'parent_id' => $parent,
                'sort' => $sorts[$parent ?? 0] = ($sorts[$parent ?? 0] ?? 0) + 10,
                'title' => mb_substr($item['title'], 0, 255),
                'url' => $link['url'] ?? null,
                'linkable_type' => $link['type'] ?? null,
                'linkable_id' => $link['id'] ?? null,
                'target' => $item['target'],
                'active' => $item['active'],
            ]);

            // The module draws this deep and no deeper: what is below sits at the last level.
            $below = $depth >= Menu::MAX_DEPTH ? $parent : $row->id;

            if ($item['children'] !== [] && $depth >= Menu::MAX_DEPTH) {
                $this->records->note("menu-depth:{$menu['id']}", "the menu \"{$menu['name']}\" is deeper than ".Menu::MAX_DEPTH.' levels: the items below the last level moved up to it');
            }

            $written += 1 + $this->writeItems($menuId, $item['children'], $below, $depth + 1, $menu, $sorts);
        }

        return $written;
    }

    /**
     * The address or the record an item goes to in Atrium. A record whose
     * module has no pages is linked by its new address instead.
     *
     * @param  array<string,mixed>|null  $link
     * @return array{url?:string,type?:string,id?:int}|null
     */
    private function menuLink(?array $link, ?string $language): ?array
    {
        if ($link === null) {
            return null;
        }

        if ($link['kind'] === 'url') {
            return ['url' => $link['url']];
        }

        $row = DB::table('wordpress_map')->where('kind', $link['kind'])->where('wp_id', $link['id'])->first();

        if ($row === null) {
            return null;
        }

        if (app(RecordUrls::class)->hasPages($row->target_type)) {
            return ['type' => $row->target_type, 'id' => $row->target_id];
        }

        $paths = (array) json_decode((string) $row->paths, true);
        $language ??= $this->records->plan->languages->default();

        foreach ($paths as [, $new]) {
            $isOther = preg_match('#^/([a-z]{2}(?:-[a-z0-9]+)?)/#', (string) $new, $match) === 1 && in_array($match[1], $this->records->plan->languages->codes(), true);

            if (($language === $this->records->plan->languages->default()) !== $isOther) {
                return ['url' => $new];
            }
        }

        return null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $records
     * @return array<int,array<string,mixed>>
     */
    private function changed(Type $type, array $records): array
    {
        $rows = DB::table('wordpress_map')->where('kind', 'post')->whereIn('wp_id', array_keys($records))->get()->keyBy('wp_id');

        return array_filter($records, static function (array $record, int $id) use ($rows, $type): bool {
            $row = $rows[$id] ?? null;

            return $row === null
                || $row->target_type !== $type->model
                || ! $type->model::query()->whereKey($row->target_id)->exists()
                || $row->wp_modified < $record['modified'];
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private function write(Type $type, int $id, array $record): void
    {
        $plan = $this->records->plan;
        $model = $this->find($type->model, 'post', $id) ?? new $type->model;
        $values = $record['values'];

        $values['site_id'] = $this->site;

        if ($type->generated()) {
            $values['wordpress_id'] = $id;
        }

        $this->fill($model, $values);
        $this->seo($model, $record['seo']);

        foreach ($type->taxonomies as $name) {
            $taxonomy = $plan->taxonomy($name);

            if ($taxonomy !== null) {
                $this->link($taxonomy->pivot($type), (int) $model->getKey(), $this->targetsOf('term', $record['terms'][$name] ?? []));
            }
        }

        $this->link($plan->authorPivot($type), (int) $model->getKey(), $this->targetsOf('author', $record['authors']));

        $this->remember('post', $type->type, $id, $model, $this->pairs($record), $record['modified']);
    }

    /**
     * Old address => new address of a record, the short /?p=ID links of
     * its published posts included.
     *
     * @param  array<string,mixed>  $record
     * @return array<int,array{0:string,1:string}>
     */
    private function pairs(array $record): array
    {
        $pairs = [];

        foreach ($record['paths'] as $language => $old) {
            $new = $record['targets'][$language];

            $pairs[$old] = [$old, $new];

            if (isset($record['short'][$language])) {
                $pairs[$record['short'][$language]] = [$record['short'][$language], $new];
            }
        }

        return array_values($pairs);
    }

    /**
     * Saves the values; an empty one is stored as NULL, not as a
     * translation of nothing ({"en":null}) the way a translatable model
     * would write it.
     *
     * @param  array<string,mixed>  $values
     */
    private function fill(Model $model, array $values): void
    {
        $nulls = array_filter($values, static fn (mixed $value): bool => $value === null);
        $model->forceFill(Arr::whereNotNull($values));
        $model->setRawAttributes([...$model->getAttributes(), ...$nulls]);
        $model->save();
    }

    /**
     * @param  array<int,int>  $related
     */
    private function link(string $pivot, int $record, array $related): void
    {
        DB::table($pivot)->where('record_id', $record)->delete();

        if ($related !== []) {
            DB::table($pivot)->insert(array_map(static fn (int $id): array => ['record_id' => $record, 'related_id' => $id], $related));
        }
    }

    /**
     * @param  array<string,string>  $seo
     */
    private function seo(Model $model, array $seo): void
    {
        $keys = ['seoable_type' => $model::class, 'seoable_id' => $model->getKey()];
        $columns = ['title', 'description', 'keywords', 'canonical_url', 'robots', 'og_title', 'og_description', 'og_image'];
        $values = array_merge(array_fill_keys($columns, null), $seo, ['updated_at' => now()]);
        $exists = DB::table('seo_meta')->where($keys)->exists();

        if ($exists) {
            DB::table('seo_meta')->where($keys)->update($values);
        } elseif ($seo !== []) {
            DB::table('seo_meta')->insert([...$keys, ...$values, 'created_at' => now()]);
        }
    }

    private function find(string $model, string $kind, int $id): ?Model
    {
        $target = $this->targetOf($kind, $id);

        return $target === null ? null : $model::query()->find($target);
    }

    /**
     * What these WordPress records became, the ones that were not written
     * left out, each once.
     *
     * @param  array<int,int>  $ids
     * @return array<int,int>
     */
    private function targetsOf(string $kind, array $ids): array
    {
        return collect($ids)->map(fn (int $id): ?int => $this->targetOf($kind, $id))->filter()->unique()->values()->all();
    }

    private function targetOf(string $kind, int $id): ?int
    {
        if (! array_key_exists($id, $this->targets[$kind] ?? [])) {
            $this->targets[$kind][$id] = DB::table('wordpress_map')->where('kind', $kind)->where('wp_id', $id)->value('target_id');
        }

        $target = $this->targets[$kind][$id];

        return $target === null ? null : (int) $target;
    }

    /**
     * @param  array<int,array{0:string,1:string}>  $pairs
     */
    private function remember(string $kind, string $type, int $id, Model $target, array $pairs, ?string $modified): void
    {
        $keys = ['kind' => $kind, 'wp_id' => $id];
        $values = [
            'wp_type' => $type,
            'target_type' => $target::class,
            'target_id' => $target->getKey(),
            'paths' => $pairs === [] ? null : json_encode($pairs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'wp_modified' => $modified,
            'updated_at' => now(),
        ];

        if (DB::table('wordpress_map')->where($keys)->exists()) {
            DB::table('wordpress_map')->where($keys)->update($values);
        } else {
            DB::table('wordpress_map')->insert([...$keys, ...$values, 'created_at' => now()]);
        }

        $this->targets[$kind][$id] = $target->getKey();
    }

    /**
     * Copies one file of the old uploads folder to the new one. False when
     * it is not in the old folder either.
     */
    private function copy(string $file): bool
    {
        $file = ltrim(str_replace('\\', '/', $file), '/');
        $from = config('atrium-wordpress.uploads_path').'/'.$file;

        // Nothing outside the uploads folder, whatever a database says.
        if ($file === '' || str_contains('/'.$file, '/../') || ! is_file($from)) {
            if ($file !== '' && ! in_array($file, $this->missingFiles, true)) {
                $this->missingFiles[] = $file;
            }

            return false;
        }

        $to = $this->target($file);

        if (! is_file($to) || filesize($to) !== filesize($from)) {
            if (! is_dir(dirname($to))) {
                mkdir(dirname($to), 0755, true);
            }

            copy($from, $to);
        }

        return true;
    }

    private function target(string $file): string
    {
        return public_path(Str::chopStart(Content::uploadsBase(), '/').'/'.$file);
    }

    private function say(string $line): void
    {
        if ($this->progress !== null) {
            ($this->progress)($line);
        }
    }
}
