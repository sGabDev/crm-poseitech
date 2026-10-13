<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A merged customer can have multiple historical deliveries/grants for one campaign.
        Schema::table('email_logs', function (Blueprint $t) {
            $t->index(['campaign_id', 'customer_id'], 'email_logs_campaign_customer_history');
            $t->dropUnique(['campaign_id', 'customer_id']);
        });
        Schema::table('coupon_grants', function (Blueprint $t) {
            $t->index(['company_id', 'coupon_id', 'customer_id'], 'coupon_grants_customer_history');
            $t->dropUnique(['company_id', 'coupon_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('email_logs')->whereNotNull('campaign_id')->groupBy('campaign_id', 'customer_id')->havingRaw('COUNT(*) > 1')->exists() || DB::table('coupon_grants')->groupBy('company_id', 'coupon_id', 'customer_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Há históricos de clientes unidos. Não é possível restaurar os índices únicos sem perder registros.');
        }
        Schema::table('email_logs', function (Blueprint $t) {
            $t->unique(['campaign_id', 'customer_id']);
            $t->dropIndex('email_logs_campaign_customer_history');
        });
        Schema::table('coupon_grants', function (Blueprint $t) {
            $t->unique(['company_id', 'coupon_id', 'customer_id']);
            $t->dropIndex('coupon_grants_customer_history');
        });
    }
};
