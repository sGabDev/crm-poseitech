<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\Commerce;
use App\Services\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    private function sale(array $overrides = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'customer_id' => $this->customer, 'items' => [['product_id' => $this->product, 'quantity' => 2]], 'discount' => '0', 'extra' => '0',
            'payments' => [['method' => 'pix', 'amount' => '50']], 'due_date' => now()->addDays(15)->toDateString(), 'installments' => 2], $overrides);
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
        $this->assertEqualsCanonicalizing(['delivery', 'credit', 'orders', 'sales', 'products', 'customers'], $this->company->fresh()->modules);
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
        $this->assertDatabaseHas('customers', ['portal_hash' => hash('sha256', $token)]);
        $this->get('/portal/access/'.$token)->assertRedirect('/portal');
        $this->get('/portal')->assertOk()->assertSee('Cliente A')->assertSee('Produto A');
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
