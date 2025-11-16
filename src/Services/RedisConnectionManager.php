<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Services;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Manages Redis connections for queue metrics storage.
 */
final readonly class RedisConnectionManager
{
    /**
     * @param array<string, int> $ttls
     */
    public function __construct(
        private string $connectionName,
        private string $prefix,
        private array $ttls,
    ) {}

    public function getConnection(): Connection
    {
        /** @var Connection */
        return Redis::connection($this->connectionName);
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getTtl(string $type): int
    {
        return (int) ($this->ttls[$type] ?? 3600);
    }

    public function key(string ...$parts): string
    {
        return $this->prefix . ':' . implode(':', $parts);
    }
}
