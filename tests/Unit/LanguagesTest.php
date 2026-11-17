<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Unit;

use Atrium\WordPress\Site\Languages;
use Atrium\WordPress\Tests\Fixtures\ArraySource as Source;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LanguagesTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function codes(): array
    {
        return [
            'already a code' => ['en', 'en'],
            'a locale' => ['pt_BR', 'pt-br'],
            'upper case' => ['RU', 'ru'],
            'spaces around' => ['  de  ', 'de'],
            'an empty one' => ['', ''],
        ];
    }

    #[DataProvider('codes')]
    public function test_a_wordpress_code_is_written_the_atrium_way(string $code, string $expected): void
    {
        $this->assertSame($expected, Languages::code($code));
    }

    public function test_a_language_is_named_in_its_own_tongue(): void
    {
        $intl = extension_loaded('intl');

        $this->assertSame($intl ? 'Русский' : 'RU', Languages::name('ru'));
        $this->assertSame($intl ? 'English' : 'EN', Languages::name('en'));
        $this->assertSame($intl ? 'Deutsch' : 'DE', Languages::name('de'));
        $this->assertSame($intl ? 'Português' : 'PT-BR', Languages::name('pt-br'));
        // Nobody knows this one: its code, in capitals.
        $this->assertSame('XX', Languages::name('xx'));
    }

    public function test_without_a_language_plugin_the_site_has_the_language_of_wplang(): void
    {
        $languages = new Languages(new Source(['WPLANG' => 'ru_RU']));

        $this->assertNull($languages->plugin());
        $this->assertSame('ru', $languages->default());
        $this->assertSame(['ru'], $languages->codes());
        $this->assertSame(['ru' => Languages::name('ru')], $languages->names());
        $this->assertSame('directory', $languages->urlMode());
    }

    public function test_an_empty_wplang_is_wordpresss_own_english(): void
    {
        $this->assertSame('en', (new Languages(new Source(['WPLANG' => ''])))->default());
        $this->assertSame('en', (new Languages(new Source))->default());
    }

    public function test_a_locale_is_cut_to_its_language(): void
    {
        $this->assertSame('pt', (new Languages(new Source(['WPLANG' => 'pt_BR'])))->default());
    }

    public function test_the_config_names_the_language_of_a_site_without_a_plugin(): void
    {
        $this->assertSame('de', (new Languages(new Source(['WPLANG' => 'ru_RU']), 'de'))->default());
        $this->assertSame('pt-br', (new Languages(new Source, 'pt_BR'))->default());
        $this->assertSame('ru', (new Languages(new Source(['WPLANG' => 'ru_RU']), ''))->default(), 'an empty setting is no setting');
        $this->assertSame('ru', (new Languages(new Source(['WPLANG' => 'ru_RU']), null))->default());
    }

    public function test_a_post_outside_any_group_is_its_own_group_in_the_default_language(): void
    {
        $languages = new Languages(new Source(['WPLANG' => 'ru_RU']));

        $this->assertSame('ru', $languages->ofPost(10));
        $this->assertSame('post:10', $languages->postGroup(10));
        $term = Source::term(105, 5, 'category', 'news');
        $this->assertSame('ru', $languages->ofTerm($term));
        $this->assertSame('term:105', $languages->termGroup($term));
    }

    public function test_wpml_ties_posts_and_terms_into_groups(): void
    {
        $source = new Source(Source::wpmlSettings('en'), [
            Source::translation('post_post', 10, 1001, 'en'),
            Source::translation('post_post', 11, 1001, 'ru'),
            Source::translation('post_page', 30, 1002, 'en'),
            Source::translation('post_recipe', 40, 1003, 'en'),
            Source::translation('tax_category', 102, 2001, 'en'),
            Source::translation('tax_category', 104, 2001, 'ru'),
            Source::translation('nav_menu', 7, 3001, 'de'),
        ]);

        $languages = new Languages($source);

        $this->assertSame('wpml', $languages->plugin());
        $this->assertSame('en', $languages->default());
        $this->assertSame('en', $languages->ofPost(10));
        $this->assertSame('ru', $languages->ofPost(11));
        $this->assertSame('wpml:1001', $languages->postGroup(10));
        $this->assertSame('wpml:1001', $languages->postGroup(11));
        $this->assertSame('wpml:1002', $languages->postGroup(30));
        $this->assertSame('wpml:1003', $languages->postGroup(40));
        $this->assertSame('post:99', $languages->postGroup(99));
        $this->assertSame('en', $languages->ofPost(99));

        // Terms are found by term_taxonomy_id, not term_id.
        $news = Source::term(102, 2, 'category', 'news');
        $novosti = Source::term(104, 4, 'category', 'novosti');
        $this->assertSame('wpml:2001', $languages->termGroup($news));
        $this->assertSame('ru', $languages->ofTerm($novosti));
        $other = Source::term(2, 102, 'category', 'other');
        $this->assertSame('en', $languages->ofTerm($other), 'term_id 102 is not term_taxonomy_id 102');
        $this->assertSame('term:2', $languages->termGroup($other));

        // What is neither a post nor a term of a taxonomy names a language and nothing more.
        $this->assertSame('de', Languages::code('de'));
        $this->assertContains('de', $languages->codes());
        $this->assertSame('en', $languages->ofPost(7));
    }

    public function test_wpmls_default_language_wins_over_wplang_and_comes_first(): void
    {
        $source = new Source(['WPLANG' => 'ru_RU', ...Source::wpmlSettings('uk')], [
            Source::translation('post_post', 11, 1001, 'ru'),
            Source::translation('post_post', 10, 1001, 'uk'),
            Source::translation('post_post', 12, 1001, 'en'),
        ]);

        $languages = new Languages($source);

        $this->assertSame('uk', $languages->default());
        $this->assertSame(['uk', 'ru', 'en'], $languages->codes());
    }

    public function test_the_default_language_is_listed_even_when_no_translation_has_it(): void
    {
        $source = new Source(Source::wpmlSettings('en'), [Source::translation('post_post', 11, 1001, 'ru')]);

        $this->assertSame(['en', 'ru'], (new Languages($source))->codes());
    }

    public function test_wpml_codes_are_written_the_atrium_way(): void
    {
        $source = new Source(Source::wpmlSettings('pt_BR'), [Source::translation('post_post', 10, 1, 'pt_BR'), Source::translation('post_post', 11, 1, 'zh_CN')]);

        $languages = new Languages($source);

        $this->assertSame('pt-br', $languages->default());
        $this->assertSame(['pt-br', 'zh-cn'], $languages->codes());
        $this->assertSame('zh-cn', $languages->ofPost(11));
    }

    /**
     * @return array<string,array{0:int|null,1:string}>
     */
    public static function wpmlNegotiation(): array
    {
        return [
            'folders' => [1, 'directory'],
            'a domain per language' => [2, 'domain'],
            'a parameter' => [3, 'parameter'],
            'not set' => [null, 'directory'],
            'something unknown' => [9, 'directory'],
        ];
    }

    #[DataProvider('wpmlNegotiation')]
    public function test_wpml_tells_how_languages_show_in_addresses(?int $type, string $mode): void
    {
        $settings = ['default_language' => 'en'] + ($type === null ? [] : ['language_negotiation_type' => $type]);
        $source = new Source(['icl_sitepress_settings' => serialize($settings)], [Source::translation('post_post', 10, 1, 'en')]);

        $this->assertSame($mode, (new Languages($source))->urlMode());
    }

    public function test_wpml_may_keep_the_way_of_telling_languages_as_text(): void
    {
        $settings = ['default_language' => 'en', 'language_negotiation_type' => '3'];
        $source = new Source(['icl_sitepress_settings' => serialize($settings)], [Source::translation('post_post', 10, 1, 'en')]);

        $this->assertSame('parameter', (new Languages($source))->urlMode());
    }

    public function test_a_translation_without_an_element_is_the_element_zero(): void
    {
        $source = new Source(Source::wpmlSettings('en'), [
            (object) ['element_type' => 'post_post', 'element_id' => null, 'trid' => 7, 'language_code' => 'ru'],
            (object) ['element_type' => 'tax_category', 'element_id' => null, 'trid' => 8, 'language_code' => 'de'],
        ]);

        $languages = new Languages($source);

        $this->assertSame(['ru', 'wpml:7'], [$languages->ofPost(0), $languages->postGroup(0)]);
        $this->assertSame(['de', 'wpml:8'], [$languages->ofTerm(Source::term(0, 0, 'category', 'x')), $languages->termGroup(Source::term(0, 0, 'category', 'x'))]);
    }

    public function test_wpml_with_broken_settings_falls_back_to_wplang(): void
    {
        $source = new Source(['WPLANG' => 'ru_RU', 'icl_sitepress_settings' => 'not serialized'], [Source::translation('post_post', 10, 1, 'ru')]);
        $languages = new Languages($source);

        $this->assertSame('wpml', $languages->plugin());
        $this->assertSame('ru', $languages->default());
        $this->assertSame('directory', $languages->urlMode());

        $empty = new Languages(new Source(['WPLANG' => 'ru_RU', 'icl_sitepress_settings' => serialize(['default_language' => ''])], [Source::translation('post_post', 10, 1, 'ru')]));
        $this->assertSame('ru', $empty->default(), 'an empty default is none');

        $notText = new Languages(new Source(['WPLANG' => 'ru_RU', 'icl_sitepress_settings' => serialize(['default_language' => 5])], [Source::translation('post_post', 10, 1, 'ru')]));
        $this->assertSame('ru', $notText->default());
    }

    public function test_wpml_without_rows_is_not_wpml(): void
    {
        $languages = new Languages(new Source(Source::wpmlSettings('de'), []));

        $this->assertNull($languages->plugin());
        $this->assertSame('en', $languages->default());
    }

    /**
     * @param  array<string,mixed>  $settings
     */
    private static function polylang(array $settings = ['default_lang' => 'en', 'force_lang' => 1]): Source
    {
        $en = Source::term(110, 10, 'language', 'en', 'English');
        $ru = Source::term(111, 11, 'language', 'ru', 'Русский');
        $termsEn = Source::term(112, 12, 'term_language', 'pll_en');
        $termsRu = Source::term(113, 13, 'term_language', 'pll_ru');
        $posts = Source::term(120, 20, 'post_translations', 'pll_aaa', description: serialize(['en' => 10, 'ru' => 11]));
        $category = Source::term(114, 14, 'term_translations', 'pll_bbb', description: serialize(['en' => 2, 'ru' => 4]));
        $news = Source::term(102, 2, 'category', 'news');
        $novosti = Source::term(104, 4, 'category', 'novosti');

        return new Source(
            ['polylang' => serialize($settings)],
            null,
            [
                'language' => [10 => 110, 11 => 111, 12 => 110, 30 => 110],
                'term_language' => [2 => 112, 4 => 113],
            ],
            [$en, $ru, $termsEn, $termsRu, $posts, $category, $news, $novosti],
        );
    }

    public function test_polylang_is_found_by_the_terms_of_its_language_taxonomy(): void
    {
        $languages = new Languages(self::polylang());

        $this->assertSame('polylang', $languages->plugin());
        $this->assertSame('en', $languages->default());
        // The names are the ones Polylang saved.
        $this->assertSame(['en' => 'English', 'ru' => 'Русский'], $languages->names());
        $this->assertSame(['en', 'ru'], $languages->codes());
    }

    public function test_polylang_groups_posts_by_their_translation_term(): void
    {
        $languages = new Languages(self::polylang());

        $this->assertSame('en', $languages->ofPost(10));
        $this->assertSame('ru', $languages->ofPost(11));
        $this->assertSame('polylang:120', $languages->postGroup(10));
        $this->assertSame('polylang:120', $languages->postGroup(11));
        // 12 has a language and no translations: a group of its own.
        $this->assertSame('en', $languages->ofPost(12));
        $this->assertSame('posts:12', $languages->postGroup(12));
        $this->assertSame('posts:30', $languages->postGroup(30));
        // 99 has no language at all.
        $this->assertSame('en', $languages->ofPost(99));
        $this->assertSame('post:99', $languages->postGroup(99));
    }

    public function test_polylang_finds_terms_by_term_id(): void
    {
        $languages = new Languages(self::polylang());
        $news = Source::term(102, 2, 'category', 'news');
        $novosti = Source::term(104, 4, 'category', 'novosti');

        $this->assertSame('en', $languages->ofTerm($news));
        $this->assertSame('ru', $languages->ofTerm($novosti));
        $this->assertSame('polylang:114', $languages->termGroup($news));
        $this->assertSame('polylang:114', $languages->termGroup($novosti));
        // Not term_taxonomy_id: 102 is no term_id of anything.
        $this->assertSame('term:2', $languages->termGroup(Source::term(2, 102, 'category', 'x')));
    }

    /**
     * @return array<string,array{0:int|null,1:string}>
     */
    public static function polylangNegotiation(): array
    {
        return [
            'a parameter' => [0, 'parameter'],
            'folders' => [1, 'directory'],
            'subdomains' => [2, 'domain'],
            'domains' => [3, 'domain'],
            'not set' => [null, 'directory'],
            'a parameter, saved as text' => ['0', 'parameter'],
            'subdomains, saved as text' => ['2', 'domain'],
            'domains, saved as text' => ['3', 'domain'],
        ];
    }

    #[DataProvider('polylangNegotiation')]
    public function test_polylang_tells_how_languages_show_in_addresses(int|string|null $force, string $mode): void
    {
        $settings = ['default_lang' => 'en'] + ($force === null ? [] : ['force_lang' => $force]);

        $this->assertSame($mode, (new Languages(self::polylang($settings)))->urlMode());
    }

    public function test_a_name_a_slug_or_a_description_that_is_not_text_is_read_as_text(): void
    {
        $en = Source::term(110, 10, 'language', 'en');
        $en->name = 7;
        $seven = Source::term(111, 11, 'language', 'x');
        $seven->slug = 7;
        $group = Source::term(120, 20, 'post_translations', 'pll_aaa');
        $group->description = 5;

        $languages = new Languages(new Source(['polylang' => serialize(['default_lang' => 'en'])], null, ['language' => [10 => 110, 11 => 111]], [$en, $seven, $group]));

        $this->assertSame('7', $languages->names()['en']);
        $this->assertSame('7', $languages->ofPost(11));
    }

    public function test_polylangs_default_language_wins_over_wplang(): void
    {
        $this->assertSame('ru', (new Languages(self::polylang(['default_lang' => 'ru'])))->default());
        $this->assertSame(['ru', 'en'], (new Languages(self::polylang(['default_lang' => 'ru'])))->codes());
        $this->assertSame('en', (new Languages(self::polylang(['default_lang' => ''])))->default());
        $this->assertSame('en', (new Languages(self::polylang(['default_lang' => 7])))->default());
    }

    public function test_polylang_with_unreadable_settings_keeps_the_default_of_wplang(): void
    {
        $source = new Source(['WPLANG' => 'ru_RU', 'polylang' => 'broken'], null, ['language' => [10 => 110]], [Source::term(110, 10, 'language', 'ru', 'Русский')]);

        $languages = new Languages($source);

        $this->assertSame('polylang', $languages->plugin());
        $this->assertSame('ru', $languages->default());
    }

    public function test_wpml_wins_when_both_leave_traces(): void
    {
        $source = new Source(Source::wpmlSettings('en'), [Source::translation('post_post', 10, 1, 'en')], ['language' => [10 => 110]], [Source::term(110, 10, 'language', 'en')]);

        $this->assertSame('wpml', (new Languages($source))->plugin());
    }
}
