<?php
/**
 * test-parallel.php - runs inside the embedded ZTS interpreter.
 *
 * Verifies that:
 *  - we are actually executing in a ZTS build (PHP_ZTS == 1)
 *  - the parallel extension was loaded by the embedded ZTS instance
 *  - parallel\Runtime can spawn real OS threads and execute closures
 */

echo "== host info ==\n";
printf("PHP_VERSION = %s\n", PHP_VERSION);
printf("PHP_SAPI    = %s\n", PHP_SAPI);
printf("PHP_ZTS     = %d\n", PHP_ZTS);
printf("parallel?   = %s\n", extension_loaded('parallel') ? 'yes' : 'no');

echo "\n== main thread ==\n";
printf("tid=%d\n", function_exists('zend_thread_id') ? zend_thread_id() : -1);

echo "\n== spawning workers ==\n";
$runtimes = [];
$futures  = [];
$n        = 4;
for ($i = 0; $i < $n; $i++) {
    $runtimes[$i] = new parallel\Runtime();
    $futures[$i]  = $runtimes[$i]->run(function (int $id): array {
        $start = microtime(true);
        $sum   = 0;
        for ($j = 0; $j < 2_000_000; $j++) {
            $sum += $j;
        }
        return [
            'id'    => $id,
            'pid'   => getmypid(),
            'sum'   => $sum,
            'zts'   => PHP_ZTS,
            'ms'    => (int) ((microtime(true) - $start) * 1000),
        ];
    }, [$i]);
}

foreach ($futures as $f) {
    $r = $f->value();
    printf(
        "worker id=%d pid=%d sum=%d zts=%d took=%dms\n",
        $r['id'], $r['pid'], $r['sum'], $r['zts'], $r['ms']
    );
}
echo "done\n";
