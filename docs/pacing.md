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

`read`, `log` and `watch` refuse to run on any machine but the account's `machine` (the hostname,
or `SEAT_PACE_MACHINE`). `pace --used --now` runs anywhere.

## The log

Tab-separated, with a header row: `utc account window used resets_at baseline projected verdict extra note`.
`extra` is the output of the account's `log_extra` command, if any; the kit knows nothing about what it
holds. The last row of an account is the previous verdict `watch` compares against.

## Running it on a schedule

`watch` is meant to be run by the operating system's scheduler, never by a timer inside a session,
which dies with the session. Templates are in `examples/schedulers/`: launchd, systemd and Task
Scheduler. Each runs `watch --once` every 55 minutes. (`watch` without `--once` loops by itself at
`--every`, for a foreground terminal.)

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
