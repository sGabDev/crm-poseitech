<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class WhatsApp
{
    public function __construct(public Tenant $t) {}

    public static function connectionError(\Throwable $error): string
    {
        if ($error instanceof RequestException) {
            return match ($error->response->status()) {
                401 => 'Token recusado pelo Baileys. Configure o mesmo WHATSAPP_SERVICE_TOKEN nos dois servidores e limpe o cache de configuração do CRM.',
                403 => 'O servidor Baileys bloqueou o acesso. Verifique as regras do proxy e a liberação do IP do CRM.',
                404 => 'A URL não aponta para a API Baileys. Confira WHATSAPP_SERVICE_URL e o encaminhamento do proxy.',
                409 => 'O bot está desconectado. Reconecte o WhatsApp antes de enviar.',
                422 => 'O número do cliente não foi reconhecido pelo WhatsApp. Confira o DDD e o telefone cadastrado.',
                default => 'O serviço Baileys respondeu com erro HTTP '.$error->response->status().'. Consulte o console da hospedagem.',
            };
        }
        if ($error instanceof ConnectionException) {
            if (str_contains($error->getMessage(), 'cURL error 7')) {
                return 'Conexão recusada pelo servidor Baileys. Inicie o serviço e confira WHATSAPP_SERVICE_HOST=0.0.0.0 e a porta atribuída no painel da hospedagem.';
            }
            if (str_contains($error->getMessage(), 'cURL error 60')) {
                return 'O certificado HTTPS do servidor Baileys não pôde ser validado. Corrija o certificado do domínio.';
            }

            return 'Não foi possível alcançar o servidor Baileys. Confira endereço, DNS, porta e firewall entre os servidores.';
        }
        if (! config('whatsapp.token')) {
            return 'Configure WHATSAPP_SERVICE_TOKEN no CRM e execute php artisan config:clear.';
        }

        return 'Resposta inválida do serviço Baileys. Confira se a URL aponta para a API e consulte o console da hospedagem.';
    }

    public function api(int $company, int $connection, string $action = '', array $data = [], string $method = 'post', ?int $timeout = null): array
    {
        if (! config('whatsapp.token')) {
            throw new \RuntimeException('Serviço WhatsApp não configurado.');
        }

        return Http::withToken(config('whatsapp.token'))->connectTimeout(3)->timeout($timeout ?? (strtolower($method) === 'get' ? 5 : 25))
            ->send(strtoupper($method), rtrim(config('whatsapp.url'), '/')."/sessions/$company/$connection".$action, ['json' => $data])->throw()->json();
    }

    public static function phone(?string $phone): ?string
    {
        $n = preg_replace('/\D/', '', $phone ?? '');
        if (strlen($n) === 10 || strlen($n) === 11) {
            $n = '55'.$n;
        }

        return preg_match('/^[1-9][0-9]{10,14}$/D', $n) ? $n : null;
    }

    public function queue(int $saleId, bool $manual = false): ?int
    {
        if (! $this->t->company->enabled('whatsapp')) {
            return null;
        }

        return DB::transaction(function () use ($saleId, $manual) {
            $this->t->lock();
            $sale = $this->t->find('sales', $saleId);
            $connection = $this->t->query('whatsapp_connections')->where('automatic', true)->first();
            $customer = $sale->customer_id ? $this->t->find('customers', $sale->customer_id) : null;
            if (! $connection || ! $customer || $customer->anonymized_at || ! self::phone($customer->phone) || $sale->status === 'cancelled') {
                if ($manual) {
                    throw ValidationException::withMessages(['whatsapp' => 'Escolha um bot para envio automático e cadastre um telefone válido para o cliente de uma venda ativa.']);
                }

                return null;
            }
            if ($existing = $this->t->query('whatsapp_messages')->where('sale_id', $saleId)->first()) {
                if ($manual && $existing->status === 'failed') {
                    $this->t->update('whatsapp_messages', $existing->id, [
                        'status' => 'pending', 'attempts' => 0, 'error' => null,
                        'recipient' => self::phone($customer->phone), 'connection_id' => $connection->id,
                    ]);
                }

                return $existing->id;
            }

            $content = app(MessageTemplates::class)->automatic('whatsapp', 'receipt', app(MessageTemplates::class)->values($customer, $sale), [
                'body' => 'Olá, '.$customer->name.'! Sua compra #'.$saleId.' na '.$this->t->company->name.' foi finalizada. Total: '.Tenant::money($sale->total).".\nComprovante: ".url('/receipt/'.$sale->receipt_hash),
            ]);

            return $this->t->insert('whatsapp_messages', [
                'connection_id' => $connection->id, 'sale_id' => $saleId, 'customer_id' => $customer->id, 'recipient' => self::phone($customer->phone),
                'body' => $content['body'],
            ]);
        });
    }

    public function send(object $message): void
    {
        $q = $this->t->query('whatsapp_messages')->where('id', $message->id);
        if (! $q->where('status', 'pending')->update(['status' => 'sending', 'updated_at' => now()])) {
            return;
        }
        $q = $this->t->query('whatsapp_messages')->where('id', $message->id);
        try {
            $sale = $this->t->find('sales', $message->sale_id);
            $customer = $this->t->find('customers', $message->customer_id);
            if (! $this->t->company->enabled('whatsapp') || $sale->status === 'cancelled' || $customer->anonymized_at) {
                $q->update(['status' => 'cancelled', 'updated_at' => now()]);

                return;
            }
            $result = $this->api($this->t->id(), $message->connection_id, '/messages', ['id' => (string) $message->id, 'phone' => $message->recipient, 'text' => $message->body]);
            $state = $result['status'] ?? 'unknown';
            $q->update(['status' => $state === 'sent' ? 'sent' : 'unknown', 'sent_at' => $state === 'sent' ? now() : null, 'error' => $state === 'sent' ? null : 'Envio sem confirmação. Confira no celular antes de reenviar.', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            $attempts = $message->attempts + 1;
            $q->update(['status' => $attempts >= 10 ? 'failed' : 'pending', 'attempts' => $attempts, 'error' => self::connectionError($e), 'updated_at' => now()]);
        }
    }
}
