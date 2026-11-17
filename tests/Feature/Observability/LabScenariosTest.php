<?php

namespace Tests\Feature\Observability;

use App\Exceptions\Lab\LabScenarioException;
use App\Jobs\Lab\LabFailingJob;
use App\Jobs\Lab\LabLongRunningJob;
use App\Jobs\Lab\LabSleepJob;
use App\Enums\Delivery\CourierStatus;
use App\Models\Delivery\Customer;
use App\Models\Delivery\DeliveryOrder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Delivery\DeliveryTestCase;

class LabScenariosTest extends DeliveryTestCase
{
    private const LAB = '/api/v1/lab';

    private const TOKEN = 'test-lab-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['lab.enabled' => true, 'lab.token' => self::TOKEN]);
    }

    // ── Safety rails ──────────────────────────────────────────────────────

    public function test_lab_is_hidden_when_disabled(): void
    {
        config(['lab.enabled' => false]);

        $this->lab('get', '/slow?seconds=0')->assertNotFound();
    }

    public function test_lab_is_hidden_without_a_valid_token(): void
    {
        $this->getJson(self::LAB.'/slow?seconds=0')->assertNotFound();
        $this->getJson(self::LAB.'/slow?seconds=0', ['X-Lab-Token' => 'wrong'])->assertNotFound();
    }

    public function test_lab_is_hidden_without_a_configured_token(): void
    {
        config(['lab.token' => '']);

        $this->getJson(self::LAB.'/slow?seconds=0', ['X-Lab-Token' => ''])->assertNotFound();
    }

    public function test_lab_is_always_off_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->lab('get', '/slow?seconds=0')->assertNotFound();
    }

    public function test_heavy_scenarios_are_rate_limited(): void
    {
        config(['lab.rate_limits.heavy_per_minute' => 2]);

        $this->lab('get', '/cpu?seconds=0')->assertOk();
        $this->lab('get', '/cpu?seconds=0')->assertOk();
        $this->lab('get', '/cpu?seconds=0')->assertStatus(429);
    }

    public function test_inputs_are_clamped_to_configured_limits(): void
    {
        config(['lab.limits.slow_seconds_max' => 0]);

        $this->lab('get', '/slow?seconds=999')->assertOk()->assertJsonPath('slept_seconds', fn ($s) => $s == 0);
    }

    // ── Scenarios ─────────────────────────────────────────────────────────

    public function test_index_lists_ten_scenarios(): void
    {
        $this->lab('get', '/')->assertOk()->assertJsonCount(10, 'scenarios');
    }

    public function test_n_plus_one_runs_one_query_per_relation_per_row_and_eager_loading_fixes_it(): void
    {
        $customer = Customer::factory()->create();
        foreach (range(1, 5) as $_) {
            $courier = $this->courierAt(self::LAT, self::LNG, CourierStatus::Busy);
            DeliveryOrder::factory()->for($customer)->pickupAt(self::LAT, self::LNG)
                ->create(['courier_id' => $courier->id, 'status' => 'delivered']);
        }

        // 1 (orders) + 5 couriers + 5 customers
        $this->lab('get', '/n-plus-one')->assertOk()->assertJsonPath('queries_run', 11);
        // 1 (orders) + 1 (couriers) + 1 (customers)
        $this->lab('get', '/n-plus-one?fixed=1')->assertOk()->assertJsonPath('queries_run', 3);
    }

    public function test_expensive_sql_slow_and_fixed_variants_return_the_same_rows(): void
    {
        $this->pendingOrderAt(self::LAT, self::LNG);
        $this->pendingOrderAt(self::LAT + 0.001, self::LNG);

        $slow = $this->lab('get', '/expensive-sql')->assertOk()->json();
        $fast = $this->lab('get', '/expensive-sql?fixed=1')->assertOk()->json();

        $this->assertSame(2, $slow['rows']);
        $this->assertSame($slow['rows'], $fast['rows']);
        $this->assertStringContainsString('ST_Distance', $slow['sql']);
        $this->assertStringContainsString('ST_DWithin', $fast['sql']);
    }

    public function test_exceptions_are_500_and_reported(): void
    {
        Exceptions::fake();

        $this->lab('get', '/exception')->assertStatus(500);
        $this->lab('get', '/exception?type=query')->assertStatus(500);

        Exceptions::assertReported(LabScenarioException::class);
        Exceptions::assertReported(\Illuminate\Database\QueryException::class);
    }

    public function test_jobs_go_to_the_isolated_lab_pool(): void
    {
        Queue::fake();

        $this->lab('post', '/jobs/failing?mode=flaky')->assertOk();
        $this->lab('post', '/jobs/long-running?seconds=5')->assertOk();

        Queue::assertPushedOn('lab', LabFailingJob::class, fn ($job) => $job->connection === 'redis-long');
        Queue::assertPushedOn('lab', LabLongRunningJob::class, fn ($job) => $job->connection === 'redis-long');
    }

    public function test_backlog_pushes_the_requested_number_of_jobs_capped_at_the_limit(): void
    {
        Queue::fake();
        config(['lab.limits.backlog_jobs_max' => 25]);

        $this->lab('post', '/jobs/backlog?count=5000&ms=0')->assertOk()->assertJsonPath('jobs_pushed', 25);

        Queue::assertPushed(LabSleepJob::class, 25);
    }

    public function test_permanent_failure_until_fixed_then_retry_succeeds(): void
    {
        // QUEUE_CONNECTION=sync in tests: the job runs inside dispatch().
        config(['lab.queue_connection' => 'sync']);
        Exceptions::fake();

        $this->lab('post', '/jobs/failing?mode=permanent')->assertStatus(500);
        $this->assertTrue(Cache::get(LabFailingJob::GATEWAY_DOWN_KEY));

        $this->lab('post', '/jobs/failing/fix')->assertOk();
        $this->assertNull(Cache::get(LabFailingJob::GATEWAY_DOWN_KEY));

        // "Retry": the same job now succeeds.
        LabFailingJob::dispatchSync('permanent');
        Exceptions::assertReported(fn (\RuntimeException $e) => str_contains($e->getMessage(), '503'));
    }

    public function test_memory_and_cpu_report_what_they_did(): void
    {
        $this->lab('get', '/memory?mb=8')->assertOk()->assertJsonPath('allocated_mb', 8);
        $this->lab('get', '/cpu?seconds=0')->assertOk()->assertJsonStructure(['sha256_rounds']);
    }

    public function test_logs_are_written_at_every_level(): void
    {
        Log::spy();

        $this->lab('post', '/logs')->assertOk()->assertJsonCount(8, 'levels');

        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
            Log::shouldHaveReceived($level)->once();
        }
    }

    private function lab(string $method, string $uri)
    {
        $headers = ['X-Lab-Token' => self::TOKEN];

        return $method === 'get'
            ? $this->getJson(self::LAB.$uri, $headers)
            : $this->postJson(self::LAB.$uri, [], $headers);
    }
}
