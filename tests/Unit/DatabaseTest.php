<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Unit;

use Atrium\WordPress\Source\Database;
use Atrium\WordPress\Tests\Fixtures\SyntheticWordPress as Wp;
use Atrium\WordPress\Tests\LightTestCase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * What the importer reads out of a copy of the WordPress database.
 */
final class DatabaseTest extends LightTestCase
{
    private Database $source;

    private function wp(): Connection
    {
        return DB::connection('wordpress');
    }

    private function site(string $languages = 'wpml', string $prefix = 'wp_'): void
    {
        Wp::install($this->wp(), $prefix, $languages);
        // MySQL hands every number back as text unless told otherwise: the source has to make its own numbers.
        $this->wp()->getPdo()->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        $this->source = new Database($this->wp(), $prefix);
    }

    public function test_it_gives_back_its_connection(): void
    {
        $this->site();

        $this->assertSame($this->wp(), $this->source->connection());
    }

    public function test_an_option_is_its_value_or_null(): void
    {
        $this->site();

        $this->assertSame('Synthetic Blog', $this->source->option('blogname'));
        $this->assertSame('', $this->source->option('category_base'));
        $this->assertNull($this->source->option('no_such_option'));
    }

    public function test_an_option_is_read_once_missing_ones_included(): void
    {
        $this->site();

        $this->assertSame('Synthetic Blog', $this->source->option('blogname'));
        $this->assertNull($this->source->option('later'));
        $this->wp()->table('wp_options')->where('option_name', 'blogname')->update(['option_value' => 'Changed']);
        $this->wp()->table('wp_options')->insert(['option_name' => 'later', 'option_value' => 'there', 'autoload' => 'yes']);

        $this->assertSame('Synthetic Blog', $this->source->option('blogname'));
        $this->assertNull($this->source->option('later'));
        $this->assertSame('there', (new Database($this->wp()))->option('later'));
    }

    public function test_tables_are_found_by_the_prefix(): void
    {
        $this->site('wpml', 'blog7_');

        $this->assertSame('Synthetic Blog', $this->source->option('blogname'));
        $this->assertSame([Wp::HELLO, Wp::HELLO_RU, Wp::GUTENBERG, Wp::DRAFT_IDEA, Wp::HELLO_AGAIN, Wp::ENCODING, Wp::SCHEDULED, Wp::PRIVATE_NOTE, Wp::ORPHAN], $this->source->postIds('post'));
        $this->assertCount(31, (array) $this->source->wpmlTranslations());
        $this->assertSame([Wp::HELLO => [102, 105, 106]], array_intersect_key($this->source->relationships([Wp::HELLO]), [Wp::HELLO => 1]));
        $this->assertSame('Alice', $this->source->users([Wp::USER_ALICE])[Wp::USER_ALICE]->first_name);
        $this->assertSame([5 => ['rank_math_title' => 'Laravel posts %sep% %sitename%', 'rank_math_description' => 'Everything about Laravel.']], $this->source->termMeta());
        $this->assertContains('_thumbnail_id', $this->source->metaKeys('post'));
        $this->assertSame(9, $this->source->postTypeCounts()['post']);
        $this->assertCount(9, $this->source->terms());
        $this->assertSame([Wp::BORSCHT => 108, Wp::BORSCHT_RU => 108, Wp::PIZZA => 107], $this->source->objectsInTaxonomy('cuisine'));
    }

    public function test_posts_are_counted_by_type_without_trash_revisions_and_attachments(): void
    {
        $this->site();

        $this->assertSame(
            ['acf-field' => 11, 'acf-field-group' => 1, 'page' => 6, 'post' => 9, 'recipe' => 3],
            $this->source->postTypeCounts(),
        );
    }

    public function test_the_statuses_to_count_can_be_given(): void
    {
        $this->site();

        $this->assertSame(['page' => 1, 'post' => 2], $this->source->postTypeCounts(['draft']));
        $this->assertSame(['attachment' => 4, 'revision' => 1], $this->source->postTypeCounts(['inherit']));
        $this->assertSame(['post' => 2], $this->source->postTypeCounts(['trash', 'auto-draft']));
        $this->assertSame([], $this->source->postTypeCounts(['nothing']));
    }

    public function test_post_ids_are_in_order_for_the_type_and_statuses(): void
    {
        $this->site();

        $this->assertSame([10, 11, 12, 13, 14, 18, 20, 21, 22], $this->source->postIds('post'));
        $this->assertSame([30, 31, 32, 33, 34, 35], $this->source->postIds('page'));
        $this->assertSame([13, 14], $this->source->postIds('post', ['draft']));
        $this->assertSame([50, 51, 52, 53], $this->source->postIds('attachment', ['inherit']));
        $this->assertSame([15], $this->source->postIds('post', ['trash']));
        $this->assertSame([], $this->source->postIds('nothing'));
        $this->assertSame([], $this->source->postIds('attachment'));
    }

    public function test_the_parents_of_a_type_are_by_post_with_zero_for_none(): void
    {
        $this->site();

        $this->assertSame([30 => 0, 31 => 0, 32 => 30, 33 => 32, 34 => 999, 35 => 0], $this->source->parents('page'));
        $this->assertSame([], $this->source->parents('revision'));
        $this->assertSame(array_fill_keys([10, 11, 12, 13, 14, 18, 20, 21, 22], 0), $this->source->parents('post'));
    }

    public function test_the_meta_keys_of_a_type_are_listed_once_each(): void
    {
        $this->site();

        $keys = $this->source->metaKeys('post');
        $expected = [
            '_edit_last', '_edit_lock', '_thumbnail_id',
            '_yoast_wpseo_canonical', '_yoast_wpseo_focuskw', '_yoast_wpseo_metadesc', '_yoast_wpseo_opengraph-image', '_yoast_wpseo_title',
            'rank_math_description', 'rank_math_facebook_title', 'rank_math_focus_keyword', 'rank_math_robots', 'rank_math_title',
        ];

        $this->assertEqualsCanonicalizing($expected, $keys);
        $this->assertSame(count($expected), count($keys));
        $this->assertSame($keys, array_values(array_unique($keys)));
        $this->assertSame([], $this->source->metaKeys('nothing'));
    }

    public function test_the_meta_keys_leave_out_trashed_posts(): void
    {
        $this->site();
        $this->wp()->table('wp_postmeta')->insert(['post_id' => Wp::TRASHED, 'meta_key' => 'only_in_the_trash', 'meta_value' => '1']);
        $this->wp()->table('wp_postmeta')->insert(['post_id' => Wp::PRIVATE_NOTE, 'meta_key' => 'only_when_private', 'meta_value' => '1']);

        $keys = $this->source->metaKeys('post');

        $this->assertNotContains('only_in_the_trash', $keys);
        $this->assertContains('only_when_private', $keys);
    }

    public function test_posts_come_by_id(): void
    {
        $this->site();

        $posts = $this->source->posts([Wp::GUTENBERG, Wp::HELLO, 12345]);

        $this->assertEqualsCanonicalizing([10, 12], array_keys($posts));
        $this->assertSame('Hello World', $posts[10]->post_title);
        $this->assertSame('gutenberg-post', $posts[12]->post_name);
        $this->assertSame([], $this->source->posts([]));
    }

    public function test_ids_are_asked_for_five_hundred_at_a_time(): void
    {
        $this->site();
        $this->wp()->enableQueryLog();

        $this->source->posts(range(1, 500));
        $this->assertCount(1, $this->wp()->getQueryLog());

        $this->wp()->flushQueryLog();
        $posts = $this->source->posts(range(1, 1001));
        $this->assertCount(3, $this->wp()->getQueryLog());
        $this->assertContains(10, array_keys($posts));
        $this->assertContains(53, array_keys($posts));

        $this->wp()->flushQueryLog();
        $this->source->meta(range(1, 501));
        $this->assertCount(2, $this->wp()->getQueryLog());

        $this->wp()->flushQueryLog();
        $this->source->relationships(range(1, 500));
        $this->assertCount(1, $this->wp()->getQueryLog());

        $this->wp()->flushQueryLog();
        $this->source->relationships(range(1, 1000));
        $this->assertCount(2, $this->wp()->getQueryLog());

        $this->wp()->flushQueryLog();
        $this->source->users(range(1, 500));
        $this->assertCount(2, $this->wp()->getQueryLog());

        $this->wp()->flushQueryLog();
        $this->source->users(range(1, 501));
        $this->assertCount(4, $this->wp()->getQueryLog());
    }

    public function test_meta_is_by_post_and_key(): void
    {
        $this->site();

        $meta = $this->source->meta([Wp::PIZZA, Wp::TEAM, Wp::ORPHAN, 12345]);

        $this->assertSame(['cooking_time' => '20', '_cooking_time' => 'field_cooking_time', 'photo' => '52', '_photo' => 'field_photo', 'spicy' => '0', '_spicy' => 'field_spicy'], $meta[Wp::PIZZA]);
        $this->assertSame(['_thumbnail_id' => '999'], $meta[Wp::ORPHAN]);
        $this->assertArrayNotHasKey(Wp::TEAM, $meta);
        $this->assertArrayNotHasKey(12345, $meta);
        $this->assertSame([], $this->source->meta([]));
    }

    public function test_a_key_saved_twice_keeps_its_first_value(): void
    {
        $this->site();
        $this->wp()->table('wp_postmeta')->insert(['post_id' => Wp::PIZZA, 'meta_key' => 'cooking_time', 'meta_value' => '99']);
        $this->wp()->table('wp_postmeta')->insert(['post_id' => Wp::PIZZA, 'meta_key' => 'empty_one', 'meta_value' => null]);

        $meta = $this->source->meta([Wp::PIZZA])[Wp::PIZZA];

        $this->assertSame('20', $meta['cooking_time']);
        $this->assertSame('', $meta['empty_one']);
    }

    public function test_the_terms_come_with_their_names_slugs_and_taxonomies_in_order(): void
    {
        $this->site();

        $terms = $this->source->terms();

        $this->assertSame([101, 102, 103, 104, 105, 106, 107, 108, 109], array_map(static fn (object $term): int => (int) $term->term_taxonomy_id, $terms));
        $this->assertEquals((object) [
            'term_taxonomy_id' => 103,
            'term_id' => 3,
            'taxonomy' => 'category',
            'description' => '',
            'parent' => 2,
            'name' => 'Local',
            'slug' => 'local',
        ], $terms[2]);
        $this->assertSame('Новости', $terms[3]->name);
        $this->assertSame('cuisine', $terms[6]->taxonomy);
        $this->assertSame('nav_menu', $terms[8]->taxonomy);
    }

    public function test_the_terms_of_polylang_include_its_own(): void
    {
        $this->site('polylang');

        $taxonomies = array_unique(array_map(static fn (object $term): string => $term->taxonomy, $this->source->terms()));

        $this->assertContains('language', $taxonomies);
        $this->assertContains('term_translations', $taxonomies);
        $this->assertContains('post_translations', $taxonomies);
    }

    public function test_the_meta_of_terms_is_by_term_and_key(): void
    {
        $this->site();

        $this->assertSame([
            Wp::TERM_LARAVEL => ['rank_math_title' => 'Laravel posts %sep% %sitename%', 'rank_math_description' => 'Everything about Laravel.'],
        ], $this->source->termMeta());
    }

    public function test_a_key_of_a_term_saved_twice_keeps_its_first_value(): void
    {
        $this->site();
        $this->wp()->table('wp_termmeta')->insert(['term_id' => Wp::TERM_LARAVEL, 'meta_key' => 'rank_math_title', 'meta_value' => 'Second']);
        $this->wp()->table('wp_termmeta')->insert(['term_id' => Wp::TERM_PHP, 'meta_key' => 'empty_one', 'meta_value' => null]);

        $meta = $this->source->termMeta();

        $this->assertSame('Laravel posts %sep% %sitename%', $meta[Wp::TERM_LARAVEL]['rank_math_title']);
        $this->assertSame(['empty_one' => ''], $meta[Wp::TERM_PHP]);
    }

    public function test_a_site_without_a_term_meta_table_has_none(): void
    {
        $this->site();
        $this->wp()->getSchemaBuilder()->drop('wp_termmeta');

        $this->assertSame([], $this->source->termMeta());
    }

    public function test_relationships_list_the_terms_of_each_object_in_order(): void
    {
        $this->site();

        $this->assertEquals(
            [Wp::HELLO => [102, 105, 106], Wp::HELLO_RU => [104], Wp::DRAFT_IDEA => [101]],
            $this->source->relationships([Wp::DRAFT_IDEA, Wp::HELLO, Wp::HELLO_RU, Wp::TEAM, 12345]),
        );
        $this->assertSame([], $this->source->relationships([]));
    }

    public function test_the_term_order_comes_before_the_term_id(): void
    {
        $this->site();
        $this->wp()->table('wp_term_relationships')->where('object_id', Wp::HELLO)->where('term_taxonomy_id', 106)->update(['term_order' => -1]);
        $this->wp()->table('wp_term_relationships')->where('object_id', Wp::HELLO)->where('term_taxonomy_id', 102)->update(['term_order' => 1]);

        $this->assertSame([106, 105, 102], $this->source->relationships([Wp::HELLO])[Wp::HELLO]);
    }

    public function test_the_objects_in_a_taxonomy_are_by_object(): void
    {
        $this->site();

        $this->assertSame([Wp::BORSCHT => 108, Wp::BORSCHT_RU => 108, Wp::PIZZA => 107], $this->source->objectsInTaxonomy('cuisine'));
        $this->assertSame([], $this->source->objectsInTaxonomy('nothing'));
    }

    public function test_the_ids_of_a_copy_that_keeps_them_as_text_are_numbers_all_the_same(): void
    {
        $schema = $this->wp()->getSchemaBuilder();
        $schema->create('txt_term_relationships', function ($table): void {
            $table->text('object_id');
            $table->text('term_taxonomy_id');
        });
        $schema->create('txt_term_taxonomy', function ($table): void {
            $table->text('term_taxonomy_id');
            $table->text('taxonomy');
        });
        $this->wp()->table('txt_term_taxonomy')->insert(['term_taxonomy_id' => '108', 'taxonomy' => 'cuisine']);
        $this->wp()->table('txt_term_relationships')->insert(['object_id' => '40', 'term_taxonomy_id' => '108']);

        $this->assertSame([40 => 108], (new Database($this->wp(), 'txt_'))->objectsInTaxonomy('cuisine'));
    }

    public function test_a_copy_that_keeps_names_and_values_as_numbers_gives_them_back_as_text(): void
    {
        $schema = $this->wp()->getSchemaBuilder();
        $schema->create('num_options', function ($table): void {
            $table->integer('option_name');
            $table->integer('option_value');
        });
        $schema->create('num_postmeta', function ($table): void {
            $table->increments('meta_id');
            $table->integer('post_id');
            $table->integer('meta_key')->nullable();
            $table->integer('meta_value')->nullable();
        });
        $schema->create('num_posts', function ($table): void {
            $table->integer('ID');
            $table->string('post_type');
            $table->string('post_status');
        });
        $schema->create('num_termmeta', function ($table): void {
            $table->increments('meta_id');
            $table->integer('term_id');
            $table->integer('meta_key')->nullable();
            $table->integer('meta_value')->nullable();
        });
        $schema->create('num_users', function ($table): void {
            $table->integer('ID');
            $table->string('user_login');
            $table->string('user_nicename');
            $table->string('user_email');
            $table->string('user_url');
            $table->string('user_registered');
            $table->string('display_name');
        });
        $schema->create('num_usermeta', function ($table): void {
            $table->increments('umeta_id');
            $table->integer('user_id');
            $table->string('meta_key');
            $table->integer('meta_value')->nullable();
        });
        $this->wp()->table('num_options')->insert(['option_name' => 7, 'option_value' => 42]);
        $this->wp()->table('num_posts')->insert(['ID' => 5, 'post_type' => 'post', 'post_status' => 'publish']);
        $this->wp()->table('num_postmeta')->insert([
            ['post_id' => 5, 'meta_key' => 123, 'meta_value' => 456],
            ['post_id' => 5, 'meta_key' => null, 'meta_value' => null],
        ]);
        $this->wp()->table('num_termmeta')->insert([
            ['term_id' => 3, 'meta_key' => 123, 'meta_value' => 456],
            ['term_id' => 3, 'meta_key' => null, 'meta_value' => null],
        ]);
        $this->wp()->table('num_users')->insert(['ID' => 9, 'user_login' => 'u', 'user_nicename' => 'u', 'user_email' => '', 'user_url' => '', 'user_registered' => '2024-01-01 00:00:00', 'display_name' => 'U']);
        $this->wp()->table('num_usermeta')->insert([
            ['user_id' => 9, 'meta_key' => 'first_name', 'meta_value' => 2024],
            ['user_id' => 9, 'meta_key' => 'last_name', 'meta_value' => null],
        ]);
        $source = new Database($this->wp(), 'num_');

        $this->assertSame('42', $source->option('7'));
        $this->assertSame(['', '123'], $source->metaKeys('post'));
        $this->assertSame([5 => ['123' => '456', '' => '']], $source->meta([5]));
        $this->assertSame([3 => ['123' => '456', '' => '']], $source->termMeta());
        $this->assertSame('2024', $source->users([9])[9]->first_name);
        $this->assertSame('', $source->users([9])[9]->last_name);
    }

    public function test_users_have_the_name_parts_and_description_from_their_meta(): void
    {
        $this->site();

        $users = $this->source->users([Wp::USER_BORIS, Wp::USER_ALICE, Wp::USER_CAROL, Wp::USER_MISSING]);

        $this->assertEqualsCanonicalizing([1, 2, 3], array_keys($users));
        $this->assertEquals((object) [
            'ID' => 1,
            'user_login' => 'alice',
            'user_nicename' => 'alice',
            'user_email' => 'alice@example.test',
            'user_url' => 'https://alice.example.test',
            'user_registered' => '2023-01-10 09:00:00',
            'display_name' => 'Alice Writer',
            'first_name' => 'Alice',
            'last_name' => 'Writer',
            'description' => 'Writes about Laravel.',
        ], $users[1]);
        $this->assertSame(['', '', 'Пишет о кухне.'], [$users[2]->first_name, $users[2]->last_name, $users[2]->description]);
        $this->assertSame(['', '', ''], [$users[3]->first_name, $users[3]->last_name, $users[3]->description]);
        $this->assertObjectNotHasProperty('user_pass', $users[1]);
        $this->assertSame([], $this->source->users([]));
    }

    public function test_the_meta_of_a_user_who_is_not_there_is_ignored(): void
    {
        $this->site();
        $this->wp()->table('wp_usermeta')->insert(['user_id' => Wp::USER_MISSING, 'meta_key' => 'first_name', 'meta_value' => 'Ghost']);

        $this->assertArrayNotHasKey(Wp::USER_MISSING, $this->source->users([Wp::USER_MISSING, Wp::USER_ALICE]));
    }

    public function test_only_the_name_parts_and_description_are_taken_from_the_meta_of_users(): void
    {
        $this->site();

        $this->assertObjectNotHasProperty('wp_capabilities', $this->source->users([Wp::USER_ALICE])[Wp::USER_ALICE]);
    }

    public function test_wpml_translations_are_listed_in_the_order_they_were_made(): void
    {
        $this->site();

        $rows = $this->source->wpmlTranslations();

        $this->assertIsArray($rows);
        $this->assertCount(31, $rows);
        $this->assertEquals((object) ['element_type' => 'post_post', 'element_id' => 10, 'trid' => 1001, 'language_code' => 'en'], $rows[0]);
        $this->assertEquals((object) ['element_type' => 'post_post', 'element_id' => 11, 'trid' => 1001, 'language_code' => 'ru'], $rows[1]);
    }

    public function test_without_wpml_there_are_no_translations_to_list(): void
    {
        $this->site('polylang');
        $this->assertNull($this->source->wpmlTranslations());
    }

    public function test_a_database_cannot_tell_a_posts_permalink(): void
    {
        $this->site();

        $this->assertNull($this->source->permalink(Wp::HELLO));
    }
}
