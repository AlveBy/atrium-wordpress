<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Feature;

use Atrium\Admin\Models\MediaItem;
use Atrium\WordPress\Tests\Concerns\ImportsWordPress;
use Atrium\WordPress\Tests\Fixtures\SyntheticWordPress as Wp;
use Atrium\WordPress\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The before-and-after check: it finds every difference between the made-up
 * site and what was moved, and says nothing when there is none.
 */
final class ParityTest extends TestCase
{
    use ImportsWordPress;

    /**
     * The report as JSON.
     *
     * @return array{0:int,1:array<string,mixed>,2:string}
     */
    private function report(): array
    {
        [$exit, $output] = $this->cli('atrium:wordpress:parity', ['--format' => 'json']);
        $report = json_decode(substr($output, 0, (int) strrpos($output, '}') + 1), true);

        return [$exit, $report, $output];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function problems(): array
    {
        return $this->report()[1]['problems'];
    }

    private function problem(string $scope, ?int $id, string $field, mixed $expected, mixed $actual): array
    {
        return ['scope' => $scope, 'wp_id' => $id, 'field' => $field, 'expected' => $expected, 'actual' => $actual];
    }

    public function test_a_parent_in_another_chunk_is_still_found(): void
    {
        $this->fullRun();
        config()->set('atrium-wordpress.chunk_size', 1);

        [$exit, $report] = $this->report();

        $this->assertSame(0, $exit);
        $this->assertSame([], $report['problems']);
    }

    public function test_nothing_to_report_after_a_clean_move(): void
    {
        $this->fullRun();

        [$exit, $report, $output] = $this->report();

        $this->assertSame(0, $exit);
        $this->assertSame([], $report['problems']);
        $this->assertSame([
            'languages' => ['wordpress' => 2, 'atrium' => 2, 'problems' => 0],
            'authors' => ['wordpress' => 2, 'atrium' => 2, 'problems' => 0],
            'terms:category' => ['wordpress' => 3, 'atrium' => 3, 'problems' => 0],
            'terms:cuisine' => ['wordpress' => 2, 'atrium' => 2, 'problems' => 0],
            'terms:post_tag' => ['wordpress' => 2, 'atrium' => 2, 'problems' => 0],
            'files' => ['wordpress' => 4, 'atrium' => 4, 'problems' => 0],
            'posts:page' => ['wordpress' => 5, 'atrium' => 5, 'problems' => 0],
            'posts:post' => ['wordpress' => 8, 'atrium' => 8, 'problems' => 0],
            'posts:recipe' => ['wordpress' => 2, 'atrium' => 2, 'problems' => 0],
        ], $report['summary']);
        $this->assertSame(['the file 2024/07/missing.jpg of attachment 52 is missing in the old uploads folder too'], $report['warnings']);
        $this->assertContains('user 99 wrote posts but is not in wp_users: they move without an author', $report['notes']);
        $this->assertStringEndsWith("Everything moved.\n", $output);
    }

    public function test_the_default_report_is_a_table_with_the_warnings_and_what_is_broken_on_the_old_site(): void
    {
        $this->fullRun();

        [$exit, $output] = $this->cli('atrium:wordpress:parity');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('| What', $output);
        $this->assertMatchesRegularExpression('/\| posts:post\s+\| 8\s+\| 8\s+\| 0\s+\|/', $output);
        $this->assertMatchesRegularExpression('/\| files\s+\| 4\s+\| 4\s+\| 0\s+\|/', $output);
        $this->assertStringContainsString('the file 2024/07/missing.jpg of attachment 52 is missing in the old uploads folder too', $output);
        $this->assertStringContainsString('Already broken on the old site (moved as well as it could be):', $output);
        $this->assertStringContainsString('  - post 34 is a child of post 999', $output);
        $this->assertStringEndsWith("Everything moved.\n", $output);

        [, $explicit] = $this->cli('atrium:wordpress:parity', ['--format' => 'table']);
        $this->assertSame($output, $explicit);
    }

    public function test_a_warning_is_shown_in_the_comment_colour(): void
    {
        $this->fullRun();
        $output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);

        Artisan::call('atrium:wordpress:parity', [], $output);

        $output->writeln('after the report');
        $text = $output->fetch();

        $this->assertStringContainsString("\e[33mthe file 2024/07/missing.jpg of attachment 52 is missing in the old uploads folder too\e[39m\n", $text);
        $this->assertStringContainsString("\nafter the report\n", $text);
    }

    public function test_a_language_nobody_asked_for_is_no_part_of_the_count(): void
    {
        $this->fullRun();
        DB::table('admin_languages')->insert(['code' => 'de', 'name' => 'Deutsch', 'active' => true, 'is_default' => false, 'sort' => 30]);

        [, $report] = $this->report();

        $this->assertSame([], $report['problems']);
        $this->assertSame(['wordpress' => 2, 'atrium' => 2, 'problems' => 0], $report['summary']['languages']);
    }

    public function test_a_term_that_is_gone_does_not_hide_the_changes_of_the_terms_after_it(): void
    {
        $this->fullRun();
        DB::table('categories')->where('id', $this->target('term', Wp::TERM_UNCATEGORIZED + Wp::TT_OFFSET)->id)->delete();
        DB::table('categories')->where('id', $this->target('term', Wp::TERM_LOCAL + Wp::TT_OFFSET)->id)->update(['code' => 'changed']);

        $problems = array_values(array_filter($this->problems(), static fn (array $problem): bool => $problem['scope'] === 'terms:category'));

        $this->assertSame([
            $this->problem('terms:category', Wp::TERM_UNCATEGORIZED + Wp::TT_OFFSET, 'record', 'moved', null),
            $this->problem('terms:category', Wp::TERM_LOCAL + Wp::TT_OFFSET, 'code', 'local', 'changed'),
        ], $problems);
    }

    public function test_a_file_that_is_not_in_the_old_folder_does_not_hide_the_files_after_it(): void
    {
        $this->fullRun();
        unlink($this->workDir.'/uploads/2024/05/photo.jpg');
        unlink($this->published('wp-content/uploads/2024/05/photo-300x200.jpg'));

        [, $report] = $this->report();

        $problems = array_values(array_filter($report['problems'], static fn (array $problem): bool => $problem['scope'] === 'files'));
        $this->assertSame([$this->problem('files', Wp::PHOTO, '2024/05/photo-300x200.jpg', 'copied', null)], $problems);
        $this->assertContains('the file 2024/05/photo.jpg of attachment 50 is missing in the old uploads folder too', $report['warnings']);
        $this->assertSame(['wordpress' => 3, 'atrium' => 2, 'problems' => 1], $report['summary']['files']);
    }

    public function test_the_old_uploads_folder_may_be_given_with_a_slash_at_the_end(): void
    {
        $this->fullRun();
        config()->set('atrium-wordpress.uploads_path', $this->workDir.'/uploads/');

        [$exit, $report] = $this->report();

        $this->assertSame(0, $exit);
        $this->assertSame([], $report['problems']);
        $this->assertSame(['wordpress' => 4, 'atrium' => 4, 'problems' => 0], $report['summary']['files']);
    }

    public function test_a_file_that_is_named_twice_is_warned_about_once(): void
    {
        $this->fullRun();
        $sizes = static fn (array $files): string => serialize(['file' => 'x', 'sizes' => array_map(static fn (string $file): array => ['file' => $file], $files)]);
        $this->wp()->table('wp_postmeta')->insert([
            ['post_id' => Wp::MISSING_PHOTO, 'meta_key' => '_wp_attachment_metadata', 'meta_value' => $sizes(['missing.jpg', 'missing.jpg'])],
            ['post_id' => Wp::MANUAL, 'meta_key' => '_wp_attachment_metadata', 'meta_value' => $sizes(['gone.pdf'])],
        ]);

        [, $report] = $this->report();

        $this->assertSame([
            'the file 2024/07/missing.jpg of attachment 52 is missing in the old uploads folder too',
            'the file docs/gone.pdf of attachment 53 is missing in the old uploads folder too',
        ], $report['warnings']);
    }

    public function test_the_links_of_a_record_are_compared_in_the_order_of_their_ids(): void
    {
        $this->site();
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO)->update(['post_author' => Wp::USER_BORIS]);
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO_RU)->update(['post_author' => Wp::USER_ALICE]);
        $this->wp()->table('wp_term_relationships')->where('object_id', Wp::HELLO)->where('term_taxonomy_id', 106)->update(['term_order' => -1]);
        $this->generateAndMigrate();
        [$exit] = $this->cli('atrium:wordpress:migrate:data');
        $this->assertSame(0, $exit);

        [, $report] = $this->report();

        $this->assertSame([], $report['problems']);
    }

    public function test_a_database_that_gives_numbers_as_text_finds_no_differences(): void
    {
        $this->fullRun();
        DB::connection()->getPdo()->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);

        [$exit, $report] = $this->report();

        $this->assertSame(0, $exit);
        $this->assertSame([], $report['problems']);
    }

    public function test_the_new_address_of_a_redirect_is_text_in_the_report(): void
    {
        $this->fullRun();
        DB::table('wordpress_map')->where('kind', 'post')->where('wp_id', Wp::HELLO)->update(['paths' => json_encode([['/2024/05/hello-world/', 123]])]);

        $problems = $this->problems();

        $this->assertContains($this->problem('posts:post', Wp::HELLO, 'redirect /2024/05/hello-world/', '/news/hello-world', '123'), $problems);
    }

    public function test_a_number_that_differs_by_one_millionth_is_a_difference(): void
    {
        $this->site();
        $this->wp()->table('wp_postmeta')->where('post_id', Wp::PIZZA)->where('meta_key', 'cooking_time')->update(['meta_value' => '0']);
        $this->generateAndMigrate();
        [$exit] = $this->cli('atrium:wordpress:migrate:data');
        $this->assertSame(0, $exit);
        DB::table('recipes')->where('id', $this->target('post', Wp::PIZZA)->id)->update(['cooking_time' => 0.000001]);

        $problems = $this->problems();

        $this->assertCount(1, $problems);
        $this->assertSame(['posts:recipe', Wp::PIZZA, 'cooking_time'], [$problems[0]['scope'], $problems[0]['wp_id'], $problems[0]['field']]);
    }

    public function test_a_wrong_parent_is_found_for_a_generated_type_too(): void
    {
        $this->site();
        config()->set('atrium-wordpress.post_types', ['page' => 'generate']);
        $this->generateAndMigrate();
        [$exit] = $this->cli('atrium:wordpress:migrate:data');
        $this->assertSame(0, $exit);
        DB::table('wordpress_pages')->where('id', $this->target('post', Wp::HISTORY)->id)->update(['parent_id' => null]);

        $this->assertSame([$this->problem('posts:page', Wp::HISTORY, 'parent', Wp::TEAM, null)], $this->problems());
    }

    public function test_a_parent_that_is_a_record_of_another_module_is_named_by_its_id(): void
    {
        $this->fullRun();
        $news = (int) DB::table('news')->max('id');
        $this->assertGreaterThan((int) DB::table('pages')->max('id'), $news);
        DB::table('pages')->where('id', $this->target('post', Wp::HISTORY)->id)->update(['parent_id' => $news]);

        $this->assertSame([$this->problem('posts:page', Wp::HISTORY, 'parent', Wp::TEAM, 'id '.$news)], $this->problems());
    }

    public function test_markdown_has_the_summary_and_the_sections_that_are_not_empty(): void
    {
        $this->fullRun();

        [$exit, $output] = $this->cli('atrium:wordpress:parity', ['--format' => 'markdown']);

        $this->assertSame(0, $exit);
        $this->assertStringStartsWith("# WordPress → Atrium\n\n| What | WordPress | Atrium | Problems |\n|---|---|---|---|\n| languages | 2 | 2 | 0 |\n| authors | 2 | 2 | 0 |\n", $output);
        $this->assertStringContainsString("| posts:recipe | 2 | 2 | 0 |\n\n## Not moved\n\n- the file 2024/07/missing.jpg of attachment 52 is missing in the old uploads folder too\n\n## Already broken on the old site\n\n- post 34 is a child of post 999", $output);
        $this->assertStringNotContainsString('## Differences', $output);
        $this->assertStringEndsWith("Everything moved.\n", $output);
    }

    public function test_a_report_can_be_written_to_a_file_in_json_and_markdown(): void
    {
        $this->fullRun();
        $json = $this->workDir.'/parity.json';
        $markdown = $this->workDir.'/parity.md';

        [$exit, $output] = $this->cli('atrium:wordpress:parity', ['--format' => 'json', '--output' => $json]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("Written to {$json}", $output);
        $this->assertStringNotContainsString('"summary"', $output);
        $this->assertSame([], json_decode((string) file_get_contents($json), true)['problems']);

        [$exit, $output] = $this->cli('atrium:wordpress:parity', ['--format' => 'markdown', '--output' => $markdown]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("Written to {$markdown}", $output);
        $this->assertStringStartsWith('# WordPress → Atrium', (string) file_get_contents($markdown));
    }

    public function test_a_markdown_file_ends_with_exactly_one_line_break(): void
    {
        $this->fullRun();
        $file = $this->workDir.'/parity.md';

        $this->cli('atrium:wordpress:parity', ['--format' => 'markdown', '--output' => $file]);

        $written = (string) file_get_contents($file);
        $this->assertStringEndsWith("\n", $written);
        $this->assertStringEndsNotWith("\n\n", $written);
    }

    public function test_the_json_is_indented_and_keeps_slashes_and_letters_as_they_are(): void
    {
        $this->fullRun();
        DB::table('news')->where('id', $this->target('post', Wp::HELLO)->id)->update(['name' => '{"en":"Changed","ru":"Привет, мир"}']);
        DB::table('wordpress_map')->where('kind', 'post')->where('wp_id', Wp::HELLO)->update(['paths' => json_encode([['/2024/05/hello-world/', '/wrong']])]);

        [, , $output] = $this->report();

        $this->assertStringContainsString("\n                \"ru\": \"Привет, мир\"", $output);
        $this->assertStringContainsString('"field": "redirect /2024/05/hello-world/"', $output);
        $this->assertStringContainsString('"actual": "/wrong"', $output);
    }

    public function test_the_table_and_markdown_keep_slashes_and_letters_of_a_value_as_they_are(): void
    {
        $this->fullRun();
        $this->wp()->table('wp_posts')->where('ID', Wp::GUTENBERG)->update(['post_title' => 'Борщ / суп']);

        [, $table] = $this->cli('atrium:wordpress:parity');
        [, $markdown] = $this->cli('atrium:wordpress:parity', ['--format' => 'markdown']);

        $this->assertStringContainsString('expected {"en":"Борщ / суп"}, found {"en":"Gutenberg post"}', $table);
        $this->assertStringContainsString('| {"en":"Борщ / суп"} | {"en":"Gutenberg post"} |', $markdown);
    }

    public function test_an_unknown_format_is_an_error(): void
    {
        $this->fullRun();

        [$exit, $output] = $this->cli('atrium:wordpress:parity', ['--format' => 'xml']);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('Unknown format "xml": table, json or markdown.', $output);
        $this->assertStringNotContainsString('Everything moved.', $output);
    }

    public function test_a_changed_text_is_found(): void
    {
        $this->fullRun();
        DB::table('news')->where('id', $this->target('post', Wp::HELLO)->id)->update(['name' => json_encode(['en' => 'Changed', 'ru' => 'Привет, мир'], JSON_UNESCAPED_UNICODE)]);

        [$exit, $report, $output] = $this->report();

        $this->assertSame(1, $exit);
        $this->assertSame(
            [$this->problem('posts:post', Wp::HELLO, 'name', ['en' => 'Hello World', 'ru' => 'Привет, мир'], ['en' => 'Changed', 'ru' => 'Привет, мир'])],
            $report['problems'],
        );
        $this->assertSame(['wordpress' => 8, 'atrium' => 8, 'problems' => 1], $report['summary']['posts:post']);
        $this->assertStringEndsWith("1 differences.\n", $output);
    }

    public function test_the_table_lists_the_differences_and_how_many_are_short(): void
    {
        $this->fullRun();
        DB::table('wordpress_map')->where('kind', 'post')->where('wp_id', Wp::GUTENBERG)->delete();
        DB::table('news')->where('id', $this->target('post', Wp::HELLO)->id)->update(['name' => '{"en":"Changed"}']);

        [$exit, $output] = $this->cli('atrium:wordpress:parity');

        $this->assertSame(1, $exit);
        $this->assertMatchesRegularExpression('/\| posts:post\s+\| 8\s+\| 7 \(SHORT by 1\)\s+\| 2\s+\|/', $output);
        $this->assertMatchesRegularExpression('/posts:post 12 record: expected moved, found nothing/', $output);
        $this->assertMatchesRegularExpression('/posts:post 10 name: expected \{"en":"Hello World","ru":"Привет, мир"\}, found \{"en":"Changed"\}/', $output);
        $this->assertStringEndsWith("2 differences.\n", $output);
        $this->assertStringNotContainsString('Everything moved.', $output);
    }

    public function test_a_record_without_a_map_row_or_without_a_row_is_missing(): void
    {
        $this->fullRun();
        DB::table('wordpress_map')->where('kind', 'post')->where('wp_id', Wp::GUTENBERG)->delete();
        DB::table('pages')->where('id', $this->target('post', Wp::TEAM)->id)->delete();

        [, $report] = $this->report();

        $this->assertSame(['wordpress' => 8, 'atrium' => 7, 'problems' => 1], $report['summary']['posts:post']);
        $this->assertSame(['wordpress' => 5, 'atrium' => 4, 'problems' => 1], $report['summary']['posts:page']);
        $this->assertContains($this->problem('posts:post', Wp::GUTENBERG, 'record', 'moved', null), $report['problems']);
        $this->assertContains($this->problem('posts:page', Wp::TEAM, 'record', 'moved', null), $report['problems']);
        $this->assertCount(2, $report['problems']);
    }

    public function test_a_map_row_that_points_at_another_model_is_a_missing_record(): void
    {
        $this->fullRun();
        DB::table('wordpress_map')->where('kind', 'post')->where('wp_id', Wp::GUTENBERG)->update(['target_type' => 'Some\\Other']);

        $this->assertSame([$this->problem('posts:post', Wp::GUTENBERG, 'record', 'moved', null)], $this->problems());
    }

    public function test_a_lost_term_link_is_found(): void
    {
        $this->fullRun();
        DB::table('news_categories')->where('record_id', $this->target('post', Wp::HELLO)->id)->delete();

        $this->assertSame([$this->problem('posts:post', Wp::HELLO, 'category', [102], [])], $this->problems());
    }

    public function test_a_wrong_term_link_is_found(): void
    {
        $this->fullRun();
        DB::table('news_tags')->where('record_id', $this->target('post', Wp::HELLO)->id)->where('related_id', $this->target('term', Wp::TERM_PHP + Wp::TT_OFFSET)->id)->delete();

        $this->assertSame([$this->problem('posts:post', Wp::HELLO, 'post_tag', [105, 106], [105])], $this->problems());
    }

    public function test_a_wrong_author_link_is_found(): void
    {
        $this->fullRun();
        DB::table('news_authors')->where('record_id', $this->target('post', Wp::GUTENBERG)->id)->update(['related_id' => $this->target('author', Wp::USER_ALICE)->id]);

        $this->assertSame([$this->problem('posts:post', Wp::GUTENBERG, 'authors', [Wp::USER_BORIS], [Wp::USER_ALICE])], $this->problems());
    }

    public function test_a_missing_link_table_is_not_a_difference(): void
    {
        $this->fullRun();
        Schema::dropIfExists('news_authors');

        $this->assertSame([], $this->problems());
    }

    public function test_a_wrong_parent_is_found_for_pages_and_for_terms(): void
    {
        $this->fullRun();
        DB::table('pages')->where('id', $this->target('post', Wp::HISTORY)->id)->update(['parent_id' => null]);
        DB::table('pages')->where('id', $this->target('post', Wp::TEAM)->id)->update(['parent_id' => $this->target('post', Wp::HISTORY)->id]);
        DB::table('categories')->where('id', $this->target('term', Wp::TERM_LOCAL + Wp::TT_OFFSET)->id)->update(['parent_id' => $this->target('term', Wp::TERM_UNCATEGORIZED + Wp::TT_OFFSET)->id]);

        $problems = $this->problems();

        $this->assertContains($this->problem('posts:page', Wp::HISTORY, 'parent', Wp::TEAM, null), $problems);
        $this->assertContains($this->problem('posts:page', Wp::TEAM, 'parent', Wp::ABOUT, Wp::HISTORY), $problems);
        $this->assertContains($this->problem('terms:category', Wp::TERM_LOCAL + Wp::TT_OFFSET, 'parent', Wp::TERM_NEWS + Wp::TT_OFFSET, Wp::TERM_UNCATEGORIZED + Wp::TT_OFFSET), $problems);
        $this->assertCount(3, $problems);
    }

    public function test_a_parent_that_is_no_record_of_the_type_is_named_by_its_id(): void
    {
        $this->fullRun();
        DB::table('pages')->where('id', $this->target('post', Wp::HISTORY)->id)->update(['parent_id' => 987654]);

        $this->assertSame([$this->problem('posts:page', Wp::HISTORY, 'parent', Wp::TEAM, 'id 987654')], $this->problems());
    }

    public function test_a_term_parent_that_is_no_record_is_named_by_its_id(): void
    {
        $this->fullRun();
        DB::table('categories')->where('id', $this->target('term', Wp::TERM_LOCAL + Wp::TT_OFFSET)->id)->update(['parent_id' => 987654]);

        $this->assertSame([$this->problem('terms:category', Wp::TERM_LOCAL + Wp::TT_OFFSET, 'parent', Wp::TERM_NEWS + Wp::TT_OFFSET, 'id 987654')], $this->problems());
    }

    public function test_a_changed_or_lost_seo_value_is_found(): void
    {
        $this->fullRun();
        DB::table('seo_meta')->where('seoable_id', $this->target('post', Wp::HELLO)->id)->where('seoable_type', $this->mapRow('post', Wp::HELLO)->target_type)->update(['title' => 'x', 'keywords' => null]);
        DB::table('seo_meta')->where('seoable_type', $this->mapRow('post', Wp::ABOUT)->target_type)->delete();

        $problems = $this->problems();

        $this->assertContains($this->problem('posts:post', Wp::HELLO, 'seo.title', 'Hello World - Synthetic Blog', 'x'), $problems);
        $this->assertContains($this->problem('posts:post', Wp::HELLO, 'seo.keywords', 'hello', null), $problems);
        $this->assertContains($this->problem('posts:page', Wp::ABOUT, 'seo.title', 'About us', null), $problems);
        $this->assertContains($this->problem('posts:page', Wp::ABOUT, 'seo.description', 'Who we are.', null), $problems);
        $this->assertContains($this->problem('posts:page', Wp::ABOUT, 'seo.robots', 'noindex', null), $problems);
    }

    public function test_the_seo_of_a_term_is_checked_too(): void
    {
        $this->fullRun();
        DB::table('seo_meta')->where('seoable_type', $this->mapRow('term', Wp::TERM_NEWS + Wp::TT_OFFSET)->target_type)->where('seoable_id', $this->target('term', Wp::TERM_NEWS + Wp::TT_OFFSET)->id)->update(['description' => 'Other']);

        $this->assertSame([$this->problem('terms:category', Wp::TERM_NEWS + Wp::TT_OFFSET, 'seo.description', 'News of the synthetic blog.', 'Other')], $this->problems());
    }

    public function test_an_old_address_that_leads_nowhere_or_elsewhere_is_found(): void
    {
        $this->fullRun();
        DB::table('wordpress_map')->where('kind', 'post')->where('wp_id', Wp::HELLO)->update(['paths' => json_encode([['/2024/05/hello-world/', '/wrong']])]);
        DB::table('wordpress_map')->where('kind', 'term')->where('wp_id', Wp::TERM_PHP + Wp::TT_OFFSET)->update(['paths' => null]);

        $problems = $this->problems();

        $this->assertContains($this->problem('posts:post', Wp::HELLO, 'redirect /2024/05/hello-world/', '/news/hello-world', '/wrong'), $problems);
        $this->assertContains($this->problem('posts:post', Wp::HELLO, 'redirect /ru/2024/05/%d0%bf%d1%80%d0%b8%d0%b2%d0%b5%d1%82-%d0%bc%d0%b8%d1%80/', '/ru/news/privet-mir', null), $problems);
        $this->assertContains($this->problem('terms:post_tag', Wp::TERM_PHP + Wp::TT_OFFSET, 'redirect /tag/php/', '/tag/php', null), $problems);
        $this->assertCount(3, $problems);
    }

    public function test_an_author_that_changed_or_is_gone_is_found(): void
    {
        $this->fullRun();
        DB::table('authors')->where('wordpress_id', Wp::USER_ALICE)->update(['email' => 'other@example.test']);
        DB::table('authors')->where('wordpress_id', Wp::USER_BORIS)->delete();

        $problems = $this->problems();

        $this->assertContains($this->problem('authors', Wp::USER_ALICE, 'email', 'alice@example.test', 'other@example.test'), $problems);
        $this->assertContains($this->problem('authors', Wp::USER_BORIS, 'record', 'moved', null), $problems);
    }

    public function test_a_term_that_is_gone_is_found(): void
    {
        $this->fullRun();
        DB::table('tags')->where('wordpress_id', Wp::TERM_PHP + Wp::TT_OFFSET)->delete();

        [, $report] = $this->report();

        $this->assertContains($this->problem('terms:post_tag', Wp::TERM_PHP + Wp::TT_OFFSET, 'record', 'moved', null), $report['problems']);
        $this->assertSame(['wordpress' => 2, 'atrium' => 1, 'problems' => 1], $report['summary']['terms:post_tag']);
    }

    public function test_a_language_that_is_off_gone_or_not_the_default_is_found(): void
    {
        $this->fullRun();
        DB::table('admin_languages')->where('code', 'ru')->update(['active' => false]);

        $this->assertSame([$this->problem('languages', null, 'ru', 'an active language', 'inactive')], $this->problems());

        DB::table('admin_languages')->where('code', 'ru')->delete();
        [, $report] = $this->report();
        $this->assertSame([$this->problem('languages', null, 'ru', 'an active language', null)], $report['problems']);
        $this->assertSame(['wordpress' => 2, 'atrium' => 1, 'problems' => 1], $report['summary']['languages']);
    }

    public function test_another_default_language_is_found(): void
    {
        $this->fullRun();
        DB::table('admin_languages')->update(['is_default' => false]);
        DB::table('admin_languages')->where('code', 'ru')->update(['is_default' => true]);

        $this->assertSame([$this->problem('languages', null, 'default', 'en', 'ru')], $this->problems());
    }

    public function test_a_default_language_nobody_has_marked_is_found(): void
    {
        $this->fullRun();
        DB::table('admin_languages')->update(['is_default' => false]);

        $this->assertSame([$this->problem('languages', null, 'default', 'en', null)], $this->problems());
    }

    public function test_files_missing_or_of_another_size_or_not_in_the_library_are_found(): void
    {
        $this->fullRun();
        unlink($this->published('wp-content/uploads/2024/05/photo.jpg'));
        file_put_contents($this->published('wp-content/uploads/2024/06/borscht.png'), 'x');
        MediaItem::query()->where('url', '/wp-content/uploads/docs/manual.pdf')->delete();

        [, $report] = $this->report();

        $this->assertContains($this->problem('files', Wp::PHOTO, '2024/05/photo.jpg', 'copied', null), $report['problems']);
        $this->assertContains($this->problem('files', Wp::BORSCHT_PHOTO, '2024/06/borscht.png', 'copied', 'a different size'), $report['problems']);
        $this->assertContains($this->problem('files', Wp::MANUAL, 'media library', '/wp-content/uploads/docs/manual.pdf', null), $report['problems']);
        $this->assertCount(3, $report['problems']);
        $this->assertSame(['wordpress' => 4, 'atrium' => 2, 'problems' => 3], $report['summary']['files']);
    }

    public function test_a_size_that_is_not_there_is_found_but_it_needs_no_library_item(): void
    {
        $this->fullRun();
        unlink($this->published('wp-content/uploads/2024/05/photo-300x200.jpg'));

        [, $report] = $this->report();

        $problems = array_values(array_filter($report['problems'], static fn (array $problem): bool => $problem['scope'] === 'files'));
        $this->assertSame([$this->problem('files', Wp::PHOTO, '2024/05/photo-300x200.jpg', 'copied', null)], $problems);
        $this->assertSame(['wordpress' => 4, 'atrium' => 3, 'problems' => 1], $report['summary']['files']);
    }

    public function test_a_missing_table_is_one_problem_and_everything_in_it_is_short(): void
    {
        $this->fullRun();
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('recipes_authors');
        Schema::dropIfExists('recipes_cuisines');
        Schema::dropIfExists('recipes');
        Schema::dropIfExists('news_tags');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('news_authors');
        Schema::dropIfExists('pages_authors');
        Schema::dropIfExists('authors');
        Schema::enableForeignKeyConstraints();

        [, $report] = $this->report();

        $this->assertContains($this->problem('posts:recipe', null, 'table', 'recipes', null), $report['problems']);
        $this->assertContains($this->problem('terms:post_tag', null, 'table', 'tags', null), $report['problems']);
        $this->assertContains($this->problem('authors', null, 'table', 'authors', null), $report['problems']);
        $this->assertCount(3, $report['problems']);
        $this->assertSame(['wordpress' => 2, 'atrium' => 0, 'problems' => 1], $report['summary']['posts:recipe']);
        $this->assertSame(['wordpress' => 2, 'atrium' => 0, 'problems' => 1], $report['summary']['terms:post_tag']);
        $this->assertSame(['wordpress' => 2, 'atrium' => 0, 'problems' => 1], $report['summary']['authors']);
    }

    public function test_without_the_map_table_nothing_can_be_compared(): void
    {
        $this->site();
        Schema::dropIfExists('wordpress_map');

        [$exit, $report] = $this->report();

        $this->assertSame(1, $exit);
        $this->assertSame([$this->problem('setup', null, 'wordpress_map', 'the table', null)], $report['problems']);
        $this->assertSame([], $report['summary']);
        $this->assertSame([], $report['warnings']);
    }

    public function test_before_the_move_every_record_is_missing(): void
    {
        $this->site();
        $this->generateAndMigrate();

        [$exit, $report] = $this->report();

        $this->assertSame(1, $exit);
        $this->assertSame(['wordpress' => 8, 'atrium' => 0, 'problems' => 8], $report['summary']['posts:post']);
        $this->assertSame(['wordpress' => 2, 'atrium' => 0, 'problems' => 2], $report['summary']['authors']);
        // Atrium has the default language of its own, English, from the start.
        $this->assertSame(['wordpress' => 2, 'atrium' => 1, 'problems' => 1], $report['summary']['languages']);
    }

    public function test_types_that_do_not_move_and_fields_with_no_place_are_warned_about(): void
    {
        config()->set('atrium-wordpress.post_types', ['book' => 'news', 'shelf' => 'skip']);
        $this->site();
        $this->wpPost(450, 'book', 'A book', ['post_name' => 'a-book']);
        $this->wpMeta(450, ['isbn' => '123']);
        $this->wpPost(451, 'shelf', 'A shelf');
        $this->wpPost(452, 'shelf', 'Another shelf');
        $this->generateAndMigrate();
        $this->cli('atrium:wordpress:migrate:data');

        [$exit, $report] = $this->report();

        $this->assertSame(0, $exit);
        $this->assertContains('shelf: 2 posts are not moved (skipped in the config)', $report['warnings']);
        $this->assertContains('book 450: isbn has no place in the news module and did not move', $report['warnings']);
        $this->assertArrayNotHasKey('posts:shelf', $report['summary']);
        $this->assertSame(['wordpress' => 1, 'atrium' => 1, 'problems' => 0], $report['summary']['posts:book']);
        $this->assertSame(['wordpress' => 8, 'atrium' => 8, 'problems' => 0], $report['summary']['posts:post']);
    }

    public function test_a_type_that_does_not_move_does_not_stop_the_check_of_the_ones_after_it(): void
    {
        config()->set('atrium-wordpress.post_types', ['page' => 'skip']);
        $this->fullRun();
        DB::table('news')->where('id', $this->target('post', Wp::HELLO)->id)->update(['name' => '{"en":"Changed"}']);

        [, $report] = $this->report();

        $this->assertContains('page: 6 posts are not moved (skipped in the config)', $report['warnings']);
        $this->assertArrayNotHasKey('posts:page', $report['summary']);
        $this->assertSame(['wordpress' => 8, 'atrium' => 8, 'problems' => 1], $report['summary']['posts:post']);
        $this->assertSame(['wordpress' => 2, 'atrium' => 2, 'problems' => 0], $report['summary']['posts:recipe']);
    }

    public function test_a_record_that_is_missing_does_not_stop_the_check_of_the_ones_after_it(): void
    {
        $this->fullRun();
        DB::table('wordpress_map')->where('kind', 'post')->where('wp_id', Wp::HELLO)->delete();
        DB::table('news')->where('id', $this->target('post', Wp::GUTENBERG)->id)->update(['name' => '{"en":"Changed"}']);

        $problems = $this->problems();

        $this->assertContains($this->problem('posts:post', Wp::HELLO, 'record', 'moved', null), $problems);
        $this->assertContains($this->problem('posts:post', Wp::GUTENBERG, 'name', ['en' => 'Gutenberg post'], ['en' => 'Changed']), $problems);
    }

    public function test_a_link_table_that_is_missing_does_not_stop_the_check_of_the_next_one(): void
    {
        $this->fullRun();
        Schema::dropIfExists('news_categories');
        DB::table('news_tags')->where('record_id', $this->target('post', Wp::HELLO)->id)->where('related_id', $this->target('term', Wp::TERM_PHP + Wp::TT_OFFSET)->id)->delete();

        $this->assertSame([$this->problem('posts:post', Wp::HELLO, 'post_tag', [105, 106], [105])], $this->problems());
    }

    public function test_markdown_lists_the_differences_in_a_table_of_their_own(): void
    {
        $this->fullRun();
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO)->update(['post_title' => 'Moved on']);
        DB::table('news')->update(['name' => '{"en":"x"}']);

        [$exit, $output] = $this->cli('atrium:wordpress:parity', ['--format' => 'markdown']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("\n## Differences\n\n| What | ID | Field | Expected | Found |\n|---|---|---|---|---|\n", $output);
        $this->assertStringContainsString('| posts:post | 10 | name | {"en":"Moved on","ru":"Привет, мир"} | {"en":"x"} |', $output);
        $this->assertStringContainsString('| posts:post | 12 | name | {"en":"Gutenberg post"} | {"en":"x"} |', $output);
    }

    public function test_the_table_shows_the_first_two_hundred_differences_and_json_all_of_them(): void
    {
        config()->set('atrium-wordpress.post_types', ['book' => 'news']);
        $this->site();

        for ($id = 1000; $id < 1250; $id++) {
            $this->wpPost($id, 'book', 'Book '.$id);
        }

        [, $table] = $this->cli('atrium:wordpress:parity');
        [, $report] = $this->report();

        $this->assertSame(200, substr_count($table, ': expected '));
        $this->assertSame(250, count(array_filter($report['problems'], static fn (array $problem): bool => $problem['scope'] === 'posts:book')));
        $this->assertStringContainsString(count($report['problems']).' differences.', $table);
    }

    public function test_pipes_in_a_cell_are_escaped_and_long_values_cut(): void
    {
        $this->fullRun();
        $this->wp()->table('wp_posts')->where('ID', Wp::GUTENBERG)->update(['post_title' => 'A | B '.str_repeat('long ', 40)]);

        [, $output] = $this->cli('atrium:wordpress:parity', ['--format' => 'markdown']);

        $this->assertStringContainsString('{"en":"A \\| B long long', $output);
        $this->assertStringContainsString('…', $output);
        $this->assertStringNotContainsString(str_repeat('long ', 40), $output);

        // The cell is 120 characters wide, the cut mark included, and the pipe's backslash comes on top.
        $expected = mb_substr('{"en":"A | B '.str_repeat('long ', 40).'"}', 0, 119).'…';
        $this->assertStringContainsString('| posts:post | 12 | name | '.str_replace('|', '\\|', $expected).' | {"en":"Gutenberg post"} |', $output);
    }
}
