<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPeek\LaravelQueueMetrics\Services\TrendAnalysisService;

/**
 * Provides trend analysis and forecasting endpoints.
 */
final readonly class TrendAnalysisController
{
    public function __construct(
        private TrendAnalysisService $trendAnalysis,
    ) {}

    /**
     * Analyze queue depth trend.
     */
    public function queueDepth(Request $request, string $connection, string $queue): JsonResponse
    {
        $periodSeconds = (int) $request->query('period', 3600);
        $intervalSeconds = (int) $request->query('interval', 60);

        $trend = $this->trendAnalysis->analyzeQueueDepthTrend(
            $connection,
            $queue,
            $periodSeconds,
            $intervalSeconds
        );

        return response()->json($trend);
    }

    /**
     * Analyze throughput trend.
     */
    public function throughput(Request $request, string $connection, string $queue): JsonResponse
    {
        $periodSeconds = (int) $request->query('period', 3600);

        $trend = $this->trendAnalysis->analyzeThroughputTrend(
            $connection,
            $queue,
            $periodSeconds
        );

        return response()->json($trend);
    }

    /**
     * Analyze worker efficiency trend.
     */
    public function workerEfficiency(Request $request): JsonResponse
    {
        $periodSeconds = (int) $request->query('period', 3600);

        $trend = $this->trendAnalysis->analyzeWorkerEfficiencyTrend($periodSeconds);

        return response()->json($trend);
    }
}
