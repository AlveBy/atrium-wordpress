<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Import;

use Atrium\WordPress\Source\Source;
use Atrium\WordPress\Support\Serialized;
use Illuminate\Support\Str;

/**
 * The SEO a post or term had in Yoast SEO or Rank Math, as the columns of
 * Atrium's seo_meta. Their templates (%%title%% %%sep%% %%sitename%%,
 * %title% %sep% %sitename%) are filled in, the old site's own canonical
 * addresses dropped: Atrium works out the canonical address itself.
 */
final class Seo
{
    private const SEPARATORS = [
        'sc-dash' => '-', 'sc-ndash' => '–', 'sc-mdash' => '—', 'sc-colon' => ':', 'sc-middot' => '·',
        'sc-bull' => '•', 'sc-star' => '*', 'sc-smstar' => '⋆', 'sc-pipe' => '|', 'sc-tilde' => '~',
        'sc-laquo' => '«', 'sc-raquo' => '»', 'sc-lt' => '<', 'sc-gt' => '>',
    ];

    private string $siteName;

    private string $siteDescription;

    private string $yoastSeparator;

    private string $rankMathSeparator;

    /** @var array<string,mixed> Yoast's term SEO: taxonomy => term ID => values */
    private array $termOptions;

    public function __construct(Source $source, private readonly Content $content)
    {
        $this->siteName = $content->text($source->option('blogname'));
        $this->siteDescription = $content->text($source->option('blogdescription'));

        $titles = Serialized::toArray($source->option('wpseo_titles'));
        $this->yoastSeparator = self::SEPARATORS[$titles['separator'] ?? ''] ?? '-';

        $rankMath = Serialized::toArray($source->option('rank-math-options-titles'));
        $this->rankMathSeparator = (string) ($rankMath['title_separator'] ?? '-');

        $this->termOptions = Serialized::toArray($source->option('wpseo_taxonomy_meta'));
    }

    /**
     * @param  array<string,string>  $meta
     * @return array<string,string> seo_meta column => value, only the filled ones
     */
    public function forPost(array $meta, string $title, string $excerpt, string $home): array
    {
        $yoast = static fn (string $key): string => trim((string) ($meta['_yoast_wpseo_'.$key] ?? ''));
        $rank = static fn (string $key): string => trim((string) ($meta['rank_math_'.$key] ?? ''));

        $robots = Serialized::toArray($meta['rank_math_robots'] ?? null);
        $noindex = $yoast('meta-robots-noindex') === '1' || in_array('noindex', $robots, true);
        $nofollow = $yoast('meta-robots-nofollow') === '1' || in_array('nofollow', $robots, true);

        $values = [
            'title' => $this->fill($yoast('title'), $title, $excerpt, 'yoast') ?: $this->fill($rank('title'), $title, $excerpt, 'rankmath'),
            'description' => $this->fill($yoast('metadesc'), $title, $excerpt, 'yoast') ?: $this->fill($rank('description'), $title, $excerpt, 'rankmath'),
            'keywords' => $yoast('focuskw') ?: $rank('focus_keyword'),
            'canonical_url' => $this->canonical($yoast('canonical') ?: $rank('canonical_url'), $home),
            'robots' => implode(', ', array_filter([$noindex ? 'noindex' : null, $nofollow ? 'nofollow' : null])),
            'og_title' => $this->fill($yoast('opengraph-title'), $title, $excerpt, 'yoast') ?: $this->fill($rank('facebook_title'), $title, $excerpt, 'rankmath'),
            'og_description' => $this->fill($yoast('opengraph-description'), $title, $excerpt, 'yoast') ?: $this->fill($rank('facebook_description'), $title, $excerpt, 'rankmath'),
            'og_image' => $this->content->uploadUrl($yoast('opengraph-image') ?: $rank('facebook_image')),
        ];

        $values['title'] = mb_substr($values['title'], 0, 255);
        $values['og_title'] = mb_substr($values['og_title'], 0, 255);

        return array_filter($values, static fn (string $value): bool => $value !== '');
    }

    /**
     * A term's SEO: Yoast keeps it in one option, Rank Math in the term's meta.
     *
     * @param  array<string,string>  $termMeta
     * @return array<string,string>
     */
    public function forTerm(string $taxonomy, int $termId, array $termMeta, string $name, string $description, string $home): array
    {
        $yoast = $this->termOptions[$taxonomy][$termId] ?? [];
        $meta = $termMeta;

        foreach (['title' => 'wpseo_title', 'metadesc' => 'wpseo_desc', 'focuskw' => 'wpseo_focuskw', 'canonical' => 'wpseo_canonical'] as $key => $option) {
            if (isset($yoast[$option]) && is_scalar($yoast[$option])) {
                $meta['_yoast_wpseo_'.$key] = $yoast[$option];
            }
        }

        if (($yoast['wpseo_noindex'] ?? null) === 'noindex') {
            $meta['_yoast_wpseo_meta-robots-noindex'] = '1';
        }

        return $this->forPost($meta, $name, $description, $home);
    }

    private function fill(string $template, string $title, string $excerpt, string $plugin): string
    {
        $separator = $plugin === 'yoast' ? $this->yoastSeparator : $this->rankMathSeparator;
        $values = ['title' => $title, 'sitename' => $this->siteName, 'sitedesc' => $this->siteDescription, 'sep' => $separator, 'excerpt' => $excerpt];
        $pattern = $plugin === 'yoast' ? '/%%([a-z_]+)%%/' : '/%([a-z_]+)%/';

        $text = preg_replace_callback($pattern, static fn (array $match): string => $values[$match[1]] ?? '', $template) ?? '';
        $text = preg_replace('/\s+/u', ' ', $this->content->text($text)) ?? '';

        // A template whose variables came out empty leaves its separators at the ends.
        return trim($text, " \t{$separator}");
    }

    /**
     * A canonical address on the old site moves with the site, so Atrium's
     * own is right; one pointing elsewhere is kept.
     */
    private function canonical(string $url, string $home): string
    {
        $host = static fn (string $address): string => Str::chopStart(strtolower((string) parse_url($address, PHP_URL_HOST)), 'www.');

        return $url === '' || ($home !== '' && $host($url) === $host($home)) ? '' : $url;
    }
}
