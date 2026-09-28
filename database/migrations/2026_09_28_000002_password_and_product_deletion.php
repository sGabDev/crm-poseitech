<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'must_change_password')) {
            Schema::table('users', fn (Blueprint $t) => $t->boolean('must_change_password')->default(true));
        }
        if (! Schema::hasColumn('products', 'deleted_at')) {
            Schema::table('products', fn (Blueprint $t) => $t->softDeletes());
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('must_change_password'));
        Schema::table('products', fn (Blueprint $t) => $t->dropSoftDeletes());
    }
};
