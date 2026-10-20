# fleet-kit
Forkable operator tools for a Robot Council fleet: seat-stage stages a machine's seats, seat-pace paces a usage allowance.

## seat-pace

Reports whether a harness's usage allowance is being spent faster or slower than a straight line to its reset. See [docs/pacing.md](docs/pacing.md).

```
bin/seat-pace pace --used 46 --now 2026-10-21T12:00:00-05:00   # arithmetic only
bin/seat-pace read | pace | log | watch
```

PHP 8.4 or later. On Windows, `bin/seat-pace.ps1` is a thin wrapper.

## seat-stage

Stages a machine's seats in a terminal multiplexer (tmux on macOS and Linux, psmux on Windows) and starts each one joined to the fleet. See [docs/restart.md](docs/restart.md).

```
bin/seat-stage stage office --dry-run --config examples/seats.toml   # print each seat's command and first prompt
bin/seat-stage check office                                          # every reason staging would fail
bin/seat-stage collect office                                        # record live conversation ids in the seats file
```

On Windows, `bin/seat-stage.ps1` is a thin wrapper.

## Tests

The suite uses [Pest](https://pestphp.com) and replays recorded fixtures, so it needs no credential, usage endpoint or multiplexer.

```
composer install
vendor/bin/pest --ci     # what CI runs; `composer test` runs the same suite without --ci
```

CI runs it on macOS, Linux and Windows for every pull request and every push to `main` (`.github/workflows/ci.yml`). Each platform reports its own check, and `ci-passed` passes only when all three do.
