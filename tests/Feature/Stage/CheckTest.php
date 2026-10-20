<?php

declare(strict_types=1);

use FleetKit\Stage\Checker;
use FleetKit\Stage\Fleet;
use FleetKit\Stage\Holders;
use FleetKit\Stage\Host;
use FleetKit\Stage\Report;
use FleetKit\Stage\Result;
use Tests\Support\FakeClock;
use Tests\Support\FakeShell;
use Tests\Support\Fakes;

const STARTED_AT = '2026-10-20T13:39:13+00:00';

/**
 * @param  array<int, array<string, mixed>>  $sessionFiles
 * @param  list<array{0: list<string>, 1: Result|callable}>  $rules
 */
function check(string $seatExtra = '', string $machine = 'multiplexer = "tmux"', string $top = '', ?Host $host = null, array $rules = [], array $sessionFiles = [], array $alive = [], string $platform = 'Windows'): Report
{
    $fleet = Fleet::parse(stageToml(seatToml('a', $seatExtra), "[[machine]]\nname = \"m\"\n{$machine}\n", $top));
    $home = Fakes::sessions($sessionFiles);

    // The first rule that matches answers, so a test's own rules go first.
    $shell = new FakeShell([
        ...$rules,
        [['tmux', 'has-session'], new Result(1)],
        [['psmux', 'has-session'], new Result(1)],
        [['claude', 'mcp', 'list'], new Result(0, "Checking MCP server health...\n\nrobot-council: php robot-council.php mcp - ✔ Connected\nother: x - ✔ Connected\n")],
    ]);

    return (new Checker($fleet, $shell, $host ?? Fakes::host($platform, $home, transcripts: ['c-1']), new Holders($home.'/.claude/sessions', new FakeClock($alive), $platform)))
        ->check($fleet->machineNamed('m'));
}

it('passes a machine that would stage', function () {
    $report = check('conversation = "c-1"');

    expect($report->ok())->toBeTrue()->and($report->failures)->toBe([]);
});

it('reports a missing multiplexer or harness', function () {
    $report = check(host: Fakes::host(missing: ['tmux', 'claude'], transcripts: ['c-1']));

    expect($report->failures)->toContain('tmux is not on PATH')->toContain('claude is not on PATH');
});

it('refuses when the multiplexer session already exists', function () {
    $report = check(rules: [[['tmux', 'has-session'], new Result(0)]]);

    expect($report->failures)->toContain('the tmux session "fleet" already exists; staging refuses to touch it');
});

it('reports a checkout that is not there and a conversation with no transcript', function () {
    $report = check('conversation = "c-9"', host: Fakes::host(directories: false, transcripts: ['c-1']));

    expect($report->failures)->toContain('m/a: the checkout /src/repo does not exist')
        ->toContain('m/a: conversation c-9 has no transcript under ~/.claude/projects');
});

it('reports a conversation a live process already holds', function () {
    $report = check(
        'conversation = "c-1"',
        sessionFiles: [101 => ['sessionId' => 'c-1', 'procStart' => Fakes::filetime(STARTED_AT)]],
        alive: [101 => STARTED_AT],
    );

    expect($report->failures)->toContain('m/a: conversation c-1 is already held by process 101');
});

it('reports a channel server that is not in `claude mcp list`', function () {
    $report = check(top: 'channel = "server:missing"');

    expect($report->failures)->toContain('m/a: the channel server "missing" is not in `claude mcp list`');
});

it('reports an entry whose --project or --harness disagrees with the seats file', function () {
    $entry = "robot-council: php rc.php mcp --project other/repo --harness codex - ✔ Connected\n";
    $report = check(rules: [[['claude', 'mcp', 'list'], new Result(0, $entry)]]);

    expect($report->failures)
        ->toContain('m/a: the "robot-council" entry has --project other/repo, the seats file says org/some.repo')
        ->toContain('m/a: the "robot-council" entry has --harness codex, the seats file says claude');
});

it('accepts an entry whose flags agree, or that sets none', function () {
    $agree = "robot-council: php rc.php mcp --project=org/some.repo --harness claude - ✔ Connected\n";

    expect(check(rules: [[['claude', 'mcp', 'list'], new Result(0, $agree)]])->ok())->toBeTrue();
});

it('runs `claude mcp list` in each seat\'s checkout', function () {
    $fleet = Fleet::parse(stageToml());
    $shell = new FakeShell([[['tmux', 'has-session'], new Result(1)], [['claude', 'mcp', 'list'], new Result(0, 'robot-council: x - ✔ Connected')]]);
    $home = Fakes::sessions([]);

    (new Checker($fleet, $shell, Fakes::host(home: $home), new Holders($home.'/.claude/sessions', new FakeClock, 'Windows')))->check($fleet->machineNamed('m'));

    expect($shell->calls[array_key_last($shell->calls)])->toBe([['claude', 'mcp', 'list'], '/src/repo']);
});

it('reports a `claude mcp list` that fails', function () {
    $report = check(rules: [[['claude', 'mcp', 'list'], new Result(1)]]);

    expect($report->failures)->toContain('m/a: `claude mcp list` failed in /src/repo');
});

it('does not check the server of a plugin channel', function () {
    $report = check(top: 'channel = "plugin:thing@market"');

    expect($report->ok())->toBeTrue()->and($report->notes)->toContain('m/a: the channel is a plugin entry; its MCP server was not checked');
});

it('accepts confirm_channels under psmux, whose capture-pane was measured in fleet-kit#4', function () {
    $report = check(machine: "multiplexer = \"psmux\"\nconfirm_channels = true\nstart = \"now\"");

    expect($report->ok())->toBeTrue();
});

it('notes that confirm_channels has no effect in enter mode', function () {
    $report = check(machine: "multiplexer = \"tmux\"\nconfirm_channels = true");

    expect($report->ok())->toBeTrue()->and($report->notes[0])->toContain('start = "enter"');
});

it('refuses when-free where the session files cannot be read, and only notes it otherwise', function () {
    $whenFree = check(machine: "multiplexer = \"tmux\"\nstart = \"when-free\"", platform: 'Linux');
    $enter = check(platform: 'Linux');

    expect($whenFree->failures[0])->toContain('start = "when-free" needs the session files')
        ->and($enter->ok())->toBeTrue()
        ->and($enter->notes[0])->toContain('cannot tell whether a live process already holds a conversation on Linux');
});

it('reports a machine with no seats', function () {
    $fleet = Fleet::parse("[[machine]]\nname = \"m\"\n[[machine]]\nname = \"empty\"\n".seatToml());
    $home = Fakes::sessions([]);

    $report = (new Checker($fleet, new FakeShell, Fakes::host(home: $home), new Holders($home.'/.claude/sessions', new FakeClock, 'Windows')))->check($fleet->machineNamed('empty'));

    expect($report->failures)->toContain('empty: no seats are listed for this machine');
});

it('changes nothing: it only asks the multiplexer whether a session exists and lists servers', function () {
    $fleet = Fleet::parse(stageToml());
    $shell = new FakeShell([[['tmux', 'has-session'], new Result(1)]]);
    $home = Fakes::sessions([]);

    (new Checker($fleet, $shell, Fakes::host(home: $home), new Holders($home.'/.claude/sessions', new FakeClock, 'Windows')))->check($fleet->machineNamed('m'));

    expect($shell->lines())->toBe(['tmux has-session -t =fleet', 'claude mcp list']);
});
