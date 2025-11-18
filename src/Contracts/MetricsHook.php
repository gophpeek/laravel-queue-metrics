<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Contracts;

/**
 * Hook interface for extending metrics processing pipeline.
 * Allows users to inject custom logic at key points in metrics collection.
 *
 * Hooks are executed through Laravel's Pipeline for clean composition.
 */
interface MetricsHook
{
    /**
     * Process the payload through the hook.
     * Can accept and return any type of payload (arrays, DTOs, etc.).
     */
    public function handle(mixed $payload): mixed;

    /**
     * Determine if this hook should run in the given context.
     */
    public function shouldRun(string $context): bool;

    /**
     * Hook priority (lower runs first).
     */
    public function priority(): int;
}
