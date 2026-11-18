<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Metrics\Metrics;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis as RedisStorage;

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
    /** @var array<string|int, float> job uuid => start time (per worker process) */
    private array $jobStartedAt = [];

    public function register(): void
    {
        $this->app->singleton(Metrics::class, function () {
            $config = config('metrics');

            if ($config['storage'] === 'redis') {
                RedisStorage::setPrefix($config['redis']['prefix']);
                $storage = new RedisStorage($config['redis']);
            } else {
                $storage = new InMemory;
            }

            // false = do not export the client's default php_info metric.
            return new Metrics(new CollectorRegistry($storage, false), $config);
        });

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

        $this->recordQueueMetrics();

        // Monitoring lab (routes/api.php, prefix /api/v1/lab).
        RateLimiter::for('lab', fn (Request $request) => Limit::perMinute(config('lab.rate_limits.per_minute'))->by($request->ip()));
        RateLimiter::for('lab-heavy', fn (Request $request) => Limit::perMinute(config('lab.rate_limits.heavy_per_minute'))->by($request->ip()));
    }

    /**
     * Job count + duration for Prometheus. These events fire inside the
     * worker (Horizon) process, which is long-lived, so the start time can be
     * kept in memory between JobProcessing and JobProcessed/JobFailed.
     */
    private function recordQueueMetrics(): void
    {
        if (! config('metrics.enabled')) {
            return;
        }

        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            $this->jobStartedAt[$event->job->uuid() ?? spl_object_id($event->job)] = microtime(true);
        });

        $finish = function (JobProcessed|JobFailed $event, string $status) {
            $key = $event->job->uuid() ?? spl_object_id($event->job);
            $startedAt = $this->jobStartedAt[$key] ?? null;
            unset($this->jobStartedAt[$key]);

            $this->app->make(Metrics::class)->recordJob(
                $event->connectionName,
                $event->job->getQueue() ?? 'default',
                $event->job->resolveName(),
                $status,
                $startedAt ? microtime(true) - $startedAt : null,
            );
        };

        Event::listen(JobProcessed::class, fn (JobProcessed $e) => $finish($e, 'processed'));
        Event::listen(JobFailed::class, fn (JobFailed $e) => $finish($e, 'failed'));
    }

    /** Shared rule for Telescope, Pulse and Horizon dashboards. */
    public static function isObservabilityAdmin(?User $user): bool
    {
        return $user !== null
            && in_array($user->email, config('observability.admin_emails', []), true);
    }
}
