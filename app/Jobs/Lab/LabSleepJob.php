<?php

namespace App\Jobs\Lab;

/**
 * Backlog scenario: one of many small jobs (a "flood" of e-mails, webhooks,
 * thumbnails...). Each takes a little time, so with only 2 lab workers the
 * queue drains slowly and the backlog becomes visible.
 */
class LabSleepJob extends LabJob
{
    public int $tries = 1;

    public function __construct(public readonly int $milliseconds)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        usleep($this->milliseconds * 1000);
    }
}
