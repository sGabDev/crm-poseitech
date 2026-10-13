<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->string('subject', 160);
            $t->string('category', 24);
            $t->string('priority', 16)->default('normal');
            $t->string('status', 24)->default('waiting_support');
            $t->foreignId('assigned_to')->nullable()->constrained('users');
            $t->boolean('company_unread')->default(false);
            $t->boolean('support_unread')->default(true);
            $t->uuid('request_key');
            $t->timestamps();
            $t->unique(['company_id', 'request_key']);
            $t->index(['company_id', 'status', 'updated_at']);
        });
        Schema::create('support_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->string('author_name', 160);
            $t->string('author_role', 16);
            $t->text('body');
            $t->uuid('request_key')->nullable();
            $t->unique(['ticket_id', 'request_key']);
            $t->timestamps();
            $t->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};
