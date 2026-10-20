<?php

namespace Tests\Feature;

use App\Services\DatabaseCapacity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

class DatabaseCapacityTest extends TestCase
{
    public function test_connection_saturation_uses_a_short_pause_without_extending_it(): void
    {
        @unlink(DatabaseCapacity::path());
        config(['database_pool.saturation_cooldown' => 3]);
        foreach ([1040, 1203, 9001] as $code) {
            @unlink(DatabaseCapacity::path());
            $error = new PDOException('private');
            $error->errorInfo = ['HY000', $code, 'private'];
            DatabaseCapacity::pause($error);
            $until = file_get_contents(DatabaseCapacity::path());
            DatabaseCapacity::pause($error);
            $this->assertSame($until, file_get_contents(DatabaseCapacity::path()));
            $this->assertGreaterThan(0, DatabaseCapacity::remaining());
            $this->assertLessThanOrEqual(3, DatabaseCapacity::remaining());
        }
    }

    public function test_saturation_does_not_shorten_an_account_quota_pause(): void
    {
        @unlink(DatabaseCapacity::path());
        DatabaseCapacity::pause();
        $until = file_get_contents(DatabaseCapacity::path());
        $error = new PDOException('private');
        $error->errorInfo = ['HY000', 1040, 'private'];
        DatabaseCapacity::pause($error);
        $this->assertSame($until, file_get_contents(DatabaseCapacity::path()));
    }

    public function test_proxy_pool_wait_timeout_returns_503_without_reporting_sensitive_sql(): void
    {
        @unlink(DatabaseCapacity::path());
        Log::spy();
        Route::get('/pool-exhausted-test', function () {
            $pdo = new PDOException('secret-password');
            $pdo->errorInfo = ['HY000', 9001, 'secret-password'];
            throw new QueryException('mysql', 'insert into customers values (?)', ['private-customer'], $pdo);
        });
        $this->getJson('/pool-exhausted-test')->assertStatus(503)->assertDontSee('private-customer')->assertDontSee('secret-password');
        Log::shouldHaveReceived('warning')->once()->with('database.operation_failed', ['sqlstate' => 'HY000', 'driver_code' => 9001]);
        Log::shouldNotHaveReceived('error');
    }

    protected function tearDown(): void
    {
        @unlink(DatabaseCapacity::path());
        parent::tearDown();
    }

    public function test_database_quota_returns_a_safe_page_and_pauses_following_requests(): void
    {
        @unlink(DatabaseCapacity::path());
        Route::get('/capacity-test', function () {
            $pdo = new PDOException('Provider account details');
            $pdo->errorInfo = ['HY000', 1226, 'Provider account details'];
            throw new QueryException('mysql', 'select 1', [], $pdo);
        });
        $this->get('/capacity-test')->assertStatus(503)->assertSee('Uma pausa para retomar.')->assertDontSee('Provider account details')->assertHeader('Retry-After', '300');
        $executed = false;
        Route::get('/capacity-blocked', function () use (&$executed) {
            $executed = true;

            return 'unexpected';
        });
        $this->getJson('/capacity-blocked')->assertStatus(503)->assertJsonPath('status', 'unavailable');
        $this->assertFalse($executed);
    }
}
