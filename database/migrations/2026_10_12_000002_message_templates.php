<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('channel', 20);
            $table->string('purpose', 20);
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['company_id', 'channel', 'purpose']);
        });
        Schema::create('template_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('message_templates')->nullOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->uuid('request_key');
            $table->string('channel', 20);
            $table->string('status', 20);
            $table->unsignedBigInteger('email_log_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'request_key']);
        });
        // Preserve existing WhatsApp access and make the new editor available.
        foreach (['companies', 'plans'] as $table) {
            DB::table($table)->orderBy('id')->chunkById(100, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    $modules = json_decode($row->modules ?? '[]', true) ?: [];
                    DB::table($table)->where('id', $row->id)->update(['modules' => json_encode(array_values(array_unique([...$modules, 'whatsapp', 'message_templates'])))]);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('template_deliveries');
        Schema::dropIfExists('message_templates');
    }
};
