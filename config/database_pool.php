<?php

// One external pool per backend instance, shared by all PHP workers and tenants.
// These settings are applied by poseitech:pool-config, not by PDO itself.
return [
    'cooldown' => (int) env('DB_CAPACITY_COOLDOWN_SECONDS', 300),
    'size' => (int) env('DB_POOL_SIZE', 10),
    'clients' => (int) env('DB_POOL_CLIENTS', 32),
    'wait_ms' => (int) env('DB_POOL_WAIT_TIMEOUT_MS', 3000),
    'query_ms' => (int) env('DB_QUERY_TIMEOUT_MS', 10000),
    'instances' => (int) env('DB_POOL_INSTANCES', 1),
    'reserve' => (int) env('DB_CONNECTION_RESERVE', 5),
    'budget' => (int) env('DB_CONNECTION_BUDGET', 0),
    'upstream_host' => env('DB_UPSTREAM_HOST'),
    'upstream_port' => (int) env('DB_UPSTREAM_PORT', 3306),
];
