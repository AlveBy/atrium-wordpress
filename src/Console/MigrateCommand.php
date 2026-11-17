<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Console;

/**
 * The whole move in one go: generate the modules, create their tables,
 * move the content, compare before and after.
 */
final class MigrateCommand extends WordPressCommand
{
    protected $signature = 'atrium:wordpress:migrate'.self::SOURCE_OPTIONS.'
        {--force : Overwrite generated files, even ones the importer did not write}';

    protected $description = 'Move a WordPress site to Atrium: generate modules, create tables, move content, check parity';

    public function handle(): int
    {
        $options = ['--connection' => $this->option('connection'), '--prefix' => $this->option('prefix')];

        $steps = [
            ['atrium:wordpress:generate', [...$options, '--force' => $this->option('force')]],
            ['migrate', ['--path' => $this->migrationPath(), '--realpath' => true, '--force' => true]],
            ['atrium:wordpress:migrate:data', $options],
            ['atrium:wordpress:parity', $options],
        ];

        foreach ($steps as [$command, $arguments]) {
            $this->info('› '.$command);

            if ($this->call($command, $arguments) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
