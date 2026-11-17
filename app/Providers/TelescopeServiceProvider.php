<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

/**
 * Registered ONLY by ObservabilityServiceProvider (local/testing), never via
 * bootstrap/providers.php, because Telescope is a dev-only package.
 */
class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        // Locally: record everything. Elsewhere: only the interesting stuff.
        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            return $isLocal ||
                   $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->isFailedJob() ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
        });

        // Tag lab scenarios so they are easy to find: Telescope -> search tag "lab".
        Telescope::tag(fn (IncomingEntry $entry) => $entry->type === 'request'
            && str_starts_with((string) ($entry->content['uri'] ?? ''), '/api/v1/lab')
            ? ['lab']
            : []);
    }

    /**
     * Telescope stores request bodies and headers in the database. Never let
     * secrets end up there, not even locally (DB dumps get shared).
     */
    protected function hideSensitiveRequestDetails(): void
    {
        Telescope::hideRequestParameters(['_token', 'password', 'password_confirmation', 'token']);

        Telescope::hideRequestHeaders([
            'authorization',
            'cookie',
            'set-cookie',
            'x-csrf-token',
            'x-xsrf-token',
            'x-lab-token',
        ]);
    }

    /** Checked outside `local`; in `local` Telescope allows everyone. */
    protected function gate(): void
    {
        Gate::define('viewTelescope', fn (?User $user = null) => ObservabilityServiceProvider::isObservabilityAdmin($user));
    }
}
