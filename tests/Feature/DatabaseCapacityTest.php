<?php

namespace Tests\Feature;

use App\Services\DatabaseCapacity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

class DatabaseCapacityTest extends TestCase
{
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
