<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Task03 – observability housekeeping
|--------------------------------------------------------------------------
| Executed by the `scheduler` container (php artisan schedule:work).
| In production you would instead run `php artisan schedule:run` every
| minute from cron / a Kubernetes CronJob.
*/

// Horizon "Metrics" tab: throughput and runtime graphs are built from these
// snapshots. Without this schedule the Metrics tab stays empty.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Telescope writes every query/request into telescope_entries; without
// pruning that table grows by millions of rows. Telescope only exists in
// local (dev dependency), so only schedule it when the command is available.
if (class_exists(\Laravel\Telescope\Telescope::class) && app()->environment('local')) {
    Schedule::command('telescope:prune --hours=48')->daily();
}

// Failed jobs are kept for inspection/retry, but not forever.
Schedule::command('queue:prune-failed --hours=168')->daily();
