<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEXES = [
        'sales' => [
            'sales_company_status_date' => ['company_id', 'status', 'created_at'],
            'sales_company_status_customer' => ['company_id', 'status', 'customer_id'],
        ],
        'payments' => ['payments_company_reversed_date' => ['company_id', 'reversed_at', 'created_at']],
        'accounts' => ['accounts_company_status_type_due' => ['company_id', 'status', 'type', 'due_date']],
        'cash_registers' => ['registers_company_closed' => ['company_id', 'closed_at']],
        'sale_items' => ['items_company_sale_product' => ['company_id', 'sale_id', 'product_id']],
        'customers' => ['customers_company_created' => ['company_id', 'created_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                // MySQL DDL is not transactional: tolerate a partially completed deploy.
                if (! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::INDEXES) as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
