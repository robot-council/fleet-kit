# Pacing a usage allowance

Every seat on one account bills the same allowance, so nothing a single seat sees says whether the
fleet is on course to run out before the weekly reset. `seat-pace` reads the figure and reports
whether it is above or below a straight line to the reset. It reports; it never acts.

## Setup

1. Install fleet-kit (PHP 8.4 or later): see [Getting started](getting-started.md).
2. Copy `examples/seats.toml` into your private configuration repository as `seats.toml` and edit the
   `[[account]]`. Point `FLEET_KIT_CONFIG` (or `--config`) at it.
3. Try the arithmetic, which reads nothing: `bin/seat-pace pace --used 46 --now 2026-10-21T12:00:00-05:00`
   (`seat-pace` if you installed with Composer; see the README's Install section).

## The line

The week runs from one reset to the next. The configured `reset` (for example `Mon 12:00` in the
account's `timezone`) is authoritative; the usage endpoint's `resets_at` is only a cross-check, and
a warning is printed on stderr when the two differ by more than five minutes.

- `hours = "clock"` draws the line over every hour of the week.
- `hours = "working"` draws it over `working_days` and `working_hours` only, so the baseline stands
  still overnight and at the weekend.

The verdict is `OVER` when used exceeds the baseline by more than `dead_band` percentage points,
otherwise `UNDER`. It also reports the projected percentage at the reset (used divided by the share
of the line already run) and the projected time the allowance runs out, when it would.

## Commands

| Command | Does |
| --- | --- |
| `read [account]` | prints the 5h and 7d utilization and reset times |
| `pace [account] [--json]` | prints the verdict line |
| `pace [account] --used 46 --now <iso>` | arithmetic only: no reading, no credential, no network |
| `log [account] [--note ...]` | reads and appends a row to the log |
| `watch [account] [--every 55m] [--once]` | reads, logs, and runs `on_change` when the verdict differs from the last row's |

Exit status: `0` UNDER, `3` OVER, `1` a read failed, `2` configuration error. `watch --once` exits
`0` after a tick, so a scheduler only sees a failure when something is wrong.

`watch` with no account name covers every account whose `machine` matches this host, so one scheduled
job serves them all; a failed read of one account is reported on stderr and does not stop the others.
`--now` needs an explicit offset (`Z` or `-05:00`), and `reset` cannot be `24:00`. In the seats file,
write Windows paths with forward slashes or as a `'literal string'`; a stray backslash in a `"..."`
string is refused. A reset that falls in a spring-forward gap lands an hour later that one week.

`read`, `log` and `watch` refuse to run on a machine the account does not list in `machine` (this
machine is its hostname, or `SEAT_PACE_MACHINE`). `pace --used --now` runs anywhere. An account can
list several machines: see [When the reading machine is down](#when-the-reading-machine-is-down).

## The log

Tab-separated, with a header row: `utc account window used resets_at baseline projected verdict extra note`.
`extra` is the output of the account's `log_extra` command, if any; the kit knows nothing about what it
holds. The last row of an account is the previous verdict `watch` compares against.

## Running it on a schedule

`watch` is meant to be run by the operating system's scheduler, never by a timer inside a session,
which dies with the session. Templates are in `examples/schedulers/`: launchd, systemd and Task
Scheduler. Each runs `watch --once` every 55 minutes. (`watch` without `--once` loops by itself at
`--every`, for a foreground terminal.)

## When the reading machine is down

An account's `machine` can be a list, the preferred machine first, so a second machine reads while the
first is off, asleep or away:

```toml
[[account]]
name = "example"
machine = ["office", "home"]    # a plain string still means a list of one
```

Each listed machine runs its own scheduled `seat-pace watch --once`, with its own login for the account
and its own log. **The names must be the machines' labels in the fleet** (what `sessions_list` shows as a
machine, normally the hostname the machine enrolled with), compared without regard to case. Set
`SEAT_PACE_MACHINE` to the label if the hostname differs.

**How a fallback machine knows.** On each tick it asks the fleet, through the enrolled `robot-council`
command, whether any machine *before it* in the list has an active session: `robot-council api GET
/api/lanes --harness=<the account's harness>`, which lists the fleet's sessions with a `machine_label` and a
`status`. Only `active` counts; a `stale` session (one that has stopped answering) does not. If a machine
before it is present, it prints `STANDBY account=<name> reading=<machine>` and reads nothing. If none is,
it reads, logs and runs `on_change` as the account's reader. When the preferred machine returns, the next
tick on the fallback machine sees it and stands by again. The preferred machine never asks.

- **It needs** `robot-council` on the scheduler's `PATH`, `ROBOT_COUNCIL_SERVICE` in the scheduler's
  environment (a scheduled job does not inherit your shell's), and the machine enrolled under a harness
  named like the account's `harness` (`claude`): that only chooses which enrolled credential makes the
  call. The fallback machine needs no usage credential for this and the kit stores none. The call is
  given 30 seconds.
- **If it cannot ask** (the command is missing, not enrolled, unreachable or too slow), it says so on
  stderr, exits 1 and does not read. It never reads on the assumption that the preferred machine is down.
- **What it cannot see.** "Present" means the machine has a live session in the fleet, of *any* harness
  (the list cannot be filtered by one), which is not the same as `seat-pace` reading there. A present
  machine whose scheduler is broken goes unnoticed, and the reading lapses.
- **When both read.** Every tick on which the fallback finds the preferred machine absent while its
  scheduler is in fact running, both read and both may notify. That happens when the preferred machine is up
  but has no active session (its seats are stopped), and on every tick when a name in `machine` is not the
  machine's label in the fleet (the fallback never sees it). Check the label against `sessions_list`.
- **Log continuity.** Each machine's log is its own, so the last row on a machine that has just taken
  over (or just come back) can be old. For an account with a fallback, a reading whose last local row is
  older than one and a half times `--every` (82 minutes by default; a tick stood by makes a row two
  intervals old) is logged as a **fresh start** and `on_change` does
  not run for it, with a line on stderr, so a takeover does not invent a verdict change from a stale row.
  The next reading, one interval later, compares against the fresh row as usual. The limit: a change that
  happened *while* the other machine was reading is not reported by the machine that takes over, because
  the kit cannot see the other machine's log. Its consumer has the last `on_change` the other machine
  sent. An account with one machine behaves as it always has.
- `read`, `log` and `pace` (live) run by hand on any listed machine without asking the fleet: they are an
  operator's own act.

Not exercised against a live fleet: the shape of the session list is read from robot-council/core's and
robot-council/cli's source, and the tests replay a synthetic answer.

## Platform support

The usage adapter for Claude reads the token from the harness's own credential store and passes it to
`curl` on stdin, so it never appears on a command line.

- **macOS:** reads the macOS keychain.
- **Windows:** reads `.credentials.json` in Claude Code's data directory, `%USERPROFILE%\.claude`, or
  `CLAUDE_CONFIG_DIR` where that is set. Measured on Windows 11 (10.0.26200) with Claude Code 2.1.304
  (fleet-kit#5): the file holds a `claudeAiOauth` object with `accessToken`, `refreshToken`, `expiresAt`,
  `refreshTokenExpiresAt`, `scopes`, `subscriptionType`, `subscriptionTypeSource` and `rateLimitTier`, the
  shape the keychain reader already expects (the keychain item itself is not verified), and also entries
  for unrelated MCP servers. Only
  `claudeAiOauth.accessToken` is read; no message, log or test carries the file's contents, and the
  tests use a synthetic file with fake values.
- **Linux:** `read` answers "unsupported on this platform": no Linux machine has been measured.
  `pace --used --now` works everywhere.

The token goes to `curl` on stdin on every platform, never on a command line or in an environment
variable.

Not verified: the endpoint and its response shape against a live account (the tests replay recorded
responses, and the keychain, the credential file on a real disk and `curl` are not run by them); what
happens when the access token has expired (the file records an expiry, but how the endpoint answers
an expired token, and whether Claude Code has refreshed the file by then, were not measured; `read`
would report the failed request); and `CLAUDE_CONFIG_DIR`, which was not set where this was measured.

## What to do when OVER

That lives in your private configuration repository. Start from [`pacing-policy.example.md`](pacing-policy.example.md); the [Coordinator guide](coordinators.md) says how a verdict reaches a coordinator.
