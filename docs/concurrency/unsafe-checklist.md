# Unsafe checklist

Status: **Draft** -- boundary conditions implementers must
respect. Written for contributors writing the C helpers, FFI
cdefs, and Arena / Payload bookkeeping. Users of the public API
should never have to read this page.

Think of it as the list of things that, if ignored, produce
silently-wrong behaviour: use-after-free, double-free, torn
reads, leaked persistent allocations, or the interpreter
tripping on mismatched allocator state.

## 1. Allocation

### 1.1 Always persistent; never ZendMM

All cross-interpreter sharing uses `zend_string_alloc(len, 1)`
(persistent=1) or equivalent `pemalloc` paths. **Never mix**
ZendMM-allocated zend_strings into the shared path -- the
`efree` that eventually runs on them targets the wrong arena
and corrupts the allocator.

Specifically:

- `zend_string_init(..., 0)` is forbidden in our codebase.
- Receiving user strings and forwarding them persistently
  means copying: `zend_string_alloc(len, 1)` + `memcpy`.
- `smart_str` buffers default to non-persistent. Use the
  persistent variants or allocate the final zend_string
  separately.

### 1.2 `IS_STR_PERSISTENT` must be set

`zend_string_release_ex` reads the flag and routes to `pefree`
or `efree` accordingly. A persistent zend_string that was not
marked `IS_STR_PERSISTENT` will be passed to `efree`, which
looks it up in the ZendMM free lists, does not find it, and
either crashes or corrupts state.

When constructing a persistent zend_string by hand (rare,
usually indirectly through `zend_string_alloc`), verify the
flag is set before returning the pointer to PHP land.

### 1.3 `h` field hazard

`zend_string::h` is the precomputed hash. It is populated
lazily: the first code path that needs a hash (array key
lookup, some string interning paths) calls
`zend_string_hash_val`, which writes to `h` and sets a flag.

This means **two readers in different threads can race on `h`**
even when both are only "reading" the string. The race is
usually benign (the same hash value is recomputed by both and
both writes store the same bits), but strict under TSan it is
still a data race.

Mitigation:

- Where we control the producer, call `zend_string_hash_val`
  on the zend_string before handing the pointer across a
  thread boundary. The flag is then set; readers do not
  race-write.
- Where we do not control the producer (e.g. receiving a
  user-provided string and persisting it), compute the hash on
  the persistent copy before broadcasting.

This is a Phase 2 / 3 concern (Phase 1 is single-consumer) but
documenting it now so broadcast does not reintroduce the
problem.

### 1.4 Refcount is non-atomic

`zend_refcounted_h::refcount` is a plain `uint32_t`.

- Phase 1 single-consumer handoff: refcount stays at 1;
  producer does not touch it after send; consumer's CV dtor
  decrements. No sharing, no race.
- Phase 1 broadcast: **forbidden.** Do not call `GC_ADDREF` /
  `GC_DELREF` on a shared zend_string from multiple threads
  without atomic helpers.
- Broadcast phase: FFI helpers using `__atomic_*` intrinsics
  on the refcount field are the only safe path.

## 2. CV injection

### 2.1 Synchronous caller only

The injection helper walks
`EG(current_execute_data)->prev_execute_data` to reach the
caller's op_array. This works when the call stack looks like:

```
[FFI helper frame -- our C code]
[PHP caller frame -- the user function that called $ch->recvInto]
```

It does **not** work when there are intermediate frames
(deferred callbacks, internal-function-driven callbacks,
signal handlers, destructors during GC). In those cases,
`prev_execute_data` points somewhere we do not want to write.

**Implementer rule:** the C helper must detect when its
`prev_execute_data->func` is not a user op_array (or is an
op_array but with a fabricated CV table that does not match
the caller's expectations) and raise rather than write. The
caller on the PHP side then wraps this as
`CvNotFoundException`.

### 2.2 `op_array->last_var` vs `num_args`

CVs are `op_array->vars[0..last_var-1]`. The first `num_args`
of these are parameters; the rest are additional local
variables. Our lookup uses `last_var` (all CVs), not
`num_args` (parameters only).

### 2.3 `ZEND_CALL_VAR_NUM` is the right macro

Use `ZEND_CALL_VAR_NUM(execute_data, index)` to compute the
zval pointer for CV slot `index`. Do not hand-compute the
offset; the stack frame layout is not stable across PHP
versions.

### 2.4 Write order: dtor the old zval, then set the new

```c
zval *cv = ZEND_CALL_VAR_NUM(ex, i);
zval_ptr_dtor(cv);     // release whatever was there (e.g. NULL from $buf = null)
ZVAL_STR(cv, s);        // set our zend_string*
```

`zval_ptr_dtor` is a no-op for `IS_NULL`, so the common case
(`$buf = null;` as the slot placeholder) is cheap. For cases
where the user already stored something refcounted in the
slot, the dtor releases it properly before we overwrite.

**Do not** skip the dtor to save a cycle. Leaked refs are
silent.

### 2.5 Refcount bump on inject (or not)

The Phase 1 handoff does **not** bump the refcount when
injecting: ownership is transferred from the producer's arena
tracking to the consumer's CV slot. The refcount stays at 1
throughout.

If a future path wants to share (keep a reference in the
producer's arena and also inject into the consumer), that path
must bump the refcount before injecting, and must account for
the shared state via the atomic helpers in \u00a71.4.

## 3. Arena lifecycle

### 3.1 Release order

```
pool.shutdown()          // drain pending tasks, join workers
-> arena.release()       // free never-sent persistent zstrs
   -> embed.shutdown()   // shut down the embedded ZTS interpreter
```

Releasing the arena before the pool has drained risks
freeing pointers that a still-running task was about to read.

### 3.2 Release is producer-local

`$arena->release()` frees only the persistent zend_strings
this arena is still tracking -- i.e. those that were allocated
but never successfully sent. Sent payloads are the consumer's
responsibility (CV dtor).

Implementer trap: do not iterate the arena's tracking table
and call `zend_string_release_ex` on every entry regardless.
The Payload's `send()` path is what removes an entry from the
tracking table; anything still in the table at release time is
genuinely leaked from the producer's perspective.

### 3.3 Release is idempotent

A second `release()` call is a no-op. The tracking table is
cleared on first call; subsequent calls find nothing to do.

### 3.4 Re-use after release

Once released, an arena cannot be reused -- further
`alloc()` / `fromString()` raise `ArenaClosedException`. The
Arena does not support "reopen". Callers who want a fresh
arena create a new one.

## 4. FFI cdef lifetimes

### 4.1 Keep the FFI instance alive

FFI CData objects derive from an `FFI` instance. When that
instance is garbage-collected, the CData becomes invalid --
its backing symbols are unloaded.

**Implementer rule:** every class that owns CData (Arena,
CvInjector, Channel, Pool) must hold a property referencing
the FFI instance it depends on. Do not rely on a singleton or
a global; the FFI instance's lifecycle is the CData's
lifecycle.

### 4.2 CData owning vs non-owning

`FFI::new('...', owned: true)` allocates and takes ownership;
the memory is freed with the CData's dtor. `owned: false`
means the CData is a view onto memory managed elsewhere;
freeing it does not free the memory.

For persistent zend_strings: we never let FFI own them. The
Arena is the owner; any CData we construct to view them is
`owned: false`.

### 4.3 Pointer validity across boundaries

An FFI `intptr_t` that crosses a thread boundary stays
numerically intact -- `parallel` passes integers verbatim.
But its validity depends on what it points to:

- Persistent zend_string: valid until any thread calls
  `pefree` on it.
- ZendMM allocation: valid only in the allocating
  interpreter's request lifetime. Never cross a thread
  boundary with one.
- Stack memory: never valid across threads.
- `FFI::new(..., owned: true)` memory: valid until the owning
  CData is collected; do not rely on that timing across
  threads.

## 5. Shutdown sequencing

### 5.1 Embed shutdown is the last step

The existing `DESIGN.md` covers the loader shutdown ordering
(`php_module_shutdown`, `sapi_shutdown`, `tsrm_shutdown`). The
concurrency layer adds:

```
arenas released
-> pools shut down
-> channels closed
-> embed shutdown runs (existing path)
```

An arena that is still alive at embed shutdown is a leak. A
pool that is still running at embed shutdown is a crash --
its workers will try to access torn-down state.

### 5.2 Signal delivery during shutdown

If SIGTERM arrives during shutdown, the default is to complete
the shutdown in progress. Signal handlers should set a flag,
not call into the concurrency API directly. CV injection from
a signal handler is explicitly not supported (see \u00a72.1).

## 6. Thread-local gotchas

### 6.1 TSRM bindings

ZTS state is thread-local. Our C helpers that read
`EG(current_execute_data)` must be executing on the thread
whose EG they want -- never dispatched via `uv_queue_work` or
similar to a pool thread.

The FFI calls from PHP are guaranteed to execute on the
calling PHP thread; this matches what we need.

### 6.2 Opcache JIT

JIT'd code is not re-entrant across sudden TSRM swaps, but
since we do not migrate PHP execution between threads (each
worker stays on its runtime's thread for its lifetime), this
does not affect us. If a future phase adds fiber migration
(see core `limits.md` \u00a73.1), this becomes a real concern
that would need VM cooperation.

## 7. Interaction with user-land

### 7.1 No `serialize()` / `__sleep` / `__wakeup` on Payload

A Payload carries a raw pointer in a private property. Any
serialization mechanism that round-trips it through bytes will
preserve the integer but not the validity. A deserialised
Payload whose original was freed is a live hazard.

Our Payload class explicitly throws from `__serialize`,
`__sleep`, and `__set_state`. `__clone` is private and throws
(documented in `safety.md` \u00a73).

### 7.2 No reflection-driven property writes

Setting `ptr` through reflection from user code would allow
creating a Payload pointing at arbitrary memory. We do not
prevent this -- we accept the threat model of trusted
application code (`safety.md` \u00a76).

### 7.3 FFI passthrough

Users who want to share FFI CData (not Payload) across threads
use the raw-pointer pattern documented in the existing
`DESIGN.md` \u00a78. Our Payload / Arena / CvInjector layer is
for zend_string-backed sharing; FFI CData has its own
lifecycle rules and is not layered on Payload.

## 8. Quick review checklist

Before merging any PR that touches the concurrency layer:

- [ ] Every `pemalloc` / `zend_string_alloc(..., 1)` has a
      matching `pefree` / `zend_string_release_ex` reachable
      from all normal and exceptional paths.
- [ ] No `zend_string_init` or `zend_string_alloc` with
      persistent=0 in the shared path.
- [ ] `IS_STR_PERSISTENT` is set on all persistent
      zend_strings before they leave the allocator.
- [ ] CV injection helper checks the `prev_execute_data`
      frame's function type before writing.
- [ ] `zval_ptr_dtor` is called on the target CV before
      overwriting with `ZVAL_STR`.
- [ ] Every class owning FFI CData holds the FFI instance as
      a property.
- [ ] Arena release order: pools drained, then arena
      released, then embed shutdown.
- [ ] Payload overrides all serialisation hooks to throw.
- [ ] No cross-thread refcount mutation without atomic helpers.
