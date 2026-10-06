<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\DataTableServiceProvider;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Runs on an in-memory SQLite by default. Set `DT_DB=mysql` or `DT_DB=pgsql` to run
 * the same tests on a real server, and `DT_DB_HOST`, `DT_DB_PORT`, `DT_DB_DATABASE`,
 * `DT_DB_USERNAME` and `DT_DB_PASSWORD` to point at it. The tables the tests create
 * are dropped after every test, so use a database that is only for this.
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [DataTableServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', self::connection());
    }

    protected function tearDown(): void
    {
        if (self::driver() !== 'sqlite') {
            Schema::dropAllTables();
        }

        parent::tearDown();
    }

    protected static function driver(): string
    {
        return (string) (getenv('DT_DB') ?: 'sqlite');
    }

    /**
     * @return array<string, mixed>
     */
    private static function connection(): array
    {
        $env = static fn (string $name, string $default): string => (string) (getenv("DT_DB_{$name}") ?: $default);

        return match (self::driver()) {
            'mysql' => [
                'driver'    => 'mysql',
                'host'      => $env('HOST', '127.0.0.1'),
                'port'      => (int) $env('PORT', '3306'),
                'database'  => $env('DATABASE', 'testing'),
                'username'  => $env('USERNAME', 'root'),
                'password'  => $env('PASSWORD', ''),
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => '',
            ],
            'pgsql' => [
                'driver'      => 'pgsql',
                'host'        => $env('HOST', '127.0.0.1'),
                'port'        => (int) $env('PORT', '5432'),
                'database'    => $env('DATABASE', 'testing'),
                'username'    => $env('USERNAME', 'postgres'),
                'password'    => $env('PASSWORD', ''),
                'charset'     => 'utf8',
                'prefix'      => '',
                'search_path' => 'public',
                'sslmode'     => 'disable',
            ],
            default => [
                'driver'   => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
            ],
        };
    }
}
