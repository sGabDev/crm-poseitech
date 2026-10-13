<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\Commerce;
use App\Services\CustomerMerge;
use App\Services\PortalLink;
use App\Services\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerMergeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $t;

    private Company $company;

    private User $owner;

    private int $keep;

    private int $duplicate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->company = Company::create(['plan_id' => Plan::where('name', 'Profissional')->value('id'), 'name' => 'Empresa A', 'slug' => 'merge-a', 'currency' => 'BRL', 'modules' => array_keys(config('poseitech.modules'))]);
        $this->owner = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'active' => true]);
        $this->actingAs($this->owner);
        $this->t = app(Tenant::class);
        $this->t->company = $this->company;
        $this->keep = $this->t->insert('customers', ['name' => 'Ana Silva', 'email' => 'ana@example.test', 'phone' => '11999999999', 'tags' => 'VIP, Atacado', 'notes' => 'Cadastro principal', 'portal_hash' => hash('sha256', 'keep'), 'email_consent' => true]);
        $this->duplicate = $this->t->insert('customers', ['name' => 'Ana S.', 'email' => 'outro@example.test', 'phone' => '11888888888', 'document' => '12345678900', 'tags' => 'vip, Novo', 'notes' => 'Outra observação', 'portal_hash' => hash('sha256', 'duplicate'), 'email_consent' => false]);
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['duplicate_id' => $this->duplicate, 'version' => app(CustomerMerge::class)->preview($this->keep, $this->duplicate)['version'], 'confirmation' => 1], $extra);
    }

    private function sale(int $customer): int
    {
        if (! $this->t->query('cash_registers')->exists()) {
            $this->t->insert('cash_registers', ['user_id' => $this->owner->id, 'opening' => 0]);
        }
        $product = $this->t->insert('products', ['name' => 'Produto', 'price' => 10000, 'cost' => 1000, 'stock' => 10, 'active' => true]);

        return app(Commerce::class)->sell(['request_key' => (string) Str::uuid(), 'customer_id' => $customer, 'items' => [['product_id' => $product, 'quantity' => 1]], 'payments' => [['method' => 'pix', 'amount' => '50']]]);
    }

    public function test_compare_and_merge_preserves_financial_and_chat_history(): void
    {
        $this->sale($this->keep);
        $sale = $this->sale($this->duplicate);
        foreach ([$this->keep => 1000, $this->duplicate => 2000] as $customer => $amount) {
            $this->t->insert('wallet_entries', ['customer_id' => $customer, 'user_id' => $this->owner->id, 'amount' => $amount, 'description' => 'Depósito']);
        }
        $bot = $this->t->insert('whatsapp_connections', ['name' => 'Bot']);
        foreach ([$this->keep, $this->duplicate] as $customer) {
            $this->t->insert('whatsapp_conversations', ['connection_id' => $bot, 'customer_id' => $customer, 'jid' => $customer.'@s.whatsapp.net', 'tags' => json_encode(['Atendimento']), 'unread' => 2]);
        }
        $this->t->insert('whatsapp_messages', ['connection_id' => $bot, 'customer_id' => $this->duplicate, 'sale_id' => $sale, 'recipient' => '5511888888888', 'body' => 'Histórico', 'status' => 'sent']);
        $this->t->insert('template_deliveries', ['customer_id' => $this->duplicate, 'channel' => 'whatsapp', 'status' => 'sent', 'request_key' => (string) Str::uuid()]);
        $account = $this->t->query('accounts')->where('sale_id', $sale)->first();
        $this->t->insert('mp_checkouts', ['account_id' => $account->id, 'sale_id' => $sale, 'customer_id' => $this->duplicate, 'user_id' => $this->owner->id, 'reference' => (string) Str::uuid(), 'amount' => 5000, 'mode' => 'production', 'collector_id' => '1', 'expires_at' => now()->addDay()]);
        $before = DB::table('payments')->sum('amount');
        $this->get('/customers/'.$this->keep.'/merge?duplicate_id='.$this->duplicate)->assertOk()->assertSee('Confira os dois cadastros')->assertSee('Ana S.');
        $this->post('/customers/'.$this->keep.'/merge', $this->payload(['choices' => ['name' => 'keep', 'phone' => 'duplicate']]))->assertSessionHasNoErrors()->assertRedirect('/customers/'.$this->keep);
        $this->assertDatabaseMissing('customers', ['id' => $this->duplicate]);
        $customer = $this->t->find('customers', $this->keep);
        $this->assertSame('Ana Silva', $customer->name);
        $this->assertSame('11888888888', $customer->phone);
        $this->assertSame('12345678900', $customer->document);
        $this->assertSame('VIP, Atacado, Novo', $customer->tags);
        $this->assertStringContainsString('Outra observação', $customer->notes);
        $this->assertStringContainsString('outro@example.test', $customer->notes);
        $this->assertNull($customer->portal_hash);
        $this->assertFalse((bool) $customer->email_consent);
        foreach (CustomerMerge::TABLES as $table) {
            $this->assertSame(0, $this->t->query($table)->where('customer_id', $this->duplicate)->count(), $table);
        }
        $this->assertSame((int) $before, (int) DB::table('payments')->sum('amount'));
        $this->assertSame(3000, (int) $this->t->query('wallet_entries')->where('customer_id', $this->keep)->sum('amount'));
        $this->assertSame(10000, (int) $this->t->query('accounts')->where('customer_id', $this->keep)->selectRaw('SUM(amount-paid) as balance')->value('balance'));
        $this->assertDatabaseCount('whatsapp_conversations', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.merged', 'entity_id' => $this->keep]);
        $this->get('/customers/'.$this->keep)->assertOk();
    }

    public function test_campaign_history_is_kept_and_pending_duplicate_is_cancelled(): void
    {
        $campaign = $this->t->insert('campaigns', ['name' => 'Campanha', 'segment' => 'all', 'subject' => 'Olá', 'body' => 'Texto']);
        foreach ([$this->keep => 'sent', $this->duplicate => 'pending'] as $customer => $status) {
            $this->t->insert('email_logs', ['campaign_id' => $campaign, 'customer_id' => $customer, 'recipient' => 'ana@example.test', 'subject' => 'Olá', 'body' => 'Histórico', 'status' => $status]);
        }
        $this->post('/customers/'.$this->keep.'/merge', $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(2, $this->t->query('email_logs')->where('campaign_id', $campaign)->where('customer_id', $this->keep)->count());
        $this->assertDatabaseHas('email_logs', ['campaign_id' => $campaign, 'status' => 'sent']);
        $this->assertDatabaseHas('email_logs', ['campaign_id' => $campaign, 'status' => 'cancelled']);
    }

    public function test_duplicate_coupon_grants_preserve_used_history_without_extra_benefits(): void
    {
        $sale = $this->sale($this->keep);
        $coupon = $this->t->insert('coupons', ['code' => 'UNICO', 'type' => 'fixed', 'value' => 100, 'max_uses' => 100, 'expires_at' => now()->addMonth()->toDateString()]);
        $this->t->insert('coupon_grants', ['coupon_id' => $coupon, 'customer_id' => $this->keep, 'used_sale_id' => $sale]);
        $grant = $this->t->insert('coupon_grants', ['coupon_id' => $coupon, 'customer_id' => $this->duplicate]);
        $this->post('/customers/'.$this->keep.'/merge', $this->payload())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('coupon_grants', 2);
        $this->assertNotNull($this->t->find('coupon_grants', $grant)->revoked_at);
        $this->assertDatabaseHas('coupon_grants', ['customer_id' => $this->keep, 'used_sale_id' => $sale]);
    }

    public function test_stale_preview_refuses_merge_without_partial_updates(): void
    {
        $payload = $this->payload();
        $this->t->update('customers', $this->duplicate, ['name' => 'Nome atualizado']);
        $this->post('/customers/'.$this->keep.'/merge', $payload)->assertSessionHasErrors('merge');
        $this->assertDatabaseHas('customers', ['id' => $this->duplicate, 'name' => 'Nome atualizado']);
        $this->assertDatabaseHas('customers', ['id' => $this->keep, 'name' => 'Ana Silva']);
    }

    public function test_cross_company_ids_and_staff_cannot_merge(): void
    {
        $payload = $this->payload();
        $other = Company::create(['plan_id' => $this->company->plan_id, 'name' => 'Outra', 'slug' => 'merge-b', 'modules' => array_keys(config('poseitech.modules'))]);
        DB::table('customers')->where('id', $this->duplicate)->update(['company_id' => $other->id]);
        $this->post('/customers/'.$this->keep.'/merge', $payload)->assertNotFound();
        $staff = User::factory()->create(['company_id' => $this->company->id, 'role' => 'staff', 'active' => true, 'permissions' => ['customers.read', 'customers.write']]);
        $this->actingAs($staff);
        $this->get('/customers/'.$this->keep.'/merge')->assertForbidden();
        $this->post('/customers/'.$this->keep.'/merge', $payload)->assertForbidden();
        $this->assertDatabaseCount('customers', 2);
    }

    public function test_confirmation_required_and_same_or_anonymized_customer_rejected(): void
    {
        $this->post('/customers/'.$this->keep.'/merge', $this->payload(['confirmation' => 0]))->assertSessionHasErrors('confirmation');
        $this->get('/customers/'.$this->keep.'/merge?duplicate_id='.$this->keep)->assertSessionHasErrors('merge');
        $payload = $this->payload();
        $this->t->update('customers', $this->duplicate, ['anonymized_at' => now()]);
        $this->post('/customers/'.$this->keep.'/merge', $payload)->assertSessionHasErrors('merge');
        $this->assertDatabaseCount('customers', 2);
    }

    public function test_duplicate_submission_cannot_move_money_twice(): void
    {
        $this->t->insert('wallet_entries', ['customer_id' => $this->duplicate, 'user_id' => $this->owner->id, 'amount' => 2000, 'description' => 'Saldo']);
        $payload = $this->payload();
        $this->post('/customers/'.$this->keep.'/merge', $payload)->assertSessionHasNoErrors();
        $this->post('/customers/'.$this->keep.'/merge', $payload)->assertNotFound();
        $this->assertSame(2000, (int) $this->t->query('wallet_entries')->where('customer_id', $this->keep)->sum('amount'));
    }

    public function test_all_customer_relationships_are_covered_by_the_merge(): void
    {
        foreach (Schema::getTables() as $table) {
            if (Schema::hasColumn($table['name'], 'customer_id')) {
                $this->assertContains($table['name'], CustomerMerge::TABLES);
            }
        }
    }

    public function test_old_portal_links_are_revoked_and_inflight_messages_block_merge(): void
    {
        $oldKeep = PortalLink::token($this->t->find('customers', $this->keep));
        $oldDuplicate = PortalLink::token($this->t->find('customers', $this->duplicate));
        $payload = $this->payload();
        $email = $this->t->insert('email_logs', ['customer_id' => $this->duplicate, 'recipient' => 'outro@example.test', 'subject' => 'Compra', 'body' => 'Texto', 'status' => 'sending']);
        $this->post('/customers/'.$this->keep.'/merge', $payload)->assertSessionHasErrors('merge');
        $this->assertDatabaseCount('customers', 2);
        $this->t->update('email_logs', $email, ['status' => 'sent']);
        $this->post('/customers/'.$this->keep.'/merge', $this->payload())->assertSessionHasNoErrors();
        $this->get('/portal/access/'.$oldKeep)->assertNotFound();
        $this->get('/portal/access/'.$oldDuplicate)->assertNotFound();
    }
}
