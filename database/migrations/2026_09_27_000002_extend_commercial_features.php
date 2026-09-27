<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->json('addons')->nullable();
        });
        Schema::table('sale_items', function (Blueprint $t) {
            $t->string('addons')->nullable();
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->uuid('request_key')->nullable();
            $t->unique(['company_id', 'request_key']);
        });
        Schema::create('customer_credits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->foreignId('sale_id')->nullable()->constrained();
            $t->bigInteger('amount');
            $t->string('description');
            $t->timestamps();
            $t->index(['company_id', 'customer_id']);
        });
        Schema::create('platform_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('customer_credits');
        Schema::table('payments', function (Blueprint $t) {
            $t->dropUnique(['company_id', 'request_key']);
            $t->dropColumn('request_key');
        });
        Schema::table('sale_items', fn (Blueprint $t) => $t->dropColumn('addons'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('addons'));
    }
};
