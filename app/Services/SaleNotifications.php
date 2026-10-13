<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SaleNotifications
{
    public function __construct(private Tenant $t) {}

    public function queue(int $saleId): void
    {
        DB::transaction(function () use ($saleId) {
            $this->t->lock();
            $sale = $this->t->find('sales', $saleId);
            if (! $sale->customer_id || $sale->status !== 'completed') {
                return;
            }
            $customer = $this->t->find('customers', $sale->customer_id);
            if ($customer->anonymized_at) {
                return;
            }
            if (filter_var($customer->email, FILTER_VALIDATE_EMAIL) && ! $this->t->query('email_logs')->where('sale_id', $saleId)->where('customer_id', $customer->id)->exists()) {
                $templates = app(MessageTemplates::class);
                $content = $templates->automatic('email', 'receipt', $templates->values($customer, $sale), ['subject' => 'Comprovante da compra #'.$saleId.' · '.$this->t->company->name, 'body' => 'Olá, '.$customer->name.'. Sua compra #'.$saleId.' foi registrada. Total: '.Tenant::money($sale->total).'.']);
                $this->t->insert('email_logs', ['sale_id' => $saleId, 'customer_id' => $customer->id, 'recipient' => $customer->email] + $content);
            }
            app(WhatsApp::class)->queue($saleId);
        }, 3);
    }
}
