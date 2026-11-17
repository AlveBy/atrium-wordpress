<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Import;

use Atrium\WordPress\Plan\Plan;

/**
 * The addresses the old site gave its posts and terms, worked out from its
 * permalink settings the way WordPress does: the structure in Settings →
 * Permalinks for posts, the page tree for pages, /{type}/{slug}/ for other
 * post types, the category and tag bases for terms, and the language in
 * front (/ru/…) or behind (?lang=ru) when WPML or Polylang said so.
 */
final class Permalinks
{
    private string $structure;

    /** @var array<int,object> ID => post row, for the page tree */
    private array $posts = [];

    /** @var array<int,object> user ID => row */
    private array $users = [];

    /** @var array<int,array<int,object>> post ID => its categories */
    private array $categories = [];

    /** @var array<int,object> term_taxonomy_id => term */
    private array $terms = [];

    /** @var array<string,array<int,object>> taxonomy => term_id => term */
    private array $byTermId = [];

    public function __construct(private readonly Plan $plan)
    {
        $this->structure = (string) $plan->source->option('permalink_structure');

        foreach ($plan->source->terms() as $term) {
            $this->terms[$term->term_taxonomy_id] = $term;
            $this->byTermId[$term->taxonomy][$term->term_id] = $term;
        }
    }

    /**
     * Rows the addresses depend on: the posts (their parents too) and,
     * when the structure has them, their categories and authors.
     *
     * @param  array<int,object>  $posts
     */
    public function remember(array $posts): void
    {
        foreach ($posts as $post) {
            $this->posts[$post->ID] = $post;
        }

        $ids = array_map(static fn (object $post): int => (int) $post->ID, $posts);

        if (str_contains($this->structure, '%category%')) {
            foreach ($this->plan->source->relationships($ids) as $object => $links) {
                foreach ($links as $link) {
                    if (($this->terms[$link]->taxonomy ?? null) === 'category') {
                        $this->categories[$object][] = $this->terms[$link];
                    }
                }
            }
        }

        if (str_contains($this->structure, '%author%')) {
            $authors = array_map(static fn (object $post): int => (int) $post->post_author, $posts);
            $this->users += $this->plan->source->users(array_values(array_unique($authors)));
        }
    }

    /**
     * The old path of a published post, or null when it had none: drafts,
     * scheduled and private posts are not on the site, and the front page
     * lives at / on both sites.
     */
    public function post(object $post, string $language): ?string
    {
        if ($post->post_status !== 'publish' || (int) $post->ID === (int) $this->plan->source->option('page_on_front')) {
            return null;
        }

        $type = $post->post_type;
        $slug = (string) $post->post_name;

        if ($this->structure === '' || $slug === '') {
            $path = '/?'.($type === 'page' ? 'page_id' : 'p').'='.$post->ID;

            return $this->withLanguage($path, $language);
        }

        $path = match ($type) {
            'post' => $this->fromStructure($post),
            'page' => '/'.$this->treePath($post).'/',
            default => '/'.$type.'/'.$this->treePath($post).'/',
        };

        return $this->withLanguage($this->trailing($path), $language);
    }

    /**
     * The old path of a term's archive.
     */
    public function term(object $term, string $language): string
    {
        $base = match ($term->taxonomy) {
            'category' => $this->plan->source->option('category_base') ?: 'category',
            'post_tag' => $this->plan->source->option('tag_base') ?: 'tag',
            default => $term->taxonomy,
        };

        if ($this->structure === '') {
            $parameter = match ($term->taxonomy) {
                'category' => 'cat='.$term->term_id,
                'post_tag' => 'tag='.$term->slug,
                default => $term->taxonomy.'='.$term->slug,
            };

            return $this->withLanguage('/?'.$parameter, $language);
        }

        return $this->withLanguage($this->trailing('/'.$base.'/'.$this->termPath($term).'/'), $language);
    }

    /**
     * The old address of every post by ID: /?p=10 and /?page_id=30 still
     * open them, whatever the permalink structure.
     */
    public static function shortLink(object $post): string
    {
        return '/?'.($post->post_type === 'page' ? 'page_id' : 'p').'='.$post->ID;
    }

    private function fromStructure(object $post): string
    {
        $date = strtotime((string) $post->post_date) ?: 0;
        $category = $this->categories[$post->ID] ?? [];
        usort($category, static fn (object $a, object $b): int => $a->term_id <=> $b->term_id);

        return strtr($this->structure, [
            '%year%' => date('Y', $date),
            '%monthnum%' => date('m', $date),
            '%day%' => date('d', $date),
            '%hour%' => date('H', $date),
            '%minute%' => date('i', $date),
            '%second%' => date('s', $date),
            '%postname%' => $post->post_name,
            '%post_id%' => $post->ID,
            '%category%' => $category === [] ? 'uncategorized' : $this->termPath($category[0]),
            '%author%' => $this->users[$post->post_author]->user_nicename ?? '',
        ]);
    }

    /**
     * Slugs of a post and its ancestors: about/team/history.
     */
    private function treePath(object $post): string
    {
        $parts = [];
        $seen = [];

        for ($cursor = $post; $cursor !== null && ! isset($seen[$cursor->ID]); $cursor = $this->posts[$cursor->post_parent] ?? null) {
            $seen[$cursor->ID] = $cursor;
            array_unshift($parts, (string) $cursor->post_name);

            if ($cursor->post_type !== $post->post_type) {
                break;
            }
        }

        return implode('/', $parts);
    }

    private function termPath(object $term): string
    {
        $parts = [];
        $seen = [];

        for ($cursor = $term; $cursor !== null && ! isset($seen[$cursor->term_id]); $cursor = $this->byTermId[$term->taxonomy][$cursor->parent] ?? null) {
            $seen[$cursor->term_id] = $cursor;
            array_unshift($parts, (string) $cursor->slug);
        }

        return implode('/', $parts);
    }

    /**
     * A structure without a slash at the end gives addresses without one.
     */
    private function trailing(string $path): string
    {
        $path = implode('/', preg_split('#/+#', $path) ?: []);

        return str_ends_with($this->structure, '/') ? $path : rtrim($path, '/');
    }

    private function withLanguage(string $path, string $language): string
    {
        $languages = $this->plan->languages;

        if ($languages->plugin() === null || $language === $languages->default()) {
            return $path;
        }

        return match ($languages->urlMode()) {
            'directory' => '/'.$language.$path,
            'parameter' => $path.(str_contains($path, '?') ? '&' : '?').'lang='.$language,
            default => $path,
        };
    }
}
