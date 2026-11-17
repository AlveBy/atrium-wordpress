<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Import;

use Atrium\WordPress\Support\Text;
use Illuminate\Support\Str;

/**
 * Texts of posts as Atrium keeps them. WordPress stores classic-editor
 * posts without paragraphs (wpautop adds them on every page view) and block
 * posts wrapped in <!-- wp:… --> comments; files are linked by the old
 * site's full address.
 */
final class Content
{
    private const BLOCK_TAGS = '/<(p|div|h[1-6]|ul|ol|table|blockquote|figure|pre|section|article|hr|dl)[\s>\/]/i';

    /** @var array<int,string> hosts of the old site */
    private array $hosts;

    public function __construct(string ...$urls)
    {
        $hosts = [];

        foreach ($urls as $url) {
            $host = parse_url($url, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $hosts[] = Str::of($host)->replaceMatches('/^www\./i', '')->value();
            }
        }

        $this->hosts = $hosts;
    }

    /**
     * The address files are served from on the new site: /wp-content/uploads.
     */
    public static function uploadsBase(): string
    {
        return '/'.trim((string) config('atrium-wordpress.uploads_target', 'wp-content/uploads'), '/');
    }

    /**
     * A post's HTML: valid UTF-8, without block comments, with paragraphs,
     * linking files at their new address.
     */
    public function html(?string $content): string
    {
        $content = Str::of(str_replace(["\r\n", "\r"], "\n", Text::clean($content)))
            ->replaceMatches('/<!--\s*\/?wp:[^>]*?-->\n?/', '')
            ->replaceMatches("/\n{3,}/", "\n\n")
            ->trim()
            ->value();

        if ($content !== '' && preg_match(self::BLOCK_TAGS, $content) !== 1) {
            $paragraphs = array_filter(array_map('trim', preg_split('/\n\s*\n/', $content) ?: []), static fn (string $p): bool => $p !== '');
            $content = implode("\n", array_map(static fn (string $p): string => '<p>'.nl2br($p, false).'</p>', $paragraphs));
        }

        return $this->localUploads($content);
    }

    /**
     * Plain text, such as a title: valid UTF-8, entities WordPress saved
     * left as they are, no surrounding spaces.
     */
    public function text(?string $value): string
    {
        return trim(Text::clean($value));
    }

    /**
     * Links to the old site's uploads point at the copied files.
     */
    public function localUploads(string $content): string
    {
        foreach ($this->hosts as $host) {
            $content = Str::of($content)->replaceMatches(
                '#(?:https?:)?//(?:www\.)?'.preg_quote($host, '#').'/wp-content/uploads/#i',
                self::uploadsBase().'/',
            )->value();
        }

        return $content;
    }

    /**
     * An address of a file on the old site as a path on the new one; an
     * address elsewhere stays as it is.
     */
    public function uploadUrl(string $url): string
    {
        $url = $this->localUploads(trim($url));

        return str_starts_with($url, '/wp-content/uploads/') ? self::uploadsBase().'/'.substr($url, 20) : $url;
    }

    /**
     * Files of the old uploads folder a text links to, relative to it.
     *
     * @return array<int,string>
     */
    public static function referencedUploads(string $html): array
    {
        $base = preg_quote(self::uploadsBase(), '#');
        preg_match_all('#'.$base.'/([^"\'\s?\#<>)]+)#', $html, $matches);

        return array_values(array_unique(array_map('rawurldecode', $matches[1])));
    }

    /**
     * A record's code from the post's slug: WordPress keeps non-Latin slugs
     * URL-encoded (%d0%bf…); Atrium codes are Latin, so they are spelled out.
     */
    public static function slug(string $postName, string $title, string $fallback): string
    {
        $slug = Str::slug(rawurldecode($postName));

        if ($slug === '') {
            $slug = Str::slug($title);
        }

        return $slug === '' ? $fallback : substr($slug, 0, 200);
    }
}
