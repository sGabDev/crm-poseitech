<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\CashFlow;
use App\Services\Commerce;
use App\Services\CompanySmtp;
use App\Services\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommerceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private int $customer;

    private int $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->company = Company::create(['plan_id' => Plan::where('name', 'Profissional')->value('id'), 'name' => 'Empresa A', 'slug' => 'empresa-a', 'modules' => array_keys(config('poseitech.modules'))]);
        $this->owner = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'active' => true]);
        $this->actingAs($this->owner);
        $t = app(Tenant::class);
        $t->company = $this->company;
        $this->customer = $t->insert('customers', ['name' => 'Cliente A', 'email' => 'cliente@example.test', 'email_consent' => true]);
        $this->product = $t->insert('products', ['name' => 'Produto A', 'price' => 10000, 'cost' => 4000, 'stock' => 10, 'active' => true]);
    }

    private function sale(array $overrides = [], bool $openCash = true): array
    {
        if ($openCash && ! DB::table('cash_registers')->where('company_id', $this->company->id)->whereNull('closed_at')->exists()) {
            DB::table('cash_registers')->insert(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'opening' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        return array_replace(['request_key' => (string) Str::uuid(), 'customer_id' => $this->customer, 'items' => [['product_id' => $this->product, 'quantity' => 2]], 'discount' => '0', 'extra' => '0',
            'payments' => [['method' => 'pix', 'amount' => '50']], 'due_date' => now()->addDays(15)->toDateString(), 'installments' => 2], $overrides);
    }

    public function test_custom_items_and_explicit_negative_stock_confirmation(): void
    {
        $this->post('/sales', $this->sale(['items' => [['name' => 'Serviço avulso', 'price' => '15.50', 'quantity' => 2]], 'auto_payment' => 1]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sale_items', ['product_id' => null, 'name' => 'Serviço avulso', 'total' => 3100]);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 10]);
        $data = $this->sale(['items' => [['product_id' => $this->product, 'quantity' => 12]], 'auto_payment' => 1]);
        $this->post('/sales', $data)->assertSessionHasErrors();
        $this->post('/sales', $data + ['allow_negative_stock' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => -2]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stock.override']);
        $id = DB::table('sales')->latest('id')->value('id');
        $this->post('/sales/'.$id.'/cancel', ['reason' => 'Teste de devolução'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 10]);
    }

    public function test_coupon_is_earned_then_used_below_earning_threshold_and_archived(): void
    {
        $coupon = app(Tenant::class)->insert('coupons', ['code' => 'RECOMPENSA', 'type' => 'fixed', 'value' => 1000, 'minimum' => 15000, 'max_uses' => 20, 'expires_at' => today()->addMonth()->toDateString(), 'active' => true]);
        $this->post('/sales', $this->sale(['coupon' => 'RECOMPENSA']))->assertSessionHasErrors();
        $data = $this->sale();
        $this->post('/sales', $data)->assertSessionHasNoErrors();
        $this->post('/sales', $data)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('coupon_grants')->count());
        $grant = DB::table('coupon_grants')->first();
        $this->assertNotNull($grant->email_log_id);
        $this->post('/sales', $this->sale(['items' => [['product_id' => $this->product, 'quantity' => 1]], 'coupon' => 'RECOMPENSA', 'auto_payment' => 1]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', ['coupon_id' => $coupon, 'total' => 9000]);
        $this->assertNotNull(DB::table('coupon_grants')->value('used_sale_id'));
        $this->post('/sales', $this->sale(['coupon' => 'RECOMPENSA']))->assertSessionHasErrors();
        $this->get('/records/coupons')->assertOk()->assertSee('Usado na venda');
        $this->post('/records/coupons/'.$coupon.'/delete')->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('coupons')->where('id', $coupon)->value('deleted_at'));
        $this->assertSame(1, DB::table('coupon_grants')->count());
    }

    public function test_catalog_checkout_validates_prices_stock_and_converts_once(): void
    {
        $this->company->update(['settings' => ['catalog_checkout' => true]]);
        $payload = ['request_key' => (string) Str::uuid(), 'name' => 'Comprador online', 'phone' => '+55 (11) 99999-9999', 'address' => 'Rua do cliente, 123', 'items' => [['product_id' => $this->product, 'quantity' => 2, 'price' => 1]]];
        $this->get('/catalog/empresa-a')->assertOk()->assertSee('Enviar pedido à loja');
        $this->post('/catalog/empresa-a', $payload)->assertSessionHasNoErrors();
        $this->post('/catalog/empresa-a', $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('catalog_orders')->count());
        $order = DB::table('catalog_orders')->first();
        $this->assertSame(20000, $order->total);
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 10]);
        $this->get('/orders')->assertOk()->assertSee('Rua do cliente, 123');
        $this->post('/catalog-orders/'.$order->id, ['action' => 'prepare'])->assertRedirect('/sales/new');
        $this->post('/sales', $this->sale(['catalog_order_id' => $order->id, 'auto_payment' => 1]))->assertSessionHasNoErrors();
        $this->post('/sales', $this->sale(['catalog_order_id' => $order->id, 'auto_payment' => 1]))->assertSessionHasErrors();
        $this->assertDatabaseHas('catalog_orders', ['id' => $order->id, 'status' => 'converted']);
        $payload['request_key'] = (string) Str::uuid();
        $payload['items'][0]['quantity'] = 99;
        $this->post('/catalog/empresa-a', $payload)->assertSessionHasErrors('items');
        $this->company->update(['settings' => ['catalog_checkout' => false]]);
        $this->post('/catalog/empresa-a', $payload)->assertNotFound();
    }

    public function test_catalog_cannot_order_another_company_product(): void
    {
        $other = Company::create(['plan_id' => $this->company->plan_id, 'name' => 'Outra', 'slug' => 'outra', 'modules' => ['catalog', 'orders']]);
        $product = DB::table('products')->insertGetId(['company_id' => $other->id, 'name' => 'Privado', 'price' => 1, 'stock' => 100, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->company->update(['settings' => ['catalog_checkout' => true]]);
        $this->post('/catalog/empresa-a', ['request_key' => (string) Str::uuid(), 'name' => 'Teste', 'phone' => '11999999999', 'items' => [['product_id' => $product, 'quantity' => 1]]])->assertNotFound();
        $this->assertSame(0, DB::table('catalog_orders')->count());
    }

    public function test_category_lists_save_together_and_invalid_list_does_not_partially_save(): void
    {
        $this->post('/cash-flow/categories', ['categories_in' => "Aporte\nReembolso", 'categories_out' => "Aluguel\nImpostos"])->assertSessionHasNoErrors();
        $settings = $this->company->fresh()->settings;
        $this->assertSame(['Aporte', 'Reembolso'], $settings['flow_categories_in']);
        $this->assertSame(['Aluguel', 'Impostos'], $settings['flow_categories_out']);
        $this->post('/cash-flow/categories', ['categories_in' => 'Outra', 'categories_out' => str_repeat('a', 61)])->assertSessionHasErrors('categories_out');
        $this->assertSame($settings, $this->company->fresh()->settings);
        $this->get('/cash-flow')->assertOk()->assertSee('Salvar todas as categorias');
    }

    public function test_sale_automatically_queues_one_receipt_and_renders_html_and_text(): void
    {
        $d = $this->sale();
        $this->post('/sales', $d)->assertSessionHasNoErrors();
        $this->post('/sales', $d)->assertSessionHasNoErrors();
        $sale = DB::table('sales')->first();
        $this->assertSame(1, DB::table('email_logs')->where('sale_id', $sale->id)->count());
        $email = DB::table('email_logs')->where('sale_id', $sale->id)->first();
        $this->assertSame('pending', $email->status);
        $this->assertSame('cliente@example.test', $email->recipient);
        $this->company->update(['smtp' => ['host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls', 'from' => 'user@gmail.com', 'from_name' => 'Loja']]);
        $mailer = new Mailer('test', app('view'), new ArrayTransport, app('events'));
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        $this->post('/settings/mail', ['action' => 'send', 'id' => $email->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('email_logs', ['id' => $email->id, 'status' => 'sent']);
        $message = $mailer->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertStringContainsString('Produto A', $message->getHtmlBody());
        $this->assertStringContainsString('Abrir comprovante completo', $message->getHtmlBody());
        $this->assertStringContainsString('Valor em fiado desta compra', $message->getHtmlBody());
        $this->assertStringContainsString($sale->receipt_hash, $message->getTextBody());
        $this->post('/sales/'.$sale->id.'/email')->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('email_logs')->where('sale_id', $sale->id)->count());
    }

    public function test_failed_and_unidentified_sales_do_not_queue_receipts(): void
    {
        $this->post('/sales', $this->sale(['items' => [['product_id' => $this->product, 'quantity' => 99]]]))->assertSessionHasErrors();
        $this->assertSame(0, DB::table('email_logs')->count());
        $this->post('/sales', $this->sale(['customer_id' => null, 'auto_payment' => 1]))->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('email_logs')->count());
    }

    public function test_direction_categories_and_active_cash_flow_menu(): void
    {
        $this->post('/cash-flow/categories', ['categories_in' => 'Investimento', 'categories_out' => 'Aluguel'])->assertSessionHasNoErrors();
        $this->post('/cash-flow/categories', ['categories_in' => 'Investimento', 'categories_out' => 'Aluguel'])->assertSessionHasNoErrors();
        $data = ['direction' => 'in', 'category' => 'Aluguel', 'amount' => '10', 'method' => 'pix', 'description' => 'Teste', 'request_key' => (string) Str::uuid()];
        $this->post('/cash-flow', $data)->assertSessionHasErrors('category');
        $this->post('/cash-flow', array_replace($data, ['category' => 'Investimento']))->assertSessionHasNoErrors();
        $this->post('/cash-flow', array_replace($data, ['direction' => 'out', 'amount' => '3', 'request_key' => (string) Str::uuid()]))->assertSessionHasNoErrors();
        $this->get('/cash-flow')->assertOk()->assertSee('Diferença (recebido - gasto)')->assertSee('R$ 7,00')->assertSee('class="active" href="'.url('/cash-flow').'"', false)->assertDontSee('class="active" href="'.url('/cash').'"', false);
    }

    public function test_portal_fiado_filters_and_monthly_remaining_debt(): void
    {
        $this->travelTo(Carbon::parse('2026-08-10 12:00:00', 'America/Sao_Paulo'));
        $this->post('/sales', $this->sale())->assertSessionHasNoErrors();
        $older = DB::table('sales')->first();
        $this->travelTo(Carbon::parse('2026-09-10 12:00:00', 'America/Sao_Paulo'));
        $this->post('/sales', $this->sale())->assertSessionHasNoErrors();
        $newer = DB::table('sales')->orderByDesc('id')->first();
        $this->post('/customers/'.$this->customer.'/debt-payment', ['amount' => '170', 'method' => 'pix', 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post('/customers/'.$this->customer.'/portal')->assertRedirect();
        $this->get(session('portal_link'))->assertRedirect('/portal');
        $this->get('/portal?month=2026-09&kind=fiado')->assertOk()->assertViewHas('fiadoMonth', 15000)->assertViewHas('debtMonth', 13000)->assertViewHas('debtTotal', 13000)->assertSee($newer->receipt_hash)->assertDontSee($older->receipt_hash);
        $this->get('/portal?month=2026-09&scope=all&kind=fiado')->assertOk()->assertDontSee($older->receipt_hash)->assertSee($newer->receipt_hash)->assertDontSee('De todos os meses');
        $this->get('/portal?month=2026-08&kind=paid')->assertOk()->assertSee($older->receipt_hash)->assertDontSee($newer->receipt_hash);
        $this->get('/portal?scope=all&purchase='.$newer->id)->assertOk()->assertDontSee($older->receipt_hash);
        $this->travelBack();
    }

    public function test_receipt_identifies_wallet_payment_and_wallet_refund(): void
    {
        $this->post('/customer-deposits', ['customer_id' => $this->customer, 'amount' => '50', 'method' => 'pix', 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post('/sales', $this->sale(['auto_payment' => 1, 'use_balance' => 1]))->assertSessionHasNoErrors();
        $sale = DB::table('sales')->first();
        $this->get('/receipt/'.$sale->receipt_hash)->assertOk()->assertSee('Pago com saldo da conta')->assertSee('R$ 50,00')->assertSee('R$ 150,00');
        $this->post('/sales/'.$sale->id.'/cancel', ['reason' => 'Compra cancelada'])->assertSessionHasNoErrors();
        $this->get('/receipt/'.$sale->receipt_hash)->assertOk()->assertSee('Devolvido à conta');
    }

    public function test_backdated_flow_categories_filters_and_payment_totals(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 15:00:00', 'America/Sao_Paulo'));
        $this->post('/cash-flow/categories', ['categories_in' => 'Aporte', 'categories_out' => "Taxas\nInvestimentos"])->assertSessionHasNoErrors();
        $data = ['direction' => 'out', 'amount' => '15', 'method' => 'pix', 'category' => 'Taxas', 'description' => 'Taxa antiga', 'date' => '2026-08-10', 'request_key' => (string) Str::uuid()];
        $this->post('/cash-flow', $data)->assertSessionHasNoErrors()->assertRedirect('/cash-flow?month=2026-08');
        $entry = DB::table('flow_entries')->first();
        $this->assertSame('2026-08-10 03:00:00', $entry->occurred_at);
        $this->assertStringStartsWith('2026-09-28', $entry->created_at);
        $august = app(CashFlow::class)->month('2026-08', 'Taxas');
        $this->assertSame(1500, $august['outgoing']);
        $this->assertSame(1500, $august['methodTotals']['pix']['out']);
        $this->assertSame(-1500, app(CashFlow::class)->month('2026-09', 'Taxas')['opening']);
        $this->assertSame(0, app(CashFlow::class)->month('2026-08', 'Investimentos')['outgoing']);
        $this->get('/cash-flow?month=2026-08&category=Taxas')->assertOk()->assertSee('Taxa antiga')->assertSee('Recebido')->assertSee('Gasto');
        $this->post('/cash-flow/categories', ['categories_in' => 'Aporte', 'categories_out' => 'Investimentos'])->assertSessionHasNoErrors();
        $this->get('/cash-flow?month=2026-08&category=Taxas')->assertOk()->assertSee('Taxa antiga');
        $this->get('/cash-flow')->assertOk()->assertSee('value="2026-09-28"', false);
        $this->post('/cash-flow', array_replace($data, ['request_key' => (string) Str::uuid()]))->assertSessionHasErrors('category');
        $this->post('/cash-flow', array_replace($data, ['category' => 'Investimentos', 'date' => '2026-09-29', 'request_key' => (string) Str::uuid()]))->assertSessionHasErrors('date');
        $this->travelBack();
    }

    public function test_permanent_portal_link_is_stable_visible_and_revocable(): void
    {
        $this->post('/customers/'.$this->customer.'/portal')->assertRedirect();
        $link = session('portal_link');
        $this->post('/customers/'.$this->customer.'/portal')->assertRedirect();
        $this->assertSame($link, session('portal_link'));
        $this->get('/customers/'.$this->customer)->assertOk()->assertSee($link, false);
        $this->travel(400)->days();
        $this->get($link)->assertRedirect('/portal');
        $this->get('/portal')->assertOk();
        $token = basename($link);
        $tampered = substr($token, 0, -1).(str_ends_with($token, 'a') ? 'b' : 'a');
        $this->get('/portal/access/'.$tampered)->assertNotFound();
        $this->post('/customers/'.$this->customer.'/portal', ['action' => 'revoke'])->assertRedirect();
        $this->get($link)->assertNotFound();
        $this->get('/portal')->assertNotFound();
        $this->post('/customers/'.$this->customer.'/portal')->assertRedirect();
        $this->assertNotSame($link, session('portal_link'));
        $this->travelBack();
    }

    public function test_legacy_portal_links_remain_valid_without_expiration(): void
    {
        $token = Str::random(64);
        DB::table('customers')->where('id', $this->customer)->update(['portal_hash' => hash('sha256', $token), 'portal_expires_at' => now()->subDay()]);
        $this->get('/portal/access/'.$token)->assertRedirect('/portal');
        $this->get('/customers/'.$this->customer)->assertOk()->assertSee('Link permanente do portal');
    }

    public function test_portal_month_filter_and_paid_accounts_are_hidden(): void
    {
        $this->travelTo(Carbon::parse('2026-08-31 23:30:00', 'America/Sao_Paulo'));
        $this->post('/sales', $this->sale(['auto_payment' => 1]))->assertSessionHasNoErrors();
        $old = DB::table('sales')->first();
        $this->travelTo(Carbon::parse('2026-09-01 00:30:00', 'America/Sao_Paulo'));
        $this->post('/sales', $this->sale())->assertSessionHasNoErrors();
        $new = DB::table('sales')->orderByDesc('id')->first();
        $this->post('/customers/'.$this->customer.'/debt-payment', ['request_key' => (string) Str::uuid(), 'amount' => '150', 'method' => 'pix'])->assertSessionHasNoErrors();
        $this->post('/customers/'.$this->customer.'/portal')->assertRedirect();
        $this->get(session('portal_link'))->assertRedirect('/portal');
        $this->get('/portal?month=2026-09')->assertOk()->assertSee($new->receipt_hash)->assertDontSee($old->receipt_hash)->assertDontSee('Valores pendentes')->assertDontSee('Créditos e cashback')->assertDontSee('Saldo da conta')->assertDontSee('Extrato da conta')->assertDontSee('Produto A');
        $this->get('/portal?month=2026-08')->assertOk()->assertSee($old->receipt_hash)->assertDontSee($new->receipt_hash);
        $this->get('/portal?month=inválido')->assertSessionHasErrors('month');
        $this->travelBack();
    }

    public function test_every_sale_requires_an_open_register_even_wallet_or_fiado(): void
    {
        app(Tenant::class)->insert('wallet_entries', ['customer_id' => $this->customer, 'user_id' => $this->owner->id, 'amount' => 50000, 'description' => 'Saldo inicial']);
        foreach (['cash', 'pix', 'card', 'fiado'] as $method) {
            $this->post('/sales', $this->sale(['auto_payment' => 1, 'use_balance' => 1, 'payments' => [['method' => $method, 'amount' => '0']]], false))->assertSessionHasErrors('operation');
        }
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(10, DB::table('products')->value('stock'));
        $this->assertSame(50000, (int) DB::table('wallet_entries')->sum('amount'));
        $this->get('/sales/new')->assertOk()->assertSee('Abra o caixa antes');
        $this->post('/cash', ['action' => 'open', 'amount' => '0'])->assertSessionHasNoErrors();
        $this->post('/sales', $this->sale(['auto_payment' => 1, 'use_balance' => 1], false))->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('sales')->count());
    }

    public function test_statement_groups_same_day_and_mode_and_separates_directions(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 15:00:00', 'America/Sao_Paulo'));
        $t = app(Tenant::class);
        foreach ([1000, 2500, -500] as $amount) {
            $t->insert('flow_entries', ['user_id' => $this->owner->id, 'amount' => $amount, 'method' => 'pix', 'description' => 'Teste', 'category' => 'Ajuste', 'occurred_at' => now(), 'request_key' => (string) Str::uuid()]);
        }
        $t->insert('flow_entries', ['user_id' => $this->owner->id, 'amount' => 100, 'method' => 'cash', 'description' => 'Dinheiro', 'category' => 'Ajuste', 'occurred_at' => now(), 'request_key' => (string) Str::uuid()]);
        $this->travelTo(Carbon::parse('2026-09-11 15:00:00', 'America/Sao_Paulo'));
        $t->insert('flow_entries', ['user_id' => $this->owner->id, 'amount' => 800, 'method' => 'pix', 'description' => 'Outro dia', 'category' => 'Ajuste', 'occurred_at' => now(), 'request_key' => (string) Str::uuid()]);
        $data = app(CashFlow::class)->month('2026-09');
        $this->assertCount(3, $data['groups']);
        $pix = $data['groups']->first(fn ($g) => $g['method'] === 'pix' && $g['date'] === '2026-09-10');
        $this->assertSame(3500, $pix['incoming']);
        $this->assertSame(500, $pix['outgoing']);
        $this->assertSame(3000, $pix['difference']);
        $this->assertSame($data['incoming'], $data['groups']->sum('incoming'));
        $this->assertSame($data['outgoing'], $data['groups']->sum('outgoing'));
        $this->get('/cash-flow?month=2026-09')->assertOk()->assertSee('Diferença')->assertSee('data-flow-toggle', false);
        $this->travelBack();
    }

    public function test_gmail_configuration_corrects_port_scheme_and_password_spaces_without_leaking_secrets(): void
    {
        $smtp = ['host' => 'smtp.gmail.com', 'port' => 465, 'encryption' => 'tls', 'username' => 'user@gmail.com', 'password' => 'abcd efgh ijkl mnop', 'from' => 'user@gmail.com'];
        $config = CompanySmtp::config($smtp);
        $this->assertSame('smtps', $config['scheme']);
        $this->assertSame('abcdefghijklmnop', $config['password']);
        $this->assertSame('gmail.com', $config['local_domain']);
        $smtp['port'] = 587;
        $smtp['encryption'] = 'ssl';
        $this->assertSame('smtp', CompanySmtp::config($smtp)['scheme']);
        $message = CompanySmtp::failure(new \RuntimeException('535 authentication failed secret-password'));
        $this->assertStringContainsString('Autenticação', $message);
        $this->assertStringNotContainsString('secret-password', $message);
    }

    public function test_company_mail_renders_real_message_and_sender_without_external_smtp(): void
    {
        $this->company->update(['smtp' => ['host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'user@gmail.com', 'password' => 'app-password', 'from' => 'user@gmail.com', 'from_name' => 'Empresa']]);
        $id = app(Tenant::class)->insert('email_logs', ['recipient' => 'customer@example.test', 'subject' => 'Comprovante', 'body' => 'Conteúdo de teste']);
        $mailer = new Mailer('test', app('view'), new ArrayTransport, app('events'));
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        $this->post('/settings/mail', ['action' => 'send', 'id' => $id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('email_logs', ['id' => $id, 'status' => 'sent']);
        $sent = $mailer->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame('user@gmail.com', $sent->getFrom()[0]->getAddress());
        $this->assertStringContainsString('Conteúdo de teste', $sent->getHtmlBody());
    }

    public function test_flow_carries_prior_month_and_keeps_payment_and_later_refund_in_correct_months(): void
    {
        $this->travelTo(Carbon::parse('2026-08-20 12:00:00', $this->company->timezone));
        $this->post('/sales', $this->sale(['auto_payment' => 1]))->assertSessionHasNoErrors();
        $id = DB::table('sales')->value('id');
        $this->post('/cash', ['action' => 'close', 'amount' => '0'])->assertSessionHasNoErrors();
        $this->travelTo(Carbon::parse('2026-09-02 12:00:00', $this->company->timezone));
        $this->post('/cash', ['action' => 'open', 'amount' => '0'])->assertSessionHasNoErrors();
        $this->post('/sales/'.$id.'/cancel', ['reason' => 'Devolução posterior'])->assertSessionHasNoErrors();
        $flow = app(CashFlow::class);
        $august = $flow->month('2026-08');
        $september = $flow->month('2026-09');
        $this->assertSame(20000, $august['closing']);
        $this->assertSame(20000, $september['opening']);
        $this->assertSame(0, $september['incoming']);
        $this->assertSame(20000, $september['outgoing']);
        $this->assertSame(0, $september['closing']);
        $this->travelBack();
    }

    public function test_new_operations_are_scoped_to_company_and_permissions(): void
    {
        $other = Company::create(['plan_id' => $this->company->plan_id, 'name' => 'Outra empresa', 'slug' => 'isolada', 'modules' => []]);
        $foreignCustomer = DB::table('customers')->insertGetId(['company_id' => $other->id, 'name' => 'Cliente externo']);
        $foreignUser = User::factory()->create(['company_id' => $other->id]);
        $foreignMail = DB::table('email_logs')->insertGetId(['company_id' => $other->id, 'recipient' => 'externo@example.test', 'subject' => 'Externo', 'body' => 'Externo']);
        $this->post('/customer-deposits', ['customer_id' => $foreignCustomer, 'amount' => '10', 'method' => 'pix', 'request_key' => (string) Str::uuid()])->assertNotFound();
        $this->post('/staff/'.$foreignUser->id.'/action', ['action' => 'delete'])->assertNotFound();
        $this->company->update(['smtp' => ['host' => 'smtp.example.test']]);
        $this->post('/settings/mail', ['action' => 'send', 'id' => $foreignMail])->assertNotFound();
        $staff = User::factory()->create(['company_id' => $this->company->id, 'permissions' => ['cash.read']]);
        $this->actingAs($staff)->post('/settings/mail', ['action' => 'clear'])->assertForbidden();
        $this->post('/staff/'.$this->owner->id.'/action', ['action' => 'delete'])->assertForbidden();
        $this->post('/cash-flow', ['direction' => 'out', 'amount' => '10', 'method' => 'pix', 'category' => 'Teste', 'description' => 'Teste', 'request_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertSame(0, DB::table('customer_deposits')->count());
        $this->assertDatabaseHas('email_logs', ['id' => $foreignMail, 'status' => 'pending']);
    }

    public function test_mail_retry_all_requeues_failed_and_processes_pending_without_sending_cancelled(): void
    {
        $this->company->update(['smtp' => ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'from' => 'loja@example.test', 'from_name' => 'Loja']]);
        $t = app(Tenant::class);
        $id = $t->insert('email_logs', ['recipient' => 'cliente@example.test', 'subject' => 'Falhou', 'body' => 'Mensagem', 'status' => 'failed']);
        $cancelled = $t->insert('email_logs', ['recipient' => 'cliente@example.test', 'subject' => 'Cancelado', 'body' => 'Mensagem', 'status' => 'cancelled']);
        $this->post('/settings/mail', ['action' => 'retry'])->assertSessionHas('mail_run', true);
        $this->assertDatabaseHas('email_logs', ['id' => $id, 'status' => 'pending']);
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP indisponível'));
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        $this->postJson('/settings/mail', ['action' => 'process'])->assertOk()->assertJson(['processed' => true, 'status' => 'failed']);
        $this->postJson('/settings/mail', ['action' => 'process'])->assertOk()->assertJson(['processed' => false]);
        $this->assertDatabaseHas('email_logs', ['id' => $cancelled, 'status' => 'cancelled', 'attempts' => 0]);
    }

    public function test_deposit_pays_debt_keeps_excess_and_wallet_sale_does_not_double_cash_flow(): void
    {
        $this->post('/cash', ['action' => 'open', 'amount' => '100'])->assertSessionHasNoErrors();
        $this->post('/sales', $this->sale())->assertSessionHasNoErrors();
        $deposit = ['customer_id' => $this->customer, 'amount' => '200', 'method' => 'pix', 'request_key' => (string) Str::uuid()];
        $this->post('/customer-deposits', $deposit)->assertSessionHasNoErrors();
        $this->post('/customer-deposits', $deposit)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('customer_deposits')->count());
        $this->assertSame(15000, (int) DB::table('accounts')->sum('paid'));
        $this->assertSame(5000, (int) DB::table('wallet_entries')->sum('amount'));
        $this->post('/sales', $this->sale(['auto_payment' => 1, 'use_balance' => 1, 'payments' => [['method' => 'pix', 'amount' => '0']]]))->assertSessionHasNoErrors();
        $second = DB::table('sales')->orderByDesc('id')->first();
        $this->assertSame(5000, $second->wallet_used);
        $this->assertSame(20000, $second->paid);
        $this->assertSame(0, (int) DB::table('wallet_entries')->sum('amount'));
        $month = now($this->company->timezone)->format('Y-m');
        $flow = app(CashFlow::class)->month($month);
        $this->assertSame(40000, $flow['incoming']);
        $this->assertSame(0, $flow['outgoing']);
        $this->assertArrayHasKey('Caixa #1', $flow['sources']->all());
        $this->post('/sales/'.$second->id.'/cancel', ['reason' => 'Cliente desistiu'])->assertSessionHasNoErrors();
        $this->assertSame(5000, (int) DB::table('wallet_entries')->sum('amount'));
        $withdraw = ['direction' => 'out', 'amount' => '20', 'method' => 'pix', 'category' => 'Retirada', 'description' => 'Retirada do proprietário', 'request_key' => (string) Str::uuid()];
        $this->post('/cash-flow', $withdraw)->assertSessionHasNoErrors();
        $this->post('/cash-flow', $withdraw)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('flow_entries')->count());
        $flow = app(CashFlow::class)->month($month);
        $this->assertSame(17000, $flow['outgoing']);
        $this->assertSame(23000, $flow['closing']);
        $this->get('/cash-flow?month='.$month)->assertOk()->assertSee('Caixa #1')->assertSee('Retirada do proprietário');
        $this->get('/cash-flow?month='.$month.'&export=csv')->assertDownload('fluxo-'.$month.'.csv');
        $this->get('/customers/'.$this->customer)->assertOk()->assertSee('Saldo da conta');
    }

    public function test_deposit_partial_debt_and_failed_cash_deposit_are_atomic(): void
    {
        $this->post('/sales', $this->sale())->assertSessionHasNoErrors();
        $this->post('/cash', ['action' => 'close', 'amount' => '0'])->assertSessionHasNoErrors();
        $d = ['customer_id' => $this->customer, 'amount' => '20', 'method' => 'cash', 'request_key' => (string) Str::uuid()];
        $this->post('/customer-deposits', $d)->assertSessionHasErrors();
        $this->assertSame(0, DB::table('customer_deposits')->count());
        $this->assertSame(0, (int) DB::table('accounts')->sum('paid'));
        $this->post('/customer-deposits', array_replace($d, ['method' => 'pix']))->assertSessionHasNoErrors();
        $this->assertSame(2000, (int) DB::table('accounts')->sum('paid'));
        $this->assertSame(0, DB::table('wallet_entries')->count());
        $this->assertSame(1, DB::table('sales')->count());
    }

    public function test_percentage_adjustments_and_manually_received_amount_are_preserved(): void
    {
        $this->post('/sales', $this->sale(['discount' => '10%', 'extra' => '5%', 'auto_payment' => 0, 'payments' => [['method' => 'pix', 'amount' => '10']]]))->assertSessionHasNoErrors();
        $sale = DB::table('sales')->first();
        $this->assertSame(2000, $sale->discount);
        $this->assertSame(1000, $sale->extra);
        $this->assertSame(19000, $sale->total);
        $this->assertSame(1000, $sale->paid);
        $this->assertSame(18000, $sale->fiado_amount);
        $this->assertSame(1250, Commerce::adjustment('12,50', 20000));
        $this->post('/sales', $this->sale(['discount' => '101%']))->assertSessionHasErrors();
    }

    public function test_staff_deactivation_deletion_and_last_admin_protection(): void
    {
        $staff = User::factory()->create(['company_id' => $this->company->id, 'role' => 'staff']);
        $this->post('/staff/'.$staff->id.'/action', ['action' => 'deactivate'])->assertSessionHasNoErrors();
        $this->assertFalse($staff->fresh()->active);
        $this->post('/staff/'.$staff->id.'/action', ['action' => 'activate'])->assertSessionHasNoErrors();
        $this->assertTrue($staff->fresh()->active);
        $this->post('/staff/'.$staff->id.'/action', ['action' => 'delete'])->assertSessionHasNoErrors();
        $this->assertNotNull($staff->fresh()->deleted_at);
        $this->assertFalse($staff->fresh()->active);
        $this->post('/staff/'.$staff->id.'/action', ['action' => 'activate'])->assertNotFound();
        $this->post('/staff/'.$this->owner->id.'/action', ['action' => 'delete'])->assertSessionHasErrors();
        $this->assertTrue($this->owner->fresh()->active);
        $this->get('/settings')->assertOk()->assertDontSee($staff->email);
    }

    public function test_mail_queue_manual_send_does_not_resend_and_clear_preserves_sent(): void
    {
        $this->company->update(['smtp' => ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'from' => 'loja@example.test', 'from_name' => 'Loja']]);
        $t = app(Tenant::class);
        $id = $t->insert('email_logs', ['recipient' => 'cliente@example.test', 'subject' => 'Teste', 'body' => 'Mensagem']);
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('send')->once();
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        $this->post('/settings/mail', ['action' => 'send', 'id' => $id])->assertSessionHasNoErrors();
        $this->post('/settings/mail', ['action' => 'send', 'id' => $id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('email_logs', ['id' => $id, 'status' => 'sent', 'attempts' => 1]);
        $pending = $t->insert('email_logs', ['recipient' => 'cliente@example.test', 'subject' => 'Pendente', 'body' => 'Mensagem']);
        $this->post('/settings/mail', ['action' => 'clear'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('email_logs', ['id' => $pending, 'status' => 'cancelled']);
        $this->assertDatabaseHas('email_logs', ['id' => $id, 'status' => 'sent']);
        $this->postJson('/settings/mail', ['action' => 'process'])->assertOk()->assertJson(['processed' => false]);
    }

    public function test_team_creation_and_product_page_work_after_upgrade(): void
    {
        $this->post('/staff', ['name' => 'Equipe', 'email' => 'equipe@example.test', 'password' => 'SenhaInicial123', 'role' => 'staff', 'active' => 1, 'permissions' => ['products.read']])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'equipe@example.test', 'company_id' => $this->company->id, 'must_change_password' => true]);
        $this->get('/records/products')->assertOk()->assertSee('Produto A');
        $this->get('/sales/new')->assertOk()->assertSee('class="search-input"', false)->assertSee('Digite nome, telefone ou CPF')->assertSee('Digite o nome do produto ou serviço');
        $this->get('/settings')->assertOk()->assertSee('Módulos da empresa')->assertDontSee('Salvar módulos');
        $this->post('/settings', ['section' => 'modules', 'modules' => []])->assertForbidden();
    }

    public function test_missing_upgrade_columns_show_actionable_notice_instead_of_server_error(): void
    {
        Schema::table('products', fn ($table) => $table->dropColumn('deleted_at'));
        $this->get('/records/products')->assertStatus(503)->assertSee('Atualização do banco pendente');
        $migration = require database_path('migrations/2026_09_28_000002_password_and_product_deletion.php');
        $migration->up();
        $this->get('/records/products')->assertOk();
    }

    public function test_password_change_is_mandatory_and_validates_current_password(): void
    {
        $this->owner->update(['must_change_password' => true]);
        $this->get('/dashboard')->assertRedirect('/password/change');
        $this->post('/sales', $this->sale())->assertRedirect('/password/change');
        $this->get('/password/change')->assertOk()->assertSee('Antes de continuar');
        $this->post('/password/change', ['current_password' => 'wrong', 'password' => 'NovaSenha12345', 'password_confirmation' => 'NovaSenha12345'])->assertSessionHasErrors('current_password');
        $this->assertTrue($this->owner->fresh()->must_change_password);
        $this->post('/password/change', ['current_password' => 'password', 'password' => 'NovaSenha12345', 'password_confirmation' => 'NovaSenha12345'])->assertRedirect('/dashboard');
        $this->assertFalse($this->owner->fresh()->must_change_password);
        $this->assertTrue(Hash::check('NovaSenha12345', $this->owner->fresh()->password));
        $this->get('/dashboard')->assertOk();
    }

    public function test_deleted_product_cannot_be_sold_but_old_sale_can_be_cancelled(): void
    {
        $this->post('/sales', $this->sale())->assertSessionHasNoErrors();
        $sale = DB::table('sales')->value('id');
        $this->post('/records/products/'.$this->product.'/delete')->assertSessionHasNoErrors();
        $this->get('/records/products')->assertDontSee('Produto A');
        $this->get('/sales/new')->assertDontSee('Produto A');
        $this->post('/sales', $this->sale())->assertSessionHasErrors();
        $this->post('/sales/'.$sale.'/cancel', ['reason' => 'Cliente cancelou'])->assertSessionHasNoErrors();
        $this->assertSame(10, DB::table('products')->where('id', $this->product)->value('stock'));
    }

    public function test_customer_debt_accepts_partial_payment_across_sales_without_duplicates(): void
    {
        foreach ([1, 2] as $unused) {
            $this->post('/sales', $this->sale(['auto_payment' => 1, 'payments' => [['method' => 'fiado', 'amount' => 0]]]))->assertSessionHasNoErrors();
        }
        $this->assertSame(40000, (int) DB::table('accounts')->sum('amount'));
        $request = ['request_key' => (string) Str::uuid(), 'amount' => '220', 'method' => 'pix'];
        $this->post('/customers/'.$this->customer.'/debt-payment', $request)->assertSessionHasNoErrors();
        $this->post('/customers/'.$this->customer.'/debt-payment', $request)->assertSessionHasNoErrors();
        $this->assertSame(22000, (int) DB::table('accounts')->sum('paid'));
        $this->assertSame(1, DB::table('debt_receipts')->count());
        $this->assertSame(2, DB::table('payments')->count());
        $this->get('/credit?sort=recent')->assertOk()->assertSee('Cliente A')->assertSee('180,00');
        $this->get('/sales')->assertOk()->assertSee('Fiado')->assertDontSee('A receber')->assertDontSee('Pendente');
        $this->post('/customers/'.$this->customer.'/debt-payment', array_replace($request, ['request_key' => (string) Str::uuid(), 'amount' => '181']))->assertSessionHasErrors();
        $this->post('/cash', ['action' => 'close', 'amount' => '0'])->assertSessionHasNoErrors();
        $this->post('/customers/'.$this->customer.'/debt-payment', array_replace($request, ['request_key' => (string) Str::uuid(), 'amount' => '20', 'method' => 'cash']))->assertSessionHasErrors();
        $this->assertSame(1, DB::table('debt_receipts')->count());
        $this->post('/customers/'.$this->customer.'/debt-payment', array_replace($request, ['request_key' => (string) Str::uuid(), 'amount' => '180']))->assertSessionHasNoErrors();
        $this->get('/credit')->assertOk()->assertSee('Nenhum cliente com fiado em aberto.');
    }

    public function test_single_payment_uses_server_total_and_monthly_due_day_is_clamped(): void
    {
        $this->post('/sales', $this->sale(['auto_payment' => 1, 'payments' => [['method' => 'card', 'amount' => 0]]]))->assertSessionHasNoErrors();
        $this->assertSame(20000, DB::table('sales')->value('paid'));
        $this->assertSame(0, DB::table('accounts')->count());
        $commerce = app(Commerce::class);
        $this->travelTo(Carbon::parse('2028-02-10 12:00:00', $this->company->timezone));
        $this->assertSame('2028-02-29', $commerce->nextDueDate(31));
        $this->assertSame('2028-03-05', $commerce->nextDueDate(5));
        $this->assertSame('2028-02-10', $commerce->nextDueDate(10));
        $this->travelBack();
    }

    public function test_customer_phone_due_day_history_and_module_selection(): void
    {
        $this->post('/records/customers', ['name' => 'Telefone', 'phone' => '11999998888', 'due_day' => 12])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('customers', ['name' => 'Telefone', 'phone' => '+55 (11) 99999-8888', 'whatsapp' => '+55 (11) 99999-8888', 'due_day' => 12]);
        $this->post('/records/customers', ['name' => 'Inválido', 'due_day' => 32])->assertSessionHasErrors('due_day');
        $this->post('/sales', $this->sale())->assertSessionHasNoErrors();
        $this->get('/customers/'.$this->customer)->assertOk()->assertSee('Pago + Fiado')->assertDontSee('Conta do cliente / parcelas')->assertDontSee('Timeline de relacionamento')->assertDontSee('Privacidade e dados pessoais');
        $this->company->plan->update(['modules' => ['customers']]);
        $this->post('/settings', ['section' => 'modules', 'modules' => ['delivery']])->assertForbidden();
        $support = User::factory()->create(['role' => 'super', 'company_id' => null]);
        $this->actingAs($support)->withSession(['support_company' => $this->company->id]);
        $this->post('/settings', ['section' => 'modules', 'modules' => ['delivery', 'credit']])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['delivery', 'credit', 'orders', 'sales', 'products', 'customers', 'cash'], $this->company->fresh()->modules);
        $this->get('/orders')->assertOk();
        $this->get('/finance')->assertRedirect('/credit');
    }

    public function test_deletions_preserve_supplier_history_and_enforce_tenant_scope(): void
    {
        $t = app(Tenant::class);
        $supplier = $t->insert('suppliers', ['name' => 'Fornecedor antigo']);
        $account = $t->insert('accounts', ['supplier_id' => $supplier, 'type' => 'payable', 'description' => 'Compra', 'amount' => 1000, 'due_date' => now()->toDateString()]);
        $goal = $t->insert('goals', ['name' => 'Meta', 'metric' => 'revenue', 'target' => 100, 'starts_at' => now()->toDateString(), 'ends_at' => now()->addMonth()->toDateString()]);
        $this->post('/records/suppliers/'.$supplier.'/delete')->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('suppliers')->where('id', $supplier)->value('deleted_at'));
        $this->assertDatabaseHas('accounts', ['id' => $account, 'supplier_id' => $supplier]);
        $this->get('/records/suppliers')->assertDontSee('Fornecedor antigo');
        $this->post('/records/goals/'.$goal.'/delete')->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('goals', ['id' => $goal]);
        $other = Company::create(['plan_id' => $this->company->plan_id, 'name' => 'Outra', 'slug' => 'outra', 'modules' => []]);
        $foreign = DB::table('suppliers')->insertGetId(['company_id' => $other->id, 'name' => 'Protegido']);
        $this->post('/records/suppliers/'.$foreign.'/delete')->assertNotFound();
        $this->assertDatabaseHas('suppliers', ['id' => $foreign, 'deleted_at' => null]);
        $this->post('/records/customers/'.$this->customer.'/delete')->assertNotFound();
    }

    public function test_sale_installments_stock_settlement_and_cancellation_are_consistent(): void
    {
        $d = $this->sale();
        $this->post('/sales', $d)->assertRedirect();
        $sale = DB::table('sales')->first();
        $this->assertSame(20000, $sale->total);
        $this->assertSame(5000, $sale->paid);
        $this->assertSame(8, DB::table('products')->value('stock'));
        $this->assertSame(15000, (int) DB::table('accounts')->sum('amount'));
        $this->post('/sales', $d)->assertRedirect('/sales/'.$sale->id);
        $this->assertSame(1, DB::table('sales')->count());
        $account = DB::table('accounts')->first();
        $this->post('/accounts/'.$account->id.'/pay', ['amount' => '25.00', 'method' => 'pix'])->assertRedirect();
        $this->assertSame(7500, DB::table('sales')->value('paid'));
        $this->post('/accounts/'.$account->id.'/pay', ['amount' => '1000', 'method' => 'pix'])->assertSessionHasErrors();
        $this->post('/sales/'.$sale->id.'/cancel', ['reason' => 'Cliente desistiu da compra'])->assertRedirect();
        $this->assertSame(10, DB::table('products')->value('stock'));
        $this->assertSame('cancelled', DB::table('sales')->value('status'));
        $this->assertSame(0, DB::table('payments')->whereNull('reversed_at')->count());
        $this->assertSame(0, (int) DB::table('loyalty_transactions')->sum('points'));
    }

    public function test_failed_sale_rolls_back_every_entry_and_cannot_use_foreign_customer(): void
    {
        $this->post('/sales', $this->sale(['items' => [['product_id' => $this->product, 'quantity' => 11]]]))->assertSessionHasErrors();
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(10, DB::table('products')->value('stock'));
        $other = Company::create(['plan_id' => $this->company->plan_id, 'name' => 'B', 'slug' => 'b', 'modules' => config('poseitech.defaults')]);
        $foreign = DB::table('customers')->insertGetId(['company_id' => $other->id, 'name' => 'SEGREDO EMPRESA B', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/customers/'.$foreign)->assertNotFound();
        $this->post('/sales', $this->sale(['customer_id' => $foreign]))->assertNotFound();
        $this->get('/records/customers')->assertOk()->assertDontSee('SEGREDO EMPRESA B');
        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_disabled_modules_and_staff_write_permissions_are_enforced(): void
    {
        $staff = User::factory()->create(['company_id' => $this->company->id, 'role' => 'staff', 'permissions' => ['customers.read']]);
        $this->actingAs($staff)->get('/records/customers')->assertOk();
        $this->post('/records/customers', ['name' => 'Proibido'])->assertForbidden();
        $this->get('/sales')->assertForbidden();
        $this->get('/settings')->assertOk()->assertSee('Minha senha')->assertSee('Módulos da empresa')->assertDontSee('Salvar módulos');
        $this->get('/admin')->assertForbidden();
        $this->company->update(['modules' => config('poseitech.defaults')]);
        $this->actingAs($this->owner)->get('/orders')->assertForbidden();
        $this->get('/dashboard')->assertOk()->assertDontSee('href="'.url('/orders').'"', false);
    }

    public function test_cash_cannot_be_opened_twice_and_electronic_payments_do_not_inflate_cash(): void
    {
        $this->post('/cash', ['action' => 'open', 'amount' => '100'])->assertRedirect();
        $this->post('/cash', ['action' => 'open', 'amount' => '100'])->assertSessionHasErrors();
        $this->post('/sales', $this->sale(['payments' => [['method' => 'cash', 'amount' => '50'], ['method' => 'pix', 'amount' => '150']]]))->assertRedirect();
        $this->post('/cash', ['action' => 'close', 'amount' => '145'])->assertRedirect();
        $register = DB::table('cash_registers')->first();
        $this->assertSame(15000, $register->expected);
        $this->assertSame(-500, $register->difference);
        $this->assertDatabaseHas('alerts', ['key' => 'cash-'.$register->id]);
    }

    public function test_portal_token_is_hashed_scoped_and_revocable(): void
    {
        $this->post('/sales', $this->sale())->assertRedirect();
        $response = $this->post('/customers/'.$this->customer.'/portal');
        $link = session('portal_link');
        $response->assertRedirect();
        $token = basename($link);
        $this->assertNotSame($token, DB::table('customers')->where('id', $this->customer)->value('portal_hash'));
        $this->get('/portal/access/'.$token)->assertRedirect('/portal');
        $this->get('/portal')->assertOk()->assertSee('Cliente A')->assertSee('Ver comprovante')->assertDontSee('Produto A');
        $this->post('/customers/'.$this->customer.'/portal', ['action' => 'revoke'])->assertRedirect();
        $this->get('/portal')->assertNotFound();
        $this->get('/portal/access/'.$token)->assertNotFound();
    }

    public function test_campaign_queue_respects_consent_and_does_not_duplicate(): void
    {
        $this->company->update(['smtp' => ['host' => 'smtp.example.test', 'password' => 'secret-value']]);
        $this->assertStringNotContainsString('secret-value', DB::table('companies')->value('smtp'));
        DB::table('customers')->insert(['company_id' => $this->company->id, 'name' => 'Sem consentimento', 'email' => 'nao@example.test', 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/campaigns', ['name' => 'Reativação', 'segment' => 'all', 'days' => 30, 'minimum_ticket' => '0', 'subject' => 'Olá', 'body' => 'Olá {nome}!'])->assertRedirect();
        $id = DB::table('campaigns')->value('id');
        $this->post('/campaigns/'.$id.'/send')->assertRedirect();
        $this->assertSame(1, DB::table('email_logs')->count());
        $this->post('/campaigns/'.$id.'/send')->assertStatus(422);
        $this->assertSame(1, DB::table('email_logs')->count());
        $this->get('/settings')->assertOk()->assertDontSee('secret-value');
    }

    public function test_all_core_pages_render_with_business_data(): void
    {
        $this->post('/sales', $this->sale(['order' => 1, 'delivery' => 1, 'address' => 'Rua Teste, 10', 'fee' => '5']))->assertRedirect();
        foreach (['dashboard', 'opportunities', 'records/customers', 'records/products', 'records/products/new', 'records/suppliers', 'records/coupons', 'records/goals', 'sales', 'sales/new', 'sales/1', 'customers/'.$this->customer, 'cash', 'credit', 'stock', 'orders', 'reports', 'search?q=Produto', 'alerts', 'campaigns', 'settings', 'catalog/empresa-a'] as $path) {
            $this->get('/'.$path)->assertOk();
        }
        $this->get('/receipt/'.DB::table('sales')->value('receipt_hash'))->assertOk();
        $this->get('/reports?export=csv')->assertOk()->assertDownload('vendas.csv');
    }

    public function test_registration_and_platform_support_are_separate(): void
    {
        auth()->logout();
        $this->post('/register', ['company' => 'Nova empresa', 'name' => 'Novo dono', 'email' => 'novo@example.test', 'password' => 'SenhaSegura123', 'password_confirmation' => 'SenhaSegura123'])->assertRedirect('/dashboard');
        $new = User::where('email', 'novo@example.test')->firstOrFail();
        $this->assertNotSame($new->company_id, $this->company->id);
        $super = User::factory()->create(['role' => 'super', 'company_id' => null]);
        $this->actingAs($super)->get('/admin')->assertOk();
        $this->post('/admin/support/'.$this->company->id, ['reason' => 'Verificação técnica'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk()->assertSee('Acesso de suporte ativo');
        $this->post('/admin/leave')->assertRedirect('/admin');
        $this->assertDatabaseHas('audit_logs', ['action' => 'support.started', 'user_id' => $super->id]);
    }

    public function test_coupon_addons_and_cashback_use_server_prices(): void
    {
        $this->company->update(['settings' => ['loyalty_mode' => 'cashback', 'loyalty_rate' => 5]]);
        DB::table('products')->where('id', $this->product)->update(['addons' => json_encode([['name' => 'Embalagem', 'price' => 250]])]);
        DB::table('coupons')->insert(['company_id' => $this->company->id, 'code' => 'DEZ', 'type' => 'percent', 'value' => 1000, 'minimum' => 0, 'max_uses' => 1, 'expires_at' => now()->addDay()->toDateString(), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/sales', $this->sale(['items' => [['product_id' => $this->product, 'quantity' => 1, 'addons' => [0], 'price' => 1]], 'coupon' => 'DEZ', 'payments' => [['method' => 'pix', 'amount' => '92.25']], 'installments' => 1]))->assertRedirect();
        $this->assertSame(9225, DB::table('sales')->value('total'));
        $this->assertSame(461, (int) DB::table('customer_credits')->sum('amount'));
        $this->post('/sales', $this->sale(['coupon' => 'DEZ']))->assertSessionHasErrors();
        $this->assertSame(1, DB::table('sales')->count());
        $this->post('/sales/1/cancel', ['reason' => 'Cancelamento solicitado'])->assertRedirect();
        $this->assertSame(0, (int) DB::table('customer_credits')->sum('amount'));
        $this->assertSame(0, DB::table('coupons')->value('uses'));
    }

    public function test_repeated_receipt_request_is_idempotent_and_limits_are_enforced(): void
    {
        $this->post('/sales', $this->sale())->assertRedirect();
        $id = DB::table('accounts')->value('id');
        $request = ['request_key' => (string) Str::uuid(), 'amount' => '10', 'method' => 'pix'];
        $this->post('/accounts/'.$id.'/pay', $request)->assertRedirect();
        $this->post('/accounts/'.$id.'/pay', $request)->assertRedirect();
        $this->assertSame(1000, DB::table('accounts')->where('id', $id)->value('paid'));
        $this->company->plan->update(['customers_limit' => 1]);
        $this->post('/records/customers', ['name' => 'Excede plano'])->assertSessionHasErrors();
        $this->assertSame(1, DB::table('customers')->count());
    }

    public function test_anonymization_revokes_access_and_removes_personal_data(): void
    {
        $this->post('/customers/'.$this->customer.'/portal')->assertRedirect();
        $link = session('portal_link');
        $this->post('/sales', $this->sale())->assertRedirect();
        $this->post('/customers/'.$this->customer.'/privacy', ['action' => 'anonymize', 'confirmation' => 'ANONIMIZAR'])->assertRedirect();
        $this->assertDatabaseHas('customers', ['id' => $this->customer, 'email' => null, 'portal_hash' => null, 'email_consent' => false]);
        $this->assertSame(1, DB::table('sales')->count());
        $this->get('/portal/access/'.basename($link))->assertNotFound();
    }

    public function test_blocked_company_and_forged_settings_are_rejected(): void
    {
        $this->post('/settings', ['section' => 'modules', 'modules' => ['unknown']])->assertForbidden();
        $this->company->update(['status' => 'blocked']);
        $this->get('/dashboard')->assertForbidden();
        $this->get('/catalog/empresa-a')->assertNotFound();
        $this->post('/sales', $this->sale())->assertForbidden();
        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_auth_views_and_csv_exports_render_without_exposing_private_fields(): void
    {
        foreach (['sales', 'customers', 'products', 'stock', 'cash', 'finance', 'credit', 'payments', 'campaigns', 'loyalty'] as $report) {
            $this->get('/reports?export=csv&report='.$report)->assertOk();
        }
        $this->get('/records/products/'.$this->product.'/edit')->assertOk();
        $this->post('/stock', ['product_id' => $this->product, 'type' => 'adjustment', 'quantity' => 0, 'notes' => 'Inventário sem saldo'])->assertRedirect();
        $this->assertSame(0, DB::table('products')->value('stock'));
        auth()->logout();
        foreach (['/login', '/register', '/forgot-password', '/reset-password/example-token'] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
