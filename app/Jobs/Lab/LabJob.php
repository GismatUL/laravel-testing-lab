<?php

namespace App\Jobs\Lab;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Base for monitoring-lab jobs: always routed to the isolated `lab` pool
 * (connection redis-long, retry_after 330 s, worker timeout 300 s).
 */
abstract class LabJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onConnection(config('lab.queue_connection'));
        $this->onQueue(config('lab.queue'));
    }

    /** Horizon shows these tags; filter by "lab" to see only experiments. */
    public function tags(): array
    {
        return ['lab', 'lab:'.class_basename($this)];
    }
}
