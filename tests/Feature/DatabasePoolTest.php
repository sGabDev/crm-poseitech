<?php

namespace Tests\Feature;

use App\Database\ConnectionLifecycle;
use App\Database\MariaDbConnection;
use App\Database\MySqlConnection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use PDOStatement;
use Tests\TestCase;

class DatabasePoolTest extends TestCase
{
    public function test_manager_shares_one_lazy_connection_for_all_tenants(): void
    {
        config(['database.connections.pool-test' => array_replace(config('database.connections.mysql'), ['database' => 'unused'])]);
        $first = DB::connection('pool-test');
        $this->assertInstanceOf(MySqlConnection::class, $first);
        $this->assertSame($first, DB::connection('pool-test'));
        $this->assertInstanceOf(\Closure::class, $first->getRawPdo());
        $this->assertFalse($first->getConfig('options')[PDO::ATTR_PERSISTENT]);
        $this->assertGreaterThan(0, $first->getConfig('options')[PDO::ATTR_TIMEOUT]);
    }

    public function test_lost_write_acknowledgement_is_never_replayed(): void
    {
        foreach ([MySqlConnection::class, MariaDbConnection::class] as $class) {
            $error = new PDOException('server has gone away');
            $error->errorInfo = ['HY000', 2006, 'sensitive'];
            $statement = $this->createMock(PDOStatement::class);
            $statement->expects($this->once())->method('execute')->willThrowException($error);
            $pdo = $this->createMock(PDO::class);
            $pdo->expects($this->once())->method('prepare')->willReturn($statement);
            $connection = new $class($pdo, 'test');
            $connection->setReconnector(function () {
                $this->fail('An ambiguous write must not reconnect and replay.');
            });
            try {
                $connection->insert('insert into sales (id) values (1)');
                $this->fail('Expected database failure.');
            } catch (QueryException $caught) {
                $this->assertSame($error, $caught->getPrevious());
            }
        }
    }

    public function test_shutdown_rolls_back_before_releasing_and_does_not_open_unused_connections(): void
    {
        $connection = $this->createMock(MySqlConnection::class);
        $connection->method('getDriverName')->willReturn('mysql');
        $connection->method('transactionLevel')->willReturn(1);
        $rolledBack = false;
        $connection->expects($this->once())->method('rollBack')->with(0)->willReturnCallback(function () use (&$rolledBack) {
            $rolledBack = true;
        });
        $connection->expects($this->once())->method('disconnect')->willReturnCallback(function () use (&$rolledBack) {
            $this->assertTrue($rolledBack);
        });
        $manager = $this->createMock(DatabaseManager::class);
        $manager->expects($this->once())->method('getConnections')->willReturn([$connection]);
        $manager->expects($this->never())->method('connection');
        ConnectionLifecycle::release($manager);
    }

    public function test_shutdown_releases_even_when_rollback_fails(): void
    {
        $connection = $this->createMock(MySqlConnection::class);
        $connection->method('getDriverName')->willReturn('mysql');
        $connection->method('transactionLevel')->willReturn(1);
        $connection->method('rollBack')->willThrowException(new PDOException('secret'));
        $connection->expects($this->once())->method('disconnect');
        $manager = $this->createMock(DatabaseManager::class);
        $manager->method('getConnections')->willReturn([$connection]);
        ConnectionLifecycle::release($manager);
    }

    public function test_pool_generation_requires_confirmed_budget_and_generates_bounded_pool(): void
    {
        config(['database.default' => 'mysql', 'database.connections.mysql.url' => null,
            'database.connections.mysql.username' => 'test_user', 'database.connections.mysql.password' => 'test-secret',
            'database_pool.upstream_host' => 'mysql.internal', 'database_pool.size' => 10,
            'database_pool.instances' => 2, 'database_pool.reserve' => 5, 'database_pool.budget' => 24]);
        $this->artisan('poseitech:pool-config')->assertFailed();
        config(['database_pool.budget' => 25]);
        $oldStorage = storage_path();
        $testStorage = base_path('.runtime/pool-test-'.bin2hex(random_bytes(6)));
        app()->useStoragePath($testStorage);
        try {
            $this->artisan('poseitech:pool-config')->assertSuccessful();
            $path = storage_path('app/private/database-pool/proxysql.cnf');
            $contents = file_get_contents($path);
            $this->assertStringContainsString('max_connections=10;', $contents);
            $this->assertStringContainsString('connect_timeout_server_max=3000', $contents);
            $this->assertStringContainsString('default_query_timeout=10000', $contents);
            $this->assertStringContainsString('query_retries_on_failure=0', $contents);
            $this->assertStringContainsString('transaction_persistent=1', $contents);
            $this->assertStringContainsString('interfaces="127.0.0.1:6033"', $contents);
            $this->artisan('poseitech:pool-config')->assertFailed();
        } finally {
            @unlink($testStorage.'/app/private/database-pool/proxysql.cnf');
            foreach (['/app/private/database-pool', '/app/private', '/app', ''] as $suffix) {
                @rmdir($testStorage.$suffix);
            }
            app()->useStoragePath($oldStorage);
        }
    }
}
