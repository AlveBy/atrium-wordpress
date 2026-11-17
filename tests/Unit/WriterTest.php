<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Unit;

use Atrium\WordPress\Generator\Writer;
use Atrium\WordPress\Plan\Field;
use Atrium\WordPress\Plan\Plan;
use Atrium\WordPress\Plan\Taxonomy;
use Atrium\WordPress\Plan\Type;
use Atrium\WordPress\Site\Languages;
use Atrium\WordPress\Tests\Fixtures\ArraySource;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The files the writer makes for a plan built by hand: a recipe type with
 * every kind of field, a tree of dishes, a skipped book, the News module's
 * posts, two taxonomies and the authors. The expected files are in
 * tests/Fixtures/Writer.
 */
final class WriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atrium-writer-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/Writer/'.$name.'.txt');
    }

    /**
     * @return array<int,Field>
     */
    private function recipeFields(): array
    {
        return [
            new Field('summary', 'summary', 'Summary', 'textarea'),
            new Field('notes', 'notes', 'Notes', 'wysiwyg'),
            new Field('spicy', 'spicy', 'Spicy', 'true_false'),
            new Field('cooking_time', 'cooking_time', 'Time', 'number'),
            new Field('served_on', 'served_on', 'Served on', 'date_picker'),
            new Field('opens_at', 'opens_at', 'Opens at', 'date_time_picker'),
            new Field('photo', 'photo', 'Photo', 'image'),
            new Field('manual', 'manual', 'Manual', 'file'),
            new Field('gallery', 'gallery', 'Gallery', 'gallery'),
            new Field('labels', 'labels', 'Labels', 'checkbox', ['vegan' => 'Vegan', 'quick' => "Q'uick"]),
            new Field('level', 'level', 'Level', 'select', ['easy' => 'Easy']),
            new Field('tags_multi', 'tags_multi', 'Tags', 'select', ['a' => 'A'], true),
            new Field('mood', 'mood', 'Mood', 'radio', ['good' => 'Good']),
            new Field('align', 'align', 'Align', 'button_group', ['left' => 'Left', 'right' => 'Right']),
            new Field('steps', 'steps', 'Steps', 'repeater', [], false, ['text']),
            new Field('headline', 'headline', "Head'line", 'text'),
            new Field('contact', 'contact', 'Contact', 'email'),
            new Field('video', 'video', 'Video', 'oembed'),
            new Field('serves', 'serves', 'Serves', 'range'),
            new Field('extra', 'extra', 'Extra', 'meta'),
        ];
    }

    private function plan(): Plan
    {
        $source = new ArraySource;

        return new Plan(
            $source,
            new Languages($source),
            [
                'post' => new Type('post', Type::NEWS, 'news', 'Atrium\\News\\Models\\News', 'news', 'Posts', false, 5, [], ['category']),
                'recipe' => new Type('recipe', Type::GENERATE, 'recipes', 'App\\Models\\Recipe', 'recipe', 'Recipes', false, 2, $this->recipeFields(), ['ghost', 'cuisine']),
                'dish' => new Type('dish', Type::GENERATE, 'dishes', 'App\\Models\\Dish', 'dish', 'Dishes', true, 1),
                'book' => new Type('book', Type::SKIP, 'books', 'App\\Models\\Book', 'book', 'Books', false, 1, [], ['cuisine'], 'skipped in the config'),
            ],
            [
                'cuisine' => new Taxonomy('cuisine', 'cuisines', 'App\\Models\\Cuisine', 'cuisine', 'Cuisines', false, 2, ['recipe', 'book', 'nothing']),
                'category' => new Taxonomy('category', 'categories', 'App\\Models\\Category', 'category', 'Categories', true, 3, ['post']),
            ],
            'authors',
            'App\\Models\\Author',
        );
    }

    private function writer(bool $force = false): Writer
    {
        return new Writer($this->dir.'/models', $this->dir.'/migrations', $force);
    }

    private function migrationFile(string $table): string
    {
        $files = glob($this->dir.'/migrations/*_create_'.$table.'_table.php') ?: [];
        $this->assertCount(1, $files, "migration of {$table}");

        return $files[0];
    }

    /**
     * @return array<string,string> the files this writer made, by name
     */
    private function written(): array
    {
        $files = [];

        foreach ([...(glob($this->dir.'/migrations/*.php') ?: []), ...(glob($this->dir.'/models/*.php') ?: [])] as $file) {
            $files[basename($file)] = (string) file_get_contents($file);
        }

        return $files;
    }

    public function test_every_migration_and_model_is_what_the_plan_says(): void
    {
        $this->writer()->write($this->plan());

        $this->assertSame($this->fixture('recipes.migration'), file_get_contents($this->migrationFile('recipes')));
        $this->assertSame($this->fixture('dishes.migration'), file_get_contents($this->migrationFile('dishes')));
        $this->assertSame($this->fixture('cuisines.migration'), file_get_contents($this->migrationFile('cuisines')));
        $this->assertSame($this->fixture('categories.migration'), file_get_contents($this->migrationFile('categories')));
        $this->assertSame($this->fixture('authors.migration'), file_get_contents($this->migrationFile('authors')));

        foreach (['Recipe', 'Dish', 'Cuisine', 'Category', 'Author'] as $model) {
            $this->assertSame($this->fixture($model.'.model'), file_get_contents($this->dir.'/models/'.$model.'.php'), $model);
        }
    }

    public function test_the_tables_linking_records_to_terms_and_authors_are_written(): void
    {
        $this->writer()->write($this->plan());

        $this->assertSame($this->fixture('news_categories.migration'), file_get_contents($this->migrationFile('news_categories')));
        $this->assertSame($this->fixture('news_authors.migration'), file_get_contents($this->migrationFile('news_authors')));
        $this->assertSame($this->fixture('recipes_cuisines.migration'), file_get_contents($this->migrationFile('recipes_cuisines')));
        $this->assertSame(
            str_replace('news_authors', 'recipes_authors', $this->fixture('news_authors.migration')),
            file_get_contents($this->migrationFile('recipes_authors')),
        );
        $this->assertSame(
            str_replace('news_authors', 'dishes_authors', $this->fixture('news_authors.migration')),
            file_get_contents($this->migrationFile('dishes_authors')),
        );
    }

    public function test_a_skipped_type_and_a_taxonomy_nobody_has_get_no_link_table(): void
    {
        $this->writer()->write($this->plan());

        $this->assertSame([], glob($this->dir.'/migrations/*_create_books_*') ?: []);
        $this->assertSame([], glob($this->dir.'/migrations/*_create_recipes_ghost*') ?: []);
        $this->assertSame([], glob($this->dir.'/migrations/*_create_books_cuisines*') ?: []);
        $this->assertFileDoesNotExist($this->dir.'/models/Book.php');
        // The News module's own model is not ours to write.
        $this->assertFileDoesNotExist($this->dir.'/models/News.php');
        $this->assertSame([], glob($this->dir.'/migrations/*_create_news_table.php') ?: []);
        $this->assertCount(15, $this->written());
    }

    public function test_migrations_are_numbered_in_the_order_they_are_written_after_the_date(): void
    {
        $this->writer()->write($this->plan());

        $names = array_keys($this->written());
        $migrations = array_values(array_filter($names, static fn (string $name): bool => str_contains($name, '_create_')));
        sort($migrations);
        $date = date('Y_m_d');

        $this->assertSame([
            "{$date}_900001_create_recipes_table.php",
            "{$date}_900002_create_dishes_table.php",
            "{$date}_900003_create_cuisines_table.php",
            "{$date}_900004_create_categories_table.php",
            "{$date}_900005_create_authors_table.php",
            "{$date}_900006_create_news_categories_table.php",
            "{$date}_900007_create_news_authors_table.php",
            "{$date}_900008_create_recipes_cuisines_table.php",
            "{$date}_900009_create_recipes_authors_table.php",
            "{$date}_900010_create_dishes_authors_table.php",
        ], $migrations);
    }

    public function test_what_was_written_is_reported_by_path(): void
    {
        $result = $this->writer()->write($this->plan());

        $this->assertSame([], $result['skipped']);
        $this->assertCount(15, $result['written']);
        $this->assertSame($this->dir.'/models/Recipe.php', $result['written'][1]);
        $this->assertSame($this->migrationFile('recipes'), $result['written'][0]);
        $this->assertSame($this->dir.'/models/Author.php', $result['written'][9]);
    }

    public function test_the_generated_files_are_valid_php(): void
    {
        $this->writer()->write($this->plan());

        foreach ($this->written() as $name => $code) {
            $tokens = token_get_all($code, TOKEN_PARSE);
            $this->assertNotEmpty($tokens, $name);
            $this->assertStringContainsString(Writer::MARK, $code);
        }
    }

    public function test_the_mark_says_what_made_the_file(): void
    {
        $this->assertSame('Generated by atrium:wordpress:generate', Writer::MARK);
    }

    public function test_writing_again_leaves_the_files_alone(): void
    {
        $first = $this->writer()->write($this->plan());
        $before = $this->written();

        $again = $this->writer()->write($this->plan());

        $this->assertSame([], $again['written']);
        $this->assertSame($first['written'], $again['skipped']);
        $this->assertSame($before, $this->written());
    }

    public function test_a_writer_that_writes_again_forgets_its_last_report(): void
    {
        $writer = $this->writer();
        $writer->write($this->plan());

        $again = $writer->write($this->plan());

        $this->assertSame([], $again['written']);
        $this->assertCount(15, $again['skipped']);
    }

    public function test_a_migration_made_on_another_day_is_found_and_kept(): void
    {
        mkdir($this->dir.'/migrations');
        $old = $this->dir.'/migrations/2020_01_01_000000_create_recipes_table.php';
        file_put_contents($old, "<?php\n// ".Writer::MARK.". old\n");

        $result = $this->writer()->write($this->plan());

        $this->assertContains($old, $result['skipped']);
        $this->assertSame("<?php\n// ".Writer::MARK.". old\n", file_get_contents($old));
        $this->assertCount(1, glob($this->dir.'/migrations/*_create_recipes_table.php') ?: []);
    }

    public function test_the_forced_writer_replaces_a_migration_made_on_another_day_where_it_is(): void
    {
        mkdir($this->dir.'/migrations');
        $old = $this->dir.'/migrations/2020_01_01_000000_create_recipes_table.php';
        file_put_contents($old, "<?php\n// ".Writer::MARK.". old\n");

        $result = $this->writer(true)->write($this->plan());

        $this->assertContains($old, $result['written']);
        $this->assertSame($this->fixture('recipes.migration'), file_get_contents($old));
        $this->assertCount(1, glob($this->dir.'/migrations/*_create_recipes_table.php') ?: []);
    }

    public function test_a_file_somebody_else_wrote_is_not_overwritten(): void
    {
        mkdir($this->dir.'/models');
        file_put_contents($this->dir.'/models/Recipe.php', "<?php\n// my own model\n");

        try {
            $this->writer()->write($this->plan());
            $this->fail('The foreign file was overwritten.');
        } catch (RuntimeException $exception) {
            $this->assertSame($this->dir.'/models/Recipe.php exists and was not generated by the WordPress importer; move it away or pass --force.', $exception->getMessage());
        }

        $this->assertSame("<?php\n// my own model\n", file_get_contents($this->dir.'/models/Recipe.php'));
    }

    public function test_a_migration_somebody_else_wrote_is_not_overwritten(): void
    {
        mkdir($this->dir.'/migrations');
        file_put_contents($this->dir.'/migrations/2019_01_01_000000_create_recipes_table.php', "<?php\n// mine\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2019_01_01_000000_create_recipes_table.php exists and was not generated');

        $this->writer()->write($this->plan());
    }

    public function test_force_overwrites_foreign_files_too(): void
    {
        mkdir($this->dir.'/models');
        file_put_contents($this->dir.'/models/Recipe.php', "<?php\n// my own model\n");

        $result = $this->writer(true)->write($this->plan());

        $this->assertSame($this->fixture('Recipe.model'), file_get_contents($this->dir.'/models/Recipe.php'));
        $this->assertContains($this->dir.'/models/Recipe.php', $result['written']);
        $this->assertSame([], $result['skipped']);
    }

    public function test_force_rewrites_the_files_it_made_before(): void
    {
        $this->writer()->write($this->plan());
        file_put_contents($this->dir.'/models/Dish.php', "<?php\n// ".Writer::MARK.". edited\n");

        $result = $this->writer(true)->write($this->plan());

        $this->assertCount(15, $result['written']);
        $this->assertSame($this->fixture('Dish.model'), file_get_contents($this->dir.'/models/Dish.php'));
    }

    public function test_a_file_of_ours_with_edits_is_left_as_it_is(): void
    {
        $this->writer()->write($this->plan());
        file_put_contents($this->dir.'/models/Dish.php', "<?php\n// ".Writer::MARK.". edited\n");

        $result = $this->writer()->write($this->plan());

        $this->assertContains($this->dir.'/models/Dish.php', $result['skipped']);
        $this->assertSame("<?php\n// ".Writer::MARK.". edited\n", file_get_contents($this->dir.'/models/Dish.php'));
    }

    public function test_the_folders_are_made_when_missing(): void
    {
        $writer = new Writer($this->dir.'/deep/down/models', $this->dir.'/other/deep/migrations');

        $writer->write($this->plan());

        $this->assertFileExists($this->dir.'/deep/down/models/Recipe.php');
        $this->assertNotEmpty(glob($this->dir.'/other/deep/migrations/*_create_recipes_table.php'));
    }

    public function test_the_folders_it_makes_are_open_for_reading_but_closed_for_writing_to_others(): void
    {
        $umask = umask(022);

        try {
            (new Writer($this->dir.'/deep/down/models', $this->dir.'/other/deep/migrations'))->write($this->plan());
        } finally {
            umask($umask);
        }

        foreach (['/deep', '/deep/down', '/deep/down/models', '/other/deep/migrations'] as $folder) {
            $this->assertSame(0755, fileperms($this->dir.$folder) & 0777, $folder);
        }
    }

    public function test_a_taxonomy_the_plan_does_not_know_does_not_hide_the_ones_after_it(): void
    {
        $this->writer()->write($this->plan());

        $recipe = (string) file_get_contents($this->dir.'/models/Recipe.php');

        $this->assertStringContainsString("BelongsToManyField::make('cuisines')", $recipe);
        $this->assertStringNotContainsString('ghost', $recipe);
    }

    public function test_slashes_at_the_end_of_the_paths_do_not_double(): void
    {
        $writer = new Writer($this->dir.'/models///', $this->dir.'/migrations//');

        $result = $writer->write($this->plan());

        $this->assertSame($this->dir.'/models/Recipe.php', $writer->modelFile('App\\Models\\Recipe'));
        $this->assertContains($this->dir.'/models/Recipe.php', $result['written']);
        $this->assertNotEmpty(glob($this->dir.'/migrations/*_create_recipes_table.php'));
        $this->assertSame([], glob($this->dir.'/migrations/*//*') ?: []);
        $this->assertSame([], array_filter($result['written'], static fn (string $file): bool => str_contains($file, '//')));

        $again = $writer->write($this->plan());

        $this->assertSame([], $again['written']);
        $this->assertSame([], array_filter($again['skipped'], static fn (string $file): bool => str_contains($file, '//')));
        $this->assertContains($this->migrationFile('recipes'), $again['skipped']);
    }

    public function test_a_writer_that_is_not_told_to_force_keeps_what_somebody_else_wrote(): void
    {
        $writer = new Writer($this->dir.'/models', $this->dir.'/migrations');
        mkdir($this->dir.'/models');
        file_put_contents($this->dir.'/models/Recipe.php', "<?php\n// mine\n");

        try {
            $writer->write($this->plan());
            $this->fail('A file somebody else wrote was overwritten.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($this->dir.'/models/Recipe.php exists and was not generated', $e->getMessage());
        }

        $this->assertSame("<?php\n// mine\n", file_get_contents($this->dir.'/models/Recipe.php'));
    }

    public function test_a_table_with_underscores_has_dashes_in_the_route_of_its_admin(): void
    {
        $source = new ArraySource;
        $plan = new Plan(
            $source,
            new Languages($source),
            ['recipe_book' => new Type('recipe_book', Type::GENERATE, 'recipe_books', 'App\\Models\\RecipeBook', 'recipe-book', 'Recipe books', false, 1)],
            [],
            'authors',
            'App\\Models\\Author',
        );

        $this->writer()->write($plan);

        $model = (string) file_get_contents($this->dir.'/models/RecipeBook.php');
        $this->assertStringContainsString("public static function getAdminRoutePrefix(): string\n    {\n        return 'recipe-books';\n    }", $model);
    }

    public function test_a_plan_without_modules_to_generate_still_gets_its_authors(): void
    {
        $source = new ArraySource;
        $plan = new Plan($source, new Languages($source), [], [], 'authors', 'App\\Models\\Author');

        $result = $this->writer()->write($plan);

        $this->assertCount(2, $result['written']);
        $this->assertFileExists($this->dir.'/models/Author.php');
        $this->assertStringContainsString('    public function', $this->fixture('Author.model'));
        $this->assertStringContainsString("return 'name';", (string) file_get_contents($this->dir.'/models/Author.php'));
        $this->assertStringNotContainsString('BelongsToManyField', (string) file_get_contents($this->dir.'/models/Author.php'));
    }

    public function test_a_model_in_a_nested_namespace_goes_by_its_class_name(): void
    {
        $this->assertSame($this->dir.'/models/Recipe.php', $this->writer()->modelFile('Some\\Deep\\Namespace\\Recipe'));
    }
}
