<?php
declare(strict_types=1);

namespace SjI\FfiZts\Extension;

/**
 * Descriptor for an external ZTS extension that the embed should load
 * via extension= / zend_extension= ini entries.
 *
 * `$path` is the resolved filesystem (or /proc/self/fd/N) path to the
 * .so -- i.e. the already-patched artefact if the extension needed a
 * DT_NEEDED added. See SjI\FfiZts\Elf\ElfPatcher.
 */
final class Extension
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly bool $isZendExtension = false,
    ) {
    }
}
