<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Feature;

use Atrium\Admin\Models\MediaItem;
use Atrium\News\Models\News;
use Atrium\WordPress\Tests\Concerns\ImportsWordPress;
use Atrium\WordPress\Tests\Fixtures\SyntheticWordPress as Wp;
use Atrium\WordPress\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Moving the content: what the command says, what it refuses, how it keeps
 * to the uploads folder, that a failed chunk leaves nothing behind and that
 * a second run updates instead of adding.
 */
final class MigrateDataTest extends TestCase
{
    use ImportsWordPress;

    /**
     * Every table the move writes to, without the columns that hold the time.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function snapshot(): array
    {
        $snapshot = [];

        foreach ([
            'news', 'pages', 'recipes', 'categories', 'tags', 'cuisines', 'authors', 'seo_meta',
            'media_library_items', 'media_library_collections', 'admin_languages', 'wordpress_map',
            'news_categories', 'news_tags', 'news_authors', 'pages_authors', 'recipes_cuisines', 'recipes_authors',
        ] as $table) {
            $rows = DB::table($table)->get()->map(static fn (object $row): array => array_diff_key((array) $row, array_flip(['created_at', 'updated_at'])))->all();
            usort($rows, static fn (array $a, array $b): int => strcmp((string) json_encode($a), (string) json_encode($b)));
            $snapshot[$table] = $rows;
        }

        return $snapshot;
    }

    public function test_it_says_what_it_moved_and_what_is_broken_on_the_old_site(): void
    {
        $this->site();
        $this->generateAndMigrate();

        [$exit, $output] = $this->cli('atrium:wordpress:migrate:data');

        $this->assertSame(0, $exit);
        foreach ([
            'languages' => 2, 'authors' => 2, 'terms:category' => 3, 'terms:cuisine' => 2, 'terms:post_tag' => 2,
            'files' => 3, 'posts:page' => 5, 'posts:post' => 8, 'posts:recipe' => 2,
        ] as $what => $count) {
            $this->assertMatchesRegularExpression('/\| '.preg_quote($what, '/').'\s+\| '.$count.'\s+\|/', $output, $what);
        }
        $this->assertStringContainsString('| Moved', $output);
        $this->assertStringContainsString("1 files are missing in the old uploads folder:\n  2024/07/missing.jpg\n", $output);
        $this->assertStringContainsString("Already broken on the old site (moved as well as it could be):\n  - post 34 is a child of post 999", $output);
        $this->assertStringEndsWith("The default language is now en.\n", $output);
        $this->assertStringNotContainsString('  post: 8', $output);
    }

    public function test_a_verbose_run_reports_the_progress_of_each_type(): void
    {
        $this->site();
        $this->generateAndMigrate();

        [, $output] = $this->cli('atrium:wordpress:migrate:data', ['-v' => true]);

        $this->assertStringStartsWith("  page: 5\n  post: 8\n  recipe: 2\n", $output);
    }

    public function test_the_chunk_size_changes_how_the_work_is_cut_but_not_what_is_moved(): void
    {
        $this->site();
        $this->generateAndMigrate();
        config()->set('atrium-wordpress.chunk_size', 3);

        [, $output] = $this->cli('atrium:wordpress:migrate:data', ['-v' => true]);

        $this->assertStringContainsString("  post: 3\n  post: 6\n  post: 8\n", $output);
        $this->assertSame(8, DB::table('news')->count());
        $this->assertSame(3, DB::table('categories')->count());
        $snapshot = $this->snapshot();

        config()->set('atrium-wordpress.chunk_size', 0);
        [, $output] = $this->cli('atrium:wordpress:migrate:data', ['-v' => true]);

        $this->assertStringContainsString("  post: 1\n  post: 2\n  post: 3\n", $output);
        $this->assertEquals($snapshot, $this->snapshot());
    }

    public function test_only_missing_tables_stop_the_move_and_nothing_is_written(): void
    {
        $this->site();

        [$exit, $output] = $this->cli('atrium:wordpress:migrate:data');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'These tables are not there yet: pages_authors, news_categories, news_tags, news_authors, recipes, recipes_cuisines, recipes_authors, categories, cuisines, tags, authors. Run atrium:wordpress:generate and php artisan migrate first.',
            $output,
        );
        $this->assertSame(0, DB::table('wordpress_map')->count());
        $this->assertSame(0, DB::table('news')->count());
        $this->assertSame(1, DB::table('admin_languages')->count());
    }

    public function test_one_missing_table_is_named(): void
    {
        $this->site();
        $this->generateAndMigrate();
        Schema::dropIfExists('news_tags');
        Schema::dropIfExists('tags');

        [$exit, $output] = $this->cli('atrium:wordpress:migrate:data');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('These tables are not there yet: news_tags, tags. Run', $output);
        $this->assertSame(0, DB::table('news')->count());
    }

    public function test_a_table_two_types_share_is_named_once(): void
    {
        config()->set('atrium-wordpress.post_types', ['recipe' => 'news']);
        $this->site();
        $this->generateAndMigrate();
        Schema::dropIfExists('news_authors');

        [$exit, $output] = $this->cli('atrium:wordpress:migrate:data');

        $this->assertSame(1, $exit);
        $this->assertSame(1, substr_count($output, 'news_authors'), $output);
    }

    public function test_the_map_table_is_needed_too(): void
    {
        $this->site();
        $this->generateAndMigrate();
        Schema::dropIfExists('wordpress_map');

        [$exit, $output] = $this->cli('atrium:wordpress:migrate:data');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('These tables are not there yet: wordpress_map. Run', $output);
    }

    public function test_a_connection_that_is_not_configured_stops_the_command(): void
    {
        $this->site();
        $this->generateAndMigrate();

        try {
            $this->cli('atrium:wordpress:migrate:data', ['--connection' => 'nowhere']);
            $this->fail('A connection that is not there must stop the move.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Database connection [nowhere] not configured.', $e->getMessage());
        }

        $this->assertSame(0, DB::table('wordpress_map')->count());
    }

    public function test_a_prefix_without_tables_stops_the_command_before_anything_is_written(): void
    {
        $this->site();
        $this->generateAndMigrate();

        try {
            $this->cli('atrium:wordpress:migrate:data', ['--prefix' => 'other_']);
            $this->fail('A prefix without tables must stop the move.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('other_options', $e->getMessage());
        }

        $this->assertSame(0, DB::table('wordpress_map')->count());
        $this->assertSame(1, DB::table('admin_languages')->count());
    }

    public function test_a_site_with_another_prefix_and_connection_is_moved_when_they_are_named(): void
    {
        $this->site('wpml', 'blog_');
        config()->set('atrium-wordpress.table_prefix', 'blog_');
        $this->generateAndMigrate();
        config()->set('atrium-wordpress.connection', 'nowhere');
        config()->set('atrium-wordpress.table_prefix', 'wrong_');

        [$exit, $output] = $this->cli('atrium:wordpress:migrate:data', ['--connection' => 'wordpress', '--prefix' => 'blog_']);

        $this->assertSame(0, $exit, $output);
        $this->assertSame(8, DB::table('news')->count());
    }

    public function test_the_files_that_are_missing_are_listed_twenty_at_most(): void
    {
        $this->site();
        for ($i = 0; $i < 25; $i++) {
            $this->wpPost(100 + $i, 'attachment', 'Gone '.$i, ['post_status' => 'inherit', 'post_mime_type' => 'image/jpeg']);
            $this->wpMeta(100 + $i, ['_wp_attached_file' => "2025/01/gone-{$i}.jpg"]);
        }
        $this->generateAndMigrate();

        [, $output] = $this->cli('atrium:wordpress:migrate:data');

        $this->assertStringContainsString("26 files are missing in the old uploads folder:\n  2024/07/missing.jpg\n  2025/01/gone-0.jpg\n", $output);
        $this->assertStringContainsString("\n  2025/01/gone-18.jpg\n", $output);
        $this->assertStringNotContainsString('gone-19.jpg', $output);
        $this->assertSame(3, DB::table('media_library_items')->count());
    }

    public function test_nothing_outside_the_uploads_folder_is_copied(): void
    {
        $this->site();
        file_put_contents($this->workDir.'/secret.txt', 'secret');
        file_put_contents($this->workDir.'/uploads/b.txt', 'b');
        file_put_contents($this->workDir.'/uploads/..hidden.txt', 'hidden');
        file_put_contents($this->workDir.'/uploads/x..y.txt', 'xy');
        file_put_contents($this->workDir.'/uploads/inline.pdf', 'inline');

        foreach ([
            101 => '../secret.txt',
            102 => '2024/../../secret.txt',
            103 => 'a/../b.txt',
            104 => '..\\secret.txt',
            105 => '..hidden.txt',
            106 => 'x..y.txt',
            107 => '/../secret.txt',
        ] as $id => $file) {
            $this->wpPost($id, 'attachment', 'File '.$id, ['post_status' => 'inherit']);
            $this->wpMeta($id, ['_wp_attached_file' => $file]);
        }
        $this->wpPost(450, 'post', 'Links', ['post_name' => 'links', 'post_content' => '<a href="/wp-content/uploads/../secret.txt">a</a> <a href="/wp-content/uploads/inline.pdf">b</a>']);
        $this->generateAndMigrate();

        [$exit, $output] = $this->cli('atrium:wordpress:migrate:data');

        $this->assertSame(0, $exit);
        $this->assertFileDoesNotExist($this->published('wp-content/secret.txt'));
        $this->assertSame([], glob($this->workDir.'/public/*/*/secret.txt') ?: []);
        $this->assertSame([], glob($this->workDir.'/public/*/secret.txt') ?: []);
        foreach ([101, 102, 103, 104, 107] as $id) {
            $this->assertFalse($this->moved('attachment', $id), "attachment {$id} must not be moved");
        }
        foreach ([105, 106] as $id) {
            $this->assertTrue($this->moved('attachment', $id), "attachment {$id} is inside the folder");
        }
        $this->assertFileExists($this->published('wp-content/uploads/..hidden.txt'));
        $this->assertFileExists($this->published('wp-content/uploads/x..y.txt'));
        $this->assertFileExists($this->published('wp-content/uploads/inline.pdf'));
        $this->assertFileDoesNotExist($this->published('wp-content/uploads/b.txt'));
        $this->assertStringContainsString('files are missing in the old uploads folder:', $output);
        foreach (['../secret.txt', '2024/../../secret.txt', 'a/../b.txt'] as $refused) {
            $this->assertStringContainsString("\n  {$refused}\n", $output);
        }
        $this->assertSame(1, substr_count($output, "\n  ../secret.txt\n"), 'the same refused file is listed once');
    }

    public function test_a_file_is_copied_again_only_when_its_size_is_different(): void
    {
        $this->fullRun();
        $target = $this->published('wp-content/uploads/2024/05/photo.jpg');
        file_put_contents($target, 'x');
        $modified = filemtime($this->published('wp-content/uploads/docs/manual.pdf'));
        touch($this->published('wp-content/uploads/docs/manual.pdf'), 1_000_000_000);

        $this->cli('atrium:wordpress:migrate:data');

        $this->assertSame("\xFF\xD8\xFF\xE0synthetic-photo", file_get_contents($target));
        $this->assertSame(1_000_000_000, filemtime($this->published('wp-content/uploads/docs/manual.pdf')), 'a file of the same size is left alone');
        $this->assertNotSame($modified, filemtime($this->published('wp-content/uploads/docs/manual.pdf')));
    }

    public function test_the_uploads_go_where_the_config_says(): void
    {
        $this->site();
        config()->set('atrium-wordpress.uploads_target', '/media/old');
        $this->generateAndMigrate();

        $this->cli('atrium:wordpress:migrate:data');

        $this->assertFileExists($this->published('media/old/2024/05/photo.jpg'));
        $this->assertSame('/media/old/2024/05/photo.jpg', $this->target('attachment', Wp::PHOTO)->url);
    }

    public function test_a_second_run_updates_what_the_first_wrote(): void
    {
        $this->fullRun();
        $first = $this->snapshot();
        $hello = $this->target('post', Wp::HELLO)->id;

        [$exit] = $this->cli('atrium:wordpress:migrate:data');

        $this->assertSame(0, $exit);
        $this->assertEquals($first, $this->snapshot());
        $this->assertSame($hello, $this->target('post', Wp::HELLO)->id);
        $this->assertSame(27, DB::table('wordpress_map')->count());
        $this->assertSame(2, DB::table('admin_languages')->count());
    }

    public function test_a_second_run_brings_the_changes_of_wordpress(): void
    {
        $this->fullRun();
        $hello = $this->target('post', Wp::HELLO)->id;
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO)->update(['post_title' => 'Hello again', 'post_modified' => '2024-07-01 10:00:00']);
        $this->wp()->table('wp_terms')->where('term_id', Wp::TERM_PHP)->update(['name' => 'PHP language']);
        $this->wp()->table('wp_users')->where('ID', Wp::USER_ALICE)->update(['display_name' => 'Alice W.']);
        $this->wpPost(450, 'post', 'Brand new', ['post_name' => 'brand-new']);
        DB::table('news')->where('id', $hello)->update(['name' => '{"en":"Edited in Atrium"}']);

        $this->cli('atrium:wordpress:migrate:data');

        $this->assertSame(['en' => 'Hello again', 'ru' => 'Привет, мир'], $this->decoded($this->target('post', Wp::HELLO), 'name'));
        $this->assertSame($hello, $this->target('post', Wp::HELLO)->id);
        $this->assertSame(['en' => 'PHP language'], $this->decoded($this->target('term', Wp::TERM_PHP + Wp::TT_OFFSET), 'name'));
        $this->assertSame('Alice W.', $this->target('author', Wp::USER_ALICE)->name);
        $this->assertSame(9, DB::table('news')->count());
        $this->assertTrue($this->moved('post', 450));
    }

    public function test_only_changed_leaves_alone_what_did_not_change(): void
    {
        $this->fullRun();
        DB::table('news')->where('id', $this->target('post', Wp::GUTENBERG)->id)->update(['name' => '{"en":"Edited in Atrium"}']);
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO)->update(['post_title' => 'Hello again', 'post_modified' => '2024-07-01 10:00:00']);
        $this->wpPost(450, 'post', 'Brand new', ['post_name' => 'brand-new']);

        [$exit, $output] = $this->cli('atrium:wordpress:migrate:data', ['--only-changed' => true]);

        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression('/\| posts:post\s+\| 2\s+\|/', $output);
        $this->assertMatchesRegularExpression('/\| posts:page\s+\| 0\s+\|/', $output);
        $this->assertMatchesRegularExpression('/\| posts:recipe\s+\| 0\s+\|/', $output);
        $this->assertSame(['en' => 'Edited in Atrium'], $this->decoded($this->target('post', Wp::GUTENBERG), 'name'));
        $this->assertSame('Hello again', $this->decoded($this->target('post', Wp::HELLO), 'name')['en']);
        $this->assertSame(9, DB::table('news')->count());
        $this->assertSame('2024-07-01 10:00:00', $this->mapRow('post', Wp::HELLO)->wp_modified);
    }

    public function test_a_change_of_a_translation_counts_as_a_change_of_the_record(): void
    {
        $this->fullRun();
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO_RU)->update(['post_title' => 'Привет снова', 'post_modified' => '2024-08-01 10:00:00']);

        [, $output] = $this->cli('atrium:wordpress:migrate:data', ['--only-changed' => true]);

        $this->assertMatchesRegularExpression('/\| posts:post\s+\| 1\s+\|/', $output);
        $this->assertSame('Привет снова', $this->decoded($this->target('post', Wp::HELLO), 'name')['ru']);
    }

    public function test_only_changed_writes_again_a_record_whose_row_is_gone_or_of_another_model(): void
    {
        $this->fullRun();
        DB::table('news')->where('id', $this->target('post', Wp::GUTENBERG)->id)->delete();
        DB::table('wordpress_map')->where('kind', 'post')->where('wp_id', Wp::ENCODING)->update(['target_type' => 'Some\\Other']);

        [, $output] = $this->cli('atrium:wordpress:migrate:data', ['--only-changed' => true]);

        $this->assertMatchesRegularExpression('/\| posts:post\s+\| 2\s+\|/', $output);
        $this->assertSame(8, DB::table('news')->count());
        $this->assertSame(News::class, $this->mapRow('post', Wp::ENCODING)->target_type);
        $this->assertSame(['en' => 'Gutenberg post'], $this->decoded($this->target('post', Wp::GUTENBERG), 'name'));
    }

    public function test_only_changed_before_any_run_moves_everything(): void
    {
        $this->site();
        $this->generateAndMigrate();

        [$exit, $output] = $this->cli('atrium:wordpress:migrate:data', ['--only-changed' => true]);

        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression('/\| posts:post\s+\| 8\s+\|/', $output);
        $this->assertSame(27, DB::table('wordpress_map')->count());
    }

    public function test_the_page_tree_is_set_again_even_when_only_a_child_changed(): void
    {
        $this->fullRun();
        $this->wp()->table('wp_posts')->where('ID', Wp::HISTORY)->update(['post_parent' => Wp::ABOUT, 'post_modified' => '2024-08-01 10:00:00']);

        $this->cli('atrium:wordpress:migrate:data', ['--only-changed' => true]);

        $this->assertSame($this->target('post', Wp::ABOUT)->id, (int) $this->target('post', Wp::HISTORY)->parent_id);
    }

    public function test_the_content_goes_to_the_site_of_the_config(): void
    {
        DB::table('admin_sites')->insert(['code' => 'shop', 'name' => 'Shop', 'active' => true, 'is_default' => false, 'sort' => 200, 'created_at' => now(), 'updated_at' => now()]);
        $shop = (int) DB::table('admin_sites')->where('code', 'shop')->value('id');
        $main = (int) DB::table('admin_sites')->where('code', 'main')->value('id');
        config()->set('atrium-wordpress.site', 'shop');
        $this->fullRun();

        $this->assertSame($shop, (int) $this->target('post', Wp::HELLO)->site_id);
        $this->assertSame($shop, (int) $this->target('post', Wp::ABOUT)->site_id);
        $this->assertSame($shop, (int) $this->target('post', Wp::BORSCHT)->site_id);
        $this->assertSame($shop, (int) $this->target('term', Wp::TERM_NEWS + Wp::TT_OFFSET)->site_id);
        $this->assertNotSame($main, $shop);
    }

    public function test_the_content_goes_to_the_default_site_without_one_in_the_config(): void
    {
        $main = (int) DB::table('admin_sites')->where('is_default', true)->value('id');
        $this->fullRun();

        $this->assertSame($main, (int) $this->target('post', Wp::HELLO)->site_id);
        $this->assertSame($main, (int) $this->target('term', Wp::TERM_NEWS + Wp::TT_OFFSET)->site_id);
    }

    public function test_an_empty_site_code_is_the_default_site(): void
    {
        config()->set('atrium-wordpress.site', '');
        $main = (int) DB::table('admin_sites')->where('is_default', true)->value('id');
        $this->fullRun();

        $this->assertSame($main, (int) $this->target('post', Wp::HELLO)->site_id);
    }

    public function test_a_site_that_is_not_there_stops_the_move(): void
    {
        $this->site();
        $this->generateAndMigrate();
        config()->set('atrium-wordpress.site', 'nowhere');

        try {
            $this->cli('atrium:wordpress:migrate:data');
            $this->fail('A site that is not there must stop the move.');
        } catch (RuntimeException $e) {
            $this->assertSame('There is no Atrium site with the code "nowhere" (atrium-wordpress.site).', $e->getMessage());
        }

        $this->assertSame(0, DB::table('wordpress_map')->count());
    }

    public function test_a_failed_write_takes_back_the_whole_chunk(): void
    {
        $this->site();
        $this->generateAndMigrate();
        $saved = 0;
        $listener = static function () use (&$saved): void {
            if (++$saved === 2) {
                throw new RuntimeException('the second write fails');
            }
        };
        Event::listen('eloquent.saving: '.News::class, $listener);

        try {
            $this->cli('atrium:wordpress:migrate:data');
            $this->fail('The failed write must stop the move.');
        } catch (RuntimeException $e) {
            $this->assertSame('the second write fails', $e->getMessage());
        } finally {
            Event::forget('eloquent.saving: '.News::class);
        }

        $this->assertSame(2, $saved);
        // The first write of the chunk is gone with its links, SEO and map row.
        $this->assertSame(0, DB::table('news')->count());
        $this->assertSame(0, DB::table('news_categories')->count());
        $this->assertSame(0, DB::table('news_tags')->count());
        $this->assertSame(0, DB::table('news_authors')->count());
        $this->assertSame(0, DB::table('seo_meta')->where('seoable_type', News::class)->count());
        $this->assertSame(0, DB::table('wordpress_map')->where('kind', 'post')->where('wp_type', 'post')->count());
        $this->assertFalse($this->moved('post', Wp::HELLO));
        // What was written in earlier steps stays, and a new run does the rest.
        $this->assertSame(2, DB::table('authors')->count());
        $this->assertSame(3, DB::table('categories')->count());

        [$exit] = $this->cli('atrium:wordpress:migrate:data');

        $this->assertSame(0, $exit);
        $this->assertSame(8, DB::table('news')->count());
        $this->assertSame(2, DB::table('authors')->count());
        $this->assertSame(27, DB::table('wordpress_map')->count());
    }

    public function test_a_failed_chunk_does_not_undo_the_chunks_before_it(): void
    {
        $this->site();
        $this->generateAndMigrate();
        config()->set('atrium-wordpress.chunk_size', 3);
        $saved = 0;
        Event::listen('eloquent.saving: '.News::class, static function () use (&$saved): void {
            if (++$saved === 5) {
                throw new RuntimeException('the fifth write fails');
            }
        });

        try {
            $this->cli('atrium:wordpress:migrate:data');
            $this->fail('The failed write must stop the move.');
        } catch (RuntimeException) {
            // expected
        } finally {
            Event::forget('eloquent.saving: '.News::class);
        }

        // The first chunk of three records is in; the second one, whose 2nd write failed, is not.
        $this->assertSame(3, DB::table('news')->count());
        $this->assertSame(3, DB::table('wordpress_map')->where('kind', 'post')->where('wp_type', 'post')->count());
    }

    public function test_a_chunk_is_tried_again_after_a_deadlock(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('A deadlock is retried only outside the transaction of the test, whose rows MySQL and PostgreSQL would keep.');
        }

        $this->site();
        $this->generateAndMigrate();
        // The transaction of the test is committed: the importer's own are the outermost ones, which Laravel retries.
        DB::connection()->commit();
        $tries = 0;
        Event::listen('eloquent.saving: '.News::class, static function () use (&$tries): void {
            if (++$tries === 2) {
                throw new QueryException('testing', 'update', [], new \PDOException('database table is locked'));
            }
        });

        [$exit, $output] = $this->cli('atrium:wordpress:migrate:data');

        Event::forget('eloquent.saving: '.News::class);
        $this->assertSame(0, $exit, $output);
        $this->assertSame(8, DB::table('news')->count());
        $this->assertGreaterThan(8, $tries, 'the chunk was written twice');
    }

    public function test_a_deadlock_that_comes_again_stops_the_move_after_three_tries(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('A deadlock is retried only outside the transaction of the test, whose rows MySQL and PostgreSQL would keep.');
        }

        $this->site();
        $this->generateAndMigrate();
        DB::connection()->commit();
        $tries = 0;
        Event::listen('eloquent.saving: '.News::class, static function () use (&$tries): void {
            $tries++;

            throw new QueryException('testing', 'update', [], new \PDOException('database table is locked'));
        });

        try {
            $this->cli('atrium:wordpress:migrate:data');
            $this->fail('A deadlock that does not go away must stop the move.');
        } catch (QueryException) {
            // expected
        } finally {
            Event::forget('eloquent.saving: '.News::class);
        }

        $this->assertSame(3, $tries);
        $this->assertSame(0, DB::table('news')->count());
    }

    public function test_a_second_run_brings_the_changed_seo_of_a_post_and_a_term_without_a_second_row(): void
    {
        $this->fullRun();
        $hello = $this->target('post', Wp::HELLO);
        $this->assertNotNull($this->seoOf('post', Wp::HELLO));
        $rows = DB::table('seo_meta')->count();
        $this->wp()->table('wp_postmeta')->where('post_id', Wp::HELLO)->where('meta_key', '_yoast_wpseo_metadesc')->update(['meta_value' => 'A new description.']);
        $this->wp()->table('wp_postmeta')->where('post_id', Wp::HELLO)->where('meta_key', '_yoast_wpseo_title')->update(['meta_value' => 'A new title']);

        $this->cli('atrium:wordpress:migrate:data');

        $seo = $this->seoOf('post', Wp::HELLO);
        $this->assertSame('A new title', $seo->title);
        $this->assertSame('A new description.', $seo->description);
        $this->assertSame($rows, DB::table('seo_meta')->count());
        $this->assertSame(1, DB::table('seo_meta')->where('seoable_type', News::class)->where('seoable_id', $hello->id)->count());
    }

    public function test_the_media_library_keeps_a_row_per_file_on_a_second_run(): void
    {
        $this->fullRun();
        $before = MediaItem::query()->pluck('id', 'url')->all();

        $this->cli('atrium:wordpress:migrate:data');

        $this->assertSame($before, MediaItem::query()->pluck('id', 'url')->all());
        $this->assertSame(1, DB::table('media_library_collections')->count());
    }
}
