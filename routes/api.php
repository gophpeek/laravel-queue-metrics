<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use PHPeek\LaravelQueueMetrics\Http\Controllers\HealthCheckController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\JobMetricsController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\OverviewController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\PrometheusController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\QueueMetricsController;
use PHPeek\LaravelQueueMetrics\Http\Controllers\WorkerController;

Route::prefix(config('queue-metrics.api.prefix', 'queue-metrics'))
    ->middleware(config('queue-metrics.api.middleware', ['api']))
    ->group(function () {
        // Health check
        Route::get('/health', HealthCheckController::class)
            ->name('queue-metrics.health');

        // Overview
        Route::get('/overview', OverviewController::class)
            ->name('queue-metrics.overview');

        // Job metrics
        Route::get('/jobs/{jobClass}', [JobMetricsController::class, 'show'])
            ->name('queue-metrics.jobs.show');

        // Queue metrics
        Route::get('/queues/{connection}/{queue}', [QueueMetricsController::class, 'show'])
            ->name('queue-metrics.queues.show');

        // Workers
        Route::get('/workers', [WorkerController::class, 'index'])
            ->name('queue-metrics.workers.index');

        // Prometheus export
        if (config('queue-metrics.prometheus.enabled', true)) {
            Route::get('/prometheus', PrometheusController::class)
                ->name('queue-metrics.prometheus');
        }
    });
