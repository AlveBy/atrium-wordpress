<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Unit;

use Atrium\WordPress\Import\Permalinks;
use Atrium\WordPress\Plan\Planner;
use Atrium\WordPress\Source\Database;
use Atrium\WordPress\Tests\Fixtures\SpySource;
use Atrium\WordPress\Tests\Fixtures\SyntheticWordPress as Wp;
use Atrium\WordPress\Tests\LightTestCase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The old addresses worked out from the permalink settings of the made-up
 * site. Its posts: Hello World (10, News, Alice, 2024-05-10 10:00:00),
 * Gutenberg post (12, Local, a child of News, Boris), the Russian
 * translation of Hello (11), the page tree About / Team / History.
 */
final class PermalinksTest extends LightTestCase
{
    private Permalinks $links;

    private SpySource $spy;

    /** @var array<int,object> */
    private array $posts = [];

    private function wp(): Connection
    {
        return DB::connection('wordpress');
    }

    private function option(string $name, ?string $value): void
    {
        $this->wp()->table('wp_options')->where('option_name', $name)->delete();

        if ($value !== null) {
            $this->wp()->table('wp_options')->insert(['option_name' => $name, 'option_value' => $value, 'autoload' => 'yes']);
        }
    }

    /**
     * Installs the site and builds the addresses of its posts.
     *
     * @param  array<string,string|null>  $options
     * @param  array<int,int>|null  $remember  only these posts are known (default: all)
     */
    private function site(string $structure, string $languages = 'wpml', array $options = [], ?callable $edit = null, ?array $remember = null): void
    {
        Wp::install($this->wp(), 'wp_', $languages);
        $this->option('permalink_structure', $structure);

        foreach ($options as $name => $value) {
            $this->option($name, $value);
        }

        if ($edit !== null) {
            $edit($this->wp());
        }

        $plan = SpySource::watch((new Planner)->build(new Database($this->wp(), 'wp_')));
        $this->spy = $plan->source;
        $this->posts = $plan->source->posts(range(1, 100));
        $this->links = new Permalinks($plan);
        $this->links->remember($remember === null ? array_values($this->posts) : array_values(array_intersect_key($this->posts, array_flip($remember))));
    }

    private function path(int $id, string $language = 'en'): ?string
    {
        return $this->links->post($this->posts[$id], $language);
    }

    private function term(int $termId, string $taxonomy, string $language = 'en'): string
    {
        foreach ($this->termRows($taxonomy) as $term) {
            if ((int) $term->term_id === $termId) {
                return $this->links->term($term, $language);
            }
        }

        $this->fail("No term {$termId} in {$taxonomy}");
    }

    /**
     * @return array<int,object>
     */
    private function termRows(string $taxonomy): array
    {
        return array_values(array_filter(
            (new Database($this->wp(), 'wp_'))->terms(),
            static fn (object $term): bool => $term->taxonomy === $taxonomy,
        ));
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}> structure => path of Hello World, of About, of Team
     */
    public static function structures(): array
    {
        return [
            'year, month and name' => ['/%year%/%monthnum%/%postname%/', '/2024/05/hello-world/', '/about/'],
            'with the day' => ['/%year%/%monthnum%/%day%/%postname%/', '/2024/05/10/hello-world/', '/about/'],
            'with the time' => ['/%hour%-%minute%-%second%/%postname%/', '/10-00-00/hello-world/', '/about/'],
            'the name only' => ['/%postname%/', '/hello-world/', '/about/'],
            'the post ID' => ['/%post_id%/', '/10/', '/about/'],
            'a fixed word, the ID' => ['/archives/%post_id%', '/archives/10', '/about'],
            'the author' => ['/%author%/%postname%/', '/alice/hello-world/', '/about/'],
            'the category' => ['/%category%/%postname%/', '/news/hello-world/', '/about/'],
            'no slash at the end' => ['/%year%/%postname%', '/2024/hello-world', '/about'],
            'slashes doubled' => ['/blog//%postname%//', '/blog/hello-world/', '/about/'],
        ];
    }

    #[DataProvider('structures')]
    public function test_a_post_gets_the_address_its_structure_gives(string $structure, string $hello, string $about): void
    {
        $this->site($structure);

        $this->assertSame($hello, $this->path(Wp::HELLO));
        // A page ignores the structure: its tree is its address.
        $this->assertSame($about, $this->path(Wp::ABOUT));
    }

    public function test_an_empty_structure_is_the_plain_addresses(): void
    {
        $this->site('');

        $this->assertSame('/?p=10', $this->path(Wp::HELLO));
        $this->assertSame('/?page_id=30', $this->path(Wp::ABOUT));
        $this->assertSame('/?p=40', $this->path(Wp::BORSCHT));
        $this->assertSame('/?page_id=32', $this->path(Wp::TEAM));
    }

    public function test_a_post_without_a_name_is_the_plain_address_whatever_the_structure(): void
    {
        $this->site('/%postname%/', edit: function (Connection $db): void {
            $db->table('wp_posts')->where('ID', Wp::HELLO)->update(['post_name' => '']);
            $db->table('wp_posts')->where('ID', Wp::ABOUT)->update(['post_name' => '']);
        });

        $this->assertSame('/?p=10', $this->path(Wp::HELLO));
        $this->assertSame('/?page_id=30', $this->path(Wp::ABOUT));
    }

    public function test_a_post_that_is_not_published_has_no_address(): void
    {
        $this->site('/%postname%/');

        foreach ([Wp::DRAFT_IDEA, Wp::SCHEDULED, Wp::PRIVATE_NOTE, Wp::TRASHED, Wp::CONTACTS, Wp::HELLO_AGAIN] as $id) {
            $this->assertNull($this->path($id), "post {$id}");
        }
    }

    public function test_the_front_page_has_no_address_because_it_is_the_root(): void
    {
        $this->site('/%postname%/', options: ['page_on_front' => '30']);

        $this->assertNull($this->path(Wp::ABOUT));
        // Its children keep it in their address: the page is still there.
        $this->assertSame('/about/team/', $this->path(Wp::TEAM));
        $this->assertSame('/hello-world/', $this->path(Wp::HELLO));
    }

    public function test_a_page_tree_is_the_address(): void
    {
        $this->site('/%postname%/');

        $this->assertSame('/about/', $this->path(Wp::ABOUT));
        $this->assertSame('/about/team/', $this->path(Wp::TEAM));
        $this->assertSame('/about/team/history/', $this->path(Wp::HISTORY));
    }

    public function test_a_page_whose_parent_is_gone_stands_alone(): void
    {
        $this->site('/%postname%/');

        $this->assertSame('/lost-child/', $this->path(Wp::LOST_CHILD));
    }

    public function test_a_page_loses_the_parents_that_were_not_read(): void
    {
        $this->site('/%postname%/', remember: [Wp::TEAM, Wp::HISTORY]);

        $this->assertSame('/team/history/', $this->path(Wp::HISTORY));
    }

    public function test_posts_are_remembered_batch_after_batch(): void
    {
        $this->site('/%postname%/', remember: [Wp::ABOUT]);
        $this->links->remember([$this->posts[Wp::TEAM]]);
        $this->links->remember([$this->posts[Wp::HISTORY]]);

        $this->assertSame('/about/team/history/', $this->path(Wp::HISTORY));
    }

    public function test_authors_are_asked_for_once_each_by_their_number(): void
    {
        $this->site('/%author%/%postname%/', remember: []);
        $this->spy->calls = [];
        $posts = [];

        foreach ([Wp::HELLO => '1', Wp::ABOUT => '1', Wp::GUTENBERG => '2'] as $id => $author) {
            $posts[] = (object) [...(array) $this->posts[$id], 'post_author' => $author];
        }

        $this->links->remember($posts);

        $this->assertSame([[[1, 2]]], $this->spy->calls['users']);
    }

    public function test_the_authors_of_every_batch_are_kept(): void
    {
        $this->site('/%author%/%postname%/', remember: [Wp::HELLO]);
        $this->links->remember([$this->posts[Wp::GUTENBERG]]);

        $this->assertSame('/alice/hello-world/', $this->path(Wp::HELLO));
        $this->assertSame('/boris/gutenberg-post/', $this->path(Wp::GUTENBERG));
    }

    public function test_a_tree_that_loops_back_does_not_loop_forever(): void
    {
        $this->site('/%postname%/', edit: function (Connection $db): void {
            $db->table('wp_posts')->where('ID', Wp::ABOUT)->update(['post_parent' => Wp::HISTORY]);
        });

        $this->assertSame('/about/team/history/', $this->path(Wp::HISTORY));
        $this->assertSame('/team/history/about/', $this->path(Wp::ABOUT));
    }

    public function test_a_page_a_post_is_the_parent_of_takes_the_post_and_stops(): void
    {
        $this->site('/%postname%/', edit: function (Connection $db): void {
            $db->table('wp_posts')->where('ID', Wp::TEAM)->update(['post_parent' => Wp::HELLO]);
            $db->table('wp_posts')->where('ID', Wp::HELLO)->update(['post_parent' => Wp::GUTENBERG]);
        });

        $this->assertSame('/hello-world/team/', $this->path(Wp::TEAM));
    }

    public function test_a_custom_type_lives_under_its_name(): void
    {
        $this->site('/%year%/%postname%/');

        $this->assertSame('/recipe/borscht/', $this->path(Wp::BORSCHT));
        $this->assertSame('/recipe/pizza/', $this->path(Wp::PIZZA));
    }

    public function test_a_custom_type_without_a_slash_at_the_end_has_none(): void
    {
        $this->site('/%postname%');

        $this->assertSame('/recipe/borscht', $this->path(Wp::BORSCHT));
    }

    public function test_a_post_date_that_cannot_be_read_is_the_start_of_the_epoch(): void
    {
        $this->site('/%year%/%monthnum%/%day%/%hour%/%minute%/%second%/%postname%/', edit: function (Connection $db): void {
            $db->table('wp_posts')->where('ID', Wp::HELLO)->update(['post_date' => 'not a date']);
        });

        $this->assertSame('/1970/01/01/00/00/00/hello-world/', $this->path(Wp::HELLO));
    }

    public function test_the_category_of_a_post_is_the_one_with_the_lowest_id_with_its_parents(): void
    {
        $this->site('/%category%/%postname%/');

        $this->assertSame('/news/hello-world/', $this->path(Wp::HELLO));
        // Local is a child of News.
        $this->assertSame('/news/local/gutenberg-post/', $this->path(Wp::GUTENBERG));
        $this->assertSame('/uncategorized/orphan-author/', $this->path(Wp::ORPHAN));
    }

    public function test_of_several_categories_the_first_by_id_wins(): void
    {
        $this->site('/%category%/%postname%/', edit: function (Connection $db): void {
            // Hello is in News (2); add Local (3) and Uncategorized (1), in this order.
            $db->table('wp_term_relationships')->insert([
                ['object_id' => Wp::HELLO, 'term_taxonomy_id' => Wp::TERM_LOCAL + Wp::TT_OFFSET, 'term_order' => 0],
                ['object_id' => Wp::HELLO, 'term_taxonomy_id' => Wp::TERM_UNCATEGORIZED + Wp::TT_OFFSET, 'term_order' => 0],
            ]);
        });

        $this->assertSame('/uncategorized/hello-world/', $this->path(Wp::HELLO));
    }

    public function test_a_post_without_a_category_is_filed_under_uncategorized(): void
    {
        $this->site('/%category%/%postname%/', edit: function (Connection $db): void {
            // Gutenberg keeps nothing but a tag.
            $db->table('wp_term_relationships')->where('object_id', Wp::GUTENBERG)->delete();
            $db->table('wp_term_relationships')->insert(['object_id' => Wp::GUTENBERG, 'term_taxonomy_id' => Wp::TERM_LARAVEL + Wp::TT_OFFSET, 'term_order' => 0]);
        });

        $this->assertSame('/uncategorized/gutenberg-post/', $this->path(Wp::GUTENBERG));
    }

    public function test_the_author_of_a_post_that_is_gone_leaves_the_slot_empty(): void
    {
        $this->site('/%author%/%postname%/');

        $this->assertSame('/alice/hello-world/', $this->path(Wp::HELLO));
        $this->assertSame('/boris/gutenberg-post/', $this->path(Wp::GUTENBERG));
        // User 99 is not there: the empty segment collapses.
        $this->assertSame('/orphan-author/', $this->path(Wp::ORPHAN));
    }

    public function test_categories_and_authors_are_only_read_when_the_structure_asks(): void
    {
        $this->site('/%postname%/');

        $this->assertSame('/hello-world/', $this->path(Wp::HELLO));
        $this->assertSame('/gutenberg-post/', $this->path(Wp::GUTENBERG));
    }

    /**
     * @return array<string,array{0:string,1:array<string,string>,2:string,3:string}> name, options, then the Russian Hello and the default one
     */
    public static function languageModes(): array
    {
        $wpml = fn (int $negotiation): array => ['icl_sitepress_settings' => serialize(['default_language' => 'en', 'language_negotiation_type' => $negotiation])];
        $polylang = fn (int $force): array => ['polylang' => serialize(['default_lang' => 'en', 'force_lang' => $force])];

        return [
            'WPML folders' => ['wpml', $wpml(1), '/ru/2024/05/hello-world/', '/2024/05/hello-world/'],
            'WPML domains' => ['wpml', $wpml(2), '/2024/05/hello-world/', '/2024/05/hello-world/'],
            'WPML parameter' => ['wpml', $wpml(3), '/2024/05/hello-world/?lang=ru', '/2024/05/hello-world/'],
            'Polylang parameter' => ['polylang', $polylang(0), '/2024/05/hello-world/?lang=ru', '/2024/05/hello-world/'],
            'Polylang folders' => ['polylang', $polylang(1), '/ru/2024/05/hello-world/', '/2024/05/hello-world/'],
            'Polylang subdomains' => ['polylang', $polylang(2), '/2024/05/hello-world/', '/2024/05/hello-world/'],
            'Polylang domains' => ['polylang', $polylang(3), '/2024/05/hello-world/', '/2024/05/hello-world/'],
        ];
    }

    /**
     * @param  array<string,string>  $options
     */
    #[DataProvider('languageModes')]
    public function test_the_language_goes_where_the_site_put_it(string $plugin, array $options, string $russian, string $english): void
    {
        $this->site('/%year%/%monthnum%/%postname%/', $plugin, $options);

        $this->assertSame($russian, $this->path(Wp::HELLO, 'ru'));
        $this->assertSame($english, $this->path(Wp::HELLO, 'en'));
    }

    public function test_the_language_is_put_in_front_of_a_plain_address_too(): void
    {
        $this->site('', options: ['icl_sitepress_settings' => serialize(['default_language' => 'en', 'language_negotiation_type' => 1])]);

        $this->assertSame('/ru/?p=10', $this->path(Wp::HELLO, 'ru'));
    }

    public function test_a_plain_address_takes_the_language_as_a_parameter_after_its_own(): void
    {
        $this->site('', options: ['icl_sitepress_settings' => serialize(['default_language' => 'en', 'language_negotiation_type' => 3])]);

        $this->assertSame('/?p=10&lang=ru', $this->path(Wp::HELLO, 'ru'));
    }

    public function test_without_a_language_plugin_the_language_is_not_in_the_address(): void
    {
        $this->site('/%postname%/', 'none');

        $this->assertSame('/hello-world/', $this->path(Wp::HELLO, 'ru'));
        $this->assertSame('/hello-world/', $this->path(Wp::HELLO, 'en'));
    }

    public function test_the_default_language_of_a_site_has_no_prefix(): void
    {
        // WPML says Russian is the default: English is the one with a prefix.
        $this->site('/%postname%/', options: ['icl_sitepress_settings' => serialize(['default_language' => 'ru', 'language_negotiation_type' => 1])]);

        $this->assertSame('/hello-world/', $this->path(Wp::HELLO, 'ru'));
        $this->assertSame('/en/hello-world/', $this->path(Wp::HELLO, 'en'));
    }

    public function test_terms_have_their_bases(): void
    {
        $this->site('/%postname%/');

        $this->assertSame('/category/news/', $this->term(Wp::TERM_NEWS, 'category'));
        $this->assertSame('/category/news/local/', $this->term(Wp::TERM_LOCAL, 'category'));
        $this->assertSame('/tag/laravel/', $this->term(Wp::TERM_LARAVEL, 'post_tag'));
        $this->assertSame('/cuisine/italian/', $this->term(Wp::TERM_ITALIAN, 'cuisine'));
    }

    public function test_the_category_and_tag_bases_are_the_sites_own(): void
    {
        $this->site('/%postname%/', options: ['category_base' => '/topics/', 'tag_base' => 'labels']);

        $this->assertSame('/topics/news/local/', $this->term(Wp::TERM_LOCAL, 'category'));
        $this->assertSame('/labels/php/', $this->term(Wp::TERM_PHP, 'post_tag'));
        // Other taxonomies have no base setting.
        $this->assertSame('/cuisine/russian/', $this->term(Wp::TERM_RUSSIAN, 'cuisine'));
    }

    public function test_term_addresses_follow_the_slash_of_the_structure(): void
    {
        $this->site('/%postname%');

        $this->assertSame('/category/news/local', $this->term(Wp::TERM_LOCAL, 'category'));
        $this->assertSame('/tag/php', $this->term(Wp::TERM_PHP, 'post_tag'));
    }

    public function test_plain_term_addresses_use_parameters(): void
    {
        $this->site('');

        $this->assertSame('/?cat=2', $this->term(Wp::TERM_NEWS, 'category'));
        $this->assertSame('/?cat=3', $this->term(Wp::TERM_LOCAL, 'category'));
        $this->assertSame('/?tag=laravel', $this->term(Wp::TERM_LARAVEL, 'post_tag'));
        $this->assertSame('/?cuisine=italian', $this->term(Wp::TERM_ITALIAN, 'cuisine'));
    }

    public function test_plain_term_addresses_take_the_language_as_a_parameter(): void
    {
        $this->site('', options: ['icl_sitepress_settings' => serialize(['default_language' => 'en', 'language_negotiation_type' => 3])]);

        $this->assertSame('/?cat=4&lang=ru', $this->term(Wp::TERM_NOVOSTI, 'category', 'ru'));
        $this->assertSame('/?cat=4', $this->term(Wp::TERM_NOVOSTI, 'category', 'en'));
    }

    public function test_plain_term_addresses_take_the_language_in_a_folder(): void
    {
        $this->site('', options: ['icl_sitepress_settings' => serialize(['default_language' => 'en', 'language_negotiation_type' => 1])]);

        $this->assertSame('/ru/?cat=4', $this->term(Wp::TERM_NOVOSTI, 'category', 'ru'));
    }

    public function test_term_paths_take_the_language_in_folders(): void
    {
        $this->site('/%postname%/');

        $this->assertSame('/ru/category/novosti/', $this->term(Wp::TERM_NOVOSTI, 'category', 'ru'));
        $this->assertSame('/category/novosti/', $this->term(Wp::TERM_NOVOSTI, 'category', 'en'));
    }

    public function test_a_term_tree_that_loops_back_does_not_loop_forever(): void
    {
        $this->site('/%postname%/', edit: function (Connection $db): void {
            $db->table('wp_term_taxonomy')->where('term_id', Wp::TERM_NEWS)->update(['parent' => Wp::TERM_LOCAL]);
        });

        $this->assertSame('/category/local/news/', $this->term(Wp::TERM_NEWS, 'category'));
        $this->assertSame('/category/news/local/', $this->term(Wp::TERM_LOCAL, 'category'));
    }

    public function test_a_term_parent_of_another_taxonomy_is_not_followed(): void
    {
        $this->site('/%postname%/', edit: function (Connection $db): void {
            $db->table('wp_term_taxonomy')->where('term_id', Wp::TERM_LARAVEL)->update(['parent' => Wp::TERM_NEWS]);
        });

        $this->assertSame('/tag/laravel/', $this->term(Wp::TERM_LARAVEL, 'post_tag'));
    }

    public function test_the_short_address_works_for_every_post(): void
    {
        $this->site('/%postname%/');

        $this->assertSame('/?p=10', Permalinks::shortLink($this->posts[Wp::HELLO]));
        $this->assertSame('/?page_id=30', Permalinks::shortLink($this->posts[Wp::ABOUT]));
        $this->assertSame('/?p=40', Permalinks::shortLink($this->posts[Wp::BORSCHT]));
    }

    /**
     * The rows as MySQL hands them over, every column text.
     *
     * @param  array<int,object>  $posts
     * @return array<int,object>
     */
    private function asText(array $posts): array
    {
        return array_map(static fn (object $post): object => (object) array_map(static fn (mixed $value): ?string => $value === null ? null : (string) $value, (array) $post), $posts);
    }

    public function test_a_site_without_a_permalink_setting_has_the_plain_addresses(): void
    {
        $this->site('/%postname%/', edit: function (Connection $db): void {
            $db->table('wp_options')->where('option_name', 'permalink_structure')->delete();
        });

        $this->assertSame('/?p=10', $this->path(Wp::HELLO));
    }

    public function test_rows_with_every_column_as_text_give_the_same_addresses(): void
    {
        $this->site('/%category%/%author%/%postname%/', remember: [], options: ['page_on_front' => '31']);
        $this->links->remember($this->asText(array_values($this->posts)));
        $posts = $this->asText($this->posts);

        $this->assertSame('/news/alice/hello-world/', $this->links->post($posts[Wp::HELLO], 'en'));
        $this->assertSame('/news/local/boris/gutenberg-post/', $this->links->post($posts[Wp::GUTENBERG], 'en'));
        $this->assertSame('/about/team/history/', $this->links->post($posts[Wp::HISTORY], 'en'));
        $this->assertSame('/recipe/borscht/', $this->links->post($posts[Wp::BORSCHT], 'en'));
        $this->assertNull($this->links->post($posts[Wp::ABOUT_RU], 'ru'));
    }

    public function test_the_front_page_is_found_whatever_type_its_number_has(): void
    {
        $this->site('/%postname%/', options: ['page_on_front' => '30']);

        $this->assertNull($this->links->post($this->asText($this->posts)[Wp::ABOUT], 'en'));
    }

    public function test_a_post_whose_name_is_null_has_the_plain_address(): void
    {
        $this->site('/%postname%/');

        $this->assertSame('/?p=10', $this->links->post((object) [...(array) $this->posts[Wp::HELLO], 'post_name' => null], 'en'));
    }

    public function test_of_several_categories_the_lowest_id_wins_whatever_the_order_they_come_in(): void
    {
        $this->site('/%category%/%postname%/', edit: function (Connection $db): void {
            // Uncategorized (term 1) gets the highest taxonomy ID, so it comes last.
            $db->table('wp_term_relationships')->where('term_taxonomy_id', Wp::TERM_UNCATEGORIZED + Wp::TT_OFFSET)->update(['term_taxonomy_id' => 500]);
            $db->table('wp_term_taxonomy')->where('term_id', Wp::TERM_UNCATEGORIZED)->update(['term_taxonomy_id' => 500]);
            $db->table('wp_term_relationships')->insert(['object_id' => Wp::HELLO, 'term_taxonomy_id' => 500, 'term_order' => 0]);
        });

        $this->assertSame('/uncategorized/hello-world/', $this->path(Wp::HELLO));
    }

    public function test_a_page_with_a_parent_without_a_name_does_not_leave_a_hole_in_the_address(): void
    {
        $this->site('/%postname%/', edit: function (Connection $db): void {
            $db->table('wp_posts')->where('ID', Wp::ABOUT)->update(['post_name' => '']);
        });

        $this->assertSame('/team/', $this->path(Wp::TEAM));
        $this->assertSame('/team/history/', $this->path(Wp::HISTORY));
    }
}
