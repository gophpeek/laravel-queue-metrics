<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Contracts;

/**
 * Hook interface for extending metrics processing pipeline.
 * Allows users to inject custom logic at key points in metrics collection.
 */
interface MetricsHook
{
    /**
     * Execute hook logic.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> Modified data
     */
    public function handle(array $data): array;

    /**
     * Determine if this hook should run.
     */
    public function shouldRun(string $context): bool;

    /**
     * Hook priority (lower runs first).
     */
    public function priority(): int;
}
