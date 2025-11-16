<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Actions;

use PHPeek\LaravelQueueMetrics\Enums\WorkerState;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;

/**
 * Record worker heartbeat with current state.
 */
final readonly class RecordWorkerHeartbeatAction
{
    public function __construct(
        private WorkerHeartbeatRepository $repository,
    ) {}

    public function execute(
        string $workerId,
        string $connection,
        string $queue,
        WorkerState $state,
        ?string $currentJobId = null,
        ?string $currentJobClass = null,
    ): void {
        if (! config('queue-metrics.enabled', true)) {
            return;
        }

        $pid = getmypid();
        if ($pid === false) {
            $pid = 0;
        }

        $this->repository->recordHeartbeat(
            workerId: $workerId,
            connection: $connection,
            queue: $queue,
            state: $state,
            currentJobId: $currentJobId,
            currentJobClass: $currentJobClass,
            pid: $pid,
            hostname: gethostname() ?: 'unknown',
        );
    }
}
