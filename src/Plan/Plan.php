<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Plan;

use Atrium\WordPress\Site\Languages;
use Atrium\WordPress\Source\Source;
use Atrium\WordPress\Support\Serialized;

/**
 * What the WordPress site holds and where each part of it goes. Built from
 * the source and the config every time, so analyze, generate, migrate and
 * parity always agree.
 */
final class Plan
{
    /**
     * @param  array<string,Type>  $types
     * @param  array<string,Taxonomy>  $taxonomies
     */
    public function __construct(
        public readonly Source $source,
        public readonly Languages $languages,
        public readonly array $types,
        public readonly array $taxonomies,
        public readonly string $authorsTable,
        public readonly string $authorsModel,
    ) {}

    /**
     * @return array<string,Type>
     */
    public function moving(): array
    {
        return array_filter($this->types, static fn (Type $type): bool => $type->moves());
    }

    /**
     * @return array<string,Type>
     */
    public function generated(): array
    {
        return array_filter($this->types, static fn (Type $type): bool => $type->generated());
    }

    public function type(string $type): ?Type
    {
        return $this->types[$type] ?? null;
    }

    public function taxonomy(string $taxonomy): ?Taxonomy
    {
        return $this->taxonomies[$taxonomy] ?? null;
    }

    /**
     * The table linking the records of a module to their authors.
     */
    public function authorPivot(Type $type): string
    {
        return $type->table.'_'.$this->authorsTable;
    }

    /**
     * The old site's address without a trailing slash.
     */
    public function home(): string
    {
        return rtrim((string) ($this->source->option('home') ?: $this->source->option('siteurl')), '/');
    }

    /**
     * yoast, rankmath, both or none, from the active plugins.
     *
     * @return array<int,string>
     */
    public function seoPlugins(): array
    {
        $plugins = implode(' ', Serialized::toArray($this->source->option('active_plugins')));

        return array_values(array_filter([
            str_contains($plugins, 'wordpress-seo') ? 'yoast' : null,
            str_contains($plugins, 'seo-by-rank-math') ? 'rankmath' : null,
        ]));
    }
}
