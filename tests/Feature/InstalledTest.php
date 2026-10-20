<?php

declare(strict_types=1);

// The tools as `composer require robot-council/fleet-kit` installs them (fleet-kit#15): a temporary project
// requires the package from a path repository, in two forms. "copy" is a copy of what the dist archive would
// hold, so the tools run from vendor/robot-council/fleet-kit and find the project's autoloader; "link" is the
// checkout itself, linked in, the form a developer uses. No network: Packagist is switched off.
//
// Only "copy" proves the project's autoloader is found. On macOS and Linux a linked package is read through
// its link, so "link" resolves the checkout's own vendor/autoload.php (on Windows Composer's .bat proxy names
// the project's); it is a smoke test of the linked form, nothing more.

/** @return array{0: int, 1: string} the exit code and the output (stdout and stderr together) */
function installedRun(string $command, string $cwd): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd);
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $out];
}

function installedTemp(string $prefix): string
{
    static $made = [];
    if ($made === []) {
        register_shutdown_function(function () use (&$made): void {
            foreach ($made as $dir) {
                installedRemove($dir);
            }
        });
    }

    $dir = str_replace('\\', '/', sys_get_temp_dir()).'/'.$prefix.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $made[] = $dir;

    return $dir;
}

/**
 * Removes a temporary directory. A package linked from the checkout is unlinked first, so the removal
 * can never follow a link into the checkout: `rmdir` takes a Windows junction away without its target,
 * and refuses a directory that still has files in it.
 */
function installedRemove(string $dir): void
{
    $link = $dir.'/vendor/robot-council/fleet-kit';
    $real = is_dir($link) ? realpath($link) : false;
    if (is_link($link) || ($real !== false && rtrim(str_replace('\\', '/', $real), '/') !== rtrim($link, '/') && realpath($dir) !== false && ! str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', (string) realpath($dir))))) {
        // Resolves outside this directory: linked in, so take away the link and not what it points at.
        PHP_OS_FAMILY === 'Windows' ? @rmdir($link) : @unlink($link);
    }

    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir.'/'.$name;
        is_dir($path) && ! is_link($path) ? installedRemove($path) : @unlink($path);
    }
    @rmdir($dir);
}

/** The repository as a dist archive holds it: everything but what .gitattributes marks export-ignore, and what git ignores. */
function installedDist(string $root, string $into): void
{
    $ignored = ['/vendor', '/.git', '/.phpunit.cache', '/.pest', '/seats.toml'];
    foreach (preg_split('/\R/', (string) file_get_contents($root.'/.gitattributes')) ?: [] as $line) {
        if (preg_match('#^(/\S+)\s+export-ignore$#', trim($line), $m)) {
            $ignored[] = $m[1];
        }
    }

    $copy = function (string $from, string $to, string $relative) use (&$copy, $ignored): void {
        foreach (scandir($from) ?: [] as $name) {
            $path = $relative.'/'.$name;
            if ($name === '.' || $name === '..' || in_array($path, $ignored, true) || str_ends_with($name, '.tsv')) {
                continue;
            }
            if (is_dir($from.'/'.$name)) {
                @mkdir($to.'/'.$name, 0777, true);
                $copy($from.'/'.$name, $to.'/'.$name, $path);
            } else {
                copy($from.'/'.$name, $to.'/'.$name);
            }
        }
    };
    $copy($root, $into, '');
}

/** @return string the project directory, with the package installed in it */
function installedProject(string $mode): string
{
    static $projects = [];
    if (isset($projects[$mode])) {
        return $projects[$mode];
    }

    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $source = $root;
    if ($mode === 'copy') {
        $source = installedTemp('fleet-kit-dist-');
        installedDist($root, $source);
    }

    $project = installedTemp('fleet-kit-project-');
    file_put_contents($project.'/composer.json', json_encode([
        'repositories' => [
            ['type' => 'path', 'url' => $source, 'options' => ['symlink' => $mode === 'link', 'versions' => ['robot-council/fleet-kit' => '1.0.0']]],
            ['packagist.org' => false],
        ],
        'require' => ['robot-council/fleet-kit' => '1.0.0'],
    ], JSON_UNESCAPED_SLASHES));

    [$code, $out] = installedRun('composer install --no-interaction --no-progress --no-ansi', $project);
    expect($code)->toBe(0, "composer install failed:\n{$out}");

    return $projects[$mode] = $project;
}

/** Composer's own proxy for a tool: a .bat on Windows, a PHP proxy or link elsewhere. */
function installedProxy(string $tool): string
{
    return PHP_OS_FAMILY === 'Windows' ? "vendor\\bin\\{$tool}.bat" : escapeshellarg(PHP_BINARY)." vendor/bin/{$tool}";
}

const INSTALLED_EXAMPLE = 'vendor/robot-council/fleet-kit/examples/seats.toml';

it('runs seat-pace through Composer\'s proxy, with the package\'s own example seats file', function (string $mode) {
    $project = installedProject($mode);

    [$code, $out] = installedRun(installedProxy('seat-pace').' pace --used 1 --now 2026-10-21T12:00:00-05:00 --config '.INSTALLED_EXAMPLE, $project);

    expect($code)->toBe(0, $out)->and($out)->toStartWith('UNDER account=main used=1%');
})->with(['copy', 'link']);

it('runs seat-stage through Composer\'s proxy and dry-runs the example', function (string $mode) {
    $project = installedProject($mode);

    [$code, $out] = installedRun(installedProxy('seat-stage').' stage office --dry-run --config '.INSTALLED_EXAMPLE, $project);

    expect($code)->toBe(0, $out)->and($out)->toContain('machine office: tmux session "fleet"');
})->with(['copy', 'link']);

it('runs the installed script directly, without the proxy, in the copy', function () {
    $project = installedProject('copy');

    [$code, $out] = installedRun(escapeshellarg(PHP_BINARY).' vendor/robot-council/fleet-kit/bin/seat-pace pace --used 1 --now 2026-10-21T12:00:00-05:00 --config '.INSTALLED_EXAMPLE, $project);

    expect($code)->toBe(0, $out)->and($out)->toStartWith('UNDER account=main');
});

it('installs the dist without tests, CI or the lock file, and with the docs and examples', function () {
    $package = installedProject('copy').'/vendor/robot-council/fleet-kit';

    foreach (['/tests', '/.github', '/phpunit.xml', '/composer.lock', '/.gitattributes'] as $absent) {
        expect(file_exists($package.$absent))->toBeFalse($absent);
    }
    foreach (['/bin/seat-pace', '/bin/seat-stage', '/bin/autoload.php', '/lib/pace', '/lib/stage', '/docs/restart.md', '/examples/seats.toml', '/LICENSE'] as $present) {
        expect(file_exists($package.$present))->toBeTrue($present);
    }
});

it('says plainly where the autoloader is missing, and exits 1', function (string $tool) {
    $bare = installedTemp('fleet-kit-bare-');
    mkdir($bare.'/bin');
    foreach ([$tool, 'autoload.php'] as $file) {
        copy(dirname(__DIR__, 2).'/bin/'.$file, $bare.'/bin/'.$file);
    }

    [$code, $out] = installedRun(escapeshellarg(PHP_BINARY).' bin/'.$tool.' --help', $bare);

    expect($code)->toBe(1)->and($out)->toContain("Composer's autoloader was not found");
})->with(['seat-pace', 'seat-stage']);

it('declares the package as a library with both tools as bins', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect($composer['type'])->toBe('library')
        ->and($composer['bin'])->toBe(['bin/seat-pace', 'bin/seat-stage'])
        ->and($composer)->not->toHaveKey('version')
        ->and($composer['support'])->toHaveKeys(['issues', 'source'])
        ->and($composer)->toHaveKeys(['keywords', 'homepage', 'authors']);
});
