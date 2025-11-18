<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Support;

use Illuminate\Pipeline\Pipeline;
use PHPeek\LaravelQueueMetrics\Contracts\MetricsHook;

/**
 * Pipeline-based hook execution following Laravel conventions.
 * Inspired by Statamic's Hookable pattern.
 */
final class HookPipeline
{
    public function __construct(
        private Pipeline $pipeline,
    ) {}

    /**
     * Run hooks through Laravel Pipeline.
     *
     * @param  array<MetricsHook>  $hooks
     */
    public function run(array $hooks, mixed $payload, ?object $context = null): mixed
    {
        if (empty($hooks)) {
            return $payload;
        }

        // Convert MetricsHook objects to closures
        $closures = array_map(
            fn (MetricsHook $hook) => $this->hookToClosure($hook, $context),
            $hooks
        );

        return $this->pipeline
            ->send($payload)
            ->through($closures)
            ->thenReturn();
    }

    /**
     * Convert a MetricsHook to a closure for pipeline.
     */
    private function hookToClosure(MetricsHook $hook, ?object $context): \Closure
    {
        $closure = function (mixed $payload, \Closure $next) use ($hook) {
            // Handle the payload
            $result = $hook->handle($payload);

            // Continue pipeline
            return $next($result);
        };

        // Bind closure to context if provided (like Statamic does)
        if ($context !== null) {
            $closure = $closure->bindTo($context, $context);
        }

        return $closure;
    }
}
