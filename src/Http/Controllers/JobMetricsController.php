<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use PHPeek\LaravelQueueMetrics\Services\MetricsQueryService;

/**
 * HTTP controller for job metrics endpoints.
 */
final class JobMetricsController extends Controller
{
    public function __construct(
        private readonly MetricsQueryService $metricsQuery,
    ) {}

    public function show(string $jobClass): JsonResponse
    {
        $decodedJobClass = urldecode($jobClass);

        $metrics = $this->metricsQuery->getJobMetrics($decodedJobClass);

        return response()->json([
            'data' => $metrics->toArray(),
        ]);
    }
}
