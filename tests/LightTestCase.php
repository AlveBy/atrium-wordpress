<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests;

use Atrium\WordPress\WordPressServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Only the package's config and a made-up WordPress database on SQLite: for
 * the classes that read a site and write nothing.
 */
abstract class LightTestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [WordPressServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.wordpress', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        $config->set('atrium-wordpress.connection', 'wordpress');
    }
}
