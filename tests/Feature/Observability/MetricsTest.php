<?php

namespace Tests\Feature\Observability;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Feature\Delivery\DeliveryTestCase;

/**
 * Prometheus endpoint (Task03 phase 5). Metrics are stored in memory during
 * tests (phpunit.xml: METRICS_STORAGE=memory).
 */
class MetricsTest extends DeliveryTestCase
{
    public function test_metrics_endpoint_is_hidden_on_the_public_port(): void
    {
        $this->get('/metrics')->assertNotFound();

        // A header cannot impersonate nginx's internal FastCGI parameter.
        $this->get('/metrics', ['Metrics-Internal' => '1'])->assertNotFound();
    }

    public function test_internal_scrape_returns_prometheus_text_format(): void
    {
        $this->scrape()
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->assertSee('# TYPE laravel_queue_size gauge', false)
            ->assertSee('laravel_queue_failed_jobs', false);
    }

    public function test_requests_are_labelled_by_route_template_not_by_url(): void
    {
        Route::get('/__probe/{id}', fn () => 'ok');
        Route::get('/__probe-fail', fn () => throw new RuntimeException('boom'));

        $this->get('/__probe/41')->assertOk();
        $this->get('/__probe/42')->assertOk();
        $this->get('/__probe-fail')->assertStatus(500);

        $body = $this->scrape()->getContent();

        // Two different ids -> one time series with value 2.
        $this->assertStringContainsString(
            'laravel_http_requests_total{method="GET",route="__probe/{id}",status="200"} 2', $body);
        $this->assertStringContainsString(
            'laravel_http_requests_total{method="GET",route="__probe-fail",status="500"} 1', $body);
        $this->assertStringNotContainsString('__probe/41', $body);
        $this->assertStringContainsString('laravel_http_request_duration_seconds_bucket', $body);
    }

    public function test_the_scrape_itself_is_not_counted_as_traffic(): void
    {
        $this->scrape();

        $this->assertStringNotContainsString('route="metrics"', $this->scrape()->getContent());
    }

    public function test_jobs_are_counted_with_outcome_and_duration(): void
    {
        MetricsProbeJob::dispatch(false);

        try {
            MetricsProbeJob::dispatch(true);
        } catch (RuntimeException) {
            // sync queue rethrows; the failure must still be counted
        }

        $body = $this->scrape()->getContent();
        $job = addslashes(MetricsProbeJob::class);

        $this->assertStringContainsString(
            "laravel_queue_jobs_total{connection=\"sync\",queue=\"sync\",job=\"{$job}\",status=\"processed\"} 1", $body);
        $this->assertStringContainsString(
            "laravel_queue_jobs_total{connection=\"sync\",queue=\"sync\",job=\"{$job}\",status=\"failed\"} 1", $body);
        $this->assertStringContainsString('laravel_queue_job_duration_seconds_count', $body);
    }

    private function scrape()
    {
        return $this->withServerVariables(['METRICS_INTERNAL' => '1'])->get('/metrics');
    }
}

class MetricsProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public bool $fail) {}

    public function handle(): void
    {
        if ($this->fail) {
            throw new RuntimeException('probe failure');
        }
    }
}
