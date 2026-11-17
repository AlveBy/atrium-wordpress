<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Console;

use Atrium\WordPress\Generator\Writer;
use RuntimeException;

/**
 * Writes the migrations and models of the modules the site needs: its
 * other post types, its taxonomies, its authors, the links between them.
 */
final class GenerateCommand extends WordPressCommand
{
    protected $signature = 'atrium:wordpress:generate'.self::SOURCE_OPTIONS.'
        {--force : Overwrite files, even ones the importer did not write}';

    protected $description = 'Write the migrations and models of the modules a WordPress site needs';

    public function handle(): int
    {
        $plan = $this->plan();
        $writer = new Writer($this->modelPath(), $this->migrationPath(), (bool) $this->option('force'));

        try {
            $result = $writer->write($plan);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['written'] as $file) {
            $this->line('  written  '.$file);
        }

        foreach ($result['skipped'] as $file) {
            $this->line('  kept     '.$file);
        }

        $this->newLine();
        $this->line('List the modules in config/atrium.php, so they are in the admin and on the site:');
        $this->line("'resources' => [");

        foreach ([...array_values($plan->generated()), ...$plan->taxonomies] as $module) {
            $this->line('    \\'.$module->model.'::class,');
        }

        $this->line('    \\'.$plan->authorsModel.'::class,');
        $this->line('],');
        $this->line("'public_urls' => [");

        foreach ([...array_values($plan->generated()), ...$plan->taxonomies] as $module) {
            $this->line('    \\'.$module->model."::class => ['prefix' => '{$module->prefix}'],");
        }

        $this->line('],');

        return self::SUCCESS;
    }
}
