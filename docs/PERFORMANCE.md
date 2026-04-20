# ffi-zts performance measurements

Companion to [`DESIGN.md`](DESIGN.md) §7. Kept as its own file so
the core design doc doesn't drift every time someone reruns the
benchmarks on a different host.

## Host used for these numbers

- `ffi-zts` 2.0.0 (via `composer require sj-i/ffi-zts-parallel` on a
  fresh host dir)
- Ubuntu 24.04 / glibc 2.39 / x86_64
- PHP 8.5.5 NTS host (ondrej PPA) with ext-ffi, ext-pcntl, ext-sockets
- Embedded ZTS: `libphp-8.5-x86_64-glibc.tar.gz` from
  `sj-i/ffi-zts@libphp-8.5`, with opcache statically linked
- `pecl/parallel` 1.2.12, built against the same libphp.so
- Numbers are best-of-three, reported in milliseconds.

All scripts live under [`examples/bench/`](../examples/bench/); set
env vars (`N`, `ITER`, `SIZE`, `M`) to sweep.

## 1. Compute microbench

Each worker runs `sum(0..2_000_000)` (~10 ms of pure integer add).

| N workers | `pcntl_fork` total | ffi-zts total (boot + run) | ffi-zts inner only |
| ---: | ---: | ---: | ---: |
|  1 |  29 | 27  (17 + 10) |  8 |
|  4 |  42 | 35  (17 + 18) |  4 |
| 16 |  83 | 56  (17 + 39) |  2 |

"Inner only" is the time spent inside the embed once it's up --
that's what recurring work actually pays. The gap to "total" is
one-time SAPI bring-up + request teardown.

## 2. Reused `parallel\Runtime`

One `parallel\Runtime` is spun up and warmed with a no-op task,
then M more 2M-iter sums are dispatched sequentially to the same
runtime.

| M tasks | total | per-task |
| ---: | ---: | ---: |
|  1 |   7.3 | 7.3 |
|  4 |  29.4 | 7.3 |
| 16 | 118.3 | 7.4 |
| 64 | 465.4 | 7.3 |

Per-task cost is flat -- subsequent dispatches are microseconds of
ffi-zts-side overhead plus the task itself. This is the
DESIGN.md §7.1 "reused Runtime, subsequent task" row made concrete.

## 3. Shared data: fork pipe vs ffi-zts address pass

Four workers each touch every 4096th byte of a shared buffer. The
fork variant pipes the full buffer down an `AF_UNIX SOCK_STREAM`
pair to each child; the ffi-zts variant allocates via
`FFI::new('uint8_t[SIZE]', owned: false)` in the host, casts the
address to `intptr_t`, and passes that integer to each
`parallel\Runtime::run()`.

| buffer size | `pcntl_fork` | ffi-zts total | ffi-zts inner |
| ---: | ---: | ---: | ---: |
|  10 MB |  131 |  36 |  7 |
| 100 MB | 1420 |  99 | 13 |

Fork time scales linearly with buffer size because the data has to
be serialised and written through the socket per child; ffi-zts
stays near-flat because only the 8-byte address crosses the thread
boundary.

## 4. Reconciling with `DESIGN.md` §7.1 / §7.2

- Embed bring-up measured at **~17 ms** (warm FS cache, 8.5) vs the
  §7.1 estimate of 150-200 ms. The estimate was taken on an earlier
  8.4 loader before SAPI minimisation; the §7.1 table is worth a
  re-read once a cold-start number lands too.
- `parallel\Runtime` first spawn **~8 ms** matches the §7.1
  "first spawn 5-15 ms" band.
- `pcntl_fork` steady-state per-task **~5 ms** on this host (from
  the N=16 row, averaged over children) sits at the low end of the
  §7.2 "tens of microseconds to low milliseconds" range. Socket IPC
  + child PHP shutdown dominate -- the bare `fork()` call itself is
  still microseconds.

## 5. Reproducing

```sh
cd examples/bench

# Compute (fork vs embed)
php bench-fork.php 16 2000000
N=16 ITER=2000000 php run-embed.php bench-parallel.php

# Reused runtime
M=64 ITER=2000000 php run-embed.php bench-parallel-reuse.php

# Zero-copy blob vs pipe
php bench-fork-blob.php 4 $((100*1024*1024))
N=4 SIZE=$((100*1024*1024)) php run-embed-ffi.php bench-parallel-blob.php
```

Host must have PHP 8.5+ NTS with ext-ffi / ext-pcntl / ext-sockets
and a prior `composer require sj-i/ffi-zts-parallel` one directory
up from `examples/bench/`.
