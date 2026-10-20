<?php

declare(strict_types=1);

namespace FleetKit\Pace;

use Closure;
use DateTimeImmutable;
use Exception;
use Throwable;

/**
 * seat-pace read | pace | log | watch.
 * Exit status: 0 UNDER, 3 OVER, 1 a read failed, 2 the configuration or command line is wrong.
 * The kit reports a verdict and never acts on one; `on_change` is the team's own hook.
 */
final class Cli
{
    public const UNDER = 0;

    public const READ_FAILED = 1;

    public const CONFIG_ERROR = 2;

    public const OVER = 3;

    private const USAGE = <<<'TXT'
        usage:
          seat-pace read  [account]                 5h and 7d utilization and reset times
          seat-pace pace  [account] [--json]        the verdict line
          seat-pace pace  [account] --used 46 --now <iso>   arithmetic only; no reading
          seat-pace log   [account] [--note ...]    append a row to the log
          seat-pace watch [account] [--every 55m] [--once]  read, log, run on_change when the verdict changes
        options: --config <file> (or FLEET_KIT_CONFIG; default seats.toml)
        exit status: 0 UNDER, 3 OVER, 1 read failed, 2 configuration error
        TXT;

    /**
     * @param  Closure(string): void  $out
     * @param  Closure(string): void  $err
     * @param  Closure(): DateTimeImmutable  $clock
     * @param  Closure(string, array<string, string>): string  $shell  runs a command, returns its stdout
     * @param  Closure(int): void  $sleep
     */
    public function __construct(
        private readonly Closure $out,
        private readonly Closure $err,
        private readonly UsageReader $reader,
        private readonly Closure $clock,
        private readonly Closure $shell,
        private readonly Closure $sleep,
        private readonly string $machine,
        private readonly ?Presence $presence = null,
    ) {}

    /** @param  list<string>  $argv  the arguments after the program name */
    public function run(array $argv, ?string $envConfig = null): int
    {
        try {
            [$command, $args, $options] = $this->parse($argv);
            if ($command === null || isset($options['help'])) {
                ($this->out)(self::USAGE);

                return $command === null && ! isset($options['help']) ? self::CONFIG_ERROR : self::UNDER;
            }

            if (! in_array($command, ['pace', 'read', 'log', 'watch'], true)) {
                throw new ConfigError("unknown command \"{$command}\"");
            }

            $config = Config::load($options['config'] ?? $envConfig ?? 'seats.toml');

            return match ($command) {
                'pace' => $this->pace($config->account($args[0] ?? null), $options),
                'read' => $this->read($config->account($args[0] ?? null)),
                'log' => $this->log($config->account($args[0] ?? null), $config, $options),
                'watch' => $this->watch($args[0] ?? null, $config, $options),
            };
        } catch (ConfigError $e) {
            ($this->err)('seat-pace: '.$e->getMessage());

            return self::CONFIG_ERROR;
        } catch (ReadFailed $e) {
            ($this->err)('seat-pace: '.$e->getMessage());

            return self::READ_FAILED;
        } catch (Throwable $e) {
            ($this->err)('seat-pace: unexpected '.$e::class.': '.$e->getMessage());

            return self::READ_FAILED;
        }
    }

    /** @param  array<string, string|true>  $options */
    private function pace(Account $account, array $options): int
    {
        if (isset($options['used']) !== isset($options['now'])) {
            throw new ConfigError('--used and --now go together');
        }

        if (isset($options['used'])) {
            $used = $this->number($options['used']);
            $now = $this->time($options['now']);
        } else {
            $this->mayRead($account);
            $now = ($this->clock)();
            $used = $this->reading($account, $now)->sevenDayUsed;
        }

        $verdict = Pace::judge($account, $used, $now);
        ($this->out)(isset($options['json'])
            ? json_encode(['account' => $account->name] + $verdict->toArray(), JSON_UNESCAPED_SLASHES)
            : $verdict->line($account->name));

        return $verdict->over() ? self::OVER : self::UNDER;
    }

    private function read(Account $account): int
    {
        $this->mayRead($account);
        $usage = $this->reading($account, ($this->clock)());
        $at = fn (?DateTimeImmutable $t) => $t?->setTimezone($account->timezone)->format('Y-m-d H:i T') ?? 'unknown';

        ($this->out)(sprintf('5h used=%s%% resets=%s', Verdict::num($usage->fiveHourUsed), $at($usage->fiveHourResetsAt)));
        ($this->out)(sprintf('7d used=%s%% resets=%s', Verdict::num($usage->sevenDayUsed), $at($usage->sevenDayResetsAt)));

        return self::UNDER;
    }

    /** @param  array<string, string|true>  $options */
    private function log(Account $account, Config $config, array $options): int
    {
        $this->mayRead($account);
        $this->record($account, $config, is_string($options['note'] ?? null) ? $options['note'] : '');

        return self::UNDER;
    }

    /** @param  array<string, string|true>  $options */
    private function watch(?string $name, Config $config, array $options): int
    {
        $every = $this->every(is_string($options['every'] ?? null) ? $options['every'] : '55m');

        // Unnamed, watch covers every account this machine can read.
        $accounts = $name === null
            ? array_values(array_filter($config->accounts, fn (Account $a) => $a->position($this->machine) !== null))
            : [$config->account($name)];
        if ($accounts === []) {
            throw new ConfigError("no account is read on \"{$this->machine}\"");
        }
        foreach ($accounts as $account) {
            $this->mayRead($account);
        }

        do {
            $failed = false;
            $present = [];
            foreach ($accounts as $account) {
                // One account's failed read must not stop the others, or the next tick.
                try {
                    if ($this->standingBy($account, $present)) {
                        continue;
                    }
                    // Stale after one and a half intervals: a normal next tick is one interval on, and a tick
                    // stood by is two, so the two cannot be confused by a little scheduler jitter.
                    $this->record($account, $config, '', true, intdiv($every * 3, 2));
                } catch (ReadFailed $e) {
                    ($this->err)("seat-pace: {$account->name}: ".$e->getMessage());
                    $failed = true;
                }
            }
            if (isset($options['once'])) {
                return $failed ? self::READ_FAILED : self::UNDER;
            }
            ($this->sleep)($every);
        } while (true);
    }

    /**
     * Whether this machine should leave the account to a machine earlier in its list this tick. The
     * preferred machine never stands by and never asks. A later one asks the fleet whether any machine
     * before it has an active session, and stands by if one does. If it cannot ask, it does not read:
     * it never assumes the preferred machine is down.
     *
     * @param  array<string, list<string>|ReadFailed>  $present  the fleet's answer (or why there is none) for each harness, asked once a tick
     *
     * @throws ReadFailed
     */
    private function standingBy(Account $account, array &$present): bool
    {
        $position = (int) $account->position($this->machine);
        if ($position === 0) {
            return false;
        }

        $before = array_slice($account->machines, 0, $position);
        if (! isset($present[$account->harness])) {
            // A failed ask is kept for the tick too, so accounts sharing a harness do not each wait on it.
            try {
                $present[$account->harness] = $this->presence?->activeMachines($account->harness) ?? throw new ReadFailed('no way to ask the fleet');
            } catch (ReadFailed $e) {
                $present[$account->harness] = $e;
            }
        }
        if ($present[$account->harness] instanceof ReadFailed) {
            throw new ReadFailed('cannot tell whether '.implode(' or ', $before).' is reading, so not reading ('.$present[$account->harness]->getMessage().')');
        }

        foreach ($before as $machine) {
            if (in_array(strtolower($machine), $present[$account->harness], true)) {
                ($this->out)(sprintf('STANDBY account=%s reading=%s', $account->name, $machine));

                return true;
            }
        }

        return false;
    }

    /**
     * Read, judge and log one row; with $notify, run on_change when the verdict differs from the last row's.
     *
     * An account with a fallback is read by different machines, each with a log of its own, so a row
     * older than $staleAfter seconds is not a fair "previous verdict": the other machine may have
     * read since. The reading after such a gap is logged as a fresh start and does not run on_change,
     * rather than reporting a change that may not have happened.
     */
    private function record(Account $account, Config $config, string $note, bool $notify = false, ?int $staleAfter = null): Verdict
    {
        $now = ($this->clock)();
        $used = $this->reading($account, $now)->sevenDayUsed;
        $verdict = Pace::judge($account, $used, $now);

        $log = new Log($config->log);
        $last = $log->last($account->name);
        $previous = $last['verdict'] ?? null;
        if ($notify && $previous !== null && $account->hasFallback() && $staleAfter !== null
            && ($last['at'] === null || $now->getTimestamp() - $last['at']->getTimestamp() > $staleAfter)) {
            ($this->err)(sprintf('seat-pace: %s: the last row here is older than %d minutes, so this reading is a fresh start and on_change does not run', $account->name, intdiv($staleAfter, 60)));
            $previous = null;
        }
        $extra = $account->logExtra === null ? '' : trim(($this->shell)($account->logExtra, []));
        $log->append($now, $account->name, $used, $verdict, $extra, $note);
        ($this->out)($verdict->line($account->name));

        if ($notify && $account->onChange !== null && $previous !== null && $previous !== $verdict->verdict) {
            ($this->shell)($account->onChange, [
                'SEAT_PACE_ACCOUNT' => $account->name,
                'SEAT_PACE_VERDICT' => $verdict->verdict,
                'SEAT_PACE_PREVIOUS' => $previous,
            ]);
        }

        return $verdict;
    }

    private function reading(Account $account, DateTimeImmutable $now): Usage
    {
        $usage = $this->reader->read($account);

        // The configured reset is authoritative; the endpoint's is a cross-check.
        $reported = $usage->sevenDayResetsAt;
        if ($reported !== null && abs($reported->getTimestamp() - Pace::nextReset($account, $now)->getTimestamp()) > 300) {
            ($this->err)(sprintf(
                'seat-pace: warning: account "%s" reports a reset at %s but the configured reset is %s',
                $account->name,
                $reported->setTimezone($account->timezone)->format('Y-m-d H:i T'),
                Pace::nextReset($account, $now)->format('Y-m-d H:i T'),
            ));
        }

        return $usage;
    }

    /** Reading needs a machine whose login holds the credential: one the account lists. */
    private function mayRead(Account $account): void
    {
        if ($account->position($this->machine) === null) {
            throw new ConfigError("account \"{$account->name}\" is read on ".implode(', ', array_map(fn (string $m) => "\"{$m}\"", $account->machines)).", not on \"{$this->machine}\"");
        }
    }

    /** @return array{0: ?string, 1: list<string>, 2: array<string, string|true>} */
    private function parse(array $argv): array
    {
        $flags = ['json', 'once', 'help'];
        $words = [];
        $options = [];
        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if (! str_starts_with($arg, '--')) {
                $words[] = $arg;

                continue;
            }
            $name = substr($arg, 2);
            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
                $options[$name] = $value;
            } elseif (in_array($name, $flags, true)) {
                $options[$name] = true;
            } elseif (isset($argv[$i + 1])) {
                $options[$name] = $argv[++$i];
            } else {
                throw new ConfigError("--{$name} needs a value");
            }
        }

        return [array_shift($words), $words, $options];
    }

    private function number(mixed $value): float
    {
        if (! is_string($value) || ! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            throw new ConfigError('--used must be a percentage, 0 or more');
        }

        return (float) $value;
    }

    private function time(mixed $value): DateTimeImmutable
    {
        // An explicit offset is required: a bare time would be read in PHP's default timezone.
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/i', $value)) {
            throw new ConfigError('--now must be an ISO 8601 time with an offset, like 2026-10-21T12:00:00-05:00');
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            throw new ConfigError('--now must be an ISO 8601 time with an offset, like 2026-10-21T12:00:00-05:00');
        }
    }

    private function every(string $text): int
    {
        if (! preg_match('/^(\d+)([smh])$/', $text, $m) || (int) $m[1] === 0) {
            throw new ConfigError('--every must look like 55m, 30s or 2h');
        }

        return (int) $m[1] * ['s' => 1, 'm' => 60, 'h' => 3600][$m[2]];
    }
}
