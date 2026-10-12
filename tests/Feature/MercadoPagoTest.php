<?php

namespace Tests\Feature;

use App\Jobs\SendMercadoPagoLink;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\Commerce;
use App\Services\MercadoPago;
use App\Services\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MercadoPagoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Tenant $tenant;

    private int $customer;

    private int $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Http::preventStrayRequests();
        $this->company = Company::create(['plan_id' => Plan::where('name', 'Profissional')->value('id'), 'name' => 'Loja A', 'slug' => 'mp-a', 'currency' => 'BRL', 'modules' => array_keys(config('poseitech.modules')), 'mercadopago' => ['enabled' => true, 'mode' => 'production', 'access_token' => 'test-private-token', 'webhook_secret' => 'test-signature', 'collector_id' => '12345']]);
        $owner = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'active' => true]);
        $this->actingAs($owner);
        $this->tenant = app(Tenant::class);
        $this->tenant->company = $this->company;
        $this->customer = $this->tenant->insert('customers', ['name' => 'Ana', 'email' => 'ana@example.test', 'phone' => '11999999999']);
        $this->product = $this->tenant->insert('products', ['name' => 'Produto', 'price' => 10000, 'cost' => 1000, 'stock' => 20, 'active' => true]);
        $this->tenant->insert('cash_registers', ['user_id' => $owner->id, 'opening' => 0]);
    }

    private function saleData(): array
    {
        return ['request_key' => (string) Str::uuid(), 'customer_id' => $this->customer, 'items' => [['product_id' => $this->product, 'quantity' => 1]], 'payments' => [['method' => 'mercadopago', 'amount' => '100']], 'discount' => '0', 'extra' => '0'];
    }

    private function checkout(): object
    {
        Http::fake(['*/checkout/preferences' => Http::response(['id' => 'pref-1', 'init_point' => 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-1', 'sandbox_init_point' => 'https://sandbox.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-1'])]);
        $sale = app(Commerce::class)->sell($this->saleData());
        $account = $this->tenant->query('accounts')->where('sale_id', $sale)->first();

        return app(MercadoPago::class)->create($account->id);
    }

    private function payment(object $c, array $overrides = []): array
    {
        return array_replace(['id' => 98765, 'external_reference' => $c->reference, 'collector_id' => 12345, 'transaction_amount' => 100, 'currency_id' => 'BRL', 'live_mode' => true, 'status' => 'approved'], $overrides);
    }

    private function webhook(object $c, array $overrides = [])
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/v1/payments/98765' => Http::response($this->payment($c, $overrides))]);
        $ts = (string) now()->getTimestampMs();
        $hash = hash_hmac('sha256', "id:98765;request-id:request-1;ts:$ts;", 'test-signature');

        return $this->postJson('/integrations/mercadopago/'.$this->company->id.'/webhook?data.id=98765&type=payment', ['type' => 'payment', 'data' => ['id' => '98765']], ['x-request-id' => 'request-1', 'x-signature' => "ts=$ts,v1=$hash"]);
    }

    public function test_sale_is_pending_and_checkout_is_reused_without_crediting_or_sending_receipt(): void
    {
        $c = $this->checkout();
        $this->assertDatabaseHas('sales', ['id' => $c->sale_id, 'paid' => 0, 'status' => 'pending_payment', 'fiado_amount' => 0]);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('email_logs', 0);
        $this->assertSame($c->id, app(MercadoPago::class)->create($c->account_id)->id);
        Http::assertSentCount(1);
        $this->get('/sales/'.$c->sale_id)->assertOk()->assertSee('Aguardando pagamento')->assertSee('Gerar / abrir link');
        $this->get('/payments/mercadopago/'.$c->id)->assertOk()->assertSee('Link de pagamento');
    }

    public function test_signed_webhook_credits_once_and_ignores_client_reported_status(): void
    {
        $c = $this->checkout();
        auth()->logout();
        $this->webhook($c)->assertOk();
        $this->webhook($c)->assertOk();
        $this->assertDatabaseHas('sales', ['id' => $c->sale_id, 'paid' => 10000, 'status' => 'completed']);
        $this->assertDatabaseHas('accounts', ['id' => $c->account_id, 'paid' => 10000, 'status' => 'paid']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['method' => 'mercadopago', 'request_key' => $c->reference]);
        $this->assertDatabaseHas('alerts', ['key' => 'mp-'.$c->id.'-approved']);
    }

    public function test_bad_signature_never_fetches_payment(): void
    {
        $c = $this->checkout();
        $this->postJson('/integrations/mercadopago/'.$this->company->id.'/webhook', ['type' => 'payment', 'data' => ['id' => '98765']], ['x-signature' => 'ts=123,v1=invalid', 'x-request-id' => 'fake'])->assertUnauthorized();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_mismatched_amount_account_currency_and_mode_require_review(): void
    {
        $c = $this->checkout();
        foreach ([['transaction_amount' => 99], ['collector_id' => 888], ['currency_id' => 'USD'], ['live_mode' => false]] as $override) {
            $this->webhook($c, $override)->assertOk();
            $this->assertDatabaseHas('mp_checkouts', ['id' => $c->id, 'status' => 'review']);
            $this->assertDatabaseCount('payments', 0);
        }
    }

    public function test_test_mode_never_settles_real_debt(): void
    {
        $settings = $this->company->mercadopago;
        $settings['mode'] = 'test';
        $this->company->update(['mercadopago' => $settings]);
        $c = $this->checkout();
        $this->webhook($c, ['live_mode' => false])->assertOk();
        $this->assertDatabaseHas('mp_checkouts', ['id' => $c->id, 'status' => 'test_approved']);
        $this->assertDatabaseHas('sales', ['id' => $c->sale_id, 'paid' => 0]);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_public_return_parameters_cannot_mark_sale_paid_and_do_not_expose_customer(): void
    {
        $c = $this->checkout();
        auth()->logout();
        $this->get('/pay/'.$c->reference.'?status=approved&payment_id=98765')->assertOk()->assertSee('Pagar com Mercado Pago')->assertDontSee('ana@example.test');
        $this->assertDatabaseCount('payments', 0);
        $this->tenant->update('accounts', $c->account_id, ['paid' => 500]);
        $this->get('/pay/'.$c->reference)->assertOk()->assertDontSee('Pagar com Mercado Pago');
    }

    public function test_existing_debt_checkout_and_changed_balance_payment_never_overcredit(): void
    {
        $c = $this->checkout();
        app(Commerce::class)->settle($c->account_id, ['amount' => '20', 'method' => 'pix', 'request_key' => (string) Str::uuid()]);
        $this->webhook($c)->assertOk();
        $this->assertDatabaseHas('mp_checkouts', ['id' => $c->id, 'status' => 'review']);
        $this->assertDatabaseHas('sales', ['id' => $c->sale_id, 'paid' => 2000]);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_full_refund_reverses_exactly_once_and_partial_refund_requires_review(): void
    {
        $c = $this->checkout();
        $this->webhook($c)->assertOk();
        $this->webhook($c, ['status' => 'approved', 'transaction_amount_refunded' => 50])->assertOk();
        $this->assertDatabaseHas('sales', ['id' => $c->sale_id, 'paid' => 10000]);
        $this->assertDatabaseHas('mp_checkouts', ['id' => $c->id, 'status' => 'review']);
        $this->webhook($c, ['status' => 'refunded', 'transaction_amount_refunded' => 100])->assertOk();
        $this->webhook($c, ['status' => 'refunded', 'transaction_amount_refunded' => 100])->assertOk();
        $this->assertDatabaseHas('sales', ['id' => $c->sale_id, 'paid' => 0, 'status' => 'pending_payment']);
        $this->assertSame(0, (int) $this->tenant->query('cash_transactions')->sum('amount'));
        $this->assertNotNull($this->tenant->query('payments')->first()->reversed_at);
    }

    public function test_cancel_requires_provider_refund_before_local_cancellation(): void
    {
        $c = $this->checkout();
        $this->webhook($c)->assertOk();
        $this->post('/sales/'.$c->sale_id.'/cancel', ['reason' => 'Cancelamento pedido'])->assertSessionHasErrors();
        $this->assertDatabaseHas('sales', ['id' => $c->sale_id, 'status' => 'completed']);
    }

    public function test_link_email_and_whatsapp_delivery_are_idempotent(): void
    {
        $this->company->update(['smtp' => ['host' => 'smtp.example.test']]);
        $c = $this->checkout();
        $this->tenant->insert('whatsapp_connections', ['name' => 'Bot', 'automatic' => true]);
        config(['whatsapp.token' => 'fake', 'whatsapp.url' => 'http://baileys.test']);
        Http::fake(['*/chat-resolve' => Http::response(['jid' => '5511999999999@s.whatsapp.net']), '*/chat-send' => Http::response(['status' => 'sent', 'id' => 'message-1'])]);
        foreach (['email', 'whatsapp'] as $channel) {
            app(MercadoPago::class)->deliver($c, $channel);
            app(MercadoPago::class)->deliver($c, $channel);
        }
        $this->assertDatabaseCount('mp_deliveries', 2);
        $this->assertDatabaseCount('email_logs', 1);
        $email = $this->tenant->query('email_logs')->first();
        $this->assertNull($email->sale_id);
        $this->assertStringContainsString('/pay/'.$c->reference, $email->body);
        $this->assertDatabaseHas('mp_deliveries', ['channel' => 'whatsapp', 'status' => 'sent']);
        Http::assertSentCount(2);
    }

    public function test_unknown_creation_does_not_post_second_preference(): void
    {
        Http::fake(['*/checkout/preferences' => Http::response([], 503), '*/checkout/preferences/search*' => Http::response(['elements' => []])]);
        $sale = app(Commerce::class)->sell($this->saleData());
        $account = $this->tenant->query('accounts')->where('sale_id', $sale)->first();
        for ($i = 0; $i < 2; $i++) {
            try {
                app(MercadoPago::class)->create($account->id);
                $this->fail('Expected validation exception');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('mercadopago', $e->errors());
            }
        }
        Http::assertSentCount(2);
        $this->assertDatabaseCount('mp_checkouts', 1);
        $this->assertDatabaseHas('sales', ['id' => $sale, 'paid' => 0]);
    }

    public function test_credentials_are_encrypted_and_settings_do_not_render_secrets(): void
    {
        $raw = DB::table('companies')->where('id', $this->company->id)->value('mercadopago');
        $this->assertStringNotContainsString('test-private-token', $raw);
        $this->get('/settings')->assertOk()->assertSee('Mercado Pago')->assertDontSee('test-private-token')->assertDontSee('test-signature');
        Http::fake(['*/users/me' => Http::response(['id' => 12345])]);
        $this->from('/settings')->post('/settings/mercadopago', ['enabled' => 1, 'mode' => 'production'])->assertSessionHasNoErrors();
        $this->assertSame('test-private-token', $this->company->fresh()->mercadopago['access_token']);
    }

    public function test_other_company_cannot_read_or_send_checkout(): void
    {
        $c = $this->checkout();
        $other = Company::create(['plan_id' => $this->company->plan_id, 'name' => 'Outra', 'slug' => 'mp-b', 'modules' => array_keys(config('poseitech.modules'))]);
        $this->actingAs(User::factory()->create(['company_id' => $other->id, 'role' => 'admin', 'active' => true]));
        $this->get('/payments/mercadopago/'.$c->id)->assertNotFound();
        $this->post('/payments/mercadopago/'.$c->id, ['action' => 'email'])->assertNotFound();
    }

    public function test_debt_without_sale_can_be_paid_by_link(): void
    {
        $id = $this->tenant->insert('accounts', ['customer_id' => $this->customer, 'type' => 'receivable', 'description' => 'Mensalidade', 'amount' => 10000, 'due_date' => now()->toDateString()]);
        Http::fake(['*/checkout/preferences' => Http::response(['id' => 'debt-pref', 'init_point' => 'https://www.mercadopago.com.br/checkout/debt'])]);
        $this->post('/accounts/'.$id.'/mercadopago')->assertSessionHasNoErrors()->assertRedirect();
        $c = $this->tenant->query('mp_checkouts')->first();
        $this->assertNull($c->sale_id);
        $this->webhook($c)->assertOk();
        $this->assertDatabaseHas('accounts', ['id' => $id, 'paid' => 10000, 'status' => 'paid']);
    }

    public function test_automatic_sale_sends_email_and_dispatches_whatsapp_after_response(): void
    {
        Bus::fake([SendMercadoPagoLink::class]);
        $this->company->update(['smtp' => ['host' => 'smtp.example.test']]);
        $this->tenant->insert('whatsapp_connections', ['name' => 'Bot', 'automatic' => true]);
        Http::fake(['*/checkout/preferences' => Http::response(['id' => 'pref-1', 'init_point' => 'https://www.mercadopago.com.br/checkout/1'])]);
        $data = $this->saleData();
        $this->post('/sales', $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/sales', $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('mp_checkouts', 1);
        $this->assertDatabaseCount('email_logs', 1);
        Bus::assertDispatchedAfterResponse(SendMercadoPagoLink::class);
        $c = $this->tenant->query('mp_checkouts')->first();
        config(['whatsapp.token' => 'fake', 'whatsapp.url' => 'http://baileys.test']);
        Http::fake(['*/chat-resolve' => Http::response(['jid' => '5511999999999@s.whatsapp.net']), '*/chat-send' => Http::response(['status' => 'sent'])]);
        auth()->logout();
        (new SendMercadoPagoLink($this->company->id, $c->id))->handle($this->tenant, app(MercadoPago::class));
        $this->assertDatabaseHas('mp_deliveries', ['checkout_id' => $c->id, 'channel' => 'whatsapp', 'status' => 'sent']);
    }

    public function test_recovery_reads_full_preference_without_reposting(): void
    {
        $c = $this->checkout();
        $this->tenant->update('mp_checkouts', $c->id, ['status' => 'unknown', 'checkout_url' => null]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/checkout/preferences/search*' => Http::response(['elements' => [['id' => 'pref-1']]]), '*/checkout/preferences/pref-1' => Http::response(['id' => 'pref-1', 'collector_id' => 12345, 'external_reference' => $c->reference, 'init_point' => 'https://www.mercadopago.com.br/checkout/recovered'])]);
        $recovered = app(MercadoPago::class)->create($c->account_id);
        $this->assertSame('pending', $recovered->status);
        $this->assertStringContainsString('recovered', $recovered->checkout_url);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_provider_failure_is_retryable_and_pending_payment_never_credits(): void
    {
        $c = $this->checkout();
        $this->webhook($c, ['status' => 'pending'])->assertOk();
        $this->assertDatabaseCount('payments', 0);
        Http::swap(new Factory);
        Http::fake(['*/v1/payments/98765' => Http::response([], 503)]);
        $ts = (string) now()->getTimestampMs();
        $hash = hash_hmac('sha256', "id:98765;request-id:request-1;ts:$ts;", 'test-signature');
        $this->postJson('/integrations/mercadopago/'.$this->company->id.'/webhook?data.id=98765&type=payment', ['type' => 'payment'], ['x-request-id' => 'request-1', 'x-signature' => "ts=$ts,v1=$hash"])->assertStatus(503);
        $this->assertDatabaseCount('payments', 0);
    }
}
