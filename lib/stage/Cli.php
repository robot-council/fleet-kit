<?php

declare(strict_types=1);

namespace FleetKit\Stage;

use Closure;
use FleetKit\Pace\ConfigError;
use Throwable;

/**
 * seat-stage stage | collect | check.
 * Exit status: 0 done, 1 a check failed or a step failed, 2 the configuration or command line is wrong.
 */
final class Cli
{
    private const USAGE = <<<'TXT'
        usage:
          seat-stage stage   <machine> [--dry-run]   build one multiplexer session with a window per seat
          seat-stage collect <machine>               read conversation ids from live processes into the seats file
          seat-stage check   <machine>               report every reason staging would fail, changing nothing
        options: --config <file> (or FLEET_KIT_CONFIG; default seats.toml)
        --dry-run prints each seat's exact command and first prompt and touches nothing.
        exit status: 0 done, 1 a check or a step failed, 2 configuration error
        TXT;

    /**
     * @param  Closure(string): void  $out
     * @param  Closure(string): void  $err
     * @param  Closure(int): void  $sleep
     */
    public function __construct(
        private readonly Closure $out,
        private readonly Closure $err,
        private readonly Shell $shell,
        private readonly Host $host,
        private readonly ProcessClock $clock,
        private readonly Closure $sleep,
    ) {}

    /** @param  list<string>  $argv  the arguments after the program name */
    public function run(array $argv, ?string $envConfig = null): int
    {
        try {
            [$command, $machine, $options] = $this->parse($argv);
            if (isset($options['help'])) {
                ($this->out)(self::USAGE);

                return 0;
            }
            if (! in_array($command, ['stage', 'collect', 'check'], true)) {
                throw new ConfigError($command === null ? self::USAGE : "unknown command \"{$command}\"");
            }
            $machine !== null || throw new ConfigError("{$command} needs a machine");
            isset($options['dry-run']) && $command === 'check' && throw new ConfigError('check already changes nothing; --dry-run is for stage and collect');

            $path = $options['config'] ?? $envConfig ?? 'seats.toml';
            $fleet = Fleet::load($path);
            $target = $fleet->machineNamed($machine);
            $holders = new Holders($this->host->sessionsDirectory(), $this->clock, $this->host->platform);

            return match ($command) {
                'check' => $this->check($fleet, $target, $holders),
                'collect' => (new Collector($fleet, $holders, $this->out))->collect($target, $path, isset($options['dry-run'])),
                'stage' => $this->stage($fleet, $target, $holders, isset($options['dry-run'])),
            };
        } catch (ConfigError $e) {
            ($this->err)('seat-stage: '.$e->getMessage());

            return 2;
        } catch (Throwable $e) {
            ($this->err)('seat-stage: '.$e->getMessage());

            return 1;
        }
    }

    private function check(Fleet $fleet, Machine $machine, Holders $holders): int
    {
        $report = (new Checker($fleet, $this->shell, $this->host, $holders))->check($machine);
        foreach ($report->notes as $note) {
            ($this->out)("note: {$note}");
        }
        foreach ($report->failures as $failure) {
            ($this->out)("fail: {$failure}");
        }
        $report->ok() && ($this->out)("ok: {$machine->name} would stage");

        return $report->ok() ? 0 : 1;
    }

    private function stage(Fleet $fleet, Machine $machine, Holders $holders, bool $dryRun): int
    {
        $stager = new Stager($fleet, $this->shell, $this->host, $holders, $this->out, $this->sleep);
        if ($dryRun) {
            $stager->dryRun($machine);

            return 0;
        }

        return $stager->stage($machine);
    }

    /** @return array{0: ?string, 1: ?string, 2: array<string, string|true>} */
    private function parse(array $argv): array
    {
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
            } elseif (in_array($name, ['dry-run', 'help'], true)) {
                $options[$name] = true;
            } elseif (isset($argv[$i + 1])) {
                $options[$name] = $argv[++$i];
            } else {
                throw new ConfigError("--{$name} needs a value");
            }
        }

        return [array_shift($words), array_shift($words), $options];
    }
}

/** Where a process's start time can't be read (Linux, until measured): nothing is claimed. */
final class UnknownClock implements ProcessClock
{
    public function startedAt(int $pid): ?\DateTimeImmutable
    {
        return null;
    }
}
