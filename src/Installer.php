<?php
declare(strict_types=1);

namespace SjI\FfiZts;

use SjI\FfiZts\Exception\InstallException;

/**
 * Fetches the per-host libphp.so artefact into
 * vendor/sj-i/ffi-zts/bin/.
 *
 * Normally invoked from SjI\FfiZts\ComposerPlugin in response to
 * POST_PACKAGE_INSTALL / POST_PACKAGE_UPDATE. Also callable
 * directly via `vendor/bin/ffi-zts install` for manual retries
 * and for environments with plugins disabled (`--no-plugins`).
 *
 * Per docs/DESIGN.md §6.3, the libphp.so artefact (~34 MB) does
 * not ship inside the Composer package; this hook resolves the
 * host's PHP minor + CPU arch + libc, downloads the matching
 * pre-built tarball from GitHub Releases, and extracts it. The
 * hook is idempotent: if libphp.so is already present, it
 * returns immediately. To force a re-fetch, delete the bin/
 * directory and re-run install.
 *
 * Composer passes a `\Composer\Script\Event` (or
 * `\Composer\Installer\PackageEvent`) to the plugin callback; we
 * avoid a hard typehint so users without composer/composer in
 * their require-dev still load this file fine.
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

        // PharData detects the archive format from the file
        // extension; tempnam() gives us a bare name, so attach
        // .tar.gz before handing the path to PharData.
        $tmpBase = tempnam(sys_get_temp_dir(), 'ffi-zts-');
        if ($tmpBase === false) {
            throw new InstallException('unable to allocate temp file for release archive');
        }
        $tmp = $tmpBase . '.tar.gz';
        if (!@rename($tmpBase, $tmp)) {
            @unlink($tmpBase);
            throw new InstallException("unable to stage release archive at {$tmp}");
        }
        if (@file_put_contents($tmp, $bytes) === false) {
            @unlink($tmp);
            throw new InstallException("unable to write release archive to {$tmp}");
        }
        try {
            $phar = new \PharData($tmp);
            $phar->extractTo($binDir, null, true);
        } catch (\Throwable $e) {
            throw new InstallException(
                'unable to extract release archive: ' . $e->getMessage(),
                previous: $e,
            );
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
