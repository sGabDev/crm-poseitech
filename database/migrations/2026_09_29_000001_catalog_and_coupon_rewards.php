<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', fn (Blueprint $t) => $t->unsignedBigInteger('product_id')->nullable()->change());
        if (! Schema::hasColumn('coupons', 'deleted_at')) {
            Schema::table('coupons', fn (Blueprint $t) => $t->softDeletes());
        }
        if (! Schema::hasTable('coupon_grants')) {
            Schema::create('coupon_grants', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained();
                $t->foreignId('coupon_id')->constrained();
                $t->foreignId('customer_id')->constrained();
                $t->foreignId('earned_sale_id')->nullable()->constrained('sales');
                $t->foreignId('used_sale_id')->nullable()->constrained('sales');
                $t->foreignId('email_log_id')->nullable()->constrained('email_logs');
                $t->timestamp('revoked_at')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'coupon_id', 'customer_id']);
            });
        }
        if (! Schema::hasTable('catalog_orders')) {
            Schema::create('catalog_orders', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained();
                $t->uuid('request_key');
                $t->string('name', 160);
                $t->string('phone', 30);
                $t->string('address')->nullable();
                $t->text('notes')->nullable();
                $t->json('items');
                $t->bigInteger('total');
                $t->string('status')->default('pending');
                $t->foreignId('sale_id')->nullable()->constrained();
                $t->timestamps();
                $t->unique(['company_id', 'request_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_orders');
        Schema::dropIfExists('coupon_grants');
        Schema::table('coupons', fn (Blueprint $t) => $t->dropSoftDeletes());
        // Item avulso permanece com product_id nulo para preservar seu histórico.
    }
};
