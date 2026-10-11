<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class WhatsApp
{
    public function __construct(public Tenant $t) {}

    public function api(int $company, int $connection, string $action = '', array $data = [], string $method = 'post'): array
    {
        if (! config('whatsapp.token')) {
            throw new \RuntimeException('Serviço WhatsApp não configurado.');
        }

        return Http::withToken(config('whatsapp.token'))->connectTimeout(3)->timeout(25)
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

            return $this->t->insert('whatsapp_messages', [
                'connection_id' => $connection->id, 'sale_id' => $saleId, 'customer_id' => $customer->id, 'recipient' => self::phone($customer->phone),
                'body' => 'Olá, '.$customer->name.'! Sua compra #'.$saleId.' na '.$this->t->company->name.' foi finalizada. Total: '.Tenant::money($sale->total).".\nComprovante: ".url('/receipt/'.$sale->receipt_hash),
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
            if ($sale->status === 'cancelled' || $customer->anonymized_at) {
                $q->update(['status' => 'cancelled', 'updated_at' => now()]);

                return;
            }
            $result = $this->api($this->t->id(), $message->connection_id, '/messages', ['id' => (string) $message->id, 'phone' => $message->recipient, 'text' => $message->body]);
            $state = $result['status'] ?? 'unknown';
            $q->update(['status' => $state === 'sent' ? 'sent' : 'unknown', 'sent_at' => $state === 'sent' ? now() : null, 'error' => $state === 'sent' ? null : 'Envio sem confirmação. Confira no celular antes de reenviar.', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            $attempts = $message->attempts + 1;
            $q->update(['status' => $attempts >= 10 ? 'failed' : 'pending', 'attempts' => $attempts, 'error' => 'Serviço indisponível ou bot desconectado.', 'updated_at' => now()]);
        }
    }
}
