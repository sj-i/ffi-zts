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

## Install

```sh
composer require sj-i/ffi-zts
```

`sj-i/ffi-zts` ships as a **Composer plugin**: on install it
downloads the pre-built `libphp.so` matching your host's PHP
minor / CPU arch / libc into `vendor/sj-i/ffi-zts/bin/libphp.so`.

Major tracks the host PHP minor: **1.x = PHP 8.4**, **2.x = PHP 8.5**.
Composer resolves the right major automatically from your host's
`php -v`; the 2.x line picks up upstream's static opcache, which
makes `opcache.preload` under the embed work out of the box (see
[`docs/PERFORMANCE.md`](docs/PERFORMANCE.md) for measured numbers).

### Trusting the plugin

Composer 2.2+ asks you to trust a new plugin before running it.
In interactive shells you get a `(y/N)` prompt on first install.
In CI / non-interactive environments, whitelist it up front:

```sh
composer config allow-plugins.sj-i/ffi-zts true
composer require sj-i/ffi-zts
```

If you also install the `sj-i/ffi-zts-parallel` satellite, allow it
too:

```sh
composer config allow-plugins.sj-i/ffi-zts true
composer config allow-plugins.sj-i/ffi-zts-parallel true
composer require sj-i/ffi-zts-parallel
```

### Manual install / retry

If the plugin was skipped (`--no-plugins`, network outage, binary
not yet published for a new PHP minor, ...), retry the binary fetch
on demand:

```sh
vendor/bin/ffi-zts install
```

`vendor/bin/ffi-zts info` reports the resolved host profile and
tells you whether the binary is in place.

## Usage

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use SjI\FfiZts\FfiZts;

FfiZts::boot()
    ->runScript(__DIR__ . '/worker.php');
```

`FfiZts::boot()` auto-resolves `vendor/sj-i/ffi-zts/bin/libphp.so`;
`$worker.php` runs inside the embedded ZTS interpreter.

For real OS-thread parallelism via `parallel\Runtime`, install the
satellite package
[`sj-i/ffi-zts-parallel`](https://github.com/sj-i/ffi-zts-parallel)
instead -- it pulls in this core package transitively and wires
`parallel.so` through the embed.

See [`docs/DESIGN.md`](docs/DESIGN.md) for the full architecture.

## Experimental loader (`ffi-zts.php`)

Before the library was packaged for Composer, the same mechanism
lived in a single `ffi-zts.php` script. It still works and is the
quickest way to inspect the ZTS embed end-to-end without composer
involvement:

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

### Building the binaries from source

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
