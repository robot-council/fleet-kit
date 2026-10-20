<?php

declare(strict_types=1);

use FleetKit\Stage\Fleet;
use FleetKit\Stage\Launch;

function launchFleet(string $seatExtra = '', string $machine = 'multiplexer = "tmux"'): Fleet
{
    return Fleet::parse(stageToml(seatToml('a', $seatExtra), "[[machine]]\nname = \"m\"\n{$machine}\n"));
}

it('asks a new seat to join, with every field it declares', function () {
    $fleet = launchFleet('capacity = 2');

    expect(Launch::prompt($fleet->seats[0]))->toBe(
        'Join the Robot Council fleet: call the `join` tool of the robot-council server with role: build, repository: org/some.repo, work_location: a, capacity: 2.',
    );
});

it('leaves capacity out when it is not declared', function () {
    expect(Launch::prompt(launchFleet()->seats[0]))->not->toContain('capacity');
});

it('tells a resumed seat to heartbeat and never to leave', function () {
    $prompt = Launch::prompt(launchFleet('conversation = "c-1"')->seats[0]);

    expect($prompt)->toContain('`presence_heartbeat`')
        ->toContain('`session_move` with repository: org/some.repo, work_location: a')
        ->toContain('Do not call `leave` or `join` again')
        ->not->toContain('call the `join` tool');
});

it('appends the seat\'s own prompt', function () {
    $prompt = Launch::prompt(launchFleet('prompt = "Take work   only by placement."')->seats[0]);

    expect($prompt)->toEndWith('work_location: a. Take work only by placement.');
});

it('puts the prompt first and only the tagged channel after the flag', function () {
    $fleet = launchFleet('conversation = "c-1"');
    $arguments = Launch::arguments($fleet->seats[0], $fleet->machineNamed('m'), $fleet);

    expect($arguments[0])->toBe(Launch::prompt($fleet->seats[0]))
        ->and(array_slice($arguments, 1))->toBe([
            '--resume', 'c-1',
            '--remote-control', 'some.repo/m/a',
            '--dangerously-load-development-channels', 'server:robot-council',
        ]);
    expect(end($arguments))->toBe('server:robot-council');
});

it('leaves out --remote-control when the machine turns it off, and --resume when there is no conversation', function () {
    $fleet = launchFleet('', "multiplexer = \"tmux\"\nremote_control = false");
    $arguments = Launch::arguments($fleet->seats[0], $fleet->machineNamed('m'), $fleet);

    expect($arguments)->not->toContain('--remote-control')->not->toContain('--resume');
});

it('runs a POSIX window in a clean environment', function () {
    $fleet = launchFleet();
    $command = Launch::command($fleet->seats[0], $fleet->machineNamed('m'), $fleet);

    expect($command)->toStartWith('env -i HOME="$HOME" PATH="$PATH"')
        ->toContain("claude 'Join the Robot Council fleet")
        ->toEndWith('--dangerously-load-development-channels server:robot-council');
});

it('scrubs the variables a parent session leaks on Windows, where there is no env -i', function () {
    $fleet = launchFleet('', 'multiplexer = "psmux"');
    $command = Launch::command($fleet->seats[0], $fleet->machineNamed('m'), $fleet);

    expect($command)->toStartWith('Get-ChildItem Env:')
        ->toContain('CLAUDE_CODE_|CLAUDECODE$|CLAUDE_PID$')
        ->not->toContain('env -i')
        ->toContain("; claude 'Join the Robot Council fleet");
});

it('holds an enter-mode window until Enter is pressed', function () {
    $fleet = launchFleet();
    $seat = $fleet->seats[0];

    expect(Launch::waiting('RUN', $seat, $fleet->machineNamed('m')))->toBe("printf %s 'Press Enter to start m/a '; read _; RUN");

    $windows = launchFleet('', 'multiplexer = "psmux"');
    expect(Launch::waiting('RUN', $windows->seats[0], $windows->machineNamed('m')))->toBe("Read-Host 'Press Enter to start m/a' | Out-Null; RUN");
});

it('quotes for each shell', function () {
    expect(Launch::posix("it's"))->toBe("'it'\\''s'")
        ->and(Launch::posix('plain-text_1.2/x'))->toBe('plain-text_1.2/x')
        ->and(Launch::powershell("it's"))->toBe("'it''s'")
        ->and(Launch::powershell('C:\\src\\repo'))->toBe('C:\\src\\repo');
});
