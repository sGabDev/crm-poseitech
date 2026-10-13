<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', fn (Blueprint $t) => $t->index(['campaign_id', 'status', 'id']));
        Schema::table('campaigns', fn (Blueprint $t) => $t->index(['status', 'updated_at', 'id']));
        Schema::table('mp_checkouts', fn (Blueprint $t) => $t->index(['status', 'updated_at']));
    }

    public function down(): void
    {
        Schema::table('email_logs', fn (Blueprint $t) => $t->dropIndex(['campaign_id', 'status', 'id']));
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropIndex(['status', 'updated_at', 'id']));
        Schema::table('mp_checkouts', fn (Blueprint $t) => $t->dropIndex(['status', 'updated_at']));
    }
};
