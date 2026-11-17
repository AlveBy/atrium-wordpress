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
use Atrium\Admin\Support\Sites;
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
 * addresses. Every chunk is one transaction, retried on a deadlock; files
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
