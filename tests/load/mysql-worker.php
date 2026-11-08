<?php

use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\Commerce;
use App\Services\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (PHP_SAPI !== 'cli' || getenv('LOCAL_LOAD_TEST') !== 'isolated' || ! getenv('LOAD_STORAGE')) {
    exit(1);
}
$report = ['companies' => 0, 'clients' => 0, 'sales' => 0, 'idempotency_checks' => 0, 'rollback_checks' => 0, 'queries' => 0, 'samples_ms' => [], 'errors' => []];
$start = microtime(true);
$db = null;
try {
    $app = require __DIR__.'/local-bootstrap.php';
    $app->make(Kernel::class)->bootstrap();
    config(['session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array']);
    $db = DB::connection();
    if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true) || $db->getTablePrefix() !== '') {
        throw new RuntimeException('Expected unprefixed MySQL/MariaDB');
    }
    $pdo = $db->getPdo();
    // Temporary tables disappear with the session. Refuse any reconnect, which
    // would lose those shadows and expose persistent tables to subsequent SQL.
    $db->setReconnector(function () {
        throw new RuntimeException('Reconnect forbidden in temporary-table test');
    });
    $db->beforeExecuting(function () use ($db, $pdo) {
        if ($db->getRawPdo() !== $pdo) {
            throw new RuntimeException('Database session changed');
        }
    });
    $tables = $db->select("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
    $schema = '`'.str_replace('`', '``', $db->getDatabaseName()).'`';
    foreach ($tables as $table) {
        if (! preg_match('/^[a-z_0-9]+$/D', $table->TABLE_NAME) || strtoupper($table->ENGINE) !== 'INNODB') {
            throw new RuntimeException('Unsupported table structure');
        }
        $name = '`'.$table->TABLE_NAME.'`';
        // MariaDB rejects LIKE source and target with the same qualified name.
        $stage = '`load_stage_'.bin2hex(random_bytes(8)).'`';
        $db->statement("CREATE TEMPORARY TABLE $stage LIKE $schema.$name");
        $db->statement("CREATE TEMPORARY TABLE $name LIKE $stage");
        $db->statement("DROP TEMPORARY TABLE $stage");
    }
    // No application mutations are allowed until every persistent table has
    // been shadowed on this exact PDO session. No migrations or DDL on originals.
    $db->listen(function () use (&$report) {
        $report['queries']++;
    });
    $plan = Plan::create(['name' => 'Synthetic', 'price' => 0, 'users_limit' => 100, 'customers_limit' => 10000, 'products_limit' => 100, 'campaigns_limit' => 0, 'storage_mb' => 100, 'modules' => ['sales', 'cash', 'credit', 'customers', 'products', 'stock', 'portal', 'catalog']]);
    $tenant = app(Tenant::class);
    $worker = (int) ($argv[1] ?? 0);
    for ($i = $worker + 1; $i <= 110; $i += 4) {
        $company = Company::create(['plan_id' => $plan->id, 'name' => 'Synthetic'.$i, 'slug' => 'synthetic-'.$i, 'status' => 'active', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo', 'modules' => $plan->modules]);
        $owner = User::create(['company_id' => $company->id, 'role' => 'admin', 'active' => true, 'must_change_password' => false, 'name' => 'Synthetic', 'email' => "synthetic$i@example.test", 'password' => Str::random(40)]);
        auth()->setUser($owner);
        $tenant->company = $company;
        $product = $tenant->insert('products', ['name' => 'Synthetic product', 'price' => 1000, 'cost' => 100, 'stock' => 20, 'active' => true]);
        $tenant->insert('cash_registers', ['user_id' => $owner->id, 'opening' => 0]);
        $customers = [];
        for ($j = 1; $j <= 10; $j++) {
            $customers[] = $tenant->insert('customers', ['name' => "Synthetic $i/$j", 'portal_hash' => hash('sha256', "$i/$j")]);
        }
        $access = microtime(true);
        if ($tenant->query('customers')->count() !== 10 || $tenant->query('sales')->count() !== 0) {
            throw new RuntimeException('Company isolation failed');
        }
        $report['samples_ms']['company'][] = (microtime(true) - $access) * 1000;
        $report['companies']++;
        foreach ($customers as $customer) {
            $access = microtime(true);
            $record = $tenant->find('customers', $customer);
            if ((int) $record->company_id !== (int) $company->id || ! str_starts_with($record->name, "Synthetic $i/")) {
                throw new RuntimeException('Customer isolation failed');
            }
            $tenant->query('sales')->where('customer_id', $customer)->orderByDesc('id')->limit(15)->get();
            $tenant->query('accounts')->where('customer_id', $customer)->where('status', 'pending')->get();
            $tenant->query('products')->where('active', true)->get();
            $report['samples_ms']['client'][] = (microtime(true) - $access) * 1000;
            $report['clients']++;
        }
        $sale = ['request_key' => (string) Str::uuid(), 'customer_id' => $customers[0], 'items' => [['product_id' => $product, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => '10']]];
        $access = microtime(true);
        $id = app(Commerce::class)->sell($sale);
        $report['samples_ms']['sale'][] = (microtime(true) - $access) * 1000;
        if (app(Commerce::class)->sell($sale) !== $id || $tenant->query('sales')->count() !== 1 || $tenant->query('payments')->count() !== 1) {
            throw new RuntimeException('Sale idempotency failed');
        }
        $report['sales']++;
        $report['idempotency_checks']++;
        $expected = new RuntimeException('Deliberate rollback');
        try {
            DB::transaction(function () use ($sale, $expected) {
                app(Commerce::class)->sell(array_replace($sale, ['request_key' => (string) Str::uuid()]));
                throw $expected;
            });
        } catch (RuntimeException $error) {
            if ($error !== $expected) {
                throw $error;
            }
        }
        $observed = ['sales' => $tenant->query('sales')->count(), 'payments' => $tenant->query('payments')->count(), 'cash_transactions' => $tenant->query('cash_transactions')->count(), 'stock' => (int) $tenant->find('products', $product)->stock];
        if ($observed !== ['sales' => 1, 'payments' => 1, 'cash_transactions' => 1, 'stock' => 19]) {
            $report['rollback_observed'] = $observed;
            throw new RuntimeException('Sale rollback failed');
        }
        $report['rollback_checks']++;
        echo json_encode(['progress' => $i, 'worker' => $worker])."\n";
    }
} catch (Throwable $error) {
    $report['errors'][] = ['class' => get_class($error), 'driver_code' => (int) ($error->errorInfo[1] ?? 0), 'phase' => $report['companies'] === 0 ? 'setup_or_first_company' : 'workload', 'line' => $error->getLine(), 'observed' => $report['rollback_observed'] ?? null];
    // Never include SQL, bindings or driver messages in exported diagnostics.
} finally {
    $db?->disconnect();
    $report['seconds'] = round(microtime(true) - $start, 2);
    file_put_contents(getenv('LOAD_STORAGE').'/worker-'.(int) ($argv[1] ?? 0).'.json', json_encode($report, JSON_THROW_ON_ERROR));
}
exit(count($report['errors']) ? 1 : 0);
