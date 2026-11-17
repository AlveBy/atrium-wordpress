<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Unit;

use Atrium\News\Models\News;
use Atrium\Pages\Models\Page;
use Atrium\WordPress\Plan\Field;
use Atrium\WordPress\Plan\Plan;
use Atrium\WordPress\Plan\Planner;
use Atrium\WordPress\Plan\Type;
use Atrium\WordPress\Source\Database;
use Atrium\WordPress\Source\Source;
use Atrium\WordPress\Tests\Concerns\ImportsWordPress;
use Atrium\WordPress\Tests\Fixtures\SyntheticWordPress as Wp;
use Atrium\WordPress\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Where each post type and taxonomy of the made-up site goes, by default and
 * as the config says.
 */
final class PlannerTest extends TestCase
{
    use ImportsWordPress;

    private const NS = 'Atrium\\WordPress\\Tests\\Generated';

    protected function setUp(): void
    {
        parent::setUp();

        // MySQL hands every number back as text unless told otherwise: the plan has to make its own numbers.
        $this->wp()->getPdo()->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
    }

    /**
     * @param  array<string,mixed>  $config  atrium-wordpress.* settings
     */
    private function planned(array $config = [], string $languages = 'wpml'): Plan
    {
        $this->site($languages);

        foreach ($config as $key => $value) {
            config()->set('atrium-wordpress.'.$key, $value);
        }

        return $this->plan();
    }

    /**
     * @return array<int,string>
     */
    private function names(Type $type): array
    {
        return array_map(static fn (Field $field): string => $field->name, $type->fields);
    }

    public function test_the_made_up_site_goes_where_the_defaults_send_it(): void
    {
        $plan = $this->planned();

        $this->assertSame(['page', 'post', 'recipe'], array_keys($plan->types));

        $post = $plan->types['post'];
        $this->assertSame(Type::NEWS, $post->target);
        $this->assertSame('news', $post->table);
        $this->assertSame(News::class, $post->model);
        $this->assertSame('Posts', $post->label);
        $this->assertFalse($post->hierarchical);
        // Hello, its translation, Gutenberg, a draft, Hello again, the encoding one, scheduled, private and the orphan.
        $this->assertSame(9, $post->count);
        $this->assertSame(['category', 'post_tag'], $post->taxonomies);
        $this->assertNull($post->reason);
        $this->assertSame([], $post->fields);

        $page = $plan->types['page'];
        $this->assertSame(Type::PAGES, $page->target);
        $this->assertSame('pages', $page->table);
        $this->assertSame(Page::class, $page->model);
        $this->assertSame('', $page->prefix);
        $this->assertSame('Pages', $page->label);
        $this->assertTrue($page->hierarchical);
        $this->assertSame(6, $page->count);
        $this->assertSame([], $page->taxonomies);
        $this->assertNull($page->reason);

        $recipe = $plan->types['recipe'];
        $this->assertSame(Type::GENERATE, $recipe->target);
        $this->assertSame('recipes', $recipe->table);
        $this->assertSame(self::NS.'\\Recipe', $recipe->model);
        $this->assertSame('recipe', $recipe->prefix);
        $this->assertSame('Recipes', $recipe->label);
        $this->assertFalse($recipe->hierarchical);
        $this->assertSame(3, $recipe->count);
        $this->assertSame(['cuisine'], $recipe->taxonomies);
        $this->assertNull($recipe->reason);

        $this->assertSame('authors', $plan->authorsTable);
        $this->assertSame(self::NS.'\\Author', $plan->authorsModel);
    }

    public function test_bookkeeping_post_types_are_not_content(): void
    {
        $plan = $this->planned();

        foreach (['revision', 'attachment', 'acf-field', 'acf-field-group', 'nav_menu_item'] as $internal) {
            $this->assertArrayNotHasKey($internal, $plan->types);
        }
    }

    public function test_a_type_with_no_posts_in_a_moving_status_is_not_there(): void
    {
        $this->site();
        $this->wpPost(200, 'review', 'Trashed review', ['post_status' => 'trash']);
        $this->wpPost(201, 'review', 'Auto', ['post_status' => 'auto-draft']);
        $this->wpPost(202, 'wp_navigation', 'Menu');

        $this->assertArrayNotHasKey('review', $this->plan()->types);
        $this->assertArrayNotHasKey('wp_navigation', $this->plan()->types);
    }

    public function test_the_acf_fields_of_a_recipe_come_in_the_order_of_the_group(): void
    {
        $recipe = $this->planned()->types['recipe'];

        $this->assertSame([
            'cooking_time', 'difficulty', 'ingredients', 'spicy', 'served_on', 'photo', 'gallery', 'labels', 'steps', 'notes', 'source_url',
        ], $this->names($recipe));

        $time = $recipe->field('cooking_time');
        $this->assertSame(['cooking_time', 'Cooking time', 'number', [], false, []], [$time->column, $time->label, $time->kind, $time->choices, $time->multiple, $time->subFields]);

        $difficulty = $recipe->field('difficulty');
        $this->assertSame(['select', ['easy' => 'Easy', 'hard' => 'Hard'], false], [$difficulty->kind, $difficulty->choices, $difficulty->multiple]);

        $this->assertSame(['repeater', ['step_text']], [$recipe->field('steps')->kind, $recipe->field('steps')->subFields]);
        $this->assertSame(['vegan' => 'Vegan', 'quick' => 'Quick'], $recipe->field('labels')->choices);
    }

    public function test_a_meta_key_nobody_defined_is_a_plain_field(): void
    {
        $field = $this->planned()->types['recipe']->field('source_url');

        $this->assertSame(['source_url', 'source_url', 'Source Url', 'meta'], [$field->name, $field->column, $field->label, $field->kind]);
    }

    public function test_the_keys_of_a_repeater_row_and_the_seo_plugins_are_not_fields(): void
    {
        $names = $this->names($this->planned()->types['recipe']);

        $this->assertNotContains('steps_0_step_text', $names);
        $this->assertNotContains('steps_1_step_text', $names);
        $this->assertNotContains('rank_math_title', $names);
        $this->assertNotContains('_cooking_time', $names);
    }

    public function test_the_taxonomies_of_the_made_up_site(): void
    {
        $plan = $this->planned();

        $this->assertSame(['category', 'cuisine', 'post_tag'], array_keys($plan->taxonomies));

        $category = $plan->taxonomies['category'];
        $this->assertSame(['categories', self::NS.'\\Category', 'category', 'Categories', true, 4, ['post']], [
            $category->table, $category->model, $category->prefix, $category->label, $category->hierarchical, $category->count, $category->types,
        ]);

        $tag = $plan->taxonomies['post_tag'];
        $this->assertSame(['tags', self::NS.'\\Tag', 'tag', 'Tags', false, 2, ['post']], [
            $tag->table, $tag->model, $tag->prefix, $tag->label, $tag->hierarchical, $tag->count, $tag->types,
        ]);

        $cuisine = $plan->taxonomies['cuisine'];
        $this->assertSame(['cuisines', self::NS.'\\Cuisine', 'cuisine', 'Cuisines', false, 2, ['recipe']], [
            $cuisine->table, $cuisine->model, $cuisine->prefix, $cuisine->label, $cuisine->hierarchical, $cuisine->count, $cuisine->types,
        ]);
    }

    public function test_bookkeeping_taxonomies_are_not_content(): void
    {
        $plan = $this->planned([], 'polylang');

        foreach (['nav_menu', 'language', 'term_language', 'post_translations', 'term_translations'] as $internal) {
            $this->assertArrayNotHasKey($internal, $plan->taxonomies);
        }
    }

    public function test_the_category_and_tag_bases_name_the_prefixes(): void
    {
        $this->site();
        $this->option('category_base', '/topics/');
        $this->option('tag_base', 'labels');

        $plan = $this->plan();

        $this->assertSame('topics', $plan->taxonomies['category']->prefix);
        $this->assertSame('labels', $plan->taxonomies['post_tag']->prefix);
    }

    public function test_a_custom_taxonomy_is_slugged_and_pluralised(): void
    {
        $this->site();
        $this->wp()->table('wp_terms')->insert(['term_id' => 50, 'name' => 'Bread', 'slug' => 'bread', 'term_group' => 0]);
        $this->wp()->table('wp_term_taxonomy')->insert(['term_taxonomy_id' => 150, 'term_id' => 50, 'taxonomy' => 'food_kind', 'description' => '', 'parent' => 0, 'count' => 0]);

        $kind = $this->plan()->taxonomies['food_kind'];

        $this->assertSame(['food_kinds', self::NS.'\\FoodKind', 'food-kind', 'Food Kinds', false, 1, []], [
            $kind->table, $kind->model, $kind->prefix, $kind->label, $kind->hierarchical, $kind->count, $kind->types,
        ]);
    }

    public function test_a_taxonomy_with_a_child_is_hierarchical(): void
    {
        $this->site();
        $this->wp()->table('wp_term_taxonomy')->where('term_id', Wp::TERM_PHP)->update(['parent' => Wp::TERM_LARAVEL]);

        $plan = $this->plan();

        $this->assertTrue($plan->taxonomies['post_tag']->hierarchical);
        $this->assertFalse($plan->taxonomies['cuisine']->hierarchical);
    }

    public function test_config_chooses_a_taxonomys_table_model_prefix_and_label(): void
    {
        $plan = $this->planned(['taxonomies' => ['cuisine' => ['table' => 'kitchens', 'model' => 'Kitchen', 'prefix' => '/k/', 'label' => 'The kitchens']]]);

        $cuisine = $plan->taxonomies['cuisine'];

        $this->assertSame(['kitchens', self::NS.'\\Kitchen', 'k', 'The kitchens'], [$cuisine->table, $cuisine->model, $cuisine->prefix, $cuisine->label]);
    }

    public function test_config_chooses_a_types_table_model_prefix_and_label(): void
    {
        $plan = $this->planned(['post_types' => ['recipe' => ['table' => 'dishes', 'model' => 'Dish', 'prefix' => '/food/', 'label' => 'Tasty dishes']]]);

        $recipe = $plan->types['recipe'];

        $this->assertSame(['dishes', self::NS.'\\Dish', 'food', 'Tasty dishes', Type::GENERATE], [$recipe->table, $recipe->model, $recipe->prefix, $recipe->label, $recipe->target]);
    }

    public function test_a_type_given_as_a_table_name_only_gets_the_rest_from_its_name(): void
    {
        $plan = $this->planned(['post_types' => ['recipe' => ['table' => 'dishes']]]);

        $this->assertSame(['dishes', self::NS.'\\Dish', 'recipe'], [$plan->types['recipe']->table, $plan->types['recipe']->model, $plan->types['recipe']->prefix]);
    }

    public function test_a_type_can_be_skipped(): void
    {
        $plan = $this->planned(['post_types' => ['recipe' => 'skip']]);

        $recipe = $plan->types['recipe'];

        $this->assertSame(Type::SKIP, $recipe->target);
        $this->assertFalse($recipe->moves());
        $this->assertSame('skipped in the config', $recipe->reason);
        $this->assertSame(3, $recipe->count);
        $this->assertSame('Recipes', $recipe->label);
        $this->assertSame(['cuisine'], $recipe->taxonomies);
        $this->assertNotEmpty($recipe->fields);
        $this->assertSame(['page', 'post'], array_keys($plan->moving()));
        // Nothing moving uses the taxonomy any more.
        $this->assertSame([], $plan->taxonomies['cuisine']->types);
    }

    public function test_a_post_can_be_generated_instead_of_going_to_news(): void
    {
        $plan = $this->planned(['post_types' => ['post' => 'generate', 'page' => 'generate']]);

        $this->assertSame([Type::GENERATE, 'posts', self::NS.'\\Post', 'post'], [$plan->types['post']->target, $plan->types['post']->table, $plan->types['post']->model, $plan->types['post']->prefix]);
        $this->assertSame(Type::GENERATE, $plan->types['page']->target);
        // The pages table is the module's, so ours is named apart.
        $this->assertSame(['wordpress_pages', self::NS.'\\WordpressPage'], [$plan->types['page']->table, $plan->types['page']->model]);
        $this->assertTrue($plan->types['page']->hierarchical);
        $this->assertSame(['page', 'post', 'recipe'], array_keys($plan->generated()));
    }

    public function test_a_type_can_be_sent_to_a_module_that_cannot_hold_its_fields(): void
    {
        $plan = $this->planned(['post_types' => ['recipe' => 'news']]);

        $recipe = $plan->types['recipe'];

        $this->assertSame(Type::NEWS, $recipe->target);
        $this->assertSame('news', $recipe->table);
        $this->assertSame(News::class, $recipe->model);
        $this->assertFalse($recipe->hierarchical);
        $this->assertSame('lost: custom fields the news module has no place for: '.implode(', ', $this->names($recipe)), $recipe->reason);
    }

    public function test_pages_may_take_a_type_too(): void
    {
        $plan = $this->planned(['post_types' => ['recipe' => 'pages']]);

        $recipe = $plan->types['recipe'];

        $this->assertSame([Type::PAGES, 'pages', Page::class, '', true], [$recipe->target, $recipe->table, $recipe->model, $recipe->prefix, $recipe->hierarchical]);
        $this->assertStringStartsWith('lost: custom fields the pages module has no place for: cooking_time', (string) $recipe->reason);
    }

    public function test_a_type_without_fields_has_nothing_lost_in_a_module(): void
    {
        $plan = $this->planned(['post_types' => ['page' => 'pages', 'post' => 'news']]);

        $this->assertNull($plan->types['post']->reason);
        $this->assertNull($plan->types['page']->reason);
    }

    public function test_a_post_with_custom_fields_leaves_the_news_module(): void
    {
        $this->site();
        $this->wpMeta(Wp::HELLO, ['subtitle' => 'A line under the title', 'mood' => 'good']);

        $post = $this->plan()->types['post'];

        $this->assertSame(Type::GENERATE, $post->target);
        $this->assertSame(['posts', self::NS.'\\Post'], [$post->table, $post->model]);
        // Meta keys come in alphabetical order.
        $this->assertSame('custom fields the news module has no place for: mood, subtitle', $post->reason);
        $this->assertSame(['mood', 'subtitle'], $this->names($post));
    }

    public function test_a_page_with_custom_fields_leaves_the_pages_module(): void
    {
        $this->site();
        $this->wpMeta(Wp::ABOUT, ['hero_line' => 'Hi']);

        $page = $this->plan()->types['page'];

        $this->assertSame(Type::GENERATE, $page->target);
        $this->assertSame('custom fields the pages module has no place for: hero_line', $page->reason);
        $this->assertSame('wordpress_pages', $page->table);
    }

    public function test_a_module_that_is_not_there_is_not_a_target(): void
    {
        $this->site();

        // The default connection of this test knows none of Atrium's tables.
        config()->set('database.default', 'wordpress');
        Schema::clearResolvedInstance('db.schema');

        $plan = $this->plan();

        $this->assertSame([Type::GENERATE, 'the news module is not installed', 'posts'], [$plan->types['post']->target, $plan->types['post']->reason, $plan->types['post']->table]);
        $this->assertSame([Type::GENERATE, 'the pages module is not installed', 'pages'], [$plan->types['page']->target, $plan->types['page']->reason, $plan->types['page']->table]);
    }

    public function test_a_module_asked_for_by_name_that_is_not_there_is_not_a_target_either(): void
    {
        $this->site();
        config()->set('atrium-wordpress.post_types', ['recipe' => 'news', 'page' => 'pages']);
        config()->set('database.default', 'wordpress');
        Schema::clearResolvedInstance('db.schema');

        $plan = $this->plan();

        $this->assertSame([Type::GENERATE, 'the news module is not installed'], [$plan->types['recipe']->target, $plan->types['recipe']->reason]);
        $this->assertSame([Type::GENERATE, 'the pages module is not installed'], [$plan->types['page']->target, $plan->types['page']->reason]);
    }

    public function test_the_news_prefix_is_the_one_the_public_url_config_says(): void
    {
        config()->set('atrium.public_urls.'.News::class.'.prefix', 'blog');

        $this->assertSame('blog', $this->planned()->types['post']->prefix);
    }

    public function test_the_default_news_prefix(): void
    {
        config()->set('atrium.public_urls.'.News::class, []);

        $this->assertSame('news', $this->planned()->types['post']->prefix);
    }

    public function test_the_model_namespace_is_trimmed(): void
    {
        $plan = $this->planned(['model_namespace' => '\\App\\Other\\']);

        $this->assertSame('App\\Other\\Recipe', $plan->types['recipe']->model);
        $this->assertSame('App\\Other\\Category', $plan->taxonomies['category']->model);
        $this->assertSame('App\\Other\\Author', $plan->authorsModel);
    }

    public function test_two_types_asking_for_one_table_get_their_own(): void
    {
        $plan = $this->planned(['post_types' => ['post' => 'generate', 'recipe' => ['table' => 'posts', 'model' => 'Post']]]);

        $this->assertSame(['posts', self::NS.'\\Post'], [$plan->types['post']->table, $plan->types['post']->model]);
        $this->assertSame(['wordpress_posts', self::NS.'\\Post2'], [$plan->types['recipe']->table, $plan->types['recipe']->model]);
    }

    public function test_a_third_asking_for_the_table_is_numbered(): void
    {
        $this->site();
        $this->wpPost(300, 'zine', 'A zine');
        config()->set('atrium-wordpress.post_types', ['post' => 'generate', 'recipe' => ['table' => 'posts', 'model' => 'Post'], 'zine' => ['table' => 'posts', 'model' => 'Post']]);

        $plan = $this->plan();

        $this->assertSame(['posts', 'wordpress_posts', 'wordpress_posts_2'], [$plan->types['post']->table, $plan->types['recipe']->table, $plan->types['zine']->table]);
        $this->assertSame([self::NS.'\\Post', self::NS.'\\Post2', self::NS.'\\Post3'], [$plan->types['post']->model, $plan->types['recipe']->model, $plan->types['zine']->model]);
    }

    public function test_a_table_of_the_app_is_not_taken_over(): void
    {
        $plan = $this->planned(['post_types' => ['recipe' => ['table' => 'news']], 'taxonomies' => ['cuisine' => ['table' => 'pages']]]);

        $this->assertSame('wordpress_news', $plan->types['recipe']->table);
        $this->assertSame('wordpress_pages', $plan->taxonomies['cuisine']->table);
    }

    public function test_a_table_made_by_an_earlier_run_is_reused(): void
    {
        $this->site();
        $this->generateAndMigrate();

        $plan = $this->plan();

        $this->assertSame('recipes', $plan->types['recipe']->table);
        $this->assertSame('categories', $plan->taxonomies['category']->table);
        $this->assertSame('authors', $plan->authorsTable);
    }

    public function test_the_authors_table_gives_way_to_a_taxonomy_that_wants_it(): void
    {
        $plan = $this->planned(['taxonomies' => ['cuisine' => ['table' => 'authors', 'model' => 'Author']]]);

        $this->assertSame(['authors', self::NS.'\\Author'], [$plan->taxonomies['cuisine']->table, $plan->taxonomies['cuisine']->model]);
        $this->assertSame(['wordpress_authors', self::NS.'\\Author2'], [$plan->authorsTable, $plan->authorsModel]);
    }

    public function test_a_planner_forgets_what_it_handed_out_when_it_plans_again(): void
    {
        $this->site();
        $planner = new Planner;
        $source = new Database($this->wp(), 'wp_');

        $first = $planner->build($source);
        $second = $planner->build($source);

        $this->assertSame($first->types['recipe']->table, $second->types['recipe']->table);
        $this->assertSame($first->types['recipe']->model, $second->types['recipe']->model);
        $this->assertSame($first->authorsModel, $second->authorsModel);
    }

    public function test_a_generated_type_is_a_tree_when_one_of_its_posts_has_a_parent_that_is_there(): void
    {
        $this->site();
        config()->set('atrium-wordpress.post_types', ['page' => 'generate']);

        $this->assertTrue($this->plan()->types['page']->hierarchical);
    }

    public function test_a_parent_that_is_not_there_makes_no_tree(): void
    {
        $this->site();
        config()->set('atrium-wordpress.post_types', ['page' => 'generate']);
        $this->wp()->table('wp_posts')->whereIn('ID', [Wp::TEAM, Wp::HISTORY])->update(['post_parent' => 0]);

        // Lost child's parent (999) is not a page: no tree.
        $this->assertFalse($this->plan()->types['page']->hierarchical);
    }

    public function test_a_post_whose_parent_is_a_post_of_another_type_is_no_tree(): void
    {
        $this->site();
        config()->set('atrium-wordpress.post_types', ['page' => 'generate']);
        $this->wp()->table('wp_posts')->whereIn('ID', [Wp::TEAM, Wp::HISTORY])->update(['post_parent' => Wp::HELLO]);

        $this->assertFalse($this->plan()->types['page']->hierarchical);
    }

    public function test_a_post_type_called_post_tag_is_labelled_tags(): void
    {
        $this->site();
        $this->wpPost(310, 'post_tag', 'Odd one');
        $this->wpPost(311, 'book', 'A book');

        $types = $this->plan()->types;

        $this->assertSame('Tags', $types['post_tag']->label);
        $this->assertSame('Books', $types['book']->label);
    }

    public function test_the_site_address_and_seo_plugins(): void
    {
        $this->site();

        $plan = $this->plan();

        $this->assertSame(Wp::OLD_URL, $plan->home());
        $this->assertSame(['yoast', 'rankmath'], $plan->seoPlugins());
    }

    /**
     * The made-up site as a source whose titles and excerpts that look like numbers come back as numbers, as a column
     * of a number type would give them.
     */
    private function numbering(): Source
    {
        $real = new Database($this->wp(), 'wp_');
        $source = Mockery::mock(Source::class);

        foreach (['option', 'postTypeCounts', 'postIds', 'parents', 'metaKeys', 'meta', 'terms', 'termMeta', 'relationships', 'objectsInTaxonomy', 'users', 'wpmlTranslations', 'permalink'] as $method) {
            $source->shouldReceive($method)->andReturnUsing(static fn (mixed ...$arguments): mixed => $real->{$method}(...$arguments));
        }

        $source->shouldReceive('posts')->andReturnUsing(static function (array $ids) use ($real): array {
            $posts = $real->posts($ids);

            foreach ($posts as $post) {
                foreach (['post_title', 'post_excerpt'] as $column) {
                    $post->{$column} = is_numeric($post->{$column}) ? (int) $post->{$column} : $post->{$column};
                }
            }

            return $posts;
        });

        return $source;
    }

    public function test_the_names_and_labels_of_fields_are_text_even_when_the_database_gives_numbers(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->acfGroup(400, ['book'], [
            ['name' => '2024', 'label' => '2025'],
            ['name' => 'rows', 'settings' => ['type' => 'repeater']],
            ['name' => '1999', 'parent' => 1],
        ]);

        $book = (new Planner)->build($this->numbering())->types['book'];

        $this->assertSame(['2024', 'rows'], $this->names($book));
        $this->assertSame(['2025', 'Rows'], array_map(static fn (Field $field): string => $field->label, $book->fields));
        $this->assertSame(['field_2024', 'rows'], array_map(static fn (Field $field): string => $field->column, $book->fields));
        $this->assertSame(['1999'], $book->field('rows')->subFields);
    }

    public function test_a_label_and_a_news_prefix_given_as_numbers_are_text(): void
    {
        config()->set('atrium.public_urls.'.News::class.'.prefix', 7);

        $plan = $this->planned(['post_types' => ['recipe' => ['label' => 42]]]);

        $this->assertSame('42', $plan->types['recipe']->label);
        $this->assertSame('7', $plan->types['post']->prefix);
    }

    public function test_a_taxonomys_model_is_the_configured_one_not_the_one_its_table_gives(): void
    {
        $plan = $this->planned(['taxonomies' => ['cuisine' => ['table' => 'kitchens', 'model' => 'Pantry']]]);

        $this->assertSame(['kitchens', self::NS.'\\Pantry'], [$plan->taxonomies['cuisine']->table, $plan->taxonomies['cuisine']->model]);
    }

    public function test_the_taxonomies_of_a_type_are_in_alphabetical_order_whatever_the_order_of_its_links(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->wp()->table('wp_term_relationships')->insert([
            ['object_id' => 450, 'term_taxonomy_id' => 108, 'term_order' => 0],
            ['object_id' => 450, 'term_taxonomy_id' => 107, 'term_order' => 0],
            ['object_id' => 450, 'term_taxonomy_id' => 105, 'term_order' => 1],
            ['object_id' => 450, 'term_taxonomy_id' => 102, 'term_order' => 2],
        ]);

        $this->assertSame(['category', 'cuisine', 'post_tag'], $this->plan()->types['book']->taxonomies);
    }

    public function test_a_post_that_has_the_id_zero_is_no_parent_of_the_posts_without_one(): void
    {
        $this->site();
        config()->set('atrium-wordpress.post_types', ['book' => 'generate']);
        $this->wpPost(0, 'book', 'Odd one');
        $this->wpPost(450, 'book', 'A book');

        $this->assertFalse($this->plan()->types['book']->hierarchical);
    }

    public function test_the_choices_of_a_field_that_are_one_text_are_one_choice(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->acfGroup(400, ['book'], [['name' => 'size', 'settings' => ['type' => 'select', 'choices' => 'large']]]);

        $this->assertSame(['0' => 'large'], $this->plan()->types['book']->field('size')->choices);
    }

    public function test_a_meta_key_that_starts_like_a_field_without_parts_is_a_field_of_its_own(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->wpMeta(450, ['hero_title' => 'x']);
        $this->acfGroup(400, ['book'], [['name' => 'hero', 'settings' => ['type' => 'text']]]);

        $this->assertSame(['hero', 'hero_title'], $this->names($this->plan()->types['book']));
    }

    public function test_a_meta_column_does_not_take_the_column_of_a_defined_field(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->wpMeta(450, ['colour-x' => 'red']);
        $this->acfGroup(400, ['book'], [['name' => 'colour_x']]);

        $book = $this->plan()->types['book'];

        $this->assertSame(['colour_x', 'colour-x'], $this->names($book));
        $this->assertSame(['colour_x', 'colour_x_2'], array_map(static fn (Field $field): string => $field->column, $book->fields));
    }

    public function test_skip_meta_may_be_one_pattern(): void
    {
        $this->site();
        $this->wpMeta(Wp::PIZZA, ['my_own' => 'x', 'other' => 'y']);
        config()->set('atrium-wordpress.skip_meta', 'my_*');

        $names = $this->names($this->plan()->types['recipe']);

        $this->assertNotContains('my_own', $names);
        $this->assertContains('other', $names);
    }

    public function test_a_key_is_a_repeater_row_only_when_the_repeater_starts_it(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->wpMeta(450, ['steps' => '1', "a\nsteps_0_b" => 'x']);

        $this->assertSame(["a\nsteps_0_b", 'steps'], $this->names($this->plan()->types['book']));
    }

    public function test_the_pages_module_is_not_the_news_module(): void
    {
        $this->site();
        Schema::drop('pages');

        $plan = $this->plan();

        $this->assertSame([Type::NEWS, null], [$plan->types['post']->target, $plan->types['post']->reason]);
        $this->assertSame([Type::GENERATE, 'the pages module is not installed'], [$plan->types['page']->target, $plan->types['page']->reason]);
    }

    public function test_the_news_module_is_not_the_pages_module(): void
    {
        $this->site();
        Schema::drop('news');

        $plan = $this->plan();

        $this->assertSame([Type::PAGES, null], [$plan->types['page']->target, $plan->types['page']->reason]);
        $this->assertSame([Type::GENERATE, 'the news module is not installed'], [$plan->types['post']->target, $plan->types['post']->reason]);
    }

    // ACF.

    public function test_acf_fields_are_found_for_every_type_a_group_is_shown_on(): void
    {
        $this->site();
        $this->acfGroup(400, ['book', 'recipe'], [['name' => 'isbn', 'label' => 'ISBN']]);
        $this->wpPost(450, 'book', 'A book');

        $plan = $this->plan();

        $this->assertSame(['isbn'], $this->names($plan->types['book']));
        $this->assertContains('isbn', $this->names($plan->types['recipe']));
    }

    public function test_a_group_shown_twice_on_a_type_gives_its_fields_once(): void
    {
        $this->site();
        $this->acfGroup(400, ['book', 'book'], [['name' => 'isbn']]);
        $this->wpPost(450, 'book', 'A book');

        $this->assertSame(['isbn'], $this->names($this->plan()->types['book']));
    }

    public function test_acf_rules_that_are_not_post_type_equals_are_ignored(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->acfGroup(400, ['book'], [['name' => 'isbn']], param: 'page_template');
        $this->acfGroup(410, ['book'], [['name' => 'pages_count']], operator: '!=');

        $this->assertSame([], $this->names($this->plan()->types['book']));
    }

    public function test_a_group_that_is_not_published_is_ignored(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->acfGroup(400, ['book'], [['name' => 'isbn']], status: 'draft');

        $this->assertSame([], $this->names($this->plan()->types['book']));
    }

    public function test_a_field_that_is_not_published_is_ignored(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->acfGroup(400, ['book'], [['name' => 'isbn'], ['name' => 'draft_field', 'status' => 'draft'], ['name' => 'pages_count']]);

        $this->assertSame(['isbn', 'pages_count'], $this->names($this->plan()->types['book']));
    }

    public function test_fields_are_ordered_by_menu_order_then_id(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->acfGroup(400, ['book'], [
            ['name' => 'last', 'order' => 5],
            ['name' => 'second_a', 'order' => 2],
            ['name' => 'second_b', 'order' => 2],
            ['name' => 'first', 'order' => 0],
        ]);

        $this->assertSame(['first', 'second_a', 'second_b', 'last'], $this->names($this->plan()->types['book']));
    }

    public function test_an_acf_field_takes_its_label_and_type_from_its_settings(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->acfGroup(400, ['book'], [
            ['name' => 'author_note', 'label' => 'Note on the author', 'settings' => ['type' => 'wysiwyg']],
            ['name' => 'subtitle_line'],
            ['name' => 'no_type', 'settings' => []],
            ['name' => 'tags', 'settings' => ['type' => 'select', 'multiple' => 1, 'choices' => [1 => 'One', 'b' => 2]]],
        ]);

        $book = $this->plan()->types['book'];

        $this->assertSame(['Note on the author', 'wysiwyg'], [$book->field('author_note')->label, $book->field('author_note')->kind]);
        $this->assertSame('Subtitle Line', $book->field('subtitle_line')->label);
        $this->assertSame('text', $book->field('no_type')->kind);
        $this->assertSame('select', $book->field('tags')->kind);
        $this->assertTrue($book->field('tags')->multiple);
        $this->assertSame(['1' => 'One', 'b' => '2'], $book->field('tags')->choices);
    }

    public function test_a_group_of_two_places_for_one_type_numbers_the_columns(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->acfGroup(400, ['book'], [['name' => 'color']]);
        $this->acfGroup(420, ['book'], [['name' => 'color'], ['name' => 'color']]);

        $book = $this->plan()->types['book'];

        $this->assertSame(['color', 'color', 'color'], $this->names($book));
        $this->assertSame(['color', 'color_2', 'color_3'], array_map(static fn (Field $field): string => $field->column, $book->fields));
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function columnNames(): array
    {
        return [
            'plain' => ['cooking_time', 'cooking_time'],
            'camel case' => ['cookingTime', 'cooking_time'],
            'dashes and spaces' => ['cooking-time x', 'cooking_time_x'],
            'edges trimmed' => ['--time--', 'time'],
            'upper case' => ['COLOR', 'c_o_l_o_r'],
            'cyrillic' => ['Цена', 'cena'],
            'digits first' => ['2nd', 'field_2nd'],
            'only digits' => ['404', 'field_404'],
            'reserved: id' => ['id', 'field_id'],
            'reserved: name' => ['name', 'field_name'],
            'reserved: created_at' => ['created_at', 'field_created_at'],
            'reserved: wordpress_id' => ['wordpress_id', 'field_wordpress_id'],
            'only punctuation' => ['---', 'field'],
            'cut at 56' => [str_repeat('a', 70), str_repeat('a', 56)],
            'kept at 56' => [str_repeat('b', 56), str_repeat('b', 56)],
        ];
    }

    #[DataProvider('columnNames')]
    public function test_a_column_is_a_safe_name(string $name, string $column): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->acfGroup(400, ['book'], [['name' => $name]]);

        $this->assertSame($column, $this->plan()->types['book']->fields[0]->column);
    }

    public function test_a_field_group_without_location_rules_is_unused(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->wpPost(400, 'acf-field-group', 'Empty', ['post_content' => serialize(['location' => 'nonsense'])]);
        $this->wpPost(401, 'acf-field-group', 'Odd', ['post_content' => serialize(['location' => ['nonsense', [['param' => 'post_type']]]])]);
        $this->wpPost(402, 'acf-field', 'X', ['post_excerpt' => 'x', 'post_parent' => 400, 'post_content' => serialize(['type' => 'text'])]);

        $this->assertSame([], $this->names($this->plan()->types['book']));
    }

    public function test_without_any_group_all_meta_is_plain(): void
    {
        $this->site();
        $this->wp()->table('wp_posts')->whereIn('post_type', ['acf-field', 'acf-field-group'])->delete();

        $names = $this->names($this->plan()->types['recipe']);

        $this->assertContains('cooking_time', $names);
        $this->assertContains('steps', $names);
        $this->assertNotContains('_cooking_time', $names);
    }

    // Plain meta.

    public function test_a_plain_meta_key_with_an_acf_marker_is_text(): void
    {
        $this->site();
        $this->wp()->table('wp_posts')->whereIn('post_type', ['acf-field', 'acf-field-group'])->delete();

        $recipe = $this->plan()->types['recipe'];

        $this->assertSame('text', $recipe->field('cooking_time')->kind);
        $this->assertSame('meta', $recipe->field('source_url')->kind);
        $this->assertSame('Cooking Time', $recipe->field('cooking_time')->label);
    }

    public function test_keys_in_skip_meta_are_not_fields(): void
    {
        $this->site();
        $this->wpMeta(Wp::PIZZA, ['ampforwp_thing' => '1', 'footnotes' => '[]', 'my_own' => 'x']);

        $names = $this->names($this->plan()->types['recipe']);

        $this->assertContains('my_own', $names);
        $this->assertNotContains('ampforwp_thing', $names);
        $this->assertNotContains('footnotes', $names);
    }

    public function test_skip_meta_is_the_configs(): void
    {
        $this->site();
        $this->wpMeta(Wp::PIZZA, ['my_own' => 'x', 'other' => 'y']);
        config()->set('atrium-wordpress.skip_meta', ['my_*']);

        $names = $this->names($this->plan()->types['recipe']);

        $this->assertNotContains('my_own', $names);
        $this->assertContains('other', $names);
    }

    public function test_the_row_keys_of_a_repeater_without_a_definition_are_not_fields(): void
    {
        $this->site();
        $this->wp()->table('wp_posts')->whereIn('post_type', ['acf-field', 'acf-field-group'])->delete();
        $this->wpMeta(Wp::PIZZA, ['toppings' => '2', 'toppings_0_name' => 'basil', 'toppings_1_name' => 'ham', 'loose_5_thing' => 'x']);

        $names = $this->names($this->plan()->types['recipe']);

        $this->assertContains('toppings', $names);
        $this->assertNotContains('toppings_0_name', $names);
        $this->assertNotContains('toppings_1_name', $names);
        // "loose" is not a key itself: this is a field that happens to look like a row.
        $this->assertContains('loose_5_thing', $names);
    }

    public function test_the_parts_of_a_group_are_not_fields(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->wpMeta(450, ['hero_title' => 'x', 'hero_image' => 'y', 'heroic' => 'z']);
        $this->acfGroup(400, ['book'], [['name' => 'hero', 'settings' => ['type' => 'group'], 'order' => 0], ['name' => 'sub_title', 'parent' => 0]]);

        $names = $this->names($this->plan()->types['book']);

        $this->assertSame(['hero', 'heroic'], $names);
    }

    public function test_a_plain_meta_column_does_not_clash_with_a_defined_field(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->wpMeta(450, ['Color' => 'red', 'color' => 'blue', 'name' => 'n']);
        $this->acfGroup(400, ['book'], [['name' => 'colour_x']]);

        $book = $this->plan()->types['book'];

        $this->assertSame(['color', 'field_name'], array_values(array_filter(
            array_map(static fn (Field $field): string => $field->column, $book->fields),
            static fn (string $column): bool => in_array($column, ['color', 'field_name'], true),
        )));
    }

    public function test_underscore_and_empty_meta_keys_are_not_fields(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->wpMeta(450, ['_hidden' => '1', '' => 'x', 'visible' => 'y']);

        $this->assertSame(['visible'], $this->names($this->plan()->types['book']));
    }

    public function test_two_plain_keys_that_make_one_column_are_told_apart(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->wpMeta(450, ['a-b' => '1', 'a_b' => '2']);

        $book = $this->plan()->types['book'];

        $this->assertSame(['a-b', 'a_b'], $this->names($book));
        $this->assertSame(['a_b', 'a_b_2'], array_map(static fn (Field $field): string => $field->column, $book->fields));
    }

    public function test_an_acf_field_known_by_name_is_not_added_again_as_plain_meta(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'A book');
        $this->wpMeta(450, ['isbn' => '1', '_isbn' => 'field_x']);
        $this->acfGroup(400, ['book'], [['name' => 'isbn']]);

        $this->assertSame(['isbn'], $this->names($this->plan()->types['book']));
    }
}
