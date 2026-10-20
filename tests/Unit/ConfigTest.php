<?php

declare(strict_types=1);

use FleetKit\Pace\Config;
use FleetKit\Pace\ConfigError;
use FleetKit\Pace\Toml;

it('parses the subset of TOML a seats file uses', function () {
    $data = Toml::parse(<<<'TOML'
        # a comment
        log = "pace.tsv"   # trailing comment with "quotes" in it
        [[account]]
        name = 'a#b'
        dead_band = 1.5
        flag = true
        days = ["Mon", "Tue"]
        [[account]]
        name = "second"
        TOML);

    expect($data['log'])->toBe('pace.tsv')
        ->and($data['account'])->toHaveCount(2)
        ->and($data['account'][0])->toBe(['name' => 'a#b', 'dead_band' => 1.5, 'flag' => true, 'days' => ['Mon', 'Tue']])
        ->and($data['account'][1]['name'])->toBe('second');
});

it('reads TOML escapes and literal strings, and refuses a stray backslash', function () {
    $data = Toml::parse("a = \"tab\\there \\u00e9 \\\\ \\\"q\\\"\"\nb = 'C:\\logs\\seat.tsv'");

    expect($data['a'])->toBe("tab\there é \\ \"q\"")->and($data['b'])->toBe('C:\logs\seat.tsv');

    foreach (['log = "C:\logs\seat.tsv"', 'log = "C:\temp\new.tsv"x', 'log = "\q"'] as $bad) {
        expect(fn () => Toml::parse($bad))->toThrow(ConfigError::class);
    }
});

it('refuses TOML it does not read', function (string $line) {
    Toml::parse($line);
})->throws(ConfigError::class)->with(['a = {b = 1}', 'a = 1979-05-27', 'not a line']);

it('builds accounts and resolves one by name', function () {
    $config = Config::parse(accountToml(name: 'one')."\n".accountToml(name: 'two'), '/etc/kit');

    expect($config->accounts)->toHaveCount(2)
        ->and($config->account('two')->name)->toBe('two')
        ->and($config->log)->toBe('/etc/kit'.DIRECTORY_SEPARATOR.'seat-pace.tsv');
});

it('needs a name when there is more than one account', function () {
    Config::parse(accountToml(name: 'one')."\n".accountToml(name: 'two'))->account(null);
})->throws(ConfigError::class, 'name an account');

it('rejects a bad account', function (string $toml, string $message) {
    Config::parse($toml);
})->throws(ConfigError::class)->with([
    'no accounts' => ['log = "x"', 'no [[account]]'],
    'bad reset' => [str_replace('Mon 12:00', 'Monday noon', accountToml()), 'reset'],
    'bad timezone' => [str_replace('America/Chicago', 'Nowhere/Land', accountToml()), 'timezone'],
    'reset at 24:00' => [str_replace('Mon 12:00', 'Mon 24:00', accountToml()), '24:00'],
    'bad hours' => [accountToml('some'), 'hours'],
    'backwards day' => [accountToml('working', 'working_hours = "17:00-09:00"'), 'working_hours'],
    'negative band' => [accountToml('working', 'dead_band = -1'), 'dead_band'],
    'duplicate' => [accountToml()."\n".accountToml(), 'twice'],
]);
