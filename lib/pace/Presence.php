<?php

declare(strict_types=1);

namespace FleetKit\Pace;

use Closure;
use JsonException;

/** Which machines the fleet currently has a live session on: how a fallback machine knows to stand by. */
interface Presence
{
    /**
     * @return list<string> the lower-cased machine labels with at least one active session
     *
     * @throws ReadFailed
     */
    public function activeMachines(string $harness): array;
}

/**
 * Asks the fleet through the `robot-council` command already enrolled on the machine, so the kit holds no
 * credential and needs no shared storage (fleet-kit#19). `robot-council api GET /robot-council/api/lanes`
 * lists the sessions in the fleet, each with a `machine_label` and a `status` (`active`, `stale`, or `gone`, which
 * is never listed); a session that is only `stale` does not count as present. The command starts a
 * session of its own for the read (ephemeral, so the fleet's feed and session list do not show it).
 *
 * "Present" is a live session on the machine, of any harness (the list cannot be filtered by one; `--harness`
 * only chooses which enrolled credential makes the call), not proof that seat-pace is reading there: a
 * machine whose scheduler is broken but whose seats are running still counts.
 */
final class FleetPresence implements Presence
{
    /** The service's page size is capped; pages are followed by cursor, up to this many. */
    private const PAGE = 100;

    /**
     * The fleet serves its API under `/robot-council`, as the command's own calls do (`/robot-council/api/sessions`,
     * `/robot-council/api/events`); `robot-council api` sends a path as given and adds no prefix. Without it the
     * service answers 404 "The route api/lanes could not be found." (fleet-kit#43, measured 2026-11-24).
     */
    private const ROUTE = '/robot-council/api/lanes';

    private const MAX_PAGES = 50;

    /** @param  Closure(string): array{0: int, 1: string, 2: string}  $run  runs a command line, returns exit code, stdout and stderr */
    public function __construct(private readonly Closure $run) {}

    public function activeMachines(string $harness): array
    {
        if (! preg_match('/^[a-z0-9-]{1,32}$/', $harness)) {
            throw new ReadFailed("harness \"{$harness}\" is not a name the robot-council command takes (lower-case letters, digits and hyphens)");
        }

        $active = [];
        $after = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $this->page($harness, $after);

            foreach ($data['sessions'] as $session) {
                if (is_array($session) && ($session['status'] ?? null) === 'active' && is_string($session['machine_label'] ?? null)) {
                    $active[strtolower($session['machine_label'])] = true;
                }
            }

            $cursor = $data['cursor'] ?? null;
            if ($cursor === null) {
                return array_keys($active);
            }
            // Pages run newest first, so the cursor must fall; a cursor that does not would never end.
            if (! is_int($cursor) || ($after !== null && $cursor >= $after)) {
                throw new ReadFailed('the fleet\'s session list did not page');
            }
            $after = $cursor;
        }

        throw new ReadFailed('the fleet\'s session list is longer than '.(self::MAX_PAGES * self::PAGE).' sessions');
    }

    /** @return array{sessions: list<mixed>, cursor?: mixed} */
    private function page(string $harness, ?int $after): array
    {
        $path = self::ROUTE.'?limit='.self::PAGE.($after === null ? '' : "&after={$after}");
        [$code, $out, $err] = ($this->run)('robot-council api GET '.escapeshellarg($path).' --harness='.$harness);

        if ($code !== 0) {
            $why = trim((string) preg_replace('/\s+/', ' ', $err));
            throw new ReadFailed('the robot-council command failed (exit '.$code.')'.($why === '' ? '' : ': '.substr($why, 0, 300)));
        }

        try {
            $data = json_decode($out, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ReadFailed('the fleet\'s session list is not JSON');
        }
        if (! is_array($data) || ! is_array($data['sessions'] ?? null)) {
            throw new ReadFailed('the fleet\'s session list has no sessions');
        }

        return $data;
    }
}
