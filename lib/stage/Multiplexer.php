<?php

declare(strict_types=1);

namespace FleetKit\Stage;

/** What a command run reports. */
final readonly class Result
{
    public function __construct(public int $code, public string $out = '') {}
}

/** Runs a program with an argument list and no shell of its own. */
interface Shell
{
    /** @param  list<string>  $argv */
    public function run(array $argv, ?string $cwd = null): Result;
}

/** The real thing. */
final class ProcessShell implements Shell
{
    public function run(array $argv, ?string $cwd = null): Result
    {
        $process = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (! is_resource($process)) {
            return new Result(127);
        }
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return new Result(proc_close($process), $out);
    }
}

/**
 * A tmux-compatible multiplexer: tmux on macOS and Linux, psmux on Windows. Both are driven with
 * the same argument lists, except that tmux is given `=name` exact-match targets and psmux the plain
 * names: psmux does not prefix-match a session and rejects `=window` (fleet-kit#4, psmux 3.3.8).
 */
final readonly class Multiplexer
{
    public function __construct(public string $binary) {}

    public static function for(Machine $machine): self
    {
        return new self($machine->multiplexer);
    }

    /** @return list<string> */
    public function hasSession(string $session): array
    {
        return [$this->binary, 'has-session', '-t', $this->session($session)];
    }

    /** tmux matches a session name by prefix unless it is written `=name`; psmux does not prefix-match and rejects `=window`, so it gets the plain names (fleet-kit#4). */
    private function session(string $session): string
    {
        return $this->binary === 'tmux' ? "={$session}" : $session;
    }

    private function target(string $session, string $window): string
    {
        return $this->session($session).':'.($this->binary === 'tmux' ? "={$window}" : $window);
    }

    /**
     * Creates the session with its first window. A null command leaves the window at an idle shell.
     *
     * @return list<string>
     */
    public function newSession(string $session, string $window, string $directory, ?string $command): array
    {
        return array_values(array_filter(
            [$this->binary, 'new-session', '-d', '-s', $session, '-n', $window, '-c', $directory, $command],
            fn (?string $part) => $part !== null,
        ));
    }

    /** @return list<string> */
    public function newWindow(string $session, string $window, string $directory, ?string $command): array
    {
        return array_values(array_filter(
            [$this->binary, 'new-window', '-t', $this->session($session).':','-n', $window, '-c', $directory, $command],
            fn (?string $part) => $part !== null,
        ));
    }

    /** @return list<string> */
    public function capture(string $session, string $window): array
    {
        return [$this->binary, 'capture-pane', '-p', '-t', $this->target($session, $window)];
    }

    /** @return list<string> */
    public function type(string $session, string $window, string $text): array
    {
        return [$this->binary, 'send-keys', '-t', $this->target($session, $window), '-l', $text];
    }

    /** @return list<string> */
    public function key(string $session, string $window, string $key): array
    {
        return [$this->binary, 'send-keys', '-t', $this->target($session, $window), $key];
    }
}
