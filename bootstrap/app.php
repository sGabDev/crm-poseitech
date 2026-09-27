<?php

use App\Http\Middleware\CompanyAccess;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['company' => CompanyAccess::class]);
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [RequirePasswordChange::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

if (! is_dir($app->basePath('public')) && is_dir(dirname($app->basePath()).'/public_html')) {
    $app->usePublicPath(dirname($app->basePath()).'/public_html');
}

return $app;
