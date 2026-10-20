<?php

declare(strict_types=1);

namespace FleetKit\Pace;

use DateTimeZone;
use Exception;

/** An allowance belongs to an account: its harness, the machines that can read it, its reset and its line. */
final readonly class Account
{
    private const DAYS = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6, 'Sun' => 7];

    /**
     * @param  list<string>  $machines  the machines whose logins can read it, the preferred one first
     * @param  list<int>  $workingDays  ISO weekday numbers, 1 = Monday
     * @param  array{0: int, 1: int}  $workStart  hour and minute
     * @param  array{0: int, 1: int}  $workEnd  hour and minute
     * @param  array{0: int, 1: int}  $resetTime  hour and minute
     */
    public function __construct(
        public string $name,
        public string $harness,
        public array $machines,
        public DateTimeZone $timezone,
        public int $resetDay,
        public array $resetTime,
        public bool $workingHours,
        public array $workingDays,
        public array $workStart,
        public array $workEnd,
        public float $deadBand,
        public ?string $logExtra,
        public ?string $onChange,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $name = self::string($row, 'name', null);
        $at = "account \"{$name}\"";

        try {
            $timezone = new DateTimeZone(self::string($row, 'timezone', null, $at));
        } catch (Exception) {
            throw new ConfigError("{$at}: unknown timezone");
        }

        if (! preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+(\d{1,2}):(\d{2})$/', self::string($row, 'reset', null, $at), $m)) {
            throw new ConfigError("{$at}: reset must look like \"Mon 12:00\"");
        }
        $resetTime = self::clock("{$m[2]}:{$m[3]}", "{$at}: reset");
        if ($resetTime[0] === 24) {
            throw new ConfigError("{$at}: reset cannot be 24:00; use 00:00 on the next day");
        }

        $hours = self::string($row, 'hours', 'working', $at);
        if (! in_array($hours, ['working', 'clock'], true)) {
            throw new ConfigError("{$at}: hours must be \"working\" or \"clock\"");
        }

        $days = [];
        foreach ((array) ($row['working_days'] ?? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri']) as $day) {
            $days[] = self::DAYS[$day] ?? throw new ConfigError("{$at}: unknown working day \"{$day}\"");
        }
        if ($hours === 'working' && $days === []) {
            throw new ConfigError("{$at}: working_days is empty");
        }

        if (! preg_match('/^(\d{1,2}:\d{2})-(\d{1,2}:\d{2})$/', self::string($row, 'working_hours', '09:00-17:00', $at), $w)) {
            throw new ConfigError("{$at}: working_hours must look like \"09:00-17:00\"");
        }
        $start = self::clock($w[1], "{$at}: working_hours");
        $end = self::clock($w[2], "{$at}: working_hours");
        if ($hours === 'working' && ($end[0] * 60 + $end[1]) <= ($start[0] * 60 + $start[1])) {
            throw new ConfigError("{$at}: working_hours must end after it starts");
        }

        $band = $row['dead_band'] ?? 0;
        if (! is_int($band) && ! is_float($band) || $band < 0) {
            throw new ConfigError("{$at}: dead_band must be a number of percentage points, 0 or more");
        }

        return new self(
            name: $name,
            harness: self::string($row, 'harness', null, $at),
            machines: self::machines($row['machine'] ?? null, $at),
            timezone: $timezone,
            resetDay: self::DAYS[$m[1]],
            resetTime: $resetTime,
            workingHours: $hours === 'working',
            workingDays: array_values(array_unique($days)),
            workStart: $start,
            workEnd: $end,
            deadBand: (float) $band,
            logExtra: isset($row['log_extra']) ? self::string($row, 'log_extra', null, $at) : null,
            onChange: isset($row['on_change']) ? self::string($row, 'on_change', null, $at) : null,
        );
    }

    /** Where this host stands in the account's machine list: 0 is the preferred machine, null is not listed. */
    public function position(string $host): ?int
    {
        foreach ($this->machines as $index => $machine) {
            if (strcasecmp($machine, $host) === 0) {
                return $index;
            }
        }

        return null;
    }

    public function hasFallback(): bool
    {
        return count($this->machines) > 1;
    }

    /**
     * One machine name, or a list in priority order. A plain string is a list of one.
     *
     * @return list<string>
     */
    private static function machines(mixed $value, string $at): array
    {
        $names = is_array($value) ? $value : [$value];
        $machines = [];
        foreach ($names as $name) {
            if (! is_string($name) || $name === '') {
                throw new ConfigError("{$at}: machine is required, a name or a list of names");
            }
            if (in_array(strtolower($name), array_map('strtolower', $machines), true)) {
                throw new ConfigError("{$at}: machine \"{$name}\" is listed twice");
            }
            $machines[] = $name;
        }

        return $machines === [] ? throw new ConfigError("{$at}: machine is required, a name or a list of names") : $machines;
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $key, ?string $default, string $at = 'account'): string
    {
        $value = $row[$key] ?? $default;
        if (! is_string($value) || $value === '') {
            throw new ConfigError("{$at}: {$key} is required");
        }

        return $value;
    }

    /** @return array{0: int, 1: int} */
    private static function clock(string $text, string $at): array
    {
        [$h, $m] = array_map('intval', explode(':', $text));
        if ($h > 24 || $m > 59 || ($h === 24 && $m > 0)) {
            throw new ConfigError("{$at}: {$text} is not a time of day");
        }

        return [$h, $m];
    }
}
