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
        // Inspect what the running PHP process is actually linked
        // against, not what files happen to exist on disk. A glibc
        // host can ship the musl runtime alongside (cross toolchains,
        // a chroot-able Alpine root, etc.) without itself being a
        // musl host, and disk presence would misclassify it.
        $maps = @file_get_contents('/proc/self/maps');
        if (is_string($maps) && ($detected = self::detectLibcFromMaps($maps)) !== null) {
            return $detected;
        }
        if (function_exists('shell_exec')) {
            $out = @shell_exec('ldd --version 2>&1');
            if (is_string($out) && ($detected = self::detectLibcFromLddOutput($out)) !== null) {
                return $detected;
            }
        }
        return 'glibc';
    }

    /** @internal exposed for unit tests */
    public static function detectLibcFromMaps(string $maps): ?string
    {
        if (preg_match('#/ld-musl-[^/\s]+\.so\.\d+#', $maps) === 1) {
            return 'musl';
        }
        if (preg_match('#/(?:libc\.so\.6|libc-[\d.]+\.so|ld-linux[^/\s]*\.so\.\d+)#', $maps) === 1) {
            return 'glibc';
        }
        return null;
    }

    /** @internal exposed for unit tests */
    public static function detectLibcFromLddOutput(string $output): ?string
    {
        if (str_contains($output, 'musl')) {
            return 'musl';
        }
        if (str_contains($output, 'GLIBC') || str_contains($output, 'GNU C')) {
            return 'glibc';
        }
        return null;
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
