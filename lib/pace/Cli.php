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
            ? array_values(array_filter($config->accounts, fn (Account $a) => strcasecmp($a->machine, $this->machine) === 0))
            : [$config->account($name)];
        if ($accounts === []) {
            throw new ConfigError("no account is read on \"{$this->machine}\"");
        }
        foreach ($accounts as $account) {
            $this->mayRead($account);
        }

        do {
            $failed = false;
            foreach ($accounts as $account) {
                // One account's failed read must not stop the others, or the next tick.
                try {
                    $this->record($account, $config, '', true);
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

    /** Read, judge and log one row; with $notify, run on_change when the verdict differs from the last row's. */
    private function record(Account $account, Config $config, string $note, bool $notify = false): Verdict
    {
        $now = ($this->clock)();
        $used = $this->reading($account, $now)->sevenDayUsed;
        $verdict = Pace::judge($account, $used, $now);

        $log = new Log($config->log);
        $previous = $log->lastVerdict($account->name);
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

    /** Reading needs the machine whose login holds the credential. */
    private function mayRead(Account $account): void
    {
        if (strcasecmp($account->machine, $this->machine) !== 0) {
            throw new ConfigError("account \"{$account->name}\" is read on \"{$account->machine}\", not on \"{$this->machine}\"");
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
