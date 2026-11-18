<?php

namespace App\Jobs\Lab;

use Illuminate\Support\Facades\Log;

/**
 * Long-running job scenario (a big export, a video encode...).
 *
 * Works in 1-second steps and logs progress every 10 s, so you can follow
 * it in the logs while Horizon shows it as "reserved".
 *
 * $timeout = 300 matches the lab supervisor. With seconds > 300 the worker
 * process is killed mid-job (Horizon starts a new one) and the job is
 * marked failed with TimeoutExceededException: that is the lesson.
 */
class LabLongRunningJob extends LabJob
{
    public int $tries = 1;

    public int $timeout = 300;

    /** Fail (don't silently retry) when the timeout kills the job. */
    public bool $failOnTimeout = true;

    public function __construct(public readonly int $seconds)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        for ($i = 1; $i <= $this->seconds; $i++) {
            sleep(1);

            if ($i % 10 === 0) {
                Log::info('Lab long-running job progress', ['done' => $i, 'total' => $this->seconds]);
            }
        }
    }
}
