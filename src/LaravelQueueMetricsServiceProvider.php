<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics;

use Illuminate\Support\Facades\Event;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use PHPeek\LaravelQueueMetrics\Actions\CalculateJobMetricsAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobCompletionAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobFailureAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobStartAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use PHPeek\LaravelQueueMetrics\Actions\TransitionWorkerStateAction;
use PHPeek\LaravelQueueMetrics\Console\DetectStaleWorkersCommand;
use PHPeek\LaravelQueueMetrics\Listeners\JobFailedListener;
use PHPeek\LaravelQueueMetrics\Listeners\JobProcessedListener;
use PHPeek\LaravelQueueMetrics\Listeners\JobProcessingListener;
use PHPeek\LaravelQueueMetrics\Contracts\QueueInspector;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisBaselineRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisJobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisQueueMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisWorkerHeartbeatRepository;
use PHPeek\LaravelQueueMetrics\Repositories\RedisWorkerRepository;
use PHPeek\LaravelQueueMetrics\Services\LaravelQueueInspector;
use PHPeek\LaravelQueueMetrics\Services\MetricsQueryService;
use PHPeek\LaravelQueueMetrics\Services\RedisConnectionManager;
use PHPeek\LaravelQueueMetrics\Utilities\PercentileCalculator;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class LaravelQueueMetricsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('queue-metrics')
            ->hasConfigFile('queue-metrics')
            ->hasRoute('api')
            ->hasCommand(DetectStaleWorkersCommand::class);
    }

    public function packageRegistered(): void
    {
        // Register Redis connection manager
        $this->app->singleton(RedisConnectionManager::class, function ($app) {
            $connectionName = config('queue-metrics.storage.redis.connection', 'default');
            if (! is_string($connectionName)) {
                $connectionName = 'default';
            }

            $prefix = config('queue-metrics.storage.redis.prefix', 'queue_metrics');
            if (! is_string($prefix)) {
                $prefix = 'queue_metrics';
            }

            $ttls = config('queue-metrics.storage.redis.ttl', []);
            if (! is_array($ttls)) {
                $ttls = [];
            }

            return new RedisConnectionManager($connectionName, $prefix, $ttls);
        });

        // Register repositories based on configured driver
        $this->app->singleton(JobMetricsRepository::class, function ($app) {
            return match (config('queue-metrics.storage.driver', 'redis')) {
                'redis' => new RedisJobMetricsRepository($app->make(RedisConnectionManager::class)),
                default => new RedisJobMetricsRepository($app->make(RedisConnectionManager::class)),
            };
        });

        $this->app->singleton(QueueMetricsRepository::class, function ($app) {
            return match (config('queue-metrics.storage.driver', 'redis')) {
                'redis' => new RedisQueueMetricsRepository($app->make(RedisConnectionManager::class)),
                default => new RedisQueueMetricsRepository($app->make(RedisConnectionManager::class)),
            };
        });

        $this->app->singleton(WorkerRepository::class, function ($app) {
            return match (config('queue-metrics.storage.driver', 'redis')) {
                'redis' => new RedisWorkerRepository($app->make(RedisConnectionManager::class)),
                default => new RedisWorkerRepository($app->make(RedisConnectionManager::class)),
            };
        });

        $this->app->singleton(BaselineRepository::class, function ($app) {
            return match (config('queue-metrics.storage.driver', 'redis')) {
                'redis' => new RedisBaselineRepository($app->make(RedisConnectionManager::class)),
                default => new RedisBaselineRepository($app->make(RedisConnectionManager::class)),
            };
        });

        $this->app->singleton(WorkerHeartbeatRepository::class, function ($app) {
            return match (config('queue-metrics.storage.driver', 'redis')) {
                'redis' => new RedisWorkerHeartbeatRepository($app->make(RedisConnectionManager::class)),
                default => new RedisWorkerHeartbeatRepository($app->make(RedisConnectionManager::class)),
            };
        });

        // Register services
        $this->app->singleton(QueueInspector::class, LaravelQueueInspector::class);
        $this->app->singleton(MetricsQueryService::class);

        // Register utilities
        $this->app->singleton(PercentileCalculator::class);

        // Register actions
        $this->app->singleton(RecordJobStartAction::class);
        $this->app->singleton(RecordJobCompletionAction::class);
        $this->app->singleton(RecordJobFailureAction::class);
        $this->app->singleton(CalculateJobMetricsAction::class);
        $this->app->singleton(RecordWorkerHeartbeatAction::class);
        $this->app->singleton(TransitionWorkerStateAction::class);
    }

    public function packageBooted(): void
    {
        if (! config('queue-metrics.enabled', true)) {
            return;
        }

        // Register event listeners
        Event::listen(JobProcessing::class, JobProcessingListener::class);
        Event::listen(JobProcessed::class, JobProcessedListener::class);
        Event::listen(JobFailed::class, JobFailedListener::class);
    }
}
