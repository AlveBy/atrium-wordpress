<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Import;

use Atrium\WordPress\Support\Text;
use Closure;
use Illuminate\Support\Str;

/**
 * Texts of posts as Atrium keeps them. WordPress stores classic-editor
 * posts without paragraphs (wpautop adds them on every page view) and block
 * posts wrapped in <!-- wp:… --> comments; files are linked by the old
 * site's full address.
 */
final class Content
{
    /** Tags that make a block of their own, as wpautop knows them. */
    private const BLOCKS = 'p|div|h[1-6]|ul|ol|li|dl|dt|dd|table|thead|tbody|tfoot|tr|td|th|caption|blockquote|figure|figcaption|pre|section|article|aside|header|footer|nav|details|summary|form|fieldset|legend|address|hr|style|script';

    /** Elements WordPress leaves untouched inside. */
    private const RAW = '#<(pre|script|style|textarea)\b.*?</\1>#is';

    /** @var array<int,string> hosts of the old site, with the port where the address has one */
    private array $hosts;

    public function __construct(string ...$urls)
    {
        $hosts = [];

        foreach ($urls as $url) {
            $host = parse_url($url, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $port = parse_url($url, PHP_URL_PORT);
                $hosts[] = Str::of($host)->replaceMatches('/^www\./i', '')->value().(is_int($port) ? ':'.$port : '');
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
     * captions and galleries made into figures, linking files at their new
     * address.
     *
     * @param  (Closure(array<int,int>):array<int,array{url:string,alt:string}>)|null  $images  the pictures of a gallery by their IDs, the post's own for none; without it galleries stay as written
     */
    public function html(?string $content, ?Closure $images = null): string
    {
        $content = Str::of(str_replace(["\r\n", "\r", "\x01", "\x02"], ["\n", "\n", '', ''], Text::clean($content)))
            ->replaceMatches('/<!--\s*\/?wp:[^>]*?-->\n?/', '')
            ->replaceMatches("/\n{3,}/", "\n\n")
            ->trim()
            ->value();

        return $this->localUploads($this->paragraphs($this->shortcodes($content, $images)));
    }

    /**
     * wpautop: text between blocks goes into paragraphs, a line break inside
     * it becomes <br>. A text of blocks only is left as it is.
     */
    private function paragraphs(string $content): string
    {
        $kept = [];
        $marked = Str::of($content)->replaceMatches(self::RAW, static function (array $match) use (&$kept): string {
            $kept[] = $match[0];

            return "\x01\x02".(count($kept) - 1)."\x02\x01";
        })->value();

        // \x01 marks where a block starts or ends; <!--more--> is a block of its own.
        $marked = Str::of($marked)
            ->replaceMatches('#<(?:'.self::BLOCKS.')[\s>/]|<!--more\b.*?-->#is', "\x01$0")
            ->replaceMatches('#</(?:'.self::BLOCKS.')>|<!--more\b.*?-->#is', "\x01$0\x01")
            ->replaceMatches('#<hr(?=[\s>/])[^>]*>#i', "$0\x01")
            ->value();
        $made = false;
        $text = '';

        foreach (preg_split('/(\x01|\n\s*\n)/', $marked, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $index => $part) {
            if ($index % 2 === 1) {
                $text .= $part === "\x01" ? '' : "\n";

                continue;
            }

            $part = trim($part);

            if ($part !== '' && preg_match('#^(?:</?(?:'.self::BLOCKS.')[\s>/]|<!--.*-->$|\x02\d+\x02$)#is', $part) !== 1) {
                $part = '<p>'.nl2br($part, false).'</p>';
                $made = true;
            }

            $text .= $part;
        }

        if (! $made) {
            return $content;
        }

        // A paragraph of nothing visible leaves two separators side by side.
        return Str::of($text)
            ->replaceMatches("/\n{2,}/", "\n")
            ->replaceMatches('/\x02(\d+)\x02/', static fn (array $m): string => $kept[$m[1]])
            ->value();
    }

    /**
     * [caption] and [gallery] as the figures they stand for; other shortcodes
     * are left as written.
     *
     * @param  (Closure(array<int,int>):array<int,array{url:string,alt:string}>)|null  $images
     */
    private function shortcodes(string $content, ?Closure $images): string
    {
        $content = Str::of($content)->replaceMatches('#\[caption\b([^\]]*)\](.*?)\[/caption\]#is', static function (array $match): string {
            $attributes = self::attributes($match[1]);
            $picture = preg_match('#^\s*((?:<a\b[^>]*>\s*)?<img\b[^>]*>(?:\s*</a>)?)\s*(.*?)\s*$#is', $match[2], $parts) === 1 ? $parts : [1 => '', 2 => $match[2]];
            $caption = trim($attributes['caption'] ?? $picture[2]);

            return '<figure>'.$picture[1].($caption === '' ? '' : '<figcaption>'.$caption.'</figcaption>').'</figure>';
        })->value();

        if ($images === null) {
            return $content;
        }

        return Str::of($content)->replaceMatches('#\[gallery\b([^\]]*)\]#i', static function (array $match) use ($images): string {
            $attributes = self::attributes($match[1]);
            $ids = array_values(array_filter(array_map('intval', explode(',', $attributes['ids'] ?? $attributes['include'] ?? ''))));

            return implode("\n", array_map(
                static fn (array $image): string => '<figure><img src="'.e($image['url']).'" alt="'.e($image['alt']).'"></figure>',
                $images($ids),
            ));
        })->value();
    }

    /**
     * The name="value" pairs of a shortcode.
     *
     * @return array<string,string>
     */
    private static function attributes(string $text): array
    {
        preg_match_all('/([a-z_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $text, $matches, PREG_SET_ORDER);

        $attributes = [];

        foreach ($matches as $match) {
            $attributes[strtolower($match[1])] = ($match[3] ?? '') !== '' ? $match[3] : $match[2];
        }

        return $attributes;
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
