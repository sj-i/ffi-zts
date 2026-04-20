# ffi-zts concurrency design -- overview

Status: **Draft** -- outline of the zero-copy data-sharing and
higher-level concurrency layer to be built on top of the ffi-zts
embed. Companion detail documents will follow; this page is the
entry point.

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
   `@param-out`, with runtime fallbacks for the aliasing cases
   static analysis cannot see.
3. **Richer payload shapes** -- later phases extend the same
   mechanism to immutable arrays and immutable object graphs,
   targeting read-heavy use cases (ORM row fan-out, DTO broadcast,
   configuration sharing).
4. **Async channels** -- an `fd`-backed channel primitive so that
   channel waits and I/O waits can coexist in a single event loop
   inside a worker.
5. **Structured concurrency** -- thread pools, `Task` primitives,
   and combinators (`all` / `race` / `forEach`) layered on the
   above.

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

## 3. Phases at a glance

| Phase | Deliverable | Repo |
| --- | --- | --- |
| 1 | Arena + `Payload<Fresh|Drained>` + CV injection + PHPStan rule + in-process NTS <-> ZTS round-trip test | `ffi-zts` |
| 2 | `fd`-backed `AsyncChannel` + atomic primitives + thread pool + `parallel\Channel` adapter | `ffi-zts-parallel` |
| 3 | Structured concurrency combinators (`all`, `race`, `forEach`); work-stealing-aware API surface | `ffi-zts-parallel` |
| 4 | Immutable array payloads (persistent `HashTable` + `IS_ARRAY_IMMUTABLE`) | `ffi-zts` |
| 5 | Immutable object-graph codec (`@psalm-immutable` DTO -> shared persistent HashTable + per-VM thin wrapper) | `ffi-zts` |
| 6+ | Shared mmap buffer + atomic counter primitives; mutex / rwlock; MPMC queue | `ffi-zts-parallel` |

Phase 1 is the **valve**: if it does not deliver, nothing else
matters. Subsequent phases are scheduled opportunistically based
on what real workloads ask for.

## 4. Design documents

Companion documents, added incrementally:

- `docs/concurrency/payload.md` -- the persistent zend_string
  mechanism, CV injection implementation, ownership rules.
- `docs/concurrency/safety.md` -- the static + runtime safety
  model, PHPStan rule set, aliasing mitigations.
- `docs/concurrency/api.md` -- public API surface, naming
  conventions, namespace discipline.
- `docs/concurrency/ecosystem.md` -- comparison with Node Worker
  Threads, Python sub-interpreters, Go, Rust, Erlang; notes on
  PHP RFC activity adjacent to this work (we track but do not
  depend on any specific RFC).
- `docs/concurrency/limits.md` -- what userspace fundamentally
  cannot do without VM support, and what the shape of future VM
  work would have to look like.

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
- Companion detail documents: to be added incrementally on the
  same branch before any implementation lands, so the API shape
  is reviewable before code commits against it.
- Implementation: not started. The intent is to lock the Phase 1
  surface in writing first, then build outwards.
