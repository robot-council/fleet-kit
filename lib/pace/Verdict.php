<?php

declare(strict_types=1);

namespace FleetKit\Pace;

use DateTimeImmutable;

/** Where an allowance stands against the straight line to its reset. */
final readonly class Verdict
{
    public function __construct(
        public string $verdict,
        public float $used,
        public float $baseline,
        public ?float $projected,
        public ?DateTimeImmutable $runsOutAt,
        public DateTimeImmutable $windowStart,
        public DateTimeImmutable $resetsAt,
    ) {}

    public function over(): bool
    {
        return $this->verdict === 'OVER';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict,
            'used' => $this->used,
            'baseline' => round($this->baseline, 2),
            'projected' => $this->projected === null ? null : round($this->projected, 2),
            'runs_out_at' => $this->runsOutAt?->format('c'),
            'window_start' => $this->windowStart->format('c'),
            'resets_at' => $this->resetsAt->format('c'),
        ];
    }

    public function line(string $account): string
    {
        return sprintf(
            '%s account=%s used=%s%% baseline=%s%% projected=%s runs_out=%s resets=%s',
            $this->verdict,
            $account,
            self::num($this->used),
            self::num($this->baseline),
            $this->projected === null ? 'n/a' : self::num($this->projected).'%',
            $this->runsOutAt?->format('Y-m-d\TH:i:sP') ?? 'no',
            $this->resetsAt->format('Y-m-d\TH:i:sP'),
        );
    }

    public static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
