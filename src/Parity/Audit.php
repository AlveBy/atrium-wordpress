<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Parity;

use Atrium\Admin\Models\MediaItem;
use Atrium\WordPress\Import\Content;
use Atrium\WordPress\Import\Records;
use Atrium\WordPress\Plan\Taxonomy;
use Atrium\WordPress\Plan\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Before and after: every post, term, author, file, language and old
 * address WordPress had, compared field by field with what Atrium holds.
 * The rows Atrium should hold come from Records, as the importer's do; the
 * rows it holds are read straight from the tables.
 */
final class Audit
{
    /** @var array<int,array{scope:string,wp_id:?int,field:string,expected:mixed,actual:mixed}> */
    private array $problems = [];

    /** @var array<string,array{wordpress:int,atrium:int,problems:int}> */
    private array $summary = [];

    /** @var array<int,string> */
    private array $warnings = [];

    public function __construct(private readonly Records $records) {}

    /**
     * @return array{summary:array<string,array{wordpress:int,atrium:int,problems:int}>,problems:array<int,array<string,mixed>>,warnings:array<int,string>,notes:array<int,string>}
     */
    public function run(): array
    {
        $this->problems = $this->summary = $this->warnings = [];
        $plan = $this->records->plan;

        if (! Schema::hasTable('wordpress_map')) {
            $this->problem('setup', null, 'wordpress_map', 'the table', null);

            return $this->result();
        }

        $this->languages();
        $this->authors();

        foreach ($plan->taxonomies as $taxonomy) {
            $this->terms($taxonomy);
        }

        $this->files();

        foreach ($plan->types as $type) {
            if (! $type->moves()) {
                $this->warnings[] = "{$type->type}: {$type->count} posts are not moved ({$type->reason})";

                continue;
            }

            $this->type($type);
        }

        return $this->result();
    }

    /**
     * @return array{summary:array<string,array{wordpress:int,atrium:int,problems:int}>,problems:array<int,array<string,mixed>>,warnings:array<int,string>,notes:array<int,string>}
     */
    private function result(): array
    {
        return [
            'summary' => $this->summary,
            'problems' => $this->problems,
            'warnings' => array_values(array_unique($this->warnings)),
            'notes' => $this->records->notes(),
        ];
    }

    private function languages(): void
    {
        $languages = $this->records->plan->languages;
        $rows = DB::table('admin_languages')->get()->keyBy('code');
        $before = count($this->problems);

        foreach ($languages->codes() as $code) {
            if (! isset($rows[$code]) || ! $rows[$code]->active) {
                $this->problem('languages', null, $code, 'an active language', isset($rows[$code]) ? 'inactive' : null);
            }
        }

        if (isset($rows[$languages->default()]) && ! $rows[$languages->default()]->is_default) {
            $this->problem('languages', null, 'default', $languages->default(), $rows->firstWhere('is_default', true)?->code);
        }

        $this->count('languages', count($languages->codes()), count(array_intersect($languages->codes(), $rows->keys()->all())), $before);
    }

    private function authors(): void
    {
        $plan = $this->records->plan;
        $expected = $this->records->authors();
        $before = count($this->problems);
        $found = 0;

        if (! Schema::hasTable($plan->authorsTable)) {
            $this->problem('authors', null, 'table', $plan->authorsTable, null);
            $this->count('authors', count($expected), 0, $before);

            return;
        }

        $rows = DB::table($plan->authorsTable)->get()->keyBy('wordpress_id');

        foreach ($expected as $id => $record) {
            $row = $rows[$id] ?? null;

            if ($row === null) {
                $this->problem('authors', $id, 'record', 'moved', null);

                continue;
            }

            $found++;
            $this->compare('authors', $id, $record['values'], $row);
        }

        $this->count('authors', count($expected), $found, $before);
    }

    private function terms(Taxonomy $taxonomy): void
    {
        $scope = 'terms:'.$taxonomy->taxonomy;
        $expected = $this->records->terms($taxonomy);
        $before = count($this->problems);

        if (! Schema::hasTable($taxonomy->table)) {
            $this->problem($scope, null, 'table', $taxonomy->table, null);
            $this->count($scope, count($expected), 0, $before);

            return;
        }

        $rows = DB::table($taxonomy->table)->get()->keyBy('wordpress_id');
        $ids = $rows->pluck('wordpress_id', 'id')->all();
        $map = $this->map('term');
        $found = 0;

        foreach ($expected as $id => $record) {
            $row = $rows[$id] ?? null;

            if ($row === null) {
                $this->problem($scope, $id, 'record', 'moved', null);

                continue;
            }

            $found++;
            $this->compare($scope, $id, $record['values'], $row);

            if ($taxonomy->hierarchical) {
                $this->same($scope, $id, 'parent', $record['parent'], $row->parent_id === null ? null : ($ids[$row->parent_id] ?? 'id '.$row->parent_id));
            }

            $this->seo($scope, $id, $taxonomy->model, (int) $row->id, $record['seo']);
            $this->redirects($scope, $id, $record, $map[$id] ?? null);
        }

        $this->count($scope, count($expected), $found, $before);
    }

    private function files(): void
    {
        $attachments = $this->records->attachments();
        $source = config('atrium-wordpress.uploads_path');
        $items = MediaItem::query()->whereIn('url', array_column($attachments, 'url'))->pluck('url')->flip();
        $before = count($this->problems);
        $expected = $found = 0;

        foreach ($attachments as $id => $attachment) {
            foreach ([$attachment['file'], ...$attachment['sizes']] as $file) {
                $from = $source.'/'.$file;

                if (! is_file($from)) {
                    $this->warnings[] = "the file {$file} of attachment {$id} is missing in the old uploads folder too";

                    continue;
                }

                $expected++;
                $to = public_path(Content::uploadsBase().'/'.$file);

                if (! is_file($to) || filesize($to) !== filesize($from)) {
                    $this->problem('files', $id, $file, 'copied', is_file($to) ? 'a different size' : null);

                    continue;
                }

                $found++;

                if ($file === $attachment['file'] && ! isset($items[$attachment['url']])) {
                    $this->problem('files', $id, 'media library', $attachment['url'], null);
                }
            }
        }

        $this->count('files', $expected, $found, $before);
    }

    private function type(Type $type): void
    {
        $scope = 'posts:'.$type->type;
        $before = count($this->problems);
        $expected = $this->records->count($type);

        if (! Schema::hasTable($type->table)) {
            $this->problem($scope, null, 'table', $type->table, null);
            $this->count($scope, $expected, 0, $before);

            return;
        }

        $map = $this->map('post');
        $wordpressIds = $this->wordpressIds($map, $type);
        $found = 0;

        foreach ($this->records->records($type) as $records) {
            $targets = array_map(static fn (int $id): ?int => isset($map[$id]) && $map[$id]->target_type === $type->model ? (int) $map[$id]->target_id : null, array_combine(array_keys($records), array_keys($records)));
            $rows = DB::table($type->table)->whereIn('id', $targets)->get()->keyBy('id');

            foreach ($records as $id => $record) {
                $row = isset($targets[$id]) ? ($rows[$targets[$id]] ?? null) : null;

                if ($row === null) {
                    $this->problem($scope, $id, 'record', 'moved', null);

                    continue;
                }

                $found++;
                $this->compare($scope, $id, $record['values'], $row);

                if ($type->target === Type::PAGES || $type->hierarchical) {
                    $parent = $row->parent_id === null ? null : ($wordpressIds[$row->parent_id] ?? 'id '.$row->parent_id);
                    $this->same($scope, $id, 'parent', $record['parent'], $parent);
                }

                $this->links($scope, $id, $type, (int) $row->id, $record);
                $this->seo($scope, $id, $type->model, (int) $row->id, $record['seo']);
                $this->redirects($scope, $id, $record, $map[$id] ?? null);

                foreach ($record['lost'] as $lost) {
                    $this->warnings[] = "{$type->type} {$id}: {$lost} has no place in the {$type->target} module and did not move";
                }
            }
        }

        $this->count($scope, $expected, $found, $before);
    }

    /**
     * The terms and authors of a record.
     *
     * @param  array<string,mixed>  $record
     */
    private function links(string $scope, int $id, Type $type, int $target, array $record): void
    {
        $plan = $this->records->plan;

        foreach ($type->taxonomies as $name) {
            $taxonomy = $plan->taxonomy($name);

            if ($taxonomy === null || ! Schema::hasTable($taxonomy->pivot($type))) {
                continue;
            }

            $actual = DB::table($taxonomy->pivot($type).' as p')
                ->join($taxonomy->table.' as t', 't.id', '=', 'p.related_id')
                ->where('p.record_id', $target)
                ->orderBy('t.wordpress_id')
                ->pluck('t.wordpress_id')
                ->map(static fn (mixed $value): int => (int) $value)
                ->all();
            $expected = $record['terms'][$name] ?? [];
            sort($expected);
            $this->same($scope, $id, $name, $expected, $actual);
        }

        if (Schema::hasTable($plan->authorPivot($type))) {
            $actual = DB::table($plan->authorPivot($type).' as p')
                ->join($plan->authorsTable.' as a', 'a.id', '=', 'p.related_id')
                ->where('p.record_id', $target)
                ->orderBy('a.wordpress_id')
                ->pluck('a.wordpress_id')
                ->map(static fn (mixed $value): int => (int) $value)
                ->all();
            $expected = $record['authors'];
            sort($expected);
            $this->same($scope, $id, 'authors', $expected, $actual);
        }
    }

    /**
     * @param  array<string,string>  $expected
     */
    private function seo(string $scope, int $id, string $model, int $target, array $expected): void
    {
        $row = DB::table('seo_meta')->where('seoable_type', $model)->where('seoable_id', $target)->first();

        foreach ($expected as $column => $value) {
            $this->same($scope, $id, 'seo.'.$column, $value, $row?->{$column});
        }
    }

    /**
     * Every old address of a record leads to its new one.
     *
     * @param  array<string,mixed>  $record
     */
    private function redirects(string $scope, int $id, array $record, ?object $row): void
    {
        $pairs = [];

        foreach ((array) json_decode((string) ($row->paths ?? ''), true) as $pair) {
            $pairs[$pair[0]] = (string) $pair[1];
        }

        foreach ($record['paths'] as $language => $old) {
            $this->same($scope, $id, 'redirect '.$old, $record['targets'][$language], $pairs[$old] ?? null);
        }
    }

    /**
     * @param  array<string,mixed>  $expected
     */
    private function compare(string $scope, int $id, array $expected, object $row): void
    {
        foreach ($expected as $column => $value) {
            $this->same($scope, $id, $column, $value, property_exists($row, $column) ? $row->{$column} : null);
        }
    }

    private function same(string $scope, ?int $id, string $field, mixed $expected, mixed $actual): void
    {
        // Decoded for the report too: MySQL gives JSON back with its own spacing.
        $actual = $this->json($expected, $actual);

        if (! $this->equal($expected, $actual)) {
            $this->problem($scope, $id, $field, $expected, $actual);
        }
    }

    /**
     * Equal as stored: databases give back booleans as 0 and 1, JSON as
     * text with its keys in their own order, dates with a time.
     */
    private function equal(mixed $expected, mixed $actual): bool
    {
        $actual = $this->json($expected, $actual);

        if ($this->blank($expected) || $this->blank($actual)) {
            return $this->blank($expected) && $this->blank($actual);
        }

        return match (true) {
            is_bool($expected) => $expected === filter_var($actual, FILTER_VALIDATE_BOOL),
            is_float($expected), is_int($expected) => is_numeric($actual) && abs($expected - $actual) < 0.000001,
            is_array($expected) => is_array($actual) && $this->equalArrays($expected, $actual),
            is_string($expected) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $expected) === 1 => str_starts_with(str_replace('T', ' ', (string) $actual), $expected),
            default => $expected === (string) $actual,
        };
    }

    /**
     * @param  array<array-key,mixed>  $expected
     * @param  array<array-key,mixed>  $actual
     */
    private function equalArrays(array $expected, array $actual): bool
    {
        if (count($expected) !== count($actual) || array_diff_key($expected, $actual) !== []) {
            return false;
        }

        foreach ($expected as $key => $value) {
            if (! $this->equal($value, $actual[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * A JSON column where an array (or nothing) is expected, as an array.
     */
    private function json(mixed $expected, mixed $actual): mixed
    {
        return is_string($actual) && (is_array($expected) || $expected === null) && in_array($actual[0] ?? '', ['{', '['], true)
            ? json_decode($actual, true)
            : $actual;
    }

    private function blank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * @return array<int,object> wp_id => row of wordpress_map
     */
    private function map(string $kind): array
    {
        return DB::table('wordpress_map')->where('kind', $kind)->get()->keyBy('wp_id')->all();
    }

    /**
     * @param  array<int,object>  $map
     * @return array<int,int> id of the record in the module => its WordPress ID
     */
    private function wordpressIds(array $map, Type $type): array
    {
        $ids = [];

        foreach ($map as $wordpressId => $row) {
            if ($row->target_type === $type->model) {
                $ids[$row->target_id] = $wordpressId;
            }
        }

        return $ids;
    }

    private function problem(string $scope, ?int $id, string $field, mixed $expected, mixed $actual): void
    {
        $this->problems[] = ['scope' => $scope, 'wp_id' => $id, 'field' => $field, 'expected' => $expected, 'actual' => $actual];
    }

    private function count(string $scope, int $wordpress, int $atrium, int $before): void
    {
        $this->summary[$scope] = ['wordpress' => $wordpress, 'atrium' => $atrium, 'problems' => count($this->problems) - $before];
    }
}
