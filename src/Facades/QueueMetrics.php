<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use PHPeek\LaravelQueueMetrics\Contracts\MetricsHook;
use PHPeek\LaravelQueueMetrics\Services\JobMetricsQueryService;
use PHPeek\LaravelQueueMetrics\Services\OverviewQueryService;
use PHPeek\LaravelQueueMetrics\Services\QueueMetricsQueryService;
use PHPeek\LaravelQueueMetrics\Services\WorkerMetricsQueryService;
use PHPeek\LaravelQueueMetrics\Support\ClosureHook;
use PHPeek\LaravelQueueMetrics\Support\HookManager;

/**
 * Facade providing convenient access to queue metrics services.
 *
 * Overview methods:
 *
 * @method static array<string, mixed> getOverview()
 *
 * Job metrics methods:
 * @method static \PHPeek\LaravelQueueMetrics\DataTransferObjects\JobMetricsData getJobMetrics(string $jobClass, string $connection = 'default', string $queue = 'default')
 * @method static array<string, array<string, mixed>> getAllJobsWithMetrics()
 *
 * Queue metrics methods:
 * @method static \PHPeek\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData getQueueMetrics(string $connection = 'default', string $queue = 'default')
 * @method static \PHPeek\LaravelQueueMetrics\DataTransferObjects\QueueDepthData getQueueDepth(string $connection = 'default', string $queue = 'default')
 * @method static array<string, array<string, mixed>> getAllQueuesWithMetrics()
 * @method static array<string, mixed> healthCheck()
 *
 * Worker metrics methods:
 * @method static \Illuminate\Support\Collection<int, \PHPeek\LaravelQueueMetrics\DataTransferObjects\WorkerHeartbeat> getActiveWorkers(?string $connection = null, ?string $queue = null)
 * @method static \Illuminate\Support\Collection<int, \PHPeek\LaravelQueueMetrics\DataTransferObjects\WorkerHeartbeat> getWorkerHeartbeats(?string $connection = null, ?string $queue = null)
 * @method static ?\PHPeek\LaravelQueueMetrics\DataTransferObjects\WorkerHeartbeat getWorkerHeartbeat(string $workerId)
 * @method static int detectStaledWorkers(int $thresholdSeconds = 60)
 * @method static array<string, array<string, mixed>> getAllServersWithMetrics()
 * @method static array<string, mixed> getWorkersSummary()
 *
 * Hook methods:
 * @method static void hook(string $context, \Closure|\PHPeek\LaravelQueueMetrics\Contracts\MetricsHook $hook, int $priority = 100)
 *
 * @see \PHPeek\LaravelQueueMetrics\Services\OverviewQueryService
 * @see \PHPeek\LaravelQueueMetrics\Services\JobMetricsQueryService
 * @see \PHPeek\LaravelQueueMetrics\Services\QueueMetricsQueryService
 * @see \PHPeek\LaravelQueueMetrics\Services\WorkerMetricsQueryService
 * @see \PHPeek\LaravelQueueMetrics\Support\HookManager
 */
final class QueueMetrics extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * Dynamically routes to the appropriate service based on method name.
     */
    protected static function getFacadeAccessor(): string
    {
        // Default to OverviewQueryService for the main getOverview() method
        return OverviewQueryService::class;
    }

    /**
     * Handle dynamic static method calls into the facade.
     *
     * Routes method calls to the appropriate service.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $args
     */
    public static function __callStatic(mixed $method, mixed $args): mixed
    {
        // Route to appropriate service based on method name
        $service = match (true) {
            in_array($method, ['getJobMetrics', 'getAllJobsWithMetrics', 'getJobClassBaseline']) => app(JobMetricsQueryService::class),
            in_array($method, ['getQueueMetrics', 'getQueueDepth', 'getAllQueuesWithMetrics', 'getAllQueues', 'getBaseline', 'healthCheck']) => app(QueueMetricsQueryService::class),
            in_array($method, ['getActiveWorkers', 'getWorkerHeartbeats', 'getWorkerHeartbeat', 'detectStaledWorkers', 'getAllServersWithMetrics', 'getWorkersSummary', 'getAllWorkersWithMetrics']) => app(WorkerMetricsQueryService::class),
            default => app(OverviewQueryService::class),
        };

        return $service->$method(...$args);
    }

    /**
     * Register a hook for a specific context.
     *
     * @param  string  $context  Hook context (before_record, after_record, etc.)
     * @param  Closure|MetricsHook  $hook  Closure or MetricsHook implementation
     * @param  int  $priority  Lower priority runs first (default: 100)
     */
    public static function hook(string $context, Closure|MetricsHook $hook, int $priority = 100): void
    {
        $hookManager = app(HookManager::class);

        // Wrap closures in ClosureHook
        if ($hook instanceof Closure) {
            $hook = new ClosureHook($hook, $context, $priority);
        }

        $hookManager->register($context, $hook);
    }
}
