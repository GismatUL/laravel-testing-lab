<?php

use App\Support\Metrics\Metrics;
use Illuminate\Support\Facades\Route;
use Prometheus\RenderTextFormat;

/*
| Prometheus scrape target (Task03). Loaded from bootstrap/app.php behind
| EnsureInternalMetricsRequest: only reachable via nginx's internal port.
*/
Route::get('/metrics', fn (Metrics $metrics) => response($metrics->render(), 200, [
    'Content-Type' => RenderTextFormat::MIME_TYPE,
]))->name('metrics');
