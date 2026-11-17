<?php

namespace App\Support\Metrics;

use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use Throwable;

/**
 * Application metrics for Prometheus (Task03 – phase 5).
 *
 * Two kinds of metrics:
 *
 *  1. Event metrics (counters, histograms) – recorded when something happens
 *     (a request finished, a job ran) and accumulated in Redis, because each
 *     PHP-FPM request is a fresh process with no shared memory.
 *
 *  2. State metrics (gauges) – "how many jobs are waiting right now?". These
 *     are measured at scrape time, so they are never stale.
 *
 * Monitoring must never take the application down: if Redis is unreachable,
 * recording is skipped and one warning is logged per process.
 */
class Metrics
{
    private static bool $warned = false;

    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly array $config,
    ) {}

    public function recordHttpRequest(string $method, string $route, int $status, float $seconds, int $peakMemoryBytes): void
    {
        $this->safely(function () use ($method, $route, $status, $seconds, $peakMemoryBytes) {
            $ns = $this->config['namespace'];

            $this->registry->getOrRegisterCounter($ns, 'http_requests_total',
                'HTTP requests handled, by route template and status code.',
                ['method', 'route', 'status'],
            )->inc([$method, $route, (string) $status]);

            $this->registry->getOrRegisterHistogram($ns, 'http_request_duration_seconds',
                'Time from PHP start to response, by route template.',
                ['method', 'route'], $this->config['buckets']['http_seconds'],
            )->observe($seconds, [$method, $route]);

            $this->registry->getOrRegisterHistogram($ns, 'http_request_peak_memory_bytes',
                'Peak PHP memory used while handling the request.',
                ['route'], $this->config['buckets']['memory_bytes'],
            )->observe($peakMemoryBytes, [$route]);
        });
    }

    /** @param  'processed'|'failed'  $status */
    public function recordJob(string $connection, string $queue, string $job, string $status, ?float $seconds): void
    {
        $this->safely(function () use ($connection, $queue, $job, $status, $seconds) {
            $ns = $this->config['namespace'];

            $this->registry->getOrRegisterCounter($ns, 'queue_jobs_total',
                'Queue jobs finished, by outcome.',
                ['connection', 'queue', 'job', 'status'],
            )->inc([$connection, $queue, $job, $status]);

            if ($seconds !== null) {
                $this->registry->getOrRegisterHistogram($ns, 'queue_job_duration_seconds',
                    'Job execution time inside the worker.',
                    ['queue', 'job'], $this->config['buckets']['job_seconds'],
                )->observe($seconds, [$queue, $job]);
            }
        });
    }

    /** Prometheus text exposition format: event metrics + fresh gauges. */
    public function render(): string
    {
        $samples = [];

        $this->safely(function () use (&$samples) {
            $samples = $this->registry->getMetricFamilySamples();
        });

        return (new RenderTextFormat)->render(array_merge($samples, $this->stateGauges()));
    }

    private function stateGauges(): array
    {
        $gauges = new CollectorRegistry(new InMemory, false);
        $ns = $this->config['namespace'];

        $size = $gauges->getOrRegisterGauge($ns, 'queue_size',
            'Jobs in the queue right now, by state.', ['connection', 'queue', 'state']);

        foreach ($this->config['queues'] as $connection => $queues) {
            foreach ($queues as $queue) {
                $this->safely(function () use ($size, $connection, $queue) {
                    $q = Queue::connection($connection);

                    if (! $q instanceof RedisQueue) {
                        $size->set($q->size($queue), [$connection, $queue, 'pending']);

                        return;
                    }

                    // The Redis queue driver keeps three structures per queue:
                    //   queues:{name}           LIST  pending: waiting for a worker
                    //   queues:{name}:delayed   ZSET  scheduled for later / backoff
                    //   queues:{name}:reserved  ZSET  being processed right now
                    $redis = $q->getConnection();
                    $key = $q->getQueue($queue);
                    $size->set($redis->llen($key), [$connection, $queue, 'pending']);
                    $size->set($redis->zcard($key.':delayed'), [$connection, $queue, 'delayed']);
                    $size->set($redis->zcard($key.':reserved'), [$connection, $queue, 'reserved']);
                });
            }
        }

        $this->safely(function () use ($gauges, $ns) {
            $gauges->getOrRegisterGauge($ns, 'queue_failed_jobs',
                'Rows in failed_jobs (waiting to be retried or pruned).')
                ->set(DB::table(config('queue.failed.table', 'failed_jobs'))->count());
        });

        return $gauges->getMetricFamilySamples();
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            if (! self::$warned) {
                self::$warned = true;
                Log::warning('Metrics backend unavailable, skipping: '.$e->getMessage());
            }
        }
    }
}
