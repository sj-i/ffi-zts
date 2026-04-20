<?php
declare(strict_types=1);

namespace SjI\FfiZts\Patch;

use FFI;
use SjI\FfiZts\Exception\EmbedException;

/**
 * Materialises patched ELF bytes to an in-memory file via
 * memfd_create(2) and returns /proc/self/fd/N for dlopen().
 *
 * Held fds are kept open for the lifetime of the sink; closing them
 * before dlopen-time would invalidate the /proc/self/fd path. Long
 * after dlopen has its own internal fd, the loader's reference is
 * what keeps the mapping alive, so the sink fd can be closed -- but
 * keeping it makes diagnostics (ls -l /proc/self/fd) cleaner.
 */
final class MemfdSink implements PatchSink
{
    private FFI $libc;
    /** @var list<int> */
    private array $fds = [];

    public function __construct()
    {
        $this->libc = FFI::cdef(<<<EOD
            int  memfd_create(const char *name, unsigned int flags);
            long write(int fd, const void *buf, unsigned long count);
            int  close(int fd);
        EOD, 'libc.so.6');
    }

    public function materialize(string $bytes, string $hint): string
    {
        $label = substr('ffi-zts/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $hint), 0, 249);
        $fd = $this->libc->memfd_create($label, 0);
        if ($fd < 0) {
            throw new EmbedException("memfd_create failed for {$label}");
        }
        $size = strlen($bytes);
        $buf  = FFI::new("uint8_t[{$size}]", false);
        FFI::memcpy($buf, $bytes, $size);
        $wrote = $this->libc->write($fd, $buf, $size);
        FFI::free($buf);
        if ($wrote !== $size) {
            $this->libc->close($fd);
            throw new EmbedException("short write to memfd: {$wrote} of {$size}");
        }
        $this->fds[] = $fd;
        return "/proc/self/fd/{$fd}";
    }

    public function __destruct()
    {
        foreach ($this->fds as $fd) {
            $this->libc->close($fd);
        }
    }
}
