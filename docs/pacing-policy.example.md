# Pacing policy (template)

`seat-pace` reports `UNDER` or `OVER`. What your fleet does about it is a policy of your own, and it
belongs in your private configuration repository, not in fleet-kit. Copy this file, fill in the levers, and delete what does not apply. The kit
never acts on a verdict.

## Thresholds

- Dead band (percentage points over the line before it counts as OVER): _
- Verdicts read from: _ (the log, `on_change`, a person)

## When OVER

Which levers are pulled, in what order, and by whom:

1. _
2. _

## When UNDER

What, if anything, is allowed to use the headroom:

1. _

## Who is told, and how

- `on_change` runs: _
- Escalation if OVER persists past: _

## Reviewing the policy

- Reviewed by: _
- Next review: _
