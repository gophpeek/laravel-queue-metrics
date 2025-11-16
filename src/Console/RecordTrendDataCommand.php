<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Console;

use Illuminate\Console\Command;
use PHPeek\LaravelQueueMetrics\Actions\RecordQueueDepthHistoryAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordThroughputHistoryAction;
use PHPeek\LaravelQueueMetrics\Contracts\QueueInspector;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use PHPeek\LaravelQueueMetrics\Storage\StorageManager;

/**
 * Record historical trend data for analysis.
 */
final class RecordTrendDataCommand extends Command
{
    protected $signature = 'queue-metrics:record-trends';

    protected $description = 'Record queue depth and throughput for trend analysis';

    public function __construct(
        private readonly QueueInspector $queueInspector,
        private readonly QueueMetricsRepository $queueMetrics,
        private readonly WorkerHeartbeatRepository $workerHeartbeat,
        private readonly RecordQueueDepthHistoryAction $recordQueueDepth,
        private readonly RecordThroughputHistoryAction $recordThroughput,
        private readonly StorageManager $storage,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Recording trend data...');

        $recordedQueues = 0;
        $recordedWorkers = 0;

        // Scan for active queues in Redis
        $redis = $this->storage->driver();
        $pattern = $this->storage->key('queue_snapshot', '*', '*');

        /** @var array<string> $keys */
        $keys = $redis->scanKeys($pattern);

        foreach ($keys as $key) {
            try {
                // Extract connection and queue from key
                $parts = explode(':', $key);
                if (count($parts) < 4) {
                    continue;
                }

                $connectionName = $parts[2] ?? 'default';
                $queueName = $parts[3] ?? 'default';

                // Get snapshot data
                /** @var array<string, string> $snapshot */
                $snapshot = $redis->getHash($key);

                if (! empty($snapshot)) {
                    $depth = (int) ($snapshot['size'] ?? 0);
                    $jobsProcessed = (int) ($snapshot['jobs_processed'] ?? 0);

                    $this->recordQueueDepth->execute($connectionName, $queueName, $depth);
                    $this->recordThroughput->execute($connectionName, $queueName, $jobsProcessed);

                    $recordedQueues++;
                }
            } catch (\Exception $e) {
                $this->warn("Failed to record trend for key {$key} - {$e->getMessage()}");
            }
        }

        // Record worker efficiency metrics
        try {
            $workers = $this->workerHeartbeat->getActiveWorkers();

            if ($workers->isNotEmpty()) {
                $totalBusyTime = 0.0;
                $totalIdleTime = 0.0;
                $totalMemory = 0.0;
                $totalCpu = 0.0;

                foreach ($workers as $worker) {
                    $totalBusyTime += $worker->busyTimeSeconds;
                    $totalIdleTime += $worker->idleTimeSeconds;
                    $totalMemory += $worker->memoryUsageMb;
                    $totalCpu += $worker->cpuUsagePercent;
                }

                $workerCount = $workers->count();
                $totalTime = $totalBusyTime + $totalIdleTime;
                $efficiency = $totalTime > 0 ? ($totalBusyTime / $totalTime) * 100 : 0;

                $redis = $this->storage->driver();
                $key = $this->storage->key('worker_efficiency_history');
                $now = time();

                $dataPoint = json_encode([
                    'timestamp' => $now,
                    'efficiency' => $efficiency,
                    'avg_memory_mb' => $totalMemory / $workerCount,
                    'avg_cpu_percent' => $totalCpu / $workerCount,
                ], JSON_THROW_ON_ERROR);

                $redis->addToSortedSet($key, [$dataPoint => (float) $now]);

                // Cleanup old data
                $cutoff = $now - (86400 * 7); // Keep 7 days
                $redis->pipeline(function ($pipe) use ($key, $cutoff) {
                    /** @var \Illuminate\Redis\Connections\Connection $pipe */
                    $pipe->zremrangebyscore($key, '-inf', (string) $cutoff);
                    $pipe->expire($key, 86400 * 8);
                });

                $recordedWorkers = $workerCount;
            }
        } catch (\Exception $e) {
            $this->warn("Failed to record worker efficiency - {$e->getMessage()}");
        }

        $this->info("Recorded trends for {$recordedQueues} queues and {$recordedWorkers} workers");

        return self::SUCCESS;
    }
}
