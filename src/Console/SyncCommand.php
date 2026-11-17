<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Console;

use Atrium\WordPress\Import\Importer;

/**
 * Catches up with the old site while it is still being edited: moves what
 * changed in WordPress since the last run, and with --prune takes away what
 * was deleted there.
 */
final class SyncCommand extends WordPressCommand
{
    protected $signature = 'atrium:wordpress:sync'.self::SOURCE_OPTIONS.'
        {--prune : Delete the records whose posts are gone from WordPress (deleted, trashed)}
        {--audit : Compare before and after when done}';

    protected $description = 'Move what changed on the WordPress site since the last run';

    public function handle(): int
    {
        $options = ['--connection' => $this->option('connection'), '--prefix' => $this->option('prefix')];

        if ($this->call('atrium:wordpress:migrate:data', [...$options, '--only-changed' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        if ($this->option('prune')) {
            $this->info('Deleted: '.(new Importer($this->records()))->prune());
        }

        return $this->option('audit') ? $this->call('atrium:wordpress:parity', $options) : self::SUCCESS;
    }
}
