<?php

declare(strict_types=1);

namespace FleetKit\Pace;

use DateTimeImmutable;

/** The pacing arithmetic. No network, no credential, no clock of its own. */
final class Pace
{
    private const WEEK = 604800;

    /** The first reset strictly after $now: the moment a reading at $now stops counting. */
    public static function nextReset(Account $account, DateTimeImmutable $now): DateTimeImmutable
    {
        [$hour, $minute] = $account->resetTime;
        $local = $now->setTimezone($account->timezone);
        $ahead = ($account->resetDay - (int) $local->format('N') + 7) % 7;
        $reset = $local->setTime($hour, $minute)->modify("+{$ahead} days")->setTime($hour, $minute);

        if ($reset->getTimestamp() <= $now->getTimestamp()) {
            $reset = $reset->modify('+7 days')->setTime($hour, $minute);
        }

        return $reset;
    }

    public static function judge(Account $account, float $used, DateTimeImmutable $now): Verdict
    {
        $reset = self::nextReset($account, $now);
        $start = $reset->modify('-7 days')->setTime(...$account->resetTime);

        $total = self::worked($account, $start, $reset);
        if ($total <= 0) {
            throw new ConfigError("account \"{$account->name}\": no working hours fall inside the week");
        }
        $done = self::worked($account, $start, $now);
        $share = $done / $total;

        $baseline = $share * 100;
        $projected = $share > 0 ? $used / $share : null;

        $runsOut = null;
        if ($used >= 100) {
            $runsOut = $now;
        } elseif ($projected !== null && $used > 0 && $projected > 100) {
            $runsOut = self::after($account, $start, (int) round($done * 100 / $used));
        }

        return new Verdict(
            verdict: $used - $baseline > $account->deadBand ? 'OVER' : 'UNDER',
            used: $used,
            baseline: $baseline,
            projected: $projected,
            runsOutAt: $runsOut,
            windowStart: $start,
            resetsAt: $reset,
        );
    }

    /** Seconds of the line (working or clock) between two instants. */
    public static function worked(Account $account, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if ($to->getTimestamp() <= $from->getTimestamp()) {
            return 0;
        }
        if (! $account->workingHours) {
            return $to->getTimestamp() - $from->getTimestamp();
        }

        $seconds = 0;
        foreach (self::days($account, $from, $to) as [$open, $close]) {
            $low = max($open, $from->getTimestamp());
            $high = min($close, $to->getTimestamp());
            $seconds += max(0, $high - $low);
        }

        return $seconds;
    }

    /** The instant at which $seconds of the line have run since $from, or null past the end of the week. */
    public static function after(Account $account, DateTimeImmutable $from, int $seconds): ?DateTimeImmutable
    {
        if (! $account->workingHours) {
            return $seconds >= self::WEEK ? null : $from->modify("+{$seconds} seconds");
        }

        $end = $from->modify('+7 days');
        foreach (self::days($account, $from, $end) as [$open, $close]) {
            $open = max($open, $from->getTimestamp());
            $close = min($close, $end->getTimestamp());
            $length = max(0, $close - $open);
            if ($seconds <= $length) {
                return $from->setTimestamp($open + $seconds);
            }
            $seconds -= $length;
        }

        return null;
    }

    /**
     * The working intervals, as timestamps, of each day from $from's date to $to's.
     *
     * @return iterable<array{0: int, 1: int}>
     */
    private static function days(Account $account, DateTimeImmutable $from, DateTimeImmutable $to): iterable
    {
        $day = $from->setTimezone($account->timezone)->setTime(0, 0);
        $last = $to->getTimestamp();

        while ($day->getTimestamp() < $last) {
            if (in_array((int) $day->format('N'), $account->workingDays, true)) {
                yield [
                    $day->setTime(...$account->workStart)->getTimestamp(),
                    $day->setTime(...$account->workEnd)->getTimestamp(),
                ];
            }
            $day = $day->modify('+1 day')->setTime(0, 0);
        }
    }
}
