# fleet-kit

Operator tools for a [Robot Council](https://github.com/robot-council/core) fleet: a fleet of AI coding agents (Claude Code seats) that coordinate through a shared service. fleet-kit is for two people in an organization that runs one:

- **Operators**, who keep the seats running on their own machines. `seat-stage` brings a machine's seats back, resumed and joined to the fleet, after a reboot or an update. `seat-pace` says whether the fleet's usage allowance is being spent faster or slower than a straight line to its reset.
- **Coordinators**, who decide what the seats do. They read `seat-pace`'s verdicts and set the policy for what happens when the fleet is on course to run out. The kit never acts on a verdict.

You install fleet-kit and keep your own configuration in a private repository of your own. You do not fork it: a fork of a public repository stays public, and your seats file (machine names, checkout paths, conversation ids), your pacing policy and your `on_change` scripts must not be.

Start with [Getting started](docs/getting-started.md) if you operate machines, or the [Coordinator guide](docs/coordinators.md) if you decide what the seats do.

## What's verified

Everything below was built and tested against recorded and faked output on macOS, Linux and Windows, and measured by hand on one Windows 11 machine (Claude Code 2.1.304, psmux 3.3.8). **These have not been run live, and you should expect to be the first to:**

| Not run live | Where it is written down |
| --- | --- |
| `seat-stage` against a live multiplexer. Its tests fake the multiplexer; the psmux commands it uses were run by hand. | [Unmeasured](docs/restart.md#unmeasured), [Measured under psmux](docs/restart.md#measured-under-psmux) |
| tmux targets. A session is matched with `=name` and a window with `=window`, tmux's own exact-match form, never run here. | [Unmeasured](docs/restart.md#unmeasured) |
| The usage endpoint with a live account, and how it answers an expired token. `seat-pace read` has only ever been exercised against recorded responses. | [Platform support](docs/pacing.md#platform-support) |
| macOS: which process holds a conversation (the session files and their `procStart`), and the `claude mcp list` output format. | [Unmeasured](docs/restart.md#unmeasured), [Which process holds a conversation](docs/restart.md#which-process-holds-a-conversation) |
| `seat-stage` with `harness = "codex"`: `codex "<prompt>"` and `codex resume <id> "<prompt>"` were run live (macOS, codex-cli 0.163.0). A Codex seat gets no channel notice; an idle one was woken from outside with `codex queue`, which `seat-stage` does not run. Its bridge must carry `--auto-join` with the seat's role, repository and work location, or the session keeps only `join`; `seat-stage` does not write those args. Usage and Windows are unmeasured. | [Codex seats](docs/restart.md#codex-seats) |
| Linux. `seat-pace read` answers "unsupported on this platform", and `start = "when-free"` refuses because the process that holds a conversation cannot be told. | [Platform support](docs/pacing.md#platform-support), [Which process holds a conversation](docs/restart.md#which-process-holds-a-conversation) |

What *was* measured, on Windows: where Claude Code keeps its OAuth credential, that psmux's `capture-pane` reads a window (so `confirm_channels` works there), how to tell which process holds a conversation, and the order of arguments the channel flag takes. Each is recorded in the doc it affects.

## Install

PHP 8.4 or later and [Composer](https://getcomposer.org). The usual way:

```
composer global require robot-council/fleet-kit        # seat-pace and seat-stage on your PATH
```

or, in a project of your own, `composer require robot-council/fleet-kit`, which puts `vendor/bin/seat-pace` and `vendor/bin/seat-stage` in place. `composer create-project robot-council/fleet-kit` gives a copy of the release to run from (no tests or CI). To work on fleet-kit itself and send a change upstream, clone it:

```
git clone https://github.com/robot-council/fleet-kit && cd fleet-kit && composer install
```

`composer global require` needs Composer's global `vendor/bin` on your `PATH` (`composer global config bin-dir --absolute` prints it). On Windows Composer writes `.bat` proxies, so `seat-pace` and `seat-stage` run from any terminal; the `bin/*.ps1` wrappers are optional, for Task Scheduler and for running a checkout from PowerShell without a proxy.

Your seats file is yours, not the package's. Keep it in a private repository of your own and point `FLEET_KIT_CONFIG` (or `--config`) at it; the default is `seats.toml` in the current directory. The examples ship with the package, in `vendor/robot-council/fleet-kit/examples/` (or `$(composer global config home)/vendor/robot-council/fleet-kit/examples/` for a global install). [Getting started](docs/getting-started.md) walks through it.

Scheduler examples (`examples/schedulers/`) name `/path/to/fleet-kit/bin/seat-pace`. For a Composer install use the proxy instead: `vendor/bin/seat-pace` in your project, or `seat-pace` in Composer's global `bin-dir` (a `.bat` on Windows). A checkout keeps working with `bin/seat-pace`.

Packagist reads release tags: install a tag, or `dev-main` for the branch. To take a new release, see [Updating](docs/getting-started.md#updating).

## seat-pace

Reports whether a harness's usage allowance is being spent faster or slower than a straight line to its reset. An account can list several machines, so a second one reads when the fleet shows the first absent. See [docs/pacing.md](docs/pacing.md).

```
seat-pace pace --used 46 --now 2026-10-21T12:00:00-05:00   # arithmetic only
seat-pace read | pace | log | watch
```

## seat-stage

Stages a machine's seats in a terminal multiplexer (tmux on macOS and Linux, psmux on Windows) and starts each one joined to the fleet. See [docs/restart.md](docs/restart.md).

```
seat-stage stage office --dry-run     # print each seat's command and first prompt
seat-stage check office               # every reason staging would fail
seat-stage collect office             # record live conversation ids in the seats file
```

(In a checkout, `bin/seat-pace` and `bin/seat-stage`, with `--config examples/seats.toml` to try the example.)

## Contributing

Changing the tools themselves is a contribution upstream: open an issue or a pull request at [robot-council/fleet-kit](https://github.com/robot-council/fleet-kit/issues). Your configuration stays in your own repository.

## Tests

The suite uses [Pest](https://pestphp.com) and replays recorded fixtures, so it needs no credential, usage endpoint or multiplexer.

```
composer install
vendor/bin/pest --ci     # what CI runs; `composer test` runs the same suite without --ci
```

CI runs it on macOS, Linux and Windows for every pull request and every push to `main` (`.github/workflows/ci.yml`). Each platform reports its own check, and `ci-passed` passes only when all three do.
