<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    App\Providers\ObservabilityServiceProvider::class, // registers Telescope only in local/testing
];
