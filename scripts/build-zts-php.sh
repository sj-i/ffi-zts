#!/usr/bin/env bash
# Build a ZTS PHP 8.4 with --enable-embed into $PREFIX (default /home/user/php-zts).
# Matches the version of the system NTS PHP.
#
# EXPERIMENTAL: this script patches ext/opcache/config.m4 to force
# opcache to link statically into libphp.so. Upstream PHP hard-codes
# `ext_shared=yes` for opcache so a vanilla `./configure
# --enable-opcache` always produces a separate opcache.so, which then
# needs the same DT_NEEDED libphp.so re-link the rest of the ffi-zts
# extensions go through. Linking opcache in statically avoids that
# extra step entirely - opcache's references to module_registry /
# zend_extensions resolve inside libphp.so with no runtime DEEPBIND
# gymnastics needed, which is what DESIGN.md \u00a75.2 assumes.
set -euo pipefail

PHP_VERSION="${PHP_VERSION:-8.4.19}"
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

# Force opcache to compile statically into libphp.so. Upstream pins
# ext_shared=yes unconditionally; flip it before `./configure`
# generates config.status.
if grep -q '^ext_shared=yes' ext/opcache/config.m4; then
    sed -i 's/^ext_shared=yes/ext_shared=no/' ext/opcache/config.m4
    echo "[build-zts-php] patched ext/opcache/config.m4: ext_shared=yes -> ext_shared=no"
fi

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

# Verify opcache went static: libphp.so should export
# zend_accel_* symbols, and no standalone opcache.so should exist
# under the extensions directory.
echo "[build-zts-php] verifying opcache static-linkage..."
if nm -D "$PREFIX/lib/libphp.so" 2>/dev/null | grep -q 'accel_startup\|zend_accel_'; then
    echo "[build-zts-php] OK: opcache symbols are exported from libphp.so"
else
    echo "[build-zts-php] WARNING: opcache symbols NOT found in libphp.so - static link likely failed"
fi
if ls "$PREFIX"/lib/php/extensions/*/opcache.so 2>/dev/null; then
    echo "[build-zts-php] WARNING: a standalone opcache.so is still present - static link did not take"
fi
