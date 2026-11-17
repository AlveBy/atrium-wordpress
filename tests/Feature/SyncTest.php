<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Feature;

use Atrium\News\Models\News;
use Atrium\WordPress\Tests\Concerns\ImportsWordPress;
use Atrium\WordPress\Tests\Fixtures\SyntheticWordPress as Wp;
use Atrium\WordPress\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * Catching up with a site that is still being edited: what changed comes
 * over, what was deleted goes away only when asked, and the audit says
 * whether the two sites agree.
 */
final class SyncTest extends TestCase
{
    use ImportsWordPress;

    private function gone(int $id): void
    {
        $this->wp()->table('wp_posts')->where('ID', $id)->delete();
    }

    public function test_it_brings_what_changed_and_leaves_what_was_deleted(): void
    {
        $this->fullRun();
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO)->update(['post_title' => 'Hello again', 'post_modified' => '2024-07-01 10:00:00']);
        $this->gone(Wp::GUTENBERG);

        [$exit, $output] = $this->cli('atrium:wordpress:sync');

        $this->assertSame(0, $exit, $output);
        $this->assertMatchesRegularExpression('/\| posts:post\s+\| 1\s+\|/', $output);
        $this->assertSame('Hello again', $this->decoded($this->target('post', Wp::HELLO), 'name')['en']);
        $this->assertStringNotContainsString('Deleted:', $output);
        $this->assertStringNotContainsString('Everything moved.', $output);
        $this->assertTrue($this->moved('post', Wp::GUTENBERG));
        $this->assertSame(8, DB::table('news')->count());
    }

    public function test_it_stops_when_the_move_cannot_run(): void
    {
        $this->site();

        [$exit, $output] = $this->cli('atrium:wordpress:sync', ['--prune' => true, '--audit' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('These tables are not there yet', $output);
        $this->assertStringNotContainsString('Deleted:', $output);
        $this->assertStringNotContainsString('Everything moved.', $output);
    }

    public function test_prune_takes_away_a_deleted_post_with_its_links_and_seo(): void
    {
        $this->fullRun();
        $hello = $this->target('post', Wp::HELLO)->id;
        $other = $this->target('post', Wp::GUTENBERG)->id;
        $this->assertGreaterThan(0, DB::table('news_categories')->where('record_id', $hello)->count());
        $this->assertGreaterThan(0, DB::table('news_authors')->where('record_id', $hello)->count());
        $this->assertSame(1, DB::table('seo_meta')->where('seoable_type', News::class)->where('seoable_id', $hello)->count());
        $authors = DB::table('news_authors')->where('record_id', $other)->count();
        $this->gone(Wp::HELLO);
        $this->gone(Wp::HELLO_RU);

        [$exit, $output] = $this->cli('atrium:wordpress:sync', ['--prune' => true]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Deleted: 1', $output);
        $this->assertNull(DB::table('news')->find($hello));
        $this->assertSame(0, DB::table('news_categories')->where('record_id', $hello)->count());
        $this->assertSame(0, DB::table('news_tags')->where('record_id', $hello)->count());
        $this->assertSame(0, DB::table('news_authors')->where('record_id', $hello)->count());
        $this->assertSame(0, DB::table('seo_meta')->where('seoable_type', News::class)->where('seoable_id', $hello)->count());
        $this->assertFalse($this->moved('post', Wp::HELLO));
        $this->assertSame(7, DB::table('news')->count());
        $this->assertSame($authors, DB::table('news_authors')->where('record_id', $other)->count());
        $this->assertNotNull(DB::table('news')->find($other));
        $this->assertSame(26, DB::table('wordpress_map')->count());
    }

    public function test_prune_without_anything_to_take_away_says_so(): void
    {
        $this->fullRun();

        [$exit, $output] = $this->cli('atrium:wordpress:sync', ['--prune' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Deleted: 0', $output);
        $this->assertSame(27, DB::table('wordpress_map')->count());
    }

    public function test_prune_takes_away_a_trashed_post_and_one_that_is_not_a_post_any_more(): void
    {
        $this->fullRun();
        $this->wp()->table('wp_posts')->where('ID', Wp::GUTENBERG)->update(['post_status' => 'trash']);
        $this->wp()->table('wp_posts')->where('ID', Wp::ENCODING)->update(['post_type' => 'revision']);

        [, $output] = $this->cli('atrium:wordpress:sync', ['--prune' => true]);

        $this->assertStringContainsString('Deleted: 2', $output);
        $this->assertFalse($this->moved('post', Wp::GUTENBERG));
        $this->assertFalse($this->moved('post', Wp::ENCODING));
        $this->assertSame(6, DB::table('news')->count());
    }

    public function test_prune_without_the_option_keeps_everything(): void
    {
        $this->fullRun();
        $this->gone(Wp::HELLO);

        $this->gone(Wp::HELLO_RU);

        $this->cli('atrium:wordpress:migrate:data', ['--only-changed' => true]);

        $this->assertTrue($this->moved('post', Wp::HELLO));
        $this->assertSame(8, DB::table('news')->count());
    }

    public function test_prune_moves_the_children_of_a_deleted_page_up(): void
    {
        $this->fullRun();
        $about = $this->target('post', Wp::ABOUT)->id;
        $history = $this->target('post', Wp::HISTORY)->id;
        $this->assertSame($this->target('post', Wp::TEAM)->id, (int) $this->target('post', Wp::HISTORY)->parent_id);
        $this->gone(Wp::TEAM);
        $this->wp()->table('wp_posts')->where('ID', Wp::HISTORY)->update(['post_parent' => Wp::ABOUT, 'post_modified' => '2024-08-01 10:00:00']);

        $this->cli('atrium:wordpress:sync', ['--prune' => true]);

        $this->assertFalse($this->moved('post', Wp::TEAM));
        $this->assertSame($about, (int) DB::table('pages')->find($history)->parent_id);
        $this->assertSame(4, DB::table('pages')->count());
    }

    public function test_prune_cleans_the_links_of_a_type_that_has_no_posts_left(): void
    {
        $this->fullRun();
        $this->assertGreaterThan(0, DB::table('recipes_cuisines')->count());
        $this->assertGreaterThan(0, DB::table('recipes_authors')->count());
        $model = $this->generatedRecipe();
        $this->assertGreaterThan(0, DB::table('seo_meta')->where('seoable_type', $model)->count());
        foreach ([Wp::BORSCHT, Wp::BORSCHT_RU, Wp::PIZZA] as $id) {
            $this->gone($id);
        }

        [, $output] = $this->cli('atrium:wordpress:sync', ['--prune' => true]);

        $this->assertStringContainsString('Deleted: 2', $output);
        $this->assertSame(0, DB::table('recipes')->count());
        $this->assertSame(0, DB::table('recipes_cuisines')->count());
        $this->assertSame(0, DB::table('recipes_authors')->count());
        $this->assertSame(0, DB::table('seo_meta')->where('seoable_type', $model)->count());
    }

    public function test_prune_takes_away_the_row_of_a_model_that_does_not_exist_any_more(): void
    {
        $this->fullRun();
        $hello = $this->target('post', Wp::HELLO)->id;
        DB::table('wordpress_map')->where('kind', 'post')->where('wp_id', Wp::HELLO)->update(['target_type' => 'Gone\\Model']);
        $this->gone(Wp::HELLO);

        [$exit, $output] = $this->cli('atrium:wordpress:sync', ['--prune' => true]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Deleted: 1', $output);
        $this->assertFalse($this->moved('post', Wp::HELLO));
        $this->assertNotNull(DB::table('news')->find($hello), 'a row of a model nobody knows is not touched');
    }

    public function test_prune_takes_away_the_row_of_a_type_that_is_not_in_the_plan(): void
    {
        $this->fullRun();
        $hello = $this->target('post', Wp::HELLO)->id;
        DB::table('wordpress_map')->where('kind', 'post')->where('wp_id', Wp::HELLO)->update(['wp_type' => 'vanished']);
        $this->gone(Wp::HELLO);

        [$exit] = $this->cli('atrium:wordpress:sync', ['--prune' => true]);

        $this->assertSame(0, $exit);
        $this->assertFalse($this->moved('post', Wp::HELLO));
        $this->assertNull(DB::table('news')->find($hello), 'the model is still there, so the record goes');
    }

    public function test_prune_takes_away_only_posts(): void
    {
        $this->fullRun();
        $terms = DB::table('wordpress_map')->where('kind', 'term')->count();
        $authors = DB::table('wordpress_map')->where('kind', 'author')->count();
        $files = DB::table('wordpress_map')->where('kind', 'attachment')->count();
        $this->wp()->table('wp_terms')->delete();
        $this->wp()->table('wp_users')->delete();

        $this->cli('atrium:wordpress:migrate:data', ['--only-changed' => true]);
        [, $output] = $this->cli('atrium:wordpress:sync', ['--prune' => true]);

        $this->assertStringContainsString('Deleted: 0', $output);
        $this->assertSame($terms, DB::table('wordpress_map')->where('kind', 'term')->count());
        $this->assertSame($authors, DB::table('wordpress_map')->where('kind', 'author')->count());
        $this->assertSame($files, DB::table('wordpress_map')->where('kind', 'attachment')->count());
    }

    public function test_prune_works_in_chunks_of_two_hundred(): void
    {
        $this->fullRun();
        $rows = [];
        for ($i = 0; $i < 205; $i++) {
            $rows[] = ['kind' => 'post', 'wp_id' => 5000 + $i, 'wp_type' => 'post', 'target_type' => 'Gone\\Model', 'target_id' => 1, 'paths' => '[]', 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('wordpress_map')->insert($rows);

        [, $output] = $this->cli('atrium:wordpress:sync', ['--prune' => true]);

        $this->assertStringContainsString('Deleted: 205', $output);
        $this->assertSame(27, DB::table('wordpress_map')->count());
    }

    public function test_prune_works_in_chunks_of_the_configured_size(): void
    {
        $this->fullRun();
        config()->set('atrium-wordpress.chunk_size', 1);
        $this->gone(Wp::GUTENBERG);
        $this->gone(Wp::ENCODING);
        $deleting = 0;
        Event::listen('eloquent.deleting: '.News::class, static function () use (&$deleting): void {
            if (++$deleting === 2) {
                throw new RuntimeException('the second record cannot be deleted');
            }
        });

        try {
            $this->cli('atrium:wordpress:sync', ['--prune' => true]);
            $this->fail('The failed delete must stop the prune.');
        } catch (RuntimeException $e) {
            $this->assertSame('the second record cannot be deleted', $e->getMessage());
        } finally {
            Event::forget('eloquent.deleting: '.News::class);
        }

        // The first chunk of one record is gone for good, the second one is back as it was.
        $this->assertSame(7, DB::table('news')->count());
        $this->assertSame(1, DB::table('wordpress_map')->whereIn('wp_id', [Wp::GUTENBERG, Wp::ENCODING])->where('kind', 'post')->count());
    }

    public function test_prune_does_not_mind_a_record_somebody_deleted_in_atrium(): void
    {
        $this->fullRun();
        DB::table('news')->where('id', $this->target('post', Wp::GUTENBERG)->id)->delete();
        $this->gone(Wp::GUTENBERG);

        [$exit, $output] = $this->cli('atrium:wordpress:sync', ['--prune' => true]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Deleted: 1', $output);
        $this->assertFalse($this->moved('post', Wp::GUTENBERG));
    }

    public function test_prune_tries_a_deadlocked_chunk_three_times(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('A deadlock is retried only outside the transaction of the test.');
        }

        $this->fullRun();
        $this->gone(Wp::GUTENBERG);
        DB::connection()->commit();
        $tries = 0;
        Event::listen('eloquent.deleting: '.News::class, static function () use (&$tries): void {
            if (++$tries < 3) {
                throw new QueryException('testing', 'delete', [], new \PDOException('database table is locked'));
            }
        });

        [$exit, $output] = $this->cli('atrium:wordpress:sync', ['--prune' => true]);

        Event::forget('eloquent.deleting: '.News::class);
        $this->assertSame(0, $exit, $output);
        $this->assertSame(3, $tries);
        $this->assertStringContainsString('Deleted: 1', $output);
        $this->assertFalse($this->moved('post', Wp::GUTENBERG));
    }

    public function test_prune_gives_up_on_a_chunk_that_deadlocks_every_time(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('A deadlock is retried only outside the transaction of the test.');
        }

        $this->fullRun();
        $this->gone(Wp::GUTENBERG);
        DB::connection()->commit();
        $tries = 0;
        Event::listen('eloquent.deleting: '.News::class, static function () use (&$tries): void {
            $tries++;

            throw new QueryException('testing', 'delete', [], new \PDOException('database table is locked'));
        });

        try {
            $this->cli('atrium:wordpress:sync', ['--prune' => true]);
            $this->fail('A deadlock that does not go away must stop the prune.');
        } catch (QueryException) {
            // expected
        } finally {
            Event::forget('eloquent.deleting: '.News::class);
        }

        $this->assertSame(3, $tries);
        $this->assertTrue($this->moved('post', Wp::GUTENBERG));
    }

    public function test_the_audit_runs_after_the_move_and_the_prune(): void
    {
        $this->fullRun();
        $this->gone(Wp::GUTENBERG);

        [$exit, $output] = $this->cli('atrium:wordpress:sync', ['--prune' => true, '--audit' => true]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Deleted: 1', $output);
        $this->assertStringContainsString('Everything moved.', $output);
        $this->assertLessThan(strpos($output, 'Everything moved.'), strpos($output, 'Deleted: 1'));
    }

    public function test_the_audit_fails_when_the_sites_differ(): void
    {
        $this->fullRun();
        $this->gone(Wp::HELLO);

        [$exit, $output] = $this->cli('atrium:wordpress:sync', ['--audit' => true]);

        $this->assertSame(1, $exit, $output);
        $this->assertStringNotContainsString('Everything moved.', $output);
        $this->assertStringContainsString('expected', $output);
    }

    public function test_the_audit_without_the_prune_is_enough_when_nothing_was_deleted(): void
    {
        $this->fullRun();

        [$exit, $output] = $this->cli('atrium:wordpress:sync', ['--audit' => true]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Everything moved.', $output);
        $this->assertStringNotContainsString('Deleted:', $output);
    }

    public function test_the_connection_and_prefix_are_passed_on(): void
    {
        $this->site('wpml', 'blog_');
        config()->set('atrium-wordpress.table_prefix', 'blog_');
        $this->generateAndMigrate();
        config()->set('atrium-wordpress.connection', 'nowhere');
        config()->set('atrium-wordpress.table_prefix', 'wrong_');

        [$exit, $output] = $this->cli('atrium:wordpress:sync', ['--connection' => 'wordpress', '--prefix' => 'blog_', '--prune' => true, '--audit' => true]);

        $this->assertSame(0, $exit, $output);
        $this->assertSame(8, DB::table('news')->count());
        $this->assertStringContainsString('Deleted: 0', $output);
        $this->assertStringContainsString('Everything moved.', $output);
    }

    private function generatedRecipe(): string
    {
        return (string) DB::table('wordpress_map')->where('kind', 'post')->where('wp_type', 'recipe')->value('target_type');
    }
}
