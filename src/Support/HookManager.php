<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Support;

use PHPeek\LaravelQueueMetrics\Contracts\MetricsHook;

/**
 * Manages and executes metrics processing hooks.
 * Provides extensibility points for custom metrics processing.
 */
final class HookManager
{
    /**
     * @var array<string, array<MetricsHook>>
     */
    private array $hooks = [];

    /**
     * Register a hook for a specific context.
     */
    public function register(string $context, MetricsHook $hook): void
    {
        if (! isset($this->hooks[$context])) {
            $this->hooks[$context] = [];
        }

        $this->hooks[$context][] = $hook;

        // Sort by priority
        usort($this->hooks[$context], fn ($a, $b) => $a->priority() <=> $b->priority());
    }

    /**
     * Execute all hooks for a given context.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $context, array $data): array
    {
        if (! isset($this->hooks[$context])) {
            return $data;
        }

        foreach ($this->hooks[$context] as $hook) {
            if ($hook->shouldRun($context)) {
                $data = $hook->handle($data);
            }
        }

        return $data;
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
