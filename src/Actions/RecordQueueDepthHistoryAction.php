<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Actions;

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\Config\QueueMetricsConfig;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\QueueDepthData;
use PHPeek\LaravelQueueMetrics\Events\QueueDepthThresholdExceeded;
use PHPeek\LaravelQueueMetrics\Support\MetricsConstants;
use PHPeek\LaravelQueueMetrics\Support\RedisMetricsStore;

/**
 * Record queue depth to historical time series for trend analysis.
 */
final readonly class RecordQueueDepthHistoryAction
{
    public function __construct(
        private RedisMetricsStore $storage,
        private QueueMetricsConfig $config,
    ) {}

    public function execute(
        string $connection,
        string $queue,
        int $depth,
    ): void {
        if (! $this->config->enabled) {
            return;
        }

        $redis = $this->storage->driver();
        $now = Carbon::now();
        $key = $this->storage->key('queue_depth_history', $connection, $queue);

        $dataPoint = json_encode([
            'timestamp' => $now->timestamp,
            'depth' => $depth,
        ], JSON_THROW_ON_ERROR);

        // Store as sorted set with timestamp as score
        $redis->addToSortedSet($key, [$dataPoint => (float) $now->timestamp]);

        // Keep only last 24 hours of data
        $cutoff = $now->copy()->subHours(24)->timestamp;
        $redis->removeSortedSetByScore($key, '-inf', (string) $cutoff);

        // Check if depth exceeds threshold and dispatch event for autoscaler
        $threshold = MetricsConstants::QUEUE_DEPTH_THRESHOLD;
        if ($depth > $threshold) {
            $percentageOver = (($depth - $threshold) / $threshold) * 100;

            QueueDepthThresholdExceeded::dispatch(
                new QueueDepthData(
                    $connection,
                    $queue,
                    $depth, // pendingJobs
                    0, // reservedJobs
                    0, // delayedJobs
                    null, // oldestPendingJobAge
                    null, // oldestDelayedJobAge
                    $now // measuredAt
                ),
                $threshold,
                round($percentageOver, 2)
            );
        }
    }
}
