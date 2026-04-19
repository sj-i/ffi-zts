# ffi-zts

Add-on ZTS VM for an NTS PHP: a plain non-thread-safe PHP CLI loads
`libphp.so` from a thread-safe PHP build through FFI and executes
scripts inside that embedded ZTS interpreter -- including scripts
that use ZTS-only extensions such as
[`pecl/parallel`](https://www.php.net/manual/en/book.parallel.php).

Originally prompted by
<https://github.com/adsr/php-meta-sapi/issues/1>, which asked whether
[adsr/php-meta-sapi](https://github.com/adsr/php-meta-sapi)'s
"PHP embedded in PHP" trick can be pushed one step further: use the
embedded PHP to pick up ZTS extensions while the outer PHP stays NTS.

The short answer is **yes**, with two small pieces of glue.

## What's here

| Path | What |
| --- | --- |
| `ffi-zts.php`                 | NTS-hosted loader that brings up ZTS `libphp.so` via FFI and runs a script |
| `examples/hello.php`          | Smoke test -- prints `PHP_ZTS`, `PHP_SAPI`, ... |
| `examples/test-parallel.php`  | Spawns four `parallel\Runtime` workers and shows their results |
| `examples/ffi-zts.ini`        | INI fragment fed to the embedded ZTS (`extension=parallel.so`) |
| `scripts/build-zts-php.sh`    | Build ZTS PHP 8.4 with `--enable-embed` into `/home/user/php-zts` |
| `scripts/build-parallel.sh`   | Build two `parallel.so`s: vanilla + FFI-linked |

## Running

```sh
# Host PHP is the system NTS build (e.g. /usr/bin/php).
php ffi-zts.php \
    --libphp-path=/home/user/php-zts/lib/libphp.so \
    --ini=examples/ffi-zts.ini \
    examples/test-parallel.php
```

Expected output:

```
== host info ==
PHP_VERSION = 8.4.19
PHP_SAPI    = ffi-zts
PHP_ZTS     = 1
parallel?   = yes
...
worker id=0 pid=... sum=1999999000000 zts=1 took=10ms
worker id=1 pid=... sum=1999999000000 zts=1 took=10ms
worker id=2 pid=... sum=1999999000000 zts=1 took=10ms
worker id=3 pid=... sum=1999999000000 zts=1 took=10ms
```

Verify with `php -v` that the outer process is indeed NTS; the
embedded SAPI reports `PHP_ZTS=1` and `PHP_SAPI=ffi-zts`.

## Build steps

```sh
# 1. ZTS PHP (embed SAPI) into /home/user/php-zts
./scripts/build-zts-php.sh

# 2. Two parallel.so builds: vanilla + FFI-linked
./scripts/build-parallel.sh
```

Host-side requirements: a working NTS PHP 8.x with `ffi` enabled
(`/etc/php/8.4/cli/conf.d/20-ffi.ini` on Debian/Ubuntu).

## Why two `parallel.so`s?

Because symbol resolution is the whole game here.

When `libphp.so` (ZTS) is dlopen'd into an NTS PHP process, the
host binary has already exported its own NTS copies of the Zend
runtime (`zend_register_functions`, `zend_register_internal_class_with_flags`,
`compiler_globals`, ...). The runtime linker puts the main
executable *first* in the global search order, so any later library
that references those symbols resolves to the NTS copy by default.

`ffi-zts.php` works around this with two flags on its own
`dlopen()` of `libphp.so`:

- `RTLD_DEEPBIND` so `libphp.so`'s own internal calls prefer its own
  (ZTS) symbols over the host's NTS ones.
- `RTLD_GLOBAL` so subsequently-dlopen'd extensions can *also* see
  `libphp.so`'s ZTS symbols.

That is enough to run the embedded ZTS interpreter cleanly. But when
the embedded PHP honors `extension=parallel.so`, its internal
`php_load_shlib()` calls `dlopen(parallel.so, RTLD_GLOBAL|RTLD_DEEPBIND)`
too -- and `RTLD_DEEPBIND` only promotes symbols from the library
*and its `DT_NEEDED` dependencies*. Stock `parallel.so` has no
`DT_NEEDED libphp.so`, so its undefined references fall through to
the global scope, which still has the host NTS symbols at the front.
Result: `parallel`'s `MINIT` calls the NTS
`zend_register_internal_class_with_flags` on the NTS class table and
segfaults.

The fix is to re-link `parallel.so` against `libphp.so`:

```sh
LDFLAGS="-Wl,--no-as-needed -L$PREFIX/lib -Wl,-rpath,$PREFIX/lib -lphp" \
    ./configure --with-php-config=$PREFIX/bin/php-config
```

Now `parallel.so` has `libphp.so` in `DT_NEEDED`, so with
`RTLD_DEEPBIND` its references to the Zend runtime resolve through
`libphp.so` (ZTS) before reaching the host (NTS).

This re-linked `parallel.so` is not usable from the native ZTS CLI
(double-loading `libphp.so` crashes the self-contained CLI binary),
which is why the build script keeps it in a separate
`extensions/ffi-zts/` directory.

## Why not `dlmopen`?

`dlmopen(LM_ID_NEWLM, ...)` would give perfect isolation, and that
was the first thing tried -- the baseline "hello" script worked
cleanly. But as soon as the embedded ZTS tried to `dlopen(parallel.so)`
from inside its new namespace, glibc segfaulted in
`ld-linux.so`'s `add_to_global_resize` (`./elf/dl-open.c:126`). This
is a long-standing limitation: loading any library that pulls in
libpthread-ish behavior into a non-default linker namespace is
fragile in glibc. So `dlmopen` is the nicer theory, and
`RTLD_DEEPBIND + libphp-linked extension` is the one that actually
runs.

## Caveats

- The host NTS process is still fundamentally single-threaded PHP.
  Only code running *inside* the embedded ZTS interpreter (and in
  threads spawned from it by `parallel\Runtime`) benefits from
  thread-safety.
- Each ZTS extension you want to use from the embedded side may need
  the same `libphp` re-link treatment if it references core Zend
  symbols.
- Shutdown is minimal. The loader calls `php_module_shutdown`,
  `sapi_shutdown`, and `tsrm_shutdown`, but interleaving multiple
  startups or reusing the FFI handle is out of scope here.

## References

- <https://github.com/adsr/php-meta-sapi> -- source of the trick.
- <https://github.com/adsr/php-meta-sapi/issues/1> -- the question
  this repo is the experiment for.
- <https://www.php.net/manual/en/book.parallel.php> -- ZTS-only
  extension used as the demo payload.
