<?php
// One parallel\Runtime reused across M tasks. Measures steady-state
// per-task cost once spawn has amortised.
$M    = (int)(getenv('M')    ?: 16);
$ITER = (int)(getenv('ITER') ?: 2_000_000);

$rt = new parallel\Runtime();
$rt->run(function () { return 1; })->value();  // warm-up

$t0 = hrtime(true);
$fs = [];
for ($i = 0; $i < $M; $i++) {
    $fs[$i] = $rt->run(function (int $id, int $iter): int {
        $s = 0; for ($j = 0; $j < $iter; $j++) $s += $j;
        return $s;
    }, [$i, $ITER]);
}
foreach ($fs as $f) $f->value();
$dt = (hrtime(true) - $t0) / 1e6;
printf("[parallel-reuse] M=%d iter=%d total=%.2fms per=%.2fms\n", $M, $ITER, $dt, $dt / max(1, $M));
