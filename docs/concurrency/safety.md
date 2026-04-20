# Safety model

Status: **Draft** -- details the static + runtime enforcement
strategy for the `Payload` handle described in
[payload.md](payload.md).

## 1. Why safety matters more than usual here

Concurrency bugs in the cross-interpreter space are qualitatively
worse than ordinary PHP bugs:

- Use-after-free reads return plausible-looking garbage rather than
  a clear exception. Dumps produce misleading data.
- Double-free corrupts the libc allocator's free list; the crash
  can surface arbitrarily later, in unrelated code.
- Data races are not reproducible. They often survive staging and
  only fire under the traffic pattern the author had not anticipated.
- Stack traces cross FFI / thread boundaries and lose fidelity.

PHP's cultural default is to ship and iterate on runtime failures.
That default is a poor fit for this subsystem. The design
prioritises static guarantees wherever the PHP ecosystem's static
tooling can actually provide one, and keeps runtime checks as a
loud secondary line.

## 2. Layered enforcement

### 2.1 Layer 1 -- static type state (PHPStan / Psalm)

A `Payload` carries a phantom type parameter for its lifecycle
state. The parameter is **invariant**: `Payload<Fresh>` and
`Payload<Drained>` are distinct types and neither is assignable
to the other.

```php
/**
 * @template TState of Fresh|Drained
 */
final class Payload
{
    /**
     * Drains the payload and returns the raw pointer. Intended
     * for Channel implementations, not user code. @phpstan-self-out
     * retypes $this to Payload<Drained> so further methods fail the
     * type check.
     *
     * @phpstan-self-out self<Drained>
     */
    public function take(): int { ... }
}

/** @return Payload<Fresh> */
public function alloc(int $size): Payload { ... }
```

The handoff boundary is where `<Fresh>` is statically required.
For example `Channel::send` is declared:

```php
/**
 * @param        Payload<Fresh>   $payload
 * @param-out    Payload<Drained> $payload
 */
public function send(Payload $payload): void;
```

Passing a `Payload<Drained>` to `send()` fails PHPStan. After
the call, the caller's variable is retyped to `Payload<Drained>`,
so any subsequent read / write method on it also fails the type
check. Users get a red squiggle in their IDE before the code
ever runs.

### 2.2 Layer 2 -- custom PHPStan rule against aliasing

`@param-out` is flow-sensitive on the caller's variable but does
not follow values into arrays, properties, or closure captures.
The author could still write:

```php
$arr[] = $payload;          // aliased, not caught by @param-out
$this->field = $payload;    // same
fn () => $payload;           // captured, outlives intent
```

A custom PHPStan rule flags these patterns on `Payload` values:

| Pattern | Status |
| --- | --- |
| `$local = $p;` | allowed (local aliasing within one scope) |
| `fn_that_accepts_payload($p)` | allowed if parameter declares `@param-out` |
| `$arr[] = $p;` | **error** |
| `$arr['k'] = $p;` | **error** |
| `$obj->field = $p;` | **error** |
| `static::$field = $p;` | **error** |
| `fn () use ($p) => ...` | **error** |
| `fn () use (&$p) => ...` | **error** |
| `Reflection...->setValue($p)` | not analysed; see \u00a76 |

The rule ships in this repository and is wired up via
`phpstan-extension.neon`, so Composer autoloading picks it up when
a user requires `sj-i/ffi-zts`.

### 2.3 Layer 3 -- runtime consumed-state check

Ideally Layer 3 would read the Payload's zval refcount at handoff
to catch lingering aliases that slipped past static analysis. PHP's
userland does not expose that: there is no standard
`debug_zval_refcount()` function, and `debug_zval_dump()` only
writes to stdout. We therefore **do not attempt runtime aliasing
detection** in Phase 1.

What Layer 3 does guarantee is the **double-consume** case:

```php
public function take(): int
{
    if ($this->ptr === null) {
        throw new ConsumedPayloadException(
            'Payload was already sent or released.'
        );
    }
    $ptr = $this->ptr;
    $this->ptr = null;
    return $ptr;
}
```

The first `send()` (or any consume path) nulls the internal
pointer; a second consume on any aliased reference raises
cleanly instead of double-freeing the persistent zend_string.

This means plain aliasing that never triggers a second consume
goes undetected at runtime. The enforcement story is therefore:

- Layer 1 types catch use-after-send on the original variable.
- Layer 2's PHPStan rule catches common aliasing patterns.
- Layer 3 catches double-consume even through aliased references.
- The gap between them (an alias that slips past Layer 2 and is
  never consumed a second time, so Layer 3 does not fire) is
  acknowledged in \u00a76 as out of scope for Phase 1.

A future phase may add refcount-based detection via an FFI helper
that reads the zval header directly -- that moves the safety
story from "static-first" to "static + FFI helper" and is
deferred until measured need.

### 2.4 Layer 4 -- destructor cleanup for leaks

If a `Payload<Fresh>` goes out of scope without being sent
(exception during preparation, early return, channel closed before
send), its destructor asks its owning Arena to release the
persistent zend_string:

```php
public function __destruct()
{
    if ($this->ptr !== null) {
        $this->arena->release($this->ptr);
    }
}
```

This mirrors `Arc::drop` semantics: a handle that leaves scope
without an explicit hand-off cleans up after itself. It turns what
would otherwise be a subtle leak into a no-op.

## 3. Final-class and no-clone discipline

`Payload` is `final` and its `__clone` is private and throws.
Aliasing via `$b = clone $p` is rejected at the language level.
`__serialize` / `__unserialize` are likewise suppressed -- a
serialised Payload would carry a stale pointer and is never a
correct thing to do.

The Arena itself is not `final` (subclassing is reasonable for
testing), but `Payload` is held final to simplify the static rule
(no need to handle subtype-specific method tables).

## 4. Error taxonomy

All runtime safety failures throw `LogicException` subclasses with
actionable messages:

| Exception | Thrown when |
| --- | --- |
| `ConsumedPayloadException` | Operation attempted on a Payload whose `ptr` is null (already sent or released; may be reached via an aliased reference that bypassed Layer 2). |
| `ArenaClosedException` | Allocation requested from an arena that has been released. |
| `CvNotFoundException` | `recvInto('name')` called where the named CV does not exist in the caller's op_array, or CV injection invoked from a non-synchronous context whose `prev_execute_data` chain points elsewhere. |

These extend `LogicException` rather than `RuntimeException`
because all of them indicate programmer error rather than
environmental failure.

## 5. Testing the safety model

Each enforcement layer has a dedicated test fixture:

- **Static** -- `tests/static/` contains PHP files that are
  expected to produce specific PHPStan errors. CI runs
  `phpstan analyse --error-format=json` and asserts the set of
  errors matches a snapshot. Regressions (errors that stop
  firing) are caught the same way as new violations.
- **Runtime** -- `tests/runtime/` exercises the reflection /
  variable-variable escape hatches explicitly and asserts the
  correct exception type where we do detect, and documents (as
  expected behaviour) the cases we do not detect.
- **Destructor cleanup** -- `tests/arena/` allocates without
  sending and verifies (via `/proc/self/statm` or equivalent)
  that memory is released on scope exit.

## 6. What the safety model does not catch

- **Reflection-driven aliasing.** PHP userland does not expose
  zval refcount, so aliasing through reflection,
  variable-variables, or third-party extensions that clone zvals
  goes undetected at runtime (see \u00a72.3). Layer 2's PHPStan rule
  catches the common syntactic aliasing patterns; beyond that,
  the threat model assumes trusted application code. A runtime
  detector via FFI helper is possible but deferred.
- **FFI-level pointer theft.** Anyone with FFI access can read
  the raw `ptr` field off a Payload via FFI reflection. There is
  no way to hide a pointer from FFI, and trying to would be
  theatre. The threat model assumes trusted application code.
- **Concurrent mutation of the backing zend_string.** Nothing
  prevents one side from writing into `ZSTR_VAL()` while the
  other reads. Phase 1's ownership-handoff model precludes this
  by discipline; later phases that share memory concurrently
  need explicit atomic primitives (covered in a future
  `atomics.md`).

These are acknowledged gaps, not bugs. The safety model aims to
make the normal path trivially correct, not to sandbox adversarial
code.

## 7. Relationship to language-level concurrency safety

Rust's `Send` / `Sync`, Swift's `Sendable`, Ruby Ractor's
shareability check, and similar runtime- or compiler-level
guarantees are stronger than anything PHP's tooling can provide
today. The difference is one of enforcement venue, not of intent:
our rules do the same checking, just via the ecosystem's static
analysers rather than the core compiler or VM.

This is a practical compromise, not an ideal. If PHP grows native
support for equivalent contracts in the future, our user-visible
API can be mapped onto it without semantic changes -- the Payload
state machine and aliasing prohibition translate directly into
`Send`-shaped constraints.
