<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use PHPeek\LaravelQueueMetrics\Services\QueueMetricsQueryService;

/**
 * HTTP controller for queue metrics endpoints.
 */
final class QueueMetricsController extends Controller
{
    public function __construct(
        private readonly QueueMetricsQueryService $metricsQuery,
    ) {}

    public function show(string $connection, string $queue): JsonResponse
    {
        $metrics = $this->metricsQuery->getQueueMetrics($connection, $queue);

        return response()->json([
            'data' => $metrics->toArray(),
        ]);
    }
}
