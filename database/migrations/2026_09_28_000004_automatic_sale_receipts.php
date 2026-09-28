<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $t) {
            $t->foreignId('sale_id')->nullable()->constrained();
            $t->unique(['company_id', 'sale_id']);
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $t) {
            $t->dropUnique(['company_id', 'sale_id']);
            $t->dropConstrainedForeignId('sale_id');
        });
    }
};
