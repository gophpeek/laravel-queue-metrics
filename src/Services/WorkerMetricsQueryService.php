<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Services;

use Illuminate\Support\Collection;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\WorkerHeartbeat;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\WorkerStatsData;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerRepository;

/**
 * Service for querying worker-specific metrics.
 */
final readonly class WorkerMetricsQueryService
{
    public function __construct(
        private WorkerRepository $workerRepository,
        private WorkerHeartbeatRepository $workerHeartbeatRepository,
    ) {}

    /**
     * Get all active workers with their stats.
     *
     * @return Collection<int, WorkerStatsData>
     */
    public function getActiveWorkers(
        ?string $connection = null,
        ?string $queue = null,
    ): Collection {
        $workers = $this->workerRepository->getActiveWorkers($connection, $queue);

        return collect($workers);
    }

    /**
     * Get all worker heartbeats.
     *
     * @return Collection<int, WorkerHeartbeat>
     */
    public function getWorkerHeartbeats(
        ?string $connection = null,
        ?string $queue = null,
    ): Collection {
        $workers = $this->workerHeartbeatRepository->getActiveWorkers($connection, $queue);

        return collect($workers);
    }

    /**
     * Get a specific worker heartbeat by ID.
     */
    public function getWorkerHeartbeat(string $workerId): ?WorkerHeartbeat
    {
        return $this->workerHeartbeatRepository->getWorker($workerId);
    }

    /**
     * Detect staled workers (haven't sent heartbeat in threshold seconds).
     */
    public function detectStaledWorkers(int $thresholdSeconds = 60): int
    {
        return $this->workerHeartbeatRepository->detectStaledWorkers($thresholdSeconds);
    }

    /**
     * Get all workers with detailed metrics.
     *
     * @return array<string, mixed>
     */
    public function getAllWorkersWithMetrics(): array
    {
        $summary = $this->getWorkersSummary();

        // Get all active workers from heartbeat repository
        $activeWorkers = $this->workerHeartbeatRepository->getActiveWorkers();

        // Convert WorkerHeartbeat objects to array format
        $workersArray = $activeWorkers->map(function ($worker) {
            return [
                'worker_id' => $worker->workerId,
                'connection' => $worker->connection,
                'queue' => $worker->queue,
                'state' => $worker->state->value,
                'pid' => $worker->pid,
                'hostname' => $worker->hostname,
                'last_heartbeat' => $worker->lastHeartbeat->toIso8601String(),
                'last_state_change' => $worker->lastStateChange?->toIso8601String(),
                'current_job_id' => $worker->currentJobId,
                'current_job_class' => $worker->currentJobClass,
                'jobs_processed' => $worker->jobsProcessed,
                'idle_time_seconds' => $worker->idleTimeSeconds,
                'busy_time_seconds' => $worker->busyTimeSeconds,
                'memory_usage_mb' => $worker->memoryUsageMb,
                'cpu_usage_percent' => $worker->cpuUsagePercent,
                'peak_memory_usage_mb' => $worker->peakMemoryUsageMb,
                'seconds_since_last_heartbeat' => $worker->secondsSinceLastHeartbeat(),
                'is_stale' => $worker->isStale(),
            ];
        })->values()->all();

        return [
            'summary' => $summary,
            'workers' => $workersArray,
        ];
    }

    /**
     * Get workers summary with totals and averages.
     *
     * @return array<string, mixed>
     */
    public function getWorkersSummary(): array
    {
        $workers = $this->workerRepository->getActiveWorkers();
        $total = count($workers);
        $active = 0;
        $idle = 0;
        $totalJobsProcessed = 0;
        $totalIdleTime = 0.0;
        $totalBusyTime = 0.0;

        foreach ($workers as $worker) {
            if ($worker->status === 'active') {
                $active++;
            } elseif ($worker->status === 'idle') {
                $idle++;
            }

            // Get worker stats for jobs processed
            // @phpstan-ignore-next-line - PHPStan knows hostname is string from PHPDoc, but handle gethostname() failure
            $hostname = is_string($worker->hostname) ? $worker->hostname : gethostname();
            // @phpstan-ignore-next-line - gethostname() can return false on failure
            if ($hostname === false) {
                $hostname = 'unknown';
            }

            $stats = $this->workerRepository->getWorkerStats(
                $worker->pid,
                $hostname
            );

            if ($stats) {
                $totalJobsProcessed += $stats->jobsProcessed;
                // Note: idleTime and busyTime not available in WorkerStatsData
                // Using idlePercentage and jobs processed as approximation
                $totalIdleTime += $stats->idlePercentage;
                $totalBusyTime += (100 - $stats->idlePercentage);
            }
        }

        $avgJobsPerWorker = $total > 0 ? $totalJobsProcessed / $total : 0.0;

        $totalTime = $totalIdleTime + $totalBusyTime;
        $avgIdlePercentage = $totalTime > 0 ? ($totalIdleTime / $totalTime) * 100 : 0.0;

        return [
            'total' => $total,
            'active' => $active,
            'idle' => $idle,
            'avg_jobs_per_worker' => round($avgJobsPerWorker, 2),
            'total_jobs_processed' => $totalJobsProcessed,
            'avg_idle_percentage' => round($avgIdlePercentage, 2),
        ];
    }

    /**
     * Get all servers with aggregated metrics per hostname.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllServersWithMetrics(): array
    {
        $servers = [];
        $workers = $this->workerRepository->getActiveWorkers();

        // Group workers by hostname
        foreach ($workers as $worker) {
            $hostname = $worker->hostname ?? 'unknown';

            if (! isset($servers[$hostname])) {
                $servers[$hostname] = [
                    'hostname' => $hostname,
                    'workers' => ['total' => 0, 'active' => 0, 'idle' => 0],
                    'performance' => [
                        'total_jobs_processed' => 0,
                        'total_jobs_failed' => 0,
                        'failure_rate' => 0.0,
                        'jobs_per_minute' => 0.0,
                        'avg_job_duration_ms' => 0.0,
                    ],
                    'resources' => [
                        'total_memory_mb' => 0.0,
                        'avg_memory_per_job_mb' => 0.0,
                        'peak_memory_mb' => 0.0,
                        'cpu_usage' => 0.0,
                        'memory_usage' => 0.0,
                    ],
                    'utilization' => [
                        'server_utilization' => 0.0,
                        'avg_idle_percentage' => 0.0,
                        'capacity_recommendation' => null,
                    ],
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $servers[$hostname]['workers']['total']++;

            // Aggregate worker metrics
            if ($worker->status === 'active') {
                $servers[$hostname]['workers']['active']++;
            } elseif ($worker->status === 'idle') {
                $servers[$hostname]['workers']['idle']++;
            }

            // Aggregate performance metrics from worker stats
            $stats = $this->workerRepository->getWorkerStats($worker->pid, $hostname);
            if ($stats) {
                $servers[$hostname]['performance']['total_jobs_processed'] += $stats->jobsProcessed;
                // Note: Memory metrics not available in WorkerStatsData
                // These would need to be added to the DTO if needed
            }
        }

        // Calculate averages and utilization per server
        foreach ($servers as $hostname => &$server) {
            $totalWorkers = $server['workers']['total'];
            // @phpstan-ignore-next-line - Defensive check even though PHPStan knows totalWorkers >= 1
            if ($totalWorkers > 0) {
                $server['resources']['avg_memory_per_job_mb'] =
                    $server['resources']['total_memory_mb'] / $totalWorkers;

                $activeWorkers = $server['workers']['active'];
                $server['utilization']['server_utilization'] = $activeWorkers / $totalWorkers;
                $server['utilization']['avg_idle_percentage'] =
                    ($server['workers']['idle'] / $totalWorkers) * 100;

                // Add capacity recommendation
                $utilization = $server['utilization']['server_utilization'];
                if ($utilization > 0.9) {
                    $server['utilization']['capacity_recommendation'] =
                        'Consider horizontal scaling: Add more workers or servers';
                } elseif ($utilization < 0.3) {
                    $server['utilization']['capacity_recommendation'] =
                        'Consider reducing worker count to optimize resource usage';
                }
            }
        }

        return $servers;
    }
}
