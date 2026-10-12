<?php

namespace App\Services;

use App\Jobs\SendMercadoPagoLink;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MercadoPago
{
    public function __construct(public Tenant $t) {}

    public function api(string $method, string $path, array $data = []): array
    {
        $token = $this->t->company->mercadopago['access_token'] ?? '';
        if (! $token) {
            $this->fail('Configure o Mercado Pago em Configurações.');
        }

        return Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20)
            ->send($method, 'https://api.mercadopago.com'.$path, [$method === 'GET' ? 'query' : 'json' => $data])->throw()->json();
    }

    public function create(int $accountId): object
    {
        return Cache::lock('mp-create-'.$this->t->id().'-'.$accountId, 60)->block(2, function () use ($accountId) {
            $settings = $this->t->company->mercadopago ?? [];
            $a = $this->t->find('accounts', $accountId);
            if (! ($settings['enabled'] ?? false) || parse_url(config('app.url'), PHP_URL_SCHEME) !== 'https' || $this->t->company->currency !== 'BRL' || $a->type !== 'receivable' || ! $a->customer_id || $a->status !== 'pending' || $a->amount <= $a->paid) {
                $this->fail('Selecione uma conta a receber pendente, com cliente e integração ativa em BRL.');
            }
            if ($a->sale_id && $this->t->find('sales', $a->sale_id)->status === 'cancelled') {
                $this->fail('Venda cancelada.');
            }
            $amount = $a->amount - $a->paid;
            $existing = $this->t->query('mp_checkouts')->where('account_id', $a->id)->where('amount', $amount)->where('expires_at', '>', now())->whereIn('status', ['creating', 'unknown', 'pending'])->latest('id')->first();
            if ($existing && $existing->checkout_url) {
                return $existing;
            }
            if ($existing) {
                // Never repeat an ambiguous POST: find the preference by its durable reference.
                return $this->recover($existing);
            }
            $id = $this->t->insert('mp_checkouts', ['account_id' => $a->id, 'sale_id' => $a->sale_id, 'customer_id' => $a->customer_id, 'user_id' => auth()->id(), 'reference' => (string) Str::uuid(), 'amount' => $amount, 'mode' => $settings['mode'], 'collector_id' => $settings['collector_id'], 'expires_at' => now()->addDay()]);
            $checkout = $this->t->find('mp_checkouts', $id);
            try {
                $return = url('/pay/'.$checkout->reference);
                $preference = $this->api('POST', '/checkout/preferences', ['items' => [['id' => (string) $a->id, 'title' => Str::limit($a->description, 120), 'quantity' => 1, 'currency_id' => 'BRL', 'unit_price' => $amount / 100]], 'external_reference' => $checkout->reference, 'notification_url' => url('/integrations/mercadopago/'.$this->t->id().'/webhook'), 'back_urls' => ['success' => $return, 'failure' => $return, 'pending' => $return], 'auto_return' => 'approved', 'expires' => true, 'expiration_date_from' => now()->toIso8601String(), 'expiration_date_to' => now()->addDay()->toIso8601String()]);

                return $this->savePreference($checkout, $preference);
            } catch (\Throwable $e) {
                $knownFailure = $e instanceof RequestException && in_array($e->response->status(), [400, 401, 403, 404, 422]);
                $this->t->query('mp_checkouts')->where('id', $id)->whereNull('credited_at')->where('status', 'creating')->update(['status' => $knownFailure ? 'failed' : 'unknown', 'error' => $knownFailure ? 'Mercado Pago recusou a criação. Confira credenciais e configuração da aplicação.' : 'Não foi possível confirmar a criação junto ao Mercado Pago.', 'updated_at' => now()]);
                $this->fail('Venda preservada. Não foi possível confirmar a criação do link. Consulte o pagamento e tente reconciliar.');
            }
        });
    }

    private function savePreference(object $checkout, array $p): object
    {
        $url = $p[$checkout->mode === 'test' ? 'sandbox_init_point' : 'init_point'] ?? '';
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        if (! ($p['id'] ?? null) || parse_url($url, PHP_URL_SCHEME) !== 'https' || ! preg_match('/^(?:(?:www|sandbox)\.)?mercadopago\.com(?:\.br)?$/D', $host)) {
            $this->fail('O Mercado Pago retornou um link inválido.');
        }
        DB::transaction(function () use ($checkout, $p, $url) {
            $this->t->lock();
            $fresh = $this->t->find('mp_checkouts', $checkout->id);
            $data = ['preference_id' => $p['id'], 'checkout_url' => $url];
            // A fast webhook may already have confirmed the payment before this POST returned.
            if (in_array($fresh->status, ['creating', 'unknown'])) {
                $data += ['status' => 'pending', 'error' => null];
            }
            $this->t->update('mp_checkouts', $checkout->id, $data);
        }, 3);

        return $this->t->find('mp_checkouts', $checkout->id);
    }

    public function recover(object $checkout): object
    {
        $found = $this->api('GET', '/checkout/preferences/search', ['external_reference' => $checkout->reference]);
        foreach (array_slice($found['elements'] ?? [], 0, 5) as $element) {
            if (! isset($element['id'])) {
                continue;
            }
            // Search returns a summary; get the full preference to recover its URLs.
            $preference = $this->api('GET', '/checkout/preferences/'.rawurlencode($element['id']));
            if (($preference['external_reference'] ?? '') === $checkout->reference && (string) ($preference['collector_id'] ?? '') === $checkout->collector_id) {
                return $this->savePreference($checkout, $preference);
            }
        }
        $this->fail('Criação anterior sem confirmação. Aguarde a reconciliação ou a expiração do link antes de tentar novamente.');
    }

    public function refresh(object $checkout): void
    {
        $result = $this->api('GET', '/v1/payments/search', ['external_reference' => $checkout->reference, 'sort' => 'date_created', 'criteria' => 'desc', 'limit' => 50]);
        foreach ($result['results'] ?? [] as $payment) {
            $this->confirm((string) $payment['id']);
        }
    }

    public function confirm(string $providerId): void
    {
        $p = $this->api('GET', '/v1/payments/'.$providerId);
        $checkout = $this->t->query('mp_checkouts')->where('reference', $p['external_reference'] ?? '')->first();
        if (! $checkout) {
            return;
        }
        DB::transaction(function () use ($checkout, $providerId, $p) {
            $this->t->lock();
            $c = $this->t->find('mp_checkouts', $checkout->id);
            $tx = $this->t->query('mp_transactions')->where('provider_id', $providerId)->first();
            if ($tx && $tx->checkout_id !== $c->id) {
                return;
            }
            $status = $p['status'] ?? 'unknown';
            $valid = (string) ($p['id'] ?? '') === $providerId && (string) ($p['collector_id'] ?? '') === $c->collector_id && ($p['currency_id'] ?? '') === $c->currency && (int) round(($p['transaction_amount'] ?? 0) * 100) === $c->amount && array_key_exists('live_mode', $p) && (bool) $p['live_mode'] === ($c->mode === 'production');
            if (! $valid) {
                $this->review($c, 'Dados do pagamento divergentes. Confira a conta Mercado Pago.');

                return;
            }
            if (! $tx) {
                $id = $this->t->insert('mp_transactions', ['checkout_id' => $c->id, 'provider_id' => $providerId, 'status' => $status]);
                $tx = $this->t->find('mp_transactions', $id);
            }
            if ($tx->payment_id) {
                $refunded = (int) round(($p['transaction_amount_refunded'] ?? 0) * 100);
                if (($refunded > 0 && $refunded < $c->amount) || $status === 'in_mediation') {
                    $this->review($c, 'Reembolso parcial ou pagamento em disputa: confira os valores no Mercado Pago.');
                } elseif (in_array($status, ['refunded', 'charged_back'])) {
                    $this->reverse($c, $tx, $p);
                }

                return;
            }
            $this->t->update('mp_transactions', $tx->id, ['status' => $status]);
            if ($status !== 'approved') {
                if (! $c->credited_at) {
                    $this->t->update('mp_checkouts', $c->id, ['status' => in_array($status, ['pending', 'in_process', 'authorized', 'rejected', 'cancelled']) ? 'pending' : $status]);
                }

                return;
            }
            if (($p['transaction_amount_refunded'] ?? 0) > 0) {
                $this->review($c, 'Pagamento já possui reembolso. Confira os valores antes de dar baixa.');

                return;
            }
            if ($c->mode === 'test') {
                $this->t->update('mp_checkouts', $c->id, ['status' => 'test_approved']);

                return;
            }
            $a = $this->t->find('accounts', $c->account_id);
            if ($c->credited_at || $a->status !== 'pending' || $a->amount - $a->paid !== $c->amount || ($c->sale_id && $this->t->find('sales', $c->sale_id)->status === 'cancelled')) {
                $this->review($c, 'Pagamento recebido com saldo alterado ou já quitado. Verifique antes de dar baixa ou devolver.');

                return;
            }
            app(Commerce::class)->settleConfirmedMercadoPago($a->id, $c->amount, $c->user_id, $c->reference);
            $payment = $this->t->query('payments')->where('request_key', $c->reference)->firstOrFail();
            $this->t->update('mp_transactions', $tx->id, ['payment_id' => $payment->id]);
            $this->t->update('mp_checkouts', $c->id, ['status' => 'approved', 'credited_at' => now(), 'error' => null]);
            if ($c->sale_id) {
                $sale = $this->t->find('sales', $c->sale_id);
                if ($sale->paid >= $sale->total) {
                    $this->t->update('sales', $sale->id, ['status' => 'completed']);
                }
            }
            $this->alert($c, 'Pagamento confirmado', 'Mercado Pago confirmou '.Tenant::money($c->amount).'.', 'approved');
        }, 3);
    }

    private function reverse(object $c, object $tx, array $p): void
    {
        $payment = $this->t->find('payments', $tx->payment_id);
        if ($payment->reversed_at) {
            return;
        }
        if ($p['status'] === 'refunded' && (int) round(($p['transaction_amount_refunded'] ?? 0) * 100) !== $c->amount) {
            $this->review($c, 'Reembolso parcial: confira os valores no Mercado Pago.');

            return;
        }
        $this->t->update('payments', $payment->id, ['reversed_at' => now()]);
        $a = $this->t->find('accounts', $c->account_id);
        $this->t->update('accounts', $a->id, ['paid' => max(0, $a->paid - $payment->amount), 'status' => 'pending']);
        if ($c->sale_id) {
            $sale = $this->t->find('sales', $c->sale_id);
            $this->t->update('sales', $sale->id, ['paid' => max(0, $sale->paid - $payment->amount), 'status' => 'pending_payment']);
        }
        $register = $this->t->query('cash_registers')->whereNull('closed_at')->first();
        if ($register) {
            $this->t->insert('cash_transactions', ['cash_register_id' => $register->id, 'payment_id' => $payment->id, 'user_id' => $c->user_id, 'description' => 'Estorno Mercado Pago #'.$c->id, 'method' => 'mercadopago', 'amount' => -$payment->amount]);
        }
        $this->t->update('mp_transactions', $tx->id, ['status' => $p['status']]);
        $this->t->update('mp_checkouts', $c->id, ['status' => $p['status']]);
        $this->alert($c, 'Pagamento estornado', 'O saldo voltou a ficar pendente após estorno confirmado pelo Mercado Pago.', 'refund');
    }

    private function review(object $c, string $message): void
    {
        $this->t->update('mp_checkouts', $c->id, ['status' => 'review', 'error' => $message]);
        $this->alert($c, 'Pagamento precisa de conferência', $message, 'review');
    }

    private function alert(object $c, string $title, string $body, string $key): void
    {
        DB::table('alerts')->insertOrIgnore(['company_id' => $this->t->id(), 'key' => 'mp-'.$c->id.'-'.$key, 'title' => $title, 'body' => $body, 'url' => '/payments/mercadopago/'.$c->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function deliver(object $c, string $channel): void
    {
        if (! in_array($channel, ['email', 'whatsapp']) || $c->status !== 'pending' || ! $c->checkout_url || now()->gte($c->expires_at)) {
            $this->fail('Este link não está disponível para envio.');
        }
        Cache::lock('mp-send-'.$c->id.'-'.$channel, 60)->block(2, function () use ($c, $channel) {
            $previous = $this->t->query('mp_deliveries')->where('checkout_id', $c->id)->where('channel', $channel)->first();
            if ($previous && ! in_array($previous->status, ['failed', 'pending'])) {
                if (in_array($previous->status, ['unknown', 'sending'])) {
                    $this->fail('Envio anterior sem confirmação. Confira a conversa/fila antes de compartilhar o link manualmente.');
                }

                return;
            }
            $customer = $this->t->find('customers', $c->customer_id);
            if ($customer->anonymized_at) {
                $this->fail('Cliente anonimizado.');
            }
            $text = 'Olá, '.$customer->name.'. '.$this->t->company->name.' enviou um link para pagamento de '.Tenant::money($c->amount).":\n".url('/pay/'.$c->reference)."\nA confirmação é automática após a aprovação do Mercado Pago.";
            $bot = null;
            if ($channel === 'email' && ! filter_var($customer->email, FILTER_VALIDATE_EMAIL)) {
                $this->fail('Cadastre um e-mail válido para o cliente.');
            }
            if ($channel === 'email' && empty($this->t->company->smtp['host'])) {
                $this->fail('Configure o SMTP da empresa antes de enviar o link.');
            }
            $resolved = null;
            if ($channel === 'whatsapp') {
                if (! $this->t->company->enabled('whatsapp')) {
                    $this->fail('Habilite o módulo WhatsApp para enviar o link.');
                }
                $bot = $this->t->query('whatsapp_connections')->where('automatic', true)->first();
                if (! $bot || ! WhatsApp::phone($customer->phone)) {
                    $this->fail('Conecte o bot automático e cadastre o telefone do cliente.');
                }
                // Resolving a destination does not send anything and can safely be retried.
                $resolved = app(WhatsApp::class)->api($this->t->id(), $bot->id, '/chat-resolve', ['phone' => WhatsApp::phone($customer->phone)]);
            }
            $key = $previous?->request_key ?? (string) Str::uuid();
            $id = $previous?->id ?? $this->t->insert('mp_deliveries', ['checkout_id' => $c->id, 'channel' => $channel, 'request_key' => $key, 'status' => 'sending']);
            $this->t->update('mp_deliveries', $id, ['status' => 'sending']);
            try {
                if ($channel === 'email') {
                    DB::transaction(function () use ($customer, $text, $id) {
                        $email = $this->t->insert('email_logs', ['customer_id' => $customer->id, 'recipient' => $customer->email, 'subject' => 'Link de pagamento · '.$this->t->company->name, 'body' => $text]);
                        $this->t->update('mp_deliveries', $id, ['status' => 'queued', 'email_log_id' => $email]);
                    });
                } else {
                    $wa = app(WhatsApp::class);
                    $result = $wa->api($this->t->id(), $bot->id, '/chat-send', ['jid' => $resolved['jid'], 'text' => $text, 'requestKey' => $key]);
                    $this->t->update('mp_deliveries', $id, ['status' => ($result['status'] ?? '') === 'sent' ? 'sent' : 'unknown']);
                    if (($result['status'] ?? '') !== 'sent') {
                        $this->fail('Envio sem confirmação.');
                    }
                }
            } catch (\Throwable $e) {
                $knownFailure = $channel === 'email' || ($e instanceof RequestException && in_array($e->response->status(), [401, 403, 409, 422]));
                $this->t->update('mp_deliveries', $id, ['status' => $knownFailure ? 'failed' : 'unknown']);
                $this->fail($knownFailure ? 'Envio não realizado. Confira SMTP/conexão do bot e tente novamente.' : 'Envio sem confirmação. Copie o link ou confira a fila/conversa antes de reenviar.');
            }
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['mercadopago' => $message]);
    }

    public function queueWhatsApp(object $c): void
    {
        if (! $this->t->company->enabled('whatsapp') || $c->status !== 'pending') {
            $this->fail('WhatsApp indisponível ou pagamento já encerrado.');
        }
        $customer = $this->t->find('customers', $c->customer_id);
        if (! WhatsApp::phone($customer->phone) || $customer->anonymized_at || ! $this->t->query('whatsapp_connections')->where('automatic', true)->exists()) {
            $this->fail('Conecte o bot automático e cadastre o telefone do cliente.');
        }
        Cache::lock('mp-send-'.$c->id.'-whatsapp', 60)->block(2, function () use ($c) {
            $delivery = $this->t->query('mp_deliveries')->where('checkout_id', $c->id)->where('channel', 'whatsapp')->first();
            if ($delivery && in_array($delivery->status, ['unknown', 'sending'])) {
                $this->fail('Envio sem confirmação. Confira a conversa antes de compartilhar manualmente.');
            }
            if ($delivery && in_array($delivery->status, ['pending', 'sent'])) {
                return;
            }
            if ($delivery) {
                $this->t->update('mp_deliveries', $delivery->id, ['status' => 'pending']);
            } else {
                $this->t->insert('mp_deliveries', ['checkout_id' => $c->id, 'channel' => 'whatsapp', 'request_key' => (string) Str::uuid(), 'status' => 'pending']);
            }
            SendMercadoPagoLink::dispatchAfterResponse($this->t->id(), $c->id);
        });
    }
}
