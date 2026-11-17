<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Feature;

use Atrium\WordPress\Tests\LightTestCase;
use Atrium\WordPress\WordPressServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * What the package gives the application that installs it.
 */
final class ServiceProviderTest extends LightTestCase
{
    public function test_the_config_is_merged_with_the_defaults(): void
    {
        $config = config('atrium-wordpress');

        $this->assertIsArray($config);
        foreach (['connection', 'table_prefix', 'uploads_path', 'chunk_size'] as $key) {
            $this->assertArrayHasKey($key, $config);
        }
        $this->assertSame('wordpress', config('atrium-wordpress.connection'));
        $this->assertSame(array_keys(require __DIR__.'/../../config/atrium-wordpress.php'), array_keys($config));
    }

    public function test_the_migration_of_the_map_is_known_to_the_migrator(): void
    {
        $paths = $this->app['migrator']->paths();

        $this->assertContains(realpath(__DIR__.'/../../database/migrations'), array_map('realpath', $paths));
    }

    public function test_the_map_table_is_created_by_migrating(): void
    {
        $this->artisan('migrate', ['--database' => 'testing', '--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('wordpress_map'));
        $this->assertTrue(Schema::hasColumns('wordpress_map', ['id', 'kind', 'wp_id', 'wp_type', 'target_type', 'target_id', 'paths', 'wp_modified', 'created_at', 'updated_at']));
    }

    public function test_every_command_is_registered(): void
    {
        $commands = Artisan::all();

        foreach (['analyze', 'generate', 'migrate', 'migrate:data', 'parity', 'redirects', 'sync'] as $name) {
            $this->assertArrayHasKey('atrium:wordpress:'.$name, $commands);
        }
        $this->assertCount(7, array_filter(array_keys($commands), static fn (string $name): bool => str_starts_with($name, 'atrium:wordpress:')));
    }

    public function test_the_config_can_be_published_under_its_own_tag(): void
    {
        $paths = ServiceProvider::pathsToPublish(WordPressServiceProvider::class, 'atrium-wordpress-config');

        $this->assertCount(1, $paths);
        $this->assertSame(realpath(__DIR__.'/../../config/atrium-wordpress.php'), realpath((string) array_key_first($paths)));
        $this->assertSame(config_path('atrium-wordpress.php'), reset($paths));
        $this->assertSame([], ServiceProvider::pathsToPublish(WordPressServiceProvider::class, 'nothing-of-the-kind'));
    }
}
