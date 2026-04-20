# Userspace limits

Status: **Draft** -- documents what this design can and cannot
deliver without changes to PHP core itself, so contributors and
consumers can plan around the gaps.

## 1. What we can do in userspace

- Share large byte buffers zero-copy (Phase 1).
- Move ownership through a type-state machine (Phase 1).
- Unify channel and I/O waits in a single event loop via
  `fd`-backed channels (Phase 2).
- Build atomic counters / flags via FFI wrappers over `__atomic_*`
  intrinsics (Phase 2).
- Thread pool + structured-concurrency combinators on top of
  `parallel\Runtime` (Phases 2 / 3).
- Share immutable arrays and object graphs between isolates
  by handing off persistent backing memory and constructing thin
  per-VM wrappers (Phases 4 / 5).

This covers the large majority of practical cross-thread
workloads we know about: ORM row fan-out, ETL pipelines,
batch analytics, image / buffer processing, read-only cache
sharing.

## 2. What we cannot do in userspace

### 2.1 Preemptive cancellation

Once a `parallel\Runtime` worker is inside a long CPU loop, there
is no way for the host to interrupt it other than killing the
thread entirely (`Runtime::kill()`). PHP's VM does not expose a
per-opcode cancellation check.

The workaround is cooperative cancellation: workers periodically
check an `Atomic::bool` flag. This is the same posture as Java
and C++ -- neither offers cross-thread preemption either -- but
it means ill-behaved third-party code cannot be interrupted.

### 2.2 Task migration between threads

A goroutine-style scheduler that moves a parked task to a
different carrier thread on resume requires VM support: the
fiber's execution context (`execute_data`, VM registers, TSRM
bindings) must be detachable from one thread and re-attached to
another.

Current PHP fibers are tied to the thread they were created on.
Userspace cannot unmount them. Consequently:

- Work-stealing is limited to stealing **unstarted** tasks
  (dispatching them to a different worker at submission time).
- A slow task cannot be migrated off a busy carrier onto an idle
  one mid-execution.
- Goroutine-level granularity (millions of µs-scale tasks) is
  not achievable; we stay at thread-level granularity
  (thousands of ms-scale tasks).

### 2.3 Universal `select` across runtime primitives

Go's `select` statement covers channels, timers, and I/O in one
syntactic form because the Go runtime owns the scheduler and the
network poller jointly. PHP has no such runtime. Even with our
`fd`-backed channels, the unified-wait surface is the event loop
(Revolt / AMP), not a language construct.

### 2.4 Atomic refcount on shared PHP values

`zend_refcounted_h::refcount` is a `uint32_t` with no atomicity.
Sharing a PHP value with PHP-level refcounting across threads is
not safe. This is why the Phase 1 ownership model avoids
refcount bumps entirely (handoff, not sharing), and why the
later broadcast phase needs FFI-wrapped atomic ops on that
field.

A VM change that made refcount atomic (or distinguished "shared"
from "local" zvals) would remove the need for external atomic
wrappers. Absent that, users of the broadcast path must stay
within our API boundaries -- direct `$shared->release()` calls
from PHP land break the atomicity.

### 2.5 Shared mutable state across interpreters

Objects, arrays with non-trivial contents, resources, and
closures carry per-VM state (class_entry pointers, per-request
hash keys, etc.). They cannot be shared between isolates at all,
regardless of refcounting. The design treats this as a hard
limit and offers only immutable-graph sharing (Phase 5) as the
closest practical approximation.

## 3. What future VM support would unlock

This section is descriptive, not prescriptive. We are not
proposing any specific core change; we are noting what shape of
change would expand which capability, so contributors can
evaluate adjacent RFC activity with that context.

### 3.1 Fiber unmount / remount

The single smallest VM change with the largest scheduler impact
would be a primitive that lets a suspended fiber be detached
from its current thread's TSRM binding and re-attached to a
different thread on resume. With that, a userspace work-stealing
scheduler could migrate fibers across carrier threads, reaching
the "Level 2 M:N" described in our internal design notes.

What this would require, roughly:

- A C API for `zend_fiber_unmount` / `zend_fiber_mount`.
- Confirmation that JIT'd code (opcache optimiser output) is
  carrier-independent (it should already be; worth verifying
  under the unmount/remount scenario).
- A scheduler hook so the VM asks userland "what to do with this
  fiber now" on suspend.

### 3.2 Standard reactor / netpoller

A core reactor API (libuv-based or otherwise) would let channel
waits and I/O waits integrate without the `eventfd` adapter
layer. Our `AsyncChannel` implementation could simply register
its fd with the core reactor rather than forcing users through
Revolt / AMP.

### 3.3 Atomic refcount for selected zvals

If the Zend engine could be told "this zval is shared across
threads, use atomic refcount operations on it", broadcast
sharing would stop needing external atomic wrappers. This is a
larger change than unmount/remount because it touches the VM's
hottest path.

### 3.4 Per-interpreter memory policy

Sub-interpreter-style isolation with explicit cross-interpreter
sharing (like Python's shareable types) would let the VM
participate in our safety model rather than forcing userspace
to enforce it externally.

## 4. Posture

- We build the best userspace version of each capability we can
  reach, and document what is out of reach.
- We keep an eye on adjacent in-flight RFC activity that might
  lower any of the barriers in section 3, but we do not depend
  on specific RFCs. Our API surface is chosen so that future
  core additions can slot in underneath without breaking user
  code.
- When a concrete VM change lands that we can use, we add an
  adapter (e.g. `LibUvAsyncChannel`) alongside the existing
  `eventfd` implementation and let users opt in. The user-facing
  interface stays stable across that transition.
- If we ever propose a VM change ourselves, the prerequisite is
  that it must be motivated by measured pain in this userspace
  implementation, not by theoretical nicety.

This posture is intentionally modest. The ecosystem already has
several threads of design thinking about concurrency in PHP; our
contribution is a practical data-sharing and pool layer, not a
parallel proposal for language-level async.
