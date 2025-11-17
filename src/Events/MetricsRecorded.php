<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Events;

use Illuminate\Foundation\Events\Dispatchable;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\JobMetricsData;

/**
 * Fired when new metrics are recorded.
 * High-frequency event for real-time monitoring.
 */
final class MetricsRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly JobMetricsData $metrics,
    ) {}
}
