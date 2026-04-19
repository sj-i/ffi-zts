<?php
declare(strict_types=1);

namespace SjI\FfiZts\Patch;

/**
 * Strategy for materialising patched ELF bytes into something the
 * dynamic loader can dlopen().
 *
 * Two implementations ship with the design (docs/DESIGN.md §5.5):
 *   - DiskCacheSink: writes under vendor/.../cache/, default for
 *     normal usage; benefits from page-cache sharing between FPM
 *     workers and is debuggable with readelf.
 *   - MemfdSink: writes to memfd_create(2) and returns
 *     /proc/self/fd/N; for read-only environments and runtime
 *     overrides via withExtension('/path/my-parallel.so').
 */
interface PatchSink
{
    /**
     * Materialise $bytes and return a path suitable for dlopen().
     *
     * $hint is a human-readable basename (e.g. "parallel.so") used
     * for cache filenames, memfd labels, and error messages. It is
     * not security-sensitive.
     */
    public function materialize(string $bytes, string $hint): string;
}
