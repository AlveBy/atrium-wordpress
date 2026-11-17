<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Feature;

use Atrium\WordPress\Tests\Concerns\ImportsWordPress;
use Atrium\WordPress\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The 301 map: every old address the made-up site had, in the three formats.
 */
final class RedirectsTest extends TestCase
{
    use ImportsWordPress;

    /**
     * What the made-up site's published posts, pages and terms were called
     * (permalinks /%year%/%monthnum%/%postname%/) and what they are called
     * now: the long address and the short /?p=ID one of every published
     * post, the address of every term. Drafts, a scheduled and a private
     * post had no public address.
     *
     * @var array<string,string>
     */
    private const PAIRS = [
        '/2024/05/hello-world/' => '/news/hello-world',
        '/?p=10' => '/news/hello-world',
        '/ru/2024/05/%d0%bf%d1%80%d0%b8%d0%b2%d0%b5%d1%82-%d0%bc%d0%b8%d1%80/' => '/ru/news/privet-mir',
        '/?p=11' => '/ru/news/privet-mir',
        '/2024/05/gutenberg-post/' => '/news/gutenberg-post',
        '/?p=12' => '/news/gutenberg-post',
        '/2024/05/encoding-mess/' => '/news/encoding-mess',
        '/?p=18' => '/news/encoding-mess',
        '/2024/05/orphan-author/' => '/news/orphan-author',
        '/?p=22' => '/news/orphan-author',
        '/about/' => '/about',
        '/?page_id=30' => '/about',
        '/ru/o-nas/' => '/ru/about',
        '/?page_id=31' => '/ru/about',
        '/about/team/' => '/about/team',
        '/?page_id=32' => '/about/team',
        '/about/team/history/' => '/about/team/history',
        '/?page_id=33' => '/about/team/history',
        '/lost-child/' => '/lost-child',
        '/?page_id=34' => '/lost-child',
        '/recipe/borscht/' => '/recipe/borscht',
        '/?p=40' => '/recipe/borscht',
        '/ru/recipe/borshch/' => '/ru/recipe/borshch',
        '/?p=41' => '/ru/recipe/borshch',
        '/recipe/pizza/' => '/recipe/pizza',
        '/?p=42' => '/recipe/pizza',
        '/category/uncategorized/' => '/category/uncategorized',
        '/category/news/' => '/category/news',
        '/ru/category/novosti/' => '/ru/category/news',
        '/category/news/local/' => '/category/local',
        '/tag/laravel/' => '/tag/laravel',
        '/tag/php/' => '/tag/php',
        '/cuisine/italian/' => '/cuisine/italian',
        '/cuisine/russian/' => '/cuisine/russian',
    ];

    /**
     * A row of wordpress_map with old and new addresses.
     *
     * @param  array<int,array{0:string,1:string}>  $pairs
     */
    private function mapped(string $kind, int $id, array $pairs): void
    {
        DB::table('wordpress_map')->insert([
            'kind' => $kind,
            'wp_id' => $id,
            'wp_type' => 'x',
            'target_type' => 'X',
            'target_id' => $id,
            'paths' => json_encode($pairs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function test_nginx_is_the_default_and_maps_every_address(): void
    {
        $this->fullRun();

        [$exit, $output] = $this->cli('atrium:wordpress:redirects');

        $lines = [
            '# In the http block; then in the server block:',
            '#   if ($atrium_wordpress_redirect) { return 301 $atrium_wordpress_redirect; }',
            'map $request_uri $atrium_wordpress_redirect {',
            '    default "";',
        ];
        foreach (self::PAIRS as $old => $new) {
            $lines[] = '    "'.$old.'" "'.$new.'";';
        }
        $lines[] = '}';

        $this->assertSame(0, $exit);
        $this->assertSame(implode("\n", $lines)."\n", $output);
    }

    public function test_the_same_in_nginx_when_asked_for(): void
    {
        $this->fullRun();

        [, $default] = $this->cli('atrium:wordpress:redirects');
        [$exit, $asked] = $this->cli('atrium:wordpress:redirects', ['--format' => 'nginx']);

        $this->assertSame(0, $exit);
        $this->assertSame($default, $asked);
    }

    public function test_csv_has_a_row_per_address(): void
    {
        $this->fullRun();

        [$exit, $output] = $this->cli('atrium:wordpress:redirects', ['--format' => 'csv']);

        $lines = ['old,new'];
        foreach (self::PAIRS as $old => $new) {
            $lines[] = '"'.$old.'","'.$new.'"';
        }

        $this->assertSame(0, $exit);
        $this->assertSame(implode("\n", $lines)."\n", $output);
    }

    public function test_apache_has_a_rule_per_address_and_a_condition_for_each_query(): void
    {
        $this->fullRun();

        [$exit, $output] = $this->cli('atrium:wordpress:redirects', ['--format' => 'apache']);
        $lines = explode("\n", rtrim($output, "\n"));

        $this->assertSame(0, $exit);
        $this->assertSame('RewriteEngine On', $lines[0]);
        $this->assertSame(1 + 34 + 13, count($lines));
        $this->assertSame(34, count(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'RewriteRule '))));
        $this->assertSame(13, count(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'RewriteCond '))));
        $this->assertContains('RewriteRule ^2024/05/hello\-world/$ /news/hello-world [R=301,L]', $lines);
        // A short link is a rule on the front page with the query as a condition, and sends the query away.
        $index = array_search('RewriteCond %{QUERY_STRING} ^p\=10$', $lines, true);
        $this->assertIsInt($index);
        $this->assertSame('RewriteRule ^$ /news/hello-world? [R=301,L]', $lines[$index + 1]);
        $this->assertContains('RewriteCond %{QUERY_STRING} ^page_id\=30$', $lines);
        $this->assertContains('RewriteRule ^about/team/history/$ /about/team/history [R=301,L]', $lines);
        $this->assertContains('RewriteRule ^category/news/local/$ /category/local [R=301,L]', $lines);
    }

    public function test_apache_matches_the_decoded_path(): void
    {
        $this->fullRun();

        [, $output] = $this->cli('atrium:wordpress:redirects', ['--format' => 'apache']);

        $this->assertStringContainsString("\nRewriteRule ^ru/2024/05/привет\\-мир/\$ /ru/news/privet-mir [R=301,L]\n", $output);
        $this->assertStringNotContainsString('%d0%bf', $output);
    }

    public function test_the_map_can_be_written_to_a_file(): void
    {
        $this->fullRun();
        $file = $this->workDir.'/redirects.conf';

        [$exit, $output] = $this->cli('atrium:wordpress:redirects', ['--format' => 'csv', '--output' => $file]);

        $this->assertSame(0, $exit);
        $this->assertSame("34 redirects written to {$file}\n", $output);
        $written = (string) file_get_contents($file);
        $this->assertStringStartsWith("old,new\n\"/2024/05/hello-world/\",\"/news/hello-world\"\n", $written);
        $this->assertSame(35, substr_count($written, "\n"));
        $this->assertStringNotContainsString('hello-world', $output);
    }

    public function test_an_unknown_format_is_an_error_and_writes_nothing(): void
    {
        $this->fullRun();
        $file = $this->workDir.'/redirects.conf';

        [$exit, $output] = $this->cli('atrium:wordpress:redirects', ['--format' => 'caddy', '--output' => $file]);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('Unknown format "caddy": nginx, apache or csv.', $output);
        $this->assertFileDoesNotExist($file);
    }

    #[DataProvider('formats')]
    public function test_a_map_without_addresses_is_just_its_frame(string $format, string $expected): void
    {
        [$exit, $output] = $this->cli('atrium:wordpress:redirects', ['--format' => $format]);

        $this->assertSame(0, $exit);
        $this->assertSame($expected, $output);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function formats(): array
    {
        return [
            'nginx' => ['nginx', "# In the http block; then in the server block:\n#   if (\$atrium_wordpress_redirect) { return 301 \$atrium_wordpress_redirect; }\nmap \$request_uri \$atrium_wordpress_redirect {\n    default \"\";\n}\n"],
            'apache' => ['apache', "RewriteEngine On\n"],
            'csv' => ['csv', "old,new\n"],
        ];
    }

    public function test_rows_without_addresses_and_addresses_that_do_not_change_are_left_out(): void
    {
        DB::table('wordpress_map')->insert(['kind' => 'author', 'wp_id' => 1, 'wp_type' => 'author', 'target_type' => 'X', 'target_id' => 1, 'paths' => null]);
        $this->mapped('post', 5, [['/same', '/same'], ['/old', '/new']]);

        [, $output] = $this->cli('atrium:wordpress:redirects', ['--format' => 'csv']);

        $this->assertSame("old,new\n\"/old\",\"/new\"\n", $output);
    }

    public function test_a_row_whose_addresses_are_a_json_null_is_left_out(): void
    {
        DB::table('wordpress_map')->insert(['kind' => 'post', 'wp_id' => 4, 'wp_type' => 'post', 'target_type' => 'X', 'target_id' => 1, 'paths' => 'null']);
        $this->mapped('post', 5, [['/old', '/new']]);

        [$exit, $output] = $this->cli('atrium:wordpress:redirects', ['--format' => 'csv']);

        $this->assertSame(0, $exit);
        $this->assertSame("old,new\n\"/old\",\"/new\"\n", $output);
    }

    public function test_an_address_that_is_not_text_is_written_as_text(): void
    {
        $this->mapped('post', 5, [['/old', 404]]);

        [, $output] = $this->cli('atrium:wordpress:redirects', ['--format' => 'csv']);

        $this->assertSame("old,new\n\"/old\",\"404\"\n", $output);
    }

    public function test_apache_keeps_everything_after_the_first_question_mark_as_the_query(): void
    {
        $this->mapped('post', 5, [['/old?a=1?b=2', '/new']]);

        [, $output] = $this->cli('atrium:wordpress:redirects', ['--format' => 'apache']);

        $this->assertSame("RewriteEngine On\nRewriteCond %{QUERY_STRING} ^a\\=1\\?b\\=2\$\nRewriteRule ^old\$ /new? [R=301,L]\n", $output);
    }

    public function test_an_address_given_twice_is_listed_once_with_the_last_target_in_its_place(): void
    {
        $this->mapped('post', 5, [['/dup', '/first'], ['/other', '/o']]);
        $this->mapped('post', 6, [['/dup', '/second']]);

        [, $output] = $this->cli('atrium:wordpress:redirects', ['--format' => 'csv']);

        $this->assertSame("old,new\n\"/dup\",\"/second\"\n\"/other\",\"/o\"\n", $output);
    }

    public function test_the_rows_are_in_the_order_of_their_kind_and_id(): void
    {
        $this->mapped('term', 1, [['/t1', '/a']]);
        $this->mapped('post', 20, [['/p20', '/a']]);
        $this->mapped('post', 3, [['/p3', '/a']]);
        $this->mapped('attachment', 9, [['/a9', '/a']]);

        [, $output] = $this->cli('atrium:wordpress:redirects', ['--format' => 'csv']);

        $this->assertSame("old,new\n\"/a9\",\"/a\"\n\"/p3\",\"/a\"\n\"/p20\",\"/a\"\n\"/t1\",\"/a\"\n", $output);
    }

    public function test_quotes_and_backslashes_are_escaped(): void
    {
        $this->mapped('post', 5, [['/a"b\\c', '/d"e\\f']]);

        [, $nginx] = $this->cli('atrium:wordpress:redirects', ['--format' => 'nginx']);
        [, $csv] = $this->cli('atrium:wordpress:redirects', ['--format' => 'csv']);

        $this->assertStringContainsString("\n    \"/a\\\"b\\\\c\" \"/d\\\"e\\\\f\";\n", $nginx);
        $this->assertStringContainsString("\n\"/a\"\"b\\c\",\"/d\"\"e\\f\"\n", $csv);
    }

    public function test_apache_escapes_spaces_and_special_characters_of_the_pattern(): void
    {
        $this->mapped('post', 5, [['/my page.html', '/x'], ['/find/?s=a.b', '/y'], ['/q?', '/z']]);

        [, $output] = $this->cli('atrium:wordpress:redirects', ['--format' => 'apache']);

        $this->assertSame(implode("\n", [
            'RewriteEngine On',
            'RewriteRule ^my\\ page\\.html$ /x [R=301,L]',
            'RewriteCond %{QUERY_STRING} ^s\\=a\\.b$',
            'RewriteRule ^find/$ /y? [R=301,L]',
            'RewriteCond %{QUERY_STRING} ^$',
            'RewriteRule ^q$ /z? [R=301,L]',
        ])."\n", $output);
    }
}
