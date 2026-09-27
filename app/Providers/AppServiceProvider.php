<?php

namespace App\Providers;

use App\Services\Tenant;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(Tenant::class, fn () => new Tenant);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-company', fn ($user) => $user->active && in_array($user->role, ['admin', 'super']));
        Gate::define('platform', fn ($user) => $user->active && $user->role === 'super');
        Paginator::defaultView('components.pagination');
    }
}
