<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Services\DeliveryQueue;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ConnectionReuseTest extends TestCase
{
    public function test_delivery_batch_reuses_its_pdo_outside_a_transaction(): void
    {
        // No RefreshDatabase transaction: the old releaseConnection guard only
        // disconnected real deployments, hiding the regression in ordinary tests.
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->seed();
        $company = Company::create(['plan_id' => Plan::where('name', 'Profissional')->value('id'),
            'name' => 'Reuse', 'slug' => 'reuse', 'modules' => array_keys(config('poseitech.modules')),
            'smtp' => ['host' => 'smtp.example.test', 'port' => 587, 'from' => 'test@example.test']]);
        for ($i = 0; $i < 3; $i++) {
            DB::table('email_logs')->insert(['company_id' => $company->id, 'recipient' => 'test@example.test',
                'subject' => 'Test', 'body' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        }
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $this->assertSame(0, $connection->transactionLevel());
        // Change only the connection's label, never its SQLite :memory: DSN.
        // This exercises the production branch of the former disconnect guard.
        $connection->setDatabaseName('isolated-reuse-test');
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('send')->times(3)->andReturnUsing(function () use ($connection, $pdo) {
            $this->assertSame($pdo, $connection->getRawPdo());
        });
        Mail::shouldReceive('build')->times(3)->andReturn($mailer);
        try {
            $this->assertSame(3, app(DeliveryQueue::class)->process('email'));
            $this->assertSame($pdo, $connection->getRawPdo());
            $this->assertSame(3, DB::table('email_logs')->where('status', 'sent')->count());
        } finally {
            $connection->setDatabaseName(':memory:');
        }
    }
}
