<?php

declare(strict_types=1);

use FleetKit\Pace\ConfigError;
use FleetKit\Stage\Fleet;

it('accepts a work_location of 32 characters', function () {
    $name = str_repeat('a', 32);
    $fleet = Fleet::parse(stageToml(seatToml('a', "work_location = \"{$name}\"")));

    expect($fleet->seats[0]->workLocation)->toBe($name);
});

it('refuses 33 characters, naming the seat, the length and the limit', function () {
    $name = str_repeat('a', 33);

    expect(fn () => Fleet::parse(stageToml(seatToml('a', "work_location = \"{$name}\""))))
        ->toThrow(ConfigError::class, 'm/a: work_location "'.$name.'" is 33 characters; the limit is 32');
});

it('refuses the 37-character name that core cut short at join', function () {
    $name = 'slack-session-bus-coordinator-primary';

    expect(fn () => Fleet::parse(stageToml(seatToml('a', "work_location = \"{$name}\""))))
        ->toThrow(ConfigError::class, 'is 37 characters; the limit is 32');
});

it('holds the default work_location, the slot, to the same limit, and says it is the default', function () {
    $slot = str_repeat('s', 32);
    $long = str_repeat('s', 33);

    expect(Fleet::parse(stageToml(seatToml($slot)))->seats[0]->workLocation)->toBe($slot)
        ->and(fn () => Fleet::parse(stageToml(seatToml($long))))
        ->toThrow(ConfigError::class, "m/{$long}: work_location \"{$long}\" is 33 characters; the limit is 32")
        ->and(fn () => Fleet::parse(stageToml(seatToml($long))))
        ->toThrow(ConfigError::class, 'it defaults to the slot');
});

it('does not blame the default when work_location was given', function () {
    $name = str_repeat('a', 33);

    try {
        Fleet::parse(stageToml(seatToml('a', "work_location = \"{$name}\"")));
    } catch (ConfigError $e) {
        expect($e->getMessage())->not->toContain('defaults to the slot');
    }
});

it('makes seat-stage check report it', function () {
    $name = str_repeat('a', 33);
    [$code, , $err] = seatStage(['check', 'm'], stageToml(seatToml('a', "work_location = \"{$name}\"")));

    expect($code)->toBe(2)->and($err[0])->toContain('the limit is 32');
});
