<?php

declare(strict_types=1);

use FleetKit\Pace\FleetPresence;
use FleetKit\Pace\ReadFailed;

/** @param list<array{0: int, 1: string, 2: string}> $answers one per page asked; the commands are recorded in $commands */
function presenceWith(array $answers, array &$commands = []): FleetPresence
{
    return new FleetPresence(function (string $command) use (&$answers, &$commands) {
        $commands[] = $command;

        return array_shift($answers) ?? [1, '', 'asked too often'];
    });
}

it('lists the machines with an active session, following the cursor', function () {
    $commands = [];
    $presence = presenceWith([[0, recorded('lanes-fleet.json'), ''], [0, recorded('lanes-fleet-2.json'), '']], $commands);

    // "Home" is lower-cased; the stale laptop session does not count.
    expect($presence->activeMachines('claude'))->toBe(['home', 'office'])
        ->and($commands)->toHaveCount(2)
        ->and($commands[0])->toContain('robot-council api GET')->toContain('/api/lanes?limit=100')->toContain('--harness=claude')->not->toContain('after=')
        ->and($commands[1])->toContain('after=88');
});

it('says what the command said when it fails', function () {
    $presence = presenceWith([[1, '', "Could not tell which harness this is.\nPass --harness."]]);

    expect(fn () => $presence->activeMachines('claude'))
        ->toThrow(ReadFailed::class, 'the robot-council command failed (exit 1): Could not tell which harness this is. Pass --harness.');
});

it('rejects an answer that is not a session list', function (string $out) {
    $presence = presenceWith([[0, $out, '']]);

    expect(fn () => $presence->activeMachines('claude'))->toThrow(ReadFailed::class);
})->with(['not json' => ['<html>'], 'no sessions' => ['{"cursor":null}'], 'sessions not a list' => ['{"sessions":"none"}']]);

it('stops on a cursor that does not fall, rather than looping', function () {
    $page = '{"sessions":[],"cursor":50}';
    $presence = presenceWith([[0, $page, ''], [0, $page, '']]);

    expect(fn () => $presence->activeMachines('claude'))->toThrow(ReadFailed::class, 'did not page');
});

it('will not put an odd harness name on a command line', function (string $harness) {
    $commands = [];
    $presence = presenceWith([], $commands);

    expect(fn () => $presence->activeMachines($harness))->toThrow(ReadFailed::class)
        ->and($commands)->toBe([]);
})->with(['space' => ['claude code'], 'shell' => ['x;rm'], 'quote' => ['a"b'], 'upper' => ['Claude'], 'empty' => ['']]);
