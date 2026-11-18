<?php

namespace App\Jobs\Lab;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Failed-job scenario. Simulates a call to an external payment gateway.
 *
 *  mode=flaky      fails on attempts 1 and 2, succeeds on attempt 3:
 *                  a transient error that retries + backoff absorb.
 *  mode=permanent  fails while the "gateway is down" flag is set. All
 *                  3 attempts fail -> the job lands in failed_jobs.
 *                  Fix: POST /lab/jobs/failing/fix, then retry it
 *                  (Horizon "Retry" button or `php artisan queue:retry <id>`).
 */
class LabFailingJob extends LabJob
{
    public const GATEWAY_DOWN_KEY = 'lab:payment-gateway-down';

    public int $tries = 3;

    /** Seconds to wait before attempt 2 and attempt 3 (exponential-ish). */
    public array $backoff = [5, 15];

    public function __construct(public readonly string $mode = 'permanent')
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $attempt = $this->attempts();

        if ($this->mode === 'flaky' && $attempt < 3) {
            throw new RuntimeException("Payment gateway timed out (attempt {$attempt}, will retry)");
        }

        if ($this->mode === 'permanent' && Cache::get(self::GATEWAY_DOWN_KEY)) {
            throw new RuntimeException('Payment gateway returned 503 Service Unavailable');
        }

        Log::info('Lab payment charged', ['mode' => $this->mode, 'attempt' => $attempt]);
    }

    /** Called once, after the last attempt failed. Alert / compensate here. */
    public function failed(\Throwable $e): void
    {
        Log::error('Lab payment job gave up', ['mode' => $this->mode, 'error' => $e->getMessage()]);
    }
}
