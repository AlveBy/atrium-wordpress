<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Unit;

use Atrium\WordPress\Import\Content;
use Atrium\WordPress\Import\Seo;
use Atrium\WordPress\Tests\Fixtures\ArraySource;
use Atrium\WordPress\Tests\LightTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SeoTest extends LightTestCase
{
    private const HOME = 'https://old.example.com';

    /**
     * @param  array<string,mixed>  $titles  Yoast's wpseo_titles
     * @param  array<string,mixed>|null  $rankMath  Rank Math's title settings
     * @param  array<string,mixed>|null  $terms  Yoast's term SEO
     * @param  array<string,string>  $extra
     */
    private function seo(array $titles = [], ?array $rankMath = null, ?array $terms = null, array $extra = []): Seo
    {
        $options = ['blogname' => 'Synthetic Blog', 'blogdescription' => 'Just an invented site', ...$extra];

        if ($titles !== []) {
            $options['wpseo_titles'] = serialize($titles);
        }

        if ($rankMath !== null) {
            $options['rank-math-options-titles'] = serialize($rankMath);
        }

        if ($terms !== null) {
            $options['wpseo_taxonomy_meta'] = serialize($terms);
        }

        return new Seo(new ArraySource($options), new Content(self::HOME));
    }

    /**
     * @param  array<string,string>  $meta
     * @return array<string,string>
     */
    private function forPost(Seo $seo, array $meta, string $title = 'The Title', string $excerpt = 'The excerpt'): array
    {
        return $seo->forPost($meta, $title, $excerpt, self::HOME);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function separators(): array
    {
        return [
            'dash' => ['sc-dash', '-'],
            'en dash' => ['sc-ndash', '–'],
            'em dash' => ['sc-mdash', '—'],
            'colon' => ['sc-colon', ':'],
            'middle dot' => ['sc-middot', '·'],
            'bullet' => ['sc-bull', '•'],
            'star' => ['sc-star', '*'],
            'small star' => ['sc-smstar', '⋆'],
            'pipe' => ['sc-pipe', '|'],
            'tilde' => ['sc-tilde', '~'],
            'left angle quote' => ['sc-laquo', '«'],
            'right angle quote' => ['sc-raquo', '»'],
            'less than' => ['sc-lt', '<'],
            'greater than' => ['sc-gt', '>'],
            'unknown' => ['sc-whatever', '-'],
        ];
    }

    #[DataProvider('separators')]
    public function test_yoasts_separator_is_the_one_the_site_chose(string $name, string $separator): void
    {
        $seo = $this->seo(['separator' => $name]);

        $values = $this->forPost($seo, ['_yoast_wpseo_title' => '%%title%% %%sep%% %%sitename%%']);

        $this->assertSame("The Title {$separator} Synthetic Blog", $values['title']);
    }

    public function test_without_a_separator_setting_it_is_a_dash(): void
    {
        $this->assertSame('The Title - Synthetic Blog', $this->forPost($this->seo(), ['_yoast_wpseo_title' => '%%title%% %%sep%% %%sitename%%'])['title']);
        $this->assertSame('The Title - Synthetic Blog', $this->forPost($this->seo(['separator' => 7]), ['_yoast_wpseo_title' => '%%title%% %%sep%% %%sitename%%'])['title']);
    }

    public function test_every_yoast_variable_is_filled_in(): void
    {
        $values = $this->forPost($this->seo(), ['_yoast_wpseo_title' => '[%%title%%|%%sitename%%|%%sitedesc%%|%%sep%%|%%excerpt%%]']);

        $this->assertSame('[The Title|Synthetic Blog|Just an invented site|-|The excerpt]', $values['title']);
    }

    public function test_an_unknown_variable_is_nothing(): void
    {
        $values = $this->forPost($this->seo(), ['_yoast_wpseo_title' => 'A %%page%% B %%unknown_thing%% C']);

        $this->assertSame('A B C', $values['title']);
    }

    public function test_extra_spaces_collapse(): void
    {
        $values = $this->forPost($this->seo(), ['_yoast_wpseo_title' => "  A   \t  B\n C  "]);

        $this->assertSame('A B C', $values['title']);
    }

    public function test_a_separator_left_at_an_end_by_empty_variables_goes(): void
    {
        $seo = new Seo(new ArraySource(['blogname' => '']), new Content(self::HOME));

        $this->assertSame('The Title', $this->forPost($seo, ['_yoast_wpseo_title' => '%%title%% %%sep%% %%sitename%%'])['title']);
        $this->assertSame('The Title', $this->forPost($seo, ['_yoast_wpseo_title' => '%%sitename%% %%sep%% %%title%%'])['title']);
        $this->assertSame('A - B', $this->forPost($seo, ['_yoast_wpseo_title' => 'A %%sep%% B'])['title']);
        $this->assertArrayNotHasKey('title', $this->forPost($seo, ['_yoast_wpseo_title' => '%%sitename%%']));
        $this->assertArrayNotHasKey('title', $this->forPost($seo, ['_yoast_wpseo_title' => '%%sep%%']));
    }

    public function test_a_title_ending_in_a_different_character_than_the_separator_is_kept(): void
    {
        $seo = $this->seo(['separator' => 'sc-pipe']);

        $this->assertSame('A - B', $this->forPost($seo, ['_yoast_wpseo_title' => 'A - B'])['title']);
    }

    public function test_rank_math_has_its_own_variables_and_separator(): void
    {
        $seo = $this->seo(['separator' => 'sc-pipe'], ['title_separator' => '•']);

        $values = $this->forPost($seo, ['rank_math_title' => '%title% %sep% %sitename% / %sitedesc% / %excerpt%']);

        $this->assertSame('The Title • Synthetic Blog / Just an invented site / The excerpt', $values['title']);
    }

    public function test_rank_math_without_a_setting_uses_a_dash_not_yoasts_separator(): void
    {
        $seo = $this->seo(['separator' => 'sc-pipe']);

        $this->assertSame('The Title - Synthetic Blog', $this->forPost($seo, ['rank_math_title' => '%title% %sep% %sitename%'])['title']);
    }

    public function test_a_template_of_one_plugin_is_not_read_with_the_other_ones_syntax(): void
    {
        $this->assertSame('%title% - Synthetic Blog', $this->forPost($this->seo(), ['_yoast_wpseo_title' => '%title% %%sep%% %%sitename%%'])['title']);
        $this->assertSame('%The Title% - Synthetic Blog', $this->forPost($this->seo(), ['rank_math_title' => '%%title%% %sep% %sitename%'])['title']);
    }

    public function test_yoast_comes_first_and_rank_math_fills_in(): void
    {
        $seo = $this->seo();

        $both = $this->forPost($seo, [
            '_yoast_wpseo_title' => 'Y title', 'rank_math_title' => 'R title',
            '_yoast_wpseo_metadesc' => 'Y desc', 'rank_math_description' => 'R desc',
            '_yoast_wpseo_focuskw' => 'y-kw', 'rank_math_focus_keyword' => 'r-kw',
            '_yoast_wpseo_opengraph-title' => 'Y og', 'rank_math_facebook_title' => 'R og',
            '_yoast_wpseo_opengraph-description' => 'Y ogd', 'rank_math_facebook_description' => 'R ogd',
            '_yoast_wpseo_opengraph-image' => '/wp-content/uploads/y.jpg', 'rank_math_facebook_image' => '/wp-content/uploads/r.jpg',
            '_yoast_wpseo_canonical' => 'https://elsewhere.test/y', 'rank_math_canonical_url' => 'https://elsewhere.test/r',
        ]);

        $this->assertSame([
            'title' => 'Y title', 'description' => 'Y desc', 'keywords' => 'y-kw', 'canonical_url' => 'https://elsewhere.test/y',
            'og_title' => 'Y og', 'og_description' => 'Y ogd', 'og_image' => '/wp-content/uploads/y.jpg',
        ], $both);

        $rank = $this->forPost($seo, [
            '_yoast_wpseo_title' => '', 'rank_math_title' => 'R title',
            '_yoast_wpseo_metadesc' => '  ', 'rank_math_description' => 'R desc',
            'rank_math_focus_keyword' => 'r-kw',
            'rank_math_facebook_title' => 'R og', 'rank_math_facebook_description' => 'R ogd',
            'rank_math_facebook_image' => 'https://old.example.com/wp-content/uploads/r.jpg',
            'rank_math_canonical_url' => 'https://elsewhere.test/r',
        ]);

        $this->assertSame([
            'title' => 'R title', 'description' => 'R desc', 'keywords' => 'r-kw', 'canonical_url' => 'https://elsewhere.test/r',
            'og_title' => 'R og', 'og_description' => 'R ogd', 'og_image' => '/wp-content/uploads/r.jpg',
        ], $rank);
    }

    public function test_a_title_made_of_nothing_falls_back_to_the_other_plugin(): void
    {
        $values = $this->forPost($this->seo(), ['_yoast_wpseo_title' => '%%sitename%%x'.'', 'rank_math_title' => 'R']);
        $this->assertSame('Synthetic Blogx', $values['title']);

        $seo = new Seo(new ArraySource, new Content(self::HOME));
        $values = $this->forPost($seo, ['_yoast_wpseo_title' => '%%sitename%%', 'rank_math_title' => 'R']);
        $this->assertSame('R', $values['title']);
    }

    public function test_values_are_trimmed_and_the_empty_ones_left_out(): void
    {
        $values = $this->forPost($this->seo(), [
            '_yoast_wpseo_metadesc' => "  spaced out \n",
            '_yoast_wpseo_focuskw' => '  kw  ',
            '_yoast_wpseo_canonical' => '   ',
            '_yoast_wpseo_opengraph-image' => '   ',
        ]);

        $this->assertSame(['description' => 'spaced out', 'keywords' => 'kw'], $values);
        $this->assertSame([], $this->forPost($this->seo(), []));
        $this->assertSame([], $this->forPost($this->seo(), ['_edit_lock' => '1', 'rank_math_unknown' => 'x']));
    }

    public function test_titles_are_cut_at_255_characters_and_descriptions_are_not(): void
    {
        $long = str_repeat('я', 300);

        $values = $this->forPost($this->seo(), [
            '_yoast_wpseo_title' => $long,
            '_yoast_wpseo_opengraph-title' => $long,
            '_yoast_wpseo_metadesc' => $long,
            '_yoast_wpseo_opengraph-description' => $long,
        ]);

        $this->assertSame(str_repeat('я', 255), $values['title']);
        $this->assertSame(str_repeat('я', 255), $values['og_title']);
        $this->assertSame($long, $values['description']);
        $this->assertSame($long, $values['og_description']);

        $exact = $this->forPost($this->seo(), ['_yoast_wpseo_title' => str_repeat('a', 255), '_yoast_wpseo_opengraph-title' => str_repeat('a', 254)]);
        $this->assertSame(255, mb_strlen($exact['title']));
        $this->assertSame(254, mb_strlen($exact['og_title']));
    }

    public function test_a_canonical_address_on_the_old_site_is_dropped(): void
    {
        $seo = $this->seo();

        $this->assertArrayNotHasKey('canonical_url', $this->forPost($seo, ['_yoast_wpseo_canonical' => 'https://old.example.com/2024/05/hello-world/']));
        $this->assertArrayNotHasKey('canonical_url', $this->forPost($seo, ['_yoast_wpseo_canonical' => 'http://OLD.example.com/x']));
        $this->assertArrayNotHasKey('canonical_url', $this->forPost($seo, ['_yoast_wpseo_canonical' => 'https://www.old.example.com/x']));
        $this->assertArrayNotHasKey('canonical_url', $this->forPost($seo, ['rank_math_canonical_url' => 'https://old.example.com/x']));
        $this->assertSame('https://elsewhere.test/x', $this->forPost($seo, ['_yoast_wpseo_canonical' => 'https://elsewhere.test/x'])['canonical_url']);
        $this->assertSame('https://sub.old.example.com/x', $this->forPost($seo, ['_yoast_wpseo_canonical' => 'https://sub.old.example.com/x'])['canonical_url']);
    }

    public function test_the_home_address_is_compared_without_www(): void
    {
        $values = $this->seo()->forPost(['_yoast_wpseo_canonical' => 'https://old.example.com/x'], 'T', '', 'https://www.old.example.com');

        $this->assertArrayNotHasKey('canonical_url', $values);
    }

    public function test_without_a_home_address_every_canonical_is_kept(): void
    {
        $values = $this->seo()->forPost(['_yoast_wpseo_canonical' => 'https://old.example.com/x'], 'T', '', '');

        $this->assertSame('https://old.example.com/x', $values['canonical_url']);
    }

    public function test_an_open_graph_image_is_rewritten_to_the_copied_file(): void
    {
        $seo = $this->seo();

        $this->assertSame('/wp-content/uploads/2024/05/photo.jpg', $this->forPost($seo, ['_yoast_wpseo_opengraph-image' => 'https://old.example.com/wp-content/uploads/2024/05/photo.jpg'])['og_image']);
        $this->assertSame('https://cdn.other.test/a.jpg', $this->forPost($seo, ['_yoast_wpseo_opengraph-image' => 'https://cdn.other.test/a.jpg'])['og_image']);
    }

    /**
     * @return array<string,array{0:array<string,string>,1:?string}>
     */
    public static function robots(): array
    {
        return [
            'nothing' => [[], null],
            'Yoast noindex' => [['_yoast_wpseo_meta-robots-noindex' => '1'], 'noindex'],
            'Yoast nofollow' => [['_yoast_wpseo_meta-robots-nofollow' => '1'], 'nofollow'],
            'Yoast both' => [['_yoast_wpseo_meta-robots-noindex' => '1', '_yoast_wpseo_meta-robots-nofollow' => '1'], 'noindex, nofollow'],
            'Yoast default (0)' => [['_yoast_wpseo_meta-robots-noindex' => '0', '_yoast_wpseo_meta-robots-nofollow' => '0'], null],
            'Yoast 2 is not 1' => [['_yoast_wpseo_meta-robots-noindex' => '2'], null],
            'Rank Math noindex' => [['rank_math_robots' => 'a:1:{i:0;s:7:"noindex";}'], 'noindex'],
            'Rank Math nofollow' => [['rank_math_robots' => 'a:1:{i:0;s:8:"nofollow";}'], 'nofollow'],
            'Rank Math both' => [['rank_math_robots' => 'a:2:{i:0;s:7:"noindex";i:1;s:8:"nofollow";}'], 'noindex, nofollow'],
            'Rank Math index, follow' => [['rank_math_robots' => 'a:2:{i:0;s:5:"index";i:1;s:6:"follow";}'], null],
            'Rank Math noarchive is not ours' => [['rank_math_robots' => 'a:1:{i:0;s:9:"noarchive";}'], null],
            'Yoast noindex, Rank Math nofollow' => [['_yoast_wpseo_meta-robots-noindex' => '1', 'rank_math_robots' => 'a:1:{i:0;s:8:"nofollow";}'], 'noindex, nofollow'],
            'Rank Math as plain text' => [['rank_math_robots' => 'noindex'], 'noindex'],
        ];
    }

    /**
     * @param  array<string,string>  $meta
     */
    #[DataProvider('robots')]
    public function test_robots_come_from_either_plugin(array $meta, ?string $expected): void
    {
        $values = $this->forPost($this->seo(), $meta);

        $this->assertSame($expected, $values['robots'] ?? null);
    }

    public function test_a_terms_seo_comes_from_yoasts_option(): void
    {
        $seo = $this->seo(terms: ['category' => [
            2 => [
                'wpseo_title' => 'All the news %%sep%% %%sitename%%',
                'wpseo_desc' => 'News of the blog.',
                'wpseo_focuskw' => 'news',
                'wpseo_canonical' => 'https://elsewhere.test/news',
                'wpseo_noindex' => 'noindex',
            ],
            3 => ['wpseo_title' => 'Local'],
        ], 'post_tag' => [2 => ['wpseo_title' => 'A tag']]]);

        $values = $seo->forTerm('category', 2, [], 'News', 'About news', self::HOME);

        $this->assertSame([
            'title' => 'All the news - Synthetic Blog',
            'description' => 'News of the blog.',
            'keywords' => 'news',
            'canonical_url' => 'https://elsewhere.test/news',
            'robots' => 'noindex',
        ], $values);
        $this->assertSame(['title' => 'Local'], $seo->forTerm('category', 3, [], 'Local', '', self::HOME));
        $this->assertSame(['title' => 'A tag'], $seo->forTerm('post_tag', 2, [], 'Tag', '', self::HOME));
        $this->assertSame([], $seo->forTerm('category', 4, [], 'Other', '', self::HOME));
        $this->assertSame([], $seo->forTerm('cuisine', 2, [], 'Other', '', self::HOME));
    }

    public function test_a_term_not_set_to_noindex_stays_indexed(): void
    {
        $seo = $this->seo(terms: ['category' => [2 => ['wpseo_noindex' => 'default', 'wpseo_title' => 'T']]]);

        $this->assertSame(['title' => 'T'], $seo->forTerm('category', 2, [], 'News', '', self::HOME));
    }

    public function test_a_term_value_that_is_not_text_is_ignored(): void
    {
        $seo = $this->seo(terms: ['category' => [2 => ['wpseo_title' => ['not', 'text'], 'wpseo_desc' => 'Fine']]]);

        $this->assertSame(['description' => 'Fine'], $seo->forTerm('category', 2, [], 'News', '', self::HOME));
    }

    public function test_a_terms_name_and_description_fill_the_templates(): void
    {
        $seo = $this->seo(terms: ['category' => [2 => ['wpseo_title' => '%%title%% / %%excerpt%%']]]);

        $this->assertSame(['title' => 'News / About news'], $seo->forTerm('category', 2, [], 'News', 'About news', self::HOME));
    }

    public function test_rank_math_keeps_a_terms_seo_in_its_meta(): void
    {
        $values = $this->seo()->forTerm('post_tag', 5, ['rank_math_title' => '%title% %sep% %sitename%', 'rank_math_description' => 'Everything.'], 'Laravel', '', self::HOME);

        $this->assertSame(['title' => 'Laravel - Synthetic Blog', 'description' => 'Everything.'], $values);
    }

    public function test_yoast_wins_over_rank_math_for_a_term(): void
    {
        $seo = $this->seo(terms: ['category' => [2 => ['wpseo_title' => 'Y']]]);

        $this->assertSame('Y', $seo->forTerm('category', 2, ['rank_math_title' => 'R'], 'News', '', self::HOME)['title']);
    }

    public function test_without_yoasts_term_option_nothing_breaks(): void
    {
        $this->assertSame([], $this->seo()->forTerm('category', 2, [], 'News', '', self::HOME));
    }

    public function test_rank_maths_separator_may_be_a_number(): void
    {
        $seo = $this->seo(rankMath: ['title_separator' => 5]);

        $this->assertSame('The Title 5 Synthetic Blog', $this->forPost($seo, ['rank_math_title' => '%title% %sep% %sitename%'])['title']);
    }

    public function test_rank_math_values_lose_the_spaces_around_them(): void
    {
        $values = $this->forPost($this->seo(), [
            'rank_math_focus_keyword' => '  blocks  ',
            'rank_math_canonical_url' => "  https://cdn.other.test/page \n",
            'rank_math_facebook_image' => '  https://cdn.other.test/a.jpg  ',
        ]);

        $this->assertSame('blocks', $values['keywords']);
        $this->assertSame('https://cdn.other.test/page', $values['canonical_url']);
        $this->assertSame('https://cdn.other.test/a.jpg', $values['og_image']);
    }
}
