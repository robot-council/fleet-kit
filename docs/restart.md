# Restarting a machine's seats

`seat-stage` builds one multiplexer session for a machine, with a window per seat, and starts each
seat joined to the fleet. It is for the restart after a reboot or an update: the seats you had
come back, resumed, in the places they were.

Starting multiplexer and Claude Code sessions is your act. `--dry-run` only prints, and `check`
changes nothing.

## Setup

1. `composer install` (PHP 8.4 or later), and install the multiplexer: tmux on macOS and Linux,
   psmux on Windows.
2. Copy `examples/seats.toml` to `seats.toml` and edit it. Pass another path with `--config` or
   `FLEET_KIT_CONFIG`. The same file holds seat-pace's `[[account]]` tables.
3. `bin/seat-stage check <machine>` reports every reason staging would fail.
4. `bin/seat-stage stage <machine> --dry-run` prints each seat's exact command and first prompt.
5. `bin/seat-stage stage <machine>` builds the session. Attach with `tmux attach -t <session>`
   (`psmux attach` on Windows).

On Windows, `bin/seat-stage.ps1` is a thin wrapper.

## The seats file

| Table | Keys |
| --- | --- |
| top level | `channel`: the tagged entry passed to the channel flag, `server:<name>` or `plugin:<name>@<marketplace>` (default `server:robot-council`) |
| `[[machine]]` | `name`, `multiplexer` (`tmux` or `psmux`), `session`, `start`, `start_gap`, `confirm_channels`, `remote_control` |
| `[[seat]]` | `machine`, `slot`, `repository`, `checkout`, `role`, and optionally `work_location`, `capacity`, `conversation`, `remote_control`, `after`, `prompt` |

Per machine, `slot`, `work_location`, `conversation` and the window name (the repository name and the
slot, with anything but letters, digits, dash and underscore turned into a dash) must each be unique;
the file is refused otherwise. `session` is letters, digits, dash and underscore. A `conversation` is an
id of letters, digits, dot, dash and underscore. `remote_control` and `checkout` may not start with a
dash, a `checkout` may not hold `#(`, and a seat `prompt` may not hold a double quote, which Windows
PowerShell 5.1 would split an argument on. In PowerShell the curly single quotes (U+2018 to U+201B) are
doubled like `'`.

`work_location` defaults to the slot, is unique per machine, and is always passed explicitly,
because a join without it from a primary checkout records `primary`. `remote_control` defaults to
`<repo>/<machine>/<slot>`. Only `harness = "claude"` is stageable.

## The first prompt

Built from the seat's own fields.

- A seat with no `conversation` is asked to call `join` with `role`, `repository`, `work_location`
  and, where declared, `capacity`.
- A seat with a `conversation` is resumed, so it is already joined: it is told to call
  `presence_heartbeat`, and `session_move` if its recorded work location differs. It is never told to
  `leave` and rejoin, which releases the tasks it holds.
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

`when-free` starts seats strictly in order: a seat whose conversation is still held holds back the seats
after it. It waits at most an hour on one conversation, then stops with an error that names the seat; the
seats before it have started, and it and the rest are left at idle shells. In `enter` mode the holders are
not looked at again when Enter is pressed. `confirm_channels` runs after every window has been started.

Two screens can still wait for a person: the **folder trust** screen for a directory Claude Code has
not seen, and the **channels warning** ("Loading development channels … 1. I am using this for local
development"). With `confirm_channels = true` (tmux only, and not in `enter` mode) `seat-stage` reads
the pane, matches the exact channels text, chooses the local-development option, and reads the pane
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
`confirm_channels` not asked of psmux; and `when-free` only where holders can be detected. Work
locations being unique per machine is checked when the file is read.

## `collect`

Reads conversation ids from live processes into the seats file. A live conversation belongs to the
seat whose `checkout` is its working directory. The file is edited line by line, so comments (including
one after `conversation = "x"`) and layout stay. It records nothing for a seat with no live conversation
or with two candidates, never records one id for two seats, and skips an id the seats file would refuse.
`--dry-run` prints what it would record and writes nothing. It writes the file before it prints "recorded".

## Unmeasured

- **tmux target syntax.** A session is matched exactly with `=name` and a window with `=window`. That is
  tmux's own exact-match form, but it was not run here; psmux is given the plain names.
- **psmux.** Whether `capture-pane` works under it (fleet-kit#4), and whether it takes the same
  `new-session`, `new-window` and `send-keys` arguments as tmux. The commands for both come from one
  builder on the assumption that it does. Its shell is assumed to be PowerShell.
- **macOS and Linux.** The `sessions/<pid>.json` file and its `procStart` form were measured on
  Windows only. The macOS comparison follows the same rule; Linux is not read.
- **`claude mcp list`.** The `<name>: <command and arguments> - <status>` line format was seen on one
  Windows machine, where the entry sets neither `--project` nor `--harness`.
- **The order of flags** other than the prompt-first form, such as `--resume` together with
  `--remote-control`, beyond what was run in fleet-kit#7.
- **Live staging.** Everything here is tested against recorded and faked multiplexer output. No
  multiplexer session was started in building it.
