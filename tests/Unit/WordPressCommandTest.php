<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Unit;

use Atrium\WordPress\Console\WordPressCommand;
use Atrium\WordPress\Source\Database;
use Atrium\WordPress\Source\Source;
use Atrium\WordPress\Tests\Fixtures\SyntheticWordPress as Wp;
use Atrium\WordPress\Tests\LightTestCase;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The notes block the commands share.
 */
final class WordPressCommandTest extends LightTestCase
{
    private function notes(array $notes): string
    {
        $command = new class extends WordPressCommand
        {
            protected $signature = 'test:notes';

            public function say(array $notes): void
            {
                $this->notes($notes);
            }
        };
        $buffer = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
        $command->say($notes);

        return $buffer->fetch();
    }

    private function modelPath(): string
    {
        $command = new class extends WordPressCommand
        {
            protected $signature = 'test:path';

            public function where(): string
            {
                return $this->modelPath();
            }
        };

        return $command->where();
    }

    /**
     * The source a command reads when it is run with these options.
     *
     * @param  array<string,string>  $options
     */
    private function source(array $options = []): Source
    {
        $command = new class extends WordPressCommand
        {
            public ?Source $found = null;

            protected $signature = 'test:source'.self::SOURCE_OPTIONS;

            public function handle(): int
            {
                $this->found = $this->source();

                return 0;
            }
        };
        $command->setLaravel($this->app);
        $command->run(new ArrayInput($options), new BufferedOutput);

        return $command->found;
    }

    public function test_the_source_is_the_configured_connection_with_the_configured_prefix(): void
    {
        Wp::install(DB::connection('wordpress'), 'blog_', 'none');
        config()->set('atrium-wordpress.table_prefix', 'blog_');

        $source = $this->source();

        $this->assertInstanceOf(Database::class, $source);
        $this->assertSame(DB::connection('wordpress'), $source->connection());
        $this->assertSame('Synthetic Blog', $source->option('blogname'));
    }

    public function test_the_prefix_is_wp_when_the_config_says_nothing(): void
    {
        Wp::install(DB::connection('wordpress'), 'wp_', 'none');
        $settings = config('atrium-wordpress');
        unset($settings['table_prefix']);
        config()->set('atrium-wordpress', $settings);

        $this->assertSame('Synthetic Blog', $this->source()->option('blogname'));
    }

    public function test_an_empty_prefix_in_the_config_is_no_prefix(): void
    {
        Wp::install(DB::connection('wordpress'), '', 'none');
        config()->set('atrium-wordpress.table_prefix', null);

        $this->assertSame('Synthetic Blog', $this->source()->option('blogname'));
    }

    public function test_the_options_of_the_command_beat_the_config(): void
    {
        config()->set('database.connections.other', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
        Wp::install(DB::connection('other'), 'copy_', 'none');
        config()->set('atrium-wordpress.table_prefix', 'blog_');

        $source = $this->source(['--connection' => 'other', '--prefix' => 'copy_']);

        $this->assertSame(DB::connection('other'), $source->connection());
        $this->assertSame('Synthetic Blog', $source->option('blogname'));
    }

    public function test_a_prefix_given_empty_is_no_prefix_even_when_the_config_has_one(): void
    {
        Wp::install(DB::connection('wordpress'), '', 'none');
        config()->set('atrium-wordpress.table_prefix', 'blog_');

        $this->assertSame('Synthetic Blog', $this->source(['--prefix' => ''])->option('blogname'));
    }

    public function test_the_models_go_where_the_namespace_leads_inside_app(): void
    {
        config()->set('atrium-wordpress.model_path', null);

        foreach (['App\\Models', 'App\\Models\\', '\\App\\Models\\'] as $namespace) {
            config()->set('atrium-wordpress.model_namespace', $namespace);
            $this->assertSame(app_path('Models'), $this->modelPath(), $namespace);
        }

        config()->set('atrium-wordpress.model_namespace', 'App\\Domain\\Blog\\');
        $this->assertSame(app_path('Domain/Blog'), $this->modelPath());
        config()->set('atrium-wordpress.model_namespace', 'App\\');
        $this->assertSame(app_path(''), $this->modelPath());
    }

    public function test_a_site_without_notes_prints_nothing(): void
    {
        $this->assertSame('', $this->notes([]));
    }

    public function test_the_notes_are_listed_under_one_heading(): void
    {
        $this->assertSame(
            "\nAlready broken on the old site (moved as well as it could be):\n  - first\n  - second\n",
            $this->notes(['first', 'second']),
        );
    }
}
