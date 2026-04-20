# Public API surface

Status: **Draft** -- user-visible classes and methods for the
concurrency layer. Phase 1 is in scope for detailed design; later
phases are sketched to show that they slot in additively.

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

```php
/**
 * @template-covariant TState of Fresh|Drained
 */
final class Payload
{
    public function size(): int;

    /**
     * @param-this-out Payload<Fresh>     // only callable on Fresh
     */
    public function writeFromString(int $offset, string $bytes): void;

    /**
     * Internal API -- drains the payload and returns the raw
     * pointer. Intended for Channel implementations, not user
     * code.
     *
     * @phpstan-self-out self<Drained>
     */
    public function take(): int;
}

/** Phantom marker types. */
final class Fresh {}
final class Drained {}
```

Users almost never reference `Fresh` or `Drained` directly -- they
only appear in `Payload<...>` type annotations on parameters and
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

### 2.5 CvInjector (advanced)

For users who want to drive CV injection from their own transport
(e.g. a custom channel):

```php
interface CvInjector
{
    /**
     * Inject the payload's zend_string into the named CV of the
     * caller's PHP frame. Transfers ownership: the Payload is
     * drained on success.
     *
     * @phpstan-param-out Payload<Drained> $payload
     */
    public function injectInto(string $cvName, Payload $payload): void;
}
```

The default implementation targets `prev_execute_data->func->op_array`
(synchronous caller's CV). Alternative implementations for
non-synchronous cases (static property, object property, global)
are exposed as separate classes users can opt into.

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

## 4. Satellite package API (Phase 2+)

Sketched here to show fit; detailed design lives in
`sj-i/ffi-zts-parallel`.

### 4.1 Channel

```php
use SjI\FfiZts\Parallel\Concurrent\Channel;

$ch = Channel::open('jobs');

/**
 * @param-out Payload<Drained> $payload
 */
$ch->send(Payload $payload): void;

/**
 * Blocks until a message is available, then injects it as a
 * zend_string into the named CV in the caller's frame.
 */
$ch->recvInto(string $cvName): void;
```

`AsyncChannel` is the same interface with an added `fd(): int` so
event loops can `poll` / `epoll` on it. Implementation detail is in
`ecosystem.md` and a future `async-channel.md`.

### 4.2 Pool (Phase 2)

```php
use SjI\FfiZts\Parallel\Concurrent\Pool;

$pool = Pool::withWorkers(8);
$future = $pool->submit(
    fn () => analyse(),
    bindings: ['row' => $payload],
);
$result = $future->get();
```

### 4.3 Structured combinators (Phase 3)

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

### 4.4 Atomics (Phase 2)

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

## 5. Type-progression API (Phases 4 / 5)

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

For Phase 2+, the same primitives apply, with the satellite's
`Channel` / `Pool` / `Structured` APIs layered over them.

## 7. What users should avoid

- Holding a `Payload` reference anywhere other than a local
  variable. The custom PHPStan rule enforces this.
- Sending the same `Payload` twice. Static types catch the second
  `send()` via `Payload<Drained>` lacking the method.
- Calling `FFI::string($cdata, $len)` when a Payload flow would
  serve the same purpose. It silently copies.
- Using `parallel\Channel::send($payload)` directly. The raw
  parallel channel will serialise. Always go through the satellite
  package's `Channel`, which understands Payload handoff.
