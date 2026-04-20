# ffi-zts concurrency design -- overview

Status: **Draft** -- outline of the zero-copy data-sharing and
higher-level concurrency layer to be built on top of the ffi-zts
embed. Companion detail documents follow; this page is the entry
point.

> **Reading guide.** Phase 1 (\u00a73 below and the
> `payload.md` / `safety.md` / `api.md` \u00a72 sections of the
> companions) is the only part we treat as design-frozen. Phases
> 2-6 are sketches to show that the Phase 1 surface composes
> additively; their shapes are expected to move as Phase 1 is
> implemented and measured.

## 1. Summary

The core `ffi-zts` package already lets an NTS host PHP run code
inside an embedded ZTS interpreter, including through
`parallel\Runtime`. What it does **not** yet provide is an
ergonomic, type-safe way to move data across the NTS / ZTS boundary
(or between ZTS worker threads) without paying serialisation costs
or giving up static guarantees.

This design adds that layer in stages:

1. **Zero-copy payloads** -- a byte buffer allocated once, shared
   between the producer and consumer by pointer, received on the
   consumer side as a plain PHP `string` with no copy.
2. **Move-semantic handle types** -- a `Payload<Fresh | Drained>`
   state machine enforced statically via PHPStan / Psalm
   `@param-out`, with a runtime double-consume guard for the cases
   static analysis cannot see. We do not claim runtime aliasing
   detection -- see `safety.md` \u00a72.3 for why.
3. **Richer payload shapes** -- later phases extend the same
   mechanism to immutable arrays and immutable object graphs,
   targeting read-heavy use cases (ORM row fan-out, DTO broadcast,
   configuration sharing). These phases are structurally harder
   than Phase 1 (`limits.md` \u00a72.5) and are scheduled only after
   Phase 1 validates the approach.
4. **Async channels** -- an `fd`-backed channel primitive so that
   channel waits and I/O waits can coexist in a single event loop
   inside a worker.
5. **Structured concurrency** -- thread pools, `Task` primitives,
   and combinators (`all` / `race` / `forEach`) layered on the
   above, with cancellation semantics that are honestly cooperative
   (not preemptive; see `limits.md` \u00a72.1).

The satellite package `sj-i/ffi-zts-parallel` hosts the cross-thread
pieces (channels, pool, structured concurrency). The core package
hosts the primitives that are useful even in single-threaded
embedded-ZTS scenarios (Arena, Payload, CV injection).

## 2. Goals and non-goals

### Goals
- Make large-data sharing between NTS host and ZTS workers cheap
  enough that the ergonomic path is also the fast path.
- Keep the user-visible API small: users should reach for
  `Arena::fromString()` and `$channel->send($payload)` without
  touching FFI directly.
- Favour static guarantees (PHPStan / Psalm) over runtime checks
  wherever possible, given how painful concurrency bugs are to
  diagnose in production.
- Leave room for later phases without rewriting Phase 1 users:
  Phase 2+ features should be additive.
- Harmonise API naming with likely future core additions so that
  user code can migrate with minimal churn when or if the language
  grows equivalent primitives.

### Non-goals
- Not a re-implementation of `parallel`. `parallel\Runtime` remains
  the underlying thread primitive.
- Not a coroutine / reactor framework. Single-threaded async I/O
  (coroutines, event loops) is covered by existing ecosystem work
  (Revolt, AMPHP, ReactPHP, Swoole) and by ongoing language
  proposals that we keep a peripheral eye on but do not depend on.
- Not a shared mutable heap. The mechanism targets read-only /
  ownership-transfer patterns. Shared mutable state is explicitly
  out of scope for v1 and arguably for the foreseeable future --
  users who need it can wrap a C data structure via FFI.
- Not a goroutine-level M:N scheduler. True M:N requires VM-level
  fiber unmount / remount support that does not exist in current
  PHP. We document the gap and stop there.
- Not a universal receive mechanism via CV injection. CV injection
  is a fast path for synchronous receive; deferred / async /
  signal-dispatched paths use alternative Injectors. See
  `api.md` \u00a72.5 and `payload.md` \u00a74.1.

## 3. Phases at a glance

| Phase | Deliverable | Repo | Status |
| --- | --- | --- | --- |
| 1 | Arena + `Payload<Fresh|Drained>` + CV injection + alternative Injectors + PHPStan rule + in-process NTS <-> ZTS round-trip test | `ffi-zts` | **scoped** |
| 2 | `fd`-backed `AsyncChannel` + atomic primitives + thread pool + `parallel\Channel` adapter | `ffi-zts-parallel` | *sketch* |
| 3 | Structured concurrency combinators (`all`, `race`, `forEach`); cancellation scope; work-stealing-aware API | `ffi-zts-parallel` | *sketch* |
| 4 | Immutable array payloads (persistent `HashTable` + `IS_ARRAY_IMMUTABLE`) | `ffi-zts` | *sketch* |
| 5 | Immutable object-graph codec (`@psalm-immutable` DTO -> shared persistent HashTable + per-VM thin wrapper) | `ffi-zts` | *sketch* |
| 6+ | Shared mmap buffer + atomic counter primitives; mutex / rwlock; MPMC queue | `ffi-zts-parallel` | *sketch* |

Phase 1 is the **valve**: if it does not deliver, nothing else
matters. Subsequent phases are scheduled opportunistically based
on what real workloads ask for, and their design is expected to
shift as Phase 1 implementation uncovers details the document
could not anticipate.

## 4. Design documents

Companion documents, added incrementally:

- `docs/concurrency/payload.md` -- the persistent zend_string
  mechanism, CV injection implementation, ownership rules,
  and the applicability constraints of CV injection.
- `docs/concurrency/safety.md` -- the static + runtime safety
  model, PHPStan rule set, acknowledged gaps (notably: no runtime
  aliasing detection in Phase 1).
- `docs/concurrency/api.md` -- public API surface, naming
  conventions, namespace discipline, and Phase 2+ sketches.
- `docs/concurrency/ecosystem.md` -- comparison with Node Worker
  Threads, Python sub-interpreters, Ruby Ractor, Go, Rust, and
  Erlang; notes on PHP RFC activity adjacent to this work (we
  track but do not depend on any specific RFC).
- `docs/concurrency/limits.md` -- what userspace fundamentally
  cannot do without VM support, and what the shape of future VM
  work would have to look like.

Still to add (companion PRs):

- `ERROR_MODEL.md` -- timeout / cancellation / worker-crash
  semantics, retry-possible vs retry-impossible distinction.
- `BACKPRESSURE.md` -- channel-full / pool-full policies
  (block / drop / error) and when each is appropriate.
- `UNSAFE_CHECKLIST.md` -- boundary conditions implementers must
  respect (persistent-alloc timing, arena release ordering, CV
  lifetime, cross-thread refcount).
- `OBSERVABILITY.md` -- minimum metrics (arena total, in-flight
  payload count, queue depth, per-worker timing).

## 5. Relationship to existing ffi-zts design

The existing [`DESIGN.md`](DESIGN.md) covers the loader, symbol
isolation, extension loading, distribution, and raw FFI-buffer
sharing at the C pointer level. **Section 8 ("Memory sharing
model") already documents the low-level shared-address technique.**
The concurrency layer described here is the ergonomic, type-safe
surface that sits on top of that raw pointer model:

- Section 8 of `DESIGN.md` = the capability.
- This document + companions = the API that makes the capability
  usable without hand-rolling FFI casts on both sides of every
  boundary.

## 6. Status

- Phase 1 scoping: **in progress** on branch
  `claude/php-ffi-zend-string-e6Hga`.
- Companion detail documents: Phase 1 set complete
  (`payload`, `safety`, `api`, `ecosystem`, `limits`). Error
  model / backpressure / unsafe checklist / observability
  companions are tracked in \u00a74 as follow-up.
- Implementation: not started. The current PR is design-only so
  that the Phase 1 surface is reviewable before code commits
  against it. An implementation PoC branch will follow, initially
  covering Arena + Payload + CvInjector + round-trip test; that
  PoC is expected to surface details that will in turn revise
  these documents.
