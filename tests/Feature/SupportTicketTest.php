<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->company = Company::create(['plan_id' => Plan::where('name', 'Profissional')->value('id'), 'name' => 'Empresa suporte', 'slug' => 'suporte-test', 'currency' => 'BRL', 'modules' => array_keys(config('poseitech.modules'))]);
        $this->owner = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'active' => true]);
        $this->actingAs($this->owner);
    }

    private function ticket(): int
    {
        $payload = ['subject' => 'Falha no atendimento', 'body' => '<script>alert(1)</script> Preciso de ajuda.', 'category' => 'technical', 'priority' => 'high', 'request_key' => (string) Str::uuid(), 'company_id' => 9999, 'author_role' => 'support'];
        $this->post('/support', $payload)->assertRedirect();
        $this->post('/support', $payload)->assertRedirect();
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseCount('support_messages', 1);

        return DB::table('support_tickets')->value('id');
    }

    public function test_company_opens_and_support_answers_with_unread_tracking_and_safe_history(): void
    {
        $id = $this->ticket();
        $this->assertDatabaseHas('support_tickets', ['id' => $id, 'company_id' => $this->company->id, 'user_id' => $this->owner->id]);
        $this->get('/support/'.$id)->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $super = User::factory()->create(['company_id' => null, 'role' => 'super', 'active' => true]);
        $this->actingAs($super)->get('/admin/tickets')->assertOk()->assertSee('Falha no atendimento');
        $this->get('/admin/tickets/'.$id)->assertOk();
        $this->assertDatabaseHas('support_tickets', ['id' => $id, 'support_unread' => false]);
        $reply = ['body' => 'Vamos verificar o problema.', 'request_key' => (string) Str::uuid()];
        $this->post('/admin/tickets/'.$id.'/reply', $reply)->assertRedirect();
        $this->post('/admin/tickets/'.$id.'/reply', $reply)->assertRedirect();
        $this->assertDatabaseCount('support_messages', 2);
        $this->assertDatabaseHas('support_tickets', ['id' => $id, 'status' => 'waiting_company', 'company_unread' => true]);
        $this->assertDatabaseHas('alerts', ['company_id' => $this->company->id, 'url' => '/support/'.$id]);
        $this->actingAs($this->owner)->get('/support/'.$id)->assertOk()->assertSee('Vamos verificar');
        $this->assertDatabaseHas('support_tickets', ['id' => $id, 'company_unread' => false]);
        $this->post('/support/'.$id.'/reply', ['body' => 'Obrigado!', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->assertDatabaseHas('support_tickets', ['id' => $id, 'status' => 'waiting_support', 'support_unread' => true]);
    }

    public function test_other_companies_cannot_read_or_change_tickets(): void
    {
        $id = $this->ticket();
        $other = Company::create(['plan_id' => $this->company->plan_id, 'name' => 'Outra', 'slug' => 'outra-suporte', 'modules' => []]);
        $this->actingAs(User::factory()->create(['company_id' => $other->id, 'role' => 'admin', 'active' => true]));
        $this->get('/support')->assertOk()->assertDontSee('Falha no atendimento');
        $this->get('/support/'.$id)->assertNotFound();
        $this->post('/support/'.$id.'/reply', ['body' => 'Intruso', 'request_key' => (string) Str::uuid()])->assertNotFound();
        $this->post('/support/'.$id.'/status', ['status' => 'closed'])->assertNotFound();
        $this->get('/admin/tickets')->assertForbidden();
        $this->assertDatabaseCount('support_messages', 1);
    }

    public function test_closed_tickets_require_reopening_and_support_can_assume_attendance(): void
    {
        $id = $this->ticket();
        $this->post('/support/'.$id.'/status', ['status' => 'in_progress'])->assertSessionHasErrors('status');
        $this->post('/support/'.$id.'/status', ['status' => 'closed'])->assertRedirect();
        $this->post('/support/'.$id.'/reply', ['body' => 'Nova resposta', 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('body');
        $this->post('/support/'.$id.'/status', ['status' => 'waiting_support'])->assertRedirect();
        $super = User::factory()->create(['company_id' => null, 'role' => 'super', 'active' => true]);
        $this->actingAs($super)->post('/admin/tickets/'.$id.'/status', ['status' => 'in_progress', 'assign' => 1])->assertRedirect();
        $this->assertDatabaseHas('support_tickets', ['id' => $id, 'assigned_to' => $super->id, 'status' => 'in_progress']);
    }

    public function test_inactive_company_can_seek_support_but_inactive_user_cannot(): void
    {
        $this->company->update(['status' => 'blocked']);
        $this->get('/settings')->assertForbidden();
        $this->get('/support/new')->assertOk();
        $this->ticket();
        $this->owner->update(['active' => false]);
        $this->get('/support')->assertForbidden();
    }

    public function test_settings_tabs_render_and_only_platform_controls_modules(): void
    {
        foreach (['company', 'modules', 'integrations', 'mail', 'team', 'automation', 'security', 'activity'] as $tab) {
            $this->get('/settings?tab='.$tab)->assertOk();
        }
        $this->get('/settings?tab=modules')->assertSee('name="integration_modules[]"', false);
        $original = $this->company->modules;
        $this->post('/settings', ['section' => 'connections'])->assertForbidden();
        $this->post('/settings', ['section' => 'modules', 'modules' => []])->assertForbidden();
        $this->assertSame($original, $this->company->fresh()->modules);
        $super = User::factory()->create(['company_id' => null, 'role' => 'super', 'active' => true]);
        $this->actingAs($super)->withSession(['support_company' => $this->company->id]);
        $this->post('/settings', ['section' => 'connections'])->assertRedirect();
        $this->assertEquals(array_values(array_diff($original, ['whatsapp', 'message_templates'])), $this->company->fresh()->modules);
        $this->post('/settings', ['section' => 'connections', 'integration_modules' => ['whatsapp', 'message_templates']])->assertRedirect();
        $this->assertContains('whatsapp', $this->company->fresh()->modules);
        $this->assertContains('message_templates', $this->company->fresh()->modules);
        $this->post('/settings', ['section' => 'connections', 'integration_modules' => ['sales']])->assertSessionHasErrors('integration_modules.0');
        $this->actingAs($this->owner);
        $this->owner->update(['role' => 'staff']);
        $this->post('/settings', ['section' => 'connections'])->assertForbidden();
    }

    public function test_attachments_are_private_scoped_and_duplicate_submissions_do_not_store_twice(): void
    {
        Storage::fake('local');
        $id = $this->ticket();
        $payload = ['request_key' => (string) Str::uuid(), 'attachments' => [UploadedFile::fake()->createWithContent('diagnostico.txt', 'Erro de conexão')]];
        $this->post('/support/'.$id.'/reply', $payload)->assertSessionHasNoErrors();
        $this->post('/support/'.$id.'/reply', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('support_attachments', 1);
        $file = DB::table('support_attachments')->first();
        Storage::disk('local')->assertExists($file->path);
        $this->get('/support/'.$id.'/files/'.$file->id)->assertOk()->assertDownload('diagnostico.txt');
        $this->getJson('/support/'.$id.'/history?after=1')->assertOk()->assertJsonFragment(['last' => $file->message_id]);
        $other = Company::create(['plan_id' => $this->company->plan_id, 'name' => 'Outra', 'slug' => 'outra-arquivo', 'modules' => []]);
        $this->actingAs(User::factory()->create(['company_id' => $other->id, 'role' => 'admin', 'active' => true]));
        $this->get('/support/'.$id.'/files/'.$file->id)->assertNotFound();
        $this->getJson('/support/'.$id.'/history?before=999')->assertNotFound();
    }

    public function test_chat_loads_latest_thirty_messages_and_older_history_by_cursor(): void
    {
        $id = $this->ticket();
        for ($i = 0; $i < 70; $i++) {
            DB::table('support_messages')->insert(['ticket_id' => $id, 'user_id' => $this->owner->id, 'author_name' => $this->owner->name, 'author_role' => 'company', 'body' => 'Mensagem sequencial '.$i, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->get('/support/'.$id)->assertOk()->assertSee('Mensagem sequencial 69')->assertDontSee('Mensagem sequencial 0')->assertViewHas('messages', fn ($messages) => $messages->count() === 30 && $messages->first()->id === 42);
        $this->getJson('/support/'.$id.'/history?before=42')->assertOk()->assertJsonPath('first', 12)->assertJsonPath('last', 41)->assertJsonPath('more', true);
        $this->getJson('/support/'.$id.'/history?before=12')->assertOk()->assertJsonPath('first', 1)->assertJsonPath('last', 11)->assertJsonPath('more', false);
        $this->getJson('/support/'.$id.'/history?after=70')->assertOk()->assertJsonPath('first', 71)->assertJsonPath('last', 71);
    }

    public function test_rejects_unsafe_or_oversized_files_and_empty_replies(): void
    {
        Storage::fake('local');
        $id = $this->ticket();
        foreach ([UploadedFile::fake()->createWithContent('ataque.php', '<?php echo 1;'), UploadedFile::fake()->create('grande.pdf', 5121, 'application/pdf')] as $file) {
            $this->post('/support/'.$id.'/reply', ['body' => 'Veja o arquivo', 'request_key' => (string) Str::uuid(), 'attachments' => [$file]])->assertSessionHasErrors('attachments.0');
        }
        $this->post('/support/'.$id.'/reply', ['request_key' => (string) Str::uuid(), 'attachments' => []])->assertSessionHasErrors('body');
        $this->assertDatabaseCount('support_attachments', 0);
        $this->assertDatabaseCount('support_messages', 1);
    }
}
