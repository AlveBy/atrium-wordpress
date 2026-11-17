<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress;

use Illuminate\Support\ServiceProvider;

final class WordPressServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/atrium-wordpress.php', 'atrium-wordpress');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/atrium-wordpress.php' => config_path('atrium-wordpress.php')], 'atrium-wordpress-config');

            $this->commands([
                Console\AnalyzeCommand::class,
                Console\GenerateCommand::class,
                Console\MigrateDataCommand::class,
                Console\MigrateCommand::class,
                Console\ParityCommand::class,
                Console\RedirectsCommand::class,
                Console\SyncCommand::class,
            ]);
        }
    }
}
