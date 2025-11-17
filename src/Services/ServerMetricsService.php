<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Services;

/**
 * Service for collecting server-wide resource metrics.
 */
final readonly class ServerMetricsService
{
    /**
     * Get current server resource metrics.
     *
     * Note: SystemMetrics package disabled due to macOS compatibility issues.
     * For production Linux environments, consider re-enabling with proper error handling.
     *
     * @return array<string, mixed>
     */
    public function getCurrentMetrics(): array
    {
        return [
            'available' => false,
            'error' => 'Server metrics temporarily disabled (SystemMetrics compatibility issue on development environment)',
        ];
    }

    /**
     * Get server health status based on resource thresholds.
     *
     * @return array<string, mixed>
     */
    public function getHealthStatus(): array
    {
        $metrics = $this->getCurrentMetrics();

        if (! $metrics['available']) {
            return [
                'status' => 'unknown',
                'score' => 0,
                'issues' => ['Unable to collect system metrics'],
            ];
        }

        $issues = [];
        $score = 100;

        // Type-safe extraction with guards
        /** @var array{usage_percent: float, count: int, load_average: array{'1min': float, '5min': float, '15min': float}} $cpu */
        $cpu = $metrics['cpu'];
        /** @var array{usage_percent: float} $memory */
        $memory = $metrics['memory'];
        /** @var array<array{mountpoint: string, usage_percent: float}> $disks */
        $disks = $metrics['disk'];

        // CPU health check
        $cpuUsage = $cpu['usage_percent'];
        if ($cpuUsage > 90) {
            $issues[] = sprintf('Critical CPU usage: %.1f%%', $cpuUsage);
            $score -= 40;
        } elseif ($cpuUsage > 70) {
            $issues[] = sprintf('High CPU usage: %.1f%%', $cpuUsage);
            $score -= 20;
        }

        // Memory health check
        $memoryUsage = $memory['usage_percent'];
        if ($memoryUsage > 90) {
            $issues[] = sprintf('Critical memory usage: %.1f%%', $memoryUsage);
            $score -= 40;
        } elseif ($memoryUsage > 80) {
            $issues[] = sprintf('High memory usage: %.1f%%', $memoryUsage);
            $score -= 20;
        }

        // Disk health check (check most critical mount)
        foreach ($disks as $disk) {
            $diskUsage = $disk['usage_percent'];
            $mountpoint = $disk['mountpoint'];

            if ($diskUsage > 95) {
                $issues[] = sprintf('Critical disk space on %s: %.1f%%', $mountpoint, $diskUsage);
                $score -= 30;
            } elseif ($diskUsage > 85) {
                $issues[] = sprintf('Low disk space on %s: %.1f%%', $mountpoint, $diskUsage);
                $score -= 10;
            }
        }

        // Load average health check
        $cpuCount = $cpu['count'];
        $loadAvg1min = $cpu['load_average']['1min'];
        $loadPerCpu = $loadAvg1min / $cpuCount;

        if ($loadPerCpu > 2.0) {
            $issues[] = sprintf('Critical system load: %.2f (%d CPUs)', $loadAvg1min, $cpuCount);
            $score -= 30;
        } elseif ($loadPerCpu > 1.5) {
            $issues[] = sprintf('High system load: %.2f (%d CPUs)', $loadAvg1min, $cpuCount);
            $score -= 15;
        }

        $score = max(0, $score);

        $status = match (true) {
            $score >= 80 => 'healthy',
            $score >= 60 => 'degraded',
            $score >= 40 => 'warning',
            default => 'critical',
        };

        return [
            'status' => $status,
            'score' => $score,
            'issues' => $issues,
            'timestamp' => time(),
        ];
    }
}
