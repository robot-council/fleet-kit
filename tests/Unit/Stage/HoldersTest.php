<?php

declare(strict_types=1);

use FleetKit\Stage\Holders;
use FleetKit\Stage\PsClock;
use Tests\Support\FakeClock;
use Tests\Support\Fakes;

const STARTED = '2026-10-20T13:39:13+00:00';

function holders(array $files, array $alive, string $platform = 'Windows'): Holders
{
    $home = Fakes::sessions($files);

    return new Holders($home.'/.claude/sessions', new FakeClock($alive), $platform);
}

it('reads a FILETIME and a UTC lstart string', function () {
    expect(Holders::procStart(Fakes::filetime(STARTED))->format('c'))->toBe(STARTED)
        ->and(Holders::procStart((string) Fakes::filetime(STARTED))->format('c'))->toBe(STARTED)
        ->and(Holders::procStart('Tue Oct 20 13:39:13 2026')->format('c'))->toBe(STARTED)
        ->and(PsClock::parse('Tue Oct 6 01:02:03 2026')?->format('c'))->toBe('2026-10-06T01:02:03+00:00')
        ->and(Holders::procStart(null))->toBeNull();
});

it('finds the process holding a conversation', function () {
    $holders = holders(
        [101 => ['sessionId' => 'conv-1', 'procStart' => Fakes::filetime(STARTED), 'cwd' => 'C:\\src\\repo']],
        [101 => STARTED],
    );

    $holder = $holders->holding('conv-1');

    expect($holder?->pid)->toBe(101)
        ->and($holder?->cwd)->toBe('C:\\src\\repo')
        ->and($holders->holding('other'))->toBeNull();
});

it('does not count a session file whose process is gone', function () {
    $holders = holders([101 => ['sessionId' => 'conv-1', 'procStart' => Fakes::filetime(STARTED)]], []);

    expect($holders->holding('conv-1'))->toBeNull();
});

it('does not count a recycled pid: the process started at a different time', function () {
    $holders = holders(
        [101 => ['sessionId' => 'conv-1', 'procStart' => Fakes::filetime(STARTED)]],
        [101 => '2026-10-20T15:00:00+00:00'],
    );

    expect($holders->holding('conv-1'))->toBeNull();
});

it('allows a second of drift between the file and the process', function () {
    $holders = holders(
        [101 => ['sessionId' => 'conv-1', 'procStart' => Fakes::filetime(STARTED)]],
        [101 => '2026-10-20T13:39:14+00:00'],
    );

    expect($holders->holding('conv-1'))->not->toBeNull();
});

it('skips files it cannot read', function () {
    $home = Fakes::sessions([101 => ['sessionId' => 'conv-1', 'procStart' => Fakes::filetime(STARTED)]]);
    file_put_contents($home.'/.claude/sessions/junk.json', 'not json');
    file_put_contents($home.'/.claude/sessions/102.json', json_encode(['pid' => 102]));

    $holders = new Holders($home.'/.claude/sessions', new FakeClock([101 => STARTED]), 'Windows');

    expect($holders->live())->toHaveCount(1);
});

it('claims nothing where the files are unmeasured or unreadable', function () {
    $home = Fakes::sessions([101 => ['sessionId' => 'conv-1', 'procStart' => Fakes::filetime(STARTED)]]);

    $linux = new Holders($home.'/.claude/sessions', new FakeClock([101 => STARTED]), 'Linux');
    $missing = new Holders($home.'/nowhere', new FakeClock([101 => STARTED]), 'Windows');

    expect($linux->readable())->toBeFalse()->and($linux->holding('conv-1'))->toBeNull()
        ->and($missing->readable())->toBeFalse();
});

it('asks a batch clock once for every session file, never per process', function () {
    $clock = new class implements \FleetKit\Stage\BatchClock
    {
        public int $batch = 0;

        public int $single = 0;

        public function startedAt(int $pid): ?DateTimeImmutable
        {
            $this->single++;

            return null;
        }

        public function startTimes(): array
        {
            $this->batch++;

            return [101 => new DateTimeImmutable(STARTED, new DateTimeZone('UTC')), 102 => new DateTimeImmutable(STARTED, new DateTimeZone('UTC'))];
        }
    };
    $home = Fakes::sessions([
        101 => ['sessionId' => 'conv-1', 'procStart' => Fakes::filetime(STARTED)],
        102 => ['sessionId' => 'conv-2', 'procStart' => Fakes::filetime('2026-10-20T14:00:00+00:00')], // pid reused: starts differ
        103 => ['sessionId' => 'conv-3', 'procStart' => Fakes::filetime(STARTED)], // not running
    ]);

    $live = (new Holders($home.'/.claude/sessions', $clock, 'Windows'))->live();

    expect(array_map(fn ($h) => $h->conversation, $live))->toBe(['conv-1'])
        ->and($clock->batch)->toBe(1)
        ->and($clock->single)->toBe(0);
});

it('reads every process start time from one PowerShell answer', function () {
    $shell = new Tests\Support\FakeShell([[['powershell'], new FleetKit\Stage\Result(0, "101 2026-10-20T13:39:13.1234567Z\r\n102 2026-10-20T15:40:00.0000000+02:00\n\nnot a line\n")]]);

    $starts = (new FleetKit\Stage\WindowsClock($shell))->startTimes();

    expect(array_keys($starts))->toBe([101, 102])
        ->and($starts[101]->format('c'))->toBe('2026-10-20T13:39:13+00:00')
        ->and($starts[102]->format('c'))->toBe('2026-10-20T13:40:00+00:00')
        ->and($shell->calls)->toHaveCount(1);
});

it('says so when the PowerShell query for start times fails', function () {
    $shell = new Tests\Support\FakeShell([[['powershell'], new FleetKit\Stage\Result(1)]]);

    (new FleetKit\Stage\WindowsClock($shell))->startTimes();
})->throws(FleetKit\Stage\Unreadable::class, 'could not read the start times');
