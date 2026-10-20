<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseOperationsTest extends TestCase
{
    use RefreshDatabase;

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
