<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->string('document_normalized', 20)->nullable();
            $t->index(['company_id', 'document_normalized']);
        });
        DB::table('customers')->whereNotNull('document')->update(['document_normalized' => DB::raw("REPLACE(REPLACE(REPLACE(REPLACE(document, '.', ''), '-', ''), '/', ''), ' ', '')")]);
        Schema::table('catalog_orders', function (Blueprint $t) {
            $t->foreignId('customer_id')->nullable()->constrained('customers');
            $t->string('payment_method', 20)->default('cash');
            $t->string('fulfillment', 20)->default('delivery');
            $t->unsignedBigInteger('delivery_fee')->default(0);
            $t->boolean('stock_reserved')->default(false);
            $t->foreignId('checkout_id')->nullable()->constrained('mp_checkouts');
        });
        Schema::table('mp_checkouts', function (Blueprint $t) {
            $t->string('kind', 20)->default('preference');
            $t->string('pix_provider_id')->nullable()->unique();
            $t->text('pix_code')->nullable();
            $t->mediumText('pix_image')->nullable();
            $t->text('pix_payload')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('customer_id');
            $t->dropConstrainedForeignId('checkout_id');
            $t->dropColumn(['payment_method', 'fulfillment', 'delivery_fee', 'stock_reserved']);
        });
        Schema::table('mp_checkouts', function (Blueprint $t) {
            $t->dropUnique(['pix_provider_id']);
            $t->dropColumn(['kind', 'pix_provider_id', 'pix_code', 'pix_image', 'pix_payload']);
        });
        Schema::table('customers', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'document_normalized']);
            $t->dropColumn('document_normalized');
        });
    }
};
