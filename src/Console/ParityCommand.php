<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Console;

use Atrium\WordPress\Parity\Audit;

/**
 * Before and after, type by type and field by field. Exits with 1 while
 * anything that WordPress had is not in Atrium as it should be.
 */
final class ParityCommand extends WordPressCommand
{
    protected $signature = 'atrium:wordpress:parity'.self::SOURCE_OPTIONS.'
        {--format=table : table, json or markdown}
        {--output= : Write the report to this file}';

    protected $description = 'Compare a WordPress site with what moved to Atrium, record by record';

    public function handle(): int
    {
        $report = (new Audit($this->records()))->run();
        $format = $this->option('format');

        $text = match ($format) {
            'json' => json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'markdown' => $this->markdown($report),
            default => null,
        };

        if ($text === null && $format !== 'table') {
            $this->error("Unknown format \"{$format}\": table, json or markdown.");

            return self::INVALID;
        }

        if ($text === null) {
            $this->table(['What', 'WordPress', 'Atrium', 'Problems'], array_map(
                static fn (string $scope, array $row): array => [$scope, $row['wordpress'], $row['atrium'].($row['atrium'] < $row['wordpress'] ? ' (SHORT by '.($row['wordpress'] - $row['atrium']).')' : ''), $row['problems']],
                array_keys($report['summary']),
                $report['summary'],
            ));

            foreach (array_slice($report['problems'], 0, 200) as $problem) {
                $this->line(sprintf('<error>%s %s %s</error>: expected %s, found %s', $problem['scope'], $problem['wp_id'] ?? '', $problem['field'], $this->show($problem['expected']), $this->show($problem['actual'])));
            }

            foreach ($report['warnings'] as $warning) {
                $this->line('<comment>'.$warning.'</comment>');
            }

            $this->notes($report['notes']);
        } elseif ($this->option('output')) {
            file_put_contents((string) $this->option('output'), $text);
            $this->info('Written to '.$this->option('output'));
        } else {
            $this->line($text);
        }

        if ($report['problems'] !== []) {
            $this->error(count($report['problems']).' differences.');

            return self::FAILURE;
        }

        $this->info('Everything moved.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function markdown(array $report): string
    {
        $lines = ['# WordPress → Atrium', '', '| What | WordPress | Atrium | Problems |', '|---|---|---|---|'];

        foreach ($report['summary'] as $scope => $row) {
            $lines[] = "| {$scope} | {$row['wordpress']} | {$row['atrium']} | {$row['problems']} |";
        }

        if ($report['problems'] !== []) {
            array_push($lines, '', '## Differences', '', '| What | ID | Field | Expected | Found |', '|---|---|---|---|---|');

            foreach ($report['problems'] as $problem) {
                $lines[] = '| '.implode(' | ', array_map(static fn (string $cell): string => str_replace('|', '\\|', $cell), [$problem['scope'], $problem['wp_id'] ?? '', $problem['field'], $this->show($problem['expected']), $this->show($problem['actual'])])).' |';
            }
        }

        foreach (['warnings' => 'Not moved', 'notes' => 'Already broken on the old site'] as $key => $title) {
            if ($report[$key] !== []) {
                array_push($lines, '', '## '.$title, '', ...array_map(static fn (string $line): string => '- '.$line, $report[$key]));
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function show(mixed $value): string
    {
        return $value === null ? 'nothing' : mb_strimwidth(is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 0, 120, '…');
    }
}
