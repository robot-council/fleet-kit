<?php

declare(strict_types=1);

namespace FleetKit\Stage;

/**
 * What a seat runs: the first prompt, and the command line that carries it.
 *
 * The prompt goes BEFORE --dangerously-load-development-channels: after it, the flag reads the
 * prompt as another channel entry and the session refuses to start (fleet-kit#7, measured on
 * Windows with Claude Code 2.1.304). Only tagged entries follow the flag.
 */
final class Launch
{
    /** Environment names a parent Claude Code session leaks into a child; scrubbed on Windows. */
    private const LEAKED = '^(CLAUDE_CODE_|CLAUDECODE$|CLAUDE_PID$)';

    /**
     * The first message a seat receives. A conversation that already exists is already joined, so
     * it is told to heartbeat, never to leave: leaving releases the tasks it holds.
     */
    public static function prompt(Seat $seat): string
    {
        $join = $seat->capacity === null ? '' : ", capacity: {$seat->capacity}";

        $first = $seat->conversation === null
            ? "Join the Robot Council fleet: call the `join` tool of the robot-council server with role: {$seat->role}, repository: {$seat->repository}, work_location: {$seat->workLocation}{$join}."
            : 'You are resuming in the Robot Council fleet and are already joined. Call the `presence_heartbeat` tool of the robot-council server. '
                ."If your recorded work location is not {$seat->workLocation}, call `session_move` with repository: {$seat->repository}, work_location: {$seat->workLocation}. "
                .'Do not call `leave` or `join` again: leaving releases the tasks you hold.';

        return trim($first.($seat->prompt === '' ? '' : ' '.preg_replace('/\s+/', ' ', $seat->prompt)));
    }

    /**
     * The arguments after `claude`.
     *
     * @return list<string>
     */
    public static function arguments(Seat $seat, Machine $machine, Fleet $fleet): array
    {
        $arguments = [self::prompt($seat)];
        if ($seat->conversation !== null) {
            array_push($arguments, '--resume', $seat->conversation);
        }
        if ($machine->remoteControl) {
            array_push($arguments, '--remote-control', $seat->remoteControl);
        }
        array_push($arguments, '--dangerously-load-development-channels', $fleet->channel);

        return $arguments;
    }

    /** The line a window runs, in the machine's shell, with a clean environment. */
    public static function command(Seat $seat, Machine $machine, Fleet $fleet): string
    {
        $claude = implode(' ', array_map(
            fn (string $a) => $machine->windows() ? self::powershell($a) : self::posix($a),
            ['claude', ...self::arguments($seat, $machine, $fleet)],
        ));

        if ($machine->windows()) {
            // No `env -i` on Windows: drop the variables a parent Claude Code session would leak.
            return "Get-ChildItem Env: | Where-Object { \$_.Name -match '".self::LEAKED."' } | ForEach-Object { Remove-Item (\"Env:\" + \$_.Name) }; {$claude}";
        }

        return 'env -i HOME="$HOME" PATH="$PATH" USER="$USER" LOGNAME="$LOGNAME" SHELL="$SHELL" TERM="${TERM:-xterm-256color}" LANG="${LANG:-en_US.UTF-8}" '.$claude;
    }

    /** `enter` start mode: hold the window at a prompt until the operator presses Enter. */
    public static function waiting(string $command, Seat $seat, Machine $machine): string
    {
        return $machine->windows()
            ? 'Read-Host '.self::powershell("Press Enter to start {$seat->label()}").' | Out-Null; '.$command
            : 'printf %s '.self::posix("Press Enter to start {$seat->label()} ").'; read _; '.$command;
    }

    public static function posix(string $text): string
    {
        return preg_match('/^[A-Za-z0-9_@%+=:,.\/-]+$/', $text) ? $text : "'".str_replace("'", "'\\''", $text)."'";
    }

    public static function powershell(string $text): string
    {
        if (preg_match('/^[A-Za-z0-9_%+=:.\/\\\\-]+$/', $text)) {
            return $text;
        }

        // PowerShell reads the curly single quotes U+2018 to U+201B as quotes too, so each is doubled.
        return "'".preg_replace('/([\'\x{2018}\x{2019}\x{201A}\x{201B}])/u', '$1$1', $text)."'";
    }
}
