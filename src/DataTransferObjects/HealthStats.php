<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\DataTransferObjects;

/**
 * Queue health statistics.
 */
final readonly class HealthStats
{
    public function __construct(
        public string $status,
        public float $score,
        public int $depth,
        public int $oldestJobAge,
        public float $failureRate,
        public float $utilizationRate,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $status = $data['status'] ?? 'unknown';

        return new self(
            status: is_string($status) ? $status : 'unknown',
            score: is_numeric($data['score'] ?? 0.0) ? (float) $data['score'] : 0.0,
            depth: is_numeric($data['depth'] ?? 0) ? (int) $data['depth'] : 0,
            oldestJobAge: is_numeric($data['oldest_job_age'] ?? 0) ? (int) $data['oldest_job_age'] : 0,
            failureRate: is_numeric($data['failure_rate'] ?? 0.0) ? (float) $data['failure_rate'] : 0.0,
            utilizationRate: is_numeric($data['utilization_rate'] ?? 0.0) ? (float) $data['utilization_rate'] : 0.0,
        );
    }

    /**
     * @return array<string, string|int|float>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'score' => $this->score,
            'depth' => $this->depth,
            'oldest_job_age' => $this->oldestJobAge,
            'failure_rate' => $this->failureRate,
            'utilization_rate' => $this->utilizationRate,
        ];
    }

    public function isHealthy(): bool
    {
        return $this->status === 'healthy' || $this->score >= 80.0;
    }

    public function isWarning(): bool
    {
        return $this->status === 'warning' || ($this->score >= 50.0 && $this->score < 80.0);
    }

    public function isCritical(): bool
    {
        return $this->status === 'critical' || $this->score < 50.0;
    }
}
