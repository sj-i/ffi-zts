#!/usr/bin/env bash
# Build pecl/parallel twice for the ZTS PHP in $PREFIX:
#   1) vanilla build  -> extensions/no-debug-zts-20240924/parallel.so
#      (usable from the native ZTS CLI)
#   2) FFI-linked build -> extensions/ffi-zts/parallel.so
#      (linked against libphp.so so that, when dlopen'd inside an NTS
#       host that has also loaded libphp.so via FFI, its undefined
#       references to zend_register_internal_class_with_flags et al.
#       resolve to the ZTS libphp.so instead of the host NTS binary.)
set -euo pipefail

PREFIX="${PREFIX:-/home/user/php-zts}"
BUILD_DIR="${BUILD_DIR:-/home/user/build}"
PARALLEL_VERSION="${PARALLEL_VERSION:-1.2.12}"

mkdir -p "$BUILD_DIR"
cd "$BUILD_DIR"

if [[ ! -d "parallel-${PARALLEL_VERSION}" ]]; then
    curl -sL -o parallel.tgz https://pecl.php.net/get/parallel
    tar xzf parallel.tgz
fi

cd "parallel-${PARALLEL_VERSION}"
PHPCFG="$PREFIX/bin/php-config"
PHPIZE="$PREFIX/bin/phpize"
EXT_API=$("$PHPCFG" --extension-dir | xargs -n1 basename)
FFI_EXT_DIR="$PREFIX/lib/php/extensions/ffi-zts"
mkdir -p "$FFI_EXT_DIR"

# --- vanilla build ---
make clean  >/dev/null 2>&1 || true
"$PHPIZE" --clean >/dev/null 2>&1 || true
"$PHPIZE" >/dev/null
./configure --with-php-config="$PHPCFG" >/dev/null
make -j"$(nproc)"
make install

# --- FFI-linked build ---
make clean  >/dev/null 2>&1
"$PHPIZE" --clean >/dev/null 2>&1
"$PHPIZE" >/dev/null
LDFLAGS="-Wl,--no-as-needed -L$PREFIX/lib -Wl,-rpath,$PREFIX/lib -lphp" \
    ./configure --with-php-config="$PHPCFG" >/dev/null
make -j"$(nproc)"
cp modules/parallel.so "$FFI_EXT_DIR/parallel.so"

echo
echo "vanilla   : $PREFIX/lib/php/extensions/$EXT_API/parallel.so"
echo "ffi-zts   : $FFI_EXT_DIR/parallel.so"
