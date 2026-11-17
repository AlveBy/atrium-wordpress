<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Plan;

use Atrium\News\Models\News;
use Atrium\Pages\Models\Page;
use Atrium\WordPress\Site\Languages;
use Atrium\WordPress\Source\Source;
use Atrium\WordPress\Support\Serialized;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Looks at the source and the config and decides where everything goes.
 */
final class Planner
{
    /** Post types of WordPress and its common plugins that are not content. */
    public const INTERNAL_TYPES = [
        'revision', 'nav_menu_item', 'attachment', 'custom_css', 'customize_changeset', 'oembed_cache',
        'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
        'wp_font_family', 'wp_font_face', 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy',
        'acf-ui-options-page', 'wpcf7_contact_form', 'elementor_library', 'scheduled-action',
        'shop_order', 'shop_order_refund', 'shop_coupon', 'shop_webhook', 'product_variation',
    ];

    /** Taxonomies that are bookkeeping, not content. */
    public const INTERNAL_TAXONOMIES = [
        'nav_menu', 'link_category', 'post_format', 'language', 'term_language', 'post_translations',
        'term_translations', 'translation_priority', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category',
        'product_type', 'product_visibility',
    ];

    /** Columns every generated table has; a field with one of these names gets another. */
    public const RESERVED_COLUMNS = [
        'id', 'site_id', 'wordpress_id', 'name', 'code', 'active', 'active_from', 'sort', 'parent_id',
        'preview_picture', 'preview_text', 'detail_text', 'created_at', 'updated_at',
    ];

    /** @var array<int,string> tables handed out in this plan */
    private array $tables = [];

    /** @var array<int,string> classes handed out in this plan */
    private array $models = [];

    public function build(Source $source): Plan
    {
        $this->tables = $this->models = [];
        $languages = new Languages($source, config('atrium-wordpress.language'));
        $namespace = trim((string) config('atrium-wordpress.model_namespace', 'App\\Models'), '\\');

        $terms = $this->termsByTaxonomy($source);
        $acf = $this->acfFields($source);
        $types = [];
        $usage = [];

        foreach ($source->postTypeCounts() as $type => $count) {
            if (in_array($type, self::INTERNAL_TYPES, true)) {
                continue;
            }

            $ids = $source->postIds($type);
            $taxonomies = $this->taxonomiesOf($source, $ids, $terms);
            $types[$type] = $this->type($source, $type, $count, $acf[$type] ?? [], $taxonomies, $namespace);

            foreach ($types[$type]->moves() ? $taxonomies : [] as $taxonomy) {
                $usage[$taxonomy][] = $type;
            }
        }

        $plans = [];

        foreach ($terms as $taxonomy => $rows) {
            $plans[$taxonomy] = $this->taxonomy($source, $taxonomy, $rows, $usage[$taxonomy] ?? [], $namespace);
        }

        $authorsTable = $this->table('authors');

        return new Plan($source, $languages, $types, $plans, $authorsTable, $this->model($namespace, 'Author'));
    }

    /**
     * @param  array<int,Field>  $acf
     * @param  array<int,string>  $taxonomies
     */
    private function type(Source $source, string $type, int $count, array $acf, array $taxonomies, string $namespace): Type
    {
        $fields = [...$acf, ...$this->plainMeta($source, $type, $acf)];
        $configured = config('atrium-wordpress.post_types.'.$type);
        $options = is_array($configured) ? $configured : [];
        $target = is_string($configured) ? $configured : null;
        $reason = null;

        $hierarchical = $this->hierarchical($source->parents($type));
        $label = (string) ($options['label'] ?? Str::headline(Str::plural($type === 'post_tag' ? 'tag' : $type)));
        $lost = implode(', ', array_map(static fn (Field $field): string => $field->name, $fields));

        if ($target === null && in_array($type, ['post', 'page'], true)) {
            $module = $type === 'post' ? Type::NEWS : Type::PAGES;

            if (! $this->available($module)) {
                $reason = "the {$module} module is not installed";
            } elseif ($fields !== []) {
                $reason = "custom fields the {$module} module has no place for: {$lost}";
            } else {
                $target = $module;
            }
        }

        if (in_array($target, [Type::NEWS, Type::PAGES], true) && ! $this->available($target)) {
            $reason = "the {$target} module is not installed";
            $target = null;
        } elseif (in_array($target, [Type::NEWS, Type::PAGES], true) && $fields !== []) {
            $reason = "lost: custom fields the {$target} module has no place for: {$lost}";
        }

        if ($target === Type::SKIP) {
            return new Type($type, Type::SKIP, '', '', '', $label, $hierarchical, $count, $fields, $taxonomies, 'skipped in the config');
        }

        if ($target === Type::NEWS) {
            $prefix = (string) (config('atrium.public_urls.'.News::class.'.prefix') ?? 'news');

            return new Type($type, Type::NEWS, 'news', News::class, $prefix, $label, false, $count, $fields, $taxonomies, $reason);
        }

        if ($target === Type::PAGES) {
            return new Type($type, Type::PAGES, 'pages', Page::class, '', $label, true, $count, $fields, $taxonomies, $reason);
        }

        $table = $this->table((string) ($options['table'] ?? Str::plural(Str::slug($type, '_'))));
        $model = $this->model($namespace, (string) ($options['model'] ?? Str::studly(Str::singular($table))));
        $prefix = trim((string) ($options['prefix'] ?? Str::slug($type)), '/');

        return new Type($type, Type::GENERATE, $table, $model, $prefix, $label, $hierarchical, $count, $fields, $taxonomies, $reason);
    }

    /**
     * @param  array<int,object>  $rows
     * @param  array<int,string>  $types
     */
    private function taxonomy(Source $source, string $taxonomy, array $rows, array $types, string $namespace): Taxonomy
    {
        $configured = config('atrium-wordpress.taxonomies.'.$taxonomy);
        $options = is_array($configured) ? $configured : [];
        $base = match ($taxonomy) {
            'category' => $source->option('category_base') ?: 'category',
            'post_tag' => $source->option('tag_base') ?: 'tag',
            default => Str::slug($taxonomy),
        };
        $name = match ($taxonomy) {
            'post_tag' => 'tags',
            default => Str::plural(Str::slug($taxonomy, '_')),
        };

        $table = $this->table((string) ($options['table'] ?? $name));
        $hierarchical = $taxonomy === 'category' || array_filter($rows, static fn (object $row): bool => (int) $row->parent !== 0) !== [];

        return new Taxonomy(
            $taxonomy,
            $table,
            $this->model($namespace, (string) ($options['model'] ?? Str::studly(Str::singular($table)))),
            trim((string) ($options['prefix'] ?? $base), '/'),
            (string) ($options['label'] ?? Str::headline($name)),
            $hierarchical,
            count($rows),
            $types,
        );
    }

    /**
     * @return array<string,array<int,object>>
     */
    private function termsByTaxonomy(Source $source): array
    {
        $terms = [];

        foreach ($source->terms() as $term) {
            if (! in_array($term->taxonomy, self::INTERNAL_TAXONOMIES, true)) {
                $terms[$term->taxonomy][] = $term;
            }
        }

        ksort($terms);

        return $terms;
    }

    /**
     * @param  array<int,int>  $ids
     * @param  array<string,array<int,object>>  $terms
     * @return array<int,string>
     */
    private function taxonomiesOf(Source $source, array $ids, array $terms): array
    {
        $taxonomyOf = [];

        foreach ($terms as $taxonomy => $rows) {
            foreach ($rows as $row) {
                $taxonomyOf[$row->term_taxonomy_id] = $taxonomy;
            }
        }

        $used = [];

        foreach ($source->relationships($ids) as $links) {
            foreach ($links as $link) {
                if (isset($taxonomyOf[$link])) {
                    $used[] = $taxonomyOf[$link];
                }
            }
        }

        $used = array_unique($used);
        sort($used);

        return $used;
    }

    /**
     * @param  array<int,int>  $parents
     */
    private function hierarchical(array $parents): bool
    {
        foreach ($parents as $parent) {
            if ($parent !== 0 && isset($parents[$parent])) {
                return true;
            }
        }

        return false;
    }

    /**
     * ACF field definitions by post type, from the field groups saved in the
     * database. Groups registered in PHP or kept only as local JSON are not
     * there; their fields are found as plain meta.
     *
     * @return array<string,array<int,Field>>
     */
    private function acfFields(Source $source): array
    {
        $groups = $source->posts($source->postIds('acf-field-group', ['publish']));
        $fields = $source->posts($source->postIds('acf-field', ['publish']));

        uasort($fields, static fn (object $a, object $b): int => [$a->menu_order, $a->ID] <=> [$b->menu_order, $b->ID]);

        $children = [];

        foreach ($fields as $field) {
            $children[$field->post_parent][] = $field;
        }

        $byType = [];

        foreach ($groups as $group) {
            $settings = Serialized::toArray((string) $group->post_content);

            foreach ($this->locationTypes($settings['location'] ?? []) as $type) {
                $columns = array_map(static fn (Field $field): string => $field->column, $byType[$type] ?? []);

                foreach ($children[$group->ID] ?? [] as $row) {
                    $field = $this->acfField($row, $children, $columns);
                    $columns[] = $field->column;
                    $byType[$type][] = $field;
                }
            }
        }

        return $byType;
    }

    /**
     * Post types a field group is shown on: rules "post type is X".
     *
     * @return array<int,string>
     */
    private function locationTypes(mixed $location): array
    {
        $types = [];

        foreach (is_array($location) ? $location : [] as $rules) {
            foreach (is_array($rules) ? $rules : [] as $rule) {
                if (is_array($rule) && ($rule['param'] ?? null) === 'post_type' && ($rule['operator'] ?? null) === '==') {
                    $types[] = $rule['value'];
                }
            }
        }

        return array_unique($types);
    }

    /**
     * @param  array<int,array<int,object>>  $children
     * @param  array<int,string>  $columns
     */
    private function acfField(object $row, array $children, array $columns): Field
    {
        $settings = Serialized::toArray((string) $row->post_content);
        $name = (string) $row->post_excerpt;
        $choices = [];

        foreach ((array) ($settings['choices'] ?? []) as $value => $label) {
            $choices[$value] = (string) $label;
        }

        return new Field(
            $name,
            $this->column($name, $columns),
            (string) $row->post_title ?: Str::headline($name),
            (string) ($settings['type'] ?? 'text'),
            $choices,
            (bool) ($settings['multiple'] ?? false),
            array_map(static fn (object $sub): string => (string) $sub->post_excerpt, $children[$row->ID] ?? []),
        );
    }

    /**
     * Meta keys with no ACF definition that are content: not starting with
     * an underscore, not in skip_meta, not a row of a repeater.
     *
     * @param  array<int,Field>  $acf
     * @return array<int,Field>
     */
    private function plainMeta(Source $source, string $type, array $acf): array
    {
        $keys = $source->metaKeys($type);
        $known = array_map(static fn (Field $field): string => $field->name, $acf);
        $nested = array_map(static fn (Field $field): string => $field->name, array_filter($acf, static fn (Field $field): bool => $field->subFields !== []));
        $columns = array_map(static fn (Field $field): string => $field->column, $acf);
        $skip = (array) config('atrium-wordpress.skip_meta', []);
        $fields = [];

        foreach ($keys as $key) {
            if ($key === '' || $key[0] === '_' || in_array($key, $known, true) || $this->matches($key, $skip)) {
                continue;
            }

            // steps_0_step_text: a row of the repeater "steps"; group_sub: a field of a group.
            if (preg_match('/^(.+?)_\d+_/', $key, $match) && in_array($match[1], $keys, true)) {
                continue;
            }

            if (array_filter($nested, static fn (string $name): bool => str_starts_with($key, $name.'_')) !== []) {
                continue;
            }

            // ACF keeps the field's key under "_name"; with no definition its kind is unknown.
            $kind = in_array('_'.$key, $keys, true) ? 'text' : 'meta';
            $column = $this->column($key, $columns);
            $columns[] = $column;
            $fields[] = new Field($key, $column, Str::headline($key), $kind);
        }

        return $fields;
    }

    /**
     * @param  array<int,string>  $patterns
     */
    private function matches(string $key, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,string>  $taken
     */
    private function column(string $name, array $taken): string
    {
        $column = substr(trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::snake(Str::ascii($name))), '_'), 0, 56) ?: 'field';

        if (in_array($column, self::RESERVED_COLUMNS, true) || ctype_digit($column[0])) {
            $column = 'field_'.$column;
        }

        $candidate = $column;

        for ($i = 2; in_array($candidate, $taken, true); $i++) {
            $candidate = $column.'_'.$i;
        }

        return $candidate;
    }

    private function available(string $module): bool
    {
        [$class, $table] = $module === Type::NEWS ? [News::class, 'news'] : [Page::class, 'pages'];

        return class_exists($class) && Schema::hasTable($table);
    }

    /**
     * A table name nobody else uses: a table of the app or of a module that
     * did not come from WordPress gets "wordpress_" in front of ours.
     */
    private function table(string $name): string
    {
        $table = $name;

        if (in_array($table, $this->tables, true) || (Schema::hasTable($table) && ! Schema::hasColumn($table, 'wordpress_id'))) {
            $table = 'wordpress_'.$name;
        }

        for ($i = 2; in_array($table, $this->tables, true); $i++) {
            $table = 'wordpress_'.$name.'_'.$i;
        }

        $this->tables[] = $table;

        return $table;
    }

    private function model(string $namespace, string $class): string
    {
        $model = $namespace.'\\'.$class;

        for ($i = 2; in_array($model, $this->models, true); $i++) {
            $model = $namespace.'\\'.$class.$i;
        }

        $this->models[] = $model;

        return $model;
    }
}
