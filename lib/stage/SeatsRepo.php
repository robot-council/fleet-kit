<?php

declare(strict_types=1);

namespace FleetKit\Stage;

use Closure;

/**
 * The configuration repository the seats file sits in (fleet-kit#33): whether it is behind its upstream, and,
 * only when the operator asks with --pull, a fast-forward pull. Reading fetches remote refs and nothing else;
 * the work tree is never touched unless --pull was given.
 */
final class SeatsRepo
{
    /** @param  ?Closure(Closure): mixed  $quietly  runs a fetch with prompts off; tests pass a plain call */
    public function __construct(private readonly Shell $shell, private readonly ?Closure $quietly = null) {}

    /** The directory of the work tree the file is in, or null when it is not in one. */
    public function workTree(string $seatsFile): ?string
    {
        $dir = dirname(realpath($seatsFile) ?: $seatsFile);
        $result = $this->shell->run(['git', '-C', $dir, 'rev-parse', '--is-inside-work-tree']);

        return $result->code === 0 && trim($result->out) === 'true' ? $dir : null;
    }

    /**
     * How many commits the work tree is behind its upstream, after a fetch; null when that cannot be told
     * (no upstream, or git cannot say). A failed fetch (offline) is not reported: the count then comes from
     * the refs fetched last.
     */
    public function behind(string $dir): ?int
    {
        $fetch = fn () => $this->shell->run(['git', '-C', $dir, 'fetch', '--quiet']);
        $this->quietly === null ? $fetch() : ($this->quietly)($fetch);
        $count = $this->shell->run(['git', '-C', $dir, 'rev-list', '--count', 'HEAD..@{u}']);

        return $count->code === 0 && ctype_digit(trim($count->out)) ? (int) trim($count->out) : null;
    }

    /** `git pull --ff-only`; never a merge or a rebase. */
    public function pull(string $dir): Result
    {
        return $this->shell->run(['git', '-C', $dir, 'pull', '--ff-only']);
    }

    /** Runs the closure with git's terminal and ssh prompts off, so a fetch can fail but never wait for a person. */
    public static function withoutPrompts(Closure $run): mixed
    {
        $previous = [getenv('GIT_TERMINAL_PROMPT'), getenv('GIT_SSH_COMMAND')];
        putenv('GIT_TERMINAL_PROMPT=0');
        $previous[1] === false && putenv('GIT_SSH_COMMAND=ssh -o BatchMode=yes');
        try {
            return $run();
        } finally {
            $previous[0] === false ? putenv('GIT_TERMINAL_PROMPT') : putenv('GIT_TERMINAL_PROMPT='.$previous[0]);
            $previous[1] === false && putenv('GIT_SSH_COMMAND');
        }
    }
}
