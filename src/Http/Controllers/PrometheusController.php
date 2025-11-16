<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use PHPeek\LaravelQueueMetrics\Services\MetricsQueryService;

/**
 * HTTP controller for Prometheus metrics export.
 */
final class PrometheusController extends Controller
{
    public function __construct(
        private readonly MetricsQueryService $metricsQuery,
    ) {}

    public function __invoke(): Response
    {
        $namespace = config('queue-metrics.prometheus.namespace', 'laravel_queue');
        $overview = $this->metricsQuery->getOverview();

        $metrics = $this->formatPrometheusMetrics($namespace, $overview);

        return response($metrics, 200, [
            'Content-Type' => 'text/plain; version=0.0.4',
        ]);
    }

    /**
     * @param array<string, mixed> $overview
     */
    private function formatPrometheusMetrics(string $namespace, array $overview): string
    {
        $output = [];

        // Total queues
        $output[] = "# HELP {$namespace}_queues_total Total number of queues";
        $output[] = "# TYPE {$namespace}_queues_total gauge";
        $output[] = "{$namespace}_queues_total " . ($overview['total_queues'] ?? 0);
        $output[] = '';

        // Total jobs processed
        $output[] = "# HELP {$namespace}_jobs_processed_total Total number of jobs processed";
        $output[] = "# TYPE {$namespace}_jobs_processed_total counter";
        $output[] = "{$namespace}_jobs_processed_total " . ($overview['total_jobs_processed'] ?? 0);
        $output[] = '';

        // Total jobs failed
        $output[] = "# HELP {$namespace}_jobs_failed_total Total number of jobs failed";
        $output[] = "# TYPE {$namespace}_jobs_failed_total counter";
        $output[] = "{$namespace}_jobs_failed_total " . ($overview['total_jobs_failed'] ?? 0);
        $output[] = '';

        // Active workers
        $output[] = "# HELP {$namespace}_workers_active Number of active workers";
        $output[] = "# TYPE {$namespace}_workers_active gauge";
        $output[] = "{$namespace}_workers_active " . ($overview['total_active_workers'] ?? 0);
        $output[] = '';

        // Health score
        $output[] = "# HELP {$namespace}_health_score Overall health score (0-100)";
        $output[] = "# TYPE {$namespace}_health_score gauge";
        $output[] = "{$namespace}_health_score " . ($overview['health_score'] ?? 100);
        $output[] = '';

        return implode("\n", $output);
    }
}
