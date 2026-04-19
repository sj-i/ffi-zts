<?php
declare(strict_types=1);

namespace SjI\FfiZts;

/**
 * User-facing entry point.
 *
 *   $embed = FfiZts::boot('/path/to/libphp.so')
 *       ->withExtensionDir('/path/to/ext')
 *       ->withExtension(new Extension\Extension('parallel', '/path/to/parallel.so'));
 *   $embed->runScript('/path/to/script.php');
 *
 * The default libphp path resolves to the binary fetched by
 * Installer::fetchBinaries(), under vendor/sj-i/ffi-zts/bin/.
 */
final class FfiZts
{
    public static function boot(?string $libphpPath = null): Embed
    {
        $libphpPath ??= self::defaultLibphpPath();
        return new Embed(new Config(libphpPath: $libphpPath));
    }

    public static function defaultLibphpPath(): string
    {
        // vendor/sj-i/ffi-zts/src/ -> vendor/sj-i/ffi-zts/
        $base = dirname(__DIR__);
        return $base . '/bin/libphp.so';
    }
}
