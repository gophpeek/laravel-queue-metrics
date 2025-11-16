<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use PHPeek\LaravelQueueMetrics\Services\MetricsQueryService;

/**
 * HTTP controller for health check endpoint.
 */
final class HealthCheckController extends Controller
{
    public function __construct(
        private readonly MetricsQueryService $metricsQuery,
    ) {}

    public function __invoke(): JsonResponse
    {
        $health = $this->metricsQuery->healthCheck();

        return response()->json($health);
    }
}
