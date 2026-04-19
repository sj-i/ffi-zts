# ffi-zts design document

Status: **Draft** -- distillation of the experimental findings in
`ffi-zts.php` and the follow-up discussion on distribution, extension
loading, and performance.

## 1. Summary

`ffi-zts` lets a plain non-thread-safe (NTS) PHP CLI bring up a
thread-safe (ZTS) PHP interpreter inside the same process through FFI
and run user code -- including code that depends on ZTS-only
extensions such as `pecl/parallel` -- inside that embedded ZTS
interpreter. The primary goal is to make real OS-thread
multithreading available to NTS PHP users via a `composer require`
without asking them to replace their system PHP build.

The initial spike (merged as the `ffi-zts.php` loader plus the
`parallel` demo) established that the approach is technically viable
on Linux/glibc. This document captures the design decisions that
follow from that spike and proposes the shape of a distributable
library.

## 2. Goals and non-goals

### Goals
- Provide a `composer require`-able library that gives NTS PHP the
  ability to run CPU-bound code on real OS threads.
- Keep zero-copy data sharing between the outer NTS PHP and the
  embedded ZTS workers as a first-class feature (FFI buffers shared
  by address).
- Preserve upstream `parallel` release cadence for users: patch-level
  updates of `parallel` should not require a new `ffi-zts` release.
- Bind the library version cleanly to the PHP minor version ABI
  (one `ffi-zts` major version per supported PHP minor).

### Non-goals
- Not a drop-in replacement for `pcntl_fork` + IPC; the sweet spot is
  CPU-bound, memory-heavy work, not arbitrary process parallelism.
- Not a way to share stateful handles (DB connections, libcurl easy
  handles, `FILE*`, etc.) across threads; those remain thread-local
  by construction.
- Not cross-platform at launch. Linux + glibc only for v1
  (`RTLD_DEEPBIND`, `memfd_create`, ELF). macOS / Windows / musl can
  be revisited later as separate targets.

## 3. High-level architecture

```
+-------------------------- host PHP process -----------------------+
|                                                                    |
|   /usr/bin/php  (NTS)                                              |
|     |                                                              |
|     |  FFI                                                         |
|     v                                                              |
|   libphp.so  (ZTS, --enable-embed, -Bsymbolic, bundled opcache)    |
|     |                                                              |
|     |  zend_extension= / extension= (via embed ini_entries)        |
|     v                                                              |
|   parallel.so  (ZTS, DT_NEEDED libphp.so via ELF patch)            |
|     |                                                              |
|     |  pthread_create                                              |
|     v                                                              |
|   worker thread 1 .. N                                             |
|                                                                    |
+-------------------------------------------------------------------+
```

The outer NTS PHP only ever calls into the embed through a small,
typed FFI surface (SAPI startup, request startup, `execute_script`,
shutdown). Everything that runs inside the embed -- opcode execution,
`parallel\Runtime` scheduling, the worker threads themselves --
executes at full native ZTS speed, with no FFI calls on the hot path.

## 4. Embed loader and symbol isolation

### 4.1 The core problem

When a process started from `/usr/bin/php` (NTS) `dlopen()`s a ZTS
`libphp.so`, both images end up in the same address space. The host
binary is built with `--export-dynamic`, so its NTS copies of the
Zend runtime (`zend_register_functions`,
`zend_register_internal_class_with_flags`, `compiler_globals`,
hundreds of others) are placed at the head of the global symbol
search order. Any relocation in the loaded library that falls through
to the global scope will therefore resolve to the host NTS copy --
and the NTS and ZTS copies operate on different in-memory structures
(global `compiler_globals` vs per-thread TSRM).

The result is a cluster of symptoms: "duplicate function name"
warnings during module startup (because the NTS function table
already contains `exit`, `die`, ...), `zend_mm_heap corrupted` from
mixed allocator ownership, or outright segmentation faults from
calling NTS routines against ZTS state.

### 4.2 Approach taken: `RTLD_DEEPBIND`

The embed loader opens `libphp.so` with `RTLD_NOW | RTLD_GLOBAL |
RTLD_DEEPBIND`:

- `RTLD_DEEPBIND` places the loaded library's own scope *before* the
  global scope for the purpose of resolving the library's undefined
  references. `libphp.so` thereby prefers its own (ZTS) copies of
  Zend functions over the host's NTS copies.
- `RTLD_GLOBAL` makes `libphp.so`'s exported symbols visible to
  libraries dlopen'd *later* (e.g. extension `.so`s loaded by
  `php_module_startup` when it honors `extension=...`). Without this,
  extensions fail to resolve core ZTS symbols like
  `core_globals_offset`.
- `RTLD_NOW` binds eagerly so any missing symbol surfaces at load
  time, not at the first call.

The host PHP is linked `BIND_NOW`, so adding globals after it starts
does not re-bind its own PLT. This is what makes `RTLD_GLOBAL` safe
in this direction.

### 4.3 Rejected alternative: `dlmopen`

`dlmopen(LM_ID_NEWLM, ...)` gives cleaner isolation -- `libphp.so`
and its transitive dependencies live in a fresh linker namespace and
cannot see the host's symbols at all. The experiment shows this
works cleanly for the baseline (loading `libphp.so`, running a PHP
script with no extensions), but fails when the embedded PHP later
dlopen's `parallel.so`: glibc's `ld-linux.so` segfaults inside
`add_to_global_resize` (`./elf/dl-open.c:126`). This is a known class
of bug: libraries with libpthread-related state do not play well
with secondary linker namespaces. The `RTLD_DEEPBIND` route is what
actually runs end-to-end today.

### 4.4 Loader responsibilities

The loader (current `ffi-zts.php`, destined to become
`SjI\FfiZts\Embed` or similar) owns:

- `dlopen(libphp.so, RTLD_NOW|RTLD_GLOBAL|RTLD_DEEPBIND)` via FFI to
  libc.
- `dlsym`'ing a small set of entry points
  (`php_tsrm_startup`, `zend_signal_startup`, `sapi_startup`,
  `php_module_startup`, `php_request_startup`/`_shutdown`,
  `php_execute_script`, `zend_stream_init_filename`,
  `zend_destroy_file_handle`, and their shutdown counterparts).
- Building a minimal `sapi_module_struct` with PHP closures plugged
  in for `ub_write`, `sapi_error`, `send_header`, `log_message`,
  `read_cookies`, `register_server_variables`.
- Feeding `ini_entries` to the embed so it self-configures
  (`extension_dir`, `extension=`, `zend_extension=`, preload, error
  display, etc.).
- Owning lifetimes of C strings and callback closures for the
  duration of the embed.

## 5. Extension loading strategy

### 5.1 Two categories of extensions

ZTS extensions used from the embed fall into two categories that want
opposite defaults:

| Category | Example | Release coupling to PHP core | Default strategy |
| --- | --- | --- | --- |
| Bundled (shipped inside php-src) | `opcache`, `mbstring`, `pcre`, `spl`, `reflection`, `json`, `date`, `ffi`, `standard` | Fully synchronised with PHP minor release | **Statically linked into `libphp.so`** |
| External (PECL / third-party) | `parallel` | Independent version, but ABI-bound to PHP minor in practice | **Shared `.so`, ELF-patched, loaded by the embed** |

### 5.2 Static bundling of core + opcache

Bundled extensions are compiled into `libphp.so` at build time
(`--enable-opcache` without `=shared`, etc.). This removes the need
for any per-extension symbol-resolution workaround:

- Their references to the Zend runtime are resolved inside
  `libphp.so` at link time.
- With `RTLD_DEEPBIND` on `libphp.so`, those references stay inside
  the library at runtime regardless of what the host exports.
- `opcache.preload` becomes a natural fit: a long-lived embed
  preloads a domain kernel once, and every subsequent
  `parallel\Runtime` worker thread inherits the preloaded classes
  and functions for free (OPcache SHM is process-wide in ZTS mode).

OPcache SHM segments for the NTS host and the embedded ZTS do not
collide: on Linux they default to anonymous `mmap` mappings that are
inherently per-process region, and the bytecode layout is ABI-specific
so sharing would be incorrect anyway.

### 5.3 Dynamic loading of external extensions

A naive `extension=parallel.so` in the embed's `ini_entries` fails
because stock `parallel.so` has no `DT_NEEDED libphp.so`. Under
`RTLD_DEEPBIND`, the symbols it needs (e.g.
`zend_register_internal_class_with_flags`) fall through to the host
NTS binary and segfault during `MINIT`.

The fix is to ensure `parallel.so` declares `libphp.so` as a
`DT_NEEDED` dependency so DEEPBIND's per-library scope includes the
ZTS core before falling through to global scope. This can be done at
build time (`LDFLAGS="-lphp ..."`) or post-build via an ELF edit
(`patchelf --add-needed libphp.so --set-rpath <libphp-dir>`).

The same re-linked `parallel.so` is **not** usable under a
self-contained native ZTS CLI -- loading `libphp.so` on top of a
CLI that already contains its own Zend runtime double-registers
everything and crashes. Two artefacts therefore need to coexist on
disk in separate locations:

- `extensions/no-debug-zts-<api>/parallel.so` -- vanilla, for native
  ZTS CLI use.
- `extensions/ffi-zts/parallel.so` -- `DT_NEEDED libphp.so` added,
  for ffi-zts embed use only.

### 5.4 ELF writer in pure PHP

The ELF edit (adding `DT_NEEDED` + optionally `DT_RUNPATH`) is small
enough that shipping a pure-PHP implementation inside `ffi-zts`
avoids taking a hard dependency on the `patchelf` system tool and on
a compiler toolchain. Scope:

- ELF64 little-endian shared objects only (Linux x86_64 / aarch64).
- Operations: append a new `PT_LOAD` segment that carries an extended
  `.dynstr` and a rewritten `.dynamic`, insert `DT_NEEDED` before the
  trailing `DT_NULL`, fix up program headers, optionally set
  `DT_RUNPATH`.
- Reader-side infrastructure can be shared with reliforp/reli-prof,
  which already parses ELF headers, program headers, `.dynamic`, and
  `.dynsym` via FFI-backed struct overlays. The net-new code is the
  writer side plus the trailing `PT_LOAD` placement logic.

### 5.5 Loader paths: disk cache vs `memfd_create`

The patched `parallel.so` can be materialised two ways:

1. **Disk cache (default).** Run the ELF patch at `composer install`
   / `composer update` time (or via `vendor/bin/ffi-zts rebuild-cache`)
   and write the result under `vendor/.../cache/parallel.so.patched`.
   Runtime just `dlopen`s a regular file. Cache filenames embed
   hashes of the input `.so`, the target `libphp.so`, and the
   `ffi-zts` version to make invalidation deterministic.

2. **`memfd_create` (override / read-only environments).** Perform
   the ELF patch in memory, write the bytes to a memfd, and
   `dlopen("/proc/self/fd/N", ...)`. No filesystem write is required.
   This is the path for `FfiZts::boot()->withExtension('/path/my-parallel.so')`
   -- runtime user overrides, read-only containers, forensic
   sandboxes.

Both paths share the same `patchElfBytes()` implementation; only the
sink differs. Disk cache is the default for normal usage because it
amortises the patch cost, is debuggable with `readelf`, and benefits
from OS page cache sharing across FPM workers.

## 6. Distribution model

### 6.1 Package split

The library is distributed as two (or more) Composer packages:

- `sj-i/ffi-zts` -- the core embed loader, libphp.so binary artefact,
  and the ELF writer. Release cadence tracks the PHP minor version.
- `sj-i/ffi-zts-parallel` -- the PHP-side wrapper around
  `parallel\Runtime` plus a "reference" `parallel.so` binary artefact.
  Release cadence tracks upstream `parallel` patch releases.

Additional satellite packages (`sj-i/ffi-zts-<whatever>`) can be
introduced as other external ZTS extensions become relevant.

### 6.2 Composer wiring

Satellite packages depend on the core package via a standard
`require`:

```json
{
    "name": "sj-i/ffi-zts-parallel",
    "require": {
        "php": ">=8.4,<8.5",
        "ext-ffi": "*",
        "sj-i/ffi-zts": "^1.0"
    }
}
```

The PHP version constraint ties the install to the matching libphp.so
ABI, and the `ext-ffi` constraint ensures the host is actually
capable of running the loader.

### 6.3 Binary delivery

Large binary artefacts (libphp.so ~34 MB, patched extension `.so`s)
do not ship inside the Composer package itself. Instead, each
package runs a post-install script that fetches the correct
pre-built tarball from GitHub Releases based on the host's PHP
version, CPU architecture, and libc variant:

```json
"scripts": {
    "post-install-cmd": "SjI\\FfiZts\\Installer::fetchBinaries",
    "post-update-cmd":  "SjI\\FfiZts\\Installer::fetchBinaries"
}
```

The installer detects the environment, downloads once, verifies
checksums, runs the ELF patch if the package ships a stock `.so` that
needs one, and writes the resulting artefacts under
`vendor/sj-i/ffi-zts{,-parallel}/bin/`. This is the same pattern that
puppeteer-php and chromedriver distribution-helpers already use.

### 6.4 Versioning policy

- **Major** of `ffi-zts` is bumped only when the target PHP minor
  changes. `ffi-zts 1.x` = PHP 8.4; `ffi-zts 2.x` = PHP 8.5; and so
  on.
- **Minor/patch** of `ffi-zts` covers PHP patch releases (e.g.
  PHP 8.4.19 -> 8.4.20), bug fixes in the loader, and ELF writer
  improvements.
- **Minor/patch** of `ffi-zts-parallel` covers upstream `parallel`
  patch/minor releases and wrapper-side fixes. It may ship on a
  different cadence than the core package.

A user who just wants to pick up a `parallel` bug fix runs
`composer update sj-i/ffi-zts-parallel`; the core package stays
pinned. A user who upgrades to a new PHP minor updates the PHP
version constraint and bumps both packages.

### 6.5 Platform coverage (v1)

- Linux x86_64 (glibc 2.31+)
- Linux aarch64 (glibc 2.31+)

musl and non-Linux targets are out of scope for v1. macOS and
Windows in particular need a different embed approach entirely
(Mach-O / PE have no DEEPBIND equivalent, memfd semantics differ).

## 7. Performance characteristics

### 7.1 Where FFI overhead occurs, and where it does not

FFI is used exclusively at the NTS <-> ZTS boundary. The hot path
inside the embed (opcode execution, `parallel\Runtime` scheduling,
worker thread code) is plain native ZTS PHP with no FFI involvement.

| Event | FFI involved? | Approximate cost |
| --- | --- | --- |
| `dlopen(libphp.so)` + module startup | yes (one-time) | ~150-200 ms, dominated by `php_module_startup`; libffi trampoline cost is sub-microsecond and negligible |
| Per-request `php_request_startup` / `_shutdown` | yes | a few microseconds |
| `php_execute_script` | yes (invocation only) | microseconds to invoke; then pure native cost for the script body |
| SAPI callbacks (`ub_write`, `log_message`, ...) | yes | microseconds per invocation; effectively free unless the embed writes huge volumes of output through `ub_write` |
| `parallel\Runtime` spawn (first) | no | ~5-15 ms (pthread_create + TSRM allocation + parallel bootstrap) |
| `parallel\Runtime` spawn (reused Runtime, subsequent task) | no | microseconds |
| Zend opcode execution inside embed | no | identical to native ZTS |

The measured end-to-end time for `hello.php` + 4-worker
`test-parallel.php` through the current loader is ~217 ms, of which
roughly 180 ms is one-time embed bring-up and the remainder is the
parallel thread work (largely overlapped).

### 7.2 Comparison with `pcntl_fork`

| Dimension | `pcntl_fork` | ffi-zts + parallel |
| --- | --- | --- |
| Spawn cost | tens of microseconds to low milliseconds; grows with parent's page-table size | pthread_create (microseconds) + TSRM init (milliseconds on first runtime, reusable thereafter) |
| State inheritance | entire parent via CoW (including live DB connections, which is hazardous) | clean ZTS context; explicit closure + argv passing |
| Shared memory | CoW until written; explicit sharing requires SysV/mmap | same address space; FFI buffers shareable by address (zero-copy) |
| Crash isolation | child death does not kill parent | thread death kills the whole process |
| One-time startup cost | 0 (host PHP already running) | ~150-200 ms embed bring-up |
| Per-task steady-state cost | one fork + IPC per task | one `Runtime::run()` per task; sub-ms on a reused Runtime |

The two approaches have opposite cost curves. `pcntl_fork` wins for
small numbers of independent jobs, especially those that need state
the parent already has. ffi-zts wins once the embed startup amortises
-- which happens quickly if the process stays alive long enough to
run more than a few dozen short tasks, and immediately if the
workload involves large inputs that `pcntl_fork`-based pipelines
would have to IPC-serialise but that ffi-zts can share by address.

## 8. Memory sharing model

Because the outer NTS PHP and the embedded ZTS PHP live in the same
address space, FFI buffers allocated outside Zend memory management
can be shared between them by raw address. The standard
`parallel\Runtime` closure-argument serialiser passes integers
verbatim, so a buffer address cast to `intptr_t` crosses the
thread boundary intact.

Concretely:

```php
// Outer NTS
$buf   = FFI::new('uint8_t[1048576]', owned: false);
$addr  = FFI::cast('intptr_t', FFI::addr($buf))->cdata;

// Inner ZTS, inside parallel\Runtime::run
$ptr   = FFI::cast('uint8_t*', $addr);
// ... reads and writes the same physical memory as the outer side
```

This bypasses `parallel`'s normal "parallel-safe type" restriction
(which otherwise forces copies for anything non-trivial) and avoids
ZendMM entirely, so there is no NTS-vs-ZTS allocator mixing.

The caller carries three responsibilities:

1. **Lifetime.** The outer side must keep the FFI buffer alive until
   every worker that holds its address has joined; otherwise the
   inner side is use-after-free.
2. **Synchronisation.** Zero-copy does not imply data-race freedom.
   Shared writes need explicit primitives (FFI-wrapped
   `pthread_mutex_t` / `std::atomic` / `parallel\Channel` as a
   notification boundary).
3. **Type contract.** The outer and inner `FFI::cdef`s are
   independent; the two sides must agree on the struct layout out of
   band.

This model is intended for plain byte buffers, numeric arrays, and
POD structs. Stateful resources -- DB connections, `FILE*`, libcurl
easy handles, open stream descriptors with userspace state -- are
**not** safe to share across threads regardless of whether the
sharing mechanism is zero-copy. Each worker must open its own
connection (or check one out of a pool per task). This restriction
is inherent to threading, not specific to ffi-zts.

## 9. Open questions

- **ELF writer scope.** Start with just `DT_NEEDED` + `DT_RUNPATH`
  addition, or generalise earlier for other patch operations? Likely
  start narrow, extract into a reusable `sj-i/php-elf` sub-package
  only when a second consumer (e.g. reliforp/reli-prof) asks.
- **memfd dlopen portability.** `dlopen("/proc/self/fd/N")` relies on
  glibc behaviour that, while stable in practice, is not strictly
  standardised. Worth benchmarking the cost of copying the patched
  bytes to `$XDG_RUNTIME_DIR` (tmpfs) vs memfd as an alternative.
- **CI coverage matrix.** PHP 8.4 x86_64 at launch; aarch64 and
  PHP 8.5 preview matrices to add before v1. Each needs a
  pre-built `libphp.so` and `parallel.so` tested under GitHub Actions
  runners.
- **Interaction with PHP-FPM.** Embed startup cost is amortised by
  long-lived processes. FPM-with-always-reload (`pm = dynamic`
  aggressive recycling) could negate the benefit. Recommend a
  deployment note on keeping embed-using workers long-lived.
- **Security posture.** Running ELF-edited shared objects is no
  worse than running user-controlled Composer packages, but the ELF
  writer's input validation (bounds checks, refusal to process
  non-ELF files, resistance to crafted inputs) needs to be written
  defensively.

## 10. References

- Spike implementation:
  [`ffi-zts.php`](../ffi-zts.php),
  [`examples/test-parallel.php`](../examples/test-parallel.php),
  [`scripts/build-parallel.sh`](../scripts/build-parallel.sh).
- Upstream inspiration:
  [adsr/php-meta-sapi](https://github.com/adsr/php-meta-sapi)
  and [issue #1](https://github.com/adsr/php-meta-sapi/issues/1).
- Thread-safety extension:
  [pecl/parallel](https://www.php.net/manual/en/book.parallel.php).
- ELF reader infrastructure already used in-house:
  [reliforp/reli-prof](https://github.com/reliforp/reli-prof).
- `dlmopen` + libpthread limitations background:
  glibc bugs in the 15971 / 20198 / 24776 series on
  <https://sourceware.org/bugzilla/>.
