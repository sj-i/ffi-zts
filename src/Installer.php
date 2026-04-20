<?php
declare(strict_types=1);

namespace SjI\FfiZts;

use SjI\FfiZts\Exception\InstallException;

/**
 * Composer post-install / post-update hook.
 *
 * Per docs/DESIGN.md §6.3, the libphp.so artefact (~34 MB) does
 * not ship inside the Composer package. After `composer install`,
 * this hook resolves the host's PHP minor + CPU arch + libc, then
 * downloads the matching pre-built tarball from GitHub Releases
 * and extracts it under vendor/sj-i/ffi-zts/bin/.
 *
 * The hook is idempotent: if libphp.so is already present, it
 * returns immediately. To force a re-fetch, delete the bin/
 * directory and re-run `composer install`.
 *
 * Composer passes a `\Composer\Script\Event` to script handlers,
 * but we avoid a hard typehint so users without composer/composer
 * in their require-dev still load this file fine.
 */
final class Installer
{
    public static function fetchBinaries(?object $event = null): void
    {
        $binDir     = self::binDir();
        $libphpPath = $binDir . '/libphp.so';

        if (is_file($libphpPath)) {
            self::log($event, "ffi-zts: libphp.so already present at {$libphpPath}");
            return;
        }
        if (!is_dir($binDir) && !@mkdir($binDir, 0755, true) && !is_dir($binDir)) {
            throw new InstallException("unable to create {$binDir}");
        }

        Platform::assertSupported();

        $url = self::releaseUrl();
        self::log($event, "ffi-zts: fetching {$url}");

        $bytes = @file_get_contents($url);
        if ($bytes === false) {
            throw new InstallException("unable to download release asset: {$url}");
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ffi-zts-');
        @file_put_contents($tmp, $bytes);
        try {
            $phar = new \PharData($tmp);
            $phar->extractTo($binDir, null, true);
        } catch (\Throwable $e) {
            throw new InstallException("unable to extract release archive: " . $e->getMessage(), previous: $e);
        } finally {
            @unlink($tmp);
        }

        if (!is_file($libphpPath)) {
            throw new InstallException(
                "expected libphp.so under {$binDir} after extraction; check the release archive layout",
            );
        }
        self::log($event, "ffi-zts: installed libphp.so to {$libphpPath}");
    }

    public static function packageRoot(): string
    {
        return dirname(__DIR__);
    }

    public static function binDir(): string
    {
        return self::packageRoot() . '/bin';
    }

    public static function cacheDir(): string
    {
        return self::packageRoot() . '/cache';
    }

    public static function releaseUrl(): string
    {
        $php  = Platform::phpAbi();
        $arch = Platform::arch();
        $libc = Platform::libc();
        $tag   = "libphp-{$php}";
        $asset = "libphp-{$php}-{$arch}-{$libc}.tar.gz";
        return "https://github.com/sj-i/ffi-zts/releases/download/{$tag}/{$asset}";
    }

    private static function log(?object $event, string $msg): void
    {
        if ($event !== null && method_exists($event, 'getIO')) {
            $event->getIO()->write($msg);
            return;
        }
        fwrite(STDERR, $msg . "\n");
    }
}
