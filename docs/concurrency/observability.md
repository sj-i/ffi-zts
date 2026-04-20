# Observability

Status: **Draft** -- specifies the minimum metrics surface for
the concurrency layer, how it is exposed, and how much overhead
it carries.

## 1. Why this is non-optional

Zero-copy is fast when it works and catastrophic when it does
not. A leaked payload is a leaked malloc; a stuck worker is a
vanished core; an overloaded channel is an unbounded queue
masquerading as throughput. None of those are visible from a
standard PHP-FPM dashboard.

The metrics listed here are the minimum to distinguish "healthy",
"saturated", and "broken". Callers can plug them into whatever
monitoring they already use; the primitives are framework-agnostic.

## 2. Metric taxonomy

All metrics are either:

- **Counter** -- monotonic total; rate derived by consumer.
- **Gauge** -- instantaneous value.
- **Histogram** -- distribution of values; exported as buckets
  plus count / sum, the same shape Prometheus and OpenTelemetry
  use.

Unit suffixes are explicit (`_bytes`, `_count`, `_ns`, `_ratio`).
Timing is nanoseconds to avoid unit confusion; consumers can
bucket to microseconds or milliseconds as they prefer.

## 3. Arena metrics

| Name | Type | Description |
| --- | --- | --- |
| `arena.allocated_bytes` | gauge | Total bytes currently held in outstanding persistent allocations. |
| `arena.outstanding_payloads` | gauge | Number of Payload handles still tracked by the arena (not yet sent or released). |
| `arena.total_allocations_count` | counter | Cumulative allocation calls. |
| `arena.total_releases_count` | counter | Cumulative `pefree` calls driven by this arena. |
| `arena.fromstring_count` | counter | Subset of allocations that came from `fromString()` (vs empty `alloc()`). |
| `arena.leaked_on_release_count` | counter | Payloads that were still tracked at `$arena->release()` (producer forgot to send). |
| `arena.dtor_leak_count` | counter | Payloads freed by `__destruct` rather than by send (fresh-state handle that went out of scope). |

Access: `$arena->metrics(): array`. Returns a snapshot of the
gauges and the current counter values.

## 4. Channel metrics

Per-channel, keyed by channel name (or handle identity if
anonymous).

| Name | Type | Description |
| --- | --- | --- |
| `channel.queue_depth` | gauge | Messages currently buffered. |
| `channel.capacity` | gauge | Channel's configured capacity. |
| `channel.send_count` | counter | Successful sends. |
| `channel.send_bytes` | counter | Cumulative bytes sent (sum of payload sizes). Does not include the tiny handoff header. |
| `channel.recv_count` | counter | Successful receives. |
| `channel.send_blocked_ns` | histogram | Time spent blocked in `send()` waiting for capacity. |
| `channel.recv_blocked_ns` | histogram | Time spent blocked in `recvInto()` waiting for a message. |
| `channel.send_rejected_count` | counter | `trySend` rejections (backpressure). |
| `channel.send_timeout_count` | counter | `sendWithin` timeouts. |
| `channel.dropped_count` | counter | `sendOrDrop` discards. |

Access: `$ch->metrics()`, or `Channel::allMetrics()` for a map
of every channel in the process.

## 5. Pool metrics

| Name | Type | Description |
| --- | --- | --- |
| `pool.workers_total` | gauge | Configured worker count. |
| `pool.workers_active` | gauge | Workers currently executing a task. |
| `pool.workers_idle` | gauge | Workers waiting for work. |
| `pool.workers_dead` | gauge | Workers whose threads exited (crash or explicit `kill`). |
| `pool.queue_depth` | gauge | Tasks queued but not yet dispatched. |
| `pool.queue_capacity` | gauge | Pool queue capacity. |
| `pool.submit_count` | counter | Successful submissions. |
| `pool.submit_blocked_ns` | histogram | Time spent blocked in `submit()` waiting for a slot. |
| `pool.submit_rejected_count` | counter | `tryBlockSubmit` rejections. |
| `pool.caller_runs_count` | counter | `submitAndRun` executions (overflow handling). |
| `pool.task_duration_ns` | histogram | End-to-end task time (dispatch to completion). |
| `pool.task_success_count` | counter | Tasks that returned normally. |
| `pool.task_error_count` | counter | Tasks that raised (see `error-model.md`, all TaskException variants). |
| `pool.task_cancelled_count` | counter | Tasks that ended with `TaskCancelled`. |
| `pool.task_timeout_count` | counter | Tasks that ended with `TaskTimeoutException`. |
| `pool.worker_crash_count` | counter | `WorkerCrashedException` occurrences. |

Access: `$pool->metrics()`.

## 6. Payload metrics (process-wide)

Payload itself holds no metric state -- its lifecycle ticks are
counted on the Arena side. The counts are useful aggregated at
the process level:

| Name | Type | Description |
| --- | --- | --- |
| `payload.inflight_total` | gauge | Sum of `arena.outstanding_payloads` across all arenas in the process. |
| `payload.max_size_bytes` | gauge | Largest individual payload seen. |
| `payload.handoff_count` | counter | Cumulative `take()` / `send()` consumption events. |

Access: `Payload::processMetrics()`.

## 7. Surfacing the metrics

### 7.1 Pull: `metrics()` methods

Every metric-carrying class exposes `metrics(): array` returning
a flat snapshot. The caller polls on whatever cadence makes
sense. This is the primitive everything else layers over.

### 7.2 Push: exporter hook

```php
use SjI\FfiZts\Concurrent\Metrics\Exporter;

Exporter::install(function (array $snapshot): void {
    $prometheus->merge($snapshot);
    // or: post to statsd, OpenTelemetry, etc.
});
```

The exporter callback is invoked on a user-chosen cadence
(e.g. from a Revolt timer). We do not install a background
thread for metric export -- that would defeat the purpose of
minimising runtime overhead.

### 7.3 Adapter sketches

- **OpenTelemetry.** Wrap `metrics()` output in OTel `Counter` /
  `ObservableGauge` / `Histogram` instruments. Sample adapter
  to ship with the package as an example.
- **Prometheus (prometheus/client_php).** Same shape, different
  instrument types; also shippable as a sample.
- **StatsD / custom.** Users who already have a client wire it
  into the exporter callback directly.

The adapters are user-facing code, not implementation-internal.
The package ships reference snippets, not hard dependencies.

## 8. Overhead

The metric hooks are hot-path adjacent, so cost is a design
concern:

| Operation | Overhead per call |
| --- | --- |
| Counter increment | a plain `++` on an `int` property. ns-scale. |
| Gauge set | field write. ns-scale. |
| Histogram observation | bucket index + bucket increment. ~10 ns. |
| `metrics()` snapshot | O(number of metrics); cheap even at tens of metrics. |

Our counters use plain PHP integers. Atomic counters are not
needed for metrics because readers tolerate slightly-stale
values and writers never read the counter back before
incrementing. This is a deliberate trade: metrics correctness is
"approximately right under load" rather than "exact".

### 8.1 Histogram bucket strategy

Histograms use fixed exponential buckets by default
(`[100, 1_000, 10_000, 100_000, 1_000_000, ...] ns`). Callers
who need custom buckets (e.g. tighter resolution around their
p99) can configure them at construction time.

### 8.2 Sampling

For the very hottest histograms (every `send_blocked_ns`
observation, for example), sampling at a fixed ratio is an
option. Default sampling rate: 1.0 (every observation). Users
who profile a 10\u00d7 overhead benefit from sampling turn it
down; the bucketing stays faithful.

## 9. Structured logging integration

Optional but recommended: when a task fails (counter increment
for `task_error_count`), log-correlate with the task's identity:

```
{
  "task_id": "...",
  "pool": "default",
  "started_at_ns": 172...,
  "duration_ns": ...,
  "error_class": "TaskException",
  "error_previous_class": "RuntimeException",
  "error_message": "...",
  "worker_id": 3
}
```

This is a callback the user installs; the package does not
emit logs directly. The metric increment fires the callback
with the relevant payload.

## 10. Minimum dashboard

Reference view for operators:

- **Arena panel:** `allocated_bytes` gauge, `outstanding_payloads`
  gauge, `dtor_leak_count` and `leaked_on_release_count` as
  alert-worthy counters.
- **Channel panel:** `queue_depth` vs `capacity` as a utilisation
  ratio per channel; `send_rejected_count`, `dropped_count`,
  `send_timeout_count` as alerts.
- **Pool panel:** `workers_active` / `workers_total` as
  utilisation; `submit_blocked_ns` p50 / p99; `task_duration_ns`
  p50 / p99; `task_error_count`, `task_timeout_count`,
  `worker_crash_count` as alerts.
- **Payload panel:** `inflight_total` gauge, `max_size_bytes`
  high-water.

## 11. Open questions

- **Per-task metadata vs per-class.** We count `task_error_count`
  per pool; richer tags (per task class, per user-defined label)
  need a tag schema that plays with the target systems.
- **Cardinality of channel metrics for anonymous channels.**
  Potentially explodes if callers create ephemeral channels per
  request. Mitigation: by-default group anonymous channels into a
  single bucket, opt-in to per-handle granularity.
- **Histogram accuracy under high rate.** Fixed buckets are cheap
  but lose precision; more accurate algorithms (HDR Histogram,
  t-digest) exist but add cost. Reference implementations ship
  with fixed buckets; swap in per use case.
- **Event-style emission vs metric aggregation.** Some users
  prefer individual events (one per task) they can aggregate in
  their stack rather than pre-aggregated metrics. Both are
  possible but are different exporters; decide based on observed
  demand.
