<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Console;

use Atrium\WordPress\Import\Importer;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the content into the tables the modules have. Run it again at any
 * time: it updates what it moved before.
 */
final class MigrateDataCommand extends WordPressCommand
{
    protected $signature = 'atrium:wordpress:migrate:data'.self::SOURCE_OPTIONS.'
        {--only-changed : Only posts changed in WordPress since the last run, and new ones}';

    protected $description = 'Move the posts, pages, terms, authors, files and SEO of a WordPress site into Atrium';

    public function handle(): int
    {
        $records = $this->records();
        $plan = $records->plan;
        $missing = [];

        foreach ($plan->moving() as $type) {
            $missing[] = $type->table;

            foreach ($type->taxonomies as $name) {
                $missing[] = $plan->taxonomy($name)->pivot($type);
            }

            $missing[] = $plan->authorPivot($type);
        }

        $missing = array_filter([...$missing, ...array_map(static fn ($taxonomy): string => $taxonomy->table, $plan->taxonomies), $plan->authorsTable, 'wordpress_map'], static fn (?string $table): bool => $table !== null && ! Schema::hasTable($table));

        if ($missing !== []) {
            $this->error('These tables are not there yet: '.implode(', ', array_unique($missing)).'. Run atrium:wordpress:generate and php artisan migrate first.');

            return self::FAILURE;
        }

        $result = (new Importer($records, fn (string $line) => $this->line('  '.$line, verbosity: 'v')))->run((bool) $this->option('only-changed'));

        $this->table(['Moved', 'Count'], array_map(null, array_keys($result['counts']), $result['counts']));

        if ($result['missing_files'] !== []) {
            $this->warn(count($result['missing_files']).' files are missing in the old uploads folder:');

            foreach (array_slice($result['missing_files'], 0, 20) as $file) {
                $this->line('  '.$file);
            }
        }

        $this->notes($result['notes']);
        $this->line('The default language is now '.$plan->languages->default().'.');

        return self::SUCCESS;
    }
}
