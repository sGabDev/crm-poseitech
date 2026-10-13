<?php

namespace App\Http\Controllers;

use App\Services\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupportTicketController extends Controller
{
    private array $storedFiles = [];

    private function fileRules(): array
    {
        return ['attachments' => 'nullable|array|max:5', 'attachments.*' => 'required|file|mimes:jpg,jpeg,png,webp,pdf,txt,doc,docx,xls,xlsx,zip|max:5120'];
    }

    private function transaction(callable $callback)
    {
        $this->storedFiles = [];
        try {
            // Filesystem writes cannot be retried with a database transaction.
            return DB::transaction($callback);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($this->storedFiles);
            throw $e;
        }
    }

    private function attach(Request $r, int $messageId): void
    {
        foreach ($r->file('attachments', []) as $file) {
            $path = $file->store('support/'.$messageId, 'local');
            if (! $path) {
                throw new \RuntimeException('Não foi possível armazenar o anexo.');
            }
            $this->storedFiles[] = $path;
            DB::table('support_attachments')->insert(['message_id' => $messageId, 'path' => $path, 'name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200), 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'created_at' => now()]);
        }
    }

    private function messages(int $id, ?int $before = null, ?int $after = null)
    {
        $query = DB::table('support_messages')->where('ticket_id', $id);
        if ($before) {
            $query->where('id', '<', $before);
        }
        if ($after) {
            $query->where('id', '>', $after);
        }
        $messages = $after ? $query->orderBy('id')->limit(30)->get() : $query->orderByDesc('id')->limit(30)->get()->reverse()->values();
        $files = DB::table('support_attachments')->whereIn('message_id', $messages->pluck('id'))->get()->groupBy('message_id');
        foreach ($messages as $message) {
            $message->attachments = $files->get($message->id, collect());
        }

        return $messages;
    }

    public function history(Request $r, int $id)
    {
        $platform = $this->platform($r);
        $this->query($platform)->where('id', $id)->firstOrFail();
        $r->validate(['before' => 'nullable|integer|min:1', 'after' => 'nullable|integer|min:1']);
        $messages = $this->messages($id, $r->integer('before') ?: null, $r->integer('after') ?: null);
        $base = $platform ? '/admin/tickets' : '/support';

        return response()->json(['html' => view('components.support-messages', compact('messages', 'base', 'id', 'platform'))->render(), 'first' => $messages->first()?->id, 'last' => $messages->last()?->id, 'more' => $messages->count() === 30]);
    }

    public function attachment(Request $r, int $id, int $file)
    {
        $this->query($this->platform($r))->where('id', $id)->firstOrFail();
        $attachment = DB::table('support_attachments')->join('support_messages', 'support_messages.id', '=', 'support_attachments.message_id')->where('support_messages.ticket_id', $id)->where('support_attachments.id', $file)->select('support_attachments.*')->firstOrFail();
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);
        $inline = $r->boolean('preview') && in_array($attachment->mime, ['image/jpeg', 'image/png', 'image/webp']);

        return Storage::disk('local')->response($attachment->path, $attachment->name, ['Content-Type' => $attachment->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'], $inline ? 'inline' : 'attachment');
    }

    public const CATEGORIES = ['technical' => 'Problema técnico', 'whatsapp' => 'WhatsApp', 'payments' => 'Vendas e pagamentos', 'access' => 'Acesso e permissões', 'suggestion' => 'Sugestão', 'other' => 'Outro assunto'];

    public const PRIORITIES = ['low' => 'Baixa', 'normal' => 'Normal', 'high' => 'Alta', 'urgent' => 'Urgente'];

    public const STATUSES = ['waiting_support' => 'Aguardando suporte', 'in_progress' => 'Em atendimento', 'waiting_company' => 'Aguardando empresa', 'resolved' => 'Resolvido', 'closed' => 'Encerrado'];

    public function __construct(private Tenant $t) {}

    private function platform(Request $r): bool
    {
        $platform = $r->is('admin/tickets', 'admin/tickets/*');
        if ($platform) {
            Gate::authorize('platform');
        }

        return $platform;
    }

    private function query(bool $platform)
    {
        $check = fn () => Schema::hasTable('support_attachments');
        $ready = app()->runningUnitTests() ? $check() : Cache::store('file')->remember('support.schema.000008.'.hash('sha256', (string) config('database.default').config('database.connections.'.config('database.default').'.database')), 60, $check);
        if (! $ready) {
            abort(response()->view('deployment-pending', ['public' => true], 503));
        }

        return DB::table('support_tickets')->when(! $platform, fn ($q) => $q->where('support_tickets.company_id', $this->t->id()));
    }

    public function index(Request $r)
    {
        $platform = $this->platform($r);
        $r->validate(['status' => ['nullable', Rule::in(array_keys(self::STATUSES))], 'q' => 'nullable|string|max:100', 'priority' => ['nullable', Rule::in(array_keys(self::PRIORITIES))]]);
        $base = $platform ? '/admin/tickets' : '/support';
        $counts = (clone $this->query($platform))->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $query = $this->query($platform)->join('companies', 'companies.id', '=', 'support_tickets.company_id');
        if ($r->filled('status')) {
            $query->where('support_tickets.status', $r->input('status'));
        }
        if ($r->filled('priority')) {
            $query->where('support_tickets.priority', $r->input('priority'));
        }
        if ($r->filled('q')) {
            $term = '%'.$r->input('q').'%';
            $query->where(fn ($q) => $q->where('support_tickets.subject', 'like', $term)->orWhere('support_tickets.id', (int) $r->input('q'))->when($platform, fn ($q) => $q->orWhere('companies.name', 'like', $term)));
        }
        $tickets = $query->select('support_tickets.*', 'companies.name as company_name')->orderByDesc($platform ? 'support_unread' : 'company_unread')->orderByDesc('support_tickets.updated_at')->paginate(20)->withQueryString();

        return view('support-index', compact('platform', 'base', 'tickets', 'counts'));
    }

    public function create(Request $r)
    {
        return view('support-create');
    }

    public function store(Request $r)
    {
        $d = $r->validate(['subject' => 'required|string|min:5|max:160', 'body' => 'required|string|min:10|max:8000', 'category' => ['required', Rule::in(array_keys(self::CATEGORIES))], 'priority' => ['required', Rule::in(array_keys(self::PRIORITIES))], 'request_key' => 'required|uuid'] + $this->fileRules());
        $id = $this->transaction(function () use ($d, $r) {
            $this->t->lock();
            if ($existing = $this->query(false)->where('request_key', $d['request_key'])->first()) {
                return $existing->id;
            }
            $id = DB::table('support_tickets')->insertGetId(['company_id' => $this->t->id(), 'user_id' => auth()->id(), 'subject' => $d['subject'], 'category' => $d['category'], 'priority' => $d['priority'], 'request_key' => $d['request_key'], 'created_at' => now(), 'updated_at' => now()]);
            $messageId = $this->message($id, $d['body'], 'company');
            $this->attach($r, $messageId);
            $this->t->audit('ticket.created', 'support_tickets', $id);

            return $id;
        });

        return redirect('/support/'.$id)->with('success', 'Chamado aberto. O suporte receberá sua solicitação no painel de atendimento.');
    }

    public function show(Request $r, int $id)
    {
        $platform = $this->platform($r);
        $ticket = $this->query($platform)->join('companies', 'companies.id', '=', 'support_tickets.company_id')->select('support_tickets.*', 'companies.name as company_name')->where('support_tickets.id', $id)->firstOrFail();
        $this->query($platform)->where('id', $id)->update([$platform ? 'support_unread' : 'company_unread' => false]);
        $messages = $this->messages($id);
        $base = $platform ? '/admin/tickets' : '/support';

        return view('support-show', compact('platform', 'ticket', 'messages', 'base'));
    }

    public function reply(Request $r, int $id)
    {
        $platform = $this->platform($r);
        $d = $r->validate(['body' => 'nullable|required_without:attachments|string|min:1|max:8000', 'request_key' => 'required|uuid'] + $this->fileRules());
        $this->transaction(function () use ($d, $id, $platform, $r) {
            $ticket = $this->query($platform)->where('id', $id)->lockForUpdate()->firstOrFail();
            if (DB::table('support_messages')->where('request_key', $d['request_key'])->where('ticket_id', $id)->exists()) {
                return;
            }
            if ($ticket->status === 'closed') {
                throw ValidationException::withMessages(['body' => 'Reabra o chamado antes de responder.']);
            }
            $messageId = $this->message($id, $d['body'] ?? '', $platform ? 'support' : 'company', $d['request_key']);
            $this->attach($r, $messageId);
            $this->query($platform)->where('id', $id)->update(['status' => $platform ? 'waiting_company' : 'waiting_support', 'company_unread' => $platform, 'support_unread' => ! $platform, 'updated_at' => now()]);
            $this->notify($ticket, $platform, $messageId, 'Nova resposta no chamado #'.$id);
        });

        return back()->with('success', 'Resposta adicionada ao chamado.');
    }

    public function update(Request $r, int $id)
    {
        $platform = $this->platform($r);
        $d = $r->validate(['status' => ['required', Rule::in($platform ? array_keys(self::STATUSES) : ['waiting_support', 'resolved', 'closed'])], 'assign' => 'nullable|boolean']);
        DB::transaction(function () use ($d, $id, $platform, $r) {
            $ticket = $this->query($platform)->where('id', $id)->lockForUpdate()->firstOrFail();
            $data = ['status' => $d['status'], 'company_unread' => $platform, 'support_unread' => ! $platform, 'updated_at' => now()];
            if ($platform && $r->boolean('assign')) {
                $data['assigned_to'] = auth()->id();
            }
            if ($ticket->status === $d['status'] && (! isset($data['assigned_to']) || $ticket->assigned_to === auth()->id())) {
                return;
            }
            $this->query($platform)->where('id', $id)->update($data);
            $body = ($platform ? 'Suporte' : 'Empresa').' alterou o status para “'.self::STATUSES[$d['status']].'”.'.(isset($data['assigned_to']) ? ' Atendimento assumido por '.auth()->user()->name.'.' : '');
            $messageId = $this->message($id, $body, 'system');
            $this->notify($ticket, $platform, $messageId, 'Atualização no chamado #'.$id);
        }, 3);

        return back()->with('success', 'Chamado atualizado.');
    }

    private function message(int $id, string $body, string $role, ?string $key = null): int
    {
        return DB::table('support_messages')->insertGetId(['ticket_id' => $id, 'user_id' => auth()->id(), 'author_name' => auth()->user()->name, 'author_role' => $role, 'body' => $body, 'request_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function notify(object $ticket, bool $platform, int $messageId, string $title): void
    {
        if ($platform) {
            DB::table('alerts')->insertOrIgnore(['company_id' => $ticket->company_id, 'key' => 'support-message-'.$messageId, 'title' => $title, 'body' => $ticket->subject, 'url' => '/support/'.$ticket->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('audit_logs')->insert(['company_id' => $ticket->company_id, 'user_id' => auth()->id(), 'action' => $platform ? 'ticket.support_updated' : 'ticket.company_updated', 'entity' => 'support_tickets', 'entity_id' => $ticket->id, 'ip' => request()->ip(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
