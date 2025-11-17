<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use PHPeek\LaravelQueueMetrics\Services\OverviewQueryService;

/**
 * HTTP controller for overview endpoint.
 */
final class OverviewController extends Controller
{
    public function __construct(
        private readonly OverviewQueryService $metricsQuery,
    ) {}

    public function __invoke(): JsonResponse
    {
        $overview = $this->metricsQuery->getOverview();

        return response()->json([
            'data' => $overview,
        ]);
    }
}
