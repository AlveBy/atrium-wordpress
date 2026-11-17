<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Console;

use Atrium\WordPress\Import\Records;
use Atrium\WordPress\Plan\Plan;
use Atrium\WordPress\Plan\Planner;
use Atrium\WordPress\Source\Database;
use Atrium\WordPress\Source\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What every atrium:wordpress command shares: the old database and the plan
 * built from it.
 */
abstract class WordPressCommand extends Command
{
    /** Options every command takes. */
    protected const SOURCE_OPTIONS = '
        {--connection= : Database connection of the WordPress copy (atrium-wordpress.connection)}
        {--prefix= : Table prefix of the WordPress database (atrium-wordpress.table_prefix)}';

    protected function source(): Source
    {
        $connection = $this->option('connection') ?: config('atrium-wordpress.connection');
        $prefix = (string) ($this->option('prefix') ?? config('atrium-wordpress.table_prefix', 'wp_'));

        return new Database(DB::connection($connection), $prefix);
    }

    protected function plan(): Plan
    {
        return (new Planner)->build($this->source());
    }

    protected function records(): Records
    {
        return new Records($this->plan());
    }

    /**
     * Where the generated models go: the configured folder, or the one
     * Composer's App\ namespace gives.
     */
    protected function modelPath(): string
    {
        $path = config('atrium-wordpress.model_path');

        if (is_string($path) && $path !== '') {
            return $path;
        }

        $namespace = trim((string) config('atrium-wordpress.model_namespace', 'App\\Models'), '\\');

        return app_path(str_replace('\\', '/', Str::after($namespace, 'App\\') === $namespace ? '' : Str::after($namespace, 'App\\')));
    }

    protected function migrationPath(): string
    {
        $path = config('atrium-wordpress.migration_path');

        return is_string($path) && $path !== '' ? $path : database_path('migrations');
    }

    /**
     * @param  array<int,string>  $notes
     */
    protected function notes(array $notes): void
    {
        if ($notes === []) {
            return;
        }

        $this->newLine();
        $this->line('<comment>Already broken on the old site (moved as well as it could be):</comment>');

        foreach ($notes as $note) {
            $this->line('  - '.$note);
        }
    }
}
