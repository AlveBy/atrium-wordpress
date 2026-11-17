<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Feature;

use Atrium\Admin\Models\Language;
use Atrium\Admin\Models\MediaItem;
use Atrium\Admin\Support\ContentLanguages;
use Atrium\WordPress\Import\Importer;
use Atrium\WordPress\Plan\Type;
use Atrium\WordPress\Tests\Concerns\ImportsWordPress;
use Atrium\WordPress\Tests\Fixtures\SyntheticWordPress as Wp;
use Atrium\WordPress\Tests\TestCase;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * What the importer writes and how it writes it: the defaults of a run, the
 * size of a chunk, a deadlock tried again in each step, and the details of
 * the files, the SEO and the map of what was moved.
 */
final class ImporterTest extends TestCase
{
    use ImportsWordPress;

    private function importer(): Importer
    {
        return new Importer($this->records());
    }

    /**
     * Adds tags to the old site, 1000 and up.
     */
    private function tags(int $count): void
    {
        $terms = [];
        $taxonomies = [];

        for ($id = 1000; $id < 1000 + $count; $id++) {
            $terms[] = ['term_id' => $id, 'name' => "Tag {$id}", 'slug' => "tag-{$id}", 'term_group' => 0];
            $taxonomies[] = ['term_taxonomy_id' => $id + Wp::TT_OFFSET, 'term_id' => $id, 'taxonomy' => 'post_tag', 'description' => '', 'parent' => 0, 'count' => 0];
        }

        foreach (array_chunk($terms, 100) as $chunk) {
            $this->wp()->table('wp_terms')->insert($chunk);
        }

        foreach (array_chunk($taxonomies, 100) as $chunk) {
            $this->wp()->table('wp_term_taxonomy')->insert($chunk);
        }
    }

    /**
     * Adds an attachment, with its file in the old uploads folder unless it is meant to be missing.
     */
    private function attachment(int $id, string $title, string $file, bool $present = true): void
    {
        $this->wpPost($id, 'attachment', $title, ['post_status' => 'inherit', 'post_mime_type' => 'image/jpeg', 'guid' => Wp::OLD_URL.'/wp-content/uploads/'.$file]);
        $this->wpMeta($id, ['_wp_attached_file' => $file]);

        if ($present) {
            $target = $this->workDir.'/uploads/'.str_replace('\\', '/', $file);
            @mkdir(dirname($target), 0777, true);
            file_put_contents($target, 'file '.$id);
        }
    }

    private function onlySqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('A deadlock is retried only outside the transaction of the test, whose rows MySQL and PostgreSQL would keep.');
        }
    }

    /**
     * Number of transactions (savepoints too) a run of the importer opens.
     */
    private function transactionsOfARun(): int
    {
        $began = 0;
        Event::listen(TransactionBeginning::class, static function () use (&$began): void {
            $began++;
        });

        $this->importer()->run();
        Event::forget(TransactionBeginning::class);

        return $began;
    }

    public function test_a_run_moves_everything_unless_told_to_move_what_changed(): void
    {
        $this->site();
        $this->generateAndMigrate();
        $this->importer()->run();
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO)->update(['post_title' => 'Hello again']);
        $this->assertSame('Hello World', $this->decoded($this->target('post', Wp::HELLO), 'name')['en']);

        $this->importer()->run();

        $this->assertSame('Hello again', $this->decoded($this->target('post', Wp::HELLO), 'name')['en']);
    }

    /**
     * @return array<string,array{0:int}>
     */
    public static function tagCounts(): array
    {
        return [
            'as many as one chunk holds' => [200],
            'one more than a chunk holds' => [201],
        ];
    }

    #[DataProvider('tagCounts')]
    public function test_without_a_setting_a_chunk_is_200_records(int $tags): void
    {
        $this->site('none');
        $this->tags($tags - 2);
        $this->generateAndMigrate();
        $this->importer()->run();
        config()->set('atrium-wordpress.chunk_size', 200);
        $explicit = $this->transactionsOfARun();
        $settings = config('atrium-wordpress');
        unset($settings['chunk_size']);
        config()->set('atrium-wordpress', $settings);

        $this->assertSame($explicit, $this->transactionsOfARun());
    }

    public function test_the_languages_the_old_site_had_are_the_languages_of_atrium_afterwards(): void
    {
        $this->site();
        $this->generateAndMigrate();
        $before = app(ContentLanguages::class)->codes();

        $this->importer()->run();

        $this->assertSame(['en'], $before);
        $this->assertSame(['en', 'ru'], app(ContentLanguages::class)->codes());
        $this->assertSame(['en', 'ru'], Language::query()->orderBy('sort')->pluck('code')->all());
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function steps(): array
    {
        return [
            'languages' => ['languages'],
            'authors' => ['authors'],
            'terms' => ['terms'],
            'the parents of terms' => ['term parents'],
            'files' => ['files'],
            'the parents of pages' => ['page parents'],
        ];
    }

    #[DataProvider('steps')]
    public function test_every_step_is_tried_three_times_in_a_deadlock(string $step): void
    {
        $this->onlySqlite();
        $this->site();
        $this->generateAndMigrate();
        $plan = $this->plan();
        $category = $plan->taxonomy('category');
        $page = $plan->type('page');
        $this->assertNotNull($category);
        $this->assertNotNull($page);
        DB::connection()->commit();
        $tries = 0;
        $deadlock = static fn (): QueryException => new QueryException('testing', 'update', [], new \PDOException('database table is locked'));

        if (in_array($step, ['term parents', 'page parents'], true)) {
            $table = $step === 'term parents' ? $category->table : (new $page->model)->getTable();
            DB::listen(static function (QueryExecuted $query) use (&$tries, $table, $deadlock): void {
                if (str_starts_with($query->sql, 'update "'.$table.'" set "parent_id"')) {
                    $tries++;

                    throw $deadlock();
                }
            });
        } else {
            $model = match ($step) {
                'languages' => Language::class,
                'authors' => $plan->authorsModel,
                'terms' => array_values($plan->taxonomies)[0]->model,
                'files' => MediaItem::class,
            };
            Event::listen('eloquent.saving: '.$model, static function () use (&$tries, $deadlock): void {
                $tries++;

                throw $deadlock();
            });
        }

        try {
            $this->importer()->run();
            $this->fail('A deadlock that does not go away must stop the move.');
        } catch (QueryException|DeadlockException) {
            // expected
        }

        $this->assertSame(3, $tries);
    }

    public function test_the_parents_of_pages_are_written_500_at_a_time(): void
    {
        $this->site('none');

        for ($id = 1000; $id < 1496; $id++) {
            $this->wpPost($id, 'page', "Page {$id}", ['post_parent' => Wp::ABOUT]);
        }

        $this->generateAndMigrate();
        $page = $this->plan()->type('page');
        $this->assertNotNull($page);
        $table = (new $page->model)->getTable();
        $updates = 0;
        DB::listen(static function (QueryExecuted $query) use (&$updates, $table): void {
            // MySQL quotes names with backticks, SQLite and PostgreSQL with double quotes.
            if (str_starts_with(str_replace(['"', '`'], '', $query->sql), 'update '.$table.' set parent_id') && ++$updates === 501) {
                throw new RuntimeException('the 501st page cannot be updated');
            }
        });

        try {
            $this->importer()->run();
            $this->fail('The failed update must stop the move.');
        } catch (RuntimeException) {
            // expected
        }

        // 500 pages are one chunk: its parents stay, the 501st one is a chunk of its own.
        $about = $this->target('post', Wp::ABOUT)->id;
        $this->assertSame($about, DB::table($table)->where('id', $this->target('post', 1494)->id)->value('parent_id'));
        $this->assertNull(DB::table($table)->where('id', $this->target('post', 1495)->id)->value('parent_id'));
    }

    public function test_a_custom_type_with_a_tree_keeps_it(): void
    {
        $this->site();
        $this->wp()->table('wp_posts')->where('ID', Wp::PIZZA)->update(['post_parent' => Wp::BORSCHT]);
        $this->generateAndMigrate();
        $this->assertSame(Type::GENERATE, $this->plan()->type('recipe')?->target);
        $this->assertTrue($this->plan()->type('recipe')->hierarchical);

        $this->importer()->run();

        $this->assertSame($this->target('post', Wp::BORSCHT)->id, $this->target('post', Wp::PIZZA)->parent_id);
        $this->assertNull($this->target('post', Wp::BORSCHT)->parent_id);
    }

    public function test_files_are_listed_in_a_chunk_of_200(): void
    {
        $this->site('none');

        for ($id = 2000; $id < 2198; $id++) {
            $this->attachment($id, "Picture {$id}", "bulk/{$id}.jpg");
        }

        $this->generateAndMigrate();
        $saved = 0;
        Event::listen('eloquent.saving: '.MediaItem::class, static function () use (&$saved): void {
            if (++$saved === 201) {
                throw new RuntimeException('the 201st file cannot be listed');
            }
        });

        try {
            $this->importer()->run();
            $this->fail('The failed write must stop the move.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(200, MediaItem::query()->count());
    }

    public function test_the_media_library_has_a_folder_for_what_came_from_wordpress(): void
    {
        $this->site();
        $this->generateAndMigrate();

        $this->importer()->run();

        $folder = DB::table('media_library_collections')->first();
        $this->assertSame('WordPress', $folder->name);
        $this->assertNull($folder->parent_id);
        $this->assertSame(500, (int) $folder->sort);
    }

    public function test_a_file_is_what_its_name_says_it_is(): void
    {
        $this->site();
        $this->attachment(2000, str_repeat('Я', 300), '2024/09/Banner.JPG');
        $this->attachment(2001, 'Odd', '2024/09/odd.'.str_repeat('я', 20));
        $this->generateAndMigrate();

        $this->importer()->run();

        $banner = MediaItem::query()->where('url', '/wp-content/uploads/2024/09/Banner.JPG')->firstOrFail();
        $this->assertSame(str_repeat('Я', 255), $banner->name);
        $this->assertSame('jpg', $banner->extension);
        $this->assertTrue((bool) $banner->is_image);
        $this->assertSame(strlen('file 2000'), (int) $banner->size);
        $odd = MediaItem::query()->where('name', 'Odd')->firstOrFail();
        $this->assertSame(str_repeat('я', 16), $odd->extension);
    }

    public function test_the_directories_of_copied_files_are_open_to_read(): void
    {
        $this->site();
        $this->generateAndMigrate();
        $umask = umask(0);

        try {
            $this->importer()->run();
        } finally {
            umask($umask);
        }

        $this->assertSame(0755, fileperms($this->published('wp-content/uploads/2024/05')) & 0777);
        $this->assertSame(0755, fileperms($this->published('wp-content/uploads/docs')) & 0777);
    }

    public function test_file_names_are_taken_as_they_are_on_the_old_site(): void
    {
        $this->site('none');
        // A Windows server saved backslashes; a text links with two slashes in a row.
        $this->attachment(2000, 'Windows', '2024\\10\\win.jpg');
        $this->wpPost(2001, 'post', 'Links', ['post_content' => '<p><img src="/wp-content/uploads//lead.jpg"></p>']);
        $this->generateAndMigrate();

        $result = $this->importer()->run();

        $this->assertFileExists($this->published('wp-content/uploads/2024/10/win.jpg'));
        $this->assertContains('lead.jpg', $result['missing_files']);
        $this->assertNotContains('/lead.jpg', $result['missing_files']);
        $this->assertNotContains('2024\\10\\win.jpg', $result['missing_files']);
    }

    public function test_nothing_outside_the_uploads_folder_is_copied(): void
    {
        $this->site('none');
        file_put_contents($this->workDir.'/outside.txt', 'secret');
        mkdir($this->workDir.'/uploads/a');
        $this->attachment(2000, 'Up', '../outside.txt', false);
        $this->attachment(2001, 'Around', 'a/../../outside.txt', false);
        $this->generateAndMigrate();

        $result = $this->importer()->run();

        $this->assertFileDoesNotExist($this->published('wp-content/outside.txt'));
        $this->assertFileDoesNotExist($this->published('outside.txt'));
        $this->assertContains('../outside.txt', $result['missing_files']);
        $this->assertContains('a/../../outside.txt', $result['missing_files']);
    }

    public function test_the_old_addresses_of_a_record_are_kept_as_they_were_written(): void
    {
        $this->site();
        $this->generateAndMigrate();

        $this->importer()->run();

        $paths = $this->mapRow('post', Wp::HELLO)->paths;
        $this->assertStringContainsString('["/2024/05/hello-world/"', $paths);
    }

    public function test_every_record_is_looked_up_in_the_map_once(): void
    {
        $this->site();
        $this->generateAndMigrate();
        $looked = [];
        DB::listen(static function (QueryExecuted $query) use (&$looked): void {
            if (str_starts_with(str_replace(['"', '`'], '', $query->sql), 'select target_id from wordpress_map')) {
                $looked[] = implode(':', $query->bindings);
            }
        });

        $this->importer()->run();

        $this->assertNotSame([], $looked);
        $this->assertSame([], array_keys(array_filter(array_count_values($looked), static fn (int $times): bool => $times > 1)));
    }

    public function test_ids_the_database_gives_as_text_are_numbers(): void
    {
        $this->site();
        $this->generateAndMigrate();
        $this->importer()->run();
        $before = DB::table('wordpress_map')->count();
        DB::connection()->getPdo()->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);

        try {
            $this->importer()->run();
        } finally {
            DB::connection()->getPdo()->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, false);
        }

        $this->assertSame($before, DB::table('wordpress_map')->count());
        $this->assertSame(8, DB::table('news')->count());
    }

    public function test_a_second_run_empties_what_is_empty_now_and_stamps_the_seo_row(): void
    {
        $this->site();
        $this->generateAndMigrate();
        Carbon::setTestNow('2030-01-02 03:04:05');
        $this->importer()->run();
        $first = $this->seoOf('post', Wp::HELLO);
        $this->assertSame('2030-01-02 03:04:05', $first->created_at);
        $this->assertSame('2030-01-02 03:04:05', $first->updated_at);
        $this->assertNotNull($first->title);
        $this->assertNotNull($this->decoded($this->target('post', Wp::HELLO), 'preview_text'));
        $this->wp()->table('wp_postmeta')->where('post_id', Wp::HELLO)->where('meta_key', '_yoast_wpseo_title')->delete();
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO)->update(['post_excerpt' => '']);
        Carbon::setTestNow('2031-02-03 04:05:06');

        $this->importer()->run();

        $seo = $this->seoOf('post', Wp::HELLO);
        $this->assertNull($seo->title);
        $this->assertSame('hello', $seo->keywords);
        $this->assertSame('2030-01-02 03:04:05', $seo->created_at);
        $this->assertSame('2031-02-03 04:05:06', $seo->updated_at);
        $this->assertNull($this->target('post', Wp::HELLO)->preview_text);
    }
}
