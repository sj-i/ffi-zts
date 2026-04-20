# ffi-zts benchmarks

Hand-runnable microbenchmarks for the numbers quoted in
[`docs/PERFORMANCE.md`](../../docs/PERFORMANCE.md) (which is a
companion to [`docs/DESIGN.md`](../../docs/DESIGN.md) §7). Point of
reference rather than a CI-gated perf suite - they read env vars
for their knobs so you can cheaply sweep N / ITER / SIZE.

All scripts assume a host PHP 8.5+ NTS with `ext-ffi`, `ext-pcntl`,
`ext-sockets`, and a working `composer require sj-i/ffi-zts-parallel`
install one directory up.

## Files

| Script | Runs where | Measures |
| --- | --- | --- |
| `bench-fork.php` | host directly | N `pcntl_fork` workers summing 0..ITER-1, result piped back |
| `bench-fork-blob.php` | host directly | N `pcntl_fork` workers each receiving a SIZE-byte blob over `AF_UNIX` |
| `bench-parallel.php` | inside embed | N `parallel\Runtime` workers with the same sum |
| `bench-parallel-reuse.php` | inside embed | 1 reused `parallel\Runtime` across M sequential tasks |
| `bench-parallel-blob.php` | inside embed | 4 workers touching a shared FFI-allocated blob by address (zero-copy) |
| `run-embed.php` | host entry point | boots `Parallel::boot()`, executes the inner script, prints boot/run/total |
| `run-embed-ffi.php` | host entry point | same as `run-embed.php` but also sets `ffi.enable=true` for the blob bench |

## Example invocations

```sh
# Compute microbench (fork baseline)
php bench-fork.php 16 2000000

# Compute microbench (embed)
N=16 ITER=2000000 php run-embed.php bench-parallel.php

# Reused runtime, 64 sequential tasks
M=64 ITER=2000000 php run-embed.php bench-parallel-reuse.php

# 100MB blob: fork pipe vs ffi-zts zero-copy
php bench-fork-blob.php 4 $((100*1024*1024))
N=4 SIZE=$((100*1024*1024)) php run-embed-ffi.php bench-parallel-blob.php
```

Expected output lines start with `[fork]`, `[fork-blob]`,
`[parallel-inside]`, `[parallel-reuse]`, `[parallel-blob]`, or
`[host]`; `total=` and `per=` are milliseconds.
