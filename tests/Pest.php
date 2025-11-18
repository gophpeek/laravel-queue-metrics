<?php

use PHPeek\LaravelQueueMetrics\Support\HookManager;
use PHPeek\LaravelQueueMetrics\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

// Mock HookManager globally to make hooks no-op during tests
beforeEach(function () {
    $hookManager = Mockery::mock(HookManager::class);
    $hookManager->shouldReceive('execute')
        ->andReturnUsing(fn ($context, $payload) => $payload);

    $this->app->instance(HookManager::class, $hookManager);
});
