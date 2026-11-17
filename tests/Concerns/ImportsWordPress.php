<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Concerns;

use Atrium\WordPress\Import\Records;
use Atrium\WordPress\Plan\Plan;
use Atrium\WordPress\Plan\Planner;
use Atrium\WordPress\Source\Database;
use Atrium\WordPress\Tests\Fixtures\SyntheticWordPress;
use Atrium\WordPress\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Helpers of the tests that run the importer on the made-up WordPress site.
 *
 * @phpstan-require-extends TestCase
 */
trait ImportsWordPress
{
    /**
     * The made-up site: its tables, rows and uploads.
     *
     * @param  string  $languages  wpml, polylang or none
     */
    protected function site(string $languages = 'wpml', string $prefix = 'wp_'): void
    {
        SyntheticWordPress::install($this->wp(), $prefix, $languages);
        SyntheticWordPress::writeUploads($this->workDir.'/uploads');
    }

    protected function wp(): Connection
    {
        return DB::connection('wordpress');
    }

    /**
     * Sets (or adds) an option of the old site.
     */
    protected function option(string $name, ?string $value, string $prefix = 'wp_'): void
    {
        $this->wp()->table($prefix.'options')->where('option_name', $name)->delete();

        if ($value !== null) {
            $this->wp()->table($prefix.'options')->insert(['option_name' => $name, 'option_value' => $value, 'autoload' => 'yes']);
        }
    }

    /**
     * Adds a post of the old site.
     *
     * @param  array<string,mixed>  $values  columns of wp_posts
     */
    protected function wpPost(int $id, string $type, string $title, array $values = [], string $prefix = 'wp_'): void
    {
        $this->wp()->table($prefix.'posts')->insert(array_merge([
            'ID' => $id,
            'post_author' => SyntheticWordPress::USER_ALICE,
            'post_date' => '2024-08-01 10:00:00',
            'post_date_gmt' => '2024-08-01 07:00:00',
            'post_content' => '',
            'post_title' => $title,
            'post_excerpt' => '',
            'post_status' => 'publish',
            'post_name' => Str::slug($title),
            'to_ping' => '',
            'pinged' => '',
            'post_modified' => '2024-08-01 10:00:00',
            'post_modified_gmt' => '2024-08-01 07:00:00',
            'post_content_filtered' => '',
            'post_type' => $type,
        ], $values));
    }

    /**
     * Adds meta to a post of the old site.
     *
     * @param  array<string,string>  $meta
     */
    protected function wpMeta(int $post, array $meta, string $prefix = 'wp_'): void
    {
        foreach ($meta as $key => $value) {
            $this->wp()->table($prefix.'postmeta')->insert(['post_id' => $post, 'meta_key' => $key, 'meta_value' => $value]);
        }
    }

    /**
     * Adds a field group shown on the given types, and its fields.
     *
     * @param  array<int,string>  $types
     * @param  array<int,array<string,mixed>>  $fields  name, label, settings, parent (index of the field in this list), order
     */
    protected function acfGroup(int $id, array $types, array $fields, string $status = 'publish', string $param = 'post_type', string $operator = '=='): void
    {
        $location = array_map(static fn (string $type): array => [['param' => $param, 'operator' => $operator, 'value' => $type]], $types);
        $this->wpPost($id, 'acf-field-group', 'Group '.$id, ['post_status' => $status, 'post_content' => serialize(['location' => $location])]);

        foreach ($fields as $index => $field) {
            $parent = isset($field['parent']) ? $id + 1 + $field['parent'] : $id;
            $this->wpPost($id + 1 + $index, 'acf-field', (string) ($field['label'] ?? ''), [
                'post_excerpt' => $field['name'],
                'post_content' => serialize($field['settings'] ?? ['type' => 'text']),
                'post_parent' => $parent,
                'menu_order' => $field['order'] ?? $index,
                'post_status' => $field['status'] ?? 'publish',
            ]);
        }
    }

    protected function plan(string $prefix = 'wp_'): Plan
    {
        return (new Planner)->build(new Database($this->wp(), $prefix));
    }

    protected function records(string $prefix = 'wp_'): Records
    {
        return new Records($this->plan($prefix));
    }

    /**
     * Runs an artisan command.
     *
     * @param  array<string,mixed>  $arguments
     * @return array{0:int,1:string} exit code and output
     */
    protected function cli(string $command, array $arguments = []): array
    {
        $exit = Artisan::call($command, $arguments);

        return [$exit, Artisan::output()];
    }

    /**
     * The generated modules written and their tables created.
     */
    protected function generateAndMigrate(): void
    {
        [$exit] = $this->cli('atrium:wordpress:generate');
        $this->assertSame(0, $exit);
        [$exit] = $this->cli('migrate', ['--path' => $this->workDir.'/migrations', '--realpath' => true]);
        $this->assertSame(0, $exit);
        $this->resumeTransaction();
    }

    /**
     * Everything: the site, the generated tables and the moved content.
     */
    protected function fullRun(string $languages = 'wpml'): void
    {
        $this->site($languages);
        $this->generateAndMigrate();
        [$exit, $output] = $this->cli('atrium:wordpress:migrate:data');
        $this->assertSame(0, $exit, $output);
    }

    /**
     * What a WordPress post, term, author or file became: its row in the
     * module's table.
     */
    protected function target(string $kind, int $wpId): object
    {
        $map = DB::table('wordpress_map')->where('kind', $kind)->where('wp_id', $wpId)->first();
        $this->assertNotNull($map, "{$kind} {$wpId} has no row in wordpress_map");

        $table = match ($kind) {
            'attachment' => 'media_library_items',
            default => (new ($map->target_type))->getTable(),
        };

        $row = DB::table($table)->find($map->target_id);
        $this->assertNotNull($row, "{$kind} {$wpId} points at a missing row of {$table}");

        return $row;
    }

    protected function moved(string $kind, int $wpId): bool
    {
        return DB::table('wordpress_map')->where('kind', $kind)->where('wp_id', $wpId)->exists();
    }

    /**
     * A JSON column decoded.
     */
    protected function decoded(object $row, string $column): mixed
    {
        return $row->{$column} === null ? null : json_decode((string) $row->{$column}, true);
    }

    /**
     * The map row of a post, term, author or file.
     */
    protected function mapRow(string $kind, int $wpId): ?object
    {
        return DB::table('wordpress_map')->where('kind', $kind)->where('wp_id', $wpId)->first();
    }

    /**
     * The [old, new] address pairs of a mapped post or term.
     *
     * @return array<int,array{0:string,1:string}>
     */
    protected function paths(string $kind, int $wpId): array
    {
        $row = $this->mapRow($kind, $wpId);

        return $row === null || $row->paths === null ? [] : json_decode((string) $row->paths, true);
    }

    /**
     * The seo_meta row of a target.
     */
    protected function seoOf(string $kind, int $wpId): ?object
    {
        $map = $this->mapRow($kind, $wpId);

        return DB::table('seo_meta')->where('seoable_type', $map->target_type)->where('seoable_id', $map->target_id)->first();
    }

    /**
     * The WordPress ids of the terms or authors a record is linked to.
     *
     * @return array<int,int>
     */
    protected function linked(string $pivot, string $table, int $record): array
    {
        $ids = DB::table($pivot.' as p')->join($table.' as t', 't.id', '=', 'p.related_id')
            ->where('p.record_id', $record)->orderBy('t.wordpress_id')->pluck('t.wordpress_id')->map(static fn ($id): int => (int) $id)->all();

        return $ids;
    }

    /**
     * A file under the public folder of the test.
     */
    protected function published(string $path): string
    {
        return $this->workDir.'/public/'.ltrim($path, '/');
    }
}
