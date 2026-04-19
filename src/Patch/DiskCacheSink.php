<?php
declare(strict_types=1);

namespace SjI\FfiZts\Patch;

use SjI\FfiZts\Exception\InstallException;

/**
 * Materialises patched ELF bytes onto disk under a vendor cache
 * directory (default: vendor/sj-i/ffi-zts/cache/).
 *
 * Cache key embeds the SHA-256 of the produced bytes so re-running
 * with identical input is a no-op and concurrent writers cannot
 * corrupt each other's output (the rename(2) is atomic on the same
 * filesystem).
 */
final class DiskCacheSink implements PatchSink
{
    public function __construct(private readonly string $cacheDir)
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
            throw new InstallException("unable to create cache dir: {$this->cacheDir}");
        }
    }

    public function materialize(string $bytes, string $hint): string
    {
        $hash = substr(hash('sha256', $bytes), 0, 16);
        $base = pathinfo($hint, PATHINFO_FILENAME);
        $ext  = pathinfo($hint, PATHINFO_EXTENSION) ?: 'so';
        $path = sprintf('%s/%s.%s.%s', $this->cacheDir, $base, $hash, $ext);
        if (is_file($path) && filesize($path) === strlen($bytes)) {
            return $path;
        }
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $bytes) === false) {
            throw new InstallException("unable to write cache file: {$tmp}");
        }
        @chmod($tmp, 0755);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new InstallException("unable to rename {$tmp} -> {$path}");
        }
        return $path;
    }
}
