# fleet-kit
Forkable operator tools for a Robot Council fleet: seat-stage stages a machine's seats, seat-pace paces a usage allowance.

## Install

PHP 8.4 or later and [Composer](https://getcomposer.org). Three ways, all giving the same two tools:

```
composer global require robot-council/fleet-kit        # seat-pace and seat-stage on your PATH
composer create-project robot-council/fleet-kit        # a copy of the release to run from (no tests or CI)
git clone https://github.com/robot-council/fleet-kit && cd fleet-kit && composer install   # for a fork
```

`composer global require` needs Composer's global `vendor/bin` on your `PATH` (`composer global config bin-dir --absolute` prints it). In a project, `composer require robot-council/fleet-kit` puts `vendor/bin/seat-pace` and `vendor/bin/seat-stage` in place. On Windows Composer writes `.bat` proxies, so `seat-pace` and `seat-stage` run from any terminal; the `bin/*.ps1` wrappers are optional, for Task Scheduler and for running a checkout from PowerShell without a proxy.

Your seats file is yours, not the package's: keep `seats.toml` in a directory of your own and pass it with `--config` or `FLEET_KIT_CONFIG` (the default is `seats.toml` in the current directory). The examples ship with the package: copy `examples/seats.toml` from `vendor/robot-council/fleet-kit/` (or from `$(composer global config home)/vendor/robot-council/fleet-kit/` for a global install). Relative paths in the file, such as the pacing log, resolve against the seats file's own directory.

Scheduler examples (`examples/schedulers/`) name `/path/to/fleet-kit/bin/seat-pace`. For a Composer install use the proxy instead: `vendor/bin/seat-pace` in your project, or `seat-pace` in Composer's global `bin-dir` (a `.bat` on Windows). A checkout keeps working with `bin/seat-pace`.

Packagist reads release tags: install a tag, or `dev-main` for the branch.

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
