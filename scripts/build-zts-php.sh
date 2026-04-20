#!/usr/bin/env bash
# Build a ZTS PHP (default 8.5.5) with --enable-embed into $PREFIX
# (default /home/user/php-zts). The PHP minor is kept in lockstep
# with the ffi-zts major line (2.x = PHP 8.5) per docs/DESIGN.md §6.4.
#
# NOTE: PHP 8.5 upstream links opcache statically into libphp.so
# (php/php-src#18660 + follow-ups), so the extra DT_NEEDED libphp.so
# re-link dance the 1.x line needed for ZTS opcache is no longer
# required. `--enable-opcache` here lands opcache inside libphp.so
# directly and the embed gets opcache.preload for free.
set -euo pipefail

PHP_VERSION="${PHP_VERSION:-8.5.5}"
PREFIX="${PREFIX:-/home/user/php-zts}"
BUILD_DIR="${BUILD_DIR:-/home/user/build}"

mkdir -p "$BUILD_DIR"
cd "$BUILD_DIR"

if [[ ! -d "php-${PHP_VERSION}" ]]; then
    curl -sL -o "php-${PHP_VERSION}.tar.gz" \
        "https://www.php.net/distributions/php-${PHP_VERSION}.tar.gz"
    tar xzf "php-${PHP_VERSION}.tar.gz"
fi

cd "php-${PHP_VERSION}"
make clean >/dev/null 2>&1 || true

./configure \
    --prefix="$PREFIX" \
    --enable-zts \
    --enable-embed \
    --enable-cli \
    --with-readline \
    --disable-cgi \
    --disable-all \
    --enable-opcache \
    --enable-ffi --with-ffi \
    --enable-pcntl \
    --enable-mbstring \
    --enable-tokenizer \
    --enable-phar \
    --enable-session \
    --enable-hash \
    --with-sqlite3 \
    --enable-pdo --with-pdo-sqlite

make -j"$(nproc)"
make install

echo "ZTS PHP installed to $PREFIX"
"$PREFIX/bin/php" -v

# Sanity-check opcache is now bundled into libphp.so rather than
# left behind as a separate extensions/.../opcache.so. If either
# invariant breaks, the preload-under-embed story regresses and a
# release should not go out.
echo "[build-zts-php] verifying bundled opcache ..."
if nm -D "$PREFIX/lib/libphp.so" 2>/dev/null | grep -qE 'accel_startup|zend_accel_'; then
    echo "[build-zts-php] OK: opcache symbols exported from libphp.so"
else
    echo "[build-zts-php] ERROR: opcache symbols NOT exported from libphp.so" >&2
    exit 1
fi
if compgen -G "$PREFIX/lib/php/extensions/*/opcache.so" >/dev/null; then
    echo "[build-zts-php] ERROR: a standalone opcache.so is still present:" >&2
    ls "$PREFIX"/lib/php/extensions/*/opcache.so >&2
    exit 1
fi
echo "[build-zts-php] OK: no standalone opcache.so in lib/php/extensions/"
