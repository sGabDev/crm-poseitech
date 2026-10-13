<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\MercadoPago;
use App\Services\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendMercadoPagoLink implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $companyId, public int $checkoutId) {}

    public function handle(Tenant $tenant, MercadoPago $mp): void
    {
        $company = Company::find($this->companyId);
        if (! $company) {
            return;
        }
        $tenant->company = $company;
        $checkout = $tenant->find('mp_checkouts', $this->checkoutId);
        if ($checkout->status !== 'pending' || now()->gte($checkout->expires_at)) {
            $tenant->query('mp_deliveries')->where('checkout_id', $this->checkoutId)->where('channel', 'whatsapp')->whereIn('status', ['pending', 'failed'])->update(['status' => 'cancelled', 'next_attempt_at' => null, 'updated_at' => now()]);

            return;
        }
        try {
            if (! $company->available()) {
                throw new \RuntimeException('Company unavailable');
            }
            $mp->deliver($checkout, 'whatsapp');
        } catch (\Throwable $error) {
            // A failure before sending is retryable; ambiguous sends already have their own state.
            $tenant->query('mp_deliveries')->where('checkout_id', $this->checkoutId)->where('channel', 'whatsapp')->whereIn('status', ['pending', 'failed'])->update(['status' => 'failed', 'next_attempt_at' => now()->addMinutes(5), 'updated_at' => now()]);
        }
    }
}
