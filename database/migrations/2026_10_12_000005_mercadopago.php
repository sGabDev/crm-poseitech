<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->text('mercadopago')->nullable());
        Schema::create('mp_checkouts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained('accounts');
            $t->foreignId('sale_id')->nullable()->constrained('sales');
            $t->foreignId('customer_id')->constrained('customers');
            $t->foreignId('user_id')->constrained('users');
            $t->uuid('reference')->unique();
            $t->unsignedBigInteger('amount');
            $t->string('currency', 3)->default('BRL');
            $t->string('mode', 12);
            $t->string('collector_id');
            $t->string('preference_id')->nullable();
            $t->text('checkout_url')->nullable();
            $t->string('status', 24)->default('creating');
            $t->timestamp('expires_at');
            $t->timestamp('credited_at')->nullable();
            $t->string('error', 500)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'account_id', 'status']);
        });
        Schema::create('mp_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('checkout_id')->constrained('mp_checkouts');
            $t->string('provider_id')->unique();
            $t->string('status', 24);
            $t->foreignId('payment_id')->nullable()->constrained('payments');
            $t->timestamps();
        });
        Schema::create('mp_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('checkout_id')->constrained('mp_checkouts');
            $t->string('channel', 20);
            $t->uuid('request_key')->unique();
            $t->string('status', 24)->default('pending');
            $t->foreignId('email_log_id')->nullable()->constrained('email_logs');
            $t->timestamps();
            $t->unique(['checkout_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mp_deliveries');
        Schema::dropIfExists('mp_transactions');
        Schema::dropIfExists('mp_checkouts');
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('mercadopago'));
    }
};
