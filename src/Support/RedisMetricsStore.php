<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Support;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Simple wrapper around Laravel's Redis for queue metrics storage.
 * Uses Laravel's Redis connection directly instead of abstraction layer.
 */
final readonly class RedisMetricsStore
{
    private Connection $redis;

    private string $prefix;

    public function __construct()
    {
        /** @var string $connection */
        $connection = config('queue-metrics.storage.connection', 'default');
        $this->redis = Redis::connection($connection);
        /** @var string $prefix */
        $prefix = config('queue-metrics.storage.prefix', 'queue_metrics');
        $this->prefix = $prefix;
    }

    /**
     * Build a Redis key from segments.
     */
    public function key(string ...$segments): string
    {
        return $this->prefix.':'.implode(':', $segments);
    }

    /**
     * Get TTL for a given key type.
     */
    public function getTtl(string $type): int
    {
        /** @var int */
        return config("queue-metrics.storage.ttl.{$type}", 3600);
    }

    /**
     * Get the underlying Redis connection.
     */
    public function connection(): Connection
    {
        return $this->redis;
    }

    /**
     * Return self as driver (for StorageManager compatibility).
     */
    public function driver(): self
    {
        return $this;
    }

    // StorageDriver interface methods

    /**
     * @param  array<string, mixed>  $data
     */
    public function setHash(string $key, array $data, ?int $ttl = null): void
    {
        $this->redis->hmset($key, $data);

        if ($ttl !== null) {
            $this->redis->expire($key, $ttl);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getHash(string $key): array
    {
        $result = $this->redis->hgetall($key);

        return is_array($result) ? $result : [];
    }

    public function getHashField(string $key, string $field): mixed
    {
        return $this->redis->hget($key, $field);
    }

    public function incrementHashField(string $key, string $field, int|float $value): void
    {
        if (is_float($value)) {
            $this->redis->hincrbyfloat($key, $field, $value);
        } else {
            $this->redis->hincrby($key, $field, $value);
        }
    }

    /**
     * @param  array<string, float|int>  $membersWithScores
     */
    public function addToSortedSet(string $key, array $membersWithScores, ?int $ttl = null): void
    {
        $this->redis->zadd($key, $membersWithScores);

        if ($ttl !== null) {
            $this->redis->expire($key, $ttl);
        }
    }

    /**
     * @return array<int, string>
     */
    public function getSortedSetByRank(string $key, int $start, int $stop): array
    {
        return $this->redis->zrange($key, $start, $stop);
    }

    /**
     * @return array<int, string>
     */
    public function getSortedSetByScore(string $key, string $min, string $max): array
    {
        return $this->redis->zrangebyscore($key, $min, $max);
    }

    public function countSortedSetByScore(string $key, string $min, string $max): int
    {
        return (int) $this->redis->zcount($key, $min, $max);
    }

    public function removeSortedSetByRank(string $key, int $start, int $stop): int
    {
        return (int) $this->redis->zremrangebyrank($key, $start, $stop);
    }

    public function removeSortedSetByScore(string $key, string $min, string $max): int
    {
        return (int) $this->redis->zremrangebyscore($key, $min, $max);
    }

    public function removeFromSortedSet(string $key, string $member): void
    {
        $this->redis->zrem($key, $member);
    }

    /**
     * @param  array<int, string>  $members
     */
    public function addToSet(string $key, array $members): void
    {
        if (! empty($members)) {
            $this->redis->sadd($key, $members);
        }
    }

    /**
     * @return array<int, string>
     */
    public function getSetMembers(string $key): array
    {
        return $this->redis->smembers($key);
    }

    /**
     * @param  array<int, string>  $members
     */
    public function removeFromSet(string $key, array $members): void
    {
        if (! empty($members)) {
            $this->redis->srem($key, $members);
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl !== null) {
            $this->redis->setex($key, $ttl, $value);
        } else {
            $this->redis->set($key, $value);
        }
    }

    public function get(string $key): mixed
    {
        return $this->redis->get($key);
    }

    /**
     * @param  array<int, string>|string  $keys
     */
    public function delete(array|string $keys): int
    {
        if (is_string($keys)) {
            $keys = [$keys];
        }

        if (empty($keys)) {
            return 0;
        }

        return (int) $this->redis->del(...$keys);
    }

    public function exists(string $key): bool
    {
        return (bool) $this->redis->exists($key);
    }

    public function expire(string $key, int $seconds): bool
    {
        return (bool) $this->redis->expire($key, $seconds);
    }

    /**
     * @return array<int, string>
     */
    public function scanKeys(string $pattern): array
    {
        // Get the underlying PhpRedis client - it includes Redis connection prefix
        /** @var \Redis $client */
        $client = $this->redis->client();

        // Get Laravel's Redis connection prefix (e.g., 'laravel_database_')
        $connectionPrefix = $this->redis->_prefix('');

        // Combine connection prefix with our pattern
        $fullPattern = $connectionPrefix.$pattern;

        $keys = [];
        $cursor = null;

        do {
            // PhpRedis scan() signature: scan(&$cursor, $pattern, $count)
            /** @var array<int, string>|false $result */
            $result = $client->scan($cursor, $fullPattern, 1000);

            if ($result === false) {
                break;
            }

            // @phpstan-ignore-next-line - PHPDoc type assertion ensures is_array check is valid
            if (is_array($result) && ! empty($result)) {
                $keys = array_merge($keys, $result);
            }
            // @phpstan-ignore-next-line - Complex scan cursor state management
        } while ($cursor !== 0 && $cursor !== null);

        /** @var array<int, string> */
        return $keys;
    }

    public function pipeline(callable $callback): void
    {
        // @phpstan-ignore-next-line - PhpRedis pipeline accepts no parameters in newer versions
        $this->redis->pipeline(function ($pipe) use ($callback) {
            $wrapper = new PipelineWrapper($pipe);
            $callback($wrapper);
        });
    }

    /**
     * Execute commands in a Redis transaction (MULTI/EXEC).
     * Ensures all commands are executed atomically.
     *
     * @param  callable(PipelineWrapper): void  $callback
     * @return array<int, mixed> Results of executed commands
     */
    public function transaction(callable $callback): array
    {
        // Laravel Redis uses multi() and exec() for transactions
        $this->redis->multi();

        try {
            // @phpstan-ignore-next-line - Laravel Connection type is compatible with PipelineWrapper
            $wrapper = new PipelineWrapper($this->redis);
            $callback($wrapper);

            $results = $this->redis->exec();

            return is_array($results) ? $results : [];
        } catch (\Throwable $e) {
            $this->redis->discard();
            throw $e;
        }
    }

    /**
     * Execute Lua script atomically on Redis.
     *
     * @param  mixed  ...$args
     */
    public function eval(string $script, int $numKeys, ...$args): mixed
    {
        // @phpstan-ignore-next-line - Redis eval accepts variadic args but PHPStan expects array
        return $this->redis->eval($script, $numKeys, ...$args);
    }

    /**
     * Execute generic Redis command.
     *
     * @param  array<int, mixed>  $parameters
     */
    public function command(string $method, array $parameters = []): mixed
    {
        return $this->redis->command($method, $parameters);
    }
}
