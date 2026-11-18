<?php

namespace Tests\Feature\Observability;

use App\Models\User;
use App\Providers\ObservabilityServiceProvider;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\TelescopeServiceProvider;
use Tests\TestCase;

/**
 * Monitoring dashboards expose SQL, payloads and stack traces, so access
 * rules are worth a test. Runs in APP_ENV=testing, i.e. "not local".
 *
 * Note: Gate checks for an *authenticated* user also run spatie/laravel-permission's
 * Gate::before hook, which needs the `permissions` table (its migration is
 * currently missing from the repo), so the allow-list rule is tested directly.
 */
class DashboardAccessTest extends TestCase
{
    public function test_telescope_is_not_registered_outside_local(): void
    {
        $this->assertFalse($this->app->providerIsLoaded(TelescopeServiceProvider::class));
    }

    public function test_pulse_dashboard_is_closed_to_guests_outside_local(): void
    {
        $this->assertFalse(Gate::allows('viewPulse'));
        $this->get('/pulse')->assertForbidden();
    }

    public function test_only_allow_listed_emails_are_observability_admins(): void
    {
        config(['observability.admin_emails' => ['ops@example.test']]);

        $this->assertFalse(ObservabilityServiceProvider::isObservabilityAdmin(null));
        $this->assertFalse(ObservabilityServiceProvider::isObservabilityAdmin(new User(['email' => 'someone@example.test'])));
        $this->assertTrue(ObservabilityServiceProvider::isObservabilityAdmin(new User(['email' => 'ops@example.test'])));
    }
}
