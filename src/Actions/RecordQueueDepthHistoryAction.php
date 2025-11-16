<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Actions;

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\Config\QueueMetricsConfig;
use PHPeek\LaravelQueueMetrics\Storage\StorageManager;

/**
 * Record queue depth to historical time series for trend analysis.
 */
final readonly class RecordQueueDepthHistoryAction
{
    public function __construct(
        private StorageManager $storage,
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
        $redis->pipeline(function ($pipe) use ($key, $cutoff) {
            /** @var \Illuminate\Redis\Connections\Connection $pipe */
            $pipe->zremrangebyscore($key, '-inf', (string) $cutoff);
            $pipe->expire($key, 86400 * 2); // 48 hour TTL
        });
    }
}
