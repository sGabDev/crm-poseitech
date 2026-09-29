<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('coupons', 'product_id')) {
            Schema::table('coupons', fn (Blueprint $t) => $t->foreignId('product_id')->nullable()->constrained());
        }
        if (! Schema::hasColumn('catalog_orders', 'visitor_hash')) {
            Schema::table('catalog_orders', fn (Blueprint $t) => $t->string('visitor_hash', 64)->nullable()->index());
        }
    }

    public function down(): void
    {
        Schema::table('catalog_orders', fn (Blueprint $t) => $t->dropColumn('visitor_hash'));
        Schema::table('coupons', fn (Blueprint $t) => $t->dropConstrainedForeignId('product_id'));
    }
};
