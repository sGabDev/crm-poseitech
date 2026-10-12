<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WhatsAppInbox
{
    public function __construct(public Tenant $t, public WhatsApp $wa) {}

    public function connection(): ?object
    {
        return $this->t->query('whatsapp_connections')->orderByDesc('automatic')->orderBy('id')->first();
    }

    public function sync(object $bot): array
    {
        $key = 'wa-inbox-sync-'.$this->t->id().'-'.$bot->id;
        $lock = Cache::lock($key.'-lock', 20);
        if (! $lock->get()) {
            return Cache::get($key, ['status' => 'syncing']);
        }
        try {
            if ($cached = Cache::get($key)) {
                return $cached;
            }
            $result = $this->wa->api($this->t->id(), $bot->id, '/events?after='.$bot->inbox_cursor, [], 'get');
            if (! isset($result['events'], $result['cursor']) || ! is_array($result['events'])) {
                throw new \RuntimeException('Atualize o servidor Baileys para usar o atendimento.');
            }
            DB::transaction(function () use ($bot, $result) {
                $locked = $this->t->query('whatsapp_connections')->where('id', $bot->id)->lockForUpdate()->firstOrFail();
                foreach ($result['events'] as $event) {
                    if (($event['sequence'] ?? 0) <= $locked->inbox_cursor) {
                        continue;
                    }
                    $this->import($bot, $event);
                }
                $this->t->update('whatsapp_connections', $bot->id, ['inbox_cursor' => max($locked->inbox_cursor, (int) $result['cursor'])]);
            });
            $state = ['status' => $result['status'] ?? 'idle', 'more' => (bool) ($result['more'] ?? false)];
            Cache::put($key, $state, 1);

            return $state;
        } finally {
            $lock->release();
        }
    }

    private function import(object $bot, array $e): void
    {
        if (! preg_match('/^[0-9]+@(s\.whatsapp\.net|lid)$/D', $e['jid'] ?? '') || empty($e['id'])) {
            return;
        }
        $conversation = $this->t->query('whatsapp_conversations')->where('connection_id', $bot->id)->where('jid', $e['jid'])->first();
        if (($e['type'] ?? '') === 'excluded') {
            if ($conversation) {
                $this->t->query('whatsapp_chat_messages')->where('conversation_id', $conversation->id)->where('remote_id', $e['id'])->delete();
                $last = $this->t->query('whatsapp_chat_messages')->where('conversation_id', $conversation->id)->where('direction', '!=', 'note')->orderByDesc('message_at')->orderByDesc('id')->first();
                $unread = $this->t->query('whatsapp_chat_messages')->where('conversation_id', $conversation->id)->where('direction', 'in')->where('status', 'received')->count();
                $this->t->update('whatsapp_conversations', $conversation->id, ['last_body' => $last?->body, 'last_message_at' => $last?->message_at, 'unread' => $conversation->manual_unread ? max(1, $unread) : $unread]);
                if (! $last && ! $conversation->customer_id && ! $this->t->query('whatsapp_chat_messages')->where('conversation_id', $conversation->id)->exists()) {
                    $this->t->update('whatsapp_conversations', $conversation->id, ['status' => 'excluded']);
                }
            }

            return;
        }
        if (isset($e['sourceJid']) && ! preg_match('/^[0-9]+@(s\.whatsapp\.net|lid)$/D', $e['sourceJid'])) {
            return;
        }
        if (in_array($e['type'] ?? '', ['edited', 'deleted'])) {
            if ($conversation) {
                $this->t->query('whatsapp_chat_messages')->where('conversation_id', $conversation->id)->where('remote_id', $e['id'])->update([
                    'body' => $e['type'] === 'deleted' ? 'Mensagem apagada no WhatsApp.' : mb_substr($e['body'] ?? '', 0, 8000),
                    'kind' => 'text', 'updated_at' => now(),
                ]);
            }

            return;
        }
        if (($e['type'] ?? '') === 'status') {
            if ($conversation && in_array($e['status'] ?? '', ['sent', 'delivered', 'read'])) {
                $ranks = ['sent' => 1, 'delivered' => 2, 'read' => 3];
                $message = $this->t->query('whatsapp_chat_messages')->where('conversation_id', $conversation->id)->where('remote_id', $e['id'])->first();
                if ($message && ($ranks[$e['status']] > ($ranks[$message->status] ?? 0))) {
                    $this->t->update('whatsapp_chat_messages', $message->id, ['status' => $e['status']]);
                }
            }

            return;
        }
        if (($e['type'] ?? '') !== 'message') {
            return;
        }
        if (! $conversation) {
            $phone = WhatsApp::phone($e['phone'] ?? null);
            $customer = $phone ? $this->t->query('customers')->whereNull('anonymized_at')->where(function ($q) use ($phone) {
                $numbers = array_unique([$phone, str_starts_with($phone, '55') ? substr($phone, 2) : $phone]);
                $q->whereIn('phone', $numbers)->orWhereIn('whatsapp', $numbers);
            })->first() : null;
            $id = $this->t->insert('whatsapp_conversations', ['connection_id' => $bot->id, 'jid' => $e['jid'], 'phone' => $phone,
                'name' => $customer?->name ?? mb_substr($e['name'] ?? $phone ?? 'Novo contato', 0, 150), 'customer_id' => $customer?->id,
                'tags' => $customer?->tags ? json_encode(array_values(array_filter(array_map('trim', explode(',', $customer->tags))))) : null]);
            $conversation = $this->t->find('whatsapp_conversations', $id);
        }
        if ($this->t->query('whatsapp_chat_messages')->where('conversation_id', $conversation->id)->where('remote_id', $e['id'])->exists()) {
            return;
        }
        if (! empty($e['fromMe']) && ! empty($e['requestKey'])) {
            $local = $this->t->query('whatsapp_chat_messages')->where('conversation_id', $conversation->id)->where('request_key', $e['requestKey'])->first();
            if ($local) {
                $this->t->update('whatsapp_chat_messages', $local->id, ['remote_id' => $e['id'], 'status' => 'sent', 'error' => null]);
                $this->t->update('whatsapp_conversations', $conversation->id, ['last_body' => mb_substr($local->body ?: '[Arquivo]', 0, 250), 'last_message_at' => $local->message_at]);

                return;
            }
        }
        $at = Carbon::createFromTimestamp(max(1, min(time() + 60, (int) ($e['timestamp'] ?? time()))))->utc()->format('Y-m-d H:i:s');
        $kind = in_array($e['kind'] ?? '', ['text', 'image', 'audio', 'video', 'document', 'sticker']) ? $e['kind'] : 'text';
        $this->t->insert('whatsapp_chat_messages', ['conversation_id' => $conversation->id, 'remote_id' => mb_substr($e['id'], 0, 100),
            'direction' => ! empty($e['fromMe']) ? 'out' : 'in', 'kind' => $kind, 'body' => mb_substr($e['body'] ?? '', 0, 8000),
            'status' => ! empty($e['fromMe']) ? (in_array($e['status'] ?? '', ['sent', 'delivered', 'read']) ? $e['status'] : 'sent') : 'received', 'filename' => mb_substr($e['filename'] ?? '', 0, 150) ?: null,
            'mime' => mb_substr($e['mime'] ?? '', 0, 100) ?: null, 'message_at' => $at]);
        $changes = [];
        if (empty($e['fromMe']) && ! empty($e['name']) && $conversation->name === $conversation->phone) {
            $changes['name'] = mb_substr($e['name'], 0, 150);
        }
        if (! $conversation->last_message_at || $at >= $conversation->last_message_at) {
            $changes += ['last_body' => mb_substr(($e['body'] ?? '') ?: '['.$kind.']', 0, 250), 'last_message_at' => $at];
        }
        if (empty($e['fromMe']) && empty($e['historical'])) {
            $changes['unread'] = $conversation->unread + 1;
            if ($conversation->status === 'resolved') {
                $changes['status'] = 'open';
            }
        }
        if ($changes) {
            $this->t->update('whatsapp_conversations', $conversation->id, $changes);
        }
    }
}
