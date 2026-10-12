<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $t) {
            $t->unsignedBigInteger('inbox_cursor')->default(0);
        });
        Schema::create('whatsapp_conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('connection_id')->constrained('whatsapp_connections');
            $t->string('jid', 100);
            $t->string('name')->nullable();
            $t->string('phone', 30)->nullable();
            $t->foreignId('customer_id')->nullable()->constrained();
            $t->foreignId('assigned_to')->nullable()->constrained('users');
            $t->string('status', 20)->default('open');
            $t->json('tags')->nullable();
            $t->unsignedInteger('unread')->default(0);
            $t->text('last_body')->nullable();
            $t->timestamp('last_message_at')->nullable();
            $t->timestamps();
            $t->unique(['connection_id', 'jid']);
            $t->index(['company_id', 'last_message_at']);
        });
        Schema::create('whatsapp_chat_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('conversation_id')->constrained('whatsapp_conversations');
            $t->string('remote_id', 100)->nullable();
            $t->uuid('request_key')->nullable();
            $t->string('direction', 10);
            $t->string('kind', 20)->default('text');
            $t->text('body')->nullable();
            $t->string('status', 20)->default('received');
            $t->string('error')->nullable();
            $t->string('filename')->nullable();
            $t->string('mime', 100)->nullable();
            $t->string('media_path')->nullable();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->timestamp('message_at');
            $t->timestamps();
            $t->unique(['conversation_id', 'remote_id']);
            $t->unique(['company_id', 'request_key']);
            $t->index(['conversation_id', 'message_at', 'id']);
        });
        Schema::create('whatsapp_quick_replies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('title', 80);
            $t->text('body');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_quick_replies');
        Schema::dropIfExists('whatsapp_chat_messages');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::table('whatsapp_connections', fn (Blueprint $t) => $t->dropColumn('inbox_cursor'));
    }
};
