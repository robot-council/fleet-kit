<?php

declare(strict_types=1);

use FleetKit\Pace\ConfigError;
use FleetKit\Stage\Fleet;

function stageToml(string $seats = '', string $machines = '', string $top = ''): string
{
    return $top."\n".($machines ?: "[[machine]]\nname = \"m\"\nmultiplexer = \"tmux\"\n")."\n".($seats ?: seatToml());
}

function seatToml(string $slot = 'a', string $extra = ''): string
{
    // A checkout that converts line endings must not change what the tests expect.
    return str_replace("\r\n", "\n", <<<TOML
        [[seat]]
        machine = "m"
        slot = "{$slot}"
        repository = "org/some.repo"
        checkout = "/src/repo"
        role = "build"
        {$extra}

        TOML);
}

it('reads the shipped example', function () {
    $fleet = Fleet::load(__DIR__.'/../../../examples/seats.toml');

    expect($fleet->channel)->toBe('server:robot-council')
        ->and(array_keys($fleet->machines))->toBe(['office', 'desk'])
        ->and($fleet->seats)->toHaveCount(3)
        ->and($fleet->machineNamed('desk')->windows())->toBeTrue()
        ->and($fleet->machineNamed('office')->windows())->toBeFalse()
        ->and($fleet->mcpServer())->toBe('robot-council');
});

it('fills in defaults', function () {
    $fleet = Fleet::parse(stageToml());
    $seat = $fleet->seats[0];

    expect($seat->workLocation)->toBe('a')
        ->and($seat->harness)->toBe('claude')
        ->and($seat->remoteControl)->toBe('some.repo/m/a')
        ->and($seat->window())->toBe('some-repo-a')
        ->and($seat->conversation)->toBeNull()
        ->and($fleet->machineNamed('m')->start)->toBe('enter')
        ->and($fleet->machineNamed('m')->startGap)->toBe(5)
        ->and($fleet->machineNamed('m')->remoteControl)->toBeTrue()
        ->and($fleet->channel)->toBe('server:robot-council');
});

it('refuses a bad file', function (string $toml, string $message) {
    expect(fn () => Fleet::parse($toml))->toThrow(ConfigError::class, $message);
})->with([
    'channel without a tag' => [stageToml(top: 'channel = "robot-council"'), 'tagged entry'],
    'unknown machine' => [stageToml(str_replace('"m"', '"x"', seatToml())), 'is not defined'],
    'duplicate slot' => [stageToml(seatToml('a', 'work_location = "z"').seatToml('a', 'work_location = "y"')), 'listed twice'],
    'duplicate work location' => [stageToml(seatToml('a').seatToml('b', 'work_location = "a"')), 'already used'],
    'bad role' => [stageToml(str_replace('"build"', '"boss"', seatToml())), 'role must be'],
    'harness not stageable' => [stageToml(seatToml('a', 'harness = "codex"')), 'not stageable'],
    'work location as a path' => [stageToml(seatToml('a', 'work_location = "/src/repo"')), 'never a path'],
    'repository without an owner' => [stageToml(str_replace('org/some.repo', 'repo', seatToml())), 'owner/name'],
    'bad capacity' => [stageToml(seatToml('a', 'capacity = 0')), 'capacity'],
    'bad start mode' => [stageToml(machines: "[[machine]]\nname = \"m\"\nstart = \"soon\"\n"), 'start must be'],
    'bad multiplexer' => [stageToml(machines: "[[machine]]\nname = \"m\"\nmultiplexer = \"screen\"\n"), 'tmux'],
    'bad gap' => [stageToml(machines: "[[machine]]\nname = \"m\"\nstart_gap = \"soon\"\n"), 'start_gap'],
    'after names nothing' => [stageToml(seatToml('a', 'after = ["ghost"]')), 'not a slot'],
    'after loops' => [stageToml(seatToml('a', 'after = ["b"]').seatToml('b', 'after = ["a"]')), 'loops'],
    'duplicate machine' => [stageToml(machines: "[[machine]]\nname = \"m\"\n[[machine]]\nname = \"m\"\n"), 'listed twice'],
]);

it('starts seats after the ones they name, otherwise in file order', function () {
    $fleet = Fleet::parse(stageToml(seatToml('c', 'after = ["a"]').seatToml('b').seatToml('a')));

    expect(array_map(fn ($s) => $s->slot, Fleet::order($fleet->seatsOn('m'))))->toBe(['b', 'a', 'c']);
});

it('names the known machines when one is missing', function () {
    Fleet::parse(stageToml())->machineNamed('nope');
})->throws(ConfigError::class, 'known: m');
