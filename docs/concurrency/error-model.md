# Error model

Status: **Draft** -- fixes the contracts for timeout,
cancellation, worker crash, and exception propagation across the
concurrency surface. Sits alongside `safety.md` (programmer-error
taxonomy) and covers the environmental / task-lifecycle errors
it does not.

## 1. Scope

`safety.md` \u00a74 lists the `LogicException` subclasses that fire
on programmer error (double-consume, closed arena, CV not found).
This document covers the orthogonal axis: what happens when
**task execution itself** goes wrong -- the worker threw, the
deadline expired, the parent scope was cancelled, or the thread
died. These are `RuntimeException` subclasses, not
`LogicException`, and they surface primarily through `Future`.

## 2. Exception categories

```
Throwable
 \u251c\u2500 LogicException  (programmer error, see safety.md \u00a74)
 \u2502   \u251c\u2500 ConsumedPayloadException
 \u2502   \u251c\u2500 ArenaClosedException
 \u2502   \u2514\u2500 CvNotFoundException
 \u2502
 \u2514\u2500 RuntimeException  (task lifecycle)
     \u251c\u2500 TaskException          (worker-side exception, wrapped)
     \u251c\u2500 TaskCancelled          (cooperative cancel acknowledged)
     \u251c\u2500 TaskTimeoutException   (deadline reached before completion)
     \u2514\u2500 WorkerCrashedException (thread died, Future can no longer resolve)
```

Aggregate exceptions for combinators:

```
RuntimeException
 \u2514\u2500 AggregateException  (race: all tasks failed; forEach: any failed)
     - getExceptions(): array<TaskException|TaskCancelled|...>
```

All `RuntimeException` subclasses are catchable at the `Future::get()`
call site. `LogicException` subclasses propagate the same way but
indicate the caller should fix code, not retry.

## 3. `Future::get()` semantics

```php
try {
    $result = $future->get();
    // task completed normally, $result is the closure's return value
} catch (TaskException $e) {
    // worker threw $e->getPrevious() on its side; use getPrevious()
    // to recover the original exception class + message + trace
} catch (TaskCancelled $e) {
    // task observed the cancel flag and returned early (or never
    // started, if cancel fired before dispatch)
} catch (TaskTimeoutException $e) {
    // task exceeded its deadline. Pool attempted Runtime::kill()
    // as a best-effort stop; see \u00a76 for what that does and does
    // not guarantee.
} catch (WorkerCrashedException $e) {
    // the worker thread died before producing a result. The cause
    // is often unrecoverable at the PHP level (fatal, OOM, segv
    // from an FFI call). The Future is permanently unresolved.
}
```

`Future::tryGet()` variants:

- `tryGet(): mixed` -- returns the value if complete, throws the
  same exceptions if failed, returns a sentinel (or throws a
  dedicated `FutureNotReadyException`) if still pending. The
  precise sentinel is pending API design.
- `isComplete(): bool` -- pure check, no side effects.

## 4. TaskException and worker-side errors

When a task closure throws, the thrown value is serialised over
the `parallel\Channel` and re-thrown on the caller side, wrapped
in a `TaskException` whose `getPrevious()` returns the original.

```php
$future = $pool->submit(fn () => throw new RuntimeException('boom'));
try {
    $future->get();
} catch (TaskException $e) {
    $original = $e->getPrevious();   // RuntimeException: boom
    // $original->getTrace() is the worker-side trace
}
```

Wrapping is deliberate:

- The caller always sees a `TaskException` first, so catch-site
  filtering by class works for "any task failure".
- The original is recoverable for handlers that need its type.
- Thread identity (which worker?) can be attached to the
  `TaskException` without modifying the user's exception.

### 4.1 Unserialisable exceptions

Some exception objects cannot be serialised (closures, resources,
FFI CData in properties). When serialisation fails, the caller
gets a synthesised `TaskException` containing the class name, the
message, and the string trace, but not the original object.
This is a known degradation, marked on the exception itself
(`$e->isSynthesised()`).

## 5. TaskCancelled and cancellation

Cancellation is **cooperative**. A task must reach a check point
to observe the cancel flag. This is a hard limit of PHP's VM
(see core `limits.md` \u00a72.1) and not something the API can hide.

### 5.1 Cancellation sources

- `$future->cancel()` -- explicit
- `$pool->shutdown(force: true)` -- pool-wide
- Structured scope abandonment -- caller's stack unwound past the
  combinator
- Deadline expiration -- automatic via `$pool->submit(..., timeoutMs: N)`
- Parent task cancelled -- propagates to child tasks submitted
  from inside the parent

### 5.2 What a cancelled task sees

```php
$pool->submit(function () {
    $cancel = Task::cancellationToken();  // Atomic::bool

    for ($i = 0; $i < 1_000_000_000; $i++) {
        if ($cancel->load()) {
            throw new TaskCancelled();   // the task voluntarily aborts
        }
        /* work */
    }
});
```

The `TaskCancelled` thrown from within the task is what the
caller's `Future::get()` sees. If the task never checks the flag
and completes normally, cancellation had no effect -- the caller
gets the return value.

### 5.3 What cancellation cannot do

- Interrupt code inside an internal function (e.g. mid-`sleep`,
  mid-`curl_exec`, mid-`preg_match` on a pathological pattern).
  The task resumes PHP-level code only when the internal function
  returns; that is the next cancel check-point available.
- Unwind a stack that has already committed a side effect. If the
  task wrote to a file and then observed the cancel flag,
  cancelling does not un-write. See \u00a77 on retry-safety.

### 5.4 Semantic guarantees

- `cancel()` is idempotent; calling it multiple times is fine.
- `cancel()` before the task is dispatched causes the task to
  complete as `TaskCancelled` without running.
- `cancel()` while the task is running sets the flag; eventual
  termination is best-effort.
- After `cancel()`, `Future::get()` eventually throws
  `TaskCancelled` or `TaskException` (if the task raised
  something else before observing the cancel). The caller must
  handle both.

## 6. TaskTimeoutException

A submit with a deadline:

```php
$future = $pool->submit(
    fn () => maybeSlow(),
    timeoutMs: 5_000,
);
```

After 5 seconds of elapsed wall time, the pool:

1. Sets the task's cancellation flag (cooperative, see \u00a75).
2. Waits a grace period (default: a fraction of `timeoutMs`, or
   a fixed minimum) for the task to observe and return.
3. If the task still has not completed, invokes
   `Runtime::kill()` on the worker.

`Runtime::kill()` attempts to interrupt the worker's main loop.
Per the `parallel` documentation, it cannot interrupt a task
that is currently inside an internal function. A worker stuck
in a pathological regex can only be stopped by taking the
process down.

`TaskTimeoutException` is thrown from `Future::get()` once the
kill completes (or the grace period gives up). The worker slot
is returned to the pool if the runtime is still alive; otherwise
the pool marks it dead and may respawn.

Given this weakness, deadline-based cancellation is honest about
what it delivers:

- For well-behaved tasks: clean `TaskCancelled` after the next
  check-point.
- For tasks that observe cancellation but are mid-syscall: stop
  once the syscall returns.
- For tasks stuck in an interruption-resistant path: potentially
  never.

Callers who need a hard wall-clock bound on pathological tasks
must accept that the only tool strong enough is process-level
supervision above the PHP process.

## 7. WorkerCrashedException

When a thread dies (fatal error, OOM, FFI segfault), any Futures
that were waiting on it enter a terminal failed state. The pool
detects this by:

- Watching for task-completion markers that never arrive.
- Catching Runtime destruction signals where possible.
- A periodic health check (optional, pending Phase 2 design).

`WorkerCrashedException` is raised on all outstanding Futures
owned by the dead worker. `$e->getOutstandingTaskCount()` tells
the caller how many siblings were affected.

Because `parallel\Runtime` death taking the process down is an
open issue (see core `limits.md`), our mitigation is
best-effort. Long-lived callers that must survive worker deaths
should run the pool in a subprocess and restart that subprocess
on crash.

## 8. Retry semantics

We do not auto-retry. The decision to retry is the caller's
responsibility, and the error class guides the classification:

| Class | Retry-safe by default? |
| --- | --- |
| `TaskException` (user exception) | Depends on the wrapped exception. Network-ish RuntimeExceptions usually yes; state-mutation exceptions usually no. |
| `TaskCancelled` | Yes if the cancel was due to timeout and the task is idempotent. No if it was an explicit `cancel()` from the caller. |
| `TaskTimeoutException` | Same as TaskCancelled. |
| `WorkerCrashedException` | Yes, but investigate the cause first -- a crashing task will crash again. Apply circuit-breaking. |

The exception subclasses do not encode this automatically; a
retry helper over the top (e.g. `RetryPolicy::exponential(...)`
) is material for a future helper package.

## 9. Combinator propagation rules

### 9.1 `Structured::all`

- All tasks run concurrently.
- First task to throw: its exception is stored; all siblings have
  their cancel flag set.
- `all()` waits for the remaining tasks to acknowledge cancellation
  (subject to \u00a75.3 limits); if they complete successfully
  regardless, their results are discarded.
- `all()` then throws the first exception (wrapped as
  `TaskException` or as-is if it was a `TaskCancelled`).
- If no task throws, `all()` returns the array of results keyed
  the same as the input.

### 9.2 `Structured::race`

- All tasks run concurrently.
- First task to complete successfully: its result is returned;
  siblings are cooperatively cancelled.
- If every task throws before any succeeds: `AggregateException`
  with all per-task exceptions.
- The distinction between "cancelled because another won" and
  "failed on its own" is preserved in `AggregateException`'s
  per-task list.

### 9.3 `Structured::forEach`

- Tasks stream through a bounded concurrency window.
- Exceptions are collected; first one becomes the propagated
  exception once the pool drains (not immediately -- other
  already-dispatched tasks complete first).
- If multiple tasks throw: they are reported via
  `AggregateException` with ordering by completion time.
- Backpressure of the input iterator is covered in `backpressure.md`.

### 9.4 Scope abandonment

If the caller's stack unwinds past a `Structured::*` call due to
a caller-side exception (not one raised inside the combinator),
all outstanding tasks have their cancel flag set as the combinator
unwinds. The combinator does not block for acknowledgement --
the caller-side exception continues propagating immediately.
Tasks that ignore cancellation will continue to run in the pool
until they complete, consuming a worker slot.

## 10. Open questions

- **`Future::tryGet` sentinel.** Whether to return a sentinel
  value or throw `FutureNotReadyException` for "still pending".
  Throwing is heavier but less ambiguous if `mixed` is already
  a valid result type.
- **Grace period defaults.** `pool->submit(timeoutMs: N)` needs
  a default grace period between "flag set" and "kill()". Likely
  `min(0.1 * N, 500ms)` pending measurement.
- **Respawn policy.** Pool default on worker crash: replace the
  slot (maintain headcount) or mark the pool degraded and let
  the caller decide? Safer default is probably "do not respawn
  until explicitly told".
- **Aggregate ordering.** `AggregateException` preserves
  completion order for debugging, but users often want "the
  first one that fired". Both need to be available.
- **Parent-child scope propagation under nested pools.** If a
  task submitted from inside task A spawns task B (in the same
  or a different pool), cancelling A should cancel B. The
  mechanism is a shared cancellation token; the edge cases
  (forked pools, detached tasks) need explicit API.
