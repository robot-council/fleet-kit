<?php

declare(strict_types=1);

// An account that lists several machines (fleet-kit#19): the first reads while the fleet shows it present;
// the next one reads when it is not. Presence is faked; nothing here calls a live fleet.

use FleetKit\Pace\Account;
use FleetKit\Pace\Presence;
use FleetKit\Pace\ReadFailed;
use FleetKit\Pace\Usage;
use FleetKit\Pace\UsageReader;

/** A fleet that answers with the machines that have an active session, or fails; counts how often it is asked. */
function fleetShowing(array|Throwable $active): Presence
{
    return new class($active) implements Presence
    {
        public int $asked = 0;

        public function __construct(private array|Throwable $active) {}

        public function activeMachines(string $harness): array
        {
            $this->asked++;

            return $this->active instanceof Throwable ? throw $this->active : $this->active;
        }
    };
}

/** A reader that must never be reached. */
function readerNeverUsed(): UsageReader
{
    return new class implements UsageReader
    {
        public function read(Account $account): Usage
        {
            throw new LogicException('read when it should have stood by');
        }
    };
}

function listedTwice(string $name = 'main'): string
{
    return str_replace('machine = "office"', 'machine = ["office", "home"]', accountToml(extra: 'on_change = "changed-cmd"', name: $name));
}

function freshDir(): string
{
    return sys_get_temp_dir().'/seat-pace-'.bin2hex(random_bytes(4));
}

/** The on_change commands among the shell calls. */
function hooksOf(array $calls): array
{
    return array_values(array_filter($calls, fn ($c) => $c[0] === 'changed-cmd'));
}

it('lets the preferred machine read without asking the fleet', function () {
    $fleet = fleetShowing(new RuntimeException('asked'));
    [$code, $out] = seatPace(['watch', '--once'], fakeReader(30), machine: 'office', toml: listedTwice(), presence: $fleet);

    expect($code)->toBe(0)->and($out[0])->toStartWith('UNDER account=main')->and($fleet->asked)->toBe(0);
});

it('stands by when the fleet shows the preferred machine present', function () {
    $fleet = fleetShowing(['office', 'desk']);
    [$code, $out, $err, , $dir] = seatPace(['watch', '--once'], readerNeverUsed(), machine: 'home', toml: listedTwice(), presence: $fleet);

    expect($code)->toBe(0)
        ->and($out)->toBe(['STANDBY account=main reading=office'])
        ->and($err)->toBe([])
        ->and(file_exists($dir.'/seat-pace.tsv'))->toBeFalse();
});

it('reads when the fleet does not show the preferred machine, and moves back when it returns', function () {
    [$code, $out, , , $dir] = seatPace(['watch', '--once'], fakeReader(30), machine: 'home', toml: listedTwice(), presence: fleetShowing(['desk']));
    [, $back] = seatPace(['watch', '--once'], readerNeverUsed(), machine: 'home', dir: $dir, presence: fleetShowing(['office']));

    expect($code)->toBe(0)
        ->and($out[0])->toStartWith('UNDER account=main')
        ->and(file($dir.'/seat-pace.tsv'))->toHaveCount(2)
        ->and($back)->toBe(['STANDBY account=main reading=office']);
});

it('waits for every machine before it, not just the first', function () {
    $toml = str_replace('machine = "office"', 'machine = ["office", "home", "laptop"]', accountToml());
    [, $out] = seatPace(['watch', '--once'], readerNeverUsed(), machine: 'laptop', toml: $toml, presence: fleetShowing(['home']));
    [$code, $read] = seatPace(['watch', '--once'], fakeReader(30), machine: 'laptop', toml: $toml, presence: fleetShowing(['laptop']));

    expect($out)->toBe(['STANDBY account=main reading=home'])
        ->and($code)->toBe(0)->and($read[0])->toStartWith('UNDER account=main');
});

it('matches machine labels without regard to case', function () {
    [, $out] = seatPace(['watch', '--once'], readerNeverUsed(), machine: 'HOME', toml: listedTwice(), presence: fleetShowing(['office']));

    expect($out)->toBe(['STANDBY account=main reading=office']);
});

it('does not read, and says why, when the fleet cannot be asked', function (string $case) {
    $fleet = $case === 'failed' ? fleetShowing(new ReadFailed('the robot-council command failed (exit 127)')) : null;
    [$code, $out, $err, , $dir] = seatPace(['watch', '--once'], readerNeverUsed(), machine: 'home', toml: listedTwice(), presence: $fleet);

    expect($code)->toBe(1)
        ->and($out)->toBe([])
        ->and($err[0])->toStartWith('seat-pace: main: cannot tell whether office is reading, so not reading (')
        ->and(file_exists($dir.'/seat-pace.tsv'))->toBeFalse();
})->with(['failed', 'nothing to ask with']);

it('still refuses a machine that is not in the list', function () {
    [$code, , $err] = seatPace(['watch', '--once'], fakeReader(30), machine: 'desk', toml: listedTwice(), presence: fleetShowing([]));
    [$read, , $readErr] = seatPace(['read'], fakeReader(30), machine: 'desk', toml: listedTwice());

    expect($code)->toBe(2)->and($err[0])->toContain('no account is read on "desk"')
        ->and($read)->toBe(2)->and($readErr[0])->toContain('is read on "office", "home", not on "desk"');
});

it('lets a listed machine read, log and judge by hand without asking the fleet', function () {
    $fleet = fleetShowing(new RuntimeException('asked'));
    [$read] = seatPace(['read'], fakeReader(30), machine: 'home', toml: listedTwice(), presence: $fleet);
    [$log] = seatPace(['log'], fakeReader(30), machine: 'home', toml: listedTwice(), presence: $fleet);
    [$pace] = seatPace(['pace'], fakeReader(30), machine: 'home', toml: listedTwice(), presence: $fleet);

    expect([$read, $log, $pace])->toBe([0, 0, 0])->and($fleet->asked)->toBe(0);
});

it('asks the fleet once a tick for accounts that share a harness', function () {
    $fleet = fleetShowing(['desk']);
    [, $out] = seatPace(['watch', '--once'], fakeReader(30), machine: 'home', toml: listedTwice('one')."\n".listedTwice('two'), presence: $fleet);

    expect($out)->toHaveCount(2)->and($fleet->asked)->toBe(1);
});

// Log continuity: each machine's log is its own, so a takeover starts from a log that is empty or old.

it('does not report a change at a takeover, and does not swallow the next real one', function () {
    $home = fn (string $now, float $used, string $dir) => seatPace(['watch', '--once'], fakeReader($used), $now, 'home', dir: $dir, toml: listedTwice(), presence: fleetShowing([]));
    $dir = freshDir();

    // The first reading on this machine: no previous row, so nothing to compare, as for any first run.
    [, , , $first] = $home('2026-10-21T12:00:00-05:00', 30, $dir);
    // Three hours on, the preferred machine has been reading in between and the verdict is OVER here:
    // the local row is too old to be a fair "previous", so this is a fresh start.
    [, , $err, $takeover] = $home('2026-10-21T15:00:00-05:00', 60, $dir);
    // One interval later this machine's own row is current, so a change is a real change.
    [, , , $changed] = $home('2026-10-21T15:55:00-05:00', 30, $dir);

    expect(hooksOf($first))->toBe([])
        ->and(hooksOf($takeover))->toBe([])
        ->and($err[0])->toContain('main: the last row here is older than 82 minutes')->toContain('fresh start')
        ->and(hooksOf($changed))->toHaveCount(1)
        ->and(hooksOf($changed)[0][1])->toBe(['SEAT_PACE_ACCOUNT' => 'main', 'SEAT_PACE_VERDICT' => 'UNDER', 'SEAT_PACE_PREVIOUS' => 'OVER'])
        ->and(file($dir.'/seat-pace.tsv'))->toHaveCount(4);
});

it('reports a change that happens within an interval on a fallback machine, as on any other', function () {
    $dir = freshDir();
    seatPace(['watch', '--once'], fakeReader(30), '2026-10-21T12:00:00-05:00', 'home', dir: $dir, toml: listedTwice(), presence: fleetShowing([]));
    [, , $err, $calls] = seatPace(['watch', '--once'], fakeReader(46), '2026-10-21T12:55:00-05:00', 'home', dir: $dir, presence: fleetShowing([]));

    expect(hooksOf($calls))->toHaveCount(1)->and($err)->toBe([]);
});

it('leaves an account with one machine exactly as it was, however old its log', function () {
    $dir = freshDir();
    seatPace(['watch', '--once'], fakeReader(20), '2026-10-21T11:00:00-05:00', dir: $dir);
    [, , $err, $calls] = seatPace(['watch', '--once'], fakeReader(90), '2026-10-23T12:00:00-05:00', dir: $dir);

    expect(hooksOf($calls))->toHaveCount(1)->and($err)->toBe([]);
});

it('treats a row one tick old as current and a row a tick stood by as stale', function (string $second, bool $fresh) {
    $dir = freshDir();
    $home = fn (string $now, float $used) => seatPace(['watch', '--once'], fakeReader($used), $now, 'home', dir: $dir, toml: listedTwice(), presence: fleetShowing([]));

    $home('2026-10-21T11:00:00-05:00', 20);
    [, , $err, $calls] = $home($second, 60);

    expect(hooksOf($calls))->toHaveCount($fresh ? 0 : 1)->and($err !== [])->toBe($fresh);
})->with([
    'one interval and some jitter' => ['2026-10-21T12:03:00-05:00', false],
    'a tick stood by (two intervals)' => ['2026-10-21T12:50:00-05:00', true],
]);

it('reads when the fleet shows no sessions at all', function () {
    $empty = new FleetKit\Pace\FleetPresence(fn () => [0, '{"sessions":[],"cursor":null}', '']);
    [$code, $out] = seatPace(['watch', '--once'], fakeReader(30), machine: 'home', toml: listedTwice(), presence: $empty);

    expect($code)->toBe(0)->and($out[0])->toStartWith('UNDER account=main');
});

it('refuses a named account on a machine it does not list', function () {
    [$code, , $err] = seatPace(['watch', 'main', '--once'], fakeReader(30), machine: 'desk', toml: listedTwice(), presence: fleetShowing([]));

    expect($code)->toBe(2)->and($err[0])->toContain('is read on "office", "home", not on "desk"');
});

it('carries on to the next account when the fleet cannot be asked, and asks only once', function () {
    $fleet = fleetShowing(new ReadFailed('unreachable'));
    $other = str_replace('machine = "office"', 'machine = "home"', accountToml(name: 'solo'));
    [$code, $out, $err] = seatPace(['watch', '--once'], fakeReader(30), machine: 'home', toml: listedTwice('one')."\n".listedTwice('two')."\n".$other, presence: $fleet);

    expect($code)->toBe(1)
        ->and($err)->toHaveCount(2)->and($err[0])->toContain('one: cannot tell')->and($err[1])->toContain('two: cannot tell')
        ->and($out)->toHaveCount(1)->and($out[0])->toContain('account=solo')
        ->and($fleet->asked)->toBe(1);
});
