# Ecosystem positioning

Status: **Draft** -- positions this work within the broader
concurrency / parallelism landscape. Included so that design
choices can be traced back to what already exists elsewhere,
and so that users can pick the right tool.

## 1. PHP's existing threading / concurrency tools

| Tool | Shape | What it actually delivers |
| --- | --- | --- |
| `parallel` (`pecl/parallel`) | Real OS threads, one Zend VM per worker | The only production option for CPU parallelism in modern PHP. Requires ZTS; wrapped by `ffi-zts` for NTS hosts. |
| Swoole | Coroutines on a single OS thread + worker processes | I/O concurrency; coroutine hooks into standard functions. Not OS-thread parallelism within one process. |
| AMPHP / Revolt | Event loop + Fibers, single thread | I/O concurrency via coroutines. No CPU parallelism. |
| ReactPHP | Event loop, single thread | I/O concurrency. |
| Fiber (PHP 8.1+) | Stackful coroutines, single thread | Language primitive under the above schedulers. No parallelism by itself. |
| FrankenPHP | Worker mode, thread pool per process | HTTP request handling across OS threads; does not expose threads as a user primitive for computation. |

The cross-cutting observation: **I/O concurrency in PHP has
multiple mature options; CPU parallelism has exactly one
(`parallel`).** Our work sits squarely on the CPU parallelism
axis, making the one option easier to reach and richer in
inter-thread data sharing.

### 1.1 In-flight adjacent work

There is active PHP RFC activity aimed at standardising
coroutine-style concurrency at the language level, with some
proposals including reactor integration. We track this
peripherally: the payload / injection layer described here is
orthogonal (it deals with parallelism, not concurrency), but if a
reactor API standardises on `libuv` or similar, our
`AsyncChannel` can add an adapter that registers through that
reactor instead of using `eventfd` directly. API names in this
package deliberately avoid the most likely globals (`spawn`,
`await`, `suspend`) so that future additions at that layer can
coexist.

No part of this design depends on any specific RFC landing.

## 2. Node.js Worker Threads

Node's model is similar in shape (isolate per worker) but with
different tools for data sharing:

| Node primitive | Role | PHP analogue |
| --- | --- | --- |
| `Worker` | OS thread with its own V8 isolate | `parallel\Runtime` (+ `ffi-zts` for the NTS case) |
| `postMessage(msg)` | structured clone across workers | `parallel\Channel` with `igbinary` |
| `postMessage(buf, [buf])` -- transferable | Ownership transfer, zero-copy; original becomes detached at runtime | **Our `Payload<Fresh>` -> `Payload<Drained>`**, enforced statically rather than at runtime |
| `SharedArrayBuffer` + `Atomics` | Long-lived shared memory region with atomic ops | Planned later-phase `SharedBuffer` + FFI atomics |
| `MessagePort` | 1:1 channel with fd-like semantics | Planned `AsyncChannel` (eventfd-backed on Linux) |
| `BroadcastChannel` | 1:N pub-sub | Deferred; broadcast needs atomic refcount, covered in a later phase |

Philosophical differences:

- Node enforces transferables at **runtime, by the VM**: using a
  detached ArrayBuffer throws. Our equivalent is enforced at
  **static analysis time**: using a Drained payload fails the
  type check. The static guarantee is weaker (aliasing can defeat
  it) but more ergonomic (no explicit transfer list).
- Node's `SharedArrayBuffer` gives raw bytes only; sharing a Map
  or object graph is not expressible. Our later phases aim at
  sharing immutable object graphs, which Node does not support.
- Node's `Atomics` API is specified; ours is a pragmatic wrap of
  C intrinsics. We keep Node's method names where plausible so
  users carrying mental models across runtimes do not have to
  relearn vocabulary.

## 3. Python

Python's concurrency story is in active evolution as of 2025/2026:

| Python primitive | Role |
| --- | --- |
| Free-threaded CPython (PEP 703, `python3.13t`+) | GIL-less build; threads can run Python bytecode in parallel. Not yet the default build. |
| `concurrent.interpreters` (PEP 734, 3.13+) | Sub-interpreters with per-interpreter GIL; similar to `parallel\Runtime` in spirit. |
| `multiprocessing` | Process-level parallelism, traditional. |
| `memoryview` over `multiprocessing.shared_memory` | Shared raw-byte buffer across processes. |
| Sub-interpreter shareable types (`bytes`, `memoryview`, primitives, tuples-of-shareable) | Narrow whitelist of types that can cross `Channel` cheaply. |

Python is approaching the same shape as this work from the
opposite direction: they are modifying the language runtime to
relax isolation, while we are layering richer sharing on top of a
runtime that already has (ZTS-based) isolation. The resulting APIs
look remarkably similar -- an arena-like shared buffer, a channel,
immutable-by-construction shareable types.

One design cue we adopt from Python: `memoryview` is Python's
unified abstraction for "a buffer of bytes whatever the source".
Our `Payload` occupies the same niche. Phase 4 / 5's richer
shapes (frozen arrays, immutable object graphs) extend this niche
where Python's sub-interpreter model has not yet taken it.

## 4. Go

Go sits in a different quadrant than PHP, Node, or Python. Three
characteristics matter for our framing:

1. **M:N scheduler (GPM).** Goroutines are multiplexed onto OS
   threads by the runtime; the scheduler owns both the task list
   and the network poller. Goroutines are nanoseconds-cheap to
   park / unpark.
2. **Shared-memory concurrency.** Goroutines share the process
   heap. The "don't communicate by sharing memory" slogan is a
   convention, not enforcement. `sync.Mutex`, `sync/atomic`, and
   `sync.Map` exist alongside channels.
3. **Unified park / unpark.** A goroutine blocked on a channel,
   a timer, a network read, or a syscall looks the same to the
   scheduler -- it is parked with a wakeup source. The netpoller
   hands ready fds back into the run queue directly.

We cannot replicate any of those without VM-level work. Our
pragmatic analogue:

- Tasks ~ goroutines, but at OS-thread granularity, not
  runtime-virtual.
- Channels are OS primitives (eventfd / pipe) rather than runtime
  primitives; an event loop (Revolt / AMP) plays the role of the
  scheduler for the parts it can see.
- `select` across I/O and channels is achieved by making
  channels look like fds. Go achieves it by making I/O look like
  channels. The destination is similar, the route is inverted.

Go's `select` vocabulary (random fair choice, `default` for
non-blocking, timer cases) is worth borrowing in the Phase 3
structured combinators.

## 5. Rust

Rust's `Send` / `Sync` marker traits are the gold standard for
compile-time concurrency safety:

- `Send` -- a type can cross threads (moved).
- `Sync` -- a type can be referenced from multiple threads.
- Auto-implemented for types whose components are all `Send`/`Sync`.
- Enforced by the compiler; bypass requires `unsafe`.

Our `Payload<Fresh>` phantom-type scheme is the PHP ecosystem's
closest available approximation. Rust's guarantee is strictly
stronger because:

- Aliasing rules are enforced by the borrow checker.
- Escape hatches (`unsafe`) are grep-visible and audit-friendly.
- Third-party crates follow the same traits by default.

We trade guarantee strength for ecosystem fit. The PHPStan-based
approach integrates into existing PHP CI pipelines; it does not
require users to adopt a new language. Acknowledging that trade
is part of the design, not a bug in it.

## 6. Erlang / Elixir

Erlang is the philosophical antipode of shared-memory Go:

- Per-actor heap. No shared mutable state across actors.
- Messages are immutable and value-copied (small) or
  reference-counted (large binaries).
- Per-process GC; actor death is recoverable.

Our model is closer to Erlang than to Go in *policy* (ownership
transfer, immutable sharing) but closer to Go in *mechanism*
(shared process address space, OS-thread granularity). The design
explicitly borrows from Erlang: the ownership-transfer default,
the later-phase immutable-object sharing, and the "let it crash
at the task boundary" posture for worker errors.

## 7. What our niche actually is

In the space defined by:

- **Isolation** -- each worker has its own VM state.
- **Rich sharing** -- strings, then immutable arrays, then
  immutable object graphs.
- **Static-type discipline** -- enforced ownership patterns in
  the types users already write.
- **CPU parallelism** -- real OS threads, not coroutines.

...this project is, as far as we know, the only current option
for PHP. Node has the isolation and (narrower) sharing but lacks
object graphs; Python has the isolation and is evolving its
sharing whitelist; Go and Rust have richer sharing but at the
cost of shared mutable state; Erlang has the isolation but
rejects rich sharing on principle. PHP, through `ffi-zts` and
this concurrency layer, can occupy a distinct quadrant.
