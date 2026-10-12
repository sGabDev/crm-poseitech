<?php

use App\Http\Middleware\CompanyAccess;
use App\Http\Middleware\DatabaseCapacityCooldown;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\SecurityHeaders;
use App\Services\DatabaseCapacity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(DatabaseCapacityCooldown::class);
        $middleware->alias(['company' => CompanyAccess::class]);
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [RequirePasswordChange::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (QueryException $error) {
            if (DatabaseCapacity::exhausted($error)) {
                DatabaseCapacity::pause();
            }
        });
        $exceptions->render(function (QueryException $error, Request $request) {
            if (DatabaseCapacity::exhausted($error)) {
                DatabaseCapacity::pause();

                return DatabaseCapacity::response($request);
            }
        });
    })->create();

if (! is_dir($app->basePath('public')) && is_dir(dirname($app->basePath()).'/public_html')) {
    $app->usePublicPath(dirname($app->basePath()).'/public_html');
}

return $app;
