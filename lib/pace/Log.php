<?php

declare(strict_types=1);

namespace FleetKit\Pace;

use DateTimeImmutable;
use DateTimeZone;

/** The TSV log: one row per reading, so the last row of an account is its previous verdict. */
final class Log
{
    public const COLUMNS = ['utc', 'account', 'window', 'used', 'resets_at', 'baseline', 'projected', 'verdict', 'extra', 'note'];

    public function __construct(private readonly string $path) {}

    public function append(DateTimeImmutable $now, string $account, float $used, Verdict $verdict, string $extra, string $note): void
    {
        $new = ! is_file($this->path) || filesize($this->path) === 0;

        $row = [
            $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            $account,
            '7d',
            Verdict::num($used),
            $verdict->resetsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            Verdict::num($verdict->baseline),
            $verdict->projected === null ? '' : Verdict::num($verdict->projected),
            $verdict->verdict,
            $extra,
            $note,
        ];

        $text = ($new ? implode("\t", self::COLUMNS)."\n" : '')
            .implode("\t", array_map(fn (string $cell) => str_replace(["\t", "\r", "\n"], ' ', $cell), $row))."\n";

        if (file_put_contents($this->path, $text, FILE_APPEND | LOCK_EX) === false) {
            throw new ReadFailed("cannot write the log: {$this->path}");
        }
    }

    /** The verdict of the account's last row, or null when it has none. */
    public function lastVerdict(string $account): ?string
    {
        if (! is_file($this->path)) {
            return null;
        }
        $last = null;
        foreach (file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $cells = explode("\t", $line);
            if (($cells[1] ?? null) === $account && in_array($cells[7] ?? null, ['UNDER', 'OVER'], true)) {
                $last = $cells[7];
            }
        }

        return $last;
    }
}
