<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The 301 map from the old site's addresses to the new ones, for nginx,
 * Apache or a spreadsheet.
 */
final class RedirectsCommand extends Command
{
    protected $signature = 'atrium:wordpress:redirects
        {--format=nginx : nginx, apache or csv}
        {--output= : Write the map to this file}';

    protected $description = 'Write the 301 map from the old WordPress addresses to the new ones';

    public function handle(): int
    {
        $pairs = [];

        foreach (DB::table('wordpress_map')->whereNotNull('paths')->orderBy('kind')->orderBy('wp_id')->get(['paths']) as $row) {
            foreach ((array) json_decode((string) $row->paths, true) as [$old, $new]) {
                if ($old !== $new) {
                    $pairs[$old] = (string) $new;
                }
            }
        }

        $text = match ($this->option('format')) {
            'nginx' => $this->nginx($pairs),
            'apache' => $this->apache($pairs),
            'csv' => $this->csv($pairs),
            default => null,
        };

        if ($text === null) {
            $this->error('Unknown format "'.$this->option('format').'": nginx, apache or csv.');

            return self::INVALID;
        }

        if ($this->option('output')) {
            file_put_contents((string) $this->option('output'), $text);
            $this->info(count($pairs).' redirects written to '.$this->option('output'));
        } else {
            $this->output->write($text);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,string>  $pairs
     */
    private function nginx(array $pairs): string
    {
        $lines = [
            '# In the http block; then in the server block:',
            '#   if ($atrium_wordpress_redirect) { return 301 $atrium_wordpress_redirect; }',
            'map $request_uri $atrium_wordpress_redirect {',
            '    default "";',
        ];

        // $request_uri is matched as the browser sent it: %D0%BF… in capitals, where WordPress keeps %d0%bf….
        $rows = [];

        foreach ($pairs as $old => $new) {
            foreach ([$old, $this->hex($old, 'strtoupper'), $this->hex($old, 'strtolower')] as $key) {
                if ($key === $old || ! isset($pairs[$key])) {
                    $rows[$key] ??= $new;
                }
            }
        }

        foreach ($rows as $old => $new) {
            $lines[] = '    "'.addcslashes((string) $old, '"\\').'" "'.addcslashes($new, '"\\').'";';
        }

        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    /**
     * The address with the hex digits of its %XX escapes in one case.
     *
     * @param  'strtoupper'|'strtolower'  $case
     */
    private function hex(string $path, string $case): string
    {
        return (string) preg_replace_callback('/%[0-9a-f]{2}/i', static fn (array $m): string => $case($m[0]), $path);
    }

    /**
     * @param  array<string,string>  $pairs
     */
    private function apache(array $pairs): string
    {
        $lines = ['RewriteEngine On'];

        foreach ($pairs as $old => $new) {
            [$path, $query] = [...explode('?', $old, 2), null];
            // Apache matches the decoded path: /%d0%bf… is /п… there.
            $pattern = '^'.preg_quote(rawurldecode(ltrim($path, '/')), '#').'$';

            if ($query !== null) {
                $lines[] = 'RewriteCond %{QUERY_STRING} ^'.preg_quote($query, '#').'$';
            }

            $lines[] = 'RewriteRule '.str_replace(' ', '\\ ', $pattern).' '.$new.($query !== null ? '?' : '').' [R=301,L]';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string,string>  $pairs
     */
    private function csv(array $pairs): string
    {
        $lines = ['old,new'];

        foreach ($pairs as $old => $new) {
            $lines[] = '"'.str_replace('"', '""', $old).'","'.str_replace('"', '""', $new).'"';
        }

        return implode("\n", $lines)."\n";
    }
}
