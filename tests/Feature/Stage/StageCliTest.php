<?php

declare(strict_types=1);

use FleetKit\Stage\Cli;
use FleetKit\Stage\Result;
use Tests\Support\FakeClock;
use Tests\Support\FakeShell;
use Tests\Support\Fakes;

/** @return array{0: int, 1: list<string>, 2: list<string>, 3: FakeShell} */
function seatStage(array $argv, ?string $toml = null, array $rules = [], array $missing = []): array
{
    $dir = sys_get_temp_dir().'/seat-stage-cli-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $config = $dir.'/seats.toml';
    file_put_contents($config, $toml ?? file_get_contents(__DIR__.'/../../../examples/seats.toml'));

    $out = [];
    $err = [];
    $shell = new FakeShell([...$rules, [['tmux', 'has-session'], new Result(1)], [['psmux', 'has-session'], new Result(1)]]);
    $cli = new Cli(
        out: function (string $l) use (&$out) { $out[] = $l; },
        err: function (string $l) use (&$err) { $err[] = $l; },
        shell: $shell,
        host: Fakes::host('Windows', $dir, $missing),
        clock: new FakeClock,
        sleep: fn (int $s) => null,
    );

    return [$cli->run(array_merge($argv, ['--config', $config])), $out, $err, $shell];
}

it('dry-runs the example for each platform and exits 0', function (string $machine, string $mux) {
    [$code, $out, , $shell] = seatStage(['stage', $machine, '--dry-run']);

    expect($code)->toBe(0)
        ->and($out[0])->toContain("machine {$machine}: {$mux} session")
        ->and($shell->calls)->toBe([]);
})->with([['office', 'tmux'], ['desk', 'psmux']]);

it('check exits 1 and names each failure for the example, whose conversation has no transcript', function () {
    [$code, $out] = seatStage(['check', 'office']);

    expect($code)->toBe(1)
        ->and(implode("\n", $out))->toContain('fail: office/ci: conversation 11111111-1111-1111-1111-111111111111 has no transcript')
        ->not->toContain('ok:');
});

it('check exits 0 and says so when the machine would stage', function () {
    $toml = stageToml(seatToml('a'), "[[machine]]\nname = \"m\"\nmultiplexer = \"tmux\"\n");
    [$code, $out] = seatStage(['check', 'm'], $toml, [[['claude', 'mcp', 'list'], new Result(0, 'robot-council: x - ✔ Connected')]]);

    expect($code)->toBe(0)->and($out)->toContain('ok: m would stage');
});

it('exits 2 for a bad command line or seats file', function (array $argv, ?string $toml = null) {
    [$code, , $err] = seatStage($argv, $toml);

    expect($code)->toBe(2)->and($err)->not->toBeEmpty();
})->with([
    'no command' => [[]],
    'unknown command' => [['frobnicate', 'office']],
    'no machine' => [['stage']],
    'unknown machine' => [['check', 'nowhere']],
    'bad file' => [['check', 'm'], 'channel = "nope"'],
]);

it('prints usage for --help', function () {
    [$code, $out] = seatStage(['--help']);

    expect($code)->toBe(0)->and($out[0])->toContain('seat-stage stage');
});

it('exits 1 when a step fails', function () {
    $toml = stageToml(seatToml('a'), "[[machine]]\nname = \"m\"\nmultiplexer = \"tmux\"\nstart = \"now\"\n");
    [$code, , $err] = seatStage(['stage', 'm'], $toml, [[['tmux', 'new-session'], new Result(1)], [['claude', 'mcp', 'list'], new Result(0, 'robot-council: x')]]);

    expect($code)->toBe(1)->and($err[0])->toContain('could not create the window for m/a');
});
