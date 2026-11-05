<?php

declare(strict_types=1);

namespace FleetKit\Stage;

use Closure;
use FleetKit\ConfigLocator;
use FleetKit\Pace\ConfigError;
use FleetKit\Release;
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
          seat-stage config  set <file> | show | unset  remember where the seats file is, once per machine
        options: --config <file>, --pull (check and stage: git pull --ff-only the seats file's repository first), --version
        the seats file is --config, else FLEET_KIT_CONFIG, else the path remembered by `config set`, else ./seats.toml
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
        private readonly ?Release $release = null,
        private readonly ?ConfigLocator $locator = null,
        private readonly ?SeatsRepo $repo = null,
    ) {}

    /** @param  list<string>  $argv  the arguments after the program name */
    public function run(array $argv, ?string $envConfig = null): int
    {
        $notify = false;
        $code = $this->dispatch($argv, $envConfig, $notify);
        // One line on stderr after the command, when the install is behind; it never changes the exit status.
        $notify && ($notice = $this->release?->behindNotice()) !== null && ($this->err)('seat-stage: '.$notice);

        return $code;
    }

    /** @param  list<string>  $argv */
    private function dispatch(array $argv, ?string $envConfig, bool &$notify): int
    {
        try {
            [$command, $machine, $options, $rest] = $this->parse($argv);
            if (isset($options['version'])) {
                ($this->out)('seat-stage '.($this->release?->installed() ?? 'unknown'));

                return 0;
            }
            if (isset($options['help'])) {
                ($this->out)(self::USAGE);

                return 0;
            }
            if ($command === 'config') {
                return $this->config($machine, $rest, $options, $envConfig);
            }
            if (! in_array($command, ['stage', 'collect', 'check'], true)) {
                throw new ConfigError($command === null ? self::USAGE : "unknown command \"{$command}\"");
            }
            $notify = $command !== 'collect';
            $machine !== null || throw new ConfigError("{$command} needs a machine");
            isset($options['dry-run']) && $command === 'check' && throw new ConfigError('check already changes nothing; --dry-run is for stage and collect');

            isset($options['pull']) && $command === 'collect' && throw new ConfigError('--pull is for check and stage');
            ['path' => $path, 'source' => $source] = $this->locate($options, $envConfig);
            is_file($path) || throw ConfigLocator::missing($path, $source);
            if ($command !== 'collect') {
                $this->seatsRepository($path, isset($options['pull']));
            }
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

    /** @param  array<string, string|true>  $options
     * @return array{path: string, source: string} */
    private function locate(array $options, ?string $envConfig): array
    {
        $flag = is_string($options['config'] ?? null) ? $options['config'] : null;

        return $this->locator?->resolve($flag, $envConfig) ?? ['path' => $flag ?? $envConfig ?? ConfigLocator::DEFAULT, 'source' => $flag !== null ? '--config' : ($envConfig !== null ? ConfigLocator::ENV : 'the default')];
    }

    /** Says when the seats file's repository is behind its upstream, and with --pull fast-forwards it first. */
    private function seatsRepository(string $path, bool $pull): void
    {
        if ($this->repo === null) {
            return;
        }
        $dir = $this->repo->workTree($path);
        if ($dir === null) {
            $pull && throw new ConfigError("--pull needs the seats file to be in a git work tree: {$path}");

            return;
        }
        $behind = $this->repo->behind($dir);
        if ($behind === null || $behind === 0) {
            return;
        }
        if (! $pull) {
            ($this->out)("note: the seats file's repository ({$dir}) is {$behind} commit(s) behind its upstream; update it with `git -C \"{$dir}\" pull --ff-only`, or pass --pull");

            return;
        }
        $result = $this->repo->pull($dir);
        $result->code === 0 || throw new \RuntimeException("git pull --ff-only failed in {$dir}; the seats file is {$behind} commit(s) behind and was not updated");
        ($this->out)("note: pulled {$behind} commit(s) into the seats file's repository ({$dir})");
    }

    /**
     * seat-stage config set <file> | show | unset
     *
     * @param  list<string>  $rest
     * @param  array<string, string|true>  $options
     */
    private function config(?string $action, array $rest, array $options, ?string $envConfig): int
    {
        $this->locator !== null || throw new ConfigError('config is not available here');
        switch ($action) {
            case 'set':
                count($rest) === 1 || throw new ConfigError('usage: seat-stage config set <path to seats.toml>');
                $path = $this->locator->remember($rest[0]);
                ($this->out)("remembered: {$path} (in {$this->locator->file()})");
                $this->locator->resolve(null, $envConfig)['source'] === ConfigLocator::ENV && ($this->out)('note: '.ConfigLocator::ENV.' is set and is read first; unset it to use the remembered path');

                return 0;
            case 'show':
                ['path' => $path, 'source' => $source] = $this->locate($options, $envConfig);
                ($this->out)("seats file: {$path}");
                ($this->out)("from: {$source}");
                ($this->out)(is_file($path) ? 'exists: yes' : 'exists: no');
                ($this->out)('remembered: '.($this->locator->remembered() ?? 'nothing'));

                return 0;
            case 'unset':
                ($this->out)($this->locator->forget() ? 'forgot the remembered path' : 'nothing was remembered');

                return 0;
            default:
                throw new ConfigError('usage: seat-stage config set <path to seats.toml> | show | unset');
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

    /** @return array{0: ?string, 1: ?string, 2: array<string, string|true>, 3: list<string>} */
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
            } elseif (in_array($name, ['dry-run', 'help', 'version', 'pull'], true)) {
                $options[$name] = true;
            } elseif (isset($argv[$i + 1])) {
                $options[$name] = $argv[++$i];
            } else {
                throw new ConfigError("--{$name} needs a value");
            }
        }

        return [array_shift($words), array_shift($words), $options, $words];
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
