<?php

use App\Services\DatabaseCapacity;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Validate application recovery with a real SELECT, using a private circuit file.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$directory = sys_get_temp_dir().'/crm-recovery-'.bin2hex(random_bytes(8));
mkdir($directory.'/framework', 0700, true);
$app->useStoragePath($directory);
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
try {
    if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
        throw new RuntimeException('MySQL required');
    }
    $error = new PDOException('Synthetic capacity pause');
    $error->errorInfo = ['HY000', 1226, 'Synthetic'];
    DatabaseCapacity::pause($error);
    $before = DatabaseCapacity::remaining();
    $start = microtime(true);
    $recovered = DatabaseCapacity::recoverIfAvailable();
    $after = DatabaseCapacity::remaining();
    echo json_encode(['synthetic_pause_seconds' => $before, 'real_select_recovered' => $recovered, 'pause_after_seconds' => $after, 'recovery_ms' => round((microtime(true) - $start) * 1000)], JSON_THROW_ON_ERROR)."\n";
    $status = $recovered && $before > 0 && $after === 0 ? 0 : 1;
} catch (Throwable $error) {
    echo json_encode(['error' => get_class($error), 'driver_code' => (int) ($error->errorInfo[1] ?? 0)])."\n";
    $status = 1;
} finally {
    $db->disconnect();
    @unlink(DatabaseCapacity::path());
    @unlink(DatabaseCapacity::probePath());
    rmdir($directory.'/framework');
    rmdir($directory);
}
exit($status);
