<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Site;

use Atrium\WordPress\Source\Source;
use Atrium\WordPress\Support\Serialized;

/**
 * The languages of a WordPress site. WPML and Polylang keep each translation
 * as a post of its own and tie them into a group; Atrium keeps one record
 * with a text per language, so every group becomes one record.
 */
final class Languages
{
    private ?string $plugin = null;

    private string $default;

    /** @var array<string,string> code => name */
    private array $names = [];

    /** @var array<int,array{0:string,1:string}> post ID => [group, language] */
    private array $posts = [];

    /** @var array<int,array{0:string,1:string}> WPML: term_taxonomy_id, Polylang: term_id => [group, language] */
    private array $terms = [];

    /** How the old site told languages apart in its addresses: directory, parameter or domain. */
    private string $urlMode = 'directory';

    public function __construct(Source $source, ?string $language = null)
    {
        $this->default = self::code((string) ($language ?: self::localeLanguage($source->option('WPLANG'))));

        $wpml = $source->wpmlTranslations();

        if ($wpml !== null && $wpml !== []) {
            $this->readWpml($source, $wpml);
        } elseif ($source->objectsInTaxonomy('language') !== []) {
            $this->readPolylang($source);
        }

        $this->names[$this->default] ??= self::name($this->default);
        // The default language first.
        $this->names = [$this->default => $this->names[$this->default]] + $this->names;
    }

    /**
     * A WordPress language code as Atrium writes it: "pt_BR" is "pt-br".
     */
    public static function code(string $code): string
    {
        return str_replace('_', '-', strtolower(trim($code)));
    }

    /**
     * A language's own name for itself: ru is Русский. WPML keeps the names
     * in tables of its own; without them the intl extension knows them.
     */
    public static function name(string $code): string
    {
        $name = class_exists(\Locale::class) ? \Locale::getDisplayLanguage($code, $code) : '';

        return $name === '' || $name === $code ? strtoupper($code) : mb_convert_case(mb_substr($name, 0, 1), MB_CASE_UPPER).mb_substr($name, 1);
    }

    public function plugin(): ?string
    {
        return $this->plugin;
    }

    public function default(): string
    {
        return $this->default;
    }

    /**
     * @return array<int,string>
     */
    public function codes(): array
    {
        return array_keys($this->names);
    }

    /**
     * @return array<string,string>
     */
    public function names(): array
    {
        return $this->names;
    }

    public function urlMode(): string
    {
        return $this->urlMode;
    }

    public function ofPost(int $id): string
    {
        return $this->posts[$id][1] ?? $this->default;
    }

    public function postGroup(int $id): string
    {
        return $this->posts[$id][0] ?? 'post:'.$id;
    }

    public function ofTerm(object $term): string
    {
        return $this->terms[$this->termKey($term)][1] ?? $this->default;
    }

    public function termGroup(object $term): string
    {
        return $this->terms[$this->termKey($term)][0] ?? 'term:'.$term->term_taxonomy_id;
    }

    private function termKey(object $term): int
    {
        return (int) ($this->plugin === 'polylang' ? $term->term_id : $term->term_taxonomy_id);
    }

    /**
     * ru_RU → ru; an empty WPLANG is WordPress's own English.
     */
    private static function localeLanguage(?string $locale): string
    {
        return $locale === null || $locale === '' ? 'en' : explode('_', $locale)[0];
    }

    /**
     * @param  array<int,object>  $rows
     */
    private function readWpml(Source $source, array $rows): void
    {
        $this->plugin = 'wpml';
        $settings = Serialized::decode($source->option('icl_sitepress_settings'));
        $settings = is_array($settings) ? $settings : [];

        if (is_string($settings['default_language'] ?? null) && $settings['default_language'] !== '') {
            $this->default = self::code($settings['default_language']);
        }

        // 1: folders (/ru/), 2: a domain per language, 3: ?lang=ru.
        $this->urlMode = match ((int) ($settings['language_negotiation_type'] ?? null)) {
            2 => 'domain',
            3 => 'parameter',
            default => 'directory',
        };

        foreach ($rows as $row) {
            $language = self::code((string) $row->language_code);
            $this->names[$language] = self::name($language);
            $entry = ['wpml:'.$row->trid, $language];

            if (str_starts_with((string) $row->element_type, 'post_')) {
                $this->posts[(int) $row->element_id] = $entry;
            } elseif (str_starts_with((string) $row->element_type, 'tax_')) {
                $this->terms[(int) $row->element_id] = $entry;
            }
        }
    }

    private function readPolylang(Source $source): void
    {
        $this->plugin = 'polylang';
        $settings = Serialized::decode($source->option('polylang'));
        $settings = is_array($settings) ? $settings : [];

        if (is_string($settings['default_lang'] ?? null) && $settings['default_lang'] !== '') {
            $this->default = self::code($settings['default_lang']);
        }

        // 0: ?lang=ru, 1: folders, 2 and 3: subdomains and domains.
        $this->urlMode = match ((int) ($settings['force_lang'] ?? 1)) {
            0 => 'parameter',
            2, 3 => 'domain',
            default => 'directory',
        };

        $terms = $source->terms();
        $byTaxonomyId = [];

        foreach ($terms as $term) {
            $byTaxonomyId[$term->term_taxonomy_id] = $term;

            if ($term->taxonomy === 'language') {
                $this->names[self::code((string) $term->slug)] = (string) $term->name;
            }
        }

        // The language of each post and of each term.
        foreach (['language' => 'posts', 'term_language' => 'terms'] as $taxonomy => $property) {
            foreach ($source->objectsInTaxonomy($taxonomy) as $object => $taxonomyId) {
                $slug = (string) ($byTaxonomyId[$taxonomyId]->slug ?? '');
                $language = self::code((string) preg_replace('/^pll_/', '', $slug));
                $this->{$property}[$object] = [$property.':'.$object, $language];
            }
        }

        // Translation groups: the description of the group's term lists its members.
        foreach ($terms as $term) {
            $property = match ($term->taxonomy) {
                'post_translations' => 'posts',
                'term_translations' => 'terms',
                default => null,
            };

            if ($property === null) {
                continue;
            }

            foreach (Serialized::toArray((string) $term->description) as $language => $member) {
                $this->{$property}[$member] = ['polylang:'.$term->term_taxonomy_id, self::code((string) $language)];
            }
        }
    }
}
