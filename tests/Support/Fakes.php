<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use FleetKit\Stage\Host;
use FleetKit\Stage\ProcessClock;
use FleetKit\Stage\Result;
use FleetKit\Stage\Shell;

/** Records every command and answers from a script: the first rule whose prefix matches wins. */
final class FakeShell implements Shell
{
    /** @var list<array{0: list<string>, 1: ?string}> */
    public array $calls = [];

    /** @param  list<array{0: list<string>, 1: Result|callable}>  $rules */
    public function __construct(private array $rules = []) {}

    public function run(array $argv, ?string $cwd = null): Result
    {
        $this->calls[] = [$argv, $cwd];
        foreach ($this->rules as [$prefix, $answer]) {
            if (array_slice($argv, 0, count($prefix)) === $prefix) {
                return is_callable($answer) ? $answer($argv) : $answer;
            }
        }

        return new Result(0);
    }

    /** @return list<string> the commands run, one line each */
    public function lines(): array
    {
        return array_map(fn (array $c) => implode(' ', $c[0]), $this->calls);
    }
}

/** A process table: pid => start time in UTC; absent means not running. */
final class FakeClock implements ProcessClock
{
    /** @param  array<int, string>  $starts */
    public function __construct(public array $starts = []) {}

    public function startedAt(int $pid): ?DateTimeImmutable
    {
        return isset($this->starts[$pid]) ? new DateTimeImmutable($this->starts[$pid], new \DateTimeZone('UTC')) : null;
    }
}

final class Fakes
{
    public const STARTED = '2026-10-20T13:39:13+00:00';

    /** @param  list<string>  $missing  commands that are not on PATH */
    public static function host(string $platform = 'Windows', string $home = '/home/x', array $missing = [], array $transcripts = [], bool $directories = true): Host
    {
        return new Host(
            home: $home,
            platform: $platform,
            onPath: fn (string $c) => ! in_array($c, $missing, true),
            isDirectory: fn (string $p) => $directories,
            hasTranscript: fn (string $id) => in_array($id, $transcripts, true),
        );
    }

    /** A temporary directory standing in for ~/.claude/sessions, with session files written in it. */
    public static function sessions(array $files): string
    {
        $home = sys_get_temp_dir().'/seat-stage-'.bin2hex(random_bytes(4));
        mkdir($home.'/.claude/sessions', 0777, true);
        foreach ($files as $pid => $data) {
            file_put_contents($home."/.claude/sessions/{$pid}.json", json_encode($data + ['pid' => $pid]));
        }

        return $home;
    }

    /** Windows FILETIME ticks for a UTC time. */
    public static function filetime(string $utc): int
    {
        return ((new DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp() + 11_644_473_600) * 10_000_000;
    }
}
