<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Actions;

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\Config\QueueMetricsConfig;
use PHPeek\LaravelQueueMetrics\Support\RedisMetricsStore;

/**
 * Record throughput to historical time series for trend analysis.
 */
final readonly class RecordThroughputHistoryAction
{
    public function __construct(
        private RedisMetricsStore $storage,
        private QueueMetricsConfig $config,
    ) {}

    public function execute(
        string $connection,
        string $queue,
        int $jobsProcessed,
    ): void {
        if (! $this->config->enabled) {
            return;
        }

        $redis = $this->storage->driver();
        $now = Carbon::now();
        $key = $this->storage->key('throughput_history', $connection, $queue);

        $dataPoint = json_encode([
            'timestamp' => $now->timestamp,
            'jobs_processed' => $jobsProcessed,
        ], JSON_THROW_ON_ERROR);

        $redis->addToSortedSet($key, [$dataPoint => (float) $now->timestamp]);

        // Keep only last 24 hours
        $cutoff = $now->copy()->subHours(24)->timestamp;
        $redis->pipeline(function ($pipe) use ($key, $cutoff) {
            /** @var \Illuminate\Redis\Connections\Connection $pipe */
            $pipe->zremrangebyscore($key, '-inf', (string) $cutoff);
            $pipe->expire($key, 86400 * 2);
        });
    }
}
