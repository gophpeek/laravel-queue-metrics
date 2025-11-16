<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PHPeek\LaravelQueueMetrics\Services\MetricsQueryService;

/**
 * HTTP controller for worker endpoints.
 */
final class WorkerController extends Controller
{
    public function __construct(
        private readonly MetricsQueryService $metricsQuery,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $connection = $request->query('connection');
        $queue = $request->query('queue');

        $workers = $this->metricsQuery->getActiveWorkers($connection, $queue);

        return response()->json([
            'data' => $workers->map(fn ($worker) => $worker->toArray())->values(),
        ]);
    }
}
