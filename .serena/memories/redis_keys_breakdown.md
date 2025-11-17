# Redis Keys Breakdown - Where Data Should Be

## How Redis Keys Are Constructed

**StorageManager.key()** - `src/Storage/StorageManager.php:40-43`

```php
public function key(string ...$parts): string
{
    return $this->config->prefix . ':' . implode(':', $parts);
}
```

With `prefix = 'queue_metrics'`:
- `key('jobs', 'default', 'default', 'App\Jobs\TestJob')`
- Result: `queue_metrics:jobs:default:default:App\Jobs\TestJob`

---

## Expected Redis Keys After 50 Test Jobs

### 1. Job Metrics Keys (Created by RecordJobCompletionAction)

**Hash Key**: `queue_metrics:jobs:default:default:App\Jobs\TestJob`

Fields in this hash:
```
total_processed       → 50
total_duration_ms     → [sum of all durations]
total_memory_mb       → [sum of all memory]
total_cpu_time_ms     → [sum of all CPU times]
last_processed_at     → [unix timestamp]
total_queued          → 50
```

**Type**: Redis Hash
**TTL**: 3600 seconds (1 hour) - from `queue-metrics.storage.ttl.raw`
**Set By**: `RedisJobMetricsRepository.recordCompletion()` (line 44-96)

---

### 2. Duration Samples (Created by RecordJobCompletionAction)

**Sorted Set Key**: `queue_metrics:durations:default:default:App\Jobs\TestJob`

```
member             → score (timestamp)
"45.23"            → 1234567890
"48.12"            → 1234567891
"42.95"            → 1234567892
... (50 samples)
```

**Type**: Sorted Set (value=duration_ms, score=timestamp)
**TTL**: 3600 seconds
**Set By**: `RedisJobMetricsRepository.recordCompletion()` (line 80, pipeline)
**Used By**: 
- `RedisJobMetricsRepository.getDurationSamples()` → retrieves for percentile calculation
- `CalculateJobMetricsAction.calculateDuration()` → calculates p50, p95, p99

---

### 3. Memory Samples

**Sorted Set Key**: `queue_metrics:memory:default:default:App\Jobs\TestJob`

```
member             → score
"256.5"            → 1234567890
"258.2"            → 1234567891
... (50 samples)
```

**Type**: Sorted Set
**TTL**: 3600 seconds
**Set By**: Line 83 in RedisJobMetricsRepository

---

### 4. CPU Time Samples

**Sorted Set Key**: `queue_metrics:cpu:default:default:App\Jobs\TestJob`

```
member             → score
"100.5"            → 1234567890
"102.3"            → 1234567891
... (50 samples)
```

**Type**: Sorted Set
**TTL**: 3600 seconds
**Set By**: Line 86 in RedisJobMetricsRepository

---

### 5. Queue Discovery Keys (NEVER CREATED - THE BUG!)

**Simple String Key**: `queue_metrics:discovered:default:default`

```
value: [unix timestamp]
```

**Type**: String
**TTL**: 3600 seconds
**Should Be Set By**: `RedisQueueMetricsRepository.markQueueDiscovered()` (line 150-154)
**Called By**: ❌ NOBODY - This is the bug!

---

### 6. Queue Snapshots (Created by RecordQueueDepthHistoryAction)

**Hash Key**: `queue_metrics:queue_snapshot:default:default`

```
depth             → 0
pending           → 0
scheduled         → 0
reserved          → 0
oldest_job_age    → 0
throughput_per_minute → 0.0
avg_duration      → 0.0
failure_rate      → 0.0
utilization_rate  → 0.0
active_workers    → 0
recorded_at       → [timestamp]
```

**Type**: Hash
**TTL**: 604800 seconds (7 days) - from `queue-metrics.storage.ttl.aggregated`
**Should Be Set By**: `RecordQueueDepthHistoryAction` (if listener is called)
**Note**: This depends on listeners that may not be wired up

---

### 7. Job Tracking Keys (Temporary, cleaned after completion)

**Hash Key**: `queue_metrics:job:{jobId}`

Created by `RecordJobStartAction`:
```
job_class    → "App\Jobs\TestJob"
connection   → "default"
queue        → "default"
started_at   → [timestamp]
```

**Type**: Hash
**TTL**: 3600 seconds
**Deleted By**: `RecordJobCompletionAction` (line 95) or `RecordJobFailureAction`
**Lifecycle**: Created when job starts, deleted when job completes

---

## Data Flow to API Response

### Query Path for overview endpoint:

```
GET /queue-metrics/overview
  ↓
OverviewController.__invoke()
  ↓
MetricsQueryService.getOverview()
  ↓
QueueMetricsRepository.listQueues()
  ↓
RedisQueueMetricsRepository.listQueues()
  ↓
$this->redis->scanKeys('queue_metrics:discovered:*:*')
  ↓
❌ Returns empty! No discovered keys in Redis
  ↓
count($queues) = 0
  ↓
Response: { total_queues: 0, total_jobs_processed: 0, ... }
```

### What SHOULD happen:

If `markQueueDiscovered()` was called during job processing:

```
JobProcessing event
  ↓
JobProcessingListener or similar
  ↓
Call QueueMetricsRepository.markQueueDiscovered('default', 'default')
  ↓
Redis SET queue_metrics:discovered:default:default = [timestamp]
  ↓
Later: listQueues() finds this key
  ↓
Parses: key parts → connection='default', queue='default'
  ↓
Returns: [{connection: 'default', queue: 'default'}]
  ↓
count($queues) = 1
  ↓
Response includes: { total_queues: 1, ... }
```

---

## Verification Commands

To check what's actually in Redis:

```bash
# See all queue_metrics keys
redis-cli KEYS 'queue_metrics:*'

# Check if discovered keys exist
redis-cli KEYS 'queue_metrics:discovered:*'

# Check job metrics hash
redis-cli HGETALL 'queue_metrics:jobs:default:default:App\Jobs\TestJob'

# Check duration samples (first 10)
redis-cli ZRANGE 'queue_metrics:durations:default:default:App\Jobs\TestJob' 0 10

# Count of duration samples
redis-cli ZCARD 'queue_metrics:durations:default:default:App\Jobs\TestJob'
```

---

## Configuration Impact

**TTL Settings** - `config/queue-metrics.php:57-61`

```php
'ttl' => [
    'raw' => 3600,        // 1 hour - job samples, start times
    'aggregated' => 604800, // 7 days - snapshots, metrics
    'baseline' => 2592000,  // 30 days - baseline data
],
```

**Risk**: If these TTLs are too short, data expires before API reads it

---

## Summary

**What Works**:
- Job listeners fire and store data in Redis
- Duration/memory/CPU samples are saved
- Aggregate metrics (total_processed) are calculated and stored

**What Doesn't Work**:
- Queue discovery keys are never set
- listQueues() finds nothing
- overview endpoint shows 0 queues
- total_jobs_processed hardcoded to 0

**Critical Missing**: Call to `markQueueDiscovered()` somewhere in the event listener chain
