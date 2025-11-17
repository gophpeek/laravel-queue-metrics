<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use PHPeek\LaravelQueueMetrics\Services\QueueMetricsQueryService;

/**
 * HTTP controller for all queues with comprehensive metrics.
 */
final class AllQueuesController extends Controller
{
    public function __construct(
        private readonly QueueMetricsQueryService $metricsQuery,
    ) {}

    public function index(): JsonResponse
    {
        $queues = $this->metricsQuery->getAllQueuesWithMetrics();

        return response()->json([
            'data' => $queues,
        ]);
    }
}
