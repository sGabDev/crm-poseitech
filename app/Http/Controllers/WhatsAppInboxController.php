<?php

namespace App\Http\Controllers;

use App\Services\Tenant;
use App\Services\WhatsApp;
use App\Services\WhatsAppInbox;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;

class WhatsAppInboxController extends Controller
{
    public function __construct(public Tenant $t, public WhatsAppInbox $inbox, public WhatsApp $wa) {}

    private function access(bool $write = false): void
    {
        $this->t->authorize('customers', $write);
    }

    public function index()
    {
        $this->access();
        if (! Schema::hasTable('whatsapp_conversations')) {
            return response()->view('deployment-pending', ['public' => true], 503);
        }

        return view('whatsapp-inbox', ['bot' => $this->inbox->connection(), 'canWrite' => auth()->user()->allows('customers', true)]);
    }

    public function state(Request $r)
    {
        $this->access();
        $d = $r->validate(['selected' => 'nullable|integer|min:1', 'q' => 'nullable|string|max:100', 'filter' => 'nullable|in:all,unread,mine,open,pending,resolved', 'tag' => 'nullable|string|max:40', 'sync' => 'nullable|boolean']);
        $bot = $this->inbox->connection();
        $connection = ['status' => 'idle'];
        if ($bot && $r->boolean('sync')) {
            try {
                $connection = $this->inbox->sync($bot);
            } catch (\Throwable $e) {
                $connection = ['status' => 'unavailable', 'message' => $e instanceof RequestException && $e->response->status() === 404
                    ? 'Atualize o servidor Baileys com o novo pacote para habilitar as conversas de atendimento.' : WhatsApp::connectionError($e)];
            }
        }
        $q = $this->t->query('whatsapp_conversations');
        if ($search = trim($d['q'] ?? '')) {
            $q->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%'));
        }
        $filter = $d['filter'] ?? 'all';
        if ($filter === 'unread') {
            $q->where('unread', '>', 0);
        } elseif ($filter === 'mine') {
            $q->where('assigned_to', auth()->id());
        } elseif (in_array($filter, ['open', 'pending', 'resolved'])) {
            $q->where('status', $filter);
        }
        if (! empty($d['tag'])) {
            $q->whereJsonContains('tags', $d['tag']);
        }
        $conversations = $q->orderByDesc('last_message_at')->orderByDesc('id')->limit(100)->get();
        $selected = ! empty($d['selected']) ? $this->t->find('whatsapp_conversations', $d['selected']) : null;
        if ($selected) {
            $this->t->query('whatsapp_chat_messages')->where('conversation_id', $selected->id)->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(2))
                ->update(['status' => 'unknown', 'error' => 'Sem confirmação. Confira no celular antes de enviar novamente.', 'updated_at' => now()]);
        }
        $messages = $selected ? $this->messages($selected->id) : collect();
        $customer = $selected?->customer_id ? $this->t->query('customers')->where('id', $selected->customer_id)->whereNull('anonymized_at')->first(['id', 'name', 'email', 'phone', 'tags']) : null;
        $counts = $this->t->query('whatsapp_conversations')->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json(['connection' => $connection, 'conversations' => $conversations, 'selected' => $selected, 'messages' => $messages,
            'customer' => $customer, 'counts' => $counts, 'hasOlder' => $messages->count() === 60,
            'staff' => DB::table('users')->where('company_id', $this->t->id())->where('active', true)->whereNull('deleted_at')->get(['id', 'name']),
            'replies' => $this->t->query('whatsapp_quick_replies')->orderBy('title')->get(['id', 'title', 'body'])])->header('Cache-Control', 'no-store');
    }

    private function messages(int $id, ?int $before = null, ?string $search = null)
    {
        $anchor = $before ? $this->t->query('whatsapp_chat_messages')->where('conversation_id', $id)->where('id', $before)->firstOrFail() : null;

        return $this->t->query('whatsapp_chat_messages')->where('conversation_id', $id)->when($anchor, fn ($q) => $q->where(function ($q) use ($anchor) {
            $q->where('message_at', '<', $anchor->message_at)->orWhere(fn ($q) => $q->where('message_at', $anchor->message_at)->where('id', '<', $anchor->id));
        }))->when($search, fn ($q) => $q->where('body', 'like', '%'.$search.'%'))->orderByDesc('message_at')->orderByDesc('id')->limit(60)->get()->reverse()->values();
    }

    public function history(Request $r, int $id)
    {
        $this->access();
        $this->t->find('whatsapp_conversations', $id);
        $d = $r->validate(['before' => 'nullable|integer|min:1', 'q' => 'nullable|string|max:100']);

        return response()->json(['messages' => $this->messages($id, $d['before'] ?? null, $d['q'] ?? null)])->header('Cache-Control', 'no-store');
    }

    public function start(Request $r)
    {
        $this->access(true);
        $d = $r->validate(['phone' => 'required|string|max:30', 'name' => 'nullable|string|max:150']);
        $phone = WhatsApp::phone($d['phone']);
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'Informe um telefone válido com DDD e código do país.']);
        }
        $bot = $this->inbox->connection();
        if (! $bot) {
            throw ValidationException::withMessages(['bot' => 'Crie e conecte o bot da empresa primeiro.']);
        }
        try {
            $resolved = $this->wa->api($this->t->id(), $bot->id, '/chat-resolve', ['phone' => $phone]);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['phone' => WhatsApp::connectionError($e)]);
        }
        $jid = $resolved['jid'] ?? '';
        if (! preg_match('/^[0-9]+@s\.whatsapp\.net$/D', $jid)) {
            throw ValidationException::withMessages(['phone' => 'Número não localizado no WhatsApp.']);
        }
        $id = DB::transaction(function () use ($phone, $bot, $d, $jid) {
            $this->t->lock();
            $existing = $this->t->query('whatsapp_conversations')->where('connection_id', $bot->id)->where('jid', $jid)->first();

            return $existing?->id ?? $this->t->insert('whatsapp_conversations', ['connection_id' => $bot->id, 'jid' => $jid, 'phone' => $phone, 'name' => ($d['name'] ?? null) ?: $phone]);
        });

        return response()->json(['id' => $id]);
    }

    public function update(Request $r, int $id)
    {
        $this->access(true);
        $chat = $this->t->find('whatsapp_conversations', $id);
        $d = $r->validate(['name' => 'sometimes|required|string|max:150', 'status' => 'sometimes|required|in:open,pending,resolved',
            'assigned_to' => 'sometimes|nullable|integer', 'tags' => 'sometimes|array|max:15', 'tags.*' => 'required|string|max:40', 'customer_id' => 'sometimes|nullable|integer']);
        if (! empty($d['assigned_to'])) {
            abort_unless(DB::table('users')->where('company_id', $this->t->id())->where('active', true)->whereNull('deleted_at')->where('id', $d['assigned_to'])->exists(), 422, 'Responsável inválido.');
        }
        if (! empty($d['customer_id'])) {
            abort_if($this->t->find('customers', $d['customer_id'])->anonymized_at, 422, 'Cliente indisponível.');
        }
        if (isset($d['tags'])) {
            $d['tags'] = json_encode(array_values(array_unique(array_map('trim', $d['tags']))));
        }
        $this->t->update('whatsapp_conversations', $id, $d);
        if (isset($d['tags']) && $chat->customer_id) {
            $this->t->query('customers')->where('id', $chat->customer_id)->whereNull('anonymized_at')->update(['tags' => mb_substr(implode(', ', json_decode($d['tags'], true)), 0, 255), 'updated_at' => now()]);
        }
        $this->t->audit('whatsapp.attendance.updated', 'whatsapp_conversations', $id);

        return response()->json(['ok' => true]);
    }

    public function read(int $id)
    {
        $this->access(true);
        $chat = $this->t->find('whatsapp_conversations', $id);
        // Clear only the unread count observed, preserving messages arriving concurrently.
        $this->t->query('whatsapp_conversations')->where('id', $id)->where('unread', '>=', $chat->unread)->decrement('unread', $chat->unread);
        try {
            $this->wa->api($this->t->id(), $chat->connection_id, '/chat-read', ['jid' => $chat->jid]);
        } catch (\Throwable) {
        }

        return response()->json(['ok' => true]);
    }

    public function send(Request $r, int $id)
    {
        $this->access(true);
        $chat = $this->t->find('whatsapp_conversations', $id);
        $d = $r->validate(['body' => 'nullable|string|max:8000', 'request_key' => 'required|uuid', 'note' => 'nullable|boolean',
            'file' => 'nullable|file|max:8192|mimetypes:image/jpeg,image/png,image/webp,application/pdf,text/plain,audio/mpeg,audio/ogg,audio/mp4,video/mp4']);
        if (! trim($d['body'] ?? '') && ! $r->hasFile('file')) {
            throw ValidationException::withMessages(['body' => 'Escreva uma mensagem ou selecione um arquivo.']);
        }
        $note = $r->boolean('note');
        if ($note && $r->hasFile('file')) {
            throw ValidationException::withMessages(['file' => 'Notas internas aceitam apenas texto.']);
        }
        $message = DB::transaction(function () use ($d, $chat, $r, $note) {
            $this->t->lock();
            $old = $this->t->query('whatsapp_chat_messages')->where('request_key', $d['request_key'])->first();
            if ($old) {
                abort_unless($old->conversation_id === $chat->id, 409);

                return $old;
            }
            $file = $r->file('file');
            $path = $file?->store('whatsapp/'.$this->t->id(), 'local');
            $mid = $this->t->insert('whatsapp_chat_messages', ['conversation_id' => $chat->id, 'request_key' => $d['request_key'], 'direction' => $note ? 'note' : 'out',
                'kind' => $file ? (str_starts_with($file->getMimeType(), 'image/') ? 'image' : 'document') : 'text', 'body' => $d['body'] ?? '', 'status' => $note ? 'internal' : 'pending',
                'filename' => $file ? mb_substr(basename($file->getClientOriginalName()), 0, 150) : null, 'mime' => $file?->getMimeType(), 'media_path' => $path,
                'user_id' => auth()->id(), 'message_at' => now()]);

            return $this->t->find('whatsapp_chat_messages', $mid);
        });
        if ($note || in_array($message->status, ['sent', 'delivered', 'read', 'unknown', 'sending'])) {
            return response()->json(['message' => $message]);
        }
        if (! $this->t->query('whatsapp_chat_messages')->where('id', $message->id)->whereIn('status', ['pending', 'failed'])->update(['status' => 'sending', 'error' => null])) {
            return response()->json(['message' => $this->t->find('whatsapp_chat_messages', $message->id)]);
        }
        try {
            $payload = ['requestKey' => $message->request_key, 'jid' => $chat->jid, 'text' => $message->body ?? ''];
            if ($message->media_path) {
                $payload['file'] = ['base64' => base64_encode(Storage::disk('local')->get($message->media_path)), 'mime' => $message->mime, 'name' => $message->filename];
            }
            $result = $this->wa->api($this->t->id(), $chat->connection_id, '/chat-send', $payload);
            $sent = ($result['status'] ?? '') === 'sent';
            DB::transaction(function () use ($message, $result, $sent, $chat) {
                $this->t->query('whatsapp_connections')->where('id', $chat->connection_id)->lockForUpdate()->firstOrFail();
                // An echo may be imported by another attendant while the HTTP send is completing.
                if (! empty($result['id'])) {
                    $this->t->query('whatsapp_chat_messages')->where('conversation_id', $chat->id)->where('remote_id', $result['id'])->where('id', '!=', $message->id)->delete();
                }
                $this->t->update('whatsapp_chat_messages', $message->id, ['remote_id' => $result['id'] ?? null, 'status' => $sent ? 'sent' : 'unknown', 'error' => $sent ? null : 'Sem confirmação. Confira no celular antes de enviar novamente.']);
                $this->t->update('whatsapp_conversations', $chat->id, ['last_body' => mb_substr($message->body ?: '[Arquivo]', 0, 250), 'last_message_at' => $message->message_at]);
            });
        } catch (\Throwable $e) {
            // Only explicit pre-send rejection is safe to retry. Timeouts can hide a successful send.
            $safe = $e instanceof RequestException && in_array($e->response->status(), [401, 403, 404, 409, 413, 422]);
            $this->t->update('whatsapp_chat_messages', $message->id, ['status' => $safe ? 'failed' : 'unknown', 'error' => WhatsApp::connectionError($e)]);
        }
        $this->t->audit('whatsapp.message.sent', 'whatsapp_chat_messages', $message->id);

        return response()->json(['message' => $this->t->find('whatsapp_chat_messages', $message->id)]);
    }

    public function media(Request $request, int $id)
    {
        $this->access();
        $message = $this->t->find('whatsapp_chat_messages', $id);
        $chat = $this->t->find('whatsapp_conversations', $message->conversation_id);
        $inline = $request->boolean('inline') && in_array($message->mime, ['image/jpeg', 'image/png', 'image/webp', 'audio/mpeg', 'audio/ogg', 'audio/mp4', 'video/mp4']);
        $name = $message->filename ?: 'arquivo';
        if ($message->media_path && Storage::disk('local')->exists($message->media_path)) {
            return response()->file(Storage::disk('local')->path($message->media_path), ['Content-Type' => $message->mime ?: 'application/octet-stream',
                'Content-Disposition' => HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $name, 'arquivo'), 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
        }
        abort_unless($message->remote_id && $message->kind !== 'text', 404);
        try {
            $result = $this->wa->api($this->t->id(), $chat->connection_id, '/chat-media', ['jid' => $chat->jid, 'id' => $message->remote_id]);
            $bytes = base64_decode($result['base64'] ?? '', true);
            abort_unless($bytes !== false && strlen($bytes) <= 8 * 1024 * 1024, 413);
            $path = 'whatsapp/'.$this->t->id().'/received-'.$message->id;
            Storage::disk('local')->put($path, $bytes);
            $this->t->update('whatsapp_chat_messages', $message->id, ['media_path' => $path]);
        } catch (\Throwable) {
            return response()->view('whatsapp-media-unavailable', [], 410);
        }

        return response($bytes)->header('Content-Type', $message->mime ?: 'application/octet-stream')->header('Content-Disposition', HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $name, 'arquivo'))->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function customer(int $id)
    {
        $this->access(true);
        $chat = $this->t->find('whatsapp_conversations', $id);
        abort_unless($chat->phone, 422, 'Cadastre o cliente e vincule seu código à conversa.');
        $customerId = DB::transaction(function () use ($chat) {
            $this->t->lock();
            $fresh = $this->t->find('whatsapp_conversations', $chat->id);
            if ($fresh->customer_id) {
                return $fresh->customer_id;
            }
            $existing = $this->t->query('customers')->whereNull('anonymized_at')->where('phone', $chat->phone)->first();
            if (! $existing) {
                $this->t->limit('customers');
            }
            $id = $existing?->id ?? $this->t->insert('customers', ['name' => $chat->name ?: $chat->phone, 'phone' => $chat->phone, 'whatsapp' => $chat->phone, 'tags' => implode(', ', json_decode($chat->tags ?? '[]', true))]);
            $this->t->update('whatsapp_conversations', $chat->id, ['customer_id' => $id]);

            return $id;
        });
        $this->t->audit('whatsapp.customer.linked', 'customers', $customerId);

        return response()->json(['id' => $customerId]);
    }

    public function reply(Request $r)
    {
        $this->access(true);
        $d = $r->validate(['title' => 'required|string|max:80', 'body' => 'required|string|max:4000']);
        abort_if($this->t->query('whatsapp_quick_replies')->count() >= 100, 422, 'Limite de 100 respostas rápidas.');

        return response()->json(['id' => $this->t->insert('whatsapp_quick_replies', $d)]);
    }

    public function deleteReply(int $id)
    {
        $this->access(true);
        $this->t->find('whatsapp_quick_replies', $id);
        $this->t->query('whatsapp_quick_replies')->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }
}
