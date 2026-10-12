<?php

use App\Models\Company;
use App\Services\MessageTemplates;
use App\Services\Tenant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Company::orderBy('id')->chunkById(100, function ($companies) {
            foreach ($companies as $company) {
                $tenant = new Tenant;
                $tenant->company = $company;
                (new MessageTemplates($tenant))->ensureDefaults();
            }
        });
    }

    public function down(): void
    {
        // Preserve messages that companies may have already customized.
    }
};
