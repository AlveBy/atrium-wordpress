<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests;

use Atrium\Admin\AtriumServiceProvider;
use Atrium\Admin\Support\PublicUrl;
use Atrium\Menus\MenusServiceProvider;
use Atrium\News\NewsServiceProvider;
use Atrium\Pages\PagesServiceProvider;
use Atrium\Theme\ThemeServiceProvider;
use Atrium\WordPress\WordPressServiceProvider;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Tighten\Ziggy\ZiggyServiceProvider;

/**
 * A made-up WordPress database on its own SQLite connection, an Atrium with
 * the News, Pages and Menus modules on the database CI picked, and a folder of its
 * own for the generated code and the copied files.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    public const NAMESPACE = 'Atrium\\WordPress\\Tests\\Generated';

    protected string $workDir;

    /**
     * On MySQL and PostgreSQL the whole suite shares one database: each
     * class migrates it again.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        RefreshDatabaseState::$migrated = false;
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    protected function getPackageProviders($app): array
    {
        return [
            InertiaServiceProvider::class,
            ZiggyServiceProvider::class,
            AtriumServiceProvider::class,
            ThemeServiceProvider::class,
            NewsServiceProvider::class,
            PagesServiceProvider::class,
            MenusServiceProvider::class,
            WordPressServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $this->workDir = sys_get_temp_dir().'/atrium-wordpress-'.bin2hex(random_bytes(4));
        mkdir($this->workDir.'/models', 0777, true);
        mkdir($this->workDir.'/migrations', 0777, true);
        mkdir($this->workDir.'/public', 0777, true);
        mkdir($this->workDir.'/uploads', 0777, true);

        // Nothing a test writes, even by mistake, lands in the Laravel app
        // the other test processes share: a stray generated migration there
        // would be migrated by every test that comes after.
        $app->usePublicPath($this->workDir.'/public');
        $app->useAppPath($this->workDir.'/app');
        $app->useDatabasePath($this->workDir.'/database');

        $config = $app['config'];
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $config->set('app.locale', 'en');
        // CI runs the suite against MySQL and PostgreSQL too; the importer's
        // writes have to work there, not just on SQLite.
        $target = env('DB_CONNECTION');
        $config->set('database.default', in_array($target, ['mysql', 'pgsql'], true) ? $target : 'testing');
        $config->set('database.connections.wordpress', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        $config->set('atrium.modules', ['alveby/atrium-news', PagesServiceProvider::MODULE, MenusServiceProvider::MODULE]);
        $config->set('atrium.demo', false);
        $config->set('atrium.auth.two_factor.enabled', false);
        $config->set('atrium.dashboard.timings.enabled', false);
        $config->set('atrium-wordpress.connection', 'wordpress');
        $config->set('atrium-wordpress.uploads_path', $this->workDir.'/uploads');
        $config->set('atrium-wordpress.model_namespace', self::NAMESPACE);
        $config->set('atrium-wordpress.model_path', $this->workDir.'/models');
        $config->set('atrium-wordpress.migration_path', $this->workDir.'/migrations');
    }

    /**
     * The News, Pages and Menus tables come with their packages. They are handed to
     * the migrator rather than loaded with loadMigrationsFrom(), which after
     * the first test runs and rolls them back around every test: dozens of
     * CREATE and DROP statements per test, minutes on MySQL.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->app['migrator']->path(__DIR__.'/../../news/database/migrations');
        $this->app['migrator']->path(__DIR__.'/../../pages/database/migrations');
        $this->app['migrator']->path(__DIR__.'/../../menus/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        PublicUrl::forgetPaths();

        // The generated tables are created in the middle of a test.
        Event::listen(MigrationsEnded::class, fn () => $this->resumeTransaction());

        // The generated classes live outside Composer's autoloader.
        spl_autoload_register(function (string $class): void {
            if (! str_starts_with($class, self::NAMESPACE.'\\')) {
                return;
            }

            $file = $this->workDir.'/models/'.str_replace('\\', '/', substr($class, strlen(self::NAMESPACE) + 1)).'.php';

            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    /**
     * MySQL commits the open transaction when a table is created, and the
     * test goes on without one: its writes would stay in the database and
     * a failed savepoint could not be rolled back. A new transaction takes
     * the place of the one that was lost; on SQLite and PostgreSQL, where
     * creating tables is part of the transaction, nothing changes.
     */
    protected function resumeTransaction(): void
    {
        $db = DB::connection();

        if ($db->transactionLevel() > 0 && ! $db->getPdo()->inTransaction()) {
            $db->rollBack(0);
            $db->beginTransaction();
        }
    }

    protected function tearDown(): void
    {
        // Tables made by generated migrations outlive the transaction of
        // RefreshDatabase on MySQL, where creating or dropping a table also
        // commits what the test wrote. So the test's writes are rolled back
        // first, the tables dropped next, and a new transaction is left for
        // RefreshDatabase: without one it would build the whole schema again
        // for the next test.
        $db = DB::connection();
        $level = $db->transactionLevel();

        // A test that changed the schema itself on MySQL (dropped a table to
        // see what fails) took its transaction with it: the next test gets
        // the schema built again.
        if ($level > 0 && ! $db->getPdo()->inTransaction()) {
            RefreshDatabaseState::$migrated = false;
        }

        $db->rollBack(0);

        foreach (glob($this->workDir.'/migrations/*.php') ?: [] as $file) {
            if (preg_match('/create_(\w+)_table\.php$/', $file, $match)) {
                Schema::dropIfExists($match[1]);
                DB::table('migrations')->where('migration', basename($file, '.php'))->delete();
            }
        }

        if ($level > 0) {
            $db->beginTransaction();
        }

        (new Filesystem)->deleteDirectory($this->workDir);

        parent::tearDown();
    }
}
