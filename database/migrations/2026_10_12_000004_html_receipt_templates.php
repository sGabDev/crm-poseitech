<?php

use App\Services\ReceiptHtml;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['message_templates', 'email_logs'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->mediumText('html_body')->nullable());
        }
        DB::table('message_templates')->where('channel', 'email')->where('purpose', 'receipt')->update(['html_body' => ReceiptHtml::defaults()]);
    }

    public function down(): void
    {
        foreach (['message_templates', 'email_logs'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('html_body'));
        }
    }
};
