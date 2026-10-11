<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Tenant;
use App\Services\WhatsApp;
use Illuminate\Foundation\Bus\Dispatchable;

class SendWhatsAppReceipt
{
    use Dispatchable;

    public function __construct(public int $companyId, public int $messageId) {}

    public function handle(): void
    {
        $company = Company::with('plan')->find($this->companyId);
        if (! $company || ! $company->available()) {
            return;
        }
        $tenant = new Tenant;
        $tenant->company = $company;
        $message = $tenant->query('whatsapp_messages')->where('id', $this->messageId)->first();
        if ($message && $message->status === 'pending' && $message->attempts === 0) {
            (new WhatsApp($tenant))->send($message);
        }
    }
}
