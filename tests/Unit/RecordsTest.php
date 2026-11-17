<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Unit;

use Atrium\WordPress\Import\Records;
use Atrium\WordPress\Plan\Type;
use Atrium\WordPress\Tests\Concerns\ImportsWordPress;
use Atrium\WordPress\Tests\Fixtures\SpySource;
use Atrium\WordPress\Tests\Fixtures\SyntheticWordPress as Wp;
use Atrium\WordPress\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What every post, term, author and file of the made-up site must become,
 * and how each kind of custom field is converted.
 */
final class RecordsTest extends TestCase
{
    use ImportsWordPress;

    private const BOOK = 450;

    private Records $records;

    private ?bool $site = null;

    private function installOnce(): bool
    {
        $this->site();

        return true;
    }

    private function load(string $languages = 'wpml'): void
    {
        $this->site($languages);
        $this->records = $this->records();
    }

    /**
     * The notes without the one the fixture's own broken attachment always gives.
     *
     * @return array<int,string>
     */
    private function notes(): array
    {
        return array_values(array_filter(
            $this->records->notes(),
            static fn (string $note): bool => ! str_starts_with($note, 'the sizes of attachment 50 '),
        ));
    }

    private function type(string $name): Type
    {
        return $this->records->plan->types[$name];
    }

    /**
     * @return array<int,array<string,mixed>> main post ID => record
     */
    private function all(string $type): array
    {
        $all = [];

        foreach ($this->records->records($this->type($type)) as $chunk) {
            $all += $chunk;
        }

        return $all;
    }

    /**
     * @return array<string,mixed>
     */
    private function record(string $type, int $main): array
    {
        return $this->all($type)[$main];
    }

    /**
     * A book with one field of the given kind, its raw value saved.
     *
     * @param  array<string,mixed>  $settings  ACF settings of the field
     * @return array<string,mixed> the values of the book's record
     */
    private function book(string $kind, ?string $raw, array $settings = [], string $name = 'thing'): array
    {
        $this->site ??= $this->installOnce();
        $this->wp()->table('wp_posts')->where('post_type', 'book')->orWhere('post_type', 'like', 'acf-%')->delete();
        $this->wp()->table('wp_postmeta')->where('post_id', '>=', 400)->delete();
        $this->wpPost(self::BOOK, 'book', 'A book');
        $this->acfGroup(400, ['book'], [['name' => $name, 'settings' => ['type' => $kind, ...$settings]]]);

        if ($raw !== null) {
            $this->wpMeta(self::BOOK, [$name => $raw]);
        }

        $this->records = $this->records();

        return $this->record('book', self::BOOK)['values'];
    }

    public function test_a_post_is_a_record_with_a_text_per_language(): void
    {
        $this->load();

        $this->assertSame([
            'wordpress_id' => 10,
            'posts' => ['en' => 10, 'ru' => 11],
            'values' => [
                'name' => ['en' => 'Hello World', 'ru' => 'Привет, мир'],
                'code' => ['en' => 'hello-world', 'ru' => 'privet-mir'],
                'active' => true,
                'active_from' => '2024-05-10 10:00:00',
                'sort' => 500,
                'preview_picture' => '/wp-content/uploads/2024/05/photo.jpg',
                'preview_text' => ['en' => 'A short hello.'],
                'detail_text' => [
                    'en' => "<p>First paragraph.</p>\n<p>Second paragraph<br>\nwith a line break.</p>",
                    'ru' => '<p>Первый абзац.</p>',
                ],
            ],
            'parent' => null,
            'terms' => ['category' => [102], 'post_tag' => [105, 106]],
            'authors' => [Wp::USER_ALICE],
            'seo' => [
                'title' => 'Hello World - Synthetic Blog',
                'description' => 'Our first post.',
                'keywords' => 'hello',
                'og_image' => '/wp-content/uploads/2024/05/photo.jpg',
            ],
            'paths' => ['en' => '/2024/05/hello-world/', 'ru' => '/ru/2024/05/%d0%bf%d1%80%d0%b8%d0%b2%d0%b5%d1%82-%d0%bc%d0%b8%d1%80/'],
            'short' => ['en' => '/?p=10', 'ru' => '/?p=11'],
            'targets' => ['en' => '/news/hello-world', 'ru' => '/ru/news/privet-mir'],
            'modified' => '2024-05-11 12:30:00',
            'lost' => [],
            'files' => [],
        ], $this->record('post', Wp::HELLO));
    }

    public function test_a_page_keeps_its_text_in_content_and_its_order_in_sort(): void
    {
        $this->load();

        $about = $this->record('page', Wp::ABOUT);

        $this->assertSame([
            'name' => ['en' => 'About', 'ru' => 'О нас'],
            'code' => 'about',
            'content' => ['en' => '<p>Who we are.</p>', 'ru' => '<p>Кто мы.</p>'],
            'template' => 'default',
            'active' => true,
            'sort' => 1,
            'source' => 'wordpress:30',
        ], $about['values']);
        $this->assertSame(['en' => '/about/', 'ru' => '/ru/o-nas/'], $about['paths']);
        $this->assertSame(['en' => '/about', 'ru' => '/ru/about'], $about['targets']);
        $this->assertSame('2024-04-01 10:05:00', $about['modified']);
    }

    public function test_pages_nest_and_a_lost_parent_goes_to_the_top(): void
    {
        $this->load();

        $pages = $this->all('page');

        $this->assertSame([null, Wp::ABOUT, Wp::TEAM, null, null], array_map(static fn (array $page): ?int => $page['parent'], array_values($pages)));
        $this->assertSame([Wp::ABOUT, Wp::TEAM, Wp::HISTORY, Wp::LOST_CHILD, Wp::CONTACTS], array_keys($pages));
        $this->assertSame('/about/team/history', $pages[Wp::HISTORY]['targets']['en']);
        $this->assertSame('/lost-child', $pages[Wp::LOST_CHILD]['targets']['en']);
        $this->assertContains('post 34 is a child of post 999, which does not move (missing, deleted or of another type): it goes to the top level', $this->records->notes());
    }

    public function test_a_draft_has_no_old_address_and_is_not_active(): void
    {
        $this->load();

        $contacts = $this->record('page', Wp::CONTACTS);

        $this->assertFalse($contacts['values']['active']);
        $this->assertSame([], $contacts['paths']);
        $this->assertSame([], $contacts['short']);
        $this->assertSame([], $contacts['targets']);
    }

    /**
     * @return array<string,array{0:string,1:bool}>
     */
    public static function statuses(): array
    {
        return ['publish' => ['publish', true], 'future' => ['future', true], 'draft' => ['draft', false], 'pending' => ['pending', false], 'private' => ['private', false]];
    }

    #[DataProvider('statuses')]
    public function test_only_published_and_scheduled_posts_are_active(string $status, bool $active): void
    {
        $this->site();
        $this->wpPost(self::BOOK, 'book', 'A book', ['post_status' => $status]);
        $this->records = $this->records();

        $this->assertSame($active, $this->record('book', self::BOOK)['values']['active']);
    }

    public function test_the_menu_order_is_the_sort_and_zero_is_the_middle(): void
    {
        $this->site();
        $this->wpPost(self::BOOK, 'book', 'A book', ['menu_order' => 7]);
        $this->wpPost(451, 'book', 'Second', ['menu_order' => 0]);
        $this->wpPost(452, 'book', 'Third', ['menu_order' => -3]);
        $this->records = $this->records();

        $books = $this->all('book');

        $this->assertSame([7, 500, -3], [$books[450]['values']['sort'], $books[451]['values']['sort'], $books[452]['values']['sort']]);
    }

    public function test_a_post_without_a_title_is_called_untitled_with_its_id(): void
    {
        $this->site();
        $this->wpPost(self::BOOK, 'book', '', ['post_name' => 'nameless']);
        $this->records = $this->records();

        $this->assertSame(['en' => 'Untitled 450'], $this->record('book', self::BOOK)['values']['name']);
    }

    public function test_texts_are_trimmed_and_excerpts_kept(): void
    {
        $this->site();
        $this->wpPost(self::BOOK, 'book', "  Spaced \n", ['post_excerpt' => '  Short  ', 'post_content' => "One\n\nTwo"]);
        $this->records = $this->records();

        $values = $this->record('book', self::BOOK)['values'];

        $this->assertSame(['en' => 'Spaced'], $values['name']);
        $this->assertSame(['en' => 'Short'], $values['preview_text']);
        $this->assertSame(['en' => "<p>One</p>\n<p>Two</p>"], $values['detail_text']);
    }

    public function test_empty_texts_are_null(): void
    {
        $this->site();
        $this->wpPost(self::BOOK, 'book', 'A book');
        $this->records = $this->records();

        $values = $this->record('book', self::BOOK)['values'];

        $this->assertNull($values['preview_text']);
        $this->assertNull($values['detail_text']);
    }

    public function test_the_featured_image_is_the_first_the_post_or_its_translations_have(): void
    {
        $this->load();
        // Hello has its own (50); give the Russian one another and remove Hello's.
        $this->wp()->table('wp_postmeta')->where('post_id', Wp::HELLO)->where('meta_key', '_thumbnail_id')->update(['meta_value' => '']);
        $this->wpMeta(Wp::HELLO_RU, ['_thumbnail_id' => (string) Wp::BORSCHT_PHOTO]);
        $this->records = $this->records();

        $this->assertSame('/wp-content/uploads/2024/06/borscht.png', $this->record('post', Wp::HELLO)['values']['preview_picture']);
    }

    public function test_the_featured_image_of_the_main_post_wins_over_a_translations(): void
    {
        $this->load();
        $this->wpMeta(Wp::HELLO_RU, ['_thumbnail_id' => (string) Wp::BORSCHT_PHOTO]);
        $this->records = $this->records();

        $this->assertSame('/wp-content/uploads/2024/05/photo.jpg', $this->record('post', Wp::HELLO)['values']['preview_picture']);
    }

    public function test_a_featured_image_that_is_not_there_is_none_and_is_told(): void
    {
        $this->load();

        $orphan = $this->record('post', Wp::ORPHAN);

        $this->assertNull($orphan['values']['preview_picture']);
        $this->assertContains('the featured image of post 22 points at attachment 999, which is not in the database', $this->records->notes());
    }

    public function test_a_page_loses_an_excerpt_and_a_featured_image(): void
    {
        $this->load();
        $this->wp()->table('wp_posts')->where('ID', Wp::TEAM)->update(['post_excerpt' => 'About the team']);
        $this->wpMeta(Wp::TEAM, ['_thumbnail_id' => (string) Wp::PHOTO]);
        $this->records = $this->records();

        $this->assertSame(['the excerpt', 'the featured image'], $this->record('page', Wp::TEAM)['lost']);
        $this->assertSame([], $this->record('page', Wp::ABOUT)['lost']);
    }

    public function test_a_page_with_only_a_featured_image_loses_only_that(): void
    {
        $this->load();
        $this->wpMeta(Wp::TEAM, ['_thumbnail_id' => (string) Wp::PHOTO]);
        $this->records = $this->records();

        $this->assertSame(['the featured image'], $this->record('page', Wp::TEAM)['lost']);
    }

    public function test_a_page_with_only_an_excerpt_loses_only_that(): void
    {
        $this->load();
        $this->wp()->table('wp_posts')->where('ID', Wp::TEAM)->update(['post_excerpt' => 'About the team']);
        $this->records = $this->records();

        $this->assertSame(['the excerpt'], $this->record('page', Wp::TEAM)['lost']);
    }

    public function test_custom_fields_a_module_cannot_hold_are_listed_as_lost_when_filled(): void
    {
        $this->site();
        config()->set('atrium-wordpress.post_types', ['book' => 'news']);
        $this->wpPost(self::BOOK, 'book', 'A book');
        $this->acfGroup(400, ['book'], [['name' => 'isbn'], ['name' => 'empty_one'], ['name' => 'zero'], ['name' => 'list', 'settings' => ['type' => 'checkbox']]]);
        $this->wpMeta(self::BOOK, ['isbn' => '123', 'empty_one' => '', 'zero' => '0', 'list' => serialize([])]);
        $this->records = $this->records();

        $record = $this->record('book', self::BOOK);

        $this->assertSame(['isbn', 'zero'], $record['lost']);
        $this->assertArrayNotHasKey('isbn', $record['values']);
    }

    public function test_a_generated_type_keeps_its_fields_as_columns_and_loses_nothing(): void
    {
        $this->load();

        $record = $this->record('recipe', Wp::BORSCHT);

        $this->assertSame([], $record['lost']);
        $this->assertSame(45.0, $record['values']['cooking_time']);
        $this->assertSame('easy', $record['values']['difficulty']);
        $this->assertSame(['en' => "Beetroot\nCabbage", 'ru' => "Свёкла\nКапуста"], $record['values']['ingredients']);
        $this->assertTrue($record['values']['spicy']);
        $this->assertSame('2024-01-15', $record['values']['served_on']);
        $this->assertSame('/wp-content/uploads/2024/06/borscht.png', $record['values']['photo']);
        $this->assertSame(['/wp-content/uploads/2024/05/photo.jpg', '/wp-content/uploads/2024/06/borscht.png'], $record['values']['gallery']);
        $this->assertSame(['vegan', 'quick'], $record['values']['labels']);
        $this->assertSame([['step_text' => 'Boil water'], ['step_text' => 'Add beets']], $record['values']['steps']);
        $this->assertSame(['en' => '<p>Serve hot.</p>', 'ru' => '<p>Подавать горячим.</p>'], $record['values']['notes']);
        $this->assertSame(['en' => 'https://cook.example.test/borscht'], $record['values']['source_url']);
        $this->assertSame(['2024/06/borscht.png', '2024/05/photo.jpg'], $record['files']);
    }

    public function test_a_field_without_a_value_is_null(): void
    {
        $this->load();

        $pizza = $this->record('recipe', Wp::PIZZA)['values'];

        $this->assertSame(20.0, $pizza['cooking_time']);
        $this->assertFalse($pizza['spicy']);
        $this->assertNull($pizza['difficulty']);
        $this->assertNull($pizza['served_on']);
        $this->assertNull($pizza['gallery'] ?? null);
        $this->assertNull($pizza['steps']);
        $this->assertNull($pizza['ingredients']);
        // Its photo (52) is in the database, its file is not in the uploads folder.
        $this->assertNull($pizza['photo']);
        $this->assertContains('the field photo of post 42 points at attachment 52, whose file 2024/07/missing.jpg is missing in the uploads folder', $this->records->notes());
    }

    public function test_terms_come_from_the_taxonomies_the_type_uses_only(): void
    {
        $this->load();

        $this->assertSame(['cuisine' => [108]], $this->record('recipe', Wp::BORSCHT)['terms']);
        $this->assertSame(['category' => [102], 'post_tag' => [105, 106]], $this->record('post', Wp::HELLO)['terms']);
        $this->assertSame([], $this->record('page', Wp::ABOUT)['terms']);
    }

    public function test_a_translation_filed_under_the_translation_of_a_term_is_filed_under_the_term(): void
    {
        $this->load();

        // Hello is in News, its Russian translation in Новости: one record, 102.
        $this->assertSame(102, $this->records->termRecord(Wp::TERM_NOVOSTI + Wp::TT_OFFSET));
        $this->assertSame([102], $this->record('post', Wp::HELLO)['terms']['category']);
    }

    public function test_the_authors_of_a_record_are_those_who_are_there(): void
    {
        $this->load();

        $this->assertSame([Wp::USER_BORIS], $this->record('post', Wp::GUTENBERG)['authors']);
        $this->assertSame([], $this->record('post', Wp::ORPHAN)['authors']);
        $this->assertContains('user 99 wrote posts but is not in wp_users: they move without an author', ($this->records->authors() ? $this->records->notes() : []));
    }

    public function test_translations_of_one_author_are_one_author(): void
    {
        $this->load();
        // Hello is Alice's, its translation Boris's.
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO_RU)->update(['post_author' => Wp::USER_BORIS]);
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO)->update(['post_author' => Wp::USER_BORIS]);
        $this->records = $this->records();

        $this->assertSame([Wp::USER_BORIS], $this->record('post', Wp::HELLO)['authors']);
    }

    public function test_the_author_of_a_post_without_one_is_left_out(): void
    {
        $this->site();
        $this->wpPost(self::BOOK, 'book', 'A book', ['post_author' => 0]);
        $this->records = $this->records();

        $this->assertSame([], $this->record('book', self::BOOK)['authors']);
        $this->assertSame([], $this->records->notes());
    }

    public function test_the_latest_change_of_a_translation_is_the_records(): void
    {
        $this->load();
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO_RU)->update(['post_modified' => '2024-06-01 08:00:00']);
        $this->records = $this->records();

        $this->assertSame('2024-06-01 08:00:00', $this->record('post', Wp::HELLO)['modified']);
        $this->assertSame('2024-05-27 09:00:00', $this->record('post', Wp::ORPHAN)['modified']);
    }

    public function test_the_files_a_text_links_to_are_listed_once(): void
    {
        $this->site();
        $this->wpPost(self::BOOK, 'book', 'A book', ['post_content' => '<img src="https://old.example.com/wp-content/uploads/2024/05/a.jpg"> <a href="/wp-content/uploads/2024/05/a.jpg">a</a> <img src="https://cdn.other.test/wp-content/uploads/b.jpg"> <a href="/wp-content/uploads/docs/c%20d.pdf">c</a>']);
        $this->records = $this->records();

        // Any address with the uploads path is a file of the uploads, whichever host it is on.
        $this->assertSame(['2024/05/a.jpg', 'b.jpg', 'docs/c d.pdf'], $this->record('book', self::BOOK)['files']);
    }

    public function test_the_seo_of_a_post_is_filled_with_its_default_language_title(): void
    {
        $this->load();

        $this->assertSame([
            'title' => 'Gutenberg post - Synthetic Blog',
            'description' => 'Blocks everywhere.',
            'keywords' => 'blocks,gutenberg',
            'robots' => 'noindex, nofollow',
            'og_title' => 'Gutenberg on Facebook',
        ], $this->record('post', Wp::GUTENBERG)['seo']);
    }

    public function test_the_seo_of_a_record_that_has_no_default_language_text_uses_the_first_it_has(): void
    {
        $this->load();
        $this->wp()->table('wp_icl_translations')->where('element_id', Wp::HELLO)->where('element_type', 'post_post')->delete();
        $this->wp()->table('wp_icl_translations')->where('element_id', Wp::HELLO_RU)->where('element_type', 'post_post')->update(['language_code' => 'ru']);
        $this->wpMeta(Wp::HELLO_RU, ['_yoast_wpseo_title' => '%%title%% %%excerpt%%']);
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO_RU)->update(['post_excerpt' => 'Кратко']);
        $this->records = $this->records();

        $record = $this->record('post', Wp::HELLO_RU);

        $this->assertSame(['ru' => Wp::HELLO_RU], $record['posts']);
        $this->assertSame('Привет, мир Кратко', $record['seo']['title']);
    }

    public function test_a_translation_group_keeps_its_ids_and_the_main_is_the_default_language_one(): void
    {
        $this->load();

        $groups = [];

        foreach ($this->all('post') as $main => $record) {
            $groups[$main] = $record['posts'];
        }

        $this->assertSame(Wp::HELLO, array_key_first($groups));
        $this->assertSame(['en' => Wp::HELLO, 'ru' => Wp::HELLO_RU], $groups[Wp::HELLO]);
        $this->assertArrayNotHasKey(Wp::HELLO_RU, $groups);
        $this->assertCount(8, $groups);
    }

    public function test_a_group_without_the_default_language_is_headed_by_its_lowest_id(): void
    {
        $this->load();
        $this->wp()->table('wp_icl_translations')->where('element_type', 'post_post')->whereIn('element_id', [Wp::HELLO, Wp::HELLO_RU])->update(['language_code' => 'ru']);
        $this->wp()->table('wp_icl_translations')->where('element_type', 'post_post')->where('element_id', Wp::HELLO)->update(['language_code' => 'de']);
        $this->records = $this->records();

        $groups = $this->all('post');

        $this->assertSame(['de' => Wp::HELLO, 'ru' => Wp::HELLO_RU], $groups[Wp::HELLO]['posts']);
    }

    public function test_two_posts_of_one_language_in_one_group_are_two_records(): void
    {
        $this->load();
        $this->wp()->table('wp_icl_translations')->where('element_type', 'post_post')->where('element_id', Wp::HELLO_RU)->update(['language_code' => 'en']);
        $this->records = $this->records();

        $posts = $this->all('post');

        $this->assertSame(['en' => Wp::HELLO], $posts[Wp::HELLO]['posts']);
        $this->assertSame(['en' => Wp::HELLO_RU], $posts[Wp::HELLO_RU]['posts']);
        $this->assertCount(9, $posts);
    }

    public function test_a_translation_may_have_the_parent_of_another_post_in_the_group(): void
    {
        $this->load();

        // About's translation points at nothing, Team's parent is About.
        $this->assertSame(Wp::ABOUT, $this->record('page', Wp::TEAM)['parent']);
        $this->wp()->table('wp_posts')->where('ID', Wp::TEAM)->update(['post_parent' => Wp::ABOUT_RU]);
        $this->records = $this->records();

        // The parent is the record About_RU belongs to.
        $this->assertSame(Wp::ABOUT, $this->record('page', Wp::TEAM)['parent']);
    }

    public function test_a_page_tree_that_loops_back_is_cut_where_it_closes(): void
    {
        $this->load();
        $this->wp()->table('wp_posts')->where('ID', Wp::ABOUT)->update(['post_parent' => Wp::HISTORY]);
        $this->records = $this->records();

        $pages = $this->all('page');

        $this->assertNull($pages[Wp::ABOUT]['parent']);
        $this->assertSame(Wp::ABOUT, $pages[Wp::TEAM]['parent']);
        $this->assertSame(Wp::TEAM, $pages[Wp::HISTORY]['parent']);
        $this->assertContains('post 30 is its own ancestor: it goes to the top level', $this->records->notes());
    }

    public function test_a_page_that_is_its_own_parent_goes_to_the_top(): void
    {
        $this->load();
        $this->wp()->table('wp_posts')->where('ID', Wp::CONTACTS)->update(['post_parent' => Wp::CONTACTS]);
        $this->records = $this->records();

        $this->assertNull($this->record('page', Wp::CONTACTS)['parent']);
        $this->assertContains('post 35 is its own ancestor: it goes to the top level', $this->records->notes());
    }

    public function test_two_pages_with_one_name_under_one_parent_are_told_apart(): void
    {
        $this->load();
        $this->wpPost(500, 'page', 'Team again', ['post_name' => 'team', 'post_parent' => Wp::ABOUT]);
        $this->wpPost(501, 'page', 'Team three', ['post_name' => 'team', 'post_parent' => Wp::ABOUT]);
        $this->wpPost(502, 'page', 'About again', ['post_name' => 'about']);
        $this->records = $this->records();

        $codes = array_map(static fn (array $page): string => $page['values']['code'], $this->all('page'));

        $this->assertSame('team', $codes[Wp::TEAM]);
        $this->assertSame('team-2', $codes[500]);
        $this->assertSame('team-3', $codes[501]);
        $this->assertSame('about-2', $codes[502]);
        $this->assertSame('/about/team-2', $this->records->target($this->type('page'), 500, 'en'));
    }

    public function test_the_same_name_under_another_parent_is_not_a_clash(): void
    {
        $this->load();
        $this->wpPost(500, 'page', 'Team', ['post_name' => 'team', 'post_parent' => Wp::CONTACTS]);
        $this->records = $this->records();

        $codes = array_map(static fn (array $page): string => $page['values']['code'], $this->all('page'));

        $this->assertSame('team', $codes[500]);
        $this->assertSame('team', $codes[Wp::TEAM]);
    }

    public function test_a_page_made_in_atrium_keeps_its_address(): void
    {
        $this->load();
        DB::table('pages')->insert(['name' => '{"en":"Mine"}', 'code' => 'about', 'parent_id' => null, 'template' => 'default', 'active' => true, 'sort' => 1, 'content' => null, 'created_at' => now(), 'updated_at' => now()]);
        $this->records = $this->records();

        $this->assertSame('about-2', $this->record('page', Wp::ABOUT)['values']['code']);
    }

    public function test_every_page_made_in_atrium_is_checked_for_its_address(): void
    {
        $this->load();
        $row = ['name' => '{"en":"Mine"}', 'parent_id' => null, 'template' => 'default', 'active' => true, 'sort' => 1, 'content' => null, 'created_at' => now(), 'updated_at' => now()];
        DB::table('pages')->insert([...$row, 'code' => 'first']);
        DB::table('pages')->insert([...$row, 'code' => 'under-it', 'parent_id' => DB::table('pages')->value('id')]);
        DB::table('pages')->insert([...$row, 'code' => 'about']);
        $this->records = $this->records();

        $codes = array_map(static fn (array $page): string => $page['values']['code'], $this->all('page'));

        $this->assertSame('about-2', $codes[Wp::ABOUT]);
        $this->assertSame('team', $codes[Wp::TEAM]);
    }

    public function test_a_post_of_atrium_without_an_address_reserves_nothing(): void
    {
        $this->load();
        DB::table('news')->insert(['name' => '{"en":"Mine"}', 'code' => 'null', 'active' => true, 'active_from' => now(), 'sort' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->records = $this->records();

        $this->assertSame(['en' => 'hello-world', 'ru' => 'privet-mir'], $this->record('post', Wp::HELLO)['values']['code']);
    }

    public function test_the_terms_of_a_taxonomy_are_worked_out_once(): void
    {
        $this->load();
        $plan = SpySource::watch($this->records->plan);
        $records = new Records($plan);
        $taxonomy = $plan->taxonomies['category'];

        $first = $records->terms($taxonomy);
        $second = $records->terms($taxonomy);

        $this->assertSame($first, $second);
        $this->assertCount(1, $plan->source->calls['termMeta']);
    }

    public function test_a_post_made_in_atrium_keeps_its_address(): void
    {
        $this->load();
        DB::table('news')->insert(['name' => '{"en":"Mine"}', 'code' => '{"en":"hello-world","ru":"privet-mir"}', 'active' => true, 'active_from' => now(), 'sort' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->records = $this->records();

        $this->assertSame(['en' => 'hello-world-2', 'ru' => 'privet-mir-2'], $this->record('post', Wp::HELLO)['values']['code']);
    }

    public function test_before_the_map_exists_every_post_of_atrium_is_one_of_its_own(): void
    {
        $this->load();
        Schema::drop('wordpress_map');
        DB::table('news')->insert(['name' => '{"en":"Mine"}', 'code' => '{"en":"hello-world","ru":"privet-mir"}', 'active' => true, 'active_from' => now(), 'sort' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->records = $this->records();

        $this->assertSame(['en' => 'hello-world-2', 'ru' => 'privet-mir-2'], $this->record('post', Wp::HELLO)['values']['code']);
        $this->assertSame(['en' => 'gutenberg-post'], $this->record('post', Wp::GUTENBERG)['values']['code']);
    }

    public function test_the_two_posts_with_one_name_in_one_language_are_told_apart(): void
    {
        $this->load();
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO_AGAIN)->update(['post_status' => 'publish']);
        $this->records = $this->records();

        $this->assertSame(['en' => 'hello-world-2'], $this->record('post', Wp::HELLO_AGAIN)['values']['code']);
        $this->assertSame('hello-world', $this->record('post', Wp::HELLO)['values']['code']['en']);
    }

    public function test_a_post_without_a_usable_name_is_coded_from_its_title_or_its_id(): void
    {
        $this->site();
        $this->wpPost(self::BOOK, 'book', 'Привет мир', ['post_name' => '']);
        $this->wpPost(451, 'book', '!!!', ['post_name' => '']);
        $this->records = $this->records();

        $books = $this->all('book');

        $this->assertSame(['en' => 'privet-mir'], $books[450]['values']['code']);
        $this->assertSame(['en' => 'book-451'], $books[451]['values']['code']);
    }

    public function test_a_page_without_a_usable_name_is_coded_from_its_title_or_its_id(): void
    {
        $this->site();
        $this->wpPost(500, 'page', 'Hello there', ['post_name' => '']);
        $this->wpPost(501, 'page', '???', ['post_name' => '']);
        $this->records = $this->records();

        $pages = $this->all('page');

        $this->assertSame('hello-there', $pages[500]['values']['code']);
        $this->assertSame('page-501', $pages[501]['values']['code']);
    }

    public function test_the_records_of_a_tree_of_a_generated_type_have_codes_by_parent(): void
    {
        $this->site();
        config()->set('atrium-wordpress.post_types', ['page' => 'generate']);
        $this->wpPost(500, 'page', 'Team', ['post_name' => 'team', 'post_parent' => Wp::CONTACTS]);
        $this->records = $this->records();

        $pages = $this->all('page');

        $this->assertSame(['en' => 'team'], $pages[500]['values']['code']);
        $this->assertSame(['en' => 'team'], $pages[Wp::TEAM]['values']['code']);
        $this->assertSame(Wp::CONTACTS, $pages[500]['parent']);
        $this->assertSame('/page/contacts/team', $this->records->target($this->type('page'), 500, 'en'));
        $this->assertSame('/page/about/team/history', $this->records->target($this->type('page'), Wp::HISTORY, 'en'));
    }

    public function test_a_generated_tree_with_a_name_taken_under_the_same_parent_is_numbered(): void
    {
        $this->site();
        config()->set('atrium-wordpress.post_types', ['page' => 'generate']);
        $this->wpPost(500, 'page', 'Team', ['post_name' => 'team', 'post_parent' => Wp::ABOUT]);
        $this->records = $this->records();

        $this->assertSame(['en' => 'team-2'], $this->all('page')[500]['values']['code']);
    }

    public function test_a_generated_tree_in_a_language_without_a_text_has_no_target(): void
    {
        $this->site();
        config()->set('atrium-wordpress.post_types', ['page' => 'generate']);
        $this->records = $this->records();
        $page = $this->type('page');

        $this->assertSame('/ru/page/o-nas', $this->records->target($page, Wp::ABOUT, 'ru'));
        $this->assertNull($this->records->target($page, Wp::TEAM, 'ru'));
        $this->assertNull($this->records->target($page, 12345, 'en'));
    }

    public function test_a_translated_child_is_reached_through_the_parents_text_in_its_language_or_the_first_one(): void
    {
        $this->site();
        config()->set('atrium-wordpress.post_types', ['page' => 'generate']);
        // Team gets a Russian translation, its parent About has one too; History has none, her parent Team has.
        $this->wpPost(520, 'page', 'Команда', ['post_name' => 'komanda', 'post_parent' => Wp::ABOUT_RU]);
        $this->wp()->table('wp_icl_translations')->insert(['element_type' => 'post_page', 'element_id' => 520, 'trid' => 1000 + 12, 'language_code' => 'ru']);
        $groupOfTeam = $this->wp()->table('wp_icl_translations')->where('element_type', 'post_page')->where('element_id', Wp::TEAM)->value('trid');
        $this->wp()->table('wp_icl_translations')->where('element_id', 520)->update(['trid' => $groupOfTeam]);
        $this->records = $this->records();
        $page = $this->type('page');

        $this->assertSame('/ru/page/o-nas/komanda', $this->records->target($page, Wp::TEAM, 'ru'));
        $this->assertSame('/page/about/team', $this->records->target($page, Wp::TEAM, 'en'));
    }

    public function test_a_parent_with_texts_in_other_languages_only_lends_its_first(): void
    {
        $this->site();
        config()->set('atrium-wordpress.post_types', ['page' => 'generate']);
        // The child is in Russian only, the parent in English only.
        $this->wp()->table('wp_icl_translations')->where('element_type', 'post_page')->where('element_id', Wp::HISTORY)->update(['language_code' => 'ru']);
        $this->records = $this->records();

        $this->assertSame('/ru/page/o-nas/team/history', $this->records->target($this->type('page'), Wp::HISTORY, 'ru'));
    }

    public function test_a_flat_type_has_the_path_of_its_prefix_and_code(): void
    {
        $this->load();
        $post = $this->type('post');

        $this->assertSame('/news/hello-world', $this->records->target($post, Wp::HELLO, 'en'));
        $this->assertSame('/ru/news/privet-mir', $this->records->target($post, Wp::HELLO, 'ru'));
        $this->assertNull($this->records->target($post, Wp::GUTENBERG, 'ru'));
        $this->assertNull($this->records->target($post, 777, 'en'));
    }

    public function test_paths_get_the_language_in_front_unless_it_is_the_default_one(): void
    {
        $this->load();

        $this->assertSame('/news/x', $this->records->path('news', 'x', 'en'));
        $this->assertSame('/ru/news/x', $this->records->path('news', 'x', 'ru'));
        $this->assertSame('/x', $this->records->path('', 'x', 'en'));
        $this->assertSame('/ru/x', $this->records->path('', 'x', 'ru'));
        $this->assertSame('/a/b/x', $this->records->path('/a/b/', 'x', 'en'));
    }

    public function test_records_come_in_chunks_of_translation_groups(): void
    {
        $this->load();

        $sizes = [];

        foreach ($this->records->records($this->type('post'), 3) as $chunk) {
            $sizes[] = count($chunk);
        }

        $this->assertSame([3, 3, 2], $sizes);
    }

    public function test_the_chunk_size_is_the_configs_and_at_least_one(): void
    {
        $this->load();
        config()->set('atrium-wordpress.chunk_size', 5);
        $sizes = [];

        foreach ($this->records->records($this->type('post')) as $chunk) {
            $sizes[] = count($chunk);
        }
        $this->assertSame([5, 3], $sizes);

        config()->set('atrium-wordpress.chunk_size', 0);
        $sizes = [];

        foreach ($this->records->records($this->type('post')) as $chunk) {
            $sizes[] = count($chunk);
        }
        $this->assertSame([1, 1, 1, 1, 1, 1, 1, 1], $sizes);
    }

    public function test_the_number_of_records_is_the_number_of_translation_groups(): void
    {
        $this->load();

        $this->assertSame(8, $this->records->count($this->type('post')));
        $this->assertSame(5, $this->records->count($this->type('page')));
        $this->assertSame(2, $this->records->count($this->type('recipe')));
    }

    // Terms.

    public function test_terms_of_one_translation_group_are_one_record(): void
    {
        $this->load();

        $terms = $this->records->terms($this->records->plan->taxonomies['category']);

        $this->assertSame([101, 102, 103], array_keys($terms));
        $this->assertSame([
            'wordpress_id' => 102,
            'terms' => ['en' => 102, 'ru' => 104],
            'values' => ['name' => ['en' => 'News', 'ru' => 'Новости'], 'code' => 'news', 'description' => null, 'sort' => 500],
            'seo' => ['title' => 'All the news - Synthetic Blog', 'description' => 'News of the synthetic blog.'],
            'paths' => ['en' => '/category/news/', 'ru' => '/ru/category/novosti/'],
            'targets' => ['en' => '/category/news', 'ru' => '/ru/category/news'],
            'parent' => null,
        ], $terms[102]);
    }

    public function test_a_child_term_knows_its_parent_record(): void
    {
        $this->load();

        $terms = $this->records->terms($this->records->plan->taxonomies['category']);

        $this->assertSame(102, $terms[103]['parent']);
        $this->assertSame('/category/news/local/', $terms[103]['paths']['en']);
        $this->assertNull($terms[101]['parent']);
    }

    public function test_a_flat_taxonomy_has_no_parents(): void
    {
        $this->load();
        $this->wp()->table('wp_term_taxonomy')->where('term_id', Wp::TERM_PHP)->update(['parent' => Wp::TERM_LARAVEL]);
        $this->records = $this->records();

        $tags = $this->records->terms($this->records->plan->taxonomies['post_tag']);

        // A tag with a child makes the taxonomy a tree: the parent is kept.
        $this->assertSame(105, $tags[106]['parent']);
        $this->assertNull($tags[105]['parent']);
    }

    public function test_a_term_that_is_its_translations_child_has_no_parent(): void
    {
        $this->load();
        $this->wp()->table('wp_term_taxonomy')->where('term_id', Wp::TERM_NOVOSTI)->update(['parent' => Wp::TERM_NEWS]);
        $this->records = $this->records();

        $this->assertNull($this->records->terms($this->records->plan->taxonomies['category'])[102]['parent']);
    }

    public function test_a_parent_that_is_not_there_is_none(): void
    {
        $this->load();
        $this->wp()->table('wp_term_taxonomy')->where('term_id', Wp::TERM_LOCAL)->update(['parent' => 777]);
        $this->records = $this->records();

        $this->assertNull($this->records->terms($this->records->plan->taxonomies['category'])[103]['parent']);
    }

    public function test_two_terms_with_one_slug_are_told_apart(): void
    {
        $this->load();
        $this->wp()->table('wp_terms')->insert(['term_id' => 60, 'name' => 'News too', 'slug' => 'news', 'term_group' => 0]);
        $this->wp()->table('wp_term_taxonomy')->insert(['term_taxonomy_id' => 160, 'term_id' => 60, 'taxonomy' => 'category', 'description' => 'Second', 'parent' => 0, 'count' => 0]);
        $this->records = $this->records();

        $terms = $this->records->terms($this->records->plan->taxonomies['category']);

        $this->assertSame('news', $terms[102]['values']['code']);
        $this->assertSame('news-2', $terms[160]['values']['code']);
        $this->assertSame(['en' => 'Second'], $terms[160]['values']['description']);
        $this->assertSame(['en' => '/category/news-2'], $terms[160]['targets']);
    }

    public function test_a_term_without_a_slug_or_name_gets_a_code_from_its_id(): void
    {
        $this->load();
        $this->wp()->table('wp_terms')->insert(['term_id' => 60, 'name' => '', 'slug' => '', 'term_group' => 0]);
        $this->wp()->table('wp_term_taxonomy')->insert(['term_taxonomy_id' => 160, 'term_id' => 60, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 0]);
        $this->records = $this->records();

        $term = $this->records->terms($this->records->plan->taxonomies['category'])[160];

        $this->assertSame('term-160', $term['values']['code']);
        $this->assertSame(['en' => 'term-160'], $term['values']['name']);
    }

    public function test_two_terms_of_one_language_in_one_translation_group_are_two_records(): void
    {
        $this->load();
        $this->wp()->table('wp_icl_translations')->where('element_type', 'tax_category')->where('element_id', 104)->update(['language_code' => 'en']);
        $this->records = $this->records();

        $terms = $this->records->terms($this->records->plan->taxonomies['category']);

        $this->assertSame(['en' => 102], $terms[102]['terms']);
        $this->assertSame(['en' => 104], $terms[104]['terms']);
    }

    public function test_a_term_in_a_language_that_is_not_the_default_alone_heads_its_record(): void
    {
        $this->load();
        $this->wp()->table('wp_icl_translations')->where('element_type', 'tax_category')->where('element_id', 102)->delete();
        $this->records = $this->records();

        $terms = $this->records->terms($this->records->plan->taxonomies['category']);

        $this->assertSame(['ru' => 104], $terms[104]['terms']);
        $this->assertSame(['title' => 'Новости'], array_intersect_key($terms[104]['seo'] + ['title' => 'Новости'], ['title' => 1]));
        $this->assertSame('/ru/category/novosti', $terms[104]['targets']['ru']);
    }

    public function test_a_terms_seo_comes_from_yoast_or_rank_math(): void
    {
        $this->load();

        $tags = $this->records->terms($this->records->plan->taxonomies['post_tag']);

        $this->assertSame(['title' => 'Laravel posts - Synthetic Blog', 'description' => 'Everything about Laravel.'], $tags[105]['seo']);
        $this->assertSame([], $tags[106]['seo']);
    }

    public function test_a_terms_description_is_translated_and_cleaned(): void
    {
        $this->load();
        $this->wp()->table('wp_term_taxonomy')->whereIn('term_id', [Wp::TERM_NEWS, Wp::TERM_NOVOSTI])->update(['description' => '  About  ']);
        $this->wp()->table('wp_term_taxonomy')->where('term_id', Wp::TERM_NOVOSTI)->update(['description' => '']);
        $this->records = $this->records();

        $this->assertSame(['en' => 'About'], $this->records->terms($this->records->plan->taxonomies['category'])[102]['values']['description']);
    }

    public function test_terms_are_worked_out_once(): void
    {
        $this->load();
        $taxonomy = $this->records->plan->taxonomies['category'];

        $first = $this->records->terms($taxonomy);
        $this->wp()->table('wp_terms')->where('term_id', Wp::TERM_NEWS)->update(['name' => 'Changed']);

        $this->assertSame($first, $this->records->terms($taxonomy));
    }

    public function test_the_record_of_a_term_is_found_before_its_taxonomy_was_asked_for(): void
    {
        $this->load();

        $this->assertSame(101, $this->records->termRecord(101));
        $this->assertSame(102, $this->records->termRecord(104));
        $this->assertNull($this->records->termRecord(9999));
        // A navigation menu is not content.
        $this->assertNull($this->records->termRecord(Wp::TERM_MAIN_MENU + Wp::TT_OFFSET));
    }

    // Authors.

    public function test_the_authors_are_the_users_of_moving_posts_without_their_passwords(): void
    {
        $this->load();

        $authors = $this->records->authors();

        $this->assertSame([Wp::USER_ALICE, Wp::USER_BORIS], array_keys($authors));
        $this->assertSame([
            'wordpress_id' => 1,
            'values' => ['name' => 'Alice Writer', 'code' => 'alice', 'email' => 'alice@example.test', 'url' => 'https://alice.example.test', 'description' => 'Writes about Laravel.'],
        ], $authors[1]);
        $this->assertSame(['name' => 'Борис Автор', 'code' => 'boris', 'email' => 'boris@example.test', 'url' => null, 'description' => 'Пишет о кухне.'], $authors[2]['values']);
        $this->assertStringNotContainsString('fakehash', (string) json_encode($authors));
    }

    public function test_the_authors_of_a_skipped_type_are_not_moved(): void
    {
        $this->load();
        config()->set('atrium-wordpress.post_types', ['post' => 'skip', 'page' => 'skip', 'recipe' => 'skip']);
        $this->wpPost(self::BOOK, 'book', 'A book', ['post_author' => Wp::USER_CAROL]);
        $this->records = $this->records();

        $this->assertSame([Wp::USER_CAROL], array_keys($this->records->authors()));
    }

    public function test_an_author_without_a_display_name_is_named_from_first_and_last_name_or_login(): void
    {
        $this->load();
        $this->wp()->table('wp_users')->where('ID', Wp::USER_ALICE)->update(['display_name' => '']);
        $this->wp()->table('wp_users')->where('ID', Wp::USER_BORIS)->update(['display_name' => '', 'user_nicename' => '', 'user_email' => '', 'user_url' => '']);
        $this->wp()->table('wp_usermeta')->where('user_id', Wp::USER_BORIS)->delete();
        $this->wp()->table('wp_usermeta')->where('user_id', Wp::USER_ALICE)->whereIn('meta_key', ['first_name', 'last_name', 'description'])->delete();
        $this->wp()->table('wp_usermeta')->insert([
            ['user_id' => Wp::USER_ALICE, 'meta_key' => 'first_name', 'meta_value' => ' Alice '],
            ['user_id' => Wp::USER_ALICE, 'meta_key' => 'last_name', 'meta_value' => 'Liddell'],
        ]);
        $this->records = $this->records();

        $authors = $this->records->authors();

        $this->assertSame('Alice Liddell', $authors[1]['values']['name']);
        $this->assertNull($authors[1]['values']['description']);
        $this->assertSame(['name' => 'boris', 'code' => 'boris', 'email' => null, 'url' => null, 'description' => null], $authors[2]['values']);
    }

    public function test_an_author_with_only_a_first_name_has_it_without_spaces_around(): void
    {
        $this->load();
        $this->wp()->table('wp_users')->where('ID', Wp::USER_ALICE)->update(['display_name' => '']);
        $this->wp()->table('wp_usermeta')->where('user_id', Wp::USER_ALICE)->whereIn('meta_key', ['first_name', 'last_name'])->delete();
        $this->wp()->table('wp_usermeta')->insert(['user_id' => Wp::USER_ALICE, 'meta_key' => 'last_name', 'meta_value' => 'Liddell']);
        $this->records = $this->records();

        $this->assertSame('Liddell', $this->records->authors()[1]['values']['name']);
    }

    // Files.

    public function test_the_media_library_is_the_attachments(): void
    {
        $this->load();

        $files = $this->records->attachments();

        $this->assertSame([Wp::PHOTO, Wp::BORSCHT_PHOTO, Wp::MISSING_PHOTO, Wp::MANUAL], array_keys($files));
        $this->assertSame([
            'id' => 50,
            'file' => '2024/05/photo.jpg',
            'url' => '/wp-content/uploads/2024/05/photo.jpg',
            'title' => 'Photo',
            'alt' => 'A photo of the street',
            'description' => '',
            'mime' => 'image/jpeg',
            'sizes' => ['2024/05/photo-300x200.jpg'],
            'date' => '2024-05-10 09:00:00',
        ], $files[Wp::PHOTO]);
        $this->assertSame([], $files[Wp::BORSCHT_PHOTO]['sizes']);
        $this->assertSame('The printed manual.', $files[Wp::MANUAL]['description']);
        $this->assertSame('docs/manual.pdf', $files[Wp::MANUAL]['file']);
        $this->assertSame('application/pdf', $files[Wp::MANUAL]['mime']);
    }

    public function test_attachments_are_read_once(): void
    {
        $this->load();

        $first = $this->records->attachments();
        $this->wp()->table('wp_posts')->where('ID', Wp::PHOTO)->update(['post_title' => 'Changed']);

        $this->assertSame($first, $this->records->attachments());
    }

    public function test_an_attachment_without_a_file_meta_is_found_by_its_old_address(): void
    {
        $this->load();
        $this->wp()->table('wp_postmeta')->where('post_id', Wp::MANUAL)->delete();
        $this->records = $this->records();

        $this->assertSame('docs/manual.pdf', $this->records->attachments()[Wp::MANUAL]['file']);
    }

    public function test_a_file_meta_with_a_slash_in_front_is_relative(): void
    {
        $this->load();
        $this->wp()->table('wp_postmeta')->where('post_id', Wp::MANUAL)->update(['meta_value' => '//docs/manual.pdf']);
        $this->records = $this->records();

        $this->assertSame('docs/manual.pdf', $this->records->attachments()[Wp::MANUAL]['file']);
        $this->assertSame('/wp-content/uploads/docs/manual.pdf', $this->records->attachments()[Wp::MANUAL]['url']);
    }

    public function test_an_attachment_without_a_title_is_called_by_its_file(): void
    {
        $this->load();
        $this->wp()->table('wp_posts')->where('ID', Wp::MANUAL)->update(['post_title' => '']);
        $this->records = $this->records();

        $this->assertSame('manual.pdf', $this->records->attachments()[Wp::MANUAL]['title']);
    }

    public function test_the_sizes_of_an_attachment_are_beside_its_file_once_each(): void
    {
        $this->load();
        $this->wp()->table('wp_postmeta')->where('post_id', Wp::PHOTO)->where('meta_key', '_wp_attachment_metadata')->update(['meta_value' => serialize([
            'sizes' => [
                'thumb' => ['file' => 'photo-150x150.jpg'],
                'again' => ['file' => 'photo-150x150.jpg'],
                'empty' => ['file' => ''],
                'broken' => 'text',
                'nofile' => ['width' => 5],
                'number' => ['file' => 12],
            ],
        ])]);
        $this->wp()->table('wp_postmeta')->where('post_id', Wp::MANUAL)->where('meta_key', '_wp_attached_file')->update(['meta_value' => 'flat.pdf']);
        $this->wp()->table('wp_postmeta')->insert(['post_id' => Wp::MANUAL, 'meta_key' => '_wp_attachment_metadata', 'meta_value' => serialize(['sizes' => ['a' => ['file' => 'flat-1.pdf']]])]);
        $this->records = $this->records();

        $this->assertSame(['2024/05/photo-150x150.jpg'], $this->records->attachments()[Wp::PHOTO]['sizes']);
        $this->assertSame(['flat-1.pdf'], $this->records->attachments()[Wp::MANUAL]['sizes']);
    }

    public function test_attachments_in_the_trash_are_not_moved(): void
    {
        $this->load();
        $this->wpPost(600, 'attachment', 'Trashed file', ['post_status' => 'trash']);
        $this->wpPost(601, 'attachment', 'Draft file', ['post_status' => 'draft']);
        $this->wpPost(602, 'attachment', 'Public file', ['post_status' => 'publish']);
        $this->wpPost(603, 'attachment', 'Private file', ['post_status' => 'private']);
        $this->records = $this->records();

        $this->assertSame([50, 51, 52, 53, 602, 603], array_keys($this->records->attachments()));
    }

    public function test_a_repaired_attachment_is_told_once(): void
    {
        $this->load();

        $this->records->attachments();
        $this->records->attachments();

        $this->assertSame(1, count(array_filter($this->records->notes(), static fn (string $note): bool => str_contains($note, 'sizes of attachment 50'))));
    }

    public function test_an_attachment_url_needs_an_attachment_with_a_file(): void
    {
        $this->load();

        $this->assertNull($this->records->attachmentUrl('abc', 'a field'));
        $this->assertNull($this->records->attachmentUrl(null, 'a field'));
        $this->assertNull($this->records->attachmentUrl('0', 'a field'));
        $this->assertNull($this->records->attachmentUrl(0, 'a field'));
        $this->assertSame([], $this->notes());
        $this->assertSame('/wp-content/uploads/2024/05/photo.jpg', $this->records->attachmentUrl('50', 'a field'));
        $this->assertSame('/wp-content/uploads/2024/05/photo.jpg', $this->records->attachmentUrl(50, 'a field'));
        $this->assertNull($this->records->attachmentUrl('777', 'the box'));
        $this->assertSame(['the box points at attachment 777, which is not in the database'], $this->notes());
    }

    public function test_a_problem_is_told_once_however_often_it_is_met(): void
    {
        $this->load();

        $this->records->attachmentUrl('777', 'the box');
        $this->records->attachmentUrl('777', 'the box');
        $this->records->attachmentUrl('777', 'the lid');
        $this->records->attachmentUrl('52', 'the box');
        $this->records->attachmentUrl('52', 'the box');

        $this->assertSame([
            'the box points at attachment 777, which is not in the database',
            'the lid points at attachment 777, which is not in the database',
            'the box points at attachment 52, whose file 2024/07/missing.jpg is missing in the uploads folder',
        ], $this->notes());
    }

    public function test_the_uploads_folder_may_end_in_a_slash(): void
    {
        $this->load();
        config()->set('atrium-wordpress.uploads_path', $this->workDir.'/uploads///');

        $this->assertSame('/wp-content/uploads/2024/05/photo.jpg', $this->records->attachmentUrl(50, 'x'));
    }

    // Custom fields.

    public function test_a_number_is_a_float_and_what_is_not_a_number_is_null(): void
    {
        $this->assertSame(4.5, $this->book('number', '4.5')['thing']);
        $this->assertSame(0.0, $this->book('number', '0')['thing']);
        $this->assertSame(-3.0, $this->book('range', '-3')['thing']);
    }

    public function test_something_that_is_not_a_number_is_null(): void
    {
        $this->assertNull($this->book('number', 'many')['thing']);
    }

    public function test_an_empty_or_missing_value_is_null_whatever_the_kind(): void
    {
        foreach (['number', 'true_false', 'date_picker', 'date_time_picker', 'image', 'gallery', 'checkbox', 'select', 'post_object', 'email'] as $kind) {
            $this->assertNull($this->book($kind, '')['thing'], $kind.' empty');
        }
    }

    public function test_a_missing_value_is_null(): void
    {
        foreach (['number', 'true_false', 'date_picker', 'image', 'email', 'post_object'] as $kind) {
            $this->assertNull($this->book($kind, null)['thing'], $kind.' missing');
        }
    }

    public function test_true_false_is_true_for_one_only(): void
    {
        $this->assertTrue($this->book('true_false', '1')['thing']);
        $this->assertFalse($this->book('true_false', '0')['thing']);
        $this->assertFalse($this->book('true_false', 'yes')['thing']);
    }

    /**
     * @return array<string,array{0:string,1:?string}>
     */
    public static function dates(): array
    {
        return [
            'ACF format' => ['20240115', '2024-01-15'],
            'February 30th' => ['20240230', null],
            'leap day' => ['20240229', '2024-02-29'],
            'not a leap day' => ['20230229', null],
            'month 13' => ['20241301', null],
            'day 0' => ['20240100', null],
            'ISO' => ['2024-01-15', '2024-01-15'],
            'with a time' => ['2024-01-15 23:30:00', '2024-01-15'],
            'words' => ['15 January 2024', '2024-01-15'],
            'garbage' => ['garbage', null],
            'nine digits' => ['202401151', null],
            'a valid date after some text' => ['ab20240115', null],
        ];
    }

    #[DataProvider('dates')]
    public function test_a_date_is_read_from_acfs_format_or_any_other(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, $this->book('date_picker', $raw)['thing']);
    }

    public function test_a_date_with_a_time_is_read_as_one(): void
    {
        $this->assertSame('2024-01-15 10:30:00', $this->book('date_time_picker', '2024-01-15 10:30:00')['thing']);
        $this->assertSame('2024-01-15 10:30:00', $this->book('date_time_picker', '2024-01-15T10:30:00')['thing']);
        $this->assertSame('2024-01-15 00:00:00', $this->book('date_time_picker', '2024-01-15')['thing']);
        $this->assertNull($this->book('date_time_picker', 'garbage')['thing']);
    }

    public function test_an_image_by_id_is_its_address(): void
    {
        $this->assertSame('/wp-content/uploads/2024/05/photo.jpg', $this->book('image', '50')['thing']);
        $this->assertSame('/wp-content/uploads/docs/manual.pdf', $this->book('file', '53')['thing']);
        $this->assertNull($this->book('image', '777')['thing']);
        $this->assertNull($this->book('image', '0')['thing']);
    }

    public function test_an_image_by_address_is_moved_to_the_copied_files(): void
    {
        $this->assertSame('/wp-content/uploads/a.jpg', $this->book('image', 'https://old.example.com/wp-content/uploads/a.jpg')['thing']);
        $this->assertSame('https://cdn.other.test/a.jpg', $this->book('file', 'https://cdn.other.test/a.jpg')['thing']);
    }

    public function test_a_gallery_is_the_addresses_of_the_files_that_are_there(): void
    {
        $this->assertSame(['/wp-content/uploads/2024/05/photo.jpg'], $this->book('gallery', serialize(['50', '777', '52']))['thing']);
        $this->assertSame([], $this->book('gallery', 'abc')['thing']);
        $this->assertSame(['/wp-content/uploads/2024/05/photo.jpg'], $this->book('gallery', '50')['thing']);
    }

    public function test_a_list_of_choices_is_a_list_of_clean_texts(): void
    {
        $this->assertSame(['a', 'b'], $this->book('checkbox', serialize(['a', 'b']))['thing']);
        $this->assertSame(['a'], $this->book('checkbox', 'a')['thing']);
        $this->assertSame(['1', '', ''], $this->book('checkbox', serialize([1, ['x'], null]))['thing']);
        $this->assertSame(['x', 'y'], $this->book('select', serialize(['x', 'y']), ['multiple' => 1])['thing']);
        $this->assertSame(['Привет'], $this->book('checkbox', serialize([mb_convert_encoding('Привет', 'Windows-1251', 'UTF-8')]))['thing']);
    }

    public function test_a_single_select_is_its_text(): void
    {
        $this->assertSame('easy', $this->book('select', 'easy')['thing']);
    }

    public function test_structured_kinds_are_decoded_with_their_texts_cleaned(): void
    {
        $this->assertSame([12, 13], $this->book('relationship', serialize([12, 13]))['thing']);
        $this->assertSame(['url' => 'https://x.test', 'title' => 'Привет', 'nested' => ['a' => 1]], $this->book('link', serialize([
            'url' => 'https://x.test',
            'title' => mb_convert_encoding('Привет', 'Windows-1251', 'UTF-8'),
            'nested' => ['a' => 1],
        ]))['thing']);
        $this->assertSame('plain', $this->book('post_object', 'plain')['thing']);
        $this->assertSame(7, $this->book('post_object', 'i:7;')['thing']);
    }

    public function test_other_kinds_are_text_with_encoding_repaired(): void
    {
        $this->assertSame('a@b.test', $this->book('email', 'a@b.test')['thing']);
        $this->assertSame('Привет', $this->book('email', mb_convert_encoding('Привет', 'Windows-1251', 'UTF-8'))['thing']);
        $this->assertSame('  spaced ', $this->book('email', '  spaced ')['thing']);
    }

    public function test_texts_of_a_field_are_per_language_and_uploads_are_local(): void
    {
        $values = $this->book('textarea', "See https://old.example.com/wp-content/uploads/x.jpg \n");

        $this->assertSame(['en' => 'See /wp-content/uploads/x.jpg'], $values['thing']);
    }

    public function test_a_wysiwyg_field_becomes_html(): void
    {
        $this->assertSame(['en' => "<p>One</p>\n<p>Two</p>"], $this->book('wysiwyg', "One\n\nTwo")['thing']);
    }

    public function test_a_plain_meta_field_is_a_text_per_language(): void
    {
        $this->site();
        $this->wpPost(self::BOOK, 'book', 'A book');
        $this->wpMeta(self::BOOK, ['loose' => ' value ']);
        $this->records = $this->records();

        $this->assertSame(['en' => 'value'], $this->record('book', self::BOOK)['values']['loose']);
    }

    public function test_a_text_field_that_is_empty_in_every_language_is_null(): void
    {
        $this->assertNull($this->book('text', '   ')['thing']);
        $this->assertNull($this->book('text', null)['thing']);
    }

    public function test_untranslated_values_come_from_the_first_translation_that_has_one(): void
    {
        $this->load();
        $this->wp()->table('wp_postmeta')->where('post_id', Wp::BORSCHT)->where('meta_key', 'cooking_time')->delete();
        $this->wp()->table('wp_postmeta')->where('post_id', Wp::BORSCHT_RU)->where('meta_key', 'cooking_time')->update(['meta_value' => '99']);
        $this->records = $this->records();

        $this->assertSame(99.0, $this->record('recipe', Wp::BORSCHT)['values']['cooking_time']);
    }

    public function test_the_main_posts_value_wins_over_a_translations(): void
    {
        $this->load();
        $this->wp()->table('wp_postmeta')->where('post_id', Wp::BORSCHT_RU)->where('meta_key', 'cooking_time')->update(['meta_value' => '99']);
        $this->records = $this->records();

        $this->assertSame(45.0, $this->record('recipe', Wp::BORSCHT)['values']['cooking_time']);
    }

    public function test_a_field_with_an_empty_value_on_the_main_post_stays_empty_even_if_a_translation_has_one(): void
    {
        $this->load();
        $this->wp()->table('wp_postmeta')->where('post_id', Wp::BORSCHT)->where('meta_key', 'cooking_time')->update(['meta_value' => '']);
        $this->records = $this->records();

        $this->assertNull($this->record('recipe', Wp::BORSCHT)['values']['cooking_time']);
    }

    public function test_a_repaired_field_is_told_with_the_post_it_came_from(): void
    {
        $this->load();

        $this->record('recipe', Wp::BORSCHT);

        $this->assertContains('the field labels of post 40 was saved with wrong string lengths (a search and replace on the database); it was read after a repair', $this->records->notes());
    }

    public function test_a_repeater_is_its_rows(): void
    {
        $this->site();
        $this->wpPost(self::BOOK, 'book', 'A book');
        $this->acfGroup(400, ['book'], [['name' => 'rows', 'settings' => ['type' => 'repeater']], ['name' => 'a', 'parent' => 0], ['name' => 'b', 'parent' => 0]]);
        $this->wpMeta(self::BOOK, ['rows' => '2', 'rows_0_a' => 'x', 'rows_0_b' => 'y', 'rows_1_a' => serialize(['k' => 'v']), 'rowsx_0_a' => 'no', 'rows_5_a' => 'beyond']);
        $this->records = $this->records();

        $this->assertSame([['a' => 'x', 'b' => 'y'], ['a' => ['k' => 'v']]], $this->record('book', self::BOOK)['values']['rows']);
    }

    public function test_a_repeater_without_rows_or_a_count_is_null(): void
    {
        $this->assertNull($this->book('repeater', '0')['thing']);
        $this->assertNull($this->book('repeater', 'x')['thing']);
        $this->assertNull($this->book('repeater', null)['thing']);
    }

    public function test_a_repeater_with_a_count_but_no_cells_has_empty_rows(): void
    {
        $this->assertSame([[], []], $this->book('repeater', '2')['thing']);
    }

    public function test_flexible_content_lists_its_layouts(): void
    {
        $this->site();
        $this->wpPost(self::BOOK, 'book', 'A book');
        $this->acfGroup(400, ['book'], [['name' => 'blocks', 'settings' => ['type' => 'flexible_content']]]);
        $this->wpMeta(self::BOOK, ['blocks' => serialize(['hero', 'text', ['odd']]), 'blocks_0_title' => 'Hi', 'blocks_1_body' => 'Words', 'blocks_2_x' => '1']);
        $this->records = $this->records();

        $this->assertSame([
            ['acf_fc_layout' => 'hero', 'title' => 'Hi'],
            ['acf_fc_layout' => 'text', 'body' => 'Words'],
            ['acf_fc_layout' => '', 'x' => '1'],
        ], $this->record('book', self::BOOK)['values']['blocks']);
    }

    public function test_flexible_content_without_layouts_is_null(): void
    {
        $this->assertNull($this->book('flexible_content', '')['thing']);
        $this->assertNull($this->book('flexible_content', null)['thing']);
    }

    public function test_a_group_is_its_sub_fields_that_have_values(): void
    {
        $this->site();
        $this->wpPost(self::BOOK, 'book', 'A book');
        $this->acfGroup(400, ['book'], [['name' => 'hero', 'settings' => ['type' => 'group']], ['name' => 'title', 'parent' => 0], ['name' => 'link', 'parent' => 0], ['name' => 'unused', 'parent' => 0]]);
        $this->wpMeta(self::BOOK, ['hero_title' => 'Hi', 'hero_link' => serialize(['u' => 'x'])]);
        $this->records = $this->records();

        $this->assertSame(['title' => 'Hi', 'link' => ['u' => 'x']], $this->record('book', self::BOOK)['values']['hero']);
    }

    public function test_a_group_without_values_is_null(): void
    {
        $this->assertNull($this->book('group', null)['thing']);
    }

    public function test_the_files_a_field_links_to_are_copied_too(): void
    {
        $values = $this->book('wysiwyg', '<img src="/wp-content/uploads/2024/05/in-field.jpg">');
        $this->assertSame(['en' => '<p><img src="/wp-content/uploads/2024/05/in-field.jpg"></p>'], $values['thing']);

        $this->wp()->table('wp_posts')->where('post_type', 'book')->orWhere('post_type', 'like', 'acf-%')->delete();
        $this->wp()->table('wp_postmeta')->where('post_id', '>=', 400)->delete();
        $this->wpPost(self::BOOK, 'book', 'A book');
        $this->acfGroup(400, ['book'], [
            ['name' => 'pic', 'settings' => ['type' => 'image']],
            ['name' => 'many', 'settings' => ['type' => 'link']],
            ['name' => 'num', 'settings' => ['type' => 'number']],
            ['name' => 'out', 'settings' => ['type' => 'url']],
        ]);
        $this->wpMeta(self::BOOK, [
            'pic' => '/wp-content/uploads/2024/05/pic.jpg',
            'many' => serialize(['a' => '/wp-content/uploads/m/one.pdf', 'b' => ['/wp-content/uploads/m/two.pdf', 5, null]]),
            'num' => '3',
            'out' => 'https://elsewhere.test/wp-content/uploads/not-ours.jpg',
        ]);
        $this->records = $this->records();

        // What looks like a path of the uploads folder counts, on whichever host; numbers and nulls do not.
        $this->assertSame(['2024/05/pic.jpg', 'm/one.pdf', 'm/two.pdf', 'not-ours.jpg'], $this->record('book', self::BOOK)['files']);
    }

    public function test_a_second_run_over_what_was_moved_gets_the_same_codes(): void
    {
        $this->site();
        $this->generateAndMigrate();
        [$exit] = $this->cli('atrium:wordpress:migrate:data');
        $this->assertSame(0, $exit);

        $records = $this->records();
        $news = $this->plan()->types['post'];

        foreach ($records->records($news) as $chunk) {
            $this->assertSame(['en' => 'hello-world', 'ru' => 'privet-mir'], $chunk[Wp::HELLO]['values']['code']);
        }

        $pages = $this->plan()->types['page'];
        foreach ($records->records($pages) as $chunk) {
            $this->assertSame('about', $chunk[Wp::ABOUT]['values']['code']);
        }
    }

    public function test_the_same_site_without_a_language_plugin_has_one_text_per_post(): void
    {
        $this->load('none');

        $hello = $this->record('post', Wp::HELLO);

        $this->assertSame(['ru' => Wp::HELLO], $hello['posts']);
        $this->assertSame(['ru' => 'Hello World'], $hello['values']['name']);
        $this->assertSame(['ru' => '/news/hello-world'], $hello['targets']);
    }

    // A translation with a lower id than the post the group is headed by.

    /**
     * Hello is in Russian and its translation 11 in English: the English post is the main one, not the first.
     */
    private function swapHelloLanguages(): void
    {
        $this->load();
        $this->wp()->table('wp_icl_translations')->where('element_type', 'post_post')->where('element_id', Wp::HELLO)->update(['language_code' => 'ru']);
        $this->wp()->table('wp_icl_translations')->where('element_type', 'post_post')->where('element_id', Wp::HELLO_RU)->update(['language_code' => 'en']);
    }

    public function test_the_main_post_of_a_group_is_the_default_language_one_whatever_its_id(): void
    {
        $this->swapHelloLanguages();
        $this->records = $this->records();

        $posts = $this->all('post');

        $this->assertArrayNotHasKey(Wp::HELLO, $posts);
        $this->assertSame(['ru' => Wp::HELLO, 'en' => Wp::HELLO_RU], $posts[Wp::HELLO_RU]['posts']);
        $this->assertSame(Wp::HELLO_RU, $posts[Wp::HELLO_RU]['wordpress_id']);
    }

    public function test_the_featured_image_of_the_main_post_is_first_even_when_a_translation_comes_before_it(): void
    {
        $this->swapHelloLanguages();
        $this->wpMeta(Wp::HELLO_RU, ['_thumbnail_id' => (string) Wp::BORSCHT_PHOTO]);
        $this->records = $this->records();

        // Hello (10, now Russian) has the photo 50; the main post 11 has the borscht one.
        $this->assertSame('/wp-content/uploads/2024/06/borscht.png', $this->record('post', Wp::HELLO_RU)['values']['preview_picture']);
    }

    public function test_an_untranslated_value_of_the_main_post_wins_even_when_a_translation_comes_before_it(): void
    {
        $this->load();
        $this->wp()->table('wp_icl_translations')->where('element_type', 'post_recipe')->where('element_id', Wp::BORSCHT)->update(['language_code' => 'ru']);
        $this->wp()->table('wp_icl_translations')->where('element_type', 'post_recipe')->where('element_id', Wp::BORSCHT_RU)->update(['language_code' => 'en']);
        $this->wp()->table('wp_postmeta')->where('post_id', Wp::BORSCHT_RU)->where('meta_key', 'cooking_time')->update(['meta_value' => '99']);
        $this->records = $this->records();

        // Borscht (40, 45 minutes) is now the Russian text; 41, the main one, says 99.
        $this->assertSame(99.0, $this->record('recipe', Wp::BORSCHT_RU)['values']['cooking_time']);
    }

    public function test_the_seo_of_a_post_is_filled_from_the_default_language_even_when_a_translation_comes_first(): void
    {
        $this->swapHelloLanguages();
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO_RU)->update(['post_excerpt' => 'English excerpt']);
        $this->wpMeta(Wp::HELLO_RU, ['_yoast_wpseo_title' => '%%title%% %%excerpt%%']);
        $this->records = $this->records();

        $this->assertSame('Привет, мир English excerpt', $this->record('post', Wp::HELLO_RU)['seo']['title']);
    }

    public function test_the_records_of_posts_follow_the_ids_of_their_main_posts(): void
    {
        $this->swapHelloLanguages();
        $this->records = $this->records();

        $mains = array_keys($this->all('post'));

        $sorted = $mains;
        sort($sorted);
        $this->assertSame($sorted, $mains);
    }

    /**
     * News (102) is now the Russian text of its group, Новости (104) the English, and a German one (160) joins.
     */
    private function swapNewsLanguages(): void
    {
        $this->load();
        $trid = $this->wp()->table('wp_icl_translations')->where('element_type', 'tax_category')->where('element_id', 102)->value('trid');
        $this->wp()->table('wp_icl_translations')->where('element_type', 'tax_category')->where('element_id', 102)->update(['language_code' => 'ru']);
        $this->wp()->table('wp_icl_translations')->where('element_type', 'tax_category')->where('element_id', 104)->update(['language_code' => 'en']);
        $this->wp()->table('wp_terms')->insert(['term_id' => 60, 'name' => 'Deutsch', 'slug' => 'deutsch', 'term_group' => 0]);
        $this->wp()->table('wp_term_taxonomy')->insert(['term_taxonomy_id' => 160, 'term_id' => 60, 'taxonomy' => 'category', 'description' => 'Auf Deutsch', 'parent' => 0, 'count' => 0]);
        $this->wp()->table('wp_icl_translations')->insert(['element_type' => 'tax_category', 'element_id' => 160, 'trid' => $trid, 'language_code' => 'de']);
        $this->wp()->table('wp_term_taxonomy')->where('term_taxonomy_id', 104)->update(['description' => 'In English']);
        $this->wp()->table('wp_term_taxonomy')->where('term_taxonomy_id', 102)->update(['description' => 'По-русски']);
    }

    public function test_the_main_term_of_a_group_is_the_default_language_one_and_the_languages_are_in_order(): void
    {
        $this->swapNewsLanguages();
        $this->records = $this->records();

        $terms = $this->records->terms($this->records->plan->taxonomies['category']);

        // Records by the id of their main term; languages in the alphabetical order, whichever term came first.
        $this->assertSame([101, 103, 104], array_keys($terms));
        $this->assertSame(104, $terms[104]['wordpress_id']);
        $this->assertSame(['de' => 160, 'en' => 104, 'ru' => 102], $terms[104]['terms']);
        $this->assertSame(['de', 'en', 'ru'], array_keys($terms[104]['values']['name']));
        $this->assertSame(['de' => 'Auf Deutsch', 'en' => 'In English', 'ru' => 'По-русски'], $terms[104]['values']['description']);
        $this->assertSame(['de', 'en', 'ru'], array_keys($terms[104]['paths']));
        $this->assertSame(['de', 'en', 'ru'], array_keys($terms[104]['targets']));
        $this->assertSame(104, $this->records->termRecord(102));
        $this->assertSame(104, $this->records->termRecord(160));
    }

    public function test_the_seo_of_a_term_is_filled_from_the_default_language_name_and_description(): void
    {
        $this->swapNewsLanguages();
        $this->wp()->table('wp_termmeta')->insert([
            ['term_id' => 4, 'meta_key' => 'rank_math_title', 'meta_value' => '%title% %sep% %excerpt%'],
            ['term_id' => 4, 'meta_key' => 'rank_math_description', 'meta_value' => '%excerpt%'],
        ]);
        $this->records = $this->records();

        $seo = $this->records->terms($this->records->plan->taxonomies['category'])[104]['seo'];

        // Новости is the English text now, and "In English" its description.
        $this->assertSame('Новости - In English', $seo['title']);
        $this->assertSame('In English', $seo['description']);
    }

    // Reading the old site.

    public function test_the_authors_of_a_type_are_asked_for_once_each_and_never_the_zero_one(): void
    {
        $this->load();
        $this->wpPost(self::BOOK, 'book', 'Nobody wrote this', ['post_author' => 0]);
        $this->wpPost(451, 'book', 'Alice again');
        $this->wpPost(452, 'book', 'Boris', ['post_author' => Wp::USER_BORIS]);
        $this->wpPost(453, 'book', 'Alice once more');
        $plan = SpySource::watch($this->records()->plan);
        $records = new Records($plan);

        $records->count($plan->types['book']);

        $this->assertSame([[[Wp::USER_ALICE, Wp::USER_BORIS]]], $plan->source->calls['users']);
    }

    public function test_the_authors_are_asked_for_as_a_list(): void
    {
        $this->load();
        $plan = SpySource::watch($this->records()->plan);
        $records = new Records($plan);

        $records->authors();

        // The last call is the one that reads the users themselves.
        $calls = $plan->source->calls['users'];
        $this->assertSame([Wp::USER_ALICE, Wp::USER_BORIS, Wp::USER_MISSING], end($calls)[0]);
    }

    public function test_a_post_type_is_read_once_however_often_it_is_asked_about(): void
    {
        $this->load();
        $plan = SpySource::watch($this->records()->plan);
        $records = new Records($plan);
        $post = $plan->types['post'];

        $records->count($post);
        $records->count($post);
        $records->target($post, Wp::HELLO, 'en');

        $this->assertCount(1, $plan->source->calls['postIds']);
    }

    public function test_the_authors_that_are_there_are_not_told_about(): void
    {
        $this->load();

        $this->records->authors();

        $told = array_values(array_filter($this->records->notes(), static fn (string $note): bool => str_starts_with($note, 'user ')));
        $this->assertSame(['user 99 wrote posts but is not in wp_users: they move without an author'], $told);
    }

    public function test_every_problem_of_one_kind_is_told_for_each_thing_it_met(): void
    {
        $this->load();
        $this->wpPost(500, 'post', 'By a stranger', ['post_author' => 98]);
        $this->wpPost(501, 'page', 'Another lost child', ['post_parent' => 998]);
        $this->records = $this->records();

        $this->records->authors();
        $this->records->attachmentUrl('777', 'the box');
        $this->records->attachmentUrl('778', 'the box');
        $this->records->attachmentUrl('52', 'the box');
        $this->records->attachmentUrl('52', 'the lid');

        $this->assertSame([
            'post 34 is a child of post 999, which does not move (missing, deleted or of another type): it goes to the top level',
            'post 501 is a child of post 998, which does not move (missing, deleted or of another type): it goes to the top level',
            'user 99 wrote posts but is not in wp_users: they move without an author',
            'user 98 wrote posts but is not in wp_users: they move without an author',
            'the box points at attachment 777, which is not in the database',
            'the box points at attachment 778, which is not in the database',
            'the box points at attachment 52, whose file 2024/07/missing.jpg is missing in the uploads folder',
            'the lid points at attachment 52, whose file 2024/07/missing.jpg is missing in the uploads folder',
        ], array_values(array_diff($this->notes(), ['the featured image of post 22 points at attachment 999, which is not in the database'])));
    }

    public function test_a_flat_type_ignores_the_parents_of_its_posts(): void
    {
        $this->load();
        $this->wpPost(500, 'post', 'A child', ['post_parent' => Wp::HELLO]);
        $this->wpPost(501, 'post', 'A lost child', ['post_parent' => Wp::NO_SUCH_POST]);
        $this->records = $this->records();

        $posts = $this->all('post');

        $this->assertFalse($this->type('post')->hierarchical);
        $this->assertNull($posts[500]['parent']);
        $this->assertNull($posts[501]['parent']);
        $this->assertNotContains('post 500 is a child of post 10, which does not move (missing, deleted or of another type): it goes to the top level', $this->records->notes());
        $this->assertSame(['en' => 'a-child'], $posts[500]['values']['code']);
        $this->assertSame('/news/a-child', $this->records->target($this->type('post'), 500, 'en'));
        $this->assertSame([], array_filter($this->records->notes(), static fn (string $note): bool => str_contains($note, 'post 501')));
    }

    public function test_a_type_without_a_table_of_its_own_reserves_nothing(): void
    {
        $this->load();
        $this->records = $this->records();
        Schema::drop('news');

        $this->assertSame(['en' => 'hello-world', 'ru' => 'privet-mir'], $this->record('post', Wp::HELLO)['values']['code']);
    }

    public function test_a_large_site_is_read_in_chunks_of_500_and_given_out_in_chunks_of_200(): void
    {
        $this->site();

        foreach (range(1000, 1500) as $id) {
            $this->wpPost($id, 'book', 'Book '.$id);
        }

        config()->set('atrium-wordpress', array_diff_key((array) config('atrium-wordpress'), ['chunk_size' => 0]));
        $plan = SpySource::watch($this->plan());
        $records = new Records($plan);
        $book = $plan->types['book'];

        $this->assertSame(501, $records->count($book));
        $this->assertSame([500, 1], array_map(static fn (array $call): int => count($call[0]), $plan->source->calls['posts']));

        $sizes = [];

        foreach ($records->records($book) as $chunk) {
            $sizes[] = count($chunk);
        }

        $this->assertSame([200, 200, 101], $sizes);
    }

    // Files and fields.

    public function test_the_files_of_every_language_of_a_post_are_listed(): void
    {
        $this->load();
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO)->update(['post_content' => '<img src="/wp-content/uploads/2024/05/en.jpg">']);
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO_RU)->update(['post_content' => '<img src="/wp-content/uploads/2024/05/ru.jpg">']);
        $this->records = $this->records();

        $this->assertSame(['2024/05/en.jpg', '2024/05/ru.jpg'], $this->record('post', Wp::HELLO)['files']);
    }

    public function test_an_address_with_another_address_inside_is_not_a_file_of_the_uploads(): void
    {
        $this->site();
        $this->wpPost(self::BOOK, 'book', 'A book', ['post_content' => '<img src="/wp-content/uploads/https://cdn.other.test/b.jpg"> <img src="/wp-content/uploads/a.jpg">']);
        $this->records = $this->records();

        $this->assertSame(['a.jpg'], $this->record('book', self::BOOK)['files']);
    }

    public function test_the_sizes_are_a_list_without_the_repeated_ones(): void
    {
        $this->load();
        $this->wp()->table('wp_postmeta')->where('post_id', Wp::PHOTO)->where('meta_key', '_wp_attachment_metadata')->update(['meta_value' => serialize([
            'sizes' => ['a' => ['file' => 'p-1.jpg'], 'b' => ['file' => 'p-1.jpg'], 'c' => ['file' => 'p-2.jpg']],
        ])]);
        $this->records = $this->records();

        $this->assertSame(['2024/05/p-1.jpg', '2024/05/p-2.jpg'], $this->records->attachments()[Wp::PHOTO]['sizes']);
    }

    public function test_a_size_of_a_file_without_a_folder_is_relative_whatever_its_slashes(): void
    {
        $this->load();
        $this->wp()->table('wp_postmeta')->where('post_id', Wp::MANUAL)->where('meta_key', '_wp_attached_file')->update(['meta_value' => 'flat.pdf']);
        $this->wp()->table('wp_postmeta')->insert(['post_id' => Wp::MANUAL, 'meta_key' => '_wp_attachment_metadata', 'meta_value' => serialize(['sizes' => ['a' => ['file' => '/flat-2.pdf']]])]);
        $this->records = $this->records();

        $this->assertSame(['flat-2.pdf'], $this->records->attachments()[Wp::MANUAL]['sizes']);
    }

    public function test_sizes_that_are_not_a_list_of_sizes_are_none(): void
    {
        $this->load();
        $this->wp()->table('wp_postmeta')->where('post_id', Wp::PHOTO)->where('meta_key', '_wp_attachment_metadata')->update(['meta_value' => serialize(['sizes' => 'text'])]);
        $this->records = $this->records();

        $this->assertSame([], $this->records->attachments()[Wp::PHOTO]['sizes']);
    }

    public function test_an_attachment_id_is_a_number_however_it_is_written(): void
    {
        $this->load();

        $this->assertSame('/wp-content/uploads/2024/05/photo.jpg', $this->records->attachmentUrl('50.0', 'a field'));
    }

    public function test_the_ids_of_a_gallery_that_are_not_there_leave_a_list(): void
    {
        $this->assertSame(['/wp-content/uploads/2024/05/photo.jpg'], $this->book('gallery', serialize(['777', '50']))['thing']);
    }

    public function test_the_choices_of_a_list_field_are_a_list_whatever_their_keys(): void
    {
        $this->assertSame(['x', 'y'], $this->book('checkbox', serialize(['a' => 'x', 'b' => 'y']))['thing']);
    }

    public function test_a_field_that_is_not_a_list_of_choices_is_decoded_as_it_is_even_when_it_allows_several(): void
    {
        $this->assertSame([12, 13], $this->book('post_object', serialize([12, 13]), ['multiple' => 1])['thing']);
    }

    public function test_the_layouts_of_flexible_content_are_taken_in_order_whatever_their_keys(): void
    {
        $this->site();
        $this->wpPost(self::BOOK, 'book', 'A book');
        $this->acfGroup(400, ['book'], [['name' => 'blocks', 'settings' => ['type' => 'flexible_content']]]);
        $this->wpMeta(self::BOOK, ['blocks' => serialize(['a' => 'hero', 'b' => 5]), 'blocks_0_title' => 'Hi', 'blocks_1_body' => 'Words']);
        $this->records = $this->records();

        $this->assertSame([
            ['acf_fc_layout' => 'hero', 'title' => 'Hi'],
            ['acf_fc_layout' => '5', 'body' => 'Words'],
        ], $this->record('book', self::BOOK)['values']['blocks']);
    }
}
