<?php

declare(strict_types=1);

use FleetKit\Pace\Pace;

const WED_NOON = '2026-10-21T12:00:00-05:00';

it('draws the line over working hours', function () {
    $over = Pace::judge(account(), 46, new DateTimeImmutable(WED_NOON));

    expect($over->baseline)->toBe(40.0)
        ->and($over->verdict)->toBe('OVER')
        ->and($over->projected)->toBe(115.0)
        ->and($over->windowStart->format('c'))->toBe('2026-10-19T12:00:00-05:00')
        ->and($over->resetsAt->format('c'))->toBe('2026-10-26T12:00:00-05:00');

    expect(Pace::judge(account(), 40, new DateTimeImmutable(WED_NOON))->verdict)->toBe('UNDER');
    expect(Pace::judge(account(), 34, new DateTimeImmutable(WED_NOON))->verdict)->toBe('UNDER');
});

it('draws the line over clock hours', function () {
    $clock = account('clock');
    $now = new DateTimeImmutable(WED_NOON);

    $over = Pace::judge($clock, 30, $now);
    expect(round($over->baseline, 4))->toBe(28.5714)
        ->and($over->verdict)->toBe('OVER')
        ->and(round($over->projected, 4))->toBe(105.0);

    expect(Pace::judge($clock, 28, $now)->verdict)->toBe('UNDER');
});

it('projects the run-out time over working hours', function () {
    $verdict = Pace::judge(account(), 46, new DateTimeImmutable(WED_NOON));

    // 16h worked by Wed noon at 46% -> 100% at 34.78h worked: 29h ends Thu 17:00, so Fri 14:46.
    expect($verdict->runsOutAt->format('D Y-m-d H:i'))->toBe('Fri 2026-10-23 14:46');
});

it('projects the run-out time over clock hours', function () {
    $verdict = Pace::judge(account('clock'), 30, new DateTimeImmutable(WED_NOON));

    // 2 days elapsed at 30% -> 100% at 6d16h after the start (Mon 12:00), which is Mon 04:00.
    expect($verdict->runsOutAt->format('D Y-m-d H:i'))->toBe('Mon 2026-10-26 04:00');
});

it('reports no run-out when the projection stays within the allowance', function () {
    expect(Pace::judge(account(), 30, new DateTimeImmutable(WED_NOON))->runsOutAt)->toBeNull();
});

it('reports a run-out now when the allowance is spent', function () {
    $now = new DateTimeImmutable(WED_NOON);

    expect(Pace::judge(account(), 100, $now)->runsOutAt?->format('c'))->toBe($now->format('c'));
});

it('does not move the working-hours line overnight or over the weekend', function () {
    $friClose = Pace::judge(account(), 0, new DateTimeImmutable('2026-10-23T17:00:00-05:00'));
    $monOpen = Pace::judge(account(), 0, new DateTimeImmutable('2026-10-26T09:00:00-05:00'));
    $sat = Pace::judge(account(), 0, new DateTimeImmutable('2026-10-24T12:00:00-05:00'));

    expect($friClose->baseline)->toBe(92.5)
        ->and($sat->baseline)->toBe(92.5)
        ->and($monOpen->baseline)->toBe(92.5);
});

it('honors a dead band', function () {
    $now = new DateTimeImmutable(WED_NOON);

    expect(Pace::judge(account('working', 'dead_band = 2'), 41, $now)->verdict)->toBe('UNDER')
        ->and(Pace::judge(account('working', 'dead_band = 2'), 42.5, $now)->verdict)->toBe('OVER');
});

it('rolls to the next week at the reset instant itself', function () {
    $atReset = new DateTimeImmutable('2026-10-26T12:00:00-05:00');
    $verdict = Pace::judge(account(), 0, $atReset);

    expect($verdict->resetsAt->format('c'))->toBe('2026-11-02T12:00:00-06:00')
        ->and($verdict->baseline)->toBe(0.0)
        ->and($verdict->projected)->toBeNull()
        ->and($verdict->verdict)->toBe('UNDER');

    expect(Pace::judge(account(), 1, $atReset)->verdict)->toBe('OVER');
});

it('accepts a time in any offset', function () {
    $utc = Pace::judge(account(), 46, new DateTimeImmutable('2026-10-21T17:00:00Z'));

    expect($utc->baseline)->toBe(40.0);
});

it('draws the working-hours line across a daylight-saving change', function () {
    // Window Mon 2026-10-26 12:00 CDT to Mon 2026-11-02 12:00 CST: still 40 working hours.
    expect(Pace::judge(account(), 0, new DateTimeImmutable('2026-10-27T12:00:00-05:00'))->baseline)->toBe(20.0)
        ->and(Pace::judge(account(), 0, new DateTimeImmutable('2026-11-02T09:00:00-06:00'))->baseline)->toBe(92.5);

    // 8h worked by Tue noon (Mon 5h + Tue 3h) at 40% -> 100% at 20h worked: Mon 5h + Tue 8h + Wed 7h = Wed 16:00.
    $over = Pace::judge(account(), 40, new DateTimeImmutable('2026-10-27T12:00:00-05:00'));
    expect($over->runsOutAt->format('D Y-m-d H:i'))->toBe('Wed 2026-10-28 16:00');
});

it('lets working hours end at 24:00', function () {
    $late = account('working', 'working_hours = "09:00-24:00"');

    // The week holds Mon 12:00-24:00 (12h), Tue-Fri 09:00-24:00 (15h each) and Mon 09:00-12:00 (3h): 75h.
    // By Wed 00:00 that is Mon 12h + Tue 15h = 27h.
    expect(Pace::judge($late, 0, new DateTimeImmutable('2026-10-21T00:00:00-05:00'))->baseline)->toBe(36.0);
});

it('keeps wall-clock times across a daylight-saving change', function () {
    // Chicago falls back on 2026-11-01: the window ending Mon 2026-11-02 12:00 CST is an hour longer.
    $verdict = Pace::judge(account('clock'), 0, new DateTimeImmutable('2026-10-30T12:00:00-05:00'));

    expect($verdict->windowStart->format('c'))->toBe('2026-10-26T12:00:00-05:00')
        ->and($verdict->resetsAt->format('c'))->toBe('2026-11-02T12:00:00-06:00');
});
