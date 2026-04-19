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
