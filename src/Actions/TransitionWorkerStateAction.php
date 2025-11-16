<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Actions;

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\Enums\WorkerState;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;

/**
 * Transition worker to a new state.
 */
final readonly class TransitionWorkerStateAction
{
    public function __construct(
        private WorkerHeartbeatRepository $repository,
    ) {}

    public function execute(
        string $workerId,
        WorkerState $newState,
        ?Carbon $transitionTime = null,
    ): void {
        if (! config('queue-metrics.enabled', true)) {
            return;
        }

        $this->repository->transitionState(
            workerId: $workerId,
            newState: $newState,
            transitionTime: $transitionTime ?? Carbon::now(),
        );
    }
}
