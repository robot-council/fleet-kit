<?php

declare(strict_types=1);

namespace FleetKit\Pace;

use DateTimeImmutable;

/** One reading of an allowance: how much of each window is spent, and when each resets. */
final readonly class Usage
{
    public function __construct(
        public float $fiveHourUsed,
        public ?DateTimeImmutable $fiveHourResetsAt,
        public float $sevenDayUsed,
        public ?DateTimeImmutable $sevenDayResetsAt,
    ) {}
}
