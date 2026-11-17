<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Fixtures;

use Atrium\WordPress\Source\Source;

/**
 * A source held in arrays, for tests of the classes that only read one:
 * what the site says about its languages, nothing else.
 */
final class ArraySource implements Source
{
    /**
     * @param  array<string,string>  $options
     * @param  array<int,object>|null  $wpml  rows of icl_translations
     * @param  array<string,array<int,int>>  $objects  taxonomy => object ID => term_taxonomy_id
     * @param  array<int,object>  $terms
     */
    public function __construct(
        private readonly array $options = [],
        private readonly ?array $wpml = null,
        private readonly array $objects = [],
        private readonly array $terms = [],
    ) {}

    /**
     * @return array<string,string>
     */
    public static function wpmlSettings(string $default = 'en', int $negotiation = 1): array
    {
        return ['icl_sitepress_settings' => serialize(['default_language' => $default, 'language_negotiation_type' => $negotiation])];
    }

    public static function translation(string $type, int $element, int $trid, string $language): object
    {
        return (object) ['element_type' => $type, 'element_id' => $element, 'trid' => $trid, 'language_code' => $language];
    }

    public static function term(int $taxonomyId, int $termId, string $taxonomy, string $slug, string $name = '', string $description = ''): object
    {
        return (object) [
            'term_taxonomy_id' => $taxonomyId,
            'term_id' => $termId,
            'taxonomy' => $taxonomy,
            'slug' => $slug,
            'name' => $name === '' ? $slug : $name,
            'description' => $description,
            'parent' => 0,
        ];
    }

    public function option(string $name): ?string
    {
        return $this->options[$name] ?? null;
    }

    public function postTypeCounts(array $statuses = self::STATUSES): array
    {
        return [];
    }

    public function postIds(string $type, array $statuses = self::STATUSES): array
    {
        return [];
    }

    public function parents(string $type): array
    {
        return [];
    }

    public function metaKeys(string $type): array
    {
        return [];
    }

    public function posts(array $ids): array
    {
        return [];
    }

    public function meta(array $ids): array
    {
        return [];
    }

    public function terms(): array
    {
        return $this->terms;
    }

    public function termMeta(): array
    {
        return [];
    }

    public function relationships(array $ids): array
    {
        return [];
    }

    public function objectsInTaxonomy(string $taxonomy): array
    {
        return $this->objects[$taxonomy] ?? [];
    }

    public function users(array $ids): array
    {
        return [];
    }

    public function wpmlTranslations(): ?array
    {
        return $this->wpml;
    }

    public function permalink(int $id): ?string
    {
        return null;
    }
}
