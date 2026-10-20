<?php

declare(strict_types=1);

namespace FleetKit\Stage;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use RuntimeException;

/** The start time of a process, or whether a conversation is held, could not be read. */
final class Unreadable extends RuntimeException {}

/** When a process started, in UTC; null when it is not running. Throws Unreadable when it can't tell. */
interface ProcessClock
{
    /** @throws Unreadable */
    public function startedAt(int $pid): ?DateTimeImmutable;
}

/** Windows: the process's CreationDate, which a session file's FILETIME `procStart` equals. */
final readonly class WindowsClock implements ProcessClock
{
    public function __construct(private Shell $shell) {}

    public function startedAt(int $pid): ?DateTimeImmutable
    {
        $result = $this->shell->run([
            'powershell', '-NoProfile', '-NonInteractive', '-Command',
            "\$p = Get-CimInstance Win32_Process -Filter 'ProcessId={$pid}'; if (\$p) { \$p.CreationDate.ToUniversalTime().ToString('o') }",
        ]);
        $text = trim($result->out);

        if ($result->code !== 0) {
            throw new Unreadable("could not read the start time of process {$pid}");
        }

        // Exit 0 and nothing printed: no such process.
        return $text === '' ? null : self::utc($text, $pid);
    }

    private static function utc(string $text, int $pid): DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($text))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            throw new Unreadable("could not read the start time of process {$pid}: \"{$text}\"");
        }
    }
}

/** macOS: `ps -o lstart=` read in UTC, which is the form a session file's `procStart` is compared in. */
final readonly class PsClock implements ProcessClock
{
    public function __construct(private Shell $shell) {}

    public function startedAt(int $pid): ?DateTimeImmutable
    {
        $result = $this->shell->run(['env', 'TZ=UTC', 'ps', '-o', 'lstart=', '-p', (string) $pid]);
        $text = preg_replace('/\s+/', ' ', trim($result->out));

        // `ps -p` for a pid that is not running prints nothing and exits 1; anything else is a failure to read.
        if ($text === '') {
            return $result->code === 1 ? null : throw new Unreadable("could not read the start time of process {$pid}");
        }

        return $result->code === 0 ? (self::parse($text) ?? throw new Unreadable("could not read the start time of process {$pid}: \"{$text}\"")) : throw new Unreadable("could not read the start time of process {$pid}");
    }

    public static function parse(string $lstart): ?DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!D M j H:i:s Y', $lstart, new DateTimeZone('UTC'));

        return $time === false ? null : $time;
    }
}

/** One live `~/.claude/sessions/<pid>.json`: a process holding a conversation. */
final readonly class Holder
{
    public function __construct(public int $pid, public string $conversation, public string $cwd) {}
}

/**
 * Finds the processes holding conversations, as measured in fleet-kit#3 on Windows: a conversation
 * is held when a `sessions/<pid>.json` carries its `sessionId`, the `pid` is alive, and `procStart`
 * equals that process's start time. Where the file or the start time can't be read, nothing is
 * claimed: `readable()` says so, and the caller refuses instead of guessing.
 */
final readonly class Holders
{
    /** Seconds either side of `procStart` a start time may differ by and still be the same process. */
    private const TOLERANCE = 2;

    public function __construct(
        private string $sessionsDirectory,
        private ProcessClock $clock,
        private string $platform,
    ) {}

    /** Measured on Windows (#3); macOS is compared by the same rule but unmeasured here; Linux is not read. */
    public function readable(): bool
    {
        return in_array($this->platform, ['Windows', 'Darwin'], true) && is_dir($this->sessionsDirectory);
    }

    /**
     * @return list<Holder>
     *
     * @throws Unreadable when a session file's process can't be told apart from a recycled pid
     */
    public function live(): array
    {
        if (! $this->readable()) {
            return [];
        }

        $holders = [];
        foreach (self::files($this->sessionsDirectory) as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (! is_array($data) || ! is_int($data['pid'] ?? null) || ! is_string($data['sessionId'] ?? null)) {
                continue;
            }
            $recorded = self::procStart($data['procStart'] ?? null)
                ?? throw new Unreadable("could not read procStart in {$file}; cannot tell whether process {$data['pid']} holds {$data['sessionId']}");
            $actual = $this->clock->startedAt($data['pid']);
            if ($actual === null || abs($recorded->getTimestamp() - $actual->getTimestamp()) > self::TOLERANCE) {
                continue;
            }
            $holders[] = new Holder($data['pid'], $data['sessionId'], is_string($data['cwd'] ?? null) ? $data['cwd'] : '');
        }

        return $holders;
    }

    /** @return list<string> the `*.json` files in a directory; a glob would misread a `[` in the path */
    private static function files(string $directory): array
    {
        $files = [];
        foreach (scandir($directory) ?: [] as $name) {
            str_ends_with($name, '.json') && $files[] = $directory.DIRECTORY_SEPARATOR.$name;
        }

        return $files;
    }

    public function holding(string $conversation): ?Holder
    {
        foreach ($this->live() as $holder) {
            if ($holder->conversation === $conversation) {
                return $holder;
            }
        }

        return null;
    }

    /** A Windows FILETIME (100 ns ticks since 1601), or a UTC date string. */
    public static function procStart(mixed $value): ?DateTimeImmutable
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $seconds = intdiv((int) $value, 10_000_000) - 11_644_473_600;

            return (new DateTimeImmutable('@'.$seconds))->setTimezone(new DateTimeZone('UTC'));
        }
        if (is_string($value) && $value !== '') {
            return PsClock::parse(preg_replace('/\s+/', ' ', trim($value)) ?? '') ?? self::date($value);
        }

        return null;
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }
}
