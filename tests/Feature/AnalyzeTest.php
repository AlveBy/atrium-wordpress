<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Feature;

use Atrium\WordPress\Tests\Concerns\ImportsWordPress;
use Atrium\WordPress\Tests\TestCase;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The report of what a WordPress site holds, before anything is moved.
 */
final class AnalyzeTest extends TestCase
{
    use ImportsWordPress;

    /**
     * @return array<string,mixed>
     */
    private function analysis(array $arguments = []): array
    {
        [$exit, $output] = $this->cli('atrium:wordpress:analyze', ['--json' => true, ...$arguments]);
        $this->assertSame(0, $exit);

        return json_decode($output, true);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function languageLines(): array
    {
        return [
            'WPML' => ['wpml', 'Languages: en, ru (default en, wpml)'],
            'Polylang' => ['polylang', 'Languages: en, ru (default en, polylang)'],
            'no plugin' => ['none', 'Languages: ru (default ru, no language plugin)'],
        ];
    }

    #[DataProvider('languageLines')]
    public function test_the_table_says_what_the_site_holds(string $plugin, string $languages): void
    {
        $this->site($plugin);

        [$exit, $output] = $this->cli('atrium:wordpress:analyze');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("Site: https://old.example.com\n{$languages}\nSEO: yoast, rankmath\n", $output);
        $this->assertStringContainsString('Authors go to the table authors; attachments to /wp-content/uploads and the media library.', $output);
        $this->assertStringEndsWith("Next: php artisan atrium:wordpress:migrate\n", $output);
    }

    public function test_a_blank_line_sets_the_summary_apart_from_the_tables(): void
    {
        $this->site();

        [, $output] = $this->cli('atrium:wordpress:analyze');

        $this->assertMatchesRegularExpression('/\nSEO: yoast, rankmath\n\n\+-+\+/', $output);
    }

    public function test_the_uploads_folder_is_shown_with_one_slash_however_it_is_written(): void
    {
        $this->site();
        config()->set('atrium-wordpress.uploads_target', '/media/old');

        [, $output] = $this->cli('atrium:wordpress:analyze');

        $this->assertStringContainsString('attachments to /media/old and the media library.', $output);
    }

    public function test_the_post_types_table_has_a_row_for_each_type(): void
    {
        $this->site();

        [, $output] = $this->cli('atrium:wordpress:analyze');

        $this->assertMatchesRegularExpression('/\| Post type \| Posts \| Records \| Goes to\s+\| Table\s+\| Fields\s+\| Why \|/', $output);
        $this->assertMatchesRegularExpression('/\| page\s+\| 6\s+\| 5\s+\| pages\s+\| pages\s+\| -\s+\|\s+\|/', $output);
        $this->assertMatchesRegularExpression('/\| post\s+\| 9\s+\| 8\s+\| news\s+\| news\s+\| -\s+\|\s+\|/', $output);
        $this->assertMatchesRegularExpression('/\| recipe\s+\| 3\s+\| 2\s+\| new module Recipe\s+\| recipes\s+\| cooking_time \(number\), difficulty \(select\), ingredients \(textarea\), spicy \(true_false\), served_on \(date_picker\), photo \(image\), gallery \(gallery\), labels \(checkbox\), steps \(repeater\), notes \(wysiwyg\), source_url \(meta\)\s+\|\s+\|/', $output);
        $this->assertStringNotContainsString('acf-field', $output);
    }

    public function test_the_taxonomies_table_says_where_each_is_used(): void
    {
        $this->site();

        [, $output] = $this->cli('atrium:wordpress:analyze');

        $this->assertMatchesRegularExpression('/\| Taxonomy \| Terms \| Table\s+\| Linked to \|/', $output);
        $this->assertMatchesRegularExpression('/\| category\s+\| 4\s+\| categories\s+\| post\s+\|/', $output);
        $this->assertMatchesRegularExpression('/\| cuisine\s+\| 2\s+\| cuisines\s+\| recipe\s+\|/', $output);
        $this->assertMatchesRegularExpression('/\| post_tag\s+\| 2\s+\| tags\s+\| post\s+\|/', $output);
        $this->assertStringNotContainsString('nav_menu', $output);
    }

    public function test_a_type_that_does_not_move_has_no_records_and_says_why(): void
    {
        config()->set('atrium-wordpress.post_types', ['book' => 'skip', 'recipe' => 'news']);
        $this->site();
        $this->wpPost(450, 'book', 'A book');

        [, $output] = $this->cli('atrium:wordpress:analyze');

        $this->assertMatchesRegularExpression('/\| book\s+\| 1\s+\| -\s+\| skip\s+\| -\s+\| -\s+\| skipped in the config\s+\|/', $output);
        $this->assertMatchesRegularExpression('/\| recipe\s+\| 3\s+\| 2\s+\| news\s+\| news\s+\|[^|]*\(number\)[^|]*\| lost: custom fields the news module has no place for: cooking_time, /', $output);
    }

    public function test_a_site_without_seo_plugins_or_a_language_plugin(): void
    {
        $this->site('none');
        $this->option('active_plugins', serialize(['advanced-custom-fields-pro/acf.php']));

        [, $output] = $this->cli('atrium:wordpress:analyze');

        $this->assertStringContainsString("\nSEO: no SEO plugin\n", $output);
    }

    public function test_the_site_address_comes_without_a_trailing_slash(): void
    {
        $this->site();
        $this->option('home', 'https://old.example.com///');

        [, $output] = $this->cli('atrium:wordpress:analyze');

        $this->assertStringStartsWith("Site: https://old.example.com\n", $output);
    }

    public function test_json_describes_the_plan(): void
    {
        $this->site();
        $generated = TestCase::NAMESPACE;

        $fields = [];
        foreach ([
            'cooking_time' => 'number', 'difficulty' => 'select', 'ingredients' => 'textarea', 'spicy' => 'true_false',
            'served_on' => 'date_picker', 'photo' => 'image', 'gallery' => 'gallery', 'labels' => 'checkbox',
            'steps' => 'repeater', 'notes' => 'wysiwyg', 'source_url' => 'meta',
        ] as $name => $kind) {
            $fields[] = ['name' => $name, 'column' => $name, 'kind' => $kind];
        }

        $this->assertEquals([
            'home' => 'https://old.example.com',
            'languages' => ['codes' => ['en', 'ru'], 'default' => 'en', 'plugin' => 'wpml', 'urls' => 'directory'],
            'seo' => ['yoast', 'rankmath'],
            'types' => [
                'page' => ['target' => 'pages', 'table' => 'pages', 'model' => 'Atrium\\Pages\\Models\\Page', 'prefix' => '', 'count' => 6, 'fields' => [], 'taxonomies' => [], 'reason' => null],
                'post' => ['target' => 'news', 'table' => 'news', 'model' => 'Atrium\\News\\Models\\News', 'prefix' => 'news', 'count' => 9, 'fields' => [], 'taxonomies' => ['category', 'post_tag'], 'reason' => null],
                'recipe' => ['target' => 'generate', 'table' => 'recipes', 'model' => $generated.'\\Recipe', 'prefix' => 'recipe', 'count' => 3, 'fields' => $fields, 'taxonomies' => ['cuisine'], 'reason' => null],
            ],
            'taxonomies' => [
                'category' => ['table' => 'categories', 'model' => $generated.'\\Category', 'prefix' => 'category', 'count' => 4, 'types' => ['post']],
                'cuisine' => ['table' => 'cuisines', 'model' => $generated.'\\Cuisine', 'prefix' => 'cuisine', 'count' => 2, 'types' => ['recipe']],
                'post_tag' => ['table' => 'tags', 'model' => $generated.'\\Tag', 'prefix' => 'tag', 'count' => 2, 'types' => ['post']],
            ],
            'authors' => ['table' => 'authors', 'model' => $generated.'\\Author'],
        ], $this->analysis());
    }

    public function test_json_is_pretty_and_not_escaped(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        config()->set('atrium-wordpress.post_types', ['book' => ['label' => 'Книги']]);

        [, $output] = $this->cli('atrium:wordpress:analyze', ['--json' => true]);

        $this->assertStringStartsWith("{\n    \"home\": \"https://old.example.com\",\n", $output);
        $this->assertStringContainsString('"model": "Atrium\\\\Pages\\\\Models\\\\Page"', $output);
        $this->assertStringNotContainsString('\\/', $output);
        $this->assertStringNotContainsString('Next:', $output);
    }

    public function test_json_of_a_site_with_one_language(): void
    {
        $this->site('none');

        $report = $this->analysis();

        $this->assertSame(['codes' => ['ru'], 'default' => 'ru', 'plugin' => null, 'urls' => 'directory'], $report['languages']);
    }

    public function test_the_urls_of_a_site_with_a_language_per_domain(): void
    {
        $this->site();
        $this->option('icl_sitepress_settings', serialize(['default_language' => 'en', 'language_negotiation_type' => 2]));

        $this->assertSame('domain', $this->analysis()['languages']['urls']);
    }

    public function test_the_wordpress_database_can_be_named_on_the_command_line(): void
    {
        $this->site();
        config()->set('atrium-wordpress.connection', 'nowhere');

        $report = $this->analysis(['--connection' => 'wordpress']);

        $this->assertSame('https://old.example.com', $report['home']);
    }

    public function test_the_prefix_of_the_tables_can_be_named_on_the_command_line(): void
    {
        $this->site('wpml', 'blog_');

        $report = $this->analysis(['--prefix' => 'blog_']);

        $this->assertSame(9, $report['types']['post']['count']);
    }

    public function test_the_prefix_comes_from_the_config_when_not_given(): void
    {
        $this->site('wpml', 'blog_');
        config()->set('atrium-wordpress.table_prefix', 'blog_');

        $this->assertSame(9, $this->analysis()['types']['post']['count']);
    }

    public function test_an_empty_prefix_is_a_prefix_too(): void
    {
        $this->site('wpml', '');
        config()->set('atrium-wordpress.table_prefix', 'wp_');

        $this->assertSame(9, $this->analysis(['--prefix' => ''])['types']['post']['count']);
    }

    public function test_an_unknown_connection_stops_the_command(): void
    {
        $this->site();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Database connection [nowhere] not configured.');

        $this->cli('atrium:wordpress:analyze', ['--connection' => 'nowhere']);
    }

    public function test_a_wrong_prefix_stops_the_command_before_anything_is_planned(): void
    {
        $this->site();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no such table: other_options');

        $this->cli('atrium:wordpress:analyze', ['--prefix' => 'other_']);
    }
}
