# Laravel Queue Metrics - Project Overview

## Project Purpose
A production-ready Laravel queue monitoring package providing deep observability into queue systems, job execution, worker performance, and server resource metrics.

## Tech Stack
- **Language**: PHP 8.2+
- **Framework**: Laravel 11.0+ or 12.0+
- **Testing**: Pest 4.0
- **Storage**: Redis (primary) or Database (persistent)
- **Monitoring**: Prometheus export support
- **Dependencies**: gophpeek/system-metrics, spatie/laravel-prometheus

## Code Quality Standards
- **Format**: Laravel Pint (PSR-12)
- **Analysis**: PHPStan level 9 ✅ (0 errors)
- **Type Hints**: Full type declarations (PHP 8.2+)
- **Naming**: PSR-4 autoloading, camelCase for methods/properties
- **Class Style**: `readonly` classes with constructor injection
- **Docstrings**: PHPDoc with @param, @return, @throws
- **Access Modifiers**: Explicit public/private/protected, `final` on classes
- **Test Coverage**: 97.4% (76/78 tests passing)

## Commands
- **Test**: `composer test` (Pest)
- **Lint/Format**: `composer format` (Pint)
- **Static Analysis**: `composer analyse` (PHPStan level 9)

## Code Structure
```
src/
├── Actions/              # Business logic (Record*, Calculate*, Transition*)
├── Listeners/            # Event handlers (job & worker lifecycle)
├── Repositories/         # Data access layer
├── Services/             # High-level operations
├── Storage/              # Storage driver implementations
├── DataTransferObjects/  # Immutable data objects
├── Http/                 # Controllers & routes
├── Commands/             # Artisan commands
├── Events/               # Custom events
├── Support/              # Helper classes & utilities
│   └── LuaScripts/       # Redis Lua scripts for atomic operations
└── Exceptions/           # Custom exceptions
```

## Current Event System
**Registered in LaravelQueueMetricsServiceProvider::packageBooted()**

**Job Lifecycle Events**:
- `JobQueued` → JobQueuedListener
- `JobProcessing` → JobProcessingListener
- `JobProcessed` → JobProcessedListener
- `JobFailed` → JobFailedListener
- `JobRetryRequested` → JobRetryRequestedListener
- `JobTimedOut` → JobTimedOutListener
- `JobExceptionOccurred` → JobExceptionOccurredListener

**Worker Lifecycle Events**:
- `WorkerStopping` → WorkerStoppingListener
- `Looping` → LoopingListener (loop iteration heartbeats)

## Worker ID Generation
Uses `HorizonDetector` utility class that intelligently detects:
- **Horizon workers**: Extracts supervisor name from CLI arguments
- **Standard workers**: Uses hostname + PID combination
- Pattern: `sprintf('worker_%s_%d', gethostname() ?: 'unknown', getmypid())`

## Redis Integration Notes
- Uses Laravel's PhpRedisConnection wrapper (not native Redis)
- Lua script execution via evalsha/eval for atomic operations
- Worker heartbeat tracking uses cached SHA1 for performance
- TTL management on all keys to prevent memory bloat
