<?php

// Integration test of the actual CLI repair without touching the workspace .env.
require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$fixture = $root.'/storage/framework/testing/hostinger-'.bin2hex(random_bytes(6));
mkdir($fixture.'/scripts', 0755, true);
mkdir($fixture.'/vendor', 0755, true);
$copy = function (string $source, string $target) use (&$copy): void {
    if (is_dir($source)) {
        if (! is_dir($target)) {
            mkdir($target, 0755, true);
        }
        foreach (scandir($source) as $entry) {
            if ($entry !== '.' && $entry !== '..' && $entry !== 'cache') {
                $copy($source.'/'.$entry, $target.'/'.$entry);
            }
        }
    } else {
        copy($source, $target);
    }
};
foreach (['bootstrap', 'config', 'routes'] as $folder) {
    $copy($root.'/'.$folder, $fixture.'/'.$folder);
}
copy($root.'/composer.json', $fixture.'/composer.json');
copy($root.'/artisan', $fixture.'/artisan');
copy($root.'/scripts/hostinger.php', $fixture.'/scripts/hostinger.php');
file_put_contents($fixture.'/vendor/autoload.php', '<?php return require '.var_export($root.'/vendor/autoload.php', true).';');
file_put_contents($fixture.'/.env', "APP_ENV=production\nAPP_DEBUG=false\nAPP_URL=https://crm.example.test\nDB_CONNECTION=sqlite\nDB_DATABASE=:memory:\nSESSION_DRIVER=file\nCACHE_STORE=file\n");
$run = function (array $arguments = ['--fix-key'], int $expected = 0) use ($fixture): string {
    $process = new Process([PHP_BINARY, 'scripts/hostinger.php', ...$arguments], $fixture, ['APP_KEY' => false]);
    $process->setTimeout(30);
    $process->run();
    if ($process->getExitCode() !== $expected) {
        throw new RuntimeException($process->getOutput().$process->getErrorOutput());
    }

    return $process->getOutput();
};
$remove = function (string $path) use (&$remove, $fixture): void {
    $real = realpath($path);
    $base = realpath($fixture);
    if (! $real || ! $base || ! ($real === $base || str_starts_with($real, $base.DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('Unsafe cleanup path');
    }
    if (is_dir($path) && ! is_link($path)) {
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path.'/'.$entry);
            }
        }rmdir($path);
    } else {
        unlink($path);
    }
};
try {
    $run();
    $first = file_get_contents($fixture.'/.env');
    if (! preg_match('/^APP_KEY=base64:([A-Za-z0-9+\/=]+)\r?$/m', $first, $match) || strlen(base64_decode($match[1], true)) !== 32) {
        throw new RuntimeException('A valid key was not generated');
    }
    file_put_contents($fixture.'/bootstrap/cache/config.php', '<?php throw new RuntimeException("Stale cache loaded");');
    $run();
    if (file_get_contents($fixture.'/.env') !== $first) {
        throw new RuntimeException('An existing key was changed');
    }
    if (is_file($fixture.'/bootstrap/cache/config.php')) {
        throw new RuntimeException('Stale cache was retained');
    }
    $run(['--init-env']);
    if (file_get_contents($fixture.'/.env') !== $first) {
        throw new RuntimeException('Environment initialization overwrote existing settings');
    }
    // Disposable fixture only: reproduce a missing hidden file on a fresh upload.
    unlink($fixture.'/.env');
    $missing = $run(['--fix-key'], 1);
    if (! str_contains(str_replace('\\', '/', $missing), str_replace('\\', '/', $fixture).'/.env') || is_file($fixture.'/.env')) {
        throw new RuntimeException('Missing environment repair must identify the path without inventing a key');
    }
    $run(['--install'], 2);
    $template = file_get_contents($fixture.'/.env');
    if (! str_contains($template, 'APP_ENV=production') || ! str_contains($template, 'PREENCHA_BANCO')) {
        throw new RuntimeException('Production environment template was not created');
    }
    $run(['--install'], 1);
    if (file_get_contents($fixture.'/.env') !== $template) {
        throw new RuntimeException('Incomplete configuration should not generate keys or change settings');
    }
    $run(['--init-env']);
    if (file_get_contents($fixture.'/.env') !== $template) {
        throw new RuntimeException('Existing template was overwritten');
    }
    if (! str_contains($run(['--php-info']), PHP_VERSION)) {
        throw new RuntimeException('CLI runtime diagnostics missing');
    }
    echo "Hostinger: chave preservada; cache removido; .env ausente tratado; modelo criado sem sobrescrita; configuração incompleta bloqueada; runtime identificado.\n";
} finally {
    $remove($fixture);
}
