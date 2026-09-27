<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedInteger('price')->default(0);
            $t->unsignedInteger('users_limit')->default(3);
            $t->unsignedInteger('customers_limit')->default(1000);
            $t->unsignedInteger('products_limit')->default(500);
            $t->unsignedInteger('campaigns_limit')->default(10);
            $t->unsignedInteger('storage_mb')->default(100);
            $t->json('modules');
            $t->timestamps();
        });
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_id')->constrained();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('legal_name')->nullable();
            $t->string('document', 30)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('whatsapp', 30)->nullable();
            $t->string('email')->nullable();
            $t->string('address')->nullable();
            $t->string('logo')->nullable();
            $t->string('currency', 3)->default('BRL');
            $t->string('timezone')->default('America/Sao_Paulo');
            $t->string('status')->default('active');
            $t->date('subscription_until')->nullable();
            $t->json('modules');
            $t->text('smtp')->nullable();
            $t->json('settings')->nullable();
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable()->constrained();
            $t->string('role')->default('staff');
            $t->json('permissions')->nullable();
            $t->boolean('active')->default(true);
        });
        Schema::create('customers', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name');
            $t->string('document', 30)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('whatsapp', 30)->nullable();
            $t->string('email')->nullable();
            $t->date('birthday')->nullable();
            $t->string('address')->nullable();
            $t->string('district')->nullable();
            $t->string('city')->nullable();
            $t->string('tags')->nullable();
            $t->string('source')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('email_consent')->default(false);
            $t->boolean('whatsapp_consent')->default(false);
            $t->timestamp('consented_at')->nullable();
            $t->string('portal_hash', 64)->nullable()->unique();
            $t->timestamp('portal_expires_at')->nullable();
            $t->timestamp('anonymized_at')->nullable();
            $t->index(['company_id', 'name']);
            $t->index(['company_id', 'phone']);
        });
        Schema::create('products', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name');
            $t->string('code')->nullable();
            $t->string('type')->default('product');
            $t->string('category')->nullable();
            $t->unsignedBigInteger('price');
            $t->unsignedBigInteger('cost')->default(0);
            $t->integer('stock')->default(0);
            $t->unsignedInteger('min_stock')->default(0);
            $t->text('description')->nullable();
            $t->string('image')->nullable();
            $t->boolean('active')->default(true);
            $t->index(['company_id', 'name']);
        });
        Schema::create('suppliers', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name');
            $t->string('contact')->nullable();
            $t->string('document')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->text('products')->nullable();
            $t->text('notes')->nullable();
        });
        Schema::create('cash_registers', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('user_id')->constrained();
            $t->unsignedBigInteger('opening');
            $t->bigInteger('expected')->nullable();
            $t->unsignedBigInteger('counted')->nullable();
            $t->bigInteger('difference')->nullable();
            $t->timestamp('closed_at')->nullable();
        });
        Schema::create('coupons', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('code', 40);
            $t->string('type');
            $t->unsignedBigInteger('value');
            $t->unsignedBigInteger('minimum')->default(0);
            $t->unsignedInteger('max_uses');
            $t->unsignedInteger('uses')->default(0);
            $t->date('expires_at');
            $t->foreignId('customer_id')->nullable()->constrained();
            $t->boolean('active')->default(true);
            $t->unique(['company_id', 'code']);
        });
        Schema::create('sales', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('customer_id')->nullable()->constrained();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('coupon_id')->nullable()->constrained();
            $t->unsignedBigInteger('subtotal');
            $t->unsignedBigInteger('discount')->default(0);
            $t->unsignedBigInteger('extra')->default(0);
            $t->unsignedBigInteger('total');
            $t->unsignedBigInteger('cost')->default(0);
            $t->unsignedBigInteger('paid')->default(0);
            $t->string('status')->default('completed');
            $t->text('notes')->nullable();
            $t->string('receipt_hash', 64)->unique();
            $t->uuid('request_key');
            $t->unique(['company_id', 'request_key']);
            $t->index(['company_id', 'created_at']);
        });
        Schema::create('sale_items', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('sale_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->string('name');
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('price');
            $t->unsignedBigInteger('cost');
            $t->unsignedBigInteger('total');
            $t->boolean('stock_deducted')->default(false);
        });
        Schema::create('accounts', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('type');
            $t->string('description');
            $t->string('category')->nullable();
            $t->foreignId('customer_id')->nullable()->constrained();
            $t->foreignId('supplier_id')->nullable()->constrained();
            $t->foreignId('sale_id')->nullable()->constrained();
            $t->unsignedBigInteger('amount');
            $t->unsignedBigInteger('paid')->default(0);
            $t->date('due_date');
            $t->string('recurrence')->default('none');
            $t->string('status')->default('pending');
            $t->string('origin')->default('manual');
            $t->text('notes')->nullable();
            $t->index(['company_id', 'type', 'due_date']);
        });
        Schema::create('payments', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('sale_id')->nullable()->constrained();
            $t->foreignId('account_id')->nullable()->constrained('accounts');
            $t->foreignId('customer_id')->nullable()->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('direction')->default('in');
            $t->string('method');
            $t->unsignedBigInteger('amount');
            $t->timestamp('reversed_at')->nullable();
        });
        Schema::create('cash_transactions', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('cash_register_id')->constrained();
            $t->foreignId('payment_id')->nullable()->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('description');
            $t->string('method');
            $t->bigInteger('amount');
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('product_id')->constrained();
            $t->foreignId('sale_id')->nullable()->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('type');
            $t->integer('quantity');
            $t->integer('balance');
            $t->string('notes')->nullable();
        });
        Schema::create('loyalty_transactions', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('sale_id')->nullable()->constrained();
            $t->integer('points');
            $t->string('description');
        });
        Schema::create('orders', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('sale_id')->unique()->constrained();
            $t->string('status')->default('received');
            $t->boolean('delivery')->default(false);
            $t->string('address')->nullable();
            $t->string('region')->nullable();
            $t->unsignedBigInteger('fee')->default(0);
            $t->foreignId('driver_id')->nullable()->constrained('users');
            $t->timestamp('estimated_at')->nullable();
        });
        Schema::create('campaigns', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name');
            $t->string('segment');
            $t->unsignedInteger('days')->default(30);
            $t->foreignId('product_id')->nullable()->constrained();
            $t->unsignedBigInteger('minimum_ticket')->default(0);
            $t->string('subject');
            $t->text('body');
            $t->string('status')->default('draft');
            $t->unsignedInteger('recipients')->default(0);
        });
        Schema::create('email_logs', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('campaign_id')->nullable()->constrained();
            $t->foreignId('customer_id')->nullable()->constrained();
            $t->string('recipient');
            $t->string('subject');
            $t->text('body');
            $t->string('status')->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->text('error')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->unique(['campaign_id', 'customer_id']);
        });
        Schema::create('alerts', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('key');
            $t->string('title');
            $t->text('body');
            $t->string('url')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->unique(['company_id', 'key']);
        });
        Schema::create('goals', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name');
            $t->string('metric');
            $t->unsignedBigInteger('target');
            $t->date('starts_at');
            $t->date('ends_at');
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('action');
            $t->string('entity')->nullable();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'created_at']);
        });
    }

    private function tenant(Blueprint $t): void
    {
        $t->id();
        $t->foreignId('company_id')->constrained();
        $t->timestamps();
    }

    public function down(): void
    {
        foreach (['audit_logs', 'goals', 'alerts', 'email_logs', 'campaigns', 'orders', 'loyalty_transactions', 'stock_movements', 'cash_transactions', 'payments', 'accounts', 'sale_items', 'sales', 'coupons', 'cash_registers', 'suppliers', 'products', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('company_id');
            $t->dropColumn(['role', 'permissions', 'active']);
        });
        Schema::dropIfExists('companies');
        Schema::dropIfExists('plans');
    }
};
