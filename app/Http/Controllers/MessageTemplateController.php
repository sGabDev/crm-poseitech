<?php

namespace App\Http\Controllers;

use App\Services\MessageTemplates;
use App\Services\ReceiptHtml;
use App\Services\Tenant;
use App\Services\WhatsApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        $htmlData = $request->validate(['html_body' => 'nullable|string|max:60000']);
        $data['html_body'] = null;
        if ($data['channel'] === 'email' && $data['purpose'] === 'receipt') {
            $html = $htmlData['html_body'] ?? ReceiptHtml::defaults();
            preg_match_all('/\{([a-z_]+)\}/', $html, $variables);
            if (array_diff($variables[1], ReceiptHtml::VARIABLES)) {
                throw ValidationException::withMessages(['html_body' => 'O HTML contém variáveis desconhecidas. Use as variáveis indicadas no editor.']);
            }
            $data['html_body'] = ReceiptHtml::sanitize($html);
        }
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
        if ($customer->anonymized_at) {
            throw ValidationException::withMessages(['customer_id' => 'Cliente indisponível.']);
        }
        $sale = $account = null;
        if ($template->purpose === 'receipt') {
            $request->validate(['sale_id' => 'required|integer|min:1']);
            $this->t->authorize('sales', true);
            $sale = $this->t->find('sales', (int) ($data['sale_id'] ?? 0));
            if ((int) $sale->customer_id !== (int) $customer->id || $sale->status === 'cancelled') {
                throw ValidationException::withMessages(['sale_id' => 'Escolha uma venda ativa deste cliente.']);
            }
        }
        if ($template->purpose === 'billing') {
            $request->validate(['account_id' => 'required|integer|min:1']);
            $account = $this->t->find('accounts', (int) ($data['account_id'] ?? 0));
            $this->t->authorize($account->origin === 'credit' ? 'credit' : 'finance', true);
            if ((int) $account->customer_id !== (int) $customer->id || $account->type !== 'receivable' || $account->status !== 'pending') {
                throw ValidationException::withMessages(['account_id' => 'Escolha uma conta pendente deste cliente.']);
            }
        }
        $message = $this->templates->render($template, $this->templates->values($customer, $sale, $account));
        if ($template->channel === 'email') {
            if (! filter_var($customer->email, FILTER_VALIDATE_EMAIL) || ! $this->t->company->smtp) {
                throw ValidationException::withMessages(['send' => 'Cadastre um e-mail válido para o cliente e configure o SMTP em Configurações.']);
            }
            if ($template->purpose === 'general' && ! $customer->email_consent) {
                throw ValidationException::withMessages(['send' => 'O cliente não autorizou mensagens por e-mail.']);
            }
        } else {
            $this->t->authorize('whatsapp', true);
            if (! WhatsApp::phone($customer->phone)) {
                throw ValidationException::withMessages(['send' => 'Cadastre um telefone válido para o cliente.']);
            }
            if ($template->purpose === 'general' && ! $customer->whatsapp_consent) {
                throw ValidationException::withMessages(['send' => 'O cliente não autorizou mensagens por WhatsApp.']);
            }
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
            $emailStatus = DB::transaction(function () use ($customer, $message, $delivery, $sale) {
                $this->t->lock();
                $existing = $sale ? $this->t->query('email_logs')->where('sale_id', $sale->id)->first() : null;
                if ($existing) {
                    $emailId = $existing->id;
                    if (in_array($existing->status, ['pending', 'failed', 'cancelled'])) {
                        $this->t->update('email_logs', $emailId, $message + ['recipient' => $customer->email, 'status' => 'pending', 'error' => null]);
                    }
                } else {
                    $emailId = $this->t->insert('email_logs', ['sale_id' => $sale?->id, 'customer_id' => $customer->id, 'recipient' => $customer->email] + $message);
                }
                $status = $existing && in_array($existing->status, ['sent', 'sending']) ? $existing->status : 'queued';
                $this->t->update('template_deliveries', $delivery, ['status' => $status, 'email_log_id' => $emailId]);

                return $status;
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

        $feedback = match ($emailStatus ?? null) {
            'sent' => 'Este comprovante já foi enviado por e-mail.',
            'sending' => 'Este comprovante está sendo enviado por e-mail.',
            'queued' => 'E-mail colocado na fila de envio.',
            default => 'Mensagem enviada pelo WhatsApp.',
        };

        return back()->with('success', $feedback);
    }
}
