<?php
declare(strict_types=1);

namespace SjI\FfiZts;

use SjI\FfiZts\Exception\EmbedException;

final class Platform
{
    public static function phpAbi(): string
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    public static function arch(): string
    {
        return php_uname('m');
    }

    public static function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    public static function libc(): string
    {
        $musl = ['/lib/ld-musl-x86_64.so.1', '/lib/ld-musl-aarch64.so.1'];
        foreach ($musl as $p) {
            if (is_file($p)) {
                return 'musl';
            }
        }
        return 'glibc';
    }

    public static function assertSupported(): void
    {
        if (!self::isLinux()) {
            throw new EmbedException('ffi-zts v1 supports Linux only; got ' . PHP_OS_FAMILY);
        }
        if (!in_array(self::arch(), ['x86_64', 'aarch64'], true)) {
            throw new EmbedException('ffi-zts v1 supports x86_64/aarch64 only; got ' . self::arch());
        }
        if (self::libc() !== 'glibc') {
            throw new EmbedException('ffi-zts v1 requires glibc; detected ' . self::libc());
        }
        if (!extension_loaded('ffi')) {
            throw new EmbedException('ffi-zts requires ext-ffi');
        }
    }
}
