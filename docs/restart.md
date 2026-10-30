# Restarting a machine's seats

`seat-stage` builds one multiplexer session for a machine, with a window per seat, and starts each
seat joined to the fleet. It is for the restart after a reboot or an update: the seats you had
come back, resumed, in the places they were.

Starting multiplexer and Claude Code sessions is your act. `--dry-run` only prints, and `check`
changes nothing.

## Setup

1. `composer install` (PHP 8.4 or later), and install the multiplexer: tmux on macOS and Linux,
   psmux on Windows.
2. Copy `examples/seats.toml` into your private configuration repository as `seats.toml` and edit it.
   Point `FLEET_KIT_CONFIG` (or `--config`) at it. The same file holds seat-pace's `[[account]]` tables.
   See [Getting started](getting-started.md).
3. `bin/seat-stage check <machine>` reports every reason staging would fail.
4. `bin/seat-stage stage <machine> --dry-run` (`seat-stage` if installed with Composer) prints each seat's exact command and first prompt.
5. `bin/seat-stage stage <machine>` builds the session. Attach with `tmux attach -t <session>`
   (`psmux attach` on Windows).

On Windows, `bin/seat-stage.ps1` is a thin wrapper.

## The seats file

| Table | Keys |
| --- | --- |
| top level | `channel`: the tagged entry passed to the channel flag, `server:<name>` or `plugin:<name>@<marketplace>` (default `server:robot-council`) |
| `[[machine]]` | `name`, `multiplexer` (`tmux` or `psmux`), `session`, `start`, `start_gap`, `confirm_channels`, `remote_control` |
| `[[seat]]` | `machine`, `slot`, `repository`, `checkout`, `role`, and optionally `work_location`, `capacity`, `conversation`, `remote_control`, `after`, `prompt`, `start` |

Per machine, `slot`, `work_location`, `conversation` and the window name (the repository name and the
slot, with anything but letters, digits, dash and underscore turned into a dash) must each be unique;
the file is refused otherwise. `session` is letters, digits, dash and underscore. A `conversation` is an
id of letters, digits, dot, dash and underscore. `remote_control` and `checkout` may not start with a
dash, a `checkout` may not hold `#(`, and a seat `prompt` may not hold a double quote, which Windows
PowerShell 5.1 would split an argument on. In PowerShell the curly single quotes (U+2018 to U+201B) are
doubled like `'`.

`work_location` defaults to the slot, is unique per machine, and is always passed explicitly,
because a join without it from a primary checkout records `primary`. `remote_control` defaults to
`<repo>/<machine>/<slot>`. `harness` is `claude` (the default) or `codex`; anything else is refused.

## Codex seats

A seat with `harness = "codex"` is started with `codex` instead of `claude`, with the same first prompt:
`codex "<first prompt>"`, or `codex resume <conversation> "<first prompt>"` when the seat has a `conversation`.
These forms are read from Codex's source (`codex-rs/cli/src/main.rs` in openai/codex), **not yet run live**:
the box on fleet-kit#26 stays open until a Codex seat has been staged on a machine with Codex installed.

- **Not woken by the fleet.** Codex has no channel notice (`openai/codex#15299`), so a staged Codex seat sits
  at its prompt until its operator types to it. `--dangerously-load-development-channels`, `confirm_channels`
  and `--remote-control` do not apply, and none is passed.
- **`seat-stage check`** requires `codex` on `PATH` only when a machine has a Codex seat, reads
  `codex mcp list --json` for the `robot-council` server and its `ROBOT_COUNCIL_HARNESS = "codex"`, and looks for the
  conversation as `rollout-<timestamp>-<id>.jsonl` under `$CODEX_HOME/sessions` (default `~/.codex/sessions`).
  What it cannot read (the list output, the sessions directory) it reports as not checked, never as passing.
- **`start = "when-free"` is refused** for a Codex seat: the holder files it reads are Claude Code's, so it cannot
  tell whether a live process holds a Codex conversation. Use `enter` or `now`, per seat if need be.
- **`seat-stage collect`** does not read Codex conversation ids and says so for each Codex seat; record
  `conversation` by hand.

## The first prompt

Built from the seat's own fields.

- A seat with no `conversation` is asked to call `join` with `role`, `repository`, `work_location`
  and, where declared, `capacity`.
- A seat with a `conversation` is resumed, and its fleet session may have ended: exiting a seat to
  restage ends it (`session.gone`). It is told to call `presence_heartbeat` if the fleet's tools are
  offered (it is still joined; `session_move` if its recorded work location differs), and to call
  `join` with `role`, `repository`, `work_location` and, where declared, `capacity` if only `join` is
  offered. A session that has ended holds nothing, so the join releases nothing.
- No prompt tells a seat to `leave`, which releases the tasks it holds.
- The seat's own `prompt` is appended.

## The command line

```
claude "<first prompt>" [--resume <id>] [--remote-control <name>] --dangerously-load-development-channels <channel>
```

**The prompt goes before the channel flag, and only tagged entries follow it.** Measured on Windows
with Claude Code 2.1.304 (fleet-kit#7): a bare prompt after the flag is read as another channel entry
and the session refuses to start (`entries must be tagged`). Before the flag, the prompt is delivered,
with or without `--resume`. A prompt can follow the flag only after a `--`. `seat-stage` uses the
first form.

On POSIX the window runs the command under `env -i` with only `HOME`, `PATH`, `USER`, `LOGNAME`,
`SHELL`, `TERM` and `LANG`. Windows has no `env -i`, so the window removes the `CLAUDE_CODE_*`,
`CLAUDECODE` and `CLAUDE_PID` variables a parent Claude Code session leaks into a child instead.

## Start modes

| `start` | Behaviour |
| --- | --- |
| `enter` | each window waits for Enter before starting (staging ahead of a restart) |
| `now` | each window starts at once (after a reboot) |
| `when-free` | each window starts once no live process holds its conversation, in `after` order, `start_gap` apart |

A seat may set its own `start` (`enter`, `now` or `when-free`) to override its machine's; without one it inherits
the machine's, so a seats file with no seat `start` stages as before. This keeps seats that are only needed
sometimes (one per repository, say) waiting for a keypress while the always-on seats start on their own:
set the machine to `when-free` and mark the occasional seats `start = "enter"`. `--dry-run` prints each seat's
effective mode. An `enter` seat on a `when-free` machine is created waiting, is skipped by the start loop and
does not hold back the seats after it; `confirm_channels` answers the warning only for seats that started on
their own, and the operator answers it for a seat they start. A seat's own `start = "when-free"` needs the
session files like a machine's does. Starting a waiting seat from outside its window is not supported.

`when-free` starts seats strictly in order: a seat whose conversation is still held holds back the seats
after it. It waits at most an hour on one conversation, then stops with an error that names the seat; the
seats before it have started, and it and the rest are left at idle shells. In `enter` mode the holders are
not looked at again when Enter is pressed. `confirm_channels` runs after every window has been started.

Two screens can still wait for a person: the **folder trust** screen for a directory Claude Code has
not seen, and the **channels warning** ("Loading development channels … 1. I am using this for local
development"). With `confirm_channels = true` (tmux and psmux, and not in `enter` mode) `seat-stage` reads
the pane, matches the channels text (whitespace ignored, so a wrapped line still matches), chooses the local-development option, and reads the pane
again to see it cleared. It never answers the folder-trust screen: it prints what it saw and leaves it
for you. The channels warning did not appear on a repeat run in a directory where it had already been
answered, so a missing prompt is reported, not treated as a failure.

## Which process holds a conversation

`when-free` and `check` use the answer measured in fleet-kit#3. A conversation is **held** when a
`~/.claude/sessions/<pid>.json` has its `sessionId`, its `pid` is alive, and its `procStart` equals
that process's start time (a FILETIME against the process's `CreationDate` on Windows, a UTC
`ps -o lstart=` string on macOS), within two seconds. That finds a conversation however it was
started, with or without `--resume`.

Where the files are not read at all, nothing is claimed: `when-free` refuses, and `check` says it can't
tell. This is the case on Linux until it is measured. Where they are read but one process's start time
can't be (the command fails, or prints something that is not a time, or a file's `procStart` is not
one), the answer is "cannot tell", never "free": `check` fails and staging refuses. A pid that is simply
not running is free. `CLAUDE_CONFIG_DIR` is honoured in place of `~/.claude`.

## `check`

Reports, without editing anything: the multiplexer and `claude` on `PATH`; the multiplexer session
not already existing (staging refuses to touch one); each checkout existing; each `conversation`
having a transcript under `~/.claude/projects`; no live process already holding a conversation
(except in `when-free`, which waits); the channel's MCP server listed by `claude mcp list` in each
checkout, with its `--project` and `--harness`, where it sets them, agreeing with the seats file;
and `when-free` only where holders can be detected. Work
locations being unique per machine is checked when the file is read.

## `collect`

Reads conversation ids from live processes into the seats file. A live conversation belongs to the
seat whose `checkout` is its working directory. The file is edited line by line, so comments (including
one after `conversation = "x"`) and layout stay. It records nothing for a seat with no live conversation
or with two candidates, never records one id for two seats, and skips an id the seats file would refuse.
`--dry-run` prints what it would record and writes nothing. It writes the file before it prints "recorded".

## Measured under psmux

Windows 11 (10.0.26200), psmux 3.3.8 (66cf613, 2026-08-18), Claude Code 2.1.304, fleet-kit#4. psmux is
driven with the same `new-session`, `new-window`, `send-keys` and `capture-pane` arguments as tmux, with
two differences, both handled: it does not prefix-match a session name, and it rejects the `=window`
form (`can't find window: =w1`, printed with exit 0), so it is given plain `session:window` targets.

- `capture-pane -p -t session:window` reads a window, exit 0, in UTF-8. Thirty reads of an unchanging
  window were byte-identical. A window created a moment ago reads as empty, not as an error.
- It read the real folder-trust screen and the real channels warning of Claude Code, from a throwaway
  session in an empty folder that never received a prompt. `send-keys Enter` chose the highlighted
  "I am using this for local development", and the next read showed the Claude Code screen.
- A pane narrower than a line wraps it, even in the middle of a word, and `capture-pane -J` does not join
  it again. Attaching a client also re-wraps the text. `seat-stage` therefore matches the screens with
  whitespace ignored.
- A window whose command exits closes, and reading it then fails with exit 1; `seat-stage` reports that as
  "could not read the pane".
- The command text `seat-stage` builds for Windows (the environment scrub, then `claude '...'`, with
  doubled single quotes) runs under psmux as written.
- `send-keys -l` with `Enter` typed into an idle window ran the command, which is how `when-free` starts a
  seat.

So `confirm_channels` is supported under psmux. Its shell was PowerShell throughout.

## Unmeasured

- **tmux target syntax.** tmux is given `=name` for a session and `=window` for a window. That is tmux's
  own exact-match form, but it was not run here.
- **macOS and Linux.** The `sessions/<pid>.json` file and its `procStart` form were measured on
  Windows only. The macOS comparison follows the same rule; Linux is not read.
- **`claude mcp list`.** The `<name>: <command and arguments> - <status>` line format was seen on one
  Windows machine, where the entry sets neither `--project` nor `--harness`.
- **The order of flags** other than the prompt-first form, such as `--resume` together with
  `--remote-control`, beyond what was run in fleet-kit#7.
- **Live staging.** `seat-stage` itself is tested against recorded and faked multiplexer output, and has
  not been run against a live multiplexer. The psmux behaviour above was measured by hand with the same
  arguments.
