<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Task03 – wiring for monitoring tools, kept out of AppServiceProvider.
 *
 * Telescope is a *dev* dependency (composer require --dev) and is excluded
 * from package auto-discovery (composer.json -> extra.laravel.dont-discover),
 * so it is only booted when this provider decides to:
 *   - the package is installed (production installs use --no-dev), AND
 *   - the environment is listed in observability.telescope_environments.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (
            class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)
            && $this->app->environment(config('observability.telescope_environments', ['local']))
        ) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    public function boot(): void
    {
        // Overrides Pulse's default gate (which only allows `local`), so we
        // must keep the local rule ourselves and add the admin allow-list.
        Gate::define('viewPulse', fn (?User $user = null) => $this->app->environment('local')
            || self::isObservabilityAdmin($user));
    }

    /** Shared rule for Telescope, Pulse and Horizon dashboards. */
    public static function isObservabilityAdmin(?User $user): bool
    {
        return $user !== null
            && in_array($user->email, config('observability.admin_emails', []), true);
    }
}
