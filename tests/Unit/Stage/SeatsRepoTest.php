<?php

declare(strict_types=1);

use FleetKit\Stage\Result;
use FleetKit\Stage\SeatsRepo;
use Tests\Support\FakeShell;

it('finds the work tree a seats file is in, and says nothing about a file that is not in one', function () {
    $dir = strtr(sys_get_temp_dir(), [chr(92) => '/']);
    $in = new FakeShell([[['git', '-C', $dir, 'rev-parse'], new Result(0, "true\n")]]);
    $out = new FakeShell([[['git', '-C', $dir, 'rev-parse'], new Result(128)]]);

    expect((new SeatsRepo($in))->workTree($dir.'/seats.toml'))->toBe(rtrim(dirname($dir.'/seats.toml')))
        ->and((new SeatsRepo($out))->workTree($dir.'/seats.toml'))->toBeNull();
});

it('fetches, then counts the commits it is behind its upstream', function () {
    $shell = new FakeShell([[['git', '-C', '/r', 'rev-list'], new Result(0, "3\n")]]);

    expect((new SeatsRepo($shell))->behind('/r'))->toBe(3)
        ->and($shell->lines())->toBe(['git -C /r fetch --quiet', 'git -C /r rev-list --count HEAD..@{u}']);
});

it('answers null when git cannot say, and still counts when the fetch fails', function () {
    $noUpstream = new FakeShell([[['git', '-C', '/r', 'rev-list'], new Result(128)]]);
    $offline = new FakeShell([[['git', '-C', '/r', 'fetch'], new Result(1)], [['git', '-C', '/r', 'rev-list'], new Result(0, "2\n")]]);

    expect((new SeatsRepo($noUpstream))->behind('/r'))->toBeNull()
        ->and((new SeatsRepo($offline))->behind('/r'))->toBe(2);
});

it('pulls fast-forward only', function () {
    $shell = new FakeShell;
    (new SeatsRepo($shell))->pull('/r');

    expect($shell->lines())->toBe(['git -C /r pull --ff-only']);
});

it('runs a fetch with git prompts off and puts the environment back', function () {
    $seen = [];
    SeatsRepo::withoutPrompts(function () use (&$seen) {
        $seen = [getenv('GIT_TERMINAL_PROMPT'), getenv('GIT_SSH_COMMAND')];
    });

    expect($seen)->toBe(['0', 'ssh -o BatchMode=yes'])
        ->and(getenv('GIT_TERMINAL_PROMPT'))->toBeFalse()
        ->and(getenv('GIT_SSH_COMMAND'))->toBeFalse();
});
