<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogPix
{
    public function __construct(private Tenant $t, private MercadoPago $mp) {}

    public function prepare(int $orderId, array $profile): object
    {
        return DB::transaction(function () use ($orderId, $profile) {
            $this->t->lock();
            $order = $this->t->find('catalog_orders', $orderId);
            if ($order->checkout_id) {
                return $this->t->find('mp_checkouts', $order->checkout_id);
            }
            $settings = $this->t->company->mercadopago ?? [];
            if (! ($settings['enabled'] ?? false) || $this->t->company->currency !== 'BRL' || parse_url(config('app.url'), PHP_URL_SCHEME) !== 'https') {
                throw ValidationException::withMessages(['payment_method' => 'Pix online indisponível. A empresa precisa configurar Mercado Pago e HTTPS.']);
            }
            $actor = DB::table('users')->where('company_id', $this->t->id())->where('role', 'admin')->where('active', true)->whereNull('deleted_at')->orderBy('id')->value('id');
            if (! $actor) {
                throw ValidationException::withMessages(['payment_method' => 'A loja não está disponível para receber pagamentos.']);
            }
            $account = $this->t->insert('accounts', ['type' => 'receivable', 'description' => 'Pix pedido online #'.$order->id, 'customer_id' => $order->customer_id, 'amount' => $order->total, 'due_date' => today()->toDateString(), 'origin' => 'catalog']);
            $reference = (string) Str::uuid();
            $expires = now()->addMinutes(30);
            $payload = ['transaction_amount' => $order->total / 100, 'description' => 'Pedido #'.$order->id.' · '.$this->t->company->name, 'payment_method_id' => 'pix', 'external_reference' => $reference, 'notification_url' => url('/integrations/mercadopago/'.$this->t->id().'/webhook'), 'date_of_expiration' => $expires->toIso8601String(), 'payer' => ['email' => $profile['email'], 'first_name' => $profile['name'], 'identification' => ['type' => 'CPF', 'number' => $profile['document']]]];
            $checkout = $this->t->insert('mp_checkouts', ['account_id' => $account, 'customer_id' => $order->customer_id, 'user_id' => $actor, 'reference' => $reference, 'amount' => $order->total, 'mode' => $settings['mode'], 'collector_id' => $settings['collector_id'], 'expires_at' => $expires, 'kind' => 'pix', 'pix_payload' => Crypt::encryptString(json_encode($payload))]);
            $this->t->update('catalog_orders', $order->id, ['checkout_id' => $checkout]);

            return $this->t->find('mp_checkouts', $checkout);
        }, 3);
    }

    public function generate(object $checkout): object
    {
        return Cache::lock('catalog-pix-'.$checkout->id, 60)->block(2, function () use ($checkout) {
            $c = $this->t->find('mp_checkouts', $checkout->id);
            if ($c->pix_provider_id || ! in_array($c->status, ['creating', 'unknown']) || now()->gte($c->expires_at)) {
                return $c;
            }
            try {
                DeliveryQueue::releaseConnection();
                $p = $this->mp->api('POST', '/v1/payments', json_decode(Crypt::decryptString($c->pix_payload), true), $c->reference);
                if (! ctype_digit((string) ($p['id'] ?? '')) || ($p['external_reference'] ?? '') !== $c->reference || (string) ($p['collector_id'] ?? '') !== $c->collector_id || (int) round(($p['transaction_amount'] ?? 0) * 100) !== $c->amount || ($p['currency_id'] ?? '') !== 'BRL' || ! array_key_exists('live_mode', $p) || (bool) $p['live_mode'] !== ($c->mode === 'production')) {
                    throw new \RuntimeException('PIX_RESPONSE');
                }
                $qr = $p['point_of_interaction']['transaction_data'] ?? [];
                $this->t->query('mp_checkouts')->where('id', $c->id)->update(['pix_provider_id' => (string) $p['id'], 'pix_code' => mb_substr($qr['qr_code'] ?? '', 0, 5000), 'pix_image' => preg_match('/^[A-Za-z0-9+\/=]+$/D', $qr['qr_code_base64'] ?? '') && strlen($qr['qr_code_base64']) < 1000000 ? $qr['qr_code_base64'] : null, 'updated_at' => now()]);
                $this->t->query('mp_checkouts')->where('id', $c->id)->whereIn('status', ['creating', 'unknown'])->update(['status' => 'pending', 'error' => null]);
                $this->mp->confirm((string) $p['id']);
                // QR generation is complete even if a later read/confirmation needs reconciliation.
            } catch (\Throwable $e) {
                $this->t->query('mp_checkouts')->where('id', $c->id)->where('status', 'creating')->update(['status' => 'unknown', 'error' => 'Aguardando confirmação da geração do Pix.', 'updated_at' => now()]);
                throw $e;
            }

            $fresh = $this->t->find('mp_checkouts', $c->id);
            if ($fresh->status === 'pending' && $fresh->pix_code) {
                foreach (['email', 'whatsapp'] as $channel) {
                    try {
                        if ($channel === 'email') {
                            $this->mp->deliver($fresh, 'email');
                        } else {
                            $this->mp->queueWhatsApp($fresh);
                        }
                    } catch (ValidationException) {
                        // Missing customer contact or unavailable channel does not block Pix.
                    }
                }
            }

            return $fresh;
        });
    }

    public function release(object $order): void
    {
        DB::transaction(function () use ($order) {
            $this->t->lock();
            $order = $this->t->find('catalog_orders', $order->id);
            $checkout = $order->checkout_id ? $this->t->find('mp_checkouts', $order->checkout_id) : null;
            if ($checkout && ! in_array($checkout->status, ['cancelled', 'rejected', 'refunded', 'charged_back'])) {
                throw ValidationException::withMessages(['payment' => 'Confirme o cancelamento ou reembolso do Pix no Mercado Pago antes de cancelar o pedido.']);
            }
            if ($order->sale_id) {
                throw ValidationException::withMessages(['payment' => 'Cancele a venda vinculada para devolver seu estoque.']);
            }
            if ($order->stock_reserved && ! $order->sale_id) {
                foreach (json_decode($order->items, true) as $line) {
                    if (! empty($line['reserved'])) {
                        $this->t->query('products')->where('id', $line['product_id'])->increment('stock', $line['quantity'], ['updated_at' => now()]);
                        $this->t->insert('stock_movements', ['user_id' => $checkout->user_id, 'product_id' => $line['product_id'], 'type' => 'return', 'quantity' => $line['quantity'], 'balance' => $this->t->find('products', $line['product_id'])->stock, 'notes' => 'Liberação pedido online #'.$order->id]);
                    }
                }
            }
            if ($checkout) {
                $this->t->query('accounts')->where('id', $checkout->account_id)->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);
            }
            $this->t->update('catalog_orders', $order->id, ['stock_reserved' => false, 'status' => 'cancelled']);
        }, 3);
    }
}
