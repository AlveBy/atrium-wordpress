<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Feature;

use Atrium\WordPress\Tests\Concerns\ImportsWordPress;
use Atrium\WordPress\Tests\Fixtures\SyntheticWordPress as Wp;
use Atrium\WordPress\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Whatever permalink structure the old site had, the old addresses of its
 * posts end up in the map and in the redirects, next to the new ones.
 *
 * Hello World is a post of 2024-05-10 10:00 by alice in the category News (Novosti
 * in Russian), with the slug hello-world and the id 10; its Russian translation
 * is 11, of 10:05.
 */
final class PermalinkStructuresTest extends TestCase
{
    use ImportsWordPress;

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function structures(): array
    {
        return [
            'year and month' => ['/%year%/%monthnum%/%postname%/', '/2024/05/hello-world/', '/ru/2024/05/%d0%bf%d1%80%d0%b8%d0%b2%d0%b5%d1%82-%d0%bc%d0%b8%d1%80/'],
            'the whole date' => ['/%year%/%monthnum%/%day%/%postname%/', '/2024/05/10/hello-world/', '/ru/2024/05/10/%d0%bf%d1%80%d0%b8%d0%b2%d0%b5%d1%82-%d0%bc%d0%b8%d1%80/'],
            'the time' => ['/%hour%-%minute%-%second%/%postname%/', '/10-00-00/hello-world/', '/ru/10-05-00/%d0%bf%d1%80%d0%b8%d0%b2%d0%b5%d1%82-%d0%bc%d0%b8%d1%80/'],
            'only the name' => ['/%postname%/', '/hello-world/', '/ru/%d0%bf%d1%80%d0%b8%d0%b2%d0%b5%d1%82-%d0%bc%d0%b8%d1%80/'],
            'only the id' => ['/%post_id%/', '/10/', '/ru/11/'],
            'the id without the closing slash' => ['/archives/%post_id%', '/archives/10', '/ru/archives/11'],
            'the category' => ['/%category%/%postname%/', '/news/hello-world/', '/ru/novosti/%d0%bf%d1%80%d0%b8%d0%b2%d0%b5%d1%82-%d0%bc%d0%b8%d1%80/'],
            'the author' => ['/%author%/%postname%/', '/alice/hello-world/', '/ru/alice/%d0%bf%d1%80%d0%b8%d0%b2%d0%b5%d1%82-%d0%bc%d0%b8%d1%80/'],
        ];
    }

    #[DataProvider('structures')]
    public function test_a_post_keeps_its_old_address_under_every_structure(string $structure, string $english, string $russian): void
    {
        $this->site();
        $this->option('permalink_structure', $structure);
        $this->generateAndMigrate();
        $this->cli('atrium:wordpress:migrate:data');

        $paths = $this->paths('post', Wp::HELLO);

        $this->assertSame([[$english, '/news/hello-world'], ['/?p=10', '/news/hello-world'], [$russian, '/ru/news/privet-mir'], ['/?p=11', '/ru/news/privet-mir']], $paths);
        [$exit, $csv] = $this->cli('atrium:wordpress:redirects', ['--format' => 'csv']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString("\"{$english}\",\"/news/hello-world\"\n", $csv);
        $this->assertStringContainsString("\"{$russian}\",\"/ru/news/privet-mir\"\n", $csv);
    }

    public function test_the_plain_structure_leaves_only_the_query_addresses(): void
    {
        $this->site();
        $this->option('permalink_structure', '');
        $this->generateAndMigrate();
        $this->cli('atrium:wordpress:migrate:data');

        $this->assertSame([['/?p=10', '/news/hello-world'], ['/ru/?p=11', '/ru/news/privet-mir'], ['/?p=11', '/ru/news/privet-mir']], $this->paths('post', Wp::HELLO));
        $this->assertSame([['/?page_id=30', '/about'], ['/ru/?page_id=31', '/ru/about'], ['/?page_id=31', '/ru/about']], $this->paths('post', Wp::ABOUT));
    }

    public function test_pages_do_not_depend_on_the_structure_of_posts(): void
    {
        $this->site();
        $this->option('permalink_structure', '/%post_id%/');
        $this->generateAndMigrate();
        $this->cli('atrium:wordpress:migrate:data');

        $this->assertSame(['/about/', '/about'], $this->paths('post', Wp::ABOUT)[0]);
        $this->assertSame(['/about/team/', '/about/team'], $this->paths('post', Wp::TEAM)[0]);
        $this->assertSame(['/about/team/history/', '/about/team/history'], $this->paths('post', Wp::HISTORY)[0]);
    }

    public function test_the_category_and_tag_bases_of_the_old_site_are_the_prefix_of_its_archives(): void
    {
        $this->site();
        $this->option('category_base', 'topics');
        $this->option('tag_base', 'labels');
        $this->generateAndMigrate();
        $this->cli('atrium:wordpress:migrate:data');

        $this->assertSame(['/topics/news/', '/topics/news'], $this->paths('term', Wp::TERM_NEWS + Wp::TT_OFFSET)[0]);
        $this->assertSame(['/labels/laravel/', '/labels/laravel'], $this->paths('term', Wp::TERM_LARAVEL + Wp::TT_OFFSET)[0]);
    }
}
