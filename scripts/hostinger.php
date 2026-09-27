<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Run through SSH only. Never expose deployment actions as web endpoints.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = dirname(__DIR__);
$install = in_array('--install', $argv, true);
$fixKey = in_array('--fix-key', $argv, true);
$failed = false;
$check = static function (bool $ok, string $message) use (&$failed): void {
    echo ($ok ? '[OK] ' : '[ERRO] ').$message.PHP_EOL;
    $failed = $failed || ! $ok;
};

$check(version_compare(PHP_VERSION, '8.2.0', '>='), 'PHP 8.2 ou superior; versão atual: '.PHP_VERSION);
foreach (['ctype', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pcre', 'PDO', 'session', 'tokenizer', 'xml'] as $extension) {
    $check(extension_loaded($extension), 'Extensão '.$extension);
}
$check(is_file($base.'/vendor/autoload.php'), 'Dependências vendor instaladas');
$check(is_file($base.'/.env'), 'Arquivo .env presente na pasta privada poseitech');
foreach (['storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $folder) {
    $path = $base.'/'.$folder;
    if (($install || $fixKey) && ! is_dir($path)) {
        mkdir($path, 0755, true);
    }
    $check(is_dir($path) && is_writable($path), $folder.' existe e permite escrita');
}
if ($failed) {
    echo 'Corrija os itens acima antes de continuar. Não use permissões 777.'.PHP_EOL;
    exit(1);
}

if ($install || $fixKey) {
    $environment = file_get_contents($base.'/.env');
    if (! preg_match('/^\s*APP_KEY\s*=/m', $environment)) {
        if (! is_writable($base.'/.env')) {
            fwrite(STDERR, '[ERRO] O arquivo .env não permite escrita.'.PHP_EOL);
            exit(1);
        }
        file_put_contents($base.'/.env', PHP_EOL.'APP_KEY='.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    // These are generated caches, never customer data, secrets, or uploaded files.
    foreach (['config.php', 'events.php', 'routes-v7.php', 'packages.php', 'services.php'] as $cache) {
        if (is_file($base.'/bootstrap/cache/'.$cache)) {
            unlink($base.'/bootstrap/cache/'.$cache);
        }
    }
    foreach (glob($base.'/storage/framework/views/*.php') ?: [] as $view) {
        if (! is_link($view)) {
            unlink($view);
        }
    }
}

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
try {
    $kernel->bootstrap();
    if (($install || $fixKey) && ! config('app.key')) {
        $code = $kernel->call('key:generate', ['--force' => true]);
        if ($code !== 0 || ! config('app.key')) {
            throw new RuntimeException('Não foi possível gerar APP_KEY.');
        }
        echo '[OK] APP_KEY inicial gerada. Chaves existentes nunca são substituídas.'.PHP_EOL;
    }
    $check((bool) config('app.key'), 'APP_KEY configurada');
    if ($fixKey) {
        if ($failed) {
            exit(1);
        }
        echo 'Chave verificada e caches antigos removidos. Dados e senhas do banco não foram alterados. Atualize a página do site.'.PHP_EOL;
        exit(0);
    }
    $check(config('app.env') === 'production', 'APP_ENV=production');
    $check(! config('app.debug'), 'APP_DEBUG=false');
    $check(str_starts_with(config('app.url'), 'https://') && ! str_contains(config('app.url'), 'seu-dominio'), 'APP_URL contém o domínio real com HTTPS');
    $connection = config('database.default');
    $check(in_array($connection, ['mysql', 'mariadb']), 'MySQL/MariaDB selecionado para hospedagem');
    $check(extension_loaded('pdo_mysql'), 'Extensão pdo_mysql');
    if ($failed) {
        exit(1);
    }
    try {
        DB::connection()->getPdo();
        $check(true, 'Conexão com o banco');
    } catch (Throwable $e) {
        $check(false, 'Conexão falhou. Confira DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME e DB_PASSWORD no hPanel/.env. Código: '.$e->getCode());
        exit(1);
    }
    if ($install) {
        foreach ([['migrate', ['--force' => true]], ['db:seed', ['--force' => true]], ['route:cache', []], ['view:cache', []]] as [$command, $arguments]) {
            $code = $kernel->call($command, $arguments);
            echo $kernel->output();
            if ($code !== 0) {
                throw new RuntimeException('Falha no comando '.$command);
            }
        }
    }
    foreach (['users', 'companies', 'plans', 'sales', 'accounts', 'platform_settings', 'customer_credits', 'sessions', 'cache'] as $table) {
        $check(Schema::hasTable($table), 'Tabela '.$table);
    }
    $pending = array_diff(array_keys($app->make('migrator')->getMigrationFiles([$base.'/database/migrations'])), $app->make('migration.repository')->getRan());
    $check(! $pending, 'Migrations atualizadas');
    echo $failed ? 'Execute php scripts/hostinger.php --install após corrigir a configuração.'.PHP_EOL : 'Instalação verificada. Abra o domínio e cadastre a primeira empresa.'.PHP_EOL;
    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    // Detailed errors remain in the private server logs, not in a public page.
    fwrite(STDERR, '[ERRO] Instalação interrompida: '.get_class($e).'. Consulte storage/logs/laravel.log e o log PHP da hospedagem.'.PHP_EOL);
    exit(1);
}
