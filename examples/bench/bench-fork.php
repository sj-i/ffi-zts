<?php
// Fork N workers, each sums 0..ITER-1, return via AF_UNIX pipe.
// Baseline for the §7.3 microbench in docs/DESIGN.md.
$N    = (int)($argv[1] ?? 4);
$ITER = (int)($argv[2] ?? 2_000_000);

$t0 = hrtime(true);
$pipes = $pids = [];
for ($i = 0; $i < $N; $i++) {
    $pair = [];
    socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
    $pid = pcntl_fork();
    if ($pid === 0) {
        socket_close($pair[0]);
        $s = 0; for ($j = 0; $j < $ITER; $j++) $s += $j;
        socket_write($pair[1], serialize(['id' => $i, 'sum' => $s]));
        socket_close($pair[1]);
        exit(0);
    }
    socket_close($pair[1]);
    $pipes[$i] = $pair[0];
    $pids[$i]  = $pid;
}
foreach ($pipes as $i => $sock) {
    $buf = '';
    while (($chunk = socket_read($sock, 65536)) !== false && $chunk !== '') $buf .= $chunk;
    socket_close($sock);
    pcntl_waitpid($pids[$i], $status);
}
$dt = (hrtime(true) - $t0) / 1e6;
printf("[fork] N=%d iter=%d total=%.2fms per=%.2fms\n", $N, $ITER, $dt, $dt / max(1, $N));
