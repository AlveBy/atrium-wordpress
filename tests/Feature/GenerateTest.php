<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Feature;

use Atrium\WordPress\Tests\Concerns\ImportsWordPress;
use Atrium\WordPress\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

/**
 * Writing the modules a site needs: what is written, what is never
 * overwritten, and that the written code works.
 */
final class GenerateTest extends TestCase
{
    use ImportsWordPress;

    private const MODELS = ['Recipe', 'Category', 'Cuisine', 'Tag', 'Author'];

    private const TABLES = [
        'recipes', 'categories', 'cuisines', 'tags', 'authors',
        'pages_authors', 'news_categories', 'news_tags', 'news_authors', 'recipes_cuisines', 'recipes_authors',
    ];

    /**
     * @return array<int,string>
     */
    private function files(string $folder): array
    {
        $files = glob($this->workDir.'/'.$folder.'/*.php') ?: [];
        sort($files);

        return $files;
    }

    public function test_it_writes_a_model_per_module_and_a_migration_per_table(): void
    {
        $this->site();

        [$exit, $output] = $this->cli('atrium:wordpress:generate');

        $this->assertSame(0, $exit);
        $this->assertSame(array_map(fn (string $name): string => $this->workDir.'/models/'.$name.'.php', ['Author', 'Category', 'Cuisine', 'Recipe', 'Tag']), $this->files('models'));
        $migrations = array_map(static fn (string $file): string => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_create_(\w+)_table\.php$/', '$1', basename($file)) ?? '', $this->files('migrations'));
        $this->assertEqualsCanonicalizing(self::TABLES, $migrations);
        $this->assertSame(16, substr_count($output, "  written  {$this->workDir}/"));
        $this->assertStringNotContainsString('  kept ', $output);
    }

    public function test_migrations_are_numbered_in_the_order_the_tables_depend_on_each_other(): void
    {
        $this->site();

        $this->cli('atrium:wordpress:generate');

        $names = array_map('basename', $this->files('migrations'));
        $this->assertSame(range(900001, 900011), array_map(static fn (string $name): int => (int) substr($name, 11, 6), $names));
        $this->assertStringContainsString('_900001_create_recipes_table.php', $names[0]);
        $this->assertStringContainsString('_900005_create_authors_table.php', $names[4]);
    }

    public function test_it_tells_what_to_list_in_the_config(): void
    {
        $this->site();

        [, $output] = $this->cli('atrium:wordpress:generate');

        $generated = TestCase::NAMESPACE;
        $this->assertStringContainsString(
            "\n\nList the modules in config/atrium.php, so they are in the admin and on the site:\n"
            ."'resources' => [\n"
            ."    \\{$generated}\\Recipe::class,\n"
            ."    \\{$generated}\\Category::class,\n"
            ."    \\{$generated}\\Cuisine::class,\n"
            ."    \\{$generated}\\Tag::class,\n"
            ."    \\{$generated}\\Author::class,\n"
            ."],\n"
            ."'public_urls' => [\n"
            ."    \\{$generated}\\Recipe::class => ['prefix' => 'recipe'],\n"
            ."    \\{$generated}\\Category::class => ['prefix' => 'category'],\n"
            ."    \\{$generated}\\Cuisine::class => ['prefix' => 'cuisine'],\n"
            ."    \\{$generated}\\Tag::class => ['prefix' => 'tag'],\n"
            ."],\n",
            $output,
        );
        $this->assertStringNotContainsString("\n\n\nList the modules", $output);
    }

    public function test_a_post_type_and_a_taxonomy_of_one_name_are_both_listed_in_the_config(): void
    {
        $this->site();
        $this->wpPost(300, 'cuisine', 'A cuisine as a post');

        [, $output] = $this->cli('atrium:wordpress:generate');

        $generated = TestCase::NAMESPACE;
        $this->assertStringContainsString(
            "'resources' => [\n"
            ."    \\{$generated}\\Cuisine::class,\n"
            ."    \\{$generated}\\Recipe::class,\n"
            ."    \\{$generated}\\Category::class,\n"
            ."    \\{$generated}\\WordpressCuisine::class,\n"
            ."    \\{$generated}\\Tag::class,\n"
            ."    \\{$generated}\\Author::class,\n"
            ."],\n"
            ."'public_urls' => [\n"
            ."    \\{$generated}\\Cuisine::class => ['prefix' => 'cuisine'],\n"
            ."    \\{$generated}\\Recipe::class => ['prefix' => 'recipe'],\n"
            ."    \\{$generated}\\Category::class => ['prefix' => 'category'],\n"
            ."    \\{$generated}\\WordpressCuisine::class => ['prefix' => 'cuisine'],\n"
            ."    \\{$generated}\\Tag::class => ['prefix' => 'tag'],\n"
            ."],\n",
            $output,
        );
    }

    public function test_what_it_wrote_is_valid_php(): void
    {
        $this->site();
        $this->cli('atrium:wordpress:generate');

        foreach ([...$this->files('models'), ...$this->files('migrations')] as $file) {
            $this->assertNotEmpty(token_get_all((string) file_get_contents($file), TOKEN_PARSE), $file);
            $this->assertStringContainsString('Generated by atrium:wordpress:generate', (string) file_get_contents($file));
        }
    }

    public function test_what_it_wrote_can_be_migrated_and_loaded(): void
    {
        $this->site();

        $this->generateAndMigrate();

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasColumns('recipes', ['id', 'site_id', 'wordpress_id', 'name', 'code', 'cooking_time', 'difficulty', 'ingredients', 'spicy', 'served_on', 'photo', 'gallery', 'labels', 'steps', 'notes', 'source_url']));
        $this->assertTrue(Schema::hasColumns('categories', ['id', 'wordpress_id', 'name', 'code', 'description', 'parent_id']));
        $this->assertTrue(Schema::hasColumns('authors', ['id', 'wordpress_id', 'name', 'code', 'email', 'url', 'description']));
        $this->assertSame(['record_id', 'related_id'], array_values(array_diff(Schema::getColumnListing('news_categories'), ['id'])));

        foreach (self::MODELS as $name) {
            $class = TestCase::NAMESPACE.'\\'.$name;
            $this->assertTrue(class_exists($class), $class);
        }

        $recipe = new (TestCase::NAMESPACE.'\\Recipe');
        $this->assertSame('recipes', $recipe->getTable());
        $this->assertSame(0, $recipe->newQuery()->count());
        $this->assertSame(0, (new (TestCase::NAMESPACE.'\\Author'))->newQuery()->count());
    }

    public function test_a_second_run_keeps_what_it_wrote_even_when_edited(): void
    {
        $this->site();
        $this->cli('atrium:wordpress:generate');
        $model = $this->workDir.'/models/Recipe.php';
        file_put_contents($model, file_get_contents($model)."\n// edited by hand\n");
        $before = array_map('md5_file', $this->files('migrations'));

        [$exit, $output] = $this->cli('atrium:wordpress:generate');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('// edited by hand', (string) file_get_contents($model));
        $this->assertSame($before, array_map('md5_file', $this->files('migrations')));
        $this->assertSame(16, substr_count($output, "  kept     {$this->workDir}/"));
        $this->assertStringNotContainsString('  written ', $output);
        $this->assertCount(11, $this->files('migrations'));
    }

    public function test_a_file_of_its_own_kind_is_overwritten_with_force(): void
    {
        $this->site();
        $this->cli('atrium:wordpress:generate');
        $model = $this->workDir.'/models/Recipe.php';
        $original = (string) file_get_contents($model);
        file_put_contents($model, $original."\n// edited by hand\n");

        [$exit, $output] = $this->cli('atrium:wordpress:generate', ['--force' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame($original, (string) file_get_contents($model));
        $this->assertSame(16, substr_count($output, "  written  {$this->workDir}/"));
        $this->assertStringNotContainsString('  kept ', $output);
    }

    public function test_a_model_it_did_not_write_is_never_overwritten(): void
    {
        $this->site();
        $model = $this->workDir.'/models/Recipe.php';
        file_put_contents($model, "<?php\n// mine\n");

        [$exit, $output] = $this->cli('atrium:wordpress:generate');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("{$model} exists and was not generated by the WordPress importer; move it away or pass --force.", $output);
        $this->assertSame("<?php\n// mine\n", file_get_contents($model));
        $this->assertStringNotContainsString('List the modules in config/atrium.php', $output);
        $this->assertFileDoesNotExist($this->workDir.'/models/Category.php');
    }

    public function test_a_migration_it_did_not_write_is_never_overwritten(): void
    {
        $this->site();
        $migration = $this->workDir.'/migrations/2020_01_01_000000_create_authors_table.php';
        file_put_contents($migration, "<?php\n// mine\n");

        [$exit, $output] = $this->cli('atrium:wordpress:generate');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("{$migration} exists and was not generated by the WordPress importer", $output);
        $this->assertSame("<?php\n// mine\n", file_get_contents($migration));
        $this->assertSame([$migration], glob($this->workDir.'/migrations/*_create_authors_table.php'));
    }

    public function test_force_overwrites_files_it_did_not_write(): void
    {
        $this->site();
        $model = $this->workDir.'/models/Recipe.php';
        $migration = $this->workDir.'/migrations/2020_01_01_000000_create_authors_table.php';
        file_put_contents($model, "<?php\n// mine\n");
        file_put_contents($migration, "<?php\n// mine\n");

        [$exit, $output] = $this->cli('atrium:wordpress:generate', ['--force' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Generated by atrium:wordpress:generate', (string) file_get_contents($model));
        $this->assertStringContainsString('Generated by atrium:wordpress:generate', (string) file_get_contents($migration));
        // The migration that was there keeps its name: a second table of the same name would clash.
        $this->assertCount(1, glob($this->workDir.'/migrations/*_create_authors_table.php') ?: []);
        $this->assertStringContainsString("  written  {$migration}\n", $output);
    }

    public function test_the_migration_of_an_earlier_run_is_reused_under_its_name(): void
    {
        $this->site();
        $this->cli('atrium:wordpress:generate');
        $old = glob($this->workDir.'/migrations/*_create_recipes_table.php')[0];
        rename($old, $this->workDir.'/migrations/2020_05_05_123456_create_recipes_table.php');

        $this->cli('atrium:wordpress:generate', ['--force' => true]);

        $this->assertSame([$this->workDir.'/migrations/2020_05_05_123456_create_recipes_table.php'], glob($this->workDir.'/migrations/*_create_recipes_table.php'));
    }

    public function test_the_folders_are_made_when_they_are_not_there(): void
    {
        $this->site();
        config()->set('atrium-wordpress.model_path', $this->workDir.'/deep/models');
        config()->set('atrium-wordpress.migration_path', $this->workDir.'/deep/migrations');

        [$exit] = $this->cli('atrium:wordpress:generate');

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->workDir.'/deep/models/Recipe.php');
        $this->assertCount(11, glob($this->workDir.'/deep/migrations/*.php') ?: []);
    }

    public function test_without_a_model_folder_the_namespace_gives_one_under_app(): void
    {
        $this->site();
        $this->app->useAppPath($this->workDir.'/app');
        config()->set('atrium-wordpress.model_path', null);
        config()->set('atrium-wordpress.model_namespace', 'App\\Models\\Shop');

        $this->cli('atrium:wordpress:generate');

        $this->assertFileExists($this->workDir.'/app/Models/Shop/Recipe.php');
        $this->assertStringContainsString('namespace App\\Models\\Shop;', (string) file_get_contents($this->workDir.'/app/Models/Shop/Recipe.php'));
    }

    public function test_an_empty_model_folder_counts_as_none(): void
    {
        $this->site();
        $this->app->useAppPath($this->workDir.'/app');
        config()->set('atrium-wordpress.model_path', '');
        config()->set('atrium-wordpress.model_namespace', '\\App\\Models\\');

        $this->cli('atrium:wordpress:generate');

        $this->assertFileExists($this->workDir.'/app/Models/Recipe.php');
    }

    public function test_a_namespace_outside_app_goes_into_the_app_folder_itself(): void
    {
        $this->site();
        $this->app->useAppPath($this->workDir.'/app');
        config()->set('atrium-wordpress.model_path', null);
        config()->set('atrium-wordpress.model_namespace', 'Shop\\Models');

        $this->cli('atrium:wordpress:generate');

        $this->assertFileExists($this->workDir.'/app/Recipe.php');
    }

    public function test_the_namespace_app_is_the_app_folder(): void
    {
        $this->site();
        $this->app->useAppPath($this->workDir.'/app');
        config()->set('atrium-wordpress.model_path', null);
        config()->set('atrium-wordpress.model_namespace', 'App');

        $this->cli('atrium:wordpress:generate');

        $this->assertFileExists($this->workDir.'/app/Recipe.php');
    }

    public function test_without_a_migration_folder_the_database_folder_is_used(): void
    {
        $this->site();
        $this->app->useDatabasePath($this->workDir.'/database');
        config()->set('atrium-wordpress.migration_path', null);

        $this->cli('atrium:wordpress:generate');

        $this->assertCount(11, glob($this->workDir.'/database/migrations/*_create_*_table.php') ?: []);
    }

    public function test_an_empty_migration_folder_counts_as_none(): void
    {
        $this->site();
        $this->app->useDatabasePath($this->workDir.'/database');
        config()->set('atrium-wordpress.migration_path', '');

        $this->cli('atrium:wordpress:generate');

        $this->assertCount(11, glob($this->workDir.'/database/migrations/*_create_*_table.php') ?: []);
    }

    public function test_a_site_with_a_prefix_of_its_own(): void
    {
        $this->site('wpml', 'blog_');

        [$exit] = $this->cli('atrium:wordpress:generate', ['--prefix' => 'blog_']);

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->workDir.'/models/Recipe.php');
    }

    public function test_a_site_without_custom_types_needs_only_the_authors(): void
    {
        $this->site();
        $this->wp()->table('wp_posts')->where('post_type', 'recipe')->delete();
        $this->wp()->table('wp_term_taxonomy')->where('taxonomy', 'cuisine')->delete();

        [, $output] = $this->cli('atrium:wordpress:generate');

        $this->assertEqualsCanonicalizing(['Author', 'Category', 'Tag'], array_map(static fn (string $file): string => basename($file, '.php'), $this->files('models')));
        $this->assertStringNotContainsString('Recipe', $output);
    }
}
