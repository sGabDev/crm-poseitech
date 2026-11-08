<?php

use App\Database\AccountLimits;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Read-only inspection. Never print connection settings, grants or SQL errors.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
try {
    if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
        throw new RuntimeException('MySQL required');
    }
    $grants = implode("\n", array_map(fn ($row) => implode('', (array) $row), $db->select('SHOW GRANTS FOR CURRENT_USER')));
    $quotas = AccountLimits::parse([$grants]);
    $pending = array_values(array_diff(array_map(fn ($file) => basename($file, '.php'), glob(database_path('migrations/*.php'))), $db->table('migrations')->pluck('migration')->all()));
    $tables = $db->select('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
    echo json_encode([
        'driver' => $db->getDriverName(),
        'version' => $db->selectOne('SELECT VERSION() AS version')->version,
        'loopback' => in_array($db->getConfig('host'), ['localhost', '127.0.0.1', '::1'], true),
        'global_create_database' => (bool) preg_match('/GRANT (ALL PRIVILEGES|[^\n]*\bCREATE\b[^\n]*) ON \*\.\*/i', $grants),
        'temporary_tables' => (bool) preg_match('/ALL PRIVILEGES|CREATE TEMPORARY TABLES/i', $grants),
        'account_quotas' => $quotas,
        'pending_migrations' => $pending,
        'non_transactional_tables' => array_values(array_map(fn ($row) => $row->TABLE_NAME, array_filter($tables, fn ($row) => strtoupper($row->ENGINE ?? '') !== 'INNODB'))),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    echo json_encode(['error' => 'Inspection failed', 'driver_code' => (int) ($error->errorInfo[1] ?? 0)])."\n";
    exit(1);
} finally {
    $db->disconnect();
}
