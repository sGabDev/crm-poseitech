<?php

// Only the local runner may select this isolated storage/database.
if (getenv('LOCAL_LOAD_TEST') !== 'isolated' || ! getenv('LOAD_STORAGE')) {
    throw new RuntimeException('Run node scripts/load-local.cjs.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useStoragePath(getenv('LOAD_STORAGE'));
if (DIRECTORY_SEPARATOR === '\\') {
    $app->addAbsoluteCachePathPrefix(substr(getenv('LOAD_STORAGE'), 0, 3));
}

return $app;
