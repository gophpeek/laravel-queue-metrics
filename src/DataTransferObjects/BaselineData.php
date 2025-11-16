<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\DataTransferObjects;

use Carbon\Carbon;

/**
 * Baseline metrics for capacity planning.
 */
final readonly class BaselineData
{
    public function __construct(
        public string $connection,
        public string $queue,
        public float $cpuPercentPerJob,
        public float $memoryMbPerJob,
        public float $avgDurationMs,
        public int $sampleCount,
        public float $confidenceScore,
        public Carbon $calculatedAt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $connection = $data['connection'] ?? 'default';
        $queue = $data['queue'] ?? 'default';
        $calculatedAt = $data['calculated_at'] ?? null;

        return new self(
            connection: is_string($connection) ? $connection : 'default',
            queue: is_string($queue) ? $queue : 'default',
            cpuPercentPerJob: is_numeric($data['cpu_percent_per_job'] ?? 0.0) ? (float) $data['cpu_percent_per_job'] : 0.0,
            memoryMbPerJob: is_numeric($data['memory_mb_per_job'] ?? 0.0) ? (float) $data['memory_mb_per_job'] : 0.0,
            avgDurationMs: is_numeric($data['avg_duration_ms'] ?? 0.0) ? (float) $data['avg_duration_ms'] : 0.0,
            sampleCount: is_numeric($data['sample_count'] ?? 0) ? (int) $data['sample_count'] : 0,
            confidenceScore: is_numeric($data['confidence_score'] ?? 0.0) ? (float) $data['confidence_score'] : 0.0,
            calculatedAt: (is_string($calculatedAt) || $calculatedAt instanceof \DateTimeInterface)
                ? Carbon::parse($calculatedAt)
                : Carbon::now(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'connection' => $this->connection,
            'queue' => $this->queue,
            'cpu_percent_per_job' => $this->cpuPercentPerJob,
            'memory_mb_per_job' => $this->memoryMbPerJob,
            'avg_duration_ms' => $this->avgDurationMs,
            'sample_count' => $this->sampleCount,
            'confidence_score' => $this->confidenceScore,
            'calculated_at' => $this->calculatedAt->toIso8601String(),
        ];
    }

    public function isReliable(): bool
    {
        return $this->sampleCount >= 50 && $this->confidenceScore >= 0.7;
    }

    public function needsMoreSamples(): bool
    {
        return $this->sampleCount < 100;
    }

    /**
     * Estimate max jobs per hour based on available resources.
     */
    public function estimateCapacity(float $availableCpuPercent, float $availableMemoryMb): int
    {
        if ($this->cpuPercentPerJob <= 0 || $this->memoryMbPerJob <= 0 || $this->avgDurationMs <= 0) {
            return 0;
        }

        $cpuBasedCapacity = (int) ($availableCpuPercent / $this->cpuPercentPerJob);
        $memoryBasedCapacity = (int) ($availableMemoryMb / $this->memoryMbPerJob);
        $parallelJobs = (int) min($cpuBasedCapacity, $memoryBasedCapacity);

        $jobsPerHour = (int) (($parallelJobs * 3600000) / $this->avgDurationMs);

        return $jobsPerHour;
    }
}
