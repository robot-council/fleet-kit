# Getting started

For an operator: someone who runs Claude Code seats on their own machines in a Robot Council fleet. If you decide what the seats do, read the [Coordinator guide](coordinators.md) as well.

**Read [What's verified](../README.md#whats-verified) first.** Several parts of fleet-kit have not been run live, and you may be the first to run them.

## The model: install, and keep your configuration in a private repository

You install fleet-kit. You do not fork it. A fork of a public repository has to stay public, and your `seats.toml` (machine names, checkout paths, conversation ids), your pacing policy and your `on_change` scripts must not be. Upstream fixes reach you through `composer update` ([Updating](#updating)). Changing the tools themselves is a contribution upstream.

## 1. Prerequisites

- **A joined Robot Council fleet,** and Claude Code with the `robot-council` MCP server wired into it and the channel flag. fleet-kit does not set these up; `seat-stage check` reports when the server is missing. Follow robot-council/cli's setup rather than anything copied here:
  - [Installing and upgrading](https://github.com/robot-council/cli/wiki/Installing-and-upgrading) the `robot-council` command
  - [Creating a fleet and enrolling a machine](https://github.com/robot-council/cli/wiki/Creating-a-fleet-and-enrolling-a-machine)
  - [Wiring the bridge into a harness](https://github.com/robot-council/cli/wiki/Wiring-the-bridge-into-a-harness)
  - [Joining the fleet and naming a project](https://github.com/robot-council/cli/wiki/Joining-the-fleet-and-naming-a-project)
- **The bridge's stop hook,** so a seat finds out about fleet events at the end of a turn: [The stop hook](https://github.com/robot-council/cli/wiki/The-stop-hook), then [under Claude Code](https://github.com/robot-council/cli/wiki/The-stop-hook-under-Claude-Code).
- **PHP 8.4 or later, [Composer](https://getcomposer.org), and `curl`** (`seat-pace read` passes the credential to it on stdin). `curl` is already on current Windows and macOS.
- **A terminal multiplexer** for `seat-stage`: tmux on macOS and Linux; [psmux](https://github.com/marlocarlo/psmux) on Windows (the one measured here was installed with `winget`, package `marlocarlo.psmux`). Installing software is your decision; fleet-kit never does it.

## 2. Install

```
composer global require robot-council/fleet-kit
```

Put Composer's global `bin-dir` on your `PATH` (`composer global config bin-dir --absolute` prints it) and check:

```
seat-pace --help
seat-stage --help
```

Other install forms, and what the `.bat` proxies and `.ps1` wrappers are for, are in the [README](../README.md#install).

## 3. Create the private configuration repository

Make a private repository of your own, for example `your-org/fleet-config`, and put these in it:

```
fleet-config/
  seats.toml             # from examples/seats.toml: channel, [[machine]], [[seat]], [[account]]
  pacing-policy.md       # from docs/pacing-policy.example.md, if you are the coordinator
  scripts/               # the commands your on_change and log_extra settings run
  .gitignore             # *.tsv, unless you want the pacing log in the repository's history
```

Copy the example from the package: `vendor/robot-council/fleet-kit/examples/seats.toml`, or `$(composer global config home)/vendor/robot-council/fleet-kit/examples/seats.toml` for a global install. Replace every `example-org`, every path and every machine name.

**What never goes in it: a credential or a token of any kind.** That includes Claude Code's own credential file, the `robot-council` service's secrets and enrollment credentials, and anything under `~/.claude/` (its credential file and the `sessions/*.key` files). The seats file needs none of them: `seat-pace read` finds Claude Code's credential itself, on the machine that has it, and never prints it. If an `on_change` or `log_extra` script needs a secret, it reads it from the machine, not from the repository.

Conversation ids in `seats.toml` are not secrets, but they name your sessions: another reason the repository is private.

## 4. Point the tools at it

```
# bash or zsh, in your profile
export FLEET_KIT_CONFIG="$HOME/fleet-config/seats.toml"
```

```
# PowerShell: for this session, and persistently for your user
$env:FLEET_KIT_CONFIG = "C:/Users/you/fleet-config/seats.toml"
[Environment]::SetEnvironmentVariable('FLEET_KIT_CONFIG', 'C:/Users/you/fleet-config/seats.toml', 'User')
```

Or pass `--config <file>` to any command. A scheduled job does not inherit your shell's environment: the scheduler examples pass `--config` explicitly. Relative paths in the file, such as `log = "seat-pace.tsv"`, resolve against the seats file's own directory, so the log lands in your configuration repository (ignore it there, or commit it, as you decide). In the seats file write Windows paths with forward slashes or as a `'literal string'`.

## 5. Stage a machine's seats with `seat-stage`

Starting multiplexer and Claude Code sessions is your act: `check` and `--dry-run` only read.

1. **`seat-stage check <machine>`** lists every reason staging would fail, and changes nothing: the multiplexer or `claude` not on `PATH`, a session already existing, a missing checkout, a `conversation` with no transcript, a conversation already held by a live process, the `robot-council` server missing from `claude mcp list`. Fix what it reports until it prints `ok: <machine> would stage`.
2. **`seat-stage stage <machine> --dry-run`** prints each seat's exact multiplexer command, shell command and first prompt. Read them: this is what will run.
3. **`seat-stage stage <machine>`** builds one multiplexer session with a window per seat. For a first run keep `start = "enter"` in the seats file, so each window waits for you to press Enter. Attach with `tmux attach -t <session>` (`psmux attach -t <session>` on Windows) and start the seats yourself.

Two screens can wait for you in a new window: Claude Code's **folder-trust** screen for a directory it has not seen (`seat-stage` never answers it), and the **channels warning** for the development-channel flag. With `confirm_channels = true` `seat-stage` answers the channels warning and nothing else. Details, the three start modes (`enter`, `now`, `when-free`) and what is measured are in [Restarting a machine's seats](restart.md).

A new seat has no `conversation` yet, so its first prompt asks it to `join` the fleet. Once seats are running, `seat-stage collect <machine>` records their conversation ids in the seats file, and the next restart *resumes* them rather than starting new ones. Commit that change to your configuration repository. (`collect` reads Claude Code's live session files, which are readable on Windows and, by the same rule, on macOS; not on Linux.)

## 6. Pace the allowance with `seat-pace`

Add an `[[account]]` to the same seats file (the example has one). Then, in order:

1. **The arithmetic, which reads nothing:** `seat-pace pace --used 46 --now 2026-10-21T12:00:00-05:00` prints a verdict from a figure you give it. Use it to check your `reset`, `timezone`, working hours and `dead_band`.
2. **`seat-pace read`** reads the live allowance with the credential of the Claude Code login on this machine, and prints the five-hour and seven-day utilization. It runs only on the account's `machine` (the hostname, or `SEAT_PACE_MACHINE`). It works on Windows and macOS; on Linux it answers "unsupported on this platform". This is the step that talks to the real usage endpoint: it has not been run live here, and how it answers an expired token is unmeasured. It is a read of your own account, run by you.
3. **A scheduler.** `seat-pace watch --once` reads, logs and runs your `on_change` when the verdict changes. Templates are in `examples/schedulers/`: launchd (macOS), systemd (Linux, once `read` is supported there) and Task Scheduler (Windows). Edit the paths in the template: for a Composer install, use `seat-pace` or `vendor/bin/seat-pace` rather than `/path/to/fleet-kit/bin/seat-pace`, and give `--config` the path to your configuration repository's `seats.toml`. The whole of [Pacing a usage allowance](pacing.md) explains the line, the verdict and the log.

## Updating

Take a new release with Composer:

```
composer global update robot-council/fleet-kit        # a global install
composer update robot-council/fleet-kit               # in a project
```

To stay on a release line, require a version constraint (`composer global require robot-council/fleet-kit:^0.1`). Release notes are on the [releases page](https://github.com/robot-council/fleet-kit/releases). After an update:

1. Read the notes, and the **Unmeasured** and **What's verified** lists, which shrink as things are measured.
2. Run `seat-stage check <machine>` and `seat-stage stage <machine> --dry-run` before restarting seats.
3. Re-measure after a Claude Code upgrade: the channel flag is hidden from `claude --help`, and its argument rules were measured on one version ([Restarting a machine's seats](restart.md#the-command-line)).

Your configuration repository is untouched by an update. If an update changes a setting's meaning, the release notes say so.
