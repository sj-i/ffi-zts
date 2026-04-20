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
state:

```php
/**
 * @template-covariant TState of Fresh|Drained
 */
final class Payload
{
    /**
     * @param-out Payload<Drained> $this
     */
    public function send(Channel $ch): void { ... }

    /**
     * Only callable while fresh.
     *
     * @phpstan-this-out self<Drained>
     */
    public function take(): int { ... }
}

/** @return Payload<Fresh> */
public function alloc(int $size): Payload { ... }
```

`Channel::send(Payload $p)` accepts only `Payload<Fresh>` and
rewrites the caller's variable to `Payload<Drained>` via
`@param-out`. After `send()`, attempting to call any write / read
method on the variable fails under PHPStan (the methods do not
exist on `Payload<Drained>`).

Users get a red squiggle in their IDE before the code ever runs.

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
| `Reflection...->setValue($p)` | not analysed; relies on layer 3 |

The rule ships in this repository and is wired up via
`phpstan-extension.neon`, so Composer autoloading picks it up when
a user requires `sj-i/ffi-zts`.

### 2.3 Layer 3 -- runtime aliasing detection

Static rules cannot see reflection, variable-variables, string
`eval`, or third-party extensions that clone zvals. For those, the
final guard is a runtime refcount check at handoff:

```php
public function take(): int
{
    // +1 for the argument binding into debug_zval_refcount itself.
    if (\debug_zval_refcount($this) > 2) {
        throw new AliasedPayloadException(
            'Payload has additional references and cannot '
            . 'transfer ownership. Hold it only in a local '
            . 'variable between alloc and send.'
        );
    }
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

The check is O(1), executes at send-time only, and fails loudly
with a message that points the user toward the supported pattern.

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
| `ConsumedPayloadException` | Operation attempted on a Payload whose `ptr` is null (already sent or released). |
| `AliasedPayloadException` | Refcount > 2 at handoff. |
| `ArenaClosedException` | Allocation requested from an arena that has been released. |
| `CvNotFoundException` | `recvInto('name')` called where the named CV does not exist in the caller's op_array. |

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
  correct exception type.
- **Destructor cleanup** -- `tests/arena/` allocates without
  sending and verifies (via `/proc/self/statm` or equivalent)
  that memory is released on scope exit.

## 6. What the safety model does not catch

- **Reflection-driven refcount manipulation.** A sufficiently
  determined caller can bump a Payload's refcount via low-level
  reflection or by passing through obscure extensions. The
  runtime check catches common cases but is not a sandbox.
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

Rust's `Send` / `Sync`, Swift's `Sendable`, and similar compiler-
level guarantees are stronger than anything PHP's tooling can
provide today. The difference is one of enforcement venue, not of
intent: our rules do the same checking, just via the ecosystem's
static analysers rather than the core compiler.

This is a practical compromise, not an ideal. If PHP grows native
support for equivalent contracts in the future, our user-visible
API can be mapped onto it without semantic changes -- the Payload
state machine and aliasing prohibition translate directly into
`Send`-shaped constraints.
