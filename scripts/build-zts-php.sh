#!/usr/bin/env bash
# Build a ZTS PHP 8.4 with --enable-embed into $PREFIX (default /home/user/php-zts).
# Matches the version of the system NTS PHP.
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
