<?php
// Host-side entry point for the embed-bound benchmarks. Boots the
// embed, executes $argv[1] inside it, and prints host-visible
// boot / run / total numbers.
require __DIR__ . '/../../vendor/autoload.php';

use SjI\FfiZts\Parallel\Parallel;

$script = $argv[1] ?? null;
if ($script === null || !is_file($script)) {
    fwrite(STDERR, "usage: php run-embed.php <inner-script.php>\n");
    exit(2);
}

$t0 = hrtime(true);
$embed = Parallel::boot();
$embed->boot();
$bootMs = (hrtime(true) - $t0) / 1e6;

$t1 = hrtime(true);
$embed->executeScript($script);
$runMs = (hrtime(true) - $t1) / 1e6;

$embed->shutdown();
printf("[host] boot=%.2fms run=%.2fms total=%.2fms\n", $bootMs, $runMs, $bootMs + $runMs);
