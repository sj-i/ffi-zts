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
