<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\Commerce;
use App\Services\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Tenant $tenant;

    private int $product;

    private string $state = 'pending';

    private array $payment = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['app.url' => 'https://crm.example.test']);
        Http::preventStrayRequests();
        $this->company = Company::create(['plan_id' => Plan::where('name', 'Profissional')->value('id'), 'name' => 'Loja catálogo', 'slug' => 'loja-catalogo', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo', 'modules' => array_keys(config('poseitech.modules')), 'settings' => ['catalog_checkout' => true, 'catalog_delivery_fee' => 500], 'mercadopago' => ['enabled' => true, 'mode' => 'production', 'access_token' => 'fake-token', 'collector_id' => '123', 'webhook_secret' => 'fake-secret']]);
        $owner = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'active' => true]);
        $this->actingAs($owner);
        $this->withCookie('catalog_visitor_'.$this->company->id, str_repeat('a', 64));
        $this->withCredentials();
        $this->tenant = app(Tenant::class);
        $this->tenant->company = $this->company;
        $this->product = $this->tenant->insert('products', ['name' => 'Hambúrguer artesanal', 'category' => 'Hambúrgueres', 'price' => 5000, 'cost' => 1000, 'stock' => 20, 'active' => true]);
        $this->tenant->insert('cash_registers', ['user_id' => $owner->id, 'opening' => 0]);
        Http::fake([
            '*/v1/payments' => function ($request) {
                $this->payment = ['id' => 987, 'external_reference' => $request['external_reference'], 'collector_id' => 123, 'transaction_amount' => $request['transaction_amount'], 'currency_id' => 'BRL', 'live_mode' => true, 'payment_method_id' => 'pix', 'status' => $this->state, 'point_of_interaction' => ['transaction_data' => ['qr_code' => '000201-CODIGO-PIX-TESTE', 'qr_code_base64' => 'aGVsbG8=']]];

                return Http::response($this->payment);
            },
            '*/v1/payments/987' => fn () => Http::response(array_replace($this->payment, ['status' => $this->state])),
        ]);
    }

    private function profile(array $extra = []): array
    {
        return array_replace(['name' => 'Ana Silva', 'document' => '529.982.247-25', 'email' => 'ana@example.test', 'phone' => '11999999999', 'address' => 'Rua Nova, 123', 'district' => 'Centro', 'city' => 'São Paulo / SP'], $extra);
    }

    private function order(array $extra = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'payment_method' => 'pix', 'fulfillment' => 'delivery', 'items' => [['product_id' => $this->product, 'quantity' => 2]]], $extra);
    }

    private function register(): void
    {
        $this->post('/catalog/loja-catalogo/register', $this->profile())->assertSessionHasNoErrors();
    }

    private function confirm(): void
    {
        $this->state = 'approved';
        $ts = (string) now()->getTimestampMs();
        $hash = hash_hmac('sha256', "id:987;request-id:catalog-test;ts:$ts;", 'fake-secret');
        $this->postJson('/integrations/mercadopago/'.$this->company->id.'/webhook?data.id=987&type=payment', ['type' => 'payment', 'data' => ['id' => '987']], ['x-request-id' => 'catalog-test', 'x-signature' => "ts=$ts,v1=$hash"])->assertOk();
    }

    public function test_registration_matches_cpf_only_within_company_and_updates_only_address(): void
    {
        $existing = $this->tenant->insert('customers', ['name' => 'Nome antigo privado', 'document' => '52998224725', 'phone' => '11888888888', 'email' => 'privado@example.test', 'address' => 'Endereço antigo']);
        $this->register();
        $this->register();
        $this->assertSame(1, $this->tenant->query('customers')->count());
        $this->assertDatabaseHas('customers', ['id' => $existing, 'name' => 'Nome antigo privado', 'email' => 'privado@example.test', 'address' => 'Rua Nova, 123']);
        $this->get('/catalog/loja-catalogo')->assertOk()->assertDontSee('privado@example.test')->assertDontSee('Nome antigo privado')->assertSee('Ana Silva');
    }

    public function test_registration_creates_new_customer_and_checkout_requires_registration_and_valid_cpf(): void
    {
        $this->post('/catalog/loja-catalogo', $this->order())->assertSessionHasErrors('registration');
        $this->post('/catalog/loja-catalogo/register', $this->profile(['document' => '11111111111']))->assertSessionHasErrors('document');
        $this->get('/catalog/loja-catalogo')->assertOk();
        $this->register();
        $this->assertDatabaseHas('customers', ['document_normalized' => '52998224725', 'source' => 'Catálogo online', 'district' => 'Centro']);
    }

    public function test_pix_is_immediate_idempotent_private_and_reserves_stock_without_trusting_prices(): void
    {
        $this->register();
        $payload = $this->order(['total' => 1, 'delivery_fee' => 0]);
        $this->post('/catalog/loja-catalogo', $payload)->assertRedirect('/catalog/loja-catalogo/orders/1');
        $this->post('/catalog/loja-catalogo', $payload)->assertRedirect('/catalog/loja-catalogo/orders/1');
        $this->assertDatabaseCount('catalog_orders', 1);
        $this->assertDatabaseCount('mp_checkouts', 1);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('catalog_orders', ['id' => 1, 'total' => 10500, 'delivery_fee' => 500, 'stock_reserved' => true]);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 18]);
        $this->get('/catalog/loja-catalogo/orders/1')->assertOk()->assertSee('000201-CODIGO-PIX-TESTE')->assertDontSee('fake-token');
        $this->getJson('/catalog/loja-catalogo/orders/1/status')->assertJsonPath('status', 'pending');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->hasHeader('X-Idempotency-Key') && $request['payment_method_id'] === 'pix');
        Http::assertSentCount(2);
        $this->withCookie('catalog_visitor_'.$this->company->id, str_repeat('b', 64))->get('/catalog/loja-catalogo/orders/1')->assertNotFound();
    }

    public function test_confirmed_pix_becomes_sale_without_duplicate_payment_or_second_stock_deduction(): void
    {
        $this->register();
        $payload = $this->order();
        $this->post('/catalog/loja-catalogo', $payload)->assertRedirect();
        $this->post('/catalog-orders/1', ['action' => 'prepare'])->assertSessionHasErrors('payment');
        $this->confirm();
        $this->confirm();
        $this->assertDatabaseCount('payments', 1);
        $this->getJson('/catalog/loja-catalogo/orders/1/status')->assertJsonPath('status', 'approved');
        $sale = app(Commerce::class)->sell(['request_key' => (string) Str::uuid(), 'catalog_order_id' => 1, 'items' => $payload['items'], 'payments' => [['method' => 'mercadopago', 'amount' => '105']], 'delivery' => true, 'address' => 'Rua Nova, 123', 'fee' => '5']);
        $this->assertDatabaseHas('sales', ['id' => $sale, 'paid' => 10500, 'total' => 10500, 'status' => 'completed']);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 18]);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('accounts', 1);
        $this->assertDatabaseHas('payments', ['sale_id' => $sale, 'amount' => 10500]);
    }

    public function test_cash_order_uses_registered_customer_without_reserving_or_creating_pix(): void
    {
        $this->register();
        $this->post('/catalog/loja-catalogo', $this->order(['payment_method' => 'cash', 'fulfillment' => 'pickup']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('catalog_orders', ['payment_method' => 'cash', 'total' => 10000, 'delivery_fee' => 0, 'stock_reserved' => false]);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 20]);
        $this->assertDatabaseCount('mp_checkouts', 0);
        Http::assertNothingSent();
    }

    public function test_wrong_provider_amount_never_confirms_or_credits_payment(): void
    {
        $this->register();
        $this->post('/catalog/loja-catalogo', $this->order())->assertRedirect();
        $this->payment['transaction_amount'] = 1;
        $this->confirm();
        $this->assertDatabaseHas('mp_checkouts', ['status' => 'review']);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_ambiguous_pix_creation_retries_with_same_key_and_immutable_body(): void
    {
        $this->register();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/v1/payments' => Http::failedConnection()]);
        $this->post('/catalog/loja-catalogo', $this->order())->assertRedirect('/catalog/loja-catalogo/orders/1');
        $this->assertDatabaseHas('mp_checkouts', ['status' => 'unknown']);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 18]);
        $this->get('/catalog/loja-catalogo/orders/1')->assertOk();
        $requests = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/v1/payments' => function ($request) use (&$requests) {
            $requests[] = ['key' => $request->header('X-Idempotency-Key'), 'body' => $request->data()];

            return Http::response([], 503);
        }]);
        $this->post('/catalog/loja-catalogo/orders/1/pix')->assertRedirect();
        $this->post('/catalog/loja-catalogo/orders/1/pix')->assertRedirect();
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0], $requests[1]);
        $this->assertDatabaseCount('mp_checkouts', 1);
        $this->assertDatabaseCount('catalog_orders', 1);
    }

    public function test_cpf_in_another_company_does_not_reuse_or_modify_that_customer(): void
    {
        $other = Company::create(['plan_id' => $this->company->plan_id, 'name' => 'Outra loja', 'slug' => 'outra-loja', 'modules' => []]);
        $customer = DB::table('customers')->insertGetId(['company_id' => $other->id, 'name' => 'Cliente de outra loja', 'document' => '52998224725', 'document_normalized' => '52998224725', 'address' => 'Endereço privado']);
        $this->register();
        $this->assertDatabaseHas('customers', ['id' => $customer, 'address' => 'Endereço privado']);
        $this->assertSame(1, $this->tenant->query('customers')->count());
    }

    public function test_expired_pix_is_confirmed_cancelled_before_stock_is_released(): void
    {
        $this->register();
        $this->post('/catalog/loja-catalogo', $this->order())->assertRedirect();
        $this->travel(31)->minutes();
        Http::fake(['*/v1/payments/987' => function ($request) {
            if ($request->method() === 'PUT') {
                $this->state = 'cancelled';
            }

            return Http::response(array_replace($this->payment, ['status' => $this->state]));
        }]);
        $this->artisan('poseitech:mercadopago')->assertSuccessful();
        $this->assertDatabaseHas('mp_checkouts', ['status' => 'cancelled']);
        $this->assertDatabaseHas('catalog_orders', ['id' => 1, 'status' => 'cancelled', 'stock_reserved' => false]);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 20]);
        $this->artisan('poseitech:mercadopago')->assertSuccessful();
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 20]);
    }

    public function test_payment_link_uses_unpredictable_reference_without_disclosing_registration_data(): void
    {
        $this->register();
        $this->post('/catalog/loja-catalogo', $this->order())->assertRedirect();
        $reference = DB::table('mp_checkouts')->value('reference');
        $this->withCookie('catalog_visitor_'.$this->company->id, str_repeat('c', 64));
        $this->get('/pay/'.$reference)->assertOk()->assertSee('000201-CODIGO-PIX-TESTE')->assertDontSee('52998224725')->assertDontSee('ana@example.test')->assertDontSee('Rua Nova, 123');
        $this->getJson('/pay/'.$reference.'/status')->assertOk()->assertJsonPath('status', 'pending');
        $this->get('/pay/'.Str::uuid())->assertNotFound();
    }
}
