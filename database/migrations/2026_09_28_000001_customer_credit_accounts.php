<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->unsignedTinyInteger('due_day')->default(5));
        Schema::table('sales', function (Blueprint $t) {
            $t->json('payment_methods')->nullable();
            $t->unsignedBigInteger('fiado_amount')->default(0);
        });
        Schema::table('suppliers', fn (Blueprint $t) => $t->softDeletes());
        Schema::create('debt_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('customer_id')->constrained();
            $t->unsignedBigInteger('amount');
            $t->string('method');
            $t->uuid('request_key');
            $t->unique(['company_id', 'request_key']);
            $t->timestamps();
        });
        Schema::table('payments', fn (Blueprint $t) => $t->foreignId('debt_receipt_id')->nullable()->constrained());
        DB::table('sales')->orderBy('id')->chunkById(200, function ($sales) {
            foreach ($sales as $s) {
                $methods = DB::table('payments')->where('company_id', $s->company_id)->where('sale_id', $s->id)->whereNull('account_id')->pluck('method')->unique()->values()->all();
                $debt = DB::table('accounts')->where('company_id', $s->company_id)->where('sale_id', $s->id)->where('origin', 'credit')->sum('amount');
                if ($debt) {
                    $methods[] = 'fiado';
                }DB::table('sales')->where('id', $s->id)->update(['payment_methods' => json_encode($methods), 'fiado_amount' => $debt]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('debt_receipt_id'));
        Schema::dropIfExists('debt_receipts');
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('sales', fn (Blueprint $t) => $t->dropColumn(['payment_methods', 'fiado_amount']));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('due_day'));
    }
};
