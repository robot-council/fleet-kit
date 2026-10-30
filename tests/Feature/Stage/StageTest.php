<?php

declare(strict_types=1);

use FleetKit\Stage\Fleet;
use FleetKit\Stage\Holders;
use FleetKit\Stage\Launch;
use FleetKit\Stage\Result;
use FleetKit\Stage\Stager;
use Tests\Support\FakeClock;
use Tests\Support\FakeShell;
use Tests\Support\Fakes;

const MCP_OK = 'robot-council: php robot-council.php mcp - ✔ Connected';

/**
 * @param  list<array{0: list<string>, 1: Result|callable}>  $rules
 * @param  array<int, array<string, mixed>>  $sessionFiles
 * @param  ?Closure(int): void  $onSleep
 * @return array{0: Stager, 1: FakeShell, 2: ArrayObject, 3: ArrayObject}
 */
function stager(Fleet $fleet, array $rules = [], ?FakeClock $clock = null, array $sessionFiles = [], ?Closure $onSleep = null): array
{
    $home = Fakes::sessions($sessionFiles);
    // The first rule that matches answers, so a test's own rules go first.
    $shell = new FakeShell([
        ...$rules,
        [['tmux', 'has-session'], new Result(1)],
        [['psmux', 'has-session'], new Result(1)],
        [['claude', 'mcp', 'list'], new Result(0, MCP_OK)],
    ]);
    $out = new ArrayObject;
    $sleeps = new ArrayObject;

    return [
        new Stager(
            $fleet,
            $shell,
            Fakes::host('Windows', $home, transcripts: ['c-1', 'c-2']),
            new Holders($home.'/.claude/sessions', $clock ?? new FakeClock, 'Windows'),
            function (string $line) use ($out) { $out[] = $line; },
            function (int $seconds) use ($sleeps, $onSleep) {
                $sleeps[] = $seconds;
                $onSleep !== null && $onSleep(count($sleeps));
            },
        ),
        $shell,
        $out,
        $sleeps,
    ];
}

function twoSeats(string $machine = 'multiplexer = "tmux"'): Fleet
{
    return Fleet::parse(stageToml(
        seatToml('a', 'conversation = "c-1"').seatToml('b', 'after = ["a"]'),
        "[[machine]]\nname = \"m\"\n{$machine}\n",
    ));
}

/** @return list<array{0: list<string>, 1: ?string}> the calls whose multiplexer verb is $verb */
function verb(FakeShell $shell, string $verb): array
{
    return array_values(array_filter($shell->calls, fn ($c) => ($c[0][1] ?? '') === $verb));
}

it('prints each seat\'s exact command and first prompt in a dry run, and touches nothing', function () {
    $fleet = Fleet::load(__DIR__.'/../../../examples/seats.toml');
    [$stager, $shell, $out] = stager($fleet);

    $stager->dryRun($fleet->machineNamed('office'));
    $text = implode("\n", $out->getArrayCopy());

    expect($shell->calls)->toBe([])
        ->and($text)->toContain('machine office: tmux session "fleet", start = enter')
        ->toContain('multiplexer: tmux new-session -d -s fleet -n example-repo-a -c /home/example/src/example-repo')
        ->toContain('multiplexer: tmux new-window -t =fleet: -n example-repo-ci -c /home/example/src/example-repo-ci')
        ->toContain('    prompt:      '.Launch::prompt($fleet->seats[0]))
        ->toContain('    prompt:      '.Launch::prompt($fleet->seats[1]))
        ->toContain('--resume 11111111-1111-1111-1111-111111111111')
        ->toContain('--dangerously-load-development-channels server:robot-council');
});

it('prints a Windows dry run in PowerShell quoting with psmux', function () {
    $fleet = Fleet::load(__DIR__.'/../../../examples/seats.toml');
    [$stager, , $out] = stager($fleet);

    $stager->dryRun($fleet->machineNamed('desk'));
    $text = implode("\n", $out->getArrayCopy());

    expect($text)->toContain('machine desk: psmux session "fleet", start = now')
        ->toContain('psmux new-session -d -s fleet -n other-repo-primary -c C:/src/other-repo')
        ->toContain('Get-ChildItem Env:')
        ->not->toContain('env -i');
});

it('shows a when-free window as an idle shell to be typed into', function () {
    $fleet = twoSeats("multiplexer = \"tmux\"\nstart = \"when-free\"\nstart_gap = \"1m\"");
    [$stager, , $out] = stager($fleet);

    $stager->dryRun($fleet->machineNamed('m'));
    $text = implode("\n", $out->getArrayCopy());

    expect($text)->toContain('start = when-free, start_gap = 60s')
        ->toContain('then, once no live process holds the conversation: send-keys the command below');
});

it('builds one session with a window per seat, in start order', function () {
    $fleet = Fleet::parse(stageToml(
        seatToml('a', 'after = ["b"]').seatToml('b'),
        "[[machine]]\nname = \"m\"\nmultiplexer = \"tmux\"\nstart = \"now\"\n",
    ));
    [$stager, $shell] = stager($fleet);

    $code = $stager->stage($fleet->machineNamed('m'));
    $session = verb($shell, 'new-session');
    $window = verb($shell, 'new-window');

    expect($code)->toBe(0)
        ->and($session)->toHaveCount(1)->and($window)->toHaveCount(1)
        ->and($session[0][0])->toContain('some-repo-b')
        ->and($window[0][0])->toContain('some-repo-a')
        ->and(end($session[0][0]))->toStartWith('env -i HOME=');
});

it('refuses to stage when the session already exists, and creates nothing', function () {
    $fleet = twoSeats();
    [$stager, $shell, $out] = stager($fleet, [[['tmux', 'has-session'], new Result(0)]]);

    $code = $stager->stage($fleet->machineNamed('m'));

    expect($code)->toBe(1)
        ->and(verb($shell, 'new-session'))->toBe([])->and(verb($shell, 'new-window'))->toBe([])
        ->and(implode("\n", $out->getArrayCopy()))->toContain('refusing: the tmux session "fleet" already exists');
});

it('refuses on any check failure before touching the multiplexer', function () {
    $fleet = twoSeats();
    [$stager, $shell] = stager($fleet, [[['claude', 'mcp', 'list'], new Result(0, 'other: x')]]);

    expect($stager->stage($fleet->machineNamed('m')))->toBe(1)
        ->and(verb($shell, 'new-session'))->toBe([]);
});

it('holds each window at Enter in enter mode', function () {
    $fleet = twoSeats();
    [$stager, $shell] = stager($fleet);

    $stager->stage($fleet->machineNamed('m'));

    expect(end(verb($shell, 'new-session')[0][0]))->toStartWith("printf %s 'Press Enter to start m/a '; read _; env -i");
});

it('fails clearly, and removes nothing, when the multiplexer refuses a window', function () {
    $fleet = twoSeats();
    [$stager, $shell] = stager($fleet, [[['tmux', 'new-window'], new Result(1)]]);

    expect(fn () => $stager->stage($fleet->machineNamed('m')))->toThrow(RuntimeException::class, 'The session may be partly built; nothing was removed.');
    expect($shell->lines())->not->toContain('tmux kill-session -t fleet');
});

it('waits for a held conversation, then types the command and presses Enter, a gap apart', function () {
    $fleet = twoSeats("multiplexer = \"tmux\"\nstart = \"when-free\"\nstart_gap = \"30s\"");
    $clock = new FakeClock([102 => Fakes::STARTED]);
    $files = [102 => ['sessionId' => 'c-1', 'procStart' => Fakes::filetime(Fakes::STARTED)]];

    // The holder exits during the first wait.
    [$stager, $shell, $out, $sleeps] = stager($fleet, [], $clock, $files, function (int $n) use ($clock) {
        $n === 1 && $clock->starts = [];
    });

    $code = $stager->stage($fleet->machineNamed('m'));
    $a = Launch::command($fleet->seats[0], $fleet->machineNamed('m'), $fleet);
    $b = Launch::command($fleet->seats[1], $fleet->machineNamed('m'), $fleet);

    $typed = array_values(array_filter($shell->calls, fn ($c) => ($c[0][1] ?? '') === 'send-keys'));

    expect($code)->toBe(0)
        ->and($sleeps->getArrayCopy())->toBe([5, 30])
        ->and(implode("\n", $out->getArrayCopy()))->toContain("waiting: m/a's conversation is held by process 102")
        ->and(array_map(fn ($c) => array_slice($c[0], 4), $typed))->toBe([
            ['-l', $a], ['Enter'], ['-l', $b], ['Enter'],
        ])
        // Windows are created idle: no command argument after the directory.
        ->and(end(verb($shell, 'new-session')[0][0]))->toBe('/src/repo');
});

it('starts a when-free seat with no conversation at once', function () {
    $fleet = Fleet::parse(stageToml(seatToml('a'), "[[machine]]\nname = \"m\"\nmultiplexer = \"tmux\"\nstart = \"when-free\"\n"));
    [$stager, , , $sleeps] = stager($fleet);

    expect($stager->stage($fleet->machineNamed('m')))->toBe(0)->and($sleeps->getArrayCopy())->toBe([]);
});

function confirmFleet(): Fleet
{
    return Fleet::parse(stageToml(seatToml('a'), "[[machine]]\nname = \"m\"\nmultiplexer = \"tmux\"\nstart = \"now\"\nconfirm_channels = true\n"));
}

const CHANNELS_SCREEN = "  WARNING: Loading development channels\n\n  Channels: server:robot-council\n\n  ❯ 1. I am using this for local development\n    2. Exit\n";

it('answers the channels warning with its local-development option, and reads the pane again to confirm', function () {
    $screens = [CHANNELS_SCREEN, "Channels (experimental) messages from server:robot-council inject directly in this session\n"];
    $fleet = confirmFleet();
    [$stager, $shell, $out] = stager($fleet, [[['tmux', 'capture-pane'], function () use (&$screens) {
        return new Result(0, count($screens) > 1 ? array_shift($screens) : $screens[0]);
    }]]);

    $stager->stage($fleet->machineNamed('m'));
    $keys = array_values(array_filter($shell->calls, fn ($c) => ($c[0][1] ?? '') === 'send-keys'));

    expect($keys)->toHaveCount(1)
        ->and(array_slice($keys[0][0], 4))->toBe(['Enter'])
        ->and(implode("\n", $out->getArrayCopy()))->toContain('confirmed the channels prompt for m/a');
});

it('leaves the folder-trust screen for the operator and prints what it saw', function () {
    $fleet = confirmFleet();
    [$stager, $shell, $out] = stager($fleet, [[['tmux', 'capture-pane'], new Result(0, " Accessing workspace:\n\n D:\\src\n\n Quick safety check: Is this a project you created or one you trust?\n\n ❯ No, exit\n   Yes, I trust this folder\n")]]);

    $stager->stage($fleet->machineNamed('m'));
    $text = implode("\n", $out->getArrayCopy());

    expect(array_filter($shell->calls, fn ($c) => ($c[0][1] ?? '') === 'send-keys'))->toBe([])
        ->and($text)->toContain('left for the operator: m/a is waiting at a screen seat-stage will not answer')
        ->toContain('|  ❯ No, exit');
});

it('says so when no channels prompt ever appears, and answers nothing', function () {
    $fleet = confirmFleet();
    [$stager, $shell, $out, $sleeps] = stager($fleet, [[['tmux', 'capture-pane'], new Result(0, "Channels (experimental) messages from server:robot-council inject directly in this session\n")]]);

    $stager->stage($fleet->machineNamed('m'));

    expect(array_filter($shell->calls, fn ($c) => ($c[0][1] ?? '') === 'send-keys'))->toBe([])
        ->and(implode("\n", $out->getArrayCopy()))->toContain('no channels prompt seen for m/a')
        ->and(count($sleeps))->toBe(20);
});

it('does not try to confirm channels in enter mode', function () {
    $fleet = Fleet::parse(stageToml(seatToml('a'), "[[machine]]\nname = \"m\"\nmultiplexer = \"tmux\"\nconfirm_channels = true\n"));
    [$stager, $shell] = stager($fleet);

    $stager->stage($fleet->machineNamed('m'));

    expect(verb($shell, 'capture-pane'))->toBe([]);
});

it('gives up on a conversation that is never released, and says what was left unstarted', function () {
    $fleet = twoSeats("multiplexer = \"tmux\"\nstart = \"when-free\"\nstart_gap = \"1s\"");
    $clock = new FakeClock([102 => Fakes::STARTED]);
    $files = [102 => ['sessionId' => 'c-1', 'procStart' => Fakes::filetime(Fakes::STARTED)]];
    [$stager, $shell, , $sleeps] = stager($fleet, [], $clock, $files);

    expect(fn () => $stager->stage($fleet->machineNamed('m')))
        ->toThrow(RuntimeException::class, 'gave up on m/a: process 102 still holds its conversation after 3600 seconds');
    expect(count($sleeps))->toBe(720)
        ->and(array_filter($shell->calls, fn ($c) => ($c[0][1] ?? '') === 'send-keys'))->toBe([]);
});

it('says the pane could not be read, not that the prompt may be acknowledged, when capture-pane fails', function () {
    $fleet = confirmFleet();
    [$stager, $shell, $out] = stager($fleet, [[['tmux', 'capture-pane'], new Result(1)]]);

    $stager->stage($fleet->machineNamed('m'));

    expect(implode("\n", $out->getArrayCopy()))->toContain('could not read the pane for m/a (tmux capture-pane exited 1)')
        ->not->toContain('may already be acknowledged')
        ->and(array_filter($shell->calls, fn ($c) => ($c[0][1] ?? '') === 'send-keys'))->toBe([]);
});

it('answers the channels warning under psmux with the same plain targets, and no =window form', function () {
    $screens = [CHANNELS_SCREEN, "Channels (experimental) messages from server:robot-council inject directly in this session\n"];
    $fleet = Fleet::parse(stageToml(seatToml('a'), "[[machine]]\nname = \"m\"\nmultiplexer = \"psmux\"\nstart = \"now\"\nconfirm_channels = true\n"));
    [$stager, $shell, $out] = stager($fleet, [[['psmux', 'capture-pane'], function () use (&$screens) {
        return new Result(0, count($screens) > 1 ? array_shift($screens) : $screens[0]);
    }]]);

    $stager->stage($fleet->machineNamed('m'));
    $keys = array_values(array_filter($shell->calls, fn ($c) => ($c[0][1] ?? '') === 'send-keys'));
    $captures = array_values(array_filter($shell->calls, fn ($c) => ($c[0][1] ?? '') === 'capture-pane'));

    expect($captures[0][0])->toBe(['psmux', 'capture-pane', '-p', '-t', 'fleet:some-repo-a'])
        ->and($keys[0][0])->toBe(['psmux', 'send-keys', '-t', 'fleet:some-repo-a', 'Enter'])
        ->and(implode("\n", $out->getArrayCopy()))->toContain('confirmed the channels prompt for m/a');
});

it('finds the channels warning in a pane that wraps it mid-word', function () {
    // Measured in fleet-kit#4: a 36-column pane under psmux split "channels" into "chann" and "els".
    $wrapped = "  WARNING: Loading development chann\nels\n\n  Channels: server:robot-council\n\n  ❯ 1. I am using this for local de\nvelopment\n    2. Exit\n";
    $screens = [$wrapped, "Channels (experimental) messages from server:robot-council inject directly in this session\n"];
    $fleet = confirmFleet();
    [$stager, $shell, $out] = stager($fleet, [[['tmux', 'capture-pane'], function () use (&$screens) {
        return new Result(0, count($screens) > 1 ? array_shift($screens) : $screens[0]);
    }]]);

    $stager->stage($fleet->machineNamed('m'));

    expect(array_filter($shell->calls, fn ($c) => ($c[0][1] ?? '') === 'send-keys'))->toHaveCount(1)
        ->and(implode("\n", $out->getArrayCopy()))->toContain('confirmed the channels prompt for m/a');
});

it('finds the folder-trust screen when it is wrapped, and still leaves it alone', function () {
    $fleet = confirmFleet();
    [$stager, $shell, $out] = stager($fleet, [[['tmux', 'capture-pane'], new Result(0, " Quick safety check: Is this a project you created or one\n you trust? (Like your own code)\n ❯ No, exit\n   Yes, I trust this folder\n")]]);

    $stager->stage($fleet->machineNamed('m'));

    expect(array_filter($shell->calls, fn ($c) => ($c[0][1] ?? '') === 'send-keys'))->toBe([])
        ->and(implode("\n", $out->getArrayCopy()))->toContain('left for the operator');
});

it('never presses a key when the trust text is on screen with the channels warning', function () {
    $fleet = confirmFleet();
    [$stager, $shell, $out] = stager($fleet, [[['tmux', 'capture-pane'], new Result(0, " Is this a project you created or one you trust?\n ❯ No, exit\n   Yes, I trust this folder\n".CHANNELS_SCREEN)]]);

    $stager->stage($fleet->machineNamed('m'));

    expect(array_filter($shell->calls, fn ($c) => ($c[0][1] ?? '') === 'send-keys'))->toBe([])
        ->and(implode("\n", $out->getArrayCopy()))->toContain('left for the operator');
});

function mixedSeats(string $machine): Fleet
{
    return Fleet::parse(stageToml(
        seatToml('a', 'conversation = "c-1"').seatToml('b', "start = \"enter\"\nconversation = \"c-2\"\nafter = [\"a\"]").seatToml('c', 'after = ["b"]'),
        "[[machine]]\nname = \"m\"\n{$machine}\n",
    ));
}

it('creates an enter seat on a when-free machine waiting, skips it in the start loop, and does not hold the seats after it back', function () {
    $fleet = mixedSeats("multiplexer = \"tmux\"\nstart = \"when-free\"\nstart_gap = \"30s\"");
    $m = $fleet->machineNamed('m');
    // a's conversation is held until the first wait ends; b waits for the operator, c starts after a.
    $clock = new FakeClock([102 => Fakes::STARTED]);
    $files = [102 => ['sessionId' => 'c-1', 'procStart' => Fakes::filetime(Fakes::STARTED)]];
    [$stager, $shell, $out, $sleeps] = stager($fleet, [], $clock, $files, function (int $n) use ($clock) {
        $n === 1 && $clock->starts = [];
    });

    $code = $stager->stage($m);
    $typed = array_values(array_filter($shell->calls, fn ($c) => ($c[0][1] ?? '') === 'send-keys'));
    $windows = [...verb($shell, 'new-session'), ...verb($shell, 'new-window')];

    expect($code)->toBe(0)
        ->and(array_map(fn ($c) => array_slice($c[0], 4), $typed))->toBe([
            ['-l', Launch::command($fleet->seats[0], $m, $fleet)], ['Enter'],
            ['-l', Launch::command($fleet->seats[2], $m, $fleet)], ['Enter'],
        ])
        // The wait on a, then one gap between the two seats that start on their own.
        ->and($sleeps->getArrayCopy())->toBe([5, 30])
        ->and(end($windows[0][0]))->toBe('/src/repo')
        ->and(end($windows[1][0]))->toContain('Press Enter to start m/b')
        ->and(end($windows[2][0]))->toBe('/src/repo')
        ->and(implode("\n", $out->getArrayCopy()))->not->toContain('waiting: m/b');
});

it('starts a now seat at once on an enter machine, and leaves the others waiting', function () {
    $fleet = Fleet::parse(stageToml(
        seatToml('a', 'start = "now"').seatToml('b'),
        "[[machine]]\nname = \"m\"\nmultiplexer = \"tmux\"\n",
    ));
    [$stager, $shell] = stager($fleet);

    $stager->stage($fleet->machineNamed('m'));
    $windows = [...verb($shell, 'new-session'), ...verb($shell, 'new-window')];

    expect(end($windows[0][0]))->toStartWith('env -i HOME=')->not->toContain('Press Enter')
        ->and(end($windows[1][0]))->toContain('Press Enter to start m/b');
});

it('answers the channels warning only for seats that start on their own', function () {
    $fleet = mixedSeats("multiplexer = \"tmux\"\nstart = \"now\"\nconfirm_channels = true");
    [$stager, $shell] = stager($fleet, [[['tmux', 'capture-pane'], new Result(0, "Channels (experimental) messages from server:robot-council\n")]]);

    $stager->stage($fleet->machineNamed('m'));
    $captured = array_map(fn ($c) => $c[0][4], verb($shell, 'capture-pane'));

    expect(array_values(array_unique($captured)))->toBe(['=fleet:=some-repo-a', '=fleet:=some-repo-c']);
});

it('prints each seat\'s effective start mode in a dry run', function () {
    $fleet = mixedSeats("multiplexer = \"tmux\"\nstart = \"when-free\"");
    [$stager, , $out] = stager($fleet);

    $stager->dryRun($fleet->machineNamed('m'));
    $text = implode("\n", $out->getArrayCopy());

    expect($text)->toContain('[1] m/a')->toContain('work_location a, start = when-free)')
        ->toContain('work_location b, start = enter)')
        ->toContain('work_location c, start = when-free)');
});

it('does not look for the channels warning on a Codex seat', function () {
    $fleet = Fleet::parse(stageToml(seatToml('a', 'harness = "codex"'), "[[machine]]\nname = \"m\"\nmultiplexer = \"tmux\"\nstart = \"now\"\nconfirm_channels = true\n"));
    [$stager, $shell] = stager($fleet, [[['codex', 'mcp', 'list'], new Result(0, '[]')]]);

    $stager->stage($fleet->machineNamed('m'));

    expect(verb($shell, 'capture-pane'))->toBe([]);
});
