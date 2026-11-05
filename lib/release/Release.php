<?php

declare(strict_types=1);

namespace FleetKit;

use Closure;
use Throwable;

/**
 * Which fleet-kit release is installed, and whether a newer one is out (fleet-kit#34).
 *
 * The notice is advice only: it never updates anything, never throws, and is silent when the lookup
 * fails. The latest release is looked up at most once a day (a failed lookup counts, so a machine with
 * no network is not retried on every run) and the answer is kept in a small cache file.
 */
final class Release
{
    public const PACKAGE = 'robot-council/fleet-kit';

    /** Set to anything but "" or "0" to turn the notice off. */
    public const OFF = 'FLEET_KIT_NO_UPDATE_CHECK';

    private const LATEST_URL = 'https://api.github.com/repos/robot-council/fleet-kit/releases/latest';

    private const DAY = 86400;

    /**
     * @param  Closure(): string  $installed  the installed release, as a tag or a Composer version
     * @param  Closure(): ?string  $latest  the latest published release tag; null when it cannot be told
     * @param  Closure(): int  $now  Unix time
     */
    public function __construct(
        private readonly Closure $installed,
        private readonly Closure $latest,
        private readonly ?string $cacheFile,
        private readonly Closure $now,
        private readonly bool $enabled = true,
        private readonly int $ttl = self::DAY,
    ) {}

    public static function real(): self
    {
        $root = dirname(__DIR__, 2);
        $off = getenv(self::OFF);

        return new self(
            installed: fn () => self::detect($root),
            latest: fn () => self::fetch(),
            cacheFile: self::cachePath(),
            now: fn () => time(),
            enabled: $off === false || $off === '' || $off === '0',
        );
    }

    /** The installed release: Composer's pretty version for a tagged install, the git tag for a checkout. */
    public static function detect(string $root): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                if (\Composer\InstalledVersions::isInstalled(self::PACKAGE)) {
                    $version = \Composer\InstalledVersions::getPrettyVersion(self::PACKAGE);
                } else {
                    $rootPackage = \Composer\InstalledVersions::getRootPackage();
                    $version = $rootPackage['name'] === self::PACKAGE ? $rootPackage['pretty_version'] : null;
                }
                if (is_string($version) && self::parse($version) !== null) {
                    return $version;
                }
            } catch (Throwable) {
                // Fall through to git.
            }
        }

        if (is_dir($root.'/.git') || is_file($root.'/.git')) {
            $described = self::git($root);
            if ($described !== null) {
                return $described;
            }
        }

        return 'unknown';
    }

    public function installed(): string
    {
        try {
            return ($this->installed)();
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /** The one line to print when the install is behind, or null: up to date, off, offline, or not a release. */
    public function behindNotice(): ?string
    {
        if (! $this->enabled) {
            return null;
        }

        try {
            $have = self::parse($this->installed());
            $latest = $this->latestKnown();
            $want = $latest === null ? null : self::parse($latest);
            if ($have === null || $want === null || version_compare($want, $have, '<=')) {
                return null;
            }

            return sprintf(
                'fleet-kit %s is installed and %s is out. Update with `composer global update %s` (`composer update %s` in a project): https://github.com/robot-council/fleet-kit/wiki/Getting-started#updating. Turn this notice off with %s=1.',
                $this->installed(),
                $latest,
                self::PACKAGE,
                self::PACKAGE,
                self::OFF,
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function latestKnown(): ?string
    {
        $cache = $this->readCache();
        $now = ($this->now)();
        if ($cache !== null && $now - $cache['checked_at'] < $this->ttl && $now >= $cache['checked_at']) {
            return $cache['latest'];
        }

        $fresh = ($this->latest)();
        $latest = $fresh ?? $cache['latest'] ?? null;
        $this->writeCache($now, $latest);

        return $latest;
    }

    /** @return array{checked_at: int, latest: ?string}|null */
    private function readCache(): ?array
    {
        if ($this->cacheFile === null || ! is_file($this->cacheFile)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($this->cacheFile), true);
        if (! is_array($data) || ! is_int($data['checked_at'] ?? null) || ! (is_string($data['latest'] ?? null) || ($data['latest'] ?? null) === null)) {
            return null;
        }

        return ['checked_at' => $data['checked_at'], 'latest' => $data['latest'] ?? null];
    }

    private function writeCache(int $now, ?string $latest): void
    {
        if ($this->cacheFile === null) {
            return;
        }
        $dir = dirname($this->cacheFile);
        is_dir($dir) || @mkdir($dir, 0777, true);
        @file_put_contents($this->cacheFile, json_encode(['checked_at' => $now, 'latest' => $latest]));
    }

    /** "v0.1.5", "0.1.5" and "v0.1.5-2-g0fc1e3e" all read as 0.1.5; anything else (dev-main, unknown) is not a release. */
    private static function parse(string $version): ?string
    {
        return preg_match('/^v?(\d+\.\d+\.\d+)(?:-\d+-g[0-9a-f]+)?$/', $version, $m) === 1 ? $m[1] : null;
    }

    private static function git(string $root): ?string
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @proc_open(['git', '-C', $root, 'describe', '--tags', '--always'], [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes);
        if (! is_resource($process)) {
            return null;
        }
        $out = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);

        return proc_close($process) === 0 && $out !== '' ? $out : null;
    }

    private static function fetch(): ?string
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @proc_open(
            ['curl', '--silent', '--show-error', '--fail', '--location', '--max-time', '3', '-H', 'Accept: application/vnd.github+json', '-H', 'User-Agent: fleet-kit', self::LATEST_URL],
            [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']],
            $pipes,
        );
        if (! is_resource($process)) {
            return null;
        }
        $body = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            return null;
        }
        $tag = json_decode($body, true)['tag_name'] ?? null;

        return is_string($tag) && self::parse($tag) !== null && ! str_contains($tag, '-') ? $tag : null;
    }

    private static function cachePath(): ?string
    {
        $base = getenv('XDG_CACHE_HOME') ?: (PHP_OS_FAMILY === 'Windows' ? getenv('LOCALAPPDATA') : (getenv('HOME') ? getenv('HOME').'/.cache' : false));

        return is_string($base) && $base !== '' ? $base.'/fleet-kit/latest-release.json' : null;
    }
}
