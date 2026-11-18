<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait IsolatedSecurityDatabase
{
    protected function securityDatabase(): void
    {
        $database = getenv('NUERS_TEST_MYSQL_DATABASE');
        if ($database) {
            $mysql = getenv('DB_PORT') === '3308'
                && getenv('NUERS_TEST_MIGRATOR_SOCKET') === base_path('.local/mysql.sock');
            $maria = getenv('DB_PORT') === '3311'
                && getenv('DB_SOCKET') === base_path('.local/vapt-mariadb-2026-11-14/mysql.sock')
                && getenv('NUERS_TEST_MIGRATOR_SOCKET') === getenv('DB_SOCKET');
            if (! preg_match('/^nuers_vapt_test_[a-f0-9]+$/D', $database)
                || getenv('DB_HOST') !== '127.0.0.1' || (! $mysql && ! $maria)) {
                throw new \RuntimeException('Refusing a non-isolated security test database.');
            }
            config(['database.default' => 'mysql', 'database.connections.mysql.database' => $database]);
            DB::purge('mysql');
            config(['database.connections.vapt_migrator' => array_replace(config('database.connections.mysql'), ['username' => getenv('NUERS_TEST_MIGRATOR_USERNAME'), 'password' => getenv('NUERS_TEST_MIGRATOR_PASSWORD'), 'unix_socket' => getenv('NUERS_TEST_MIGRATOR_SOCKET')])]);
        } else {
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
            DB::purge('sqlite');
        }
    }

    protected function securityMigrate(): void
    {
        $options = ['--force' => true];
        if (getenv('NUERS_TEST_MYSQL_DATABASE')) {
            $options['--database'] = 'vapt_migrator';
        }
        $this->artisan('migrate:fresh', $options)->assertSuccessful();
    }
}
