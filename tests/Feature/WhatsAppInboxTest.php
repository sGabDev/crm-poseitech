<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WhatsAppInboxTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private int $bot;

    private int $chat;

    private string $base = '/whatsapp/atendimento';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->company = Company::create(['plan_id' => Plan::where('name', 'Profissional')->value('id'), 'name' => 'Empresa Inbox', 'slug' => 'inbox', 'modules' => array_keys(config('poseitech.modules'))]);
        $this->owner = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'active' => true]);
        $this->actingAs($this->owner);
        app(Tenant::class)->company = $this->company;
        $this->bot = app(Tenant::class)->insert('whatsapp_connections', ['name' => 'Atendimento']);
        $this->chat = app(Tenant::class)->insert('whatsapp_conversations', ['connection_id' => $this->bot, 'jid' => '5511999999999@s.whatsapp.net', 'phone' => '5511999999999', 'name' => 'Cliente']);
        config(['whatsapp.token' => str_repeat('a', 32)]);
        Storage::fake('local');
    }

    private function event(string $id, int $sequence, array $extra = []): array
    {
        return array_replace(['type' => 'message', 'sequence' => $sequence, 'id' => $id, 'jid' => '5511999999999@s.whatsapp.net', 'phone' => '5511999999999', 'name' => 'Cliente', 'fromMe' => false, 'body' => 'Olá '.$id, 'kind' => 'text', 'timestamp' => time(), 'historical' => false], $extra);
    }

    public function test_inbox_renders_and_imports_idempotently_reopens_resolved_and_handles_status(): void
    {
        $this->get($this->base)->assertOk()->assertSee('Central de atendimento')->assertSee('Nota interna');
        app(Tenant::class)->update('whatsapp_conversations', $this->chat, ['status' => 'resolved']);
        Http::fake(['*' => Http::response(['events' => [$this->event('a', 1), $this->event('old', 2, ['historical' => true, 'timestamp' => time() - 3600]), $this->event('sent', 3, ['fromMe' => true]), ['type' => 'status', 'sequence' => 4, 'jid' => '5511999999999@s.whatsapp.net', 'id' => 'sent', 'status' => 'read']], 'cursor' => 4, 'status' => 'connected'])]);
        $response = $this->postJson($this->base.'/state', ['selected' => $this->chat, 'sync' => true])->assertOk()->assertJsonPath('selected.unread', 1)->assertJsonPath('selected.status', 'open')->assertJsonPath('connection.status', 'connected');
        $this->assertSame('old', $response->json('messages.0.remote_id'));
        $this->assertDatabaseHas('whatsapp_chat_messages', ['remote_id' => 'sent', 'status' => 'read']);
        $this->assertDatabaseHas('whatsapp_connections', ['id' => $this->bot, 'inbox_cursor' => 4]);
        Cache::flush();
        $this->postJson($this->base.'/state', ['sync' => true])->assertOk();
        $this->assertDatabaseCount('whatsapp_chat_messages', 3);
        $this->postJson($this->base.'/conversations/'.$this->chat.'/read')->assertOk();
        $this->assertDatabaseHas('whatsapp_conversations', ['id' => $this->chat, 'unread' => 0]);
    }

    public function test_notes_stay_internal_and_outgoing_retries_do_not_duplicate(): void
    {
        Http::fake(['*' => Http::response(['status' => 'sent', 'id' => 'outgoing'])]);
        $noteKey = (string) Str::uuid();
        $path = $this->base.'/conversations/'.$this->chat.'/send';
        $this->postJson($path, ['body' => 'Nota privada', 'note' => true, 'request_key' => $noteKey])->assertOk()->assertJsonPath('message.direction', 'note');
        Http::assertNothingSent();
        $key = (string) Str::uuid();
        $this->postJson($path, ['body' => 'Resposta', 'request_key' => $key])->assertOk()->assertJsonPath('message.status', 'sent');
        $this->postJson($path, ['body' => 'Resposta', 'request_key' => $key])->assertOk();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('whatsapp_chat_messages', 2);
        Http::fake(['*' => Http::failedConnection()]);
        $unknown = (string) Str::uuid();
        $this->postJson($path, ['body' => 'Incerto', 'request_key' => $unknown])->assertOk()->assertJsonPath('message.status', 'unknown');
        $this->postJson($path, ['body' => 'Incerto', 'request_key' => $unknown])->assertOk()->assertJsonPath('message.status', 'unknown');
        Http::assertSentCount(1);
    }

    public function test_tags_assignment_customer_link_filters_and_quick_replies(): void
    {
        $path = $this->base.'/conversations/'.$this->chat;
        $this->postJson($path.'/customer')->assertOk();
        $customer = app(Tenant::class)->find('whatsapp_conversations', $this->chat)->customer_id;
        $this->postJson($path.'/update', ['name' => 'Maria', 'status' => 'pending', 'tags' => ['VIP', 'Orçamento', 'VIP'], 'assigned_to' => $this->owner->id])->assertOk();
        $this->assertDatabaseHas('customers', ['id' => $customer, 'tags' => 'VIP, Orçamento']);
        $this->postJson($this->base.'/state', ['filter' => 'mine', 'tag' => 'VIP', 'selected' => $this->chat])->assertOk()->assertJsonCount(1, 'conversations')->assertJsonPath('selected.name', 'Maria')->assertJsonPath('customer.id', $customer);
        $this->postJson($this->base.'/state', ['filter' => 'resolved'])->assertOk()->assertJsonCount(0, 'conversations');
        $reply = $this->postJson($this->base.'/replies', ['title' => 'Olá', 'body' => 'Bem-vindo!'])->assertOk()->json('id');
        $this->postJson($this->base.'/state')->assertOk()->assertJsonCount(1, 'replies');
        $this->postJson($this->base.'/replies/'.$reply.'/delete')->assertOk();
        $this->assertDatabaseCount('whatsapp_quick_replies', 0);
    }

    public function test_tenant_isolation_and_read_only_staff_permissions(): void
    {
        $other = Company::create(['plan_id' => $this->company->plan_id, 'name' => 'Outra', 'slug' => 'other', 'modules' => array_keys(config('poseitech.modules'))]);
        $staff = User::factory()->create(['company_id' => $other->id, 'role' => 'admin', 'active' => true]);
        $this->postJson($this->base.'/conversations/'.$this->chat.'/update', ['assigned_to' => $staff->id])->assertUnprocessable();
        $this->actingAs($staff);
        $this->postJson($this->base.'/state', ['selected' => $this->chat])->assertNotFound();
        $this->get($this->base.'/conversations/'.$this->chat.'/history', ['Accept' => 'application/json'])->assertNotFound();
        $this->postJson($this->base.'/conversations/'.$this->chat.'/send', ['body' => 'Intrusão', 'request_key' => (string) Str::uuid()])->assertNotFound();
        $reader = User::factory()->create(['company_id' => $this->company->id, 'role' => 'staff', 'active' => true, 'permissions' => ['customers.read']]);
        $this->actingAs($reader);
        $this->get($this->base)->assertOk();
        $this->postJson($this->base.'/state')->assertOk();
        $this->postJson($this->base.'/conversations/'.$this->chat.'/update', ['status' => 'resolved'])->assertForbidden();
        $this->postJson($this->base.'/conversations/'.$this->chat.'/send', ['body' => 'Negado', 'request_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_history_paginates_by_message_time_not_import_order_and_searches(): void
    {
        $t = app(Tenant::class);
        for ($i = 65; $i >= 1; $i--) {
            $t->insert('whatsapp_chat_messages', ['conversation_id' => $this->chat, 'remote_id' => 'history-'.$i, 'direction' => 'in', 'body' => 'Mensagem '.$i, 'message_at' => now()->subMinutes(100 - $i)]);
        }
        $response = $this->postJson($this->base.'/state', ['selected' => $this->chat])->assertOk()->assertJsonCount(60, 'messages')->assertJsonPath('messages.0.body', 'Mensagem 6')->assertJsonPath('messages.59.body', 'Mensagem 65');
        $before = $response->json('messages.0.id');
        $this->getJson($this->base.'/conversations/'.$this->chat.'/history?before='.$before)->assertOk()->assertJsonCount(5, 'messages');
        $this->getJson($this->base.'/conversations/'.$this->chat.'/history?q=Mensagem%2065')->assertOk()->assertJsonCount(1, 'messages');
    }

    public function test_new_chat_resolves_whatsapp_number_and_attachments_are_private(): void
    {
        Http::fake(['*/chat-resolve' => Http::response(['jid' => '5511888888888@s.whatsapp.net']), '*/chat-send' => Http::response(['id' => 'file-id', 'status' => 'sent'])]);
        $id = $this->postJson($this->base.'/conversations', ['phone' => '11888888888', 'name' => 'João'])->assertOk()->json('id');
        $this->postJson($this->base.'/conversations', ['phone' => '11888888888'])->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('whatsapp_conversations', 2);
        $file = UploadedFile::fake()->createWithContent('teste.txt', 'Arquivo de teste');
        $response = $this->post($this->base.'/conversations/'.$id.'/send', ['file' => $file, 'request_key' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('message.status', 'sent');
        $messageId = $response->json('message.id');
        $this->get($this->base.'/media/'.$messageId)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $other = Company::create(['plan_id' => $this->company->plan_id, 'name' => 'Outra', 'slug' => 'other-file', 'modules' => array_keys(config('poseitech.modules'))]);
        $this->actingAs(User::factory()->create(['company_id' => $other->id, 'role' => 'admin', 'active' => true]));
        $this->get($this->base.'/media/'.$messageId)->assertNotFound();
    }

    public function test_sync_failure_keeps_saved_history_available(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $this->postJson($this->base.'/state', ['selected' => $this->chat, 'sync' => true])->assertOk()->assertJsonPath('connection.status', 'unavailable')->assertJsonPath('selected.id', $this->chat);
    }

    public function test_sync_reconciles_an_uncertain_send_without_duplicate_and_imports_edits(): void
    {
        $key = (string) Str::uuid();
        $id = app(Tenant::class)->insert('whatsapp_chat_messages', ['conversation_id' => $this->chat, 'request_key' => $key, 'direction' => 'out', 'status' => 'unknown', 'body' => 'Minha resposta', 'message_at' => now()]);
        Http::fake(['*' => Http::response(['events' => [$this->event('confirmed', 1, ['fromMe' => true, 'requestKey' => $key]), $this->event('confirmed', 2, ['type' => 'edited', 'body' => 'Texto corrigido'])], 'cursor' => 2, 'status' => 'connected'])]);
        $this->postJson($this->base.'/state', ['selected' => $this->chat, 'sync' => true])->assertOk()->assertJsonCount(1, 'messages');
        $this->assertDatabaseHas('whatsapp_chat_messages', ['id' => $id, 'remote_id' => 'confirmed', 'status' => 'sent', 'body' => 'Texto corrigido']);
    }
}
