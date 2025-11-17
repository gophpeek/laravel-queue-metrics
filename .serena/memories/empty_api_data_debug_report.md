# Laravel Queue Metrics - Empty API Data Debug Report

## Problem Summary
Jobs are being dispatched and processed, but API endpoints return:
- `total_queues: 0`
- `total_jobs_processed: 0`
- Only "default" queue visible
- No metrics data appearing

## Root Cause Analysis

### Issue 1: Hardcoded Zero in Overview Metrics (CRITICAL)
**File**: `src/Services/MetricsQueryService.php:86-98`

```php
public function getOverview(): array
{
    $queues = $this->queueMetricsRepository->listQueues();
    $workers = $this->workerRepository->getActiveWorkers();

    return [
        'total_queues' => count($queues),
        'total_jobs_processed' => 0, // ❌ HARDCODED TO ZERO
        'total_jobs_failed' => 0,
        'total_active_workers' => count($workers),
        'health_score' => 100.0,
    ];
}
```

**Impact**: 
- Overview endpoint always returns `total_jobs_processed: 0` regardless of actual job completions
- Should aggregate from all job classes across all queues/connections

### Issue 2: Queue Discovery Keys Never Set
**File**: `src/Repositories/RedisQueueMetricsRepository.php:130-148`

```php
public function listQueues(): array
{
    $pattern = $this->redis->key('discovered', '*', '*');
    $keys = $this->scanKeys($pattern);
    
    // Returns empty array if no 'discovered' keys exist
    // ❌ No code anywhere calls markQueueDiscovered()
}
```

**Problem**: 
- `listQueues()` searches for Redis keys with pattern `queue_metrics:discovered:*:*`
- `markQueueDiscovered()` method exists but is **never called** in the codebase
- Result: Empty queue list despite jobs being processed
- Grep confirms: Only 2 files contain this method (the definition and interface)

**Consequence**: Even though jobs are processed and stored in job-specific keys like `queue_metrics:jobs:default:default:App\Jobs\TestJob`, they won't be discovered because queue discovery keys are never created.

### Issue 3: Data Flow Break Points

Data should flow:
```
Job Event → Listener → Action → Repository → Redis Storage → API Query → Endpoint Response
```

Current state:
- ✅ Jobs dispatched (user confirmed)
- ✅ Listeners registered (JobProcessing, JobProcessed)
- ✅ Actions fire (RecordJobStart, RecordJobCompletion)
- ✅ Repository records data (RedisJobMetricsRepository.recordCompletion)
- ✅ Redis keys created: `queue_metrics:jobs:default:default:App\Jobs\TestJob`
- ✅ Metrics stored: `total_processed`, `total_duration_ms`, etc.
- ❌ Queue discovery keys NOT created (no `queue_metrics:discovered:*:*` keys)
- ❌ listQueues() returns empty
- ❌ Overview shows 0 queues

### Issue 4: getAllQueues() vs listQueues() Disconnect

**LaravelQueueInspector.php:58-84** - `getAllQueues()` reads from `config('queue.connections')`
- Returns configured queues like "default"
- Provides queue list but bypasses Redis discovery

**RedisQueueMetricsRepository.php:130-148** - `listQueues()` reads from Redis
- Returns empty because `discovered` keys never set
- Called by `MetricsQueryService.getOverview()`

**QueueDepthController.php:34-41** - `index()` endpoint calls `MetricsQueryService.getAllQueues()`
- Uses `LaravelQueueInspector.getAllQueues()`
- Returns configured queues ("default")
- Shows "only default queue" symptom

The two methods serve different purposes but create confusion about which is used where.

## Data Storage Verification

### What SHOULD exist in Redis after 50 jobs:
```
queue_metrics:jobs:default:default:App\Jobs\TestJob
  ├─ total_processed: 50
  ├─ total_duration_ms: [sum]
  ├─ total_memory_mb: [sum]
  └─ last_processed_at: [timestamp]

queue_metrics:durations:default:default:App\Jobs\TestJob (sorted set)
  └─ [50 duration samples with timestamps]

queue_metrics:memory:default:default:App\Jobs\TestJob (sorted set)
  └─ [50 memory samples with timestamps]

queue_metrics:discovered:default:default (if marking enabled)
  └─ [timestamp]
```

### What's likely missing:
- `queue_metrics:discovered:*:*` keys (discovery keys)
- Queue-level snapshot keys
- Trend history keys

## Code Flow Trace

### Listener Chain (WORKING):
1. `JobProcessed` event fires
2. `JobProcessedListener` handles it
3. Calls `RecordJobCompletionAction.execute()`
4. Calls `JobMetricsRepository.recordCompletion()`
5. Calls `RedisJobMetricsRepository.recordCompletion()`
6. Increments counters and stores samples in Redis

### API Query Chain (BROKEN):
1. Request to `/queue-metrics/overview`
2. `OverviewController.__invoke()`
3. Calls `MetricsQueryService.getOverview()`
4. Calls `$this->queueMetricsRepository->listQueues()`
5. `RedisQueueMetricsRepository.listQueues()` scans for `queue_metrics:discovered:*:*`
6. Returns empty array
7. `count($queues)` = 0
8. Returns `total_queues: 0`

## Configuration Issues

**config/queue-metrics.php**:
- TTL settings are fine (1 hour for raw data)
- Storage driver correctly set to 'redis'
- Prefix correctly set to 'queue_metrics'
- All actions configured correctly

**Potential issue**: If `QUEUE_METRICS_ENABLED` env var is false, all listeners are skipped (line 133 in ServiceProvider)

## Summary of Issues

| Issue | Severity | Location | Fix |
|-------|----------|----------|-----|
| Hardcoded `total_jobs_processed: 0` | CRITICAL | MetricsQueryService:93 | Aggregate from job metrics |
| Queue discovery never called | HIGH | No caller for markQueueDiscovered() | Call in job listeners OR change listQueues() implementation |
| Potential enabled config | MEDIUM | ServiceProvider:133 | Verify QUEUE_METRICS_ENABLED=true |

## Recommendations

1. **Immediate**: Verify `QUEUE_METRICS_ENABLED` is true in .env
2. **Short-term**: Add call to `markQueueDiscovered()` in job listeners or change queue detection logic
3. **Medium-term**: Implement proper aggregation for `total_jobs_processed` in overview
4. **Long-term**: Consider consolidating queue discovery logic (getAllQueues vs listQueues)
