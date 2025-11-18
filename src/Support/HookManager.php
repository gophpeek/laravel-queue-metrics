<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Support;

use PHPeek\LaravelQueueMetrics\Contracts\MetricsHook;

/**
 * Manages and executes metrics processing hooks using Pipeline pattern.
 * Provides extensibility points for custom metrics processing.
 */
final class HookManager
{
    /**
     * @var array<string, array<MetricsHook>>
     */
    private array $hooks = [];

    public function __construct(
        private HookPipeline $pipeline,
    ) {}

    /**
     * Register a hook for a specific context.
     */
    public function register(string $context, MetricsHook $hook): void
    {
        if (! isset($this->hooks[$context])) {
            $this->hooks[$context] = [];
        }

        $this->hooks[$context][] = $hook;

        // Sort by priority (lower priority = runs first)
        usort($this->hooks[$context], fn ($a, $b) => $a->priority() <=> $b->priority());
    }

    /**
     * Execute all hooks for a given context using Pipeline.
     * Follows Statamic's Hookable pattern for cleaner implementation.
     */
    public function execute(string $context, mixed $payload, ?object $bindTo = null): mixed
    {
        $hooks = $this->getEligibleHooks($context);

        if (empty($hooks)) {
            return $payload;
        }

        return $this->pipeline->run($hooks, $payload, $bindTo);
    }

    /**
     * Get all registered hooks for a context that should run.
     *
     * @return array<MetricsHook>
     */
    public function getEligibleHooks(string $context): array
    {
        if (! isset($this->hooks[$context])) {
            return [];
        }

        return array_filter(
            $this->hooks[$context],
            fn (MetricsHook $hook) => $hook->shouldRun($context)
        );
    }

    /**
     * Get all registered hooks for a context.
     *
     * @return array<MetricsHook>
     */
    public function getHooks(string $context): array
    {
        return $this->hooks[$context] ?? [];
    }

    /**
     * Clear all hooks for a context.
     */
    public function clear(string $context): void
    {
        unset($this->hooks[$context]);
    }
}
