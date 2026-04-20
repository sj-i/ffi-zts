# Public API surface

Status: **Draft** -- user-visible classes and methods for the
concurrency layer. Section 2 (Phase 1) is in scope for detailed
design. Sections 4 and 5 are roadmap sketches for later phases,
subject to change.

## 1. Namespace discipline

Everything lives under `SjI\FfiZts\Concurrent\*` (in the core
package) and `SjI\FfiZts\Parallel\Concurrent\*` (in the satellite).
The project-local namespace avoids collisions with any hypothetical
future global `Concurrent\*` additions to PHP itself, while keeping
names short enough to use comfortably.

The names chosen for the core types are intended to be compatible
with broader patterns (Future, Channel, Task, Scheduler) without
taking the most generic identifiers. Specifically:

| Avoided name | Why | Our equivalent |
| --- | --- | --- |
| Global `spawn()` | Likely to be taken by future language-level work | `Pool::submit()`, `Scheduler::run()` |
| Global `await()` | Same | `Future::get()`, `Future::join()` |
| Global `suspend()` | Already used by Fiber | (we do not introduce a global) |
| `Coroutine` class | Potentially in-flight adjacent work | `Task` (unit of work for a thread pool) |

The rule of thumb is: we ship methods on our own classes rather
than global functions, so users can disambiguate by class and the
risk of future collision is limited to interface / method-signature
overlap (which is low-cost to refactor around).

## 2. Phase 1 API (core package)

### 2.1 Entry point

```php
use SjI\FfiZts\FfiZts;
use SjI\FfiZts\Concurrent\Arena;

$host  = FfiZts::boot();
$arena = $host->arena();         // lifetime tied to the host
```

`FfiZts::boot()` is the existing entry point; `arena()` is new.

### 2.2 Arena

```php
interface Arena
{
    /** @return Payload<Fresh> */
    public function alloc(int $size): Payload;

    /** @return Payload<Fresh> */
    public function fromString(string $bytes): Payload;

    /**
     * Release every outstanding persistent zend_string that
     * this arena knows about and is still holding. Already-sent
     * payloads are untouched -- they are owned by their consumer
     * and will be freed by the consumer's CV dtor.
     */
    public function release(): void;
}
```

### 2.3 Payload

The phantom type parameter is **invariant**. `Payload<Fresh>` and
`Payload<Drained>` are distinct types; neither is assignable to
the other. State transitions happen at handoff points (notably
`Channel::send`, `Host::runScript` with `bindings`, and
`Payload::take` on internal channel implementations), where the
caller's variable is explicitly retyped from `<Fresh>` to
`<Drained>` via `@param-out` / `@phpstan-self-out`.

```php
/**
 * @template TState of Fresh|Drained
 */
final class Payload
{
    public function size(): int;

    /**
     * Write bytes into the payload buffer.
     *
     * Precondition: this must be a Payload<Fresh>. The static
     * guarantee against writing to a drained payload is delivered
     * at the handoff boundary (Channel::send, see below) by
     * retyping the variable to Payload<Drained>, which lacks
     * this method's signature at the static level. Direct calls
     * on a drained payload still fail at runtime via the
     * consumed-state check (see safety.md \u00a72.3).
     */
    public function writeFromString(int $offset, string $bytes): void;

    /**
     * Internal API -- drains the payload and returns the raw
     * pointer. Intended for Channel / bindings implementations,
     * not user code.
     *
     * @phpstan-self-out self<Drained>
     */
    public function take(): int;
}

/** Phantom marker types. */
final class Fresh {}
final class Drained {}
```

The handoff boundary where the static precondition is enforced:

```php
interface Channel
{
    /**
     * @param        Payload<Fresh>   $payload
     * @param-out    Payload<Drained> $payload
     */
    public function send(Payload $payload): void;
}
```

Passing a `Payload<Drained>` to `send()` is a PHPStan error.
After the call, the caller's variable is retyped to
`Payload<Drained>`, and any subsequent call on it that requires
`Fresh` is also a PHPStan error.

Users almost never reference `Fresh` or `Drained` directly --
they only appear in `Payload<...>` annotations on parameters and
returns.

### 2.4 Script execution with bindings

```php
$host->runScript('worker.php', bindings: [
    'buf' => $arena->fromString($bigData),
]);
```

`runScript` already exists; the new `bindings` parameter takes a
`array<string, Payload<Fresh>>` and injects each entry into the
matching CV in the embedded script's top-level op_array before
execution. The caller's `Payload<Fresh>` variables become
`Payload<Drained>` on return -- handoff semantics identical to
`Channel::send()`.

### 2.5 CvInjector

**Applicability.** CV injection works when the receiving PHP
frame is the direct synchronous caller of the injection helper.
The implementation walks
`EG(current_execute_data)->prev_execute_data` to reach the
caller's op_array, so call paths that interpose an additional
frame -- deferred callbacks fired from an event loop, internal
functions that call back into PHP, destructors triggered during
GC, signal handlers -- land in the wrong frame and fail with
`CvNotFoundException`.

For synchronous receive (the common case after `Channel::recv`
where the caller is a user function), CV injection is the fast
path. For asynchronous / deferred receive, the alternative
Injectors below must be used.

```php
interface CvInjector
{
    /**
     * Inject the payload's zend_string into the named CV of the
     * caller's PHP frame. Transfers ownership: the Payload is
     * drained on success.
     *
     * @param-out Payload<Drained> $payload
     *
     * @throws CvNotFoundException
     *         The named CV does not exist in the caller's op_array,
     *         or this method was invoked from a non-synchronous
     *         context whose prev_execute_data chain does not point
     *         at the intended receiving frame.
     */
    public function injectInto(string $cvName, Payload $payload): void;
}
```

Alternative Injector implementations (not bound to the caller's
frame):

| Injector | Target | Use when |
| --- | --- | --- |
| `CvInjector` (default) | `prev_execute_data->func->op_array` CV | Synchronous receive, caller-frame write |
| `StaticPropertyInjector` | `ClassName::$slot` | Deferred callback, event-loop tail, any cross-frame receive |
| `HolderObjectInjector` | `$holder->property` | Same as above, when caller already has a holder object |
| `GlobalInjector` | `$GLOBALS['key']` | Last resort; pollutes global namespace but always reachable |

Choose the Injector per-transport. The default `Channel::recvInto`
uses `CvInjector`; an `AsyncChannel` driven from an event loop
would default to one of the non-CV Injectors.

## 3. Phase 1 escape hatches

Not every payload wants to be accessed as a `string`. We document
two bypasses that need no additional machinery:

### 3.1 `pack` / `unpack` for small structured records

```php
$p = $arena->alloc(24);
$p->pack(0, 'Qd', $timestampNs, $valueF64);
$channel->send($p);

// receiver
/** @var string $buf */
['timestamp' => $t, 'value' => $v] = unpack('Qtimestamp/dvalue', $buf);
```

### 3.2 `FFI::cast` view for larger typed buffers

```php
$p = $arena->alloc(FFI::sizeof($eventType) * 1_000);
$view = $p->viewAs('struct event[1000]');   // FFI::cast, no copy
$view[0]->timestamp = $ns;

// receiver
/** @var string $buf */
$view = Payload::viewStringAs($buf, 'struct event[1000]');
echo $view[999]->value;
```

Both bypasses live on top of the `Payload` / `string` duality and
require no additions to the core API.

## 4. Roadmap sketches -- satellite package API (Phase 2+)

> **These are sketches, not contracts.** Names, shapes, and method
> sets here are expected to move as Phase 1 is implemented and
> measured. The Phase 1 surface in \u00a72 is the only part of the
> document readers should treat as design-frozen.

Detailed design will live in `sj-i/ffi-zts-parallel`.

### 4.1 Channel (sketch)

```php
use SjI\FfiZts\Parallel\Concurrent\Channel;

$ch = Channel::open('jobs');

/**
 * @param     Payload<Fresh>   $payload
 * @param-out Payload<Drained> $payload
 */
$ch->send(Payload $payload): void;

/**
 * Blocks until a message is available, then injects it as a
 * zend_string into the named CV in the caller's frame. Uses
 * CvInjector by default -- same synchronous-caller constraint
 * applies (see \u00a72.5).
 */
$ch->recvInto(string $cvName): void;
```

`AsyncChannel` is the same interface with an added `fd(): int` so
event loops can `poll` / `epoll` on it. Implementation detail is
sketched in `ecosystem.md` and a future `async-channel.md`.

### 4.2 Pool (sketch)

```php
use SjI\FfiZts\Parallel\Concurrent\Pool;

$pool = Pool::withWorkers(8);
$future = $pool->submit(
    fn () => analyse(),
    bindings: ['row' => $payload],
);
$result = $future->get();
```

### 4.3 Structured combinators (sketch)

```php
use SjI\FfiZts\Parallel\Concurrent\Structured;

$results = Structured::all([
    'a' => fn () => workA(),
    'b' => fn () => workB(),
]);

$winner = Structured::race([
    fn () => fromCache(),
    fn () => fromOrigin(),
]);

Structured::forEach($items, fn ($item) => process($item), concurrency: 8);
```

Cancellation and cooperative-cancel behaviour on these
combinators is covered in the limits doc (`limits.md` \u00a72.1) and
needs its own `ERROR_MODEL.md` companion before the shapes above
are committed.

### 4.4 Atomics (sketch)

```php
use SjI\FfiZts\Parallel\Concurrent\Atomic;

$counter = Atomic::int32(0);
$counter->add(1);
$n = $counter->load();

$cancelled = Atomic::bool(false);
$cancelled->store(true);
if ($cancelled->load()) { /* ... */ }
```

Naming follows the Node `Atomics` vocabulary (`add`, `sub`, `load`,
`store`, `compareExchange`, `wait`, `notify`) so users moving
between ecosystems carry the vocabulary.

## 5. Roadmap sketches -- type progression (Phases 4 / 5)

> **Also sketches.** Phase 4 (immutable arrays) and especially
> Phase 5 (immutable object graphs) are structurally harder than
> Phase 1 -- see `limits.md` \u00a72.5. The shapes below show the
> intended ergonomics, not a firm design.

The phantom-type scheme extends naturally to richer payloads:

```php
// Phase 4: immutable array
/** @var FrozenArray<Fresh> */
$arr = $arena->arrayBuilder()
    ->setInt('count', 42)
    ->setString('name', 'prod')
    ->freeze();
$channel->send($arr);

// Phase 5: @psalm-immutable object graph
/** @psalm-immutable */
final class UserRow { ... }

$shared = $arena->shareCollection($users, UserRow::class);
$channel->send($shared);
// worker receives array<UserRow> via CV injection; the per-VM
// object wrappers are thin, the persistent data is shared.
```

Both additions sit beside `Payload` in the same namespace; users
who do not need them never encounter them.

## 6. What users should reach for by default

For Phase 1 workflows:

| Situation | API |
| --- | --- |
| "I have a big string, send it to a worker." | `$arena->fromString()` + `$ch->send()` |
| "I need a buffer for C to fill, then read from PHP." | `$arena->alloc()` + FFI call + read as string |
| "I want to pass typed records." | `Payload::viewAs()` + struct definition |
| "I want the worker to return a big result." | Same flow, reversed: worker allocates, sends, caller `recvInto`. |

The Phase 2+ shapes above use the same primitives layered up.

## 7. What users should avoid

- Holding a `Payload` reference anywhere other than a local
  variable. The custom PHPStan rule enforces this.
- Sending the same `Payload` twice. Static types catch the second
  `send()` via `Payload<Drained>` lacking the required signature.
- Calling `FFI::string($cdata, $len)` when a Payload flow would
  serve the same purpose. It silently copies.
- Using `parallel\Channel::send($payload)` directly. The raw
  parallel channel will serialise. Always go through the satellite
  package's `Channel`, which understands Payload handoff.
- Calling `recvInto` from a context that is not the direct
  synchronous caller of the injection helper (see \u00a72.5 and
  `CvNotFoundException`). For deferred / event-loop paths, use
  `StaticPropertyInjector` or `HolderObjectInjector` instead.
