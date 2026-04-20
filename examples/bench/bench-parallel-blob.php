<?php
// N parallel\Runtime workers read a shared FFI-allocated blob by
// address (zero-copy). Run inside the embed via run-embed-ffi.php
// (which sets ffi.enable=true).
$N    = (int)(getenv('N')    ?: 4);
$SIZE = (int)(getenv('SIZE') ?: 10 * 1024 * 1024);

$ffi = FFI::cdef('', 'libc.so.6');
$buf = $ffi->new("uint8_t[$SIZE]", false);
$bufAddr = FFI::cast('intptr_t', FFI::addr($buf))->cdata;

$t0 = hrtime(true);
$rts = $fs = [];
for ($i = 0; $i < $N; $i++) {
    $rts[$i] = new parallel\Runtime();
    $fs[$i]  = $rts[$i]->run(function (int $addr, int $size): int {
        $p = FFI::cast('uint8_t*', $addr);
        $s = 0; for ($k = 0; $k < $size; $k += 4096) $s += $p[$k];
        return $s;
    }, [$bufAddr, $SIZE]);
}
foreach ($fs as $f) $f->value();
FFI::free($buf);
$dt = (hrtime(true) - $t0) / 1e6;
printf("[parallel-blob] N=%d bytes=%dMB total=%.2fms\n", $N, intdiv($SIZE, 1048576), $dt);
