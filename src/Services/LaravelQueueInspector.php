<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\RedisQueue;
use PHPeek\LaravelQueueMetrics\Contracts\QueueInspector;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\QueueDepthData;
use ReflectionClass;
use ReflectionException;

/**
 * Laravel Queue API inspector with reflection fallback.
 */
final readonly class LaravelQueueInspector implements QueueInspector
{
    public function __construct(
        private QueueFactory $queueFactory,
    ) {}

    public function getQueueDepth(string $connection, string $queue): QueueDepthData
    {
        $queueInstance = $this->queueFactory->connection($connection);

        // Try modern Laravel API first (Laravel 11+, PR #56010)
        if (method_exists($queueInstance, 'size')) {
            return $this->getDepthModernApi($queueInstance, $connection, $queue);
        }

        // Fallback to reflection for older versions
        return $this->getDepthViaReflection($queueInstance, $connection, $queue);
    }

    public function hasJobs(string $connection, string $queue): bool
    {
        $depth = $this->getQueueDepth($connection, $queue);

        return ! $depth->isEmpty();
    }

    /**
     * @return array<string>
     */
    public function getAllQueues(): array
    {
        $queues = config('queue.connections', []);
        $discovered = ['default'];

        foreach ($queues as $connection => $config) {
            if (isset($config['queue'])) {
                $discovered[] = $config['queue'];
            }

            // Also check for multiple queues in config
            if (isset($config['queues']) && is_array($config['queues'])) {
                $discovered = array_merge($discovered, $config['queues']);
            }
        }

        // Get from workers configuration if available
        $workers = config('queue.workers', []);
        foreach ($workers as $worker) {
            if (isset($worker['queue'])) {
                $queues = explode(',', $worker['queue']);
                $discovered = array_merge($discovered, $queues);
            }
        }

        return array_values(array_unique(array_filter($discovered)));
    }

    /**
     * Use modern Laravel Queue API (Laravel 11+).
     */
    private function getDepthModernApi(
        mixed $queueInstance,
        string $connection,
        string $queue,
    ): QueueDepthData {
        $pendingJobs = 0;
        if (is_object($queueInstance) && method_exists($queueInstance, 'size')) {
            $size = $queueInstance->size($queue);
            $pendingJobs = is_int($size) ? $size : 0;
        }

        $reservedJobs = 0;
        $delayedJobs = 0;

        // Try to get reserved and delayed counts if methods exist
        if (is_object($queueInstance) && method_exists($queueInstance, 'sizeReserved')) {
            $reserved = $queueInstance->sizeReserved($queue);
            $reservedJobs = is_int($reserved) ? $reserved : 0;
        }

        if (is_object($queueInstance) && method_exists($queueInstance, 'sizeDelayed')) {
            $delayed = $queueInstance->sizeDelayed($queue);
            $delayedJobs = is_int($delayed) ? $delayed : 0;
        }

        // Try to get oldest job timestamps
        $oldestPendingAge = $this->getOldestJobAge($queueInstance, $queue, 'pending');
        $oldestDelayedAge = $this->getOldestJobAge($queueInstance, $queue, 'delayed');

        return new QueueDepthData(
            connection: $connection,
            queue: $queue,
            pendingJobs: $pendingJobs,
            reservedJobs: $reservedJobs,
            delayedJobs: $delayedJobs,
            oldestPendingJobAge: $oldestPendingAge,
            oldestDelayedJobAge: $oldestDelayedAge,
            measuredAt: Carbon::now(),
        );
    }

    /**
     * Fallback using reflection for older Laravel versions.
     */
    private function getDepthViaReflection(
        mixed $queueInstance,
        string $connection,
        string $queue,
    ): QueueDepthData {
        // For Redis queues, we can use Redis commands directly
        if ($queueInstance instanceof RedisQueue) {
            return $this->getRedisQueueDepth($queueInstance, $connection, $queue);
        }

        // For other queue types, try reflection
        if (! is_object($queueInstance)) {
            return new QueueDepthData(
                connection: $connection,
                queue: $queue,
                pendingJobs: 0,
                reservedJobs: 0,
                delayedJobs: 0,
                oldestPendingJobAge: null,
                oldestDelayedJobAge: null,
                measuredAt: Carbon::now(),
            );
        }

        try {
            $reflection = new ReflectionClass($queueInstance);

            $pendingJobs = 0;
            $reservedJobs = 0;
            $delayedJobs = 0;

            // Try to call protected/private size methods
            if ($reflection->hasMethod('size')) {
                $method = $reflection->getMethod('size');
                $method->setAccessible(true);
                $size = $method->invoke($queueInstance, $queue);
                $pendingJobs = is_int($size) ? $size : 0;
            }

            return new QueueDepthData(
                connection: $connection,
                queue: $queue,
                pendingJobs: $pendingJobs,
                reservedJobs: $reservedJobs,
                delayedJobs: $delayedJobs,
                oldestPendingJobAge: null,
                oldestDelayedJobAge: null,
                measuredAt: Carbon::now(),
            );
        } catch (ReflectionException) {
            // If reflection fails, return empty depth
            return new QueueDepthData(
                connection: $connection,
                queue: $queue,
                pendingJobs: 0,
                reservedJobs: 0,
                delayedJobs: 0,
                oldestPendingJobAge: null,
                oldestDelayedJobAge: null,
                measuredAt: Carbon::now(),
            );
        }
    }

    /**
     * Get queue depth for Redis queues using direct Redis commands.
     */
    private function getRedisQueueDepth(
        RedisQueue $queue,
        string $connection,
        string $queueName,
    ): QueueDepthData {
        try {
            $reflection = new ReflectionClass($queue);
            $redisProperty = $reflection->getProperty('redis');
            $redisProperty->setAccessible(true);
            $redis = $redisProperty->getValue($queue);

            $prefix = config("queue.connections.{$connection}.prefix");
            if (! is_string($prefix)) {
                $prefix = 'queues';
            }

            // Get pending jobs count
            $pendingKey = "{$prefix}:{$queueName}";
            $pendingCount = $redis->llen($pendingKey);
            $pendingJobs = is_int($pendingCount) ? $pendingCount : 0;

            // Get reserved jobs count
            $reservedKey = "{$prefix}:{$queueName}:reserved";
            $reservedCount = $redis->zcard($reservedKey);
            $reservedJobs = is_int($reservedCount) ? $reservedCount : 0;

            // Get delayed jobs count
            $delayedKey = "{$prefix}:{$queueName}:delayed";
            $delayedCount = $redis->zcard($delayedKey);
            $delayedJobs = is_int($delayedCount) ? $delayedCount : 0;

            // Get oldest pending job timestamp
            $oldestPending = null;
            $oldestJob = $redis->lindex($pendingKey, 0);
            if (is_string($oldestJob)) {
                $decoded = json_decode($oldestJob, true);
                if (is_array($decoded) && isset($decoded['pushedAt']) && is_numeric($decoded['pushedAt'])) {
                    $oldestPending = Carbon::createFromTimestamp((int) $decoded['pushedAt']);
                }
            }

            // Get oldest delayed job timestamp
            $oldestDelayed = null;
            $oldestDelayedJobs = $redis->zrange($delayedKey, 0, 0, 'WITHSCORES');
            if (is_array($oldestDelayedJobs) && ! empty($oldestDelayedJobs)) {
                $timestamp = reset($oldestDelayedJobs);
                if (is_numeric($timestamp)) {
                    $oldestDelayed = Carbon::createFromTimestamp((int) $timestamp);
                }
            }

            return new QueueDepthData(
                connection: $connection,
                queue: $queueName,
                pendingJobs: $pendingJobs,
                reservedJobs: $reservedJobs,
                delayedJobs: $delayedJobs,
                oldestPendingJobAge: $oldestPending,
                oldestDelayedJobAge: $oldestDelayed,
                measuredAt: Carbon::now(),
            );
        } catch (ReflectionException) {
            return new QueueDepthData(
                connection: $connection,
                queue: $queueName,
                pendingJobs: 0,
                reservedJobs: 0,
                delayedJobs: 0,
                oldestPendingJobAge: null,
                oldestDelayedJobAge: null,
                measuredAt: Carbon::now(),
            );
        }
    }

    /**
     * Try to get oldest job age from queue.
     *
     * This would require custom queue driver implementation or Laravel 11+ API.
     * For now, return null and rely on Redis-specific implementation above.
     */
    private function getOldestJobAge(
        mixed $queueInstance,
        string $queue,
        string $type = 'pending',
    ): null {
        return null;
    }
}
