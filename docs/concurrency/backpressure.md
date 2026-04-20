# Backpressure

Status: **Draft** -- fixes the policies for what happens when a
producer runs faster than a consumer: channel sends, pool
submits, `forEach` input streaming.

## 1. Why this needs its own document

"Producer faster than consumer" is the failure mode that does
not announce itself. It looks fine in load tests, starts
queueing in staging, then in production either eats RAM until
OOM or melts a thread pool into a head-of-line block.

The options for handling it are well known but rarely uniform
across a codebase. The goal here is to pick one default per
primitive, document the alternatives, and make the choice
visible at the API so callers do not stumble into the wrong
behaviour.

## 2. Channel send policies

`Channel::send()` needs a policy for "queue is full". Four
standard choices:

| Policy | Behaviour | When to use |
| --- | --- | --- |
| **block** | Wait until space is available. | Default. Bounded channels where producer should slow to match consumer. |
| **tryError** | Immediately throw `ChannelFullException`. | Caller wants to implement its own backoff / shedding. |
| **timeout** | Wait up to N ms, then throw. | Half-open systems: "wait briefly, else fail". |
| **drop** | Silently discard the message. | Lossy telemetry where newer > older. Logs a warning. |

### 2.1 API shape

```php
$ch = Channel::open('jobs', capacity: 128);

$ch->send($payload);                 // block (default)
$ch->trySend($payload);               // tryError; returns bool
$ch->sendWithin($payload, 500);       // timeout in ms
$ch->sendOrDrop($payload);            // drop; returns bool (true = sent)
```

The named methods make the policy explicit at the call site.
There is no "default policy" knob on the channel itself -- each
send picks its own policy.

### 2.2 Capacity default

Channels default to a bounded capacity. An unbounded channel is
a memory leak waiting to happen; if the caller genuinely wants
unbounded, they pass `capacity: PHP_INT_MAX` and accept the
consequence.

Recommended capacity heuristic: `2 * worker_count * avg_task_ms
/ avg_inter_arrival_ms`. Pending a real benchmarking post that
nails down a better rule of thumb.

### 2.3 AsyncChannel specifics

`AsyncChannel` has the same four policies, with one extra:

- `fd()` reports readable only when there is at least one
  message available to `tryRecvInto`.
- `send` on a full `AsyncChannel` still uses the policies above,
  with `trySend` being the most natural default in an event-loop
  context (the loop can back off and retry later).
- Spurious readable events are possible (signal-delivered
  interrupts); `tryRecvInto` must be called in a loop to drain.

## 3. Pool submit policies

When all workers are busy and the pool's internal queue is full:

| Policy | Behaviour | When to use |
| --- | --- | --- |
| **block** | `submit()` waits for a slot. | Default. Producer throughput is limited by pool. |
| **callerRuns** | `submit()` runs the closure in the caller's thread. | Simple throttling; caller pays for the overflow. Java's CallerRunsPolicy. |
| **reject** | `submit()` throws `PoolFullException`. | Load shedding; caller should bucket or drop. |
| **drop** | Silently discard. | Low-value background work. |

### 3.1 API shape

```php
$pool = Pool::withWorkers(8, queueCapacity: 64);

$future = $pool->submit($fn);                    // block
$future = $pool->tryBlockSubmit($fn);            // reject
$result = $pool->submitAndRun($fn);              // callerRuns -- returns the result directly
$pool->submitOrDrop($fn);                        // drop; returns bool
```

`submitAndRun` deserves a note: it executes the closure in the
caller's context rather than handing to a worker. This means the
closure does not benefit from worker-side isolation, CV injection,
or `parallel`'s closure-context restrictions. It is a throttling
primitive, not a fallback runtime.

### 3.2 Queue capacity

Pool queue defaults to `2 * worker_count`. This is intentionally
small; callers who want more buffering should set it explicitly
and acknowledge the memory impact. The pool is not a durable
queue; a message broker is a durable queue.

## 4. `Structured::forEach` input stream

The input to `forEach` is an iterable. The combinator dispatches
items onto the pool up to the `concurrency` bound, then waits
for a slot before pulling the next item from the iterable.

```php
Structured::forEach(
    source: someGenerator(),
    fn: fn ($item) => process($item),
    concurrency: 8,
);
```

Semantics:

- The input iterable is **pulled lazily**. `forEach` will not
  read ahead of what the pool can handle.
- If the iterable itself blocks (e.g. database cursor, generator
  awaiting I/O), the pool workers that finish early wait idle
  rather than racing ahead.
- On exception from a task: by default, the input iterator is
  not advanced further; in-flight tasks drain (\u00a79 of
  `error-model.md`). Callers who want "keep going through
  failures" pass `continueOnError: true`.
- On exception from the input iterator: propagated the same way
  as a task exception.

Backpressure in forEach is therefore **automatic and
bidirectional**: the pool's capacity governs the input pull
rate, and the input's availability governs the pool's dispatch
rate. No explicit tuning is required for the usual case.

## 5. Defaults summary

| Primitive | Default when full |
| --- | --- |
| `Channel::send` | block |
| `Channel::trySend` | throw `ChannelFullException` |
| `Pool::submit` | block |
| `Pool::tryBlockSubmit` | throw `PoolFullException` |
| `forEach` input | automatic lazy pull |

Defaults are chosen for safety (block rather than drop, throw
rather than block forever). Users who have measured their
system and want different behaviour use the `try*` / `*OrDrop`
variants explicitly.

## 6. Anti-patterns to call out

These are worth flagging in the user-facing docs because they
are tempting and consistently wrong:

- **Unbounded channel capacity as "the simple choice".** It is
  simple until it is 4 GB of queued messages because the
  consumer caught a cold.
- **`tryError` in a tight loop without backoff.** Produces a
  CPU-pegged retry storm. If you retry, add exponential backoff
  or -- better -- use `block` / `timeout`.
- **`drop` for messages that matter.** Drop is correct for
  telemetry, metrics, progress ticks. It is wrong for task
  dispatch, invoices, audit events.
- **`callerRuns` as a general safety valve.** It works for small
  overflows but breaks when the caller is supposed to stay
  responsive (e.g. a web request thread). Only use it when you
  have measured that caller-side execution is acceptable.
- **Sizing the queue to "whatever does not OOM in load tests".**
  That is a formula for cliff-edge failures. Size based on
  observed latency × throughput; accept that overflow is a real
  signal, not a bug.

## 7. Observability hooks

Every backpressure-sensitive primitive exposes metrics (see
`observability.md`):

- `channel.send_blocked_ns` histogram -- how long senders
  waited.
- `channel.send_rejected_count` counter -- trySend rejections.
- `channel.dropped_count` counter -- sendOrDrop discards.
- `pool.submit_blocked_ns` histogram.
- `pool.submit_rejected_count` counter.
- `pool.caller_runs_count` counter.

Alerts should live on the rejection / drop counters, not on the
block histograms -- blocking is the intended behaviour,
rejection means capacity is exhausted.

## 8. Open questions

- **Channel priority / multiple queues.** Some users will want
  priority channels. Out of scope for Phase 2; potentially a
  Phase 3 combinator (`MergedChannel` with priority ordering).
- **Fairness.** Under `block`, multiple waiting senders are
  woken in what order? FIFO is the easy choice and probably
  right; needs explicit test once the implementation exists.
- **AsyncChannel partial backpressure.** `fd()` reports readable,
  but the consumer's `tryRecvInto` can be throttled by its own
  consumers further downstream. Whether the channel should
  signal "reader is slow" back to the writer is currently
  deferred.
- **Per-channel queue metrics vs per-pool.** Aggregation granularity
  for the dashboard -- per channel instance, per logical name,
  per process?
