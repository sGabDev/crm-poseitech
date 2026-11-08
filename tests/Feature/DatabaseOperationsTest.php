<?php

namespace Tests\Feature;

use App\Database\AccountLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_limits_extract_only_numeric_quotas(): void
    {
        $limits = AccountLimits::parse([(object) ['Grants' => "GRANT USAGE ON *.* TO 'private-account'@'private-host' WITH MAX_CONNECTIONS_PER_HOUR 500 MAX_USER_CONNECTIONS 75"], (object) ['Grants' => 'GRANT SELECT ON private_schema.* TO private_account WITH MAX_QUERIES_PER_HOUR 0 MAX_UPDATES_PER_HOUR 1000']]);
        $this->assertSame(['max_connections_per_hour' => 500, 'max_user_connections' => 75, 'max_queries_per_hour' => 0, 'max_updates_per_hour' => 1000], $limits);
        $this->assertSame([], AccountLimits::parse(['GRANT ALL PRIVILEGES ON private_schema.* TO private_account']));
    }

    public function test_capacity_indexes_can_be_reapplied_after_partial_deployment(): void
    {
        $migration = require database_path('migrations/2026_10_19_000011_database_capacity_indexes.php');
        $migration->down();
        $this->assertFalse(Schema::hasIndex('sales', 'sales_company_status_date'));
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasIndex('sales', 'sales_company_status_date'));
        $this->assertTrue(Schema::hasIndex('payments', 'payments_company_reversed_date'));
    }

    public function test_slow_query_alert_contains_only_duration_and_connection(): void
    {
        Log::spy();
        $connection = DB::connection();
        // Force the existing cumulative-duration handler without timing-sensitive sleeps.
        $connection->allowQueryDurationHandlersToRunAgain();
        $connection->logQuery('select ?', ['private-binding'], 1500);
        Log::shouldHaveReceived('warning')->once()->with('database.slow_request', \Mockery::on(
            fn ($context) => array_keys($context) === ['connection', 'duration_ms'] && $context['duration_ms'] >= 1500
        ));
    }
}
