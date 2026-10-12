<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $t) {
            $t->boolean('manual_unread')->default(false);
            $t->boolean('pinned')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', fn (Blueprint $t) => $t->dropColumn(['manual_unread', 'pinned']));
    }
};
