<?php

declare(strict_types=1);

use FleetKit\Pace\Account;
use FleetKit\Pace\Cli;
use FleetKit\Pace\Presence;
use FleetKit\Pace\ReadFailed;
use FleetKit\Pace\Usage;
use FleetKit\Pace\UsageReader;
use FleetKit\Pace\UnsupportedReader;

/** @return array{0: int, 1: list<string>, 2: list<string>, 3: list<array{0: string, 1: array}>} exit, stdout, stderr, shell calls */
function seatPace(array $argv, ?UsageReader $reader = null, string $now = '2026-10-21T12:00:00-05:00', string $machine = 'office', array $shellOut = [], ?string $dir = null, ?string $toml = null, ?Presence $presence = null): array
{
    $dir ??= sys_get_temp_dir().'/seat-pace-'.bin2hex(random_bytes(4));
    is_dir($dir) || mkdir($dir);
    $config = $dir.'/seats.toml';
    is_file($config) || file_put_contents($config, $toml ?? accountToml('working', 'log_extra = "extra-cmd"'."\n".'on_change = "changed-cmd"'));

    $out = [];
    $err = [];
    $shell = [];
    $cli = new Cli(
        out: function (string $l) use (&$out) { $out[] = $l; },
        err: function (string $l) use (&$err) { $err[] = $l; },
        reader: $reader ?? fakeReader(46),
        clock: fn () => new DateTimeImmutable($now),
        shell: function (string $c, array $e) use (&$shell, $shellOut) { $shell[] = [$c, $e]; return $shellOut[$c] ?? ''; },
        sleep: function (int $s) { throw new RuntimeException("slept {$s}"); },
        machine: $machine,
        presence: $presence,
    );

    return [$cli->run(array_merge($argv, ['--config', $config])), $out, $err, $shell, $dir];
}

function fakeReader(float $used, ?string $resetsAt = '2026-10-26T17:00:00+00:00'): UsageReader
{
    return new class($used, $resetsAt) implements UsageReader
    {
        public function __construct(private float $used, private ?string $resetsAt) {}

        public function read(Account $account): Usage
        {
            return new Usage(0.0, null, $this->used, $this->resetsAt === null ? null : new DateTimeImmutable($this->resetsAt));
        }
    };
}

it('exits 0 for UNDER and 3 for OVER with the arithmetic flags', function () {
    [$under, $out] = seatPace(['pace', '--used', '40', '--now', '2026-10-21T12:00:00-05:00']);
    [$over] = seatPace(['pace', '--used=46', '--now=2026-10-21T12:00:00-05:00']);

    expect($under)->toBe(0)
        ->and($out[0])->toStartWith('UNDER account=main used=40% baseline=40% projected=100%')
        ->and($over)->toBe(3);
});

it('prints the verdict as JSON', function () {
    [$code, $out] = seatPace(['pace', '--json', '--used', '46', '--now', '2026-10-21T12:00:00-05:00']);
    $json = json_decode($out[0], true);

    expect($code)->toBe(3)
        ->and($json)->toMatchArray(['account' => 'main', 'verdict' => 'OVER', 'baseline' => 40.0, 'projected' => 115.0]);
});

it('paces a reading taken from the reader', function () {
    [$code, $out] = seatPace(['pace'], fakeReader(30));

    expect($code)->toBe(0)->and($out[0])->toStartWith('UNDER');
});

it('exits 1 when a read fails and says unsupported where it is', function () {
    $failing = new class implements UsageReader
    {
        public function read(Account $account): Usage
        {
            throw new ReadFailed('usage request failed');
        }
    };

    [$code, , $err] = seatPace(['pace'], $failing);
    [$unsupported, , $err2] = seatPace(['read'], new UnsupportedReader);

    expect($code)->toBe(1)->and($err[0])->toContain('usage request failed')
        ->and($unsupported)->toBe(1)->and($err2[0])->toContain('unsupported on this platform');
});

it('exits 2 for configuration and command-line errors', function (array $argv, ?string $machine = null) {
    [$code, , $err] = seatPace($argv, null, '2026-10-21T12:00:00-05:00', $machine ?? 'office');

    expect($code)->toBe(2)->and($err)->not->toBeEmpty();
})->with([
    'unknown command' => [['frobnicate']],
    'unknown account' => [['pace', 'nope', '--used', '1', '--now', '2026-10-21T12:00:00Z']],
    'used without now' => [['pace', '--used', '1']],
    'bad used' => [['pace', '--used', 'lots', '--now', '2026-10-21T12:00:00Z']],
    'bad now' => [['pace', '--used', '1', '--now', 'soon']],
    'wrong machine' => [['read'], 'elsewhere'],
]);

it('exits 2 when the seats file is missing', function () {
    $cli = new Cli(fn () => null, function (string $l) use (&$msg) { $msg = $l; }, fakeReader(1), fn () => new DateTimeImmutable, fn () => '', fn () => null, 'office');

    expect($cli->run(['pace', '--config', '/no/such/seats.toml']))->toBe(2)->and($msg)->toContain('not found');
});

it('does not need the account\'s machine to judge arithmetic', function () {
    [$code] = seatPace(['pace', '--used', '1', '--now', '2026-10-21T12:00:00-05:00'], null, machine: 'elsewhere');

    expect($code)->toBe(0);
});

it('prints both windows for read', function () {
    [$code, $out] = seatPace(['read'], fakeReader(46));

    expect($code)->toBe(0)->and($out)->toBe(['5h used=0% resets=unknown', '7d used=46% resets=2026-10-26 12:00 CDT']);
});

it('warns when the endpoint disagrees with the configured reset', function () {
    [, , $err] = seatPace(['read'], fakeReader(46, '2026-10-27T17:00:00+00:00'));

    expect($err[0])->toContain('warning')->toContain('configured reset');
});

it('writes the documented TSV columns', function () {
    [$code, , , $shell, $dir] = seatPace(['log', '--note', "a\tnote"], fakeReader(46), shellOut: ['extra-cmd' => "parked=2\n"]);
    $rows = array_map(fn ($l) => explode("\t", $l), file($dir.'/seat-pace.tsv', FILE_IGNORE_NEW_LINES));

    expect($code)->toBe(0)
        ->and($rows[0])->toBe(['utc', 'account', 'window', 'used', 'resets_at', 'baseline', 'projected', 'verdict', 'extra', 'note'])
        ->and($rows[1])->toBe(['2026-10-21T17:00:00Z', 'main', '7d', '46', '2026-10-26T17:00:00Z', '40', '115', 'OVER', 'parked=2', 'a note'])
        ->and($shell)->toHaveCount(1);
});

it('appends without repeating the header', function () {
    [, , , , $dir] = seatPace(['log'], fakeReader(46));
    seatPace(['log'], fakeReader(30), dir: $dir);

    expect(file($dir.'/seat-pace.tsv'))->toHaveCount(3);
});

it('watch logs once and runs on_change only when the verdict changes', function () {
    [, , , $first, $dir] = seatPace(['watch', '--once'], fakeReader(30));
    [, , , $same] = seatPace(['watch', '--once'], fakeReader(31), dir: $dir);
    [, , , $changed] = seatPace(['watch', '--once'], fakeReader(46), dir: $dir);

    $hooks = fn (array $calls) => array_values(array_filter($calls, fn ($c) => $c[0] === 'changed-cmd'));

    expect($hooks($first))->toBe([])
        ->and($hooks($same))->toBe([])
        ->and($hooks($changed))->toHaveCount(1)
        ->and($hooks($changed)[0][1])->toBe(['SEAT_PACE_ACCOUNT' => 'main', 'SEAT_PACE_VERDICT' => 'OVER', 'SEAT_PACE_PREVIOUS' => 'UNDER'])
        ->and(file($dir.'/seat-pace.tsv'))->toHaveCount(4);
});

it('watch sleeps for the interval between readings', function () {
    // The test's sleeper throws to end the loop; run() reports any unexpected error as exit 1.
    [$code, $out, $err] = seatPace(['watch', '--every', '2h'], fakeReader(30));

    expect($code)->toBe(1)->and($out)->toHaveCount(1)->and($err[0])->toContain('slept 7200');
});

it('watch covers every account this machine reads, and skips the rest', function () {
    $toml = accountToml(name: 'one')."\n".accountToml(name: 'two')."\n".str_replace('"office"', '"elsewhere"', accountToml(name: 'far'));
    [$code, $out, , , $dir] = seatPace(['watch', '--once'], fakeReader(30), toml: $toml);

    expect($code)->toBe(0)
        ->and($out)->toHaveCount(2)
        ->and($out[0])->toContain('account=one')->and($out[1])->toContain('account=two')
        ->and(file($dir.'/seat-pace.tsv'))->toHaveCount(3);
});

it('watch keeps going past an account whose read fails, and exits 1 for it', function () {
    $flaky = new class implements UsageReader
    {
        public function read(Account $account): Usage
        {
            if ($account->name === 'one') {
                throw new ReadFailed('timed out');
            }

            return new Usage(0.0, null, 30.0, null);
        }
    };
    $toml = accountToml(name: 'one')."\n".accountToml(name: 'two');
    [$code, $out, $err] = seatPace(['watch', '--once'], $flaky, toml: $toml);

    expect($code)->toBe(1)
        ->and($err[0])->toBe('seat-pace: one: timed out')
        ->and($out)->toHaveCount(1)->and($out[0])->toContain('account=two');
});

it('watch exits 2 when no account is read on this machine', function () {
    [$code, , $err] = seatPace(['watch', '--once'], fakeReader(30), machine: 'elsewhere');

    expect($code)->toBe(2)->and($err[0])->toContain('no account is read on "elsewhere"');
});

it('rejects a used value that is not finite or a time without an offset', function (array $argv) {
    [$code, , $err] = seatPace($argv);

    expect($code)->toBe(2)->and($err[0])->toContain('seat-pace:');
})->with([
    'infinite' => [['pace', '--json', '--used', '1e999', '--now', '2026-10-21T12:00:00-05:00']],
    'nan-like' => [['pace', '--used', 'NAN', '--now', '2026-10-21T12:00:00-05:00']],
    'naive time' => [['pace', '--used', '1', '--now', '2026-10-24T03:00:00']],
    'relative time' => [['pace', '--used', '1', '--now', 'tomorrow']],
]);

it('reports an unknown command before it looks at the seats file', function () {
    $msg = null;
    $cli = new Cli(fn () => null, function (string $l) use (&$msg) { $msg = $l; }, fakeReader(1), fn () => new DateTimeImmutable, fn () => '', fn () => null, 'office');

    expect($cli->run(['frobnicate', '--config', '/no/such/seats.toml']))->toBe(2)->and($msg)->toContain('unknown command');
});

it('keeps tabs and newlines in the extra column out of the row structure', function () {
    [, , , , $dir] = seatPace(['log'], fakeReader(46), shellOut: ['extra-cmd' => "a\tb\nc\n"]);
    $rows = array_map(fn ($l) => explode("\t", $l), file($dir.'/seat-pace.tsv', FILE_IGNORE_NEW_LINES));

    expect($rows)->toHaveCount(2)->and($rows[1][8])->toBe('a b c');
});

it('rejects a bad interval', function () {
    [$code] = seatPace(['watch', '--every', 'often'], fakeReader(30));

    expect($code)->toBe(2);
});
