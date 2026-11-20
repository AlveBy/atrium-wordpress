<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Console;

use Atrium\WordPress\Import\Navigation;
use Atrium\WordPress\Import\Records;
use Atrium\WordPress\Plan\Field;
use Atrium\WordPress\Plan\Plan;

/**
 * What the WordPress site holds and where each part of it will go. Reads
 * only; changes nothing.
 */
final class AnalyzeCommand extends WordPressCommand
{
    protected $signature = 'atrium:wordpress:analyze'.self::SOURCE_OPTIONS.'
        {--json : Print the plan as JSON}';

    protected $description = 'Show what a WordPress site holds and where each part of it goes in Atrium';

    public function handle(): int
    {
        $records = $this->records();
        $plan = $records->plan;

        if ($this->option('json')) {
            $this->line((string) json_encode($this->describe($plan), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $languages = $plan->languages;
        $this->line('<info>Site:</info> '.$plan->home());
        $this->line('<info>Languages:</info> '.implode(', ', $languages->codes()).' (default '.$languages->default().', '.($languages->plugin() ?? 'no language plugin').')');
        $this->line('<info>SEO:</info> '.(implode(', ', $plan->seoPlugins()) ?: 'no SEO plugin'));
        $this->newLine();

        $this->table(['Post type', 'Posts', 'Records', 'Goes to', 'Table', 'Fields', 'Why'], array_map(fn ($type): array => [
            $type->type,
            $type->count,
            $type->moves() ? $records->count($type) : '-',
            $type->target === 'generate' ? 'new module '.class_basename($type->model) : $type->target,
            $type->table ?: '-',
            implode(', ', array_map(static fn (Field $field): string => $field->name.' ('.$field->kind.')', $type->fields)) ?: '-',
            $type->reason ?? '',
        ], $plan->types));

        $this->table(['Taxonomy', 'Terms', 'Table', 'Linked to'], array_map(static fn ($taxonomy): array => [
            $taxonomy->taxonomy,
            $taxonomy->count,
            $taxonomy->table,
            implode(', ', $taxonomy->types) ?: '-',
        ], $plan->taxonomies));

        $this->line($this->menusLine($records));
        $this->line('Authors go to the table '.$plan->authorsTable.'; attachments to /'.ltrim((string) config('atrium-wordpress.uploads_target'), '/').' and the media library.');
        $this->line('Next: php artisan atrium:wordpress:migrate');

        return self::SUCCESS;
    }

    private function menusLine(Records $records): string
    {
        $menus = (new Navigation($records))->menus();
        $count = count($menus);

        return match (true) {
            $count === 0 => 'Menus: none',
            Navigation::installed() => "Menus: {$count} go to the menus module, with their items",
            default => "Menus: {$count} are not moved until the menus module (alveby/atrium-menus) is installed",
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function describe(Plan $plan): array
    {
        return [
            'home' => $plan->home(),
            'languages' => ['codes' => $plan->languages->codes(), 'default' => $plan->languages->default(), 'plugin' => $plan->languages->plugin(), 'urls' => $plan->languages->urlMode()],
            'seo' => $plan->seoPlugins(),
            'types' => array_map(static fn ($type): array => [
                'target' => $type->target,
                'table' => $type->table,
                'model' => $type->model,
                'prefix' => $type->prefix,
                'count' => $type->count,
                'fields' => array_map(static fn (Field $field): array => ['name' => $field->name, 'column' => $field->column, 'kind' => $field->kind], $type->fields),
                'taxonomies' => $type->taxonomies,
                'reason' => $type->reason,
            ], $plan->types),
            'taxonomies' => array_map(static fn ($taxonomy): array => [
                'table' => $taxonomy->table,
                'model' => $taxonomy->model,
                'prefix' => $taxonomy->prefix,
                'count' => $taxonomy->count,
                'types' => $taxonomy->types,
            ], $plan->taxonomies),
            'authors' => ['table' => $plan->authorsTable, 'model' => $plan->authorsModel],
        ];
    }
}
