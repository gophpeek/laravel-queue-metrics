<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Support;

use Closure;
use PHPeek\LaravelQueueMetrics\Contracts\MetricsHook;

/**
 * Wrapper to use Closures as hooks.
 * Allows dynamic hook registration without creating full hook classes.
 */
final readonly class ClosureHook implements MetricsHook
{
    public function __construct(
        private Closure $callback,
        private string $targetContext,
        private int $hookPriority = 100,
    ) {}

    /**
     * Execute the closure with the provided payload.
     */
    public function handle(mixed $payload): mixed
    {
        $result = ($this->callback)($payload);

        // If closure returns null, pass through original payload
        return $result ?? $payload;
    }

    /**
     * Check if this hook should run for the given context.
     */
    public function shouldRun(string $context): bool
    {
        return $this->targetContext === $context;
    }

    /**
     * Get the priority of this hook.
     */
    public function priority(): int
    {
        return $this->hookPriority;
    }
}
