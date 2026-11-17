<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Source;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * A copy of the WordPress database, read through a Laravel connection. The
 * table prefix is the one in the site's wp-config.php ($table_prefix).
 */
final class Database implements Source
{
    /** Ids per query, below every database's limit on bound parameters. */
    private const CHUNK = 500;

    /** @var array<string,?string> */
    private array $options = [];

    public function __construct(
        private readonly Connection $db,
        private readonly string $prefix = 'wp_',
    ) {}

    public function connection(): Connection
    {
        return $this->db;
    }

    public function option(string $name): ?string
    {
        if (! array_key_exists($name, $this->options)) {
            $value = $this->table('options')->where('option_name', $name)->value('option_value');
            $this->options[$name] = $value === null ? null : (string) $value;
        }

        return $this->options[$name];
    }

    public function postTypeCounts(array $statuses = self::STATUSES): array
    {
        return $this->table('posts')
            ->whereIn('post_status', $statuses)
            ->groupBy('post_type')
            ->orderBy('post_type')
            ->selectRaw('post_type, count(*) as aggregate')
            ->pluck('aggregate', 'post_type')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }

    public function postIds(string $type, array $statuses = self::STATUSES): array
    {
        return $this->table('posts')
            ->where('post_type', $type)
            ->whereIn('post_status', $statuses)
            ->orderBy('ID')
            ->pluck('ID')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function parents(string $type): array
    {
        return $this->table('posts')
            ->where('post_type', $type)
            ->whereIn('post_status', self::STATUSES)
            ->orderBy('ID')
            ->pluck('post_parent', 'ID')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function metaKeys(string $type): array
    {
        return $this->table('postmeta', 'm')
            ->join($this->prefix.'posts as p', 'p.ID', '=', 'm.post_id')
            ->where('p.post_type', $type)
            ->whereIn('p.post_status', self::STATUSES)
            ->distinct()
            ->orderBy('m.meta_key')
            ->pluck('m.meta_key')
            ->map(static fn (mixed $key): string => (string) $key)
            ->all();
    }

    public function posts(array $ids): array
    {
        $posts = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach ($this->table('posts')->whereIn('ID', $chunk)->get() as $row) {
                $posts[$row->ID] = $row;
            }
        }

        return $posts;
    }

    public function meta(array $ids): array
    {
        $meta = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = $this->table('postmeta')->whereIn('post_id', $chunk)->orderBy('meta_id')->get(['post_id', 'meta_key', 'meta_value']);

            foreach ($rows as $row) {
                // A key saved twice keeps its first value, as get_post_meta($id, $key, true) does.
                $meta[$row->post_id][$row->meta_key ?? ''] ??= (string) $row->meta_value;
            }
        }

        return $meta;
    }

    public function terms(): array
    {
        return $this->table('term_taxonomy', 'tt')
            ->join($this->prefix.'terms as t', 't.term_id', '=', 'tt.term_id')
            ->orderBy('tt.term_taxonomy_id')
            ->get(['tt.term_taxonomy_id', 'tt.term_id', 'tt.taxonomy', 'tt.description', 'tt.parent', 't.name', 't.slug'])
            ->all();
    }

    public function termMeta(): array
    {
        if (! $this->db->getSchemaBuilder()->hasTable($this->prefix.'termmeta')) {
            return [];
        }

        $meta = [];

        foreach ($this->table('termmeta')->orderBy('meta_id')->get(['term_id', 'meta_key', 'meta_value']) as $row) {
            $meta[$row->term_id][$row->meta_key ?? ''] ??= (string) $row->meta_value;
        }

        return $meta;
    }

    public function relationships(array $ids): array
    {
        $links = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = $this->table('term_relationships')->whereIn('object_id', $chunk)->orderBy('term_order')->orderBy('term_taxonomy_id')->get();

            foreach ($rows as $row) {
                $links[$row->object_id][] = (int) $row->term_taxonomy_id;
            }
        }

        return $links;
    }

    public function objectsInTaxonomy(string $taxonomy): array
    {
        return $this->table('term_relationships', 'tr')
            ->join($this->prefix.'term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'tr.term_taxonomy_id')
            ->where('tt.taxonomy', $taxonomy)
            ->orderBy('tr.object_id')
            ->pluck('tr.term_taxonomy_id', 'tr.object_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function users(array $ids): array
    {
        $users = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = $this->table('users')->whereIn('ID', $chunk)
                ->get(['ID', 'user_login', 'user_nicename', 'user_email', 'user_url', 'user_registered', 'display_name']);

            foreach ($rows as $row) {
                $row->first_name = $row->last_name = $row->description = '';
                $users[$row->ID] = $row;
            }

            $meta = $this->table('usermeta')->whereIn('user_id', $chunk)
                ->whereIn('meta_key', ['first_name', 'last_name', 'description'])
                ->get(['user_id', 'meta_key', 'meta_value']);

            foreach ($meta as $row) {
                if (isset($users[$row->user_id])) {
                    $users[$row->user_id]->{$row->meta_key} = (string) $row->meta_value;
                }
            }
        }

        return $users;
    }

    public function wpmlTranslations(): ?array
    {
        if (! $this->db->getSchemaBuilder()->hasTable($this->prefix.'icl_translations')) {
            return null;
        }

        return $this->table('icl_translations')
            ->orderBy('translation_id')
            ->get(['element_type', 'element_id', 'trid', 'language_code'])
            ->all();
    }

    public function permalink(int $id): ?string
    {
        return null;
    }

    private function table(string $name, ?string $alias = null): Builder
    {
        return $this->db->table($this->prefix.$name.($alias === null ? '' : ' as '.$alias));
    }
}
