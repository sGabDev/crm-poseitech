<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', fn (Blueprint $t) => $t->softDeletes());
        }
        if (! Schema::hasColumn('sales', 'wallet_used')) {
            Schema::table('sales', fn (Blueprint $t) => $t->unsignedBigInteger('wallet_used')->default(0));
        }
        if (! Schema::hasTable('customer_deposits')) {
            Schema::create('customer_deposits', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained();
                $t->foreignId('customer_id')->constrained();
                $t->foreignId('user_id')->constrained();
                $t->unsignedBigInteger('amount');
                $t->unsignedBigInteger('debt_paid');
                $t->string('method');
                $t->uuid('request_key');
                $t->unique(['company_id', 'request_key']);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('wallet_entries')) {
            Schema::create('wallet_entries', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained();
                $t->foreignId('customer_id')->constrained();
                $t->foreignId('user_id')->constrained();
                $t->foreignId('sale_id')->nullable()->constrained();
                $t->foreignId('customer_deposit_id')->nullable()->constrained();
                $t->bigInteger('amount');
                $t->string('description');
                $t->timestamps();
                $t->index(['company_id', 'customer_id']);
            });
        }
        if (! Schema::hasTable('flow_entries')) {
            Schema::create('flow_entries', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained();
                $t->foreignId('user_id')->constrained();
                $t->bigInteger('amount');
                $t->string('method');
                $t->string('description');
                $t->string('category');
                $t->timestamp('occurred_at');
                $t->uuid('request_key');
                $t->unique(['company_id', 'request_key']);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_entries');
        Schema::dropIfExists('wallet_entries');
        Schema::dropIfExists('customer_deposits');
        Schema::table('sales', fn (Blueprint $t) => $t->dropColumn('wallet_used'));
        Schema::table('users', fn (Blueprint $t) => $t->dropSoftDeletes());
    }
};
