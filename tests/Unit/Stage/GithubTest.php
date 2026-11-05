<?php

declare(strict_types=1);

use FleetKit\Pace\Config;
use FleetKit\Pace\ConfigError;
use FleetKit\Stage\Fleet;
use FleetKit\Stage\Launch;

/** A seats file with one machine "m", the given seat extras, and the given [[account]] tables. */
function githubFleet(string $accounts, string $seatExtra = '', string $slot = 'a'): Fleet
{
    return Fleet::parse(stageToml(seatToml($slot, $seatExtra), "[[machine]]\nname = \"m\"\nmultiplexer = \"tmux\"\n").$accounts);
}

function accountTable(string $name, string $machine, ?string $github = null): string
{
    return "\n[[account]]\nname = \"{$name}\"\nmachine = {$machine}\n".($github === null ? '' : "github = \"{$github}\"\n");
}

const JOIN = 'Join the Robot Council fleet: call the `join` tool of the robot-council server with role: build, repository: org/some.repo, work_location: a.';

it('keeps today\'s prompt byte for byte when github is not set', function () {
    $without = githubFleet(accountTable('main', '"m"'), 'prompt = "Take work by placement."');
    $none = githubFleet('', 'prompt = "Take work by placement."');

    expect(Launch::prompt($without->seats[0]))->toBe(JOIN.' Take work by placement.')
        ->and(Launch::prompt($none->seats[0]))->toBe(JOIN.' Take work by placement.')
        ->and($without->seats[0]->github)->toBeNull();
});

it('tells a new seat whose seat it is, between the join text and the seat\'s own prompt', function () {
    $fleet = githubFleet(accountTable('main', '"m"', 'octocat'), 'prompt = "Take work by placement."');

    expect(Launch::prompt($fleet->seats[0]))->toBe(JOIN." Your operator's GitHub username is `octocat`. Take work by placement.");
});

it('tells a resumed seat, and a Codex seat, the same sentence', function (string $extra) {
    $fleet = githubFleet(accountTable('main', '"m"', 'octocat'), $extra);

    expect(Launch::prompt($fleet->seats[0]))->toContain("Your operator's GitHub username is `octocat`.");
})->with([
    'resumed' => ['conversation = "c-1"'],
    'codex' => ['harness = "codex"'],
]);

it('finds the account through its machine list, whatever the case, and ignores accounts for other machines', function () {
    $fleet = githubFleet(accountTable('other', '"x"', 'someone-else').accountTable('main', '["home", "M"]', 'octocat'));

    expect($fleet->seats[0]->github)->toBe('octocat');
});

it('says nothing when no account lists the machine, or the account sets no github', function () {
    expect(githubFleet(accountTable('other', '"x"', 'someone'))->seats[0]->github)->toBeNull()
        ->and(githubFleet(accountTable('main', '"m"'))->seats[0]->github)->toBeNull();
});

it('refuses a machine listed under more than one account when one sets github, naming the machine and the accounts', function () {
    $accounts = accountTable('main', '["m", "home"]', 'octocat').accountTable('spare', '"m"');

    expect(fn () => githubFleet($accounts))->toThrow(ConfigError::class, 'machine "m" is listed under more than one [[account]] ("main", "spare") and github is set on "main"');
});

it('refuses it when both accounts set github, even to the same value', function () {
    $accounts = accountTable('a', '"m"', 'octocat').accountTable('b', '"m"', 'octocat');

    expect(fn () => githubFleet($accounts))->toThrow(ConfigError::class, 'listed under more than one [[account]] ("a", "b") and github is set on "a", "b"');
});

it('leaves a machine under several accounts alone when none sets github, as seat-pace\'s fallback order needs', function () {
    $fleet = githubFleet(accountTable('main', '"m"').accountTable('spare', '["x", "m"]'));

    expect($fleet->seats[0]->github)->toBeNull();
});

it('does not mind about a machine that has no seat', function () {
    $accounts = accountTable('main', '"m"', 'octocat').accountTable('a', '"unused"', 'one').accountTable('b', '"unused"');

    expect(githubFleet($accounts)->seats[0]->github)->toBe('octocat');
});

it('accepts GitHub usernames of 1 to 39 letters, digits and single hyphens', function (string $name) {
    expect(githubFleet(accountTable('main', '"m"', $name))->seats[0]->github)->toBe($name);
})->with(['octocat', 'A', 'a-b', 'user-123', str_repeat('a', 39)]);

it('refuses a github value that cannot be a username', function (string $name) {
    expect(fn () => githubFleet(accountTable('main', '"m"', $name)))->toThrow(ConfigError::class, 'account "main": github must be a GitHub username');
})->with([
    'empty' => [''],
    'too long' => [str_repeat('a', 40)],
    'leading hyphen' => ['-octocat'],
    'trailing hyphen' => ['octocat-'],
    'double hyphen' => ['octo--cat'],
    'underscore' => ['octo_cat'],
    'a space' => ['octo cat'],
    'an at sign' => ['@octocat'],
    'a backtick' => ['octo`cat'],
]);

it('refuses a github that is not text', function () {
    $toml = "\n[[account]]\nname = \"main\"\nmachine = \"m\"\ngithub = 42\n";

    expect(fn () => githubFleet($toml))->toThrow(ConfigError::class, 'github must be a GitHub username');
});

it('does not trouble seat-pace, which ignores the key', function () {
    $config = Config::parse(accountToml('working', 'github = "octocat"'));

    expect($config->accounts)->toHaveCount(1);
});

it('makes check, stage and a dry run fail with status 2 and the message when a machine is ambiguous', function (array $argv) {
    $toml = stageToml(seatToml('a'), "[[machine]]\nname = \"m\"\nmultiplexer = \"tmux\"\n").accountTable('main', '"m"', 'octocat').accountTable('spare', '"m"');
    [$code, $out, $err] = seatStage($argv, $toml);

    expect($code)->toBe(2)->and($err[0])->toContain('machine "m" is listed under more than one [[account]]')->and($out)->toBe([]);
})->with([
    'check' => [['check', 'm']],
    'stage' => [['stage', 'm']],
    'dry run' => [['stage', 'm', '--dry-run']],
]);

it('shows the sentence in a dry run', function () {
    $toml = stageToml(seatToml('a'), "[[machine]]\nname = \"m\"\nmultiplexer = \"tmux\"\n").accountTable('main', '"m"', 'octocat');
    [$code, $out] = seatStage(['stage', 'm', '--dry-run'], $toml);

    expect($code)->toBe(0)->and(implode("\n", $out))->toContain("Your operator's GitHub username is `octocat`.");
});
