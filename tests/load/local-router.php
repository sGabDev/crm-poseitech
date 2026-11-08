<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
$app = require __DIR__.'/local-bootstrap.php';
try {
    $app->make(Kernel::class)->bootstrap();
} catch (Throwable $error) {
    error_log((string) $error);
    http_response_code(500);
    exit;
}
$app->handleRequest(Request::capture());
