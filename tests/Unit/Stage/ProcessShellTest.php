<?php

declare(strict_types=1);

use FleetKit\Stage\ProcessShell;

it('runs jobs together and answers in the order given', function () {
    $job = fn (string $code) => [[PHP_BINARY, '-r', $code], null];

    $start = microtime(true);
    $results = (new ProcessShell)->runMany([
        $job('usleep(600000); echo "a"; exit(3);'),
        $job('usleep(600000); echo "b";'),
        $job('usleep(600000); echo "c"; exit(1);'),
        $job('usleep(600000); echo "d";'),
    ], 4);
    $took = microtime(true) - $start;

    expect(array_map(fn ($r) => [$r->code, $r->out], $results))->toBe([[3, 'a'], [0, 'b'], [1, 'c'], [0, 'd']])
        ->and($took)->toBeLessThan(2.2); // four 0.6s jobs one after another would take 2.4s
});

it('never runs more jobs than the limit', function () {
    $dir = sys_get_temp_dir().'/fk-conc-'.bin2hex(random_bytes(4));
    mkdir($dir);
    // Each job drops a marker, counts the markers, and removes its own on the way out.
    $code = '$d=$argv[1]; $m=$d."/".getmypid(); touch($m); usleep(300000); echo count(glob($d."/*")); unlink($m);';

    $results = (new ProcessShell)->runMany(array_fill(0, 6, [[PHP_BINARY, '-r', $code, $dir], null]), 2);
    @rmdir($dir);

    expect(max(array_map(fn ($r) => (int) $r->out, $results)))->toBeLessThanOrEqual(2);
});

it('takes SHELL out of the environment psmux runs in, so its windows open in PowerShell (fleet-kit#52)', function () {
    $base = ['PATH' => '/bin', 'SHELL' => '/bin/bash.exe'];

    expect(ProcessShell::environment(['psmux', 'new-session'], $base))->toBe(['PATH' => '/bin'])
        ->and(ProcessShell::environment(['C:\bin\psmux.exe', 'new-session'], $base))->toBe(['PATH' => '/bin'])
        ->and(ProcessShell::environment(['tmux', 'new-session'], $base))->toBeNull()
        ->and(ProcessShell::environment(['psmux', 'new-session'], ['PATH' => '/bin']))->toBeNull();
});
