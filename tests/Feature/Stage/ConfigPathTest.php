<?php

declare(strict_types=1);

use FleetKit\ConfigLocator;
use FleetKit\Pace\Cli as PaceCli;
use FleetKit\Stage\Cli;
use FleetKit\Stage\Result;
use FleetKit\Stage\SeatsRepo;
use Tests\Support\FakeClock;
use Tests\Support\FakeShell;
use Tests\Support\Fakes;

/** A scratch directory holding a seats file and a place to remember its path. */
function configScratch(): array
{
    $made = strtr(sys_get_temp_dir(), [chr(92) => '/']).'/seat-stage-config-'.bin2hex(random_bytes(4));
    mkdir($made);
    $dir = realpath($made);
    $seats = $dir.'/seats.toml';
    file_put_contents($seats, stageToml(seatToml('a'), "[[machine]]\nname = \"m\"\nmultiplexer = \"tmux\"\n"));

    return [$dir, $seats, new ConfigLocator($dir.'/user/seats-path')];
}

/** @return array{0: int, 1: list<string>, 2: list<string>, 3: FakeShell} */
function stageWith(array $argv, ConfigLocator $locator, ?string $env = null, array $rules = [], bool $repo = false, ?string $cwd = null): array
{
    $out = [];
    $err = [];
    $shell = new FakeShell([...$rules, [['tmux', 'has-session'], new Result(1)]]);
    $cli = new Cli(
        out: function (string $l) use (&$out) { $out[] = $l; },
        err: function (string $l) use (&$err) { $err[] = $l; },
        shell: $shell,
        host: Fakes::host('Windows', sys_get_temp_dir()),
        clock: new FakeClock,
        sleep: fn (int $s) => null,
        locator: $locator,
        repo: $repo ? new SeatsRepo($shell) : null,
    );
    $before = getcwd();
    $cwd !== null && chdir($cwd);
    try {
        $code = $cli->run($argv, $env);
    } finally {
        chdir($before);
    }

    return [$code, $out, $err, $shell];
}

it('finds the seats file from any directory after one `config set`, with no variable and no --config', function () {
    [$dir, $seats, $locator] = configScratch();
    $elsewhere = strtr(sys_get_temp_dir(), [chr(92) => '/']);

    [$before, , $beforeErr] = stageWith(['stage', 'm', '--dry-run'], $locator, cwd: $elsewhere);
    [$set, $setOut] = stageWith(['config', 'set', $seats], $locator);
    [$after, $out, $afterErr] = stageWith(['stage', 'm', '--dry-run'], $locator, cwd: $elsewhere);

    expect($before)->toBe(2)
        ->and($beforeErr[0])->toContain('seats file not found: seats.toml')->toContain('seat-stage config set')
        ->and($set)->toBe(0)
        ->and($setOut[0])->toContain('remembered: '.realpath($seats))
        ->and($after)->toBe(0)
        ->and($afterErr)->toBe([])
        ->and($out[0])->toContain('machine m');
});

it('reads --config before FLEET_KIT_CONFIG before the remembered path, and `config show` says which won', function () {
    [$dir, $seats, $locator] = configScratch();
    stageWith(['config', 'set', $seats], $locator);

    [, $remembered] = stageWith(['config', 'show'], $locator);
    [, $env] = stageWith(['config', 'show'], $locator, env: '/env/seats.toml');
    [, $flag] = stageWith(['config', 'show', '--config', '/flag/seats.toml'], $locator, env: '/env/seats.toml');

    expect($remembered[0])->toBe('seats file: '.realpath($seats))
        ->and($remembered[1])->toContain('from: the path remembered by `seat-stage config set`')
        ->and($remembered[2])->toBe('exists: yes')
        ->and($env[0])->toBe('seats file: /env/seats.toml')
        ->and($env[1])->toBe('from: FLEET_KIT_CONFIG')
        ->and($env[2])->toBe('exists: no')
        ->and($env[3])->toBe('remembered: '.realpath($seats))
        ->and($flag[0])->toBe('seats file: /flag/seats.toml')
        ->and($flag[1])->toBe('from: --config');
});

it('says the default is in force when nothing is remembered, and `config unset` forgets', function () {
    [$dir, $seats, $locator] = configScratch();

    [, $none] = stageWith(['config', 'show'], $locator);
    stageWith(['config', 'set', $seats], $locator);
    [, $forgot] = stageWith(['config', 'unset'], $locator);
    [, $again] = stageWith(['config', 'unset'], $locator);

    expect($none[1])->toBe('from: the default, seats.toml in the current directory')
        ->and($none[3])->toBe('remembered: nothing')
        ->and($forgot)->toBe(['forgot the remembered path'])
        ->and($again)->toBe(['nothing was remembered']);
});

it('warns, when `config set` is overridden by the environment, that the variable is read first', function () {
    [$dir, $seats, $locator] = configScratch();

    [, $out] = stageWith(['config', 'set', $seats], $locator, env: '/env/seats.toml');

    expect(implode("\n", $out))->toContain('FLEET_KIT_CONFIG is set and is read first');
});

it('refuses a bad `config` command line with status 2', function (array $argv) {
    [, , $locator] = configScratch();
    [$code, , $err] = stageWith($argv, $locator);

    expect($code)->toBe(2)->and($err[0])->toContain('seat-stage config set');
})->with([
    'no action' => [['config']],
    'unknown action' => [['config', 'move']],
    'set without a path' => [['config', 'set']],
    'set with two paths' => [['config', 'set', 'a', 'b']],
]);

it('reports a seats file whose repository is behind its upstream, on check and on a dry run, without pulling', function (array $argv) {
    [$dir, $seats, $locator] = configScratch();
    $rules = [
        [['git', '-C', $dir, 'rev-parse'], new Result(0, "true\n")],
        [['git', '-C', $dir, 'rev-list'], new Result(0, "4\n")],
        [['claude', 'mcp', 'list'], new Result(0, 'robot-council: x - ✔ Connected')],
    ];
    [, $out, , $shell] = stageWith([...$argv, '--config', $seats], $locator, rules: $rules, repo: true);

    expect($out[0])->toContain("note: the seats file's repository ({$dir}) is 4 commit(s) behind its upstream")
        ->toContain('pull --ff-only')->toContain('--pull');
    expect(implode("\n", $shell->lines()))->not->toContain(' pull --ff-only');
    expect(implode("\n", $shell->lines()))->toContain('fetch --quiet');
})->with([
    'check' => [['check', 'm']],
    'stage --dry-run' => [['stage', 'm', '--dry-run']],
]);

it('says nothing when the repository is current, has no upstream, or the file is not in one', function (Result $inside, Result $count) {
    [$dir, $seats, $locator] = configScratch();
    $rules = [[['git', '-C', $dir, 'rev-parse'], $inside], [['git', '-C', $dir, 'rev-list'], $count]];
    [$code, $out] = stageWith(['stage', 'm', '--dry-run', '--config', $seats], $locator, rules: $rules, repo: true);

    expect($code)->toBe(0)->and(implode("\n", $out))->not->toContain('behind');
})->with([
    'current' => [new Result(0, "true\n"), new Result(0, "0\n")],
    'no upstream' => [new Result(0, "true\n"), new Result(128)],
    'not a work tree' => [new Result(128), new Result(0, "9\n")],
]);

it('pulls fast-forward only, before reading the file, when asked with --pull', function () {
    [$dir, $seats, $locator] = configScratch();
    $rules = [
        [['git', '-C', $dir, 'rev-parse'], new Result(0, "true\n")],
        [['git', '-C', $dir, 'rev-list'], new Result(0, "2\n")],
    ];
    [$code, $out, , $shell] = stageWith(['stage', 'm', '--dry-run', '--pull', '--config', $seats], $locator, rules: $rules, repo: true);

    expect($code)->toBe(0)
        ->and($out[0])->toContain("pulled 2 commit(s) into the seats file's repository")
        ->and($shell->lines())->toContain("git -C {$dir} pull --ff-only");
});

it('does not pull when --pull is not given, and does not pull a repository that is current', function () {
    [$dir, $seats, $locator] = configScratch();
    $rules = [[['git', '-C', $dir, 'rev-parse'], new Result(0, "true\n")], [['git', '-C', $dir, 'rev-list'], new Result(0, "0\n")]];
    [, $out, , $shell] = stageWith(['stage', 'm', '--dry-run', '--pull', '--config', $seats], $locator, rules: $rules, repo: true);

    expect(implode("\n", $shell->lines()))->not->toContain('pull');
    expect($out[0])->toContain('machine m');
});

it('stops with status 1, saying the file was not updated, when the pull is not a fast-forward', function () {
    [$dir, $seats, $locator] = configScratch();
    $rules = [
        [['git', '-C', $dir, 'rev-parse'], new Result(0, "true\n")],
        [['git', '-C', $dir, 'rev-list'], new Result(0, "2\n")],
        [['git', '-C', $dir, 'pull'], new Result(128)],
    ];
    [$code, , $err] = stageWith(['check', 'm', '--pull', '--config', $seats], $locator, rules: $rules, repo: true);

    expect($code)->toBe(1)->and($err[0])->toContain('git pull --ff-only failed')->toContain('was not updated');
});

it('refuses --pull for a file outside a work tree, and for collect', function (array $argv, Result $inside, string $message) {
    [$dir, $seats, $locator] = configScratch();
    [$code, , $err] = stageWith([...$argv, '--config', $seats], $locator, rules: [[['git', '-C', $dir, 'rev-parse'], $inside]], repo: true);

    expect($code)->toBe(2)->and($err[0])->toContain($message);
})->with([
    'not in a work tree' => [['check', 'm', '--pull'], new Result(128), 'needs the seats file to be in a git work tree'],
    'collect' => [['collect', 'm', '--pull'], new Result(0, "true\n"), '--pull is for check and stage'],
]);

it('lets seat-pace find the remembered seats file too, and names the one-time command when it cannot', function () {
    [$dir, $seats, $locator] = configScratch();
    file_put_contents($seats, accountToml('working'));
    $run = function (array $argv) use ($locator) {
        $out = [];
        $err = [];
        $cli = new PaceCli(
            out: function (string $l) use (&$out) { $out[] = $l; },
            err: function (string $l) use (&$err) { $err[] = $l; },
            reader: new FleetKit\Pace\UnsupportedReader,
            clock: fn () => new DateTimeImmutable('2026-10-21T12:00:00-05:00'),
            shell: fn (string $c, array $e) => '',
            sleep: fn (int $s) => null,
            machine: 'office',
            locator: $locator,
        );

        return [$cli->run($argv), $out, $err];
    };

    [$before, , $beforeErr] = $run(['pace', '--used', '40', '--now', '2026-10-21T12:00:00-05:00']);
    $locator->remember($seats);
    [$after, $out] = $run(['pace', '--used', '40', '--now', '2026-10-21T12:00:00-05:00']);

    expect($before)->toBe(2)->and($beforeErr[0])->toContain('seat-stage config set')
        ->and($after)->toBe(0)->and($out[0])->toStartWith('UNDER');
});
