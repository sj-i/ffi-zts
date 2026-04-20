# Payload mechanism

Status: **Draft** -- details the zero-copy byte-buffer primitive
introduced in [CONCURRENCY.md](../CONCURRENCY.md) Phase 1.

## 1. Why `zend_string` specifically

PHP has exactly one built-in value type that is "a header plus a
byte array": `zend_string`. The header carries refcount, hash, flags
and length; `val[]` is a trailing flexible array with the data
inline. Every PHP `string` is a `zend_string`, and every PHP
function that consumes a string goes through a uniform
`ZSTR_VAL() / ZSTR_LEN()` accessor.

That layout makes `zend_string` uniquely well-suited for crossing
runtime boundaries without a copy:

- **One memory region for header and data.** A single `malloc()`
  (or `pemalloc()`) yields both.
- **Every string API takes it.** `echo`, `fwrite`, `hash`, `substr`,
  `preg_match`, `sprintf`, array keys, etc. accept a `zend_string`
  with no conversion.
- **The content pointer is exposed at the FFI boundary.** When PHP
  passes a string to an FFI function declared `const char *`,
  `ZSTR_VAL(str)` is handed to C directly -- no copy, no temporary.

Other PHP types do not share this property. Arrays (`HashTable`)
have an indirect bucket layout and per-element zvals; objects have
per-VM `zend_class_entry` pointers; resources are interpreter-local.
A string is the only shape that is just bytes-with-a-header.

## 2. Persistent allocation sidesteps the allocator boundary

Each PHP interpreter instance (NTS host, ZTS embed, and each
`parallel\Runtime` worker) has its own ZendMM arena. A zend_string
allocated via `zend_string_alloc(len, 0)` (the normal path) lives in
the allocating interpreter's per-request pool; another interpreter
can read the bytes but must never call `zend_string_release` on it,
because the `efree` path would target the wrong arena.

`zend_string_alloc(len, 1)` (persistent=1) allocates through
`pemalloc`, which is plain `malloc()`. Persistent strings:

- do not belong to any ZendMM arena;
- can be freed from any interpreter via `zend_string_release_ex`
  (which routes to `pefree` because the `IS_STR_PERSISTENT` flag is
  set);
- are immune to per-request shutdown in any single interpreter.

All cross-interpreter sharing in this design uses persistent
allocation, regardless of which side does the allocating. The
producer does not need to know whether the consumer is NTS, ZTS,
or another worker thread.

## 3. Ownership model

The default model for Phase 1 is **single-producer, single-consumer,
ownership handoff**:

1. Producer calls `Arena::fromString($bytes)` or
   `Arena::alloc($size)->writeFromString($off, $bytes)`.
   This allocates a persistent zend_string, refcount = 1.
2. Producer sends the pointer across the channel. Sending is a
   logical ownership transfer; the producer no longer holds a
   live reference.
3. Consumer's channel receive injects the pointer into a CV
   (compiled-variable slot) in the receiving scope. Refcount stays
   at 1; the CV dtor will decrement it on scope exit.
4. When the CV dtor fires, `zend_string_release_ex` sees
   `IS_STR_PERSISTENT` and calls `pefree`.

No `GC_ADDREF` is performed on handoff; no `IS_STR_INTERNED` flag
is set (interning would suppress the dtor and force manual free).
The zend_string behaves like a normal PHP string to the consumer,
with the only difference being that its backing memory is persistent
rather than request-scoped.

### 3.1 Broadcast (later)

For multi-consumer broadcast, refcount must be atomic, because the
vanilla Zend refcount field is a plain `uint32_t` with no synchronisation.
A later phase adds FFI helpers that use `__atomic_*` intrinsics on
that field, invoked only by the broadcast channel machinery.
Single-consumer paths continue to use the non-atomic fast path.

## 4. CV injection as the receive mechanism

The consumer-side receive step needs to hand the consumer a PHP
variable whose zval says "string, pointing at the shared
zend_string". Userland PHP has no way to obtain the address of a
function-local (compiled) variable to overwrite its zval, which is
why naive approaches route through `$GLOBALS` or a holder object.

The primitive we actually want is available from C, through FFI,
without polluting globals. `EG(current_execute_data)` points to the
stack frame of the FFI method itself; its `->prev_execute_data`
is the PHP caller's frame, which owns the CVs we want to write to:

```c
void ffi_inject_cv(const char *name, size_t nlen, zend_string *s) {
    zend_execute_data *ex  = EG(current_execute_data)->prev_execute_data;
    zend_op_array     *op  = &ex->func->op_array;

    for (uint32_t i = 0; i < op->last_var; i++) {
        if (ZSTR_LEN(op->vars[i]) == nlen
            && memcmp(ZSTR_VAL(op->vars[i]), name, nlen) == 0) {
            zval *cv = ZEND_CALL_VAR_NUM(ex, i);
            zval_ptr_dtor(cv);
            ZVAL_STR(cv, s);
            return;
        }
    }
}
```

From PHP:

```php
function consume(Channel $ch): void {
    $buf = null;                 // reserves the CV slot
    $ch->recvInto('buf');         // injects the shared zend_string
    echo $buf;                    // zero-copy access
}                                 // CV dtor -> pefree on scope exit
```

### 4.1 Properties

- **No global state.** `$GLOBALS` / symbol_table not touched.
- **Scope-local lifetime.** The CV dtor fires at function return,
  at unset, or at reassignment, exactly like any other local
  variable.
- **Cheap lookup.** `op_array->last_var` is the number of CVs in
  the caller's function, typically small. Linear search over
  interned name strings.
- **Works inside closures.** The op_array of a closure has its own
  CV table; `prev_execute_data` still points there.
- **Does not work from async tails.** If the C helper is called
  from a context where the direct PHP caller is not the function
  whose CV we want to write (e.g. a deferred callback invoked from
  a reactor), the `prev_execute_data` chain points elsewhere. For
  those cases we fall back to holder-object or static-property
  injection, documented separately.

### 4.2 Alternatives considered

| Target | Status | Why not default |
| --- | --- | --- |
| `EG(symbol_table)` (`$GLOBALS['x']`) | fallback | Pollutes global namespace; user must know the key. |
| Static property on a holder class | fallback | Usable from any scope but requires a dedicated class and slot discipline. |
| Object property table | fallback | Requires caller to keep a holder instance alive and pass its handle. |
| Function return value slot | rejected | FFI's return-type conversion overwrites whatever the C helper stages. |
| Construct a PHP zval in FFI CData | rejected | CData is not a PHP variable; the VM does not see it as one. |

The fallback targets will be exposed as alternative `Injector`
implementations for the deferred / async cases. The CV route is the
fast path for synchronous receive.

## 5. Pre-allocated buffer pattern

For C code that needs to fill a buffer that will be handed to PHP,
the inverse of "C allocates, PHP receives" is often more ergonomic:

```php
$buf = str_repeat("\0", $len);   // zend_string, refcount=1
$ffi->fill_from($source, $buf, $len);  // C writes into ZSTR_VAL($buf)
echo $buf;                         // no subsequent copy
```

PHP's `fread`, `stream_get_contents`, and `hash_update_stream`
internally use this pattern. Our `Payload` API exposes it explicitly:

```php
$p = $arena->alloc($len);         // Payload<Fresh>
$p->writeFromString($off, $src);  // in-place write, Fresh stays Fresh
$channel->send($p);               // Payload<Drained> after return
```

The pattern does not work for cases where the producer is another
thread or interpreter -- there is no way for NTS PHP to hand a
pre-allocated PHP string down to a worker thread's C code and have
the worker write into it without also transferring the pointer.
When the buffer has to travel, we fall back to the persistent
alloc / handoff flow in section 3.

## 6. Arena

Individual persistent zend_strings are inconvenient to free: the
consumer's CV dtor frees the ones it receives, but producer-side
failures (channel full, worker died, task cancelled before send)
leave dangling allocations. An `Arena` is a producer-side registry
that tracks every outstanding persistent zend_string it hands out:

```php
$arena = $host->arena();           // tied to some lifecycle scope
$p1    = $arena->fromString($big);
$p2    = $arena->alloc($size);

// ... during work ...

$arena->release();                  // frees every outstanding
                                    // persistent zstr that was
                                    // not successfully sent
```

Successfully-sent `Payload<Drained>` values are removed from the
arena's tracking table on `send()` completion -- they are now owned
by the consumer. The arena's final `release()` only frees what the
producer still holds (i.e. leaked or never-sent payloads).

Arena lifetime is the user's choice:

- **Per-task arena** -- created at the start of one `parallel`
  task, released at task completion. Bounds leak to one task.
- **Per-request arena** -- one arena for the whole host request
  lifecycle, released during shutdown. Simpler, more tolerant to
  logic errors, but buffer footprint grows until shutdown.
- **Nested arenas** -- one parent arena, child scopes that roll up.
  Not planned for Phase 1 but not precluded by the API.

## 7. What Phase 1 implements

- `Arena` with `alloc`, `fromString`, and `release`.
- `Payload<Fresh>` with `writeFromString`, `size`, and the
  `send()` / `recvInto()` integration points.
- `CvInjector` implemented in C, loaded via FFI cdef from
  libphp.so symbols.
- A single in-process round-trip test: NTS host allocates, embedded
  ZTS reads back, byte hash matches.

Cross-thread channel plumbing, atomic refcount, broadcast, async
fd-backed channels, and structured concurrency all build on this
Phase 1 primitive and are covered in their own documents.
