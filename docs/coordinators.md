# Coordinator guide

For the person (or the coordinating seat) who decides what a fleet's seats do. If you also keep the machines running, read [Getting started](getting-started.md) first.

Read [What's verified](../README.md#whats-verified) before you build a policy on these tools: the usage endpoint and `seat-stage` against a live multiplexer have not been run live.

## Who does what

| | Does | Never does |
| --- | --- | --- |
| **fleet-kit** | Reads the allowance and reports `UNDER` or `OVER`. Stages seats when an operator runs it. | Act on a verdict. Place a task, pause a seat, change a plan. |
| **The operator** | Starts and restarts sessions (`seat-stage`), installs software, holds the credential, runs the scheduler. | Hand a credential to the fleet or to a repository. |
| **The coordinator** | Reads verdicts and decides placements: what runs, what waits, what is cheaper. | Start or restart a seat's session. That is the operator's act. |

Starting or restarting a session is the operator's act, even when a coordinator wants it done. A coordinator asks; the operator runs `seat-stage`. A verdict is information, not an order: the kit turns it into a line in a log and, if you configure one, a command of yours that runs when the verdict changes.

## Getting the verdict

Two routes, and a policy can use both.

**The log.** `seat-pace watch` appends a row each time it reads. It is tab-separated with a header row:

```
utc  account  window  used  resets_at  baseline  projected  verdict  extra  note
```

`extra` is whatever the account's `log_extra` command printed, and the kit does not interpret it. The last row of an account is the verdict `watch` compares the next reading against. The log sits wherever the seats file says (relative to the seats file's directory), on the account's machine.

**`on_change`.** When a reading's verdict differs from the last row's, `watch` runs the account's `on_change` command, with these in its environment:

| Variable | Holds |
| --- | --- |
| `SEAT_PACE_ACCOUNT` | the account name |
| `SEAT_PACE_VERDICT` | `UNDER` or `OVER` |
| `SEAT_PACE_PREVIOUS` | the verdict it changed from |

What the command does is yours: post a note where the coordinator will see it, open a task, send a message. Keep the script in your private configuration repository and make it read any secret it needs from the machine, never from the repository.

`seat-pace pace` also exits `0` for UNDER and `3` for OVER (`1`: the read failed; `2`: a configuration error), if a script of yours wants the verdict without parsing. See [Pacing a usage allowance](pacing.md) for the line, the dead band and the commands.

## Writing the policy

Start from [`pacing-policy.example.md`](pacing-policy.example.md). Copy it into your private configuration repository and fill in:

- **Thresholds.** The `dead_band` you set in the seats file, and where verdicts are read from.
- **When OVER.** The levers, in order, and who pulls each: pause low-priority placements, move work to a cheaper model, defer a batch to after the reset, ask the operator to stop a seat. The kit pulls none of them.
- **When UNDER.** What may use headroom, if anything.
- **Who is told, and how**, and when OVER that persists escalates to a person.

Test a policy against numbers before you trust it: `seat-pace pace --used 80 --now 2026-10-21T12:00:00-05:00` prints the verdict for a figure you choose, with no credential and no network.

## What a restart does to seats and tasks

This is the design intent of `seat-stage`, and **it has not been exercised against a live fleet**: treat it as a claim to check on your first restart, not a guarantee.

- A seat that has a `conversation` in the seats file is started with `claude --resume <conversation>`, so it keeps its conversation, its session in the fleet and the tasks placed on it.
- It is told to call `presence_heartbeat`, and `session_move` if its recorded work location differs. It does not `join` again.
- A seat with no `conversation` is new: its first prompt asks it to `join`.
- **The kit never tells a seat to `leave`.** A restart is not a departure, and tasks stay placed.
- `seat-stage` will not stage a conversation that a live process still holds, and `start = "when-free"` waits for a seat that is mid-turn rather than interrupting it.

So a coordinator can expect seats to come back where they were. Check after the first restart that tasks are still placed and the seats' sessions are the same; if they are not, say so upstream. The details are in [Restarting a machine's seats](restart.md).

## Adoption checklist for a new organization

Operator and coordinator, once each.

1. A Robot Council fleet exists, and each machine is enrolled and has Claude Code wired to the `robot-council` MCP server ([the CLI's setup](https://github.com/robot-council/cli/wiki/Creating-a-fleet-and-enrolling-a-machine)).
2. The bridge's stop hook is installed on every machine that runs a seat.
3. A **private** configuration repository exists, with `seats.toml`, the pacing policy and the `on_change` scripts, and **no credential or token** anywhere in it.
4. fleet-kit is installed on each machine (`composer global require robot-council/fleet-kit`) and `FLEET_KIT_CONFIG` points at the seats file.
5. `seat-stage check` is clean and `--dry-run` reads right on every machine; the first real stage is done by an operator, with `start = "enter"`.
6. `seat-stage collect` has recorded the conversation ids, and they are committed to the configuration repository.
7. `seat-pace pace --used … --now …` gives the verdicts you expect for your reset, timezone and working hours; `seat-pace read` has been run once by the account's operator; a scheduler runs `watch --once`.
8. The pacing policy is written, the `on_change` command reaches the coordinator, and someone owns the next review.
9. Someone has read the [What's verified](../README.md#whats-verified) list and knows which parts are untried, and who will report what they find at [robot-council/fleet-kit](https://github.com/robot-council/fleet-kit/issues).
10. A person owns [Updating](getting-started.md#updating): `composer global update`, then `seat-stage check` before the next restart.
