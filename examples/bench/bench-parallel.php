<?php
// Spawn N fresh parallel\Runtime instances, each sums 0..ITER-1.
// Run inside the embed via run-embed.php.
$N    = (int)(getenv('N')    ?: 4);
$ITER = (int)(getenv('ITER') ?: 2_000_000);

$t0 = hrtime(true);
$rts = $fs = [];
for ($i = 0; $i < $N; $i++) {
    $rts[$i] = new parallel\Runtime();
    $fs[$i]  = $rts[$i]->run(function (int $id, int $iter): array {
        $s = 0; for ($j = 0; $j < $iter; $j++) $s += $j;
        return ['id' => $id, 'sum' => $s];
    }, [$i, $ITER]);
}
foreach ($fs as $f) $f->value();
$dt = (hrtime(true) - $t0) / 1e6;
printf("[parallel-inside] N=%d iter=%d total=%.2fms per=%.2fms\n", $N, $ITER, $dt, $dt / max(1, $N));
