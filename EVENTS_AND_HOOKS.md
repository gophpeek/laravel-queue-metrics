# Events & Hooks System

## Overview

Laravel Queue Metrics provides a comprehensive event system and hooks architecture for building autoscalers, real-time UIs, and custom integrations.

## Events (for Autoscaler & UI)

### 1. QueueDepthThresholdExceeded

**Fired when:** Queue depth exceeds configured threshold  
**Use case:** Trigger horizontal scaling (add more workers)  
**Broadcast channels:** 
- `queue-metrics`
- `queue-metrics.{connection}.{queue}`

```php
use PHPeek\LaravelQueueMetrics\Events\QueueDepthThresholdExceeded;

Event::listen(QueueDepthThresholdExceeded::class, function ($event) {
    // Trigger autoscaler
    if ($event->percentageOver > 50) {
        Autoscaler::scaleUp($event->depth->queue, workers: 5);
    }
});
```

**Event data:**
```php
[
    'connection' => 'redis',
    'queue' => 'default',
    'total_jobs' => 150,
    'pending_jobs' => 120,
    'reserved_jobs' => 30,
    'delayed_jobs' => 0,
    'threshold' => 100,
    'percentage_over' => 50.0,
    'measured_at' => '2024-01-15T10:30:00Z',
]
```

### 2. WorkerEfficiencyChanged

**Fired when:** Worker efficiency changes significantly  
**Use case:** Optimize worker count based on actual utilization  
**Broadcast channel:** `queue-metrics`

```php
use PHPeek\LaravelQueueMetrics\Events\WorkerEfficiencyChanged;

Event::listen(WorkerEfficiencyChanged::class, function ($event) {
    match ($event->broadcastWith()['recommendation']) {
        'scale_up' => Autoscaler::scaleUp(),
        'scale_down' => Autoscaler::scaleDown(),
        'maintain' => null,
    };
});
```

**Event data:**
```php
[
    'current_efficiency' => 92.5,
    'previous_efficiency' => 75.0,
    'change_percentage' => 17.5,
    'active_workers' => 10,
    'idle_workers' => 0,
    'recommendation' => 'scale_up', // 'scale_up', 'scale_down', 'maintain'
]
```

### 3. BaselineRecalculated

**Fired when:** Baseline metrics are recalculated  
**Use case:** Update autoscaler vertical scaling settings (CPU/memory limits)  
**Broadcast channels:**
- `queue-metrics`
- `queue-metrics.{connection}.{queue}`

```php
use PHPeek\LaravelQueueMetrics\Events\BaselineRecalculated;

Event::listen(BaselineRecalculated::class, function ($event) {
    // Update container resource limits
    if ($event->significantChange) {
        Kubernetes::updateDeployment([
            'cpu' => $event->baseline->cpuPercentPerJob * 1.2, // 20% buffer
            'memory' => $event->baseline->memoryMbPerJob * 1.3, // 30% buffer
        ]);
    }
});
```

**Event data:**
```php
[
    'connection' => 'redis',
    'queue' => 'default',
    'avg_duration_ms' => 250.5,
    'cpu_percent_per_job' => 15.2,
    'memory_mb_per_job' => 45.8,
    'confidence_score' => 0.85,
    'sample_size' => 150,
    'significant_change' => true,
    'calculated_at' => '2024-01-15T10:30:00Z',
]
```

### 4. HealthScoreChanged

**Fired when:** Queue health score changes significantly  
**Use case:** Alert systems, UI status updates  
**Broadcast channels:**
- `queue-metrics`
- `queue-metrics.{connection}.{queue}`

```php
use PHPeek\LaravelQueueMetrics\Events\HealthScoreChanged;

Event::listen(HealthScoreChanged::class, function ($event) {
    if ($event->broadcastWith()['severity'] === 'critical') {
        Slack::alert("Queue health critical: {$event->currentScore}");
    }
});
```

**Event data:**
```php
[
    'connection' => 'redis',
    'queue' => 'default',
    'current_score' => 45.0,
    'previous_score' => 85.0,
    'change' => -40.0,
    'status' => 'unhealthy',
    'severity' => 'critical', // 'critical', 'warning', 'info', 'normal'
]
```

### 5. MetricsRecorded

**Fired when:** New metrics are recorded (high frequency)  
**Use case:** Real-time UI updates  
**Broadcast channels:**
- `queue-metrics`
- `queue-metrics.{connection}.{queue}`
- `queue-metrics.jobs.{jobClass}`

**Note:** Disabled by default due to high frequency. Enable in config:

```php
'broadcasting' => [
    'events' => [
        'metrics_recorded' => true,
    ],
],
```

## WebSocket Broadcasting (for Real-Time UI)

### Setup

1. Install Laravel Echo and Pusher/Soketi:

```bash
npm install --save laravel-echo pusher-js
```

2. Enable broadcasting in config:

```php
// config/queue-metrics.php
'broadcasting' => [
    'enabled' => true,
    'throttle' => 5, // seconds between broadcasts
],
```

3. Configure Laravel Echo:

```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
    forceTLS: true
});
```

### Vue.js Example (for UI Dashboard)

```vue
<script setup>
import { ref, onMounted, onUnmounted } from 'vue';

const queueDepth = ref(0);
const workerEfficiency = ref(0);
const healthScore = ref(100);

onMounted(() => {
    // Listen to all queue metrics
    window.Echo.channel('queue-metrics')
        .listen('.queue.depth.threshold.exceeded', (e) => {
            queueDepth.value = e.total_jobs;
            // Trigger warning animation
            showWarning('Queue depth high!');
        })
        .listen('.worker.efficiency.changed', (e) => {
            workerEfficiency.value = e.current_efficiency;
            updateEfficiencyChart(e);
        })
        .listen('.health.score.changed', (e) => {
            healthScore.value = e.current_score;
            if (e.severity === 'critical') {
                showCriticalAlert(e);
            }
        });

    // Listen to specific queue
    window.Echo.channel('queue-metrics.redis.high-priority')
        .listen('.baseline.recalculated', (e) => {
            updateBaselineDisplay(e);
        });
});

onUnmounted(() => {
    window.Echo.leave('queue-metrics');
    window.Echo.leave('queue-metrics.redis.high-priority');
});
</script>

<template>
    <div class="dashboard">
        <QueueDepthChart :depth="queueDepth" />
        <EfficiencyGauge :efficiency="workerEfficiency" />
        <HealthIndicator :score="healthScore" />
    </div>
</template>
```

### React Example

```jsx
import { useEffect, useState } from 'react';
import Echo from 'laravel-echo';

function QueueMetricsDashboard() {
    const [metrics, setMetrics] = useState({
        depth: 0,
        efficiency: 0,
        health: 100
    });

    useEffect(() => {
        const channel = Echo.channel('queue-metrics');

        channel
            .listen('.queue.depth.threshold.exceeded', (e) => {
                setMetrics(prev => ({ ...prev, depth: e.total_jobs }));
            })
            .listen('.worker.efficiency.changed', (e) => {
                setMetrics(prev => ({ ...prev, efficiency: e.current_efficiency }));
            })
            .listen('.health.score.changed', (e) => {
                setMetrics(prev => ({ ...prev, health: e.current_score }));
            });

        return () => {
            Echo.leave('queue-metrics');
        };
    }, []);

    return (
        <div className="dashboard">
            <QueueDepthCard depth={metrics.depth} />
            <EfficiencyCard efficiency={metrics.efficiency} />
            <HealthCard score={metrics.health} />
        </div>
    );
}
```

## Hooks System (for Custom Processing)

### Creating a Custom Hook

```php
namespace App\Hooks;

use PHPeek\LaravelQueueMetrics\Contracts\MetricsHook;

class CustomMetricsEnricherHook implements MetricsHook
{
    public function handle(array $data): array
    {
        // Add custom metrics
        $data['custom_metric'] = $this->calculateCustomMetric($data);
        
        // Enrich with external data
        $data['cost_per_job'] = $this->calculateCost($data);
        
        return $data;
    }

    public function shouldRun(string $context): bool
    {
        // Only run for specific contexts
        return in_array($context, ['after_record', 'after_calculate']);
    }

    public function priority(): int
    {
        return 10; // Lower number = higher priority
    }

    private function calculateCustomMetric(array $data): float
    {
        // Your custom logic
        return 42.0;
    }

    private function calculateCost(array $data): float
    {
        $duration = $data['duration'] ?? 0;
        $cpuCost = 0.001; // $0.001 per second
        return ($duration / 1000) * $cpuCost;
    }
}
```

### Registering Hooks

```php
// config/queue-metrics.php
'hooks' => [
    'after_record' => [
        \App\Hooks\CustomMetricsEnricherHook::class,
        \App\Hooks\CostCalculationHook::class,
    ],
    'after_calculate' => [
        \App\Hooks\MetricsExportHook::class,
    ],
    'after_baseline' => [
        \App\Hooks\BaselineNotificationHook::class,
    ],
],
```

### Available Hook Contexts

- `before_record`: Before recording job metrics
- `after_record`: After recording job metrics (enrich with custom data)
- `before_calculate`: Before calculating aggregated metrics
- `after_calculate`: After calculating aggregated metrics (export to external systems)
- `before_baseline`: Before baseline calculation
- `after_baseline`: After baseline calculation (trigger alerts/scaling)

## Autoscaler Integration Example

```php
namespace App\Services;

use PHPeek\LaravelQueueMetrics\Events\QueueDepthThresholdExceeded;
use PHPeek\LaravelQueueMetrics\Events\WorkerEfficiencyChanged;
use PHPeek\LaravelQueueMetrics\Events\BaselineRecalculated;
use Illuminate\Support\Facades\Event;

class AutoscalerService
{
    private int $minWorkers = 1;
    private int $maxWorkers = 20;
    private int $currentWorkers = 5;

    public function boot(): void
    {
        Event::listen(QueueDepthThresholdExceeded::class, [$this, 'handleQueueDepth']);
        Event::listen(WorkerEfficiencyChanged::class, [$this, 'handleEfficiency']);
        Event::listen(BaselineRecalculated::class, [$this, 'handleBaseline']);
    }

    public function handleQueueDepth(QueueDepthThresholdExceeded $event): void
    {
        if ($event->percentageOver > 50) {
            $this->scaleUp(workers: 3);
        } elseif ($event->percentageOver > 100) {
            $this->scaleUp(workers: 5);
        }
    }

    public function handleEfficiency(WorkerEfficiencyChanged $event): void
    {
        if ($event->currentEfficiency > 90 && $event->idleWorkers === 0) {
            $this->scaleUp();
        } elseif ($event->currentEfficiency < 50 && $event->idleWorkers > 3) {
            $this->scaleDown();
        }
    }

    public function handleBaseline(BaselineRecalculated $event): void
    {
        if ($event->significantChange) {
            // Update Kubernetes resource limits
            $this->updateResourceLimits([
                'cpu' => $event->baseline->cpuPercentPerJob * 1.2,
                'memory' => $event->baseline->memoryMbPerJob * 1.3,
            ]);
        }
    }

    private function scaleUp(int $workers = 1): void
    {
        $newCount = min($this->currentWorkers + $workers, $this->maxWorkers);
        if ($newCount > $this->currentWorkers) {
            $this->setWorkerCount($newCount);
        }
    }

    private function scaleDown(int $workers = 1): void
    {
        $newCount = max($this->currentWorkers - $workers, $this->minWorkers);
        if ($newCount < $this->currentWorkers) {
            $this->setWorkerCount($newCount);
        }
    }

    private function setWorkerCount(int $count): void
    {
        // Kubernetes example
        Kubernetes::scale('queue-workers', replicas: $count);
        
        // Or Supervisor
        Supervisor::setNumProcs('queue-worker', $count);
        
        $this->currentWorkers = $count;
    }
}
```

## Configuration

```php
// config/queue-metrics.php

'broadcasting' => [
    'enabled' => env('QUEUE_METRICS_BROADCASTING_ENABLED', false),
    'throttle' => 5, // seconds between broadcasts
    'events' => [
        'queue_depth_threshold' => true,
        'worker_efficiency' => true,
        'baseline_recalculated' => true,
        'health_score' => true,
        'metrics_recorded' => false, // High frequency - disabled by default
    ],
    'thresholds' => [
        'queue_depth' => 100,
        'efficiency_change' => 10, // percent
        'health_score_change' => 15, // points
    ],
],

'hooks' => [
    'before_record' => [],
    'after_record' => [],
    'before_calculate' => [],
    'after_calculate' => [],
    'before_baseline' => [],
    'after_baseline' => [],
],
```

## Event Dispatch Locations

All events are automatically dispatched by the package. Here's where each event is fired:

### 1. MetricsRecorded
**Dispatched from:** `CalculateJobMetricsAction.php:51`
**Frequency:** After metrics calculation (configurable interval)
**Tests:** `tests/Unit/Events/MetricsRecordedTest.php` (3 tests, 6 assertions)

### 2. BaselineRecalculated
**Dispatched from:**
- `CalculateBaselinesAction.php:71` (per-job baseline)
- `CalculateBaselinesAction.php:91` (aggregated baseline)

**Frequency:** During baseline recalculation
**Tests:** `tests/Unit/Events/BaselineRecalculatedTest.php` (5 tests, 10 assertions)

### 3. QueueDepthThresholdExceeded
**Dispatched from:** `RecordQueueDepthHistoryAction.php:54`
**Frequency:** When queue depth exceeds threshold
**Tests:** `tests/Unit/Events/QueueDepthThresholdExceededTest.php` (5 tests, 11 assertions)

### 4. WorkerEfficiencyChanged
**Dispatched from:** `RecordTrendDataCommand.php:120`
**Frequency:** During trend data recording
**Tests:** `tests/Unit/Events/WorkerEfficiencyChangedTest.php` (7 tests, 14 assertions)

### 5. HealthScoreChanged
**Dispatched from:** `RedisQueueMetricsRepository.php:133`
**Frequency:** When health score changes significantly
**Tests:** `tests/Unit/Events/HealthScoreChangedTest.php` (9 tests, 18 assertions)

**Total Test Coverage:** 29 tests, 63 assertions ✅

## Testing Events

All events include comprehensive unit tests. Example:

```php
use PHPeek\LaravelQueueMetrics\Events\QueueDepthThresholdExceeded;
use Illuminate\Support\Facades\Event;

it('can be dispatched when queue depth exceeds threshold', function () {
    Event::fake([QueueDepthThresholdExceeded::class]);

    $depthData = new QueueDepthData(
        connection: 'redis',
        queue: 'default',
        pendingJobs: 150,
        reservedJobs: 10,
        delayedJobs: 5,
        oldestPendingJobAge: Carbon::now()->subMinutes(10),
        oldestDelayedJobAge: null,
        measuredAt: Carbon::now(),
    );

    QueueDepthThresholdExceeded::dispatch($depthData, 100, 50.0);

    Event::assertDispatched(QueueDepthThresholdExceeded::class, function ($event) {
        return $event->depth->connection === 'redis'
            && $event->depth->queue === 'default'
            && $event->threshold === 100
            && $event->percentageOver === 50.0;
    });
});
```

Run event tests:
```bash
vendor/bin/pest tests/Unit/Events/
```
