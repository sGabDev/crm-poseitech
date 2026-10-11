<?php

namespace App\Http\Controllers;

use App\Services\Tenant;
use App\Services\WhatsApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WhatsAppController extends Controller
{
    public function __construct(public Tenant $t, public WhatsApp $wa) {}

    public function index()
    {
        Gate::authorize('manage-company');

        return view('whatsapp', ['connections' => $this->t->query('whatsapp_connections')->get(), 'messages' => $this->t->query('whatsapp_messages')->orderByDesc('id')->paginate(20)]);
    }

    public function create(Request $r)
    {
        Gate::authorize('manage-company');
        $d = $r->validate(['name' => 'required|string|max:80']);
        $id = DB::transaction(function () use ($d) {
            $this->t->lock();
            abort_if($this->t->query('whatsapp_connections')->count() >= 10, 422, 'Limite de 10 bots por empresa.');

            return $this->t->insert('whatsapp_connections', $d);
        });
        $this->t->audit('whatsapp.created', 'whatsapp_connections', $id);

        return redirect('/whatsapp')->with('success', 'Bot criado. Clique em Conectar para gerar o QR code.');
    }

    public function status(Request $r, int $id)
    {
        Gate::authorize('manage-company');
        $this->t->find('whatsapp_connections', $id);
        if (! $r->expectsJson()) {
            return redirect('/whatsapp');
        }
        try {
            return response()->json($this->wa->api($this->t->id(), $id, '', [], 'get'))->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return response()->json(['status' => 'unavailable', 'qr' => null, 'message' => WhatsApp::connectionError($e)], 503)->header('Cache-Control', 'no-store');
        }
    }

    public function action(Request $r, int $id)
    {
        Gate::authorize('manage-company');
        $this->t->find('whatsapp_connections', $id);
        $action = $r->validate(['action' => 'required|in:connect,disconnect,automatic,disable'])['action'];
        if (in_array($action, ['automatic', 'disable'])) {
            DB::transaction(function () use ($id, $action) {
                $this->t->lock();
                if ($action === 'automatic') {
                    $this->t->query('whatsapp_connections')->update(['automatic' => false]);
                } $this->t->update('whatsapp_connections', $id, ['automatic' => $action === 'automatic']);
            });
        } else {
            try {
                $this->wa->api($this->t->id(), $id, '/'.$action);
            } catch (\Throwable $e) {
                return redirect('/whatsapp')->withErrors(['whatsapp' => WhatsApp::connectionError($e)]);
            }
        }
        $this->t->audit('whatsapp.'.$action, 'whatsapp_connections', $id);

        return redirect('/whatsapp')->with('success', 'Configuração atualizada.');
    }

    public function receipt(int $id)
    {
        $this->t->authorize('sales', true);
        $messageId = $this->wa->queue($id, true);
        $message = $this->t->find('whatsapp_messages', $messageId);
        $this->wa->send($message);
        $result = $this->t->find('whatsapp_messages', $messageId);
        if (in_array($result->status, ['pending', 'failed', 'unknown'])) {
            return redirect('/sales/'.$id)->withErrors(['whatsapp' => $result->error ?? 'Envio pendente. Confira o histórico em WhatsApp · Bots.']);
        }

        return redirect('/sales/'.$id)->with('success', $result->status === 'sent' ? 'Comprovante enviado pelo WhatsApp.' : 'Comprovante em processamento.');
    }
}
