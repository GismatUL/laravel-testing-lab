<?php

namespace App\Exceptions\Lab;

use RuntimeException;

/**
 * Thrown on purpose by the monitoring lab. Unlike App\Exceptions\Delivery\*
 * it IS reported: it stands in for a real bug, so it must reach the log,
 * Telescope's Exceptions tab and Sentry.
 */
class LabScenarioException extends RuntimeException {}
