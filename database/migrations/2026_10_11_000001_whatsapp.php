<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('name', 80);
            $t->boolean('automatic')->default(false);
            $t->timestamps();
        });
        Schema::create('whatsapp_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('connection_id')->constrained('whatsapp_connections');
            $t->foreignId('sale_id')->constrained();
            $t->foreignId('customer_id')->constrained();
            $t->string('recipient', 20);
            $t->text('body');
            $t->string('status')->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->string('error')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'sale_id']);
            $t->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_connections');
    }
};
