<?php
// Fork N workers and pipe a SIZE-byte blob down to each child.
// Baseline for the §7.3 "shared-data" microbench in docs/DESIGN.md.
$N    = (int)($argv[1] ?? 4);
$SIZE = (int)($argv[2] ?? 10 * 1024 * 1024);
$blob = str_repeat("A", $SIZE);

$t0 = hrtime(true);
$pipes = $pids = [];
for ($i = 0; $i < $N; $i++) {
    $pair = [];
    socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
    $pid = pcntl_fork();
    if ($pid === 0) {
        socket_close($pair[0]);
        $buf = '';
        while (($chunk = socket_read($pair[1], 65536)) !== false && $chunk !== '') $buf .= $chunk;
        socket_close($pair[1]);
        exit(0);
    }
    socket_close($pair[1]);
    $pipes[$i] = $pair[0];
    $pids[$i]  = $pid;
}
foreach ($pipes as $i => $sock) {
    socket_write($sock, $blob);
    socket_close($sock);
    pcntl_waitpid($pids[$i], $status);
}
$dt = (hrtime(true) - $t0) / 1e6;
printf("[fork-blob] N=%d bytes=%dMB total=%.2fms\n", $N, intdiv($SIZE, 1048576), $dt);
