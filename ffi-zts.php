#!/usr/bin/env php
<?php
/**
 * ffi-zts.php -- backwards-compatible CLI shim.
 *
 * The original spike loader has graduated into the SjI\FfiZts\Embed
 * class (see src/Embed.php) and the SjI\FfiZts\FfiZts facade. This
 * file remains as a one-shot script runner so the README and
 * docs/DESIGN.md examples keep working without changes.
 *
 * Usage:
 *   php ffi-zts.php --libphp-path=/path/to/libphp.so [--ini=<file>] script.php
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Exception/EmbedException.php';
require_once __DIR__ . '/src/Exception/ElfException.php';
require_once __DIR__ . '/src/Exception/InstallException.php';
require_once __DIR__ . '/src/Platform.php';
require_once __DIR__ . '/src/Extension/Extension.php';
require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/IniBuilder.php';
require_once __DIR__ . '/src/Embed.php';
require_once __DIR__ . '/src/FfiZts.php';

use SjI\FfiZts\Config;
use SjI\FfiZts\Embed;

$opt = getopt('', ['libphp-path:', 'ini:'], $rest);
$libphpPath = $opt['libphp-path'] ?? null;
$iniPath    = $opt['ini'] ?? null;
$script     = $argv[$rest] ?? null;

if ($libphpPath === null || $script === null) {
    fwrite(STDERR, "usage: php ffi-zts.php --libphp-path=<so> [--ini=<file>] <script.php>\n");
    exit(1);
}

$embed = new Embed(new Config(libphpPath: $libphpPath, iniPath: $iniPath));
$embed->runScript($script);
