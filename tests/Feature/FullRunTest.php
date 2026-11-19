<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Feature;

use ArrayObject;
use Atrium\WordPress\Tests\Concerns\ImportsWordPress;
use Atrium\WordPress\Tests\Fixtures\SyntheticWordPress as Wp;
use Atrium\WordPress\Tests\TestCase;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The whole move on the made-up site, with each way of keeping languages
 * WordPress has: what is in Atrium afterwards is what the fixture says.
 */
final class FullRunTest extends TestCase
{
    use ImportsWordPress;

    /**
     * @return array<string,array{0:string,1:string,2:bool}>
     */
    public static function languagePlugins(): array
    {
        return [
            'WPML' => ['wpml', 'en', true],
            'Polylang' => ['polylang', 'en', true],
            'no language plugin, a Russian site' => ['none', 'ru', false],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function defaultLanguages(): array
    {
        return array_map(static fn (array $set): array => [$set[0], $set[1]], self::languagePlugins());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function plugins(): array
    {
        return array_map(static fn (array $set): array => [$set[0]], self::languagePlugins());
    }

    #[DataProvider('languagePlugins')]
    public function test_every_record_term_and_file_arrives(string $plugin, string $default, bool $translated): void
    {
        $this->fullRun($plugin);

        // Records are translation groups: a post and its translation are one.
        $this->assertSame(8, DB::table('news')->count());
        $this->assertSame(5, DB::table('pages')->count());
        $this->assertSame(2, DB::table('recipes')->count());
        $this->assertSame(3, DB::table('categories')->count());
        $this->assertSame(2, DB::table('tags')->count());
        $this->assertSame(2, DB::table('cuisines')->count());
        $this->assertSame(2, DB::table('authors')->count());
        $this->assertSame(3, DB::table('media_library_items')->count());
        $this->assertSame(1, DB::table('media_library_collections')->where('name', 'WordPress')->count());
        $this->assertSame(6, DB::table('seo_meta')->count());

        // 8 posts + 5 pages + 2 recipes + 7 terms + 2 authors + 3 files.
        $this->assertSame(27, DB::table('wordpress_map')->count());
        $this->assertSame(['attachment' => 3, 'author' => 2, 'post' => 15, 'term' => 7], DB::table('wordpress_map')->selectRaw('kind, count(*) as aggregate')->groupBy('kind')->orderBy('kind')->pluck('aggregate', 'kind')->map(fn ($n): int => (int) $n)->all());

        // Trashed, auto-draft and revision posts, and the ACF definitions, did not move.
        foreach ([Wp::TRASHED, Wp::AUTO_DRAFT, Wp::REVISION, Wp::ACF_GROUP, Wp::PHOTO] as $skipped) {
            $this->assertFalse($this->moved('post', $skipped), "post {$skipped} should not have moved");
        }
        // The internal menu term did not either.
        $this->assertFalse($this->moved('term', Wp::TERM_MAIN_MENU + Wp::TT_OFFSET));
        // A subscriber without posts is not an author.
        $this->assertFalse($this->moved('author', Wp::USER_CAROL));

        // The site's languages are Atrium's content languages, the default one first.
        $ru = DB::table('admin_languages')->where('code', 'ru')->first();
        $this->assertTrue((bool) $ru->active);
        $this->assertSame(extension_loaded('intl') ? 'Русский' : 'RU', $ru->name);
        $this->assertSame($translated ? 200 : 100, (int) $ru->sort);
        $this->assertSame(2, DB::table('admin_languages')->count());
        $this->assertSame($default, DB::table('admin_languages')->where('is_default', true)->value('code'));
    }

    #[DataProvider('languagePlugins')]
    public function test_texts_are_filed_per_language(string $plugin, string $default, bool $translated): void
    {
        $this->fullRun($plugin);
        $texts = static fn (string $first, string $second): array => $translated ? [$default => $first, 'ru' => $second] : [$default => $first];

        $hello = $this->target('post', Wp::HELLO);
        $this->assertSame($texts('Hello World', 'Привет, мир'), $this->decoded($hello, 'name'));
        $this->assertSame($texts('hello-world', 'privet-mir'), $this->decoded($hello, 'code'));
        $this->assertSame(
            $texts("<p>First paragraph.</p>\n<p>Second paragraph<br>\nwith a line break.</p>", '<p>Первый абзац.</p>'),
            $this->decoded($hello, 'detail_text'),
        );
        $this->assertSame([$default => 'A short hello.'], $this->decoded($hello, 'preview_text'));
        // 10:00 on the site's clock, which is three hours ahead of UTC: the new site keeps UTC.
        $this->assertSame('2024-05-10 07:00:00', $hello->active_from);
        $this->assertTrue((bool) $hello->active);
        $this->assertSame(500, (int) $hello->sort);

        $about = $this->target('post', Wp::ABOUT);
        $this->assertSame($texts('About', 'О нас'), $this->decoded($about, 'name'));
        $this->assertSame($texts('<p>Who we are.</p>', '<p>Кто мы.</p>'), $this->decoded($about, 'content'));
        // A page has one address part for every language, from the default one.
        $this->assertSame('about', $about->code);
        $this->assertSame('wordpress:'.Wp::ABOUT, $about->source);
        $this->assertSame(1, (int) $about->sort, 'menu_order becomes the sort');
        $this->assertSame('default', $about->template);

        $borscht = $this->target('post', Wp::BORSCHT);
        $this->assertSame($texts('Borscht', 'Борщ'), $this->decoded($borscht, 'name'));
        $this->assertSame($texts('borscht', 'borshch'), $this->decoded($borscht, 'code'));
    }

    #[DataProvider('defaultLanguages')]
    public function test_the_messy_posts_are_moved_as_well_as_they_can_be(string $plugin, string $default): void
    {
        $this->fullRun($plugin);

        // Windows-1251 and a text encoded twice become real UTF-8.
        $encoding = $this->target('post', Wp::ENCODING);
        $this->assertSame([$default => Wp::ENCODING_TITLE], $this->decoded($encoding, 'name'));
        $this->assertSame([$default => '<p>'.Wp::ENCODING_CONTENT.'</p>'], $this->decoded($encoding, 'detail_text'));

        // Block comments are gone, the upload link points at the copied file.
        $gutenberg = $this->target('post', Wp::GUTENBERG);
        $this->assertSame(
            [$default => "<p>Block paragraph.</p>\n\n<figure class=\"wp-block-image\"><img src=\"/wp-content/uploads/2024/05/photo-300x200.jpg\" alt=\"\"/></figure>"],
            $this->decoded($gutenberg, 'detail_text'),
        );

        // The same slug twice: the second one gets a number.
        $this->assertSame('hello-world', $this->decoded($this->target('post', Wp::HELLO), 'code')[$default]);
        $this->assertSame([$default => 'hello-world-2'], $this->decoded($this->target('post', Wp::HELLO_AGAIN), 'code'));

        // Drafts and private posts are inactive, scheduled ones are active from their date.
        $this->assertFalse((bool) $this->target('post', Wp::DRAFT_IDEA)->active);
        $this->assertFalse((bool) $this->target('post', Wp::HELLO_AGAIN)->active);
        $this->assertFalse((bool) $this->target('post', Wp::PRIVATE_NOTE)->active);
        $this->assertFalse((bool) $this->target('post', Wp::CONTACTS)->active);
        $scheduled = $this->target('post', Wp::SCHEDULED);
        $this->assertTrue((bool) $scheduled->active);
        $this->assertSame('2030-01-15 07:00:00', $scheduled->active_from);

        // A featured image that is not there leaves the picture empty; so does one whose file is gone.
        $this->assertNull($this->target('post', Wp::ORPHAN)->preview_picture);
        $this->assertNull($this->target('post', Wp::PIZZA)->photo);
        $this->assertSame('/wp-content/uploads/2024/05/photo.jpg', $this->target('post', Wp::HELLO)->preview_picture);
        $this->assertNull($this->target('post', Wp::GUTENBERG)->preview_picture);
    }

    /**
     * The made-up site with three more posts: one behind a password, one with
     * a gallery of its own pictures and one with a captioned picture; then
     * the whole move.
     */
    private function runWithTheNewKindsOfPosts(string $timezone = 'UTC'): string
    {
        config(['app.timezone' => $timezone]);
        $this->site();
        $this->wpPost(450, 'post', 'Secret', ['post_name' => 'secret', 'post_password' => 'hunter2', 'post_content' => 'For friends only.', 'post_date' => '2024-07-01 12:00:00', 'post_date_gmt' => '2024-07-01 09:00:00']);
        $this->wpPost(451, 'post', 'With a gallery', ['post_name' => 'with-a-gallery', 'post_content' => "See:\n\n[gallery]\n\nand\n\n[gallery ids=\"53,50\"]"]);
        $this->wpPost(452, 'post', 'With a caption', ['post_name' => 'with-a-caption', 'post_content' => '[caption id="attachment_50" width="300"]<img src="'.Wp::OLD_URL.'/wp-content/uploads/2024/05/photo-300x200.jpg" alt="Street"> The street[/caption]']);
        $this->wp()->table('wp_posts')->where('ID', Wp::BORSCHT_PHOTO)->update(['post_parent' => 451]);
        $this->wp()->table('wp_posts')->where('ID', Wp::MANUAL)->update(['post_parent' => 451]);
        $this->generateAndMigrate();
        [$exit, $output] = $this->cli('atrium:wordpress:migrate:data');
        $this->assertSame(0, $exit, $output);

        return $output;
    }

    public function test_a_post_behind_a_password_arrives_unpublished_and_is_told(): void
    {
        $output = $this->runWithTheNewKindsOfPosts();

        $secret = $this->target('post', 450);
        $this->assertFalse((bool) $secret->active);
        $this->assertSame(['en' => '<p>For friends only.</p>'], $this->decoded($secret, 'detail_text'));
        $this->assertSame('2024-07-01 09:00:00', $secret->active_from);
        $this->assertTrue((bool) $this->target('post', Wp::HELLO)->active);
        $this->assertStringContainsString("  - post 450 is protected by a password; it moved unpublished so its text does not go public\n", $output);
        $this->assertSame(1, substr_count($output, 'is protected by a password'));

        // What the old site shows nobody without the password is not a loss the check should report.
        [$exit, $parity] = $this->cli('atrium:wordpress:parity');
        $this->assertSame(0, $exit, $parity);
        $this->assertStringContainsString('Everything moved.', $parity);
    }

    public function test_galleries_and_captions_arrive_as_figures_with_their_files_copied(): void
    {
        $output = $this->runWithTheNewKindsOfPosts();

        $photo = '<figure><img src="/wp-content/uploads/2024/05/photo.jpg" alt="A photo of the street"></figure>';
        $borscht = '<figure><img src="/wp-content/uploads/2024/06/borscht.png" alt="A plate of borscht"></figure>';
        // The pictures attached to the post, then the ones it names: the manual is a PDF, not a picture, so only the photo is left.
        $this->assertSame(
            ['en' => "<p>See:</p>\n".$borscht."\n<p>and</p>\n".$photo],
            $this->decoded($this->target('post', 451), 'detail_text'),
        );
        $this->assertSame(
            ['en' => '<figure><img src="/wp-content/uploads/2024/05/photo-300x200.jpg" alt="Street"><figcaption>The street</figcaption></figure>'],
            $this->decoded($this->target('post', 452), 'detail_text'),
        );
        $this->assertFileExists($this->published('wp-content/uploads/2024/06/borscht.png'));
        $this->assertFileExists($this->published('wp-content/uploads/2024/05/photo-300x200.jpg'));
        $this->assertStringNotContainsString('the gallery of post', $output);
    }

    public function test_the_dates_are_in_the_time_zone_of_the_new_site(): void
    {
        $this->runWithTheNewKindsOfPosts('Europe/Moscow');

        $this->assertSame('2024-05-10 10:00:00', $this->target('post', Wp::HELLO)->active_from);
        $this->assertSame('2024-07-01 12:00:00', $this->target('post', 450)->active_from);
        $this->assertSame('2030-01-15 10:00:00', $this->target('post', Wp::SCHEDULED)->active_from);
    }

    #[DataProvider('plugins')]
    public function test_the_page_tree_keeps_its_parents(string $plugin): void
    {
        $this->fullRun($plugin);

        $id = static fn (int $post): int => (int) DB::table('wordpress_map')->where('kind', 'post')->where('wp_id', $post)->value('target_id');

        $this->assertNull($this->target('post', Wp::ABOUT)->parent_id);
        $this->assertSame($id(Wp::ABOUT), (int) $this->target('post', Wp::TEAM)->parent_id);
        $this->assertSame($id(Wp::TEAM), (int) $this->target('post', Wp::HISTORY)->parent_id);
        // The parent of this one is not in the database: it goes to the top.
        $this->assertNull($this->target('post', Wp::LOST_CHILD)->parent_id);
        $this->assertNull($this->target('post', Wp::CONTACTS)->parent_id);
        $this->assertSame('history', $this->target('post', Wp::HISTORY)->code);
    }

    #[DataProvider('languagePlugins')]
    public function test_terms_authors_and_links(string $plugin, string $default, bool $translated): void
    {
        $this->fullRun($plugin);
        $term = static fn (int $id): int => $id + Wp::TT_OFFSET;

        // The Russian "Новости" is a translation of "News", not a term of its own.
        $news = $this->target('term', $term(Wp::TERM_NEWS));
        $this->assertSame($translated ? [$default => 'News', 'ru' => 'Новости'] : [$default => 'News'], $this->decoded($news, 'name'));
        $this->assertSame('news', $news->code);
        $this->assertFalse($this->moved('term', $term(Wp::TERM_NOVOSTI)));

        // Local is a child of News.
        $local = $this->target('term', $term(Wp::TERM_LOCAL));
        $this->assertSame($news->id, $local->parent_id);
        $this->assertNull($news->parent_id);
        $this->assertSame('Italian', $this->decoded($this->target('term', $term(Wp::TERM_ITALIAN)), 'name')[$default]);

        $this->assertSame([$term(Wp::TERM_NEWS)], $this->linked('news_categories', 'categories', $this->target('post', Wp::HELLO)->id));
        $this->assertSame([$term(Wp::TERM_LOCAL)], $this->linked('news_categories', 'categories', $this->target('post', Wp::GUTENBERG)->id));
        $this->assertSame([$term(Wp::TERM_UNCATEGORIZED)], $this->linked('news_categories', 'categories', $this->target('post', Wp::DRAFT_IDEA)->id));
        $this->assertSame([$term(Wp::TERM_LARAVEL), $term(Wp::TERM_PHP)], $this->linked('news_tags', 'tags', $this->target('post', Wp::HELLO)->id));
        $this->assertSame([], $this->linked('news_tags', 'tags', $this->target('post', Wp::GUTENBERG)->id));
        $this->assertSame([$term(Wp::TERM_RUSSIAN)], $this->linked('recipes_cuisines', 'cuisines', $this->target('post', Wp::BORSCHT)->id));
        $this->assertSame([$term(Wp::TERM_ITALIAN)], $this->linked('recipes_cuisines', 'cuisines', $this->target('post', Wp::PIZZA)->id));
        $this->assertSame(8, DB::table('news_categories')->count());
        $this->assertSame(2, DB::table('news_tags')->count());
        $this->assertSame(2, DB::table('recipes_cuisines')->count());

        // Authors: people to credit, never to log in.
        $alice = $this->target('author', Wp::USER_ALICE);
        $this->assertSame('Alice Writer', $alice->name);
        $this->assertSame('alice', $alice->code);
        $this->assertSame('alice@example.test', $alice->email);
        $this->assertSame('https://alice.example.test', $alice->url);
        $this->assertSame('Writes about Laravel.', $alice->description);
        $boris = $this->target('author', Wp::USER_BORIS);
        $this->assertSame('Борис Автор', $boris->name);
        $this->assertNull($boris->url);
        $this->assertSame(['id', 'wordpress_id', 'name', 'code', 'email', 'url', 'description', 'created_at', 'updated_at'], array_keys((array) $alice));
        $this->assertStringNotContainsString('fakehash', (string) json_encode(DB::table('authors')->get()));

        $this->assertSame([Wp::USER_ALICE], $this->linked('news_authors', 'authors', $this->target('post', Wp::HELLO)->id));
        $this->assertSame([Wp::USER_BORIS], $this->linked('news_authors', 'authors', $this->target('post', Wp::GUTENBERG)->id));
        // User 99 does not exist: this post has no author.
        $this->assertSame([], $this->linked('news_authors', 'authors', $this->target('post', Wp::ORPHAN)->id));
        $this->assertSame([Wp::USER_ALICE], $this->linked('pages_authors', 'authors', $this->target('post', Wp::ABOUT)->id));
        $this->assertSame([Wp::USER_BORIS], $this->linked('recipes_authors', 'authors', $this->target('post', Wp::BORSCHT)->id));
        $this->assertSame([Wp::USER_ALICE], $this->linked('recipes_authors', 'authors', $this->target('post', Wp::PIZZA)->id));
    }

    #[DataProvider('languagePlugins')]
    public function test_custom_fields_of_a_recipe_keep_their_shapes(string $plugin, string $default, bool $translated): void
    {
        $this->fullRun($plugin);
        $texts = static fn (string $first, string $second): array => $translated ? [$default => $first, 'ru' => $second] : [$default => $first];

        $borscht = $this->target('post', Wp::BORSCHT);
        $this->assertEquals(45.0, $borscht->cooking_time);
        $this->assertSame('easy', $borscht->difficulty);
        $this->assertSame($texts("Beetroot\nCabbage", "Свёкла\nКапуста"), $this->decoded($borscht, 'ingredients'));
        $this->assertEquals(1, $borscht->spicy);
        $this->assertSame('2024-01-15', substr((string) $borscht->served_on, 0, 10));
        $this->assertSame('/wp-content/uploads/2024/06/borscht.png', $borscht->photo);
        $this->assertSame(['/wp-content/uploads/2024/05/photo.jpg', '/wp-content/uploads/2024/06/borscht.png'], $this->decoded($borscht, 'gallery'));
        // "s:6:"vegan"" with the wrong length, repaired.
        $this->assertSame(['vegan', 'quick'], $this->decoded($borscht, 'labels'));
        $this->assertSame([['step_text' => 'Boil water'], ['step_text' => 'Add beets']], $this->decoded($borscht, 'steps'));
        $this->assertSame($texts('<p>Serve hot.</p>', '<p>Подавать горячим.</p>'), $this->decoded($borscht, 'notes'));
        // A key without a definition is kept as meta, per language.
        $this->assertSame([$default => 'https://cook.example.test/borscht'], $this->decoded($borscht, 'source_url'));

        $pizza = $this->target('post', Wp::PIZZA);
        $this->assertEquals(20.0, $pizza->cooking_time);
        $this->assertEquals(0, $pizza->spicy);
        $this->assertNotNull($pizza->spicy, 'false is not nothing');
        $this->assertNull($pizza->difficulty);
        $this->assertNull($pizza->ingredients);
        $this->assertNull($pizza->served_on);
        $this->assertNull($pizza->photo);
        $this->assertNull($pizza->gallery);
        $this->assertNull($pizza->labels);
        $this->assertNull($pizza->steps);
        $this->assertNull($pizza->notes);
        $this->assertNull($pizza->source_url);
    }

    #[DataProvider('plugins')]
    public function test_seo_arrives_with_its_templates_filled_in(string $plugin): void
    {
        $this->fullRun($plugin);

        $hello = $this->seoOf('post', Wp::HELLO);
        $this->assertSame('Hello World - Synthetic Blog', $hello->title);
        $this->assertSame('Our first post.', $hello->description);
        $this->assertSame('hello', $hello->keywords);
        // The old site's own canonical address is dropped; its upload moves.
        $this->assertNull($hello->canonical_url);
        $this->assertSame('/wp-content/uploads/2024/05/photo.jpg', $hello->og_image);
        $this->assertNull($hello->robots);

        $gutenberg = $this->seoOf('post', Wp::GUTENBERG);
        $this->assertSame('Gutenberg post - Synthetic Blog', $gutenberg->title);
        $this->assertSame('Blocks everywhere.', $gutenberg->description);
        $this->assertSame('blocks,gutenberg', $gutenberg->keywords);
        $this->assertSame('noindex, nofollow', $gutenberg->robots);
        $this->assertSame('Gutenberg on Facebook', $gutenberg->og_title);

        $about = $this->seoOf('post', Wp::ABOUT);
        $this->assertSame('About us', $about->title);
        $this->assertSame('noindex', $about->robots);

        $this->assertSame('Borscht recipe', $this->seoOf('post', Wp::BORSCHT)->title);
        $this->assertSame('All the news - Synthetic Blog', $this->seoOf('term', Wp::TERM_NEWS + Wp::TT_OFFSET)->title);
        $this->assertSame('Everything about Laravel.', $this->seoOf('term', Wp::TERM_LARAVEL + Wp::TT_OFFSET)->description);

        // Posts with no SEO of their own get no row.
        $this->assertNull($this->seoOf('post', Wp::DRAFT_IDEA));
        $this->assertNull($this->seoOf('post', Wp::PIZZA));
    }

    #[DataProvider('plugins')]
    public function test_files_are_copied_and_listed_in_the_media_library(string $plugin): void
    {
        $this->fullRun($plugin);

        $this->assertSame("\xFF\xD8\xFF\xE0synthetic-photo", file_get_contents($this->published('wp-content/uploads/2024/05/photo.jpg')));
        // The size of a picture, found in its repaired metadata, comes along.
        $this->assertSame("\xFF\xD8\xFF\xE0synthetic-photo-medium", file_get_contents($this->published('wp-content/uploads/2024/05/photo-300x200.jpg')));
        $this->assertFileExists($this->published('wp-content/uploads/2024/06/borscht.png'));
        $this->assertFileExists($this->published('wp-content/uploads/docs/manual.pdf'));
        $this->assertFileDoesNotExist($this->published('wp-content/uploads/2024/07/missing.jpg'));

        $photo = $this->target('attachment', Wp::PHOTO);
        $this->assertSame('/wp-content/uploads/2024/05/photo.jpg', $photo->url);
        $this->assertSame('Photo', $photo->name);
        $this->assertSame('A photo of the street', $photo->description, 'the alt text');
        $this->assertSame('jpg', $photo->extension);
        $this->assertSame('image/jpeg', $photo->mime_type);
        $this->assertTrue((bool) $photo->is_image);
        $this->assertSame(strlen("\xFF\xD8\xFF\xE0synthetic-photo"), (int) $photo->size);
        $this->assertNull($photo->disk);
        $this->assertNull($photo->path);

        $manual = $this->target('attachment', Wp::MANUAL);
        $this->assertSame('Manual', $manual->name);
        $this->assertSame('The printed manual.', $manual->description, 'the description when there is no alt text');
        $this->assertSame('pdf', $manual->extension);
        $this->assertSame('application/pdf', $manual->mime_type);
        $this->assertFalse((bool) $manual->is_image);

        $this->assertSame('png', $this->target('attachment', Wp::BORSCHT_PHOTO)->extension);
        $this->assertFalse($this->moved('attachment', Wp::MISSING_PHOTO), 'a file that is gone is not in the library');

        $collection = DB::table('media_library_collections')->where('name', 'WordPress')->first();
        $this->assertSame([$collection->id], DB::table('media_library_items')->pluck('collection_id')->unique()->values()->all());
        $this->assertNull($collection->parent_id);
    }

    #[DataProvider('plugins')]
    public function test_parity_has_nothing_to_report_after_the_move(string $plugin): void
    {
        $this->fullRun($plugin);

        [$exit, $output] = $this->cli('atrium:wordpress:parity');

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Everything moved.', $output);
        $this->assertStringContainsString('posts:post', $output);
    }

    public function test_the_whole_move_in_one_command(): void
    {
        $this->site();

        [$exit, $output] = $this->cli('atrium:wordpress:migrate');

        $this->assertSame(0, $exit, $output);
        foreach (['atrium:wordpress:generate', 'migrate', 'atrium:wordpress:migrate:data', 'atrium:wordpress:parity'] as $step) {
            $this->assertStringContainsString('› '.$step, $output);
        }
        $this->assertSame(8, DB::table('news')->count());
        $this->assertSame(2, DB::table('recipes')->count());
        $this->assertStringContainsString('Everything moved.', $output);
    }

    public function test_the_one_command_hands_its_options_to_every_step(): void
    {
        $calls = $this->stubTheSteps();

        [$exit] = $this->cli('atrium:wordpress:migrate', ['--connection' => 'wordpress', '--prefix' => 'blog_', '--force' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame([
            'atrium:wordpress:generate' => ['connection' => 'wordpress', 'prefix' => 'blog_', 'force' => true],
            'migrate' => ['path' => $this->workDir.'/migrations', 'realpath' => true, 'force' => true],
            'atrium:wordpress:migrate:data' => ['connection' => 'wordpress', 'prefix' => 'blog_'],
            'atrium:wordpress:parity' => ['connection' => 'wordpress', 'prefix' => 'blog_'],
        ], $calls->getArrayCopy());
    }

    public function test_the_one_command_leaves_out_what_it_was_not_given(): void
    {
        $calls = $this->stubTheSteps();

        $this->cli('atrium:wordpress:migrate');

        $this->assertSame(['connection' => null, 'prefix' => null, 'force' => false], $calls['atrium:wordpress:generate']);
        $this->assertSame(['connection' => null, 'prefix' => null], $calls['atrium:wordpress:migrate:data']);
        $this->assertSame(['connection' => null, 'prefix' => null], $calls['atrium:wordpress:parity']);
    }

    /**
     * Commands standing in for the four steps; they write down the options
     * they were called with.
     *
     * @return ArrayObject<string,array<string,mixed>>
     */
    private function stubTheSteps(): ArrayObject
    {
        $calls = new ArrayObject;

        foreach ([
            'atrium:wordpress:generate {--connection=} {--prefix=} {--force}',
            'migrate {--path=} {--realpath} {--force}',
            'atrium:wordpress:migrate:data {--connection=} {--prefix=}',
            'atrium:wordpress:parity {--connection=} {--prefix=}',
        ] as $signature) {
            Artisan::registerCommand(new class($signature, $calls) extends Command
            {
                /**
                 * @param  ArrayObject<string,array<string,mixed>>  $calls
                 */
                public function __construct(string $signature, private readonly ArrayObject $calls)
                {
                    $this->signature = $signature;

                    parent::__construct();
                }

                public function handle(): int
                {
                    $this->calls[$this->getName()] = Arr::only($this->options(), ['connection', 'prefix', 'force', 'path', 'realpath']);

                    return self::SUCCESS;
                }
            });
        }

        return $calls;
    }

    public function test_the_one_command_stops_when_a_step_fails(): void
    {
        $this->site();
        // A file the importer did not write is in the way of its model.
        file_put_contents($this->workDir.'/models/Recipe.php', "<?php\n// mine\n");

        [$exit, $output] = $this->cli('atrium:wordpress:migrate');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('› atrium:wordpress:generate', $output);
        $this->assertStringNotContainsString('› migrate', $output);
        $this->assertSame(0, DB::table('news')->count());
    }
}
