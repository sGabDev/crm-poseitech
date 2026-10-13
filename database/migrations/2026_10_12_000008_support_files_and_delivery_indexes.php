<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->index()->constrained('support_messages')->cascadeOnDelete();
            $t->string('path');
            $t->string('name');
            $t->string('mime', 100);
            $t->unsignedBigInteger('size');
            $t->timestamp('created_at')->useCurrent();
        });
        foreach (['email_logs', 'whatsapp_messages', 'mp_deliveries'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('next_attempt_at')->nullable();
                $t->index(['status', 'next_attempt_at', 'id']);
                $t->index(['company_id', 'status', 'next_attempt_at', 'id']);
            });
        }
        Schema::table('support_tickets', fn (Blueprint $t) => $t->index(['status', 'updated_at']));
    }

    public function down(): void
    {
        Schema::dropIfExists('support_attachments');
        foreach (['email_logs', 'whatsapp_messages', 'mp_deliveries'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['status', 'next_attempt_at', 'id']);
                $t->dropIndex(['company_id', 'status', 'next_attempt_at', 'id']);
                $t->dropColumn('next_attempt_at');
            });
        }
        Schema::table('support_tickets', fn (Blueprint $t) => $t->dropIndex(['status', 'updated_at']));
    }
};
