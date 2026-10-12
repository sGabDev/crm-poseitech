<?php

namespace App\Http\Controllers;

use App\Services\MessageTemplates;
use App\Services\Tenant;
use App\Services\WhatsApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageTemplateController extends Controller
{
    public function __construct(private Tenant $t, private MessageTemplates $templates) {}

    public function index(Request $request)
    {
        $this->t->authorize('message_templates');
        $this->templates->ensureDefaults();
        $editing = $request->filled('edit') ? $this->t->find('message_templates', (int) $request->input('edit')) : null;
        if (! $editing && ! $request->boolean('new')) {
            $editing = $this->t->query('message_templates')->orderByDesc('is_default')->orderBy('id')->first();
        }

        $deliveries = $this->t->query('template_deliveries')->orderByDesc('id')->limit(20)->get();
        $emails = $this->t->query('email_logs')->whereIn('id', $deliveries->pluck('email_log_id')->filter())->get()->keyBy('id');
        foreach ($deliveries as $delivery) {
            if ($email = $emails->get($delivery->email_log_id)) {
                $delivery->status = $email->status === 'pending' ? 'queued' : $email->status;
            }
        }

        return view('message-templates', [
            'templates' => $this->t->query('message_templates')->orderBy('name')->get(), 'editing' => $editing,
            'customers' => $this->t->query('customers')->whereNull('anonymized_at')->orderBy('name')->get(),
            'deliveries' => $deliveries,
            'selectedTemplate' => $this->t->query('message_templates')->where('channel', $request->input('channel', 'whatsapp'))->where('purpose', $request->input('purpose', 'general'))->where('is_default', true)->value('id'),
        ]);
    }

    public function save(Request $request, ?int $id = null)
    {
        $this->t->authorize('message_templates', true);
        $data = $request->validate(['name' => 'required|string|max:120', 'channel' => 'required|in:email,whatsapp', 'purpose' => 'required|in:receipt,billing,general', 'subject' => 'nullable|required_if:channel,email|string|max:200|not_regex:/[\r\n]/', 'body' => 'required|string|max:7000', 'is_default' => 'nullable|boolean']);
        $this->templates->validate($data['purpose'], $data['body'].' '.($data['subject'] ?? ''));
        $data['is_default'] = $request->boolean('is_default') && $data['purpose'] !== 'general';
        DB::transaction(function () use ($data, $id) {
            $this->t->lock();
            if ($id) {
                $this->t->find('message_templates', $id);
            }
            if ($data['is_default']) {
                $this->t->query('message_templates')->where('channel', $data['channel'])->where('purpose', $data['purpose'])->update(['is_default' => false]);
            }
            if ($id) {
                $this->t->update('message_templates', $id, $data);
            } else {
                $id = $this->t->insert('message_templates', $data);
            }
            $this->t->audit('template.saved', 'message_templates', $id);
        });

        return redirect('/message-templates')->with('success', 'Modelo salvo.');
    }

    public function delete(int $id)
    {
        $this->t->authorize('message_templates', true);
        $this->t->find('message_templates', $id);
        $this->t->query('message_templates')->where('id', $id)->delete();
        $this->t->audit('template.deleted', 'message_templates', $id);

        return redirect('/message-templates')->with('success', 'Modelo excluído. O envio automático usará o texto padrão quando não houver outro modelo definido.');
    }

    public function send(Request $request)
    {
        $this->t->authorize('message_templates', true);
        $data = $request->validate(['template_id' => 'required|integer', 'customer_id' => 'required|integer', 'sale_id' => 'nullable|integer', 'account_id' => 'nullable|integer', 'request_key' => 'required|uuid']);
        $template = $this->t->find('message_templates', $data['template_id']);
        $customer = $this->t->find('customers', $data['customer_id']);
        abort_if($customer->anonymized_at, 422, 'Cliente indisponível.');
        $sale = $account = null;
        if ($template->purpose === 'receipt') {
            $this->t->authorize('sales', true);
            $sale = $this->t->find('sales', (int) ($data['sale_id'] ?? 0));
            abort_unless($sale->customer_id === $customer->id && $sale->status !== 'cancelled', 422, 'Escolha uma venda ativa deste cliente.');
        }
        if ($template->purpose === 'billing') {
            $account = $this->t->find('accounts', (int) ($data['account_id'] ?? 0));
            $this->t->authorize($account->origin === 'credit' ? 'credit' : 'finance', true);
            abort_unless($account->customer_id === $customer->id && $account->type === 'receivable' && $account->status === 'pending', 422, 'Escolha uma conta pendente deste cliente.');
        }
        $message = $this->templates->render($template, $this->templates->values($customer, $sale, $account));
        if ($template->channel === 'email') {
            abort_unless($customer->email && $this->t->company->smtp, 422, 'Cadastre o e-mail do cliente e configure o SMTP.');
            abort_if($template->purpose === 'general' && ! $customer->email_consent, 422, 'O cliente não autorizou mensagens por e-mail.');
        } else {
            $this->t->authorize('whatsapp', true);
            abort_unless(WhatsApp::phone($customer->phone), 422, 'Cadastre um telefone válido.');
            abort_if($template->purpose === 'general' && ! $customer->whatsapp_consent, 422, 'O cliente não autorizou mensagens por WhatsApp.');
        }
        // Durable idempotency record: an ambiguous WhatsApp send is never retried automatically.
        $delivery = DB::transaction(function () use ($data, $template, $customer) {
            $this->t->lock();
            $existing = $this->t->query('template_deliveries')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                return null;
            }

            return $this->t->insert('template_deliveries', ['request_key' => $data['request_key'], 'template_id' => $template->id, 'customer_id' => $customer->id, 'channel' => $template->channel, 'status' => 'pending']);
        });
        if (! $delivery) {
            return back()->with('success', 'Esta solicitação já foi registrada. Consulte o histórico antes de reenviar.');
        }
        if ($template->channel === 'email') {
            DB::transaction(function () use ($customer, $message, $delivery) {
                $emailId = $this->t->insert('email_logs', ['customer_id' => $customer->id, 'recipient' => $customer->email] + $message);
                $this->t->update('template_deliveries', $delivery, ['status' => 'queued', 'email_log_id' => $emailId]);
            });
        } else {
            try {
                $connection = $this->t->query('whatsapp_connections')->first();
                if (! $connection) {
                    throw new \RuntimeException('Conecte o WhatsApp da empresa antes de enviar.');
                }
                $wa = app(WhatsApp::class);
                $destination = $wa->api($this->t->id(), $connection->id, '/chat-resolve', ['phone' => WhatsApp::phone($customer->phone)]);
                $result = $wa->api($this->t->id(), $connection->id, '/chat-send', ['requestKey' => $data['request_key'], 'jid' => $destination['jid'], 'text' => $message['body']]);
                $status = ($result['status'] ?? '') === 'sent' ? 'sent' : 'unknown';
                $this->t->update('template_deliveries', $delivery, ['status' => $status]);
                if ($status !== 'sent') {
                    return back()->withErrors(['send' => 'Envio sem confirmação. Confira o celular antes de fazer outra solicitação.']);
                }
            } catch (\Throwable $error) {
                $this->t->update('template_deliveries', $delivery, ['status' => 'unknown']);

                return back()->withErrors(['send' => WhatsApp::connectionError($error).' Confira o celular antes de reenviar.']);
            }
        }
        $this->t->audit('template.sent', 'message_templates', $template->id);

        return back()->with('success', $template->channel === 'email' ? 'E-mail colocado na fila de envio.' : 'Mensagem enviada pelo WhatsApp.');
    }
}
