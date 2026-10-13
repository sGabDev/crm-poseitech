<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Services\DeliveryQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DeliveryQueueTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->company = $this->company('fila-a');
    }

    private function company(string $slug): Company
    {
        return Company::create(['plan_id' => Plan::where('name', 'Profissional')->value('id'), 'name' => $slug, 'slug' => $slug, 'modules' => array_keys(config('poseitech.modules')), 'smtp' => ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'from' => 'loja@example.test']]);
    }

    private function email(int $company, array $extra = []): int
    {
        return DB::table('email_logs')->insertGetId($extra + ['company_id' => $company, 'recipient' => 'cliente@example.test', 'subject' => 'Comprovante', 'body' => 'Olá!', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_batches_rotate_companies_instead_of_draining_one_large_campaign(): void
    {
        $other = $this->company('fila-b');
        for ($i = 0; $i < 8; $i++) {
            $this->email($this->company->id);
        }
        $otherEmail = $this->email($other->id);
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('send')->times(6);
        Mail::shouldReceive('build')->times(6)->andReturn($mailer);
        $this->assertSame(5, app(DeliveryQueue::class)->process('email', 5));
        $this->assertSame(1, app(DeliveryQueue::class)->process('email', 1));
        $this->assertDatabaseHas('email_logs', ['id' => $otherEmail, 'status' => 'sent']);
        $this->assertSame(3, DB::table('email_logs')->where('status', 'pending')->count());
    }

    public function test_explicit_temporary_smtp_rejection_retries_only_after_backoff(): void
    {
        $id = $this->email($this->company->id);
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('send')->once()->andThrow(new \RuntimeException('421 Service unavailable'));
        $mailer->shouldReceive('send')->once()->andReturnNull();
        Mail::shouldReceive('build')->twice()->andReturn($mailer);
        app(DeliveryQueue::class)->process('email');
        $this->assertDatabaseHas('email_logs', ['id' => $id, 'status' => 'failed', 'attempts' => 1]);
        $this->assertNotNull(DB::table('email_logs')->find($id)->next_attempt_at);
        $this->assertSame(0, app(DeliveryQueue::class)->process('email'));
        $this->travel(61)->seconds();
        $this->assertSame(1, app(DeliveryQueue::class)->process('email'));
        $this->assertDatabaseHas('email_logs', ['id' => $id, 'status' => 'sent', 'attempts' => 2, 'next_attempt_at' => null]);
        $this->assertSame(0, app(DeliveryQueue::class)->process('email'));
    }

    public function test_ambiguous_smtp_timeout_is_not_automatically_replayed(): void
    {
        $id = $this->email($this->company->id);
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('send')->once()->andThrow(new \RuntimeException('Timeout waiting for acceptance after DATA'));
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        app(DeliveryQueue::class)->process('email');
        $this->travel(2)->hours();
        $this->assertSame(0, app(DeliveryQueue::class)->process('email'));
        $this->assertDatabaseHas('email_logs', ['id' => $id, 'status' => 'failed', 'next_attempt_at' => null, 'attempts' => 1]);
    }

    public function test_worker_partitions_process_only_their_companies_and_skip_future_items(): void
    {
        $other = $this->company('fila-c');
        $mine = $this->email($this->company->id);
        $theirs = $this->email($other->id);
        $future = $this->email($this->company->id, ['next_attempt_at' => now()->addHour()]);
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('send')->once();
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        app(DeliveryQueue::class)->process('email', 100, 45, $this->company->id % 2, 2);
        $this->assertDatabaseHas('email_logs', ['id' => $mine, 'status' => 'sent']);
        $this->assertDatabaseHas('email_logs', ['id' => $theirs, 'status' => 'pending']);
        $this->assertDatabaseHas('email_logs', ['id' => $future, 'status' => 'pending']);
    }

    public function test_single_run_worker_sends_pending_email_without_browser_action(): void
    {
        $id = $this->email($this->company->id);
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('send')->once();
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        $this->artisan('poseitech:deliver --once')->assertSuccessful();
        $this->assertDatabaseHas('email_logs', ['id' => $id, 'status' => 'sent']);
        $this->artisan('poseitech:deliver --once')->assertSuccessful();
    }

    public function test_background_processing_completes_campaigns_and_creates_alert_once(): void
    {
        $campaign = DB::table('campaigns')->insertGetId(['company_id' => $this->company->id, 'name' => 'Campanha teste', 'segment' => 'all', 'subject' => 'Novidades', 'body' => 'Olá!', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        $this->email($this->company->id, ['campaign_id' => $campaign]);
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('send')->once();
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        $this->artisan('poseitech:deliver --once')->assertSuccessful();
        $this->artisan('poseitech:deliver --once')->assertSuccessful();
        $this->assertDatabaseHas('campaigns', ['id' => $campaign, 'status' => 'completed']);
        $this->assertSame(1, DB::table('alerts')->where('key', 'campaign-'.$campaign)->count());
    }
}
