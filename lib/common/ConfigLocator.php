<?php

declare(strict_types=1);

namespace FleetKit;

use FleetKit\Pace\ConfigError;

/**
 * Where the seats file is (fleet-kit#33). The order, first match wins:
 *   1. --config <file>
 *   2. FLEET_KIT_CONFIG
 *   3. the path remembered by `seat-stage config set <file>`, kept in one small per-user file
 *   4. seats.toml in the current directory
 * seat-stage and seat-pace use the same order, so a scheduler needs no --config once a path is remembered.
 */
final class ConfigLocator
{
    public const ENV = 'FLEET_KIT_CONFIG';

    public const DEFAULT = 'seats.toml';

    /** @param  ?string  $rememberedFile  the per-user file that holds the remembered path; null when the platform has no home */
    public function __construct(private readonly ?string $rememberedFile) {}

    public static function real(): self
    {
        return new self(self::userFile());
    }

    /** The platform's usual per-user configuration location. */
    public static function userFile(): ?string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        $base = match (PHP_OS_FAMILY) {
            'Windows' => getenv('APPDATA') ?: null,
            'Darwin' => $home ? $home.'/Library/Application Support' : null,
            default => getenv('XDG_CONFIG_HOME') ?: ($home ? $home.'/.config' : null),
        };

        return is_string($base) && $base !== '' ? $base.'/fleet-kit/seats-path' : null;
    }

    public function file(): ?string
    {
        return $this->rememberedFile;
    }

    /** @return array{path: string, source: string} the path to read, and which of the four supplied it */
    public function resolve(?string $flag, ?string $env): array
    {
        if ($flag !== null && $flag !== '') {
            return ['path' => $flag, 'source' => '--config'];
        }
        if ($env !== null && $env !== '') {
            return ['path' => $env, 'source' => self::ENV];
        }
        $remembered = $this->remembered();
        if ($remembered !== null) {
            return ['path' => $remembered, 'source' => "the path remembered by `seat-stage config set` ({$this->rememberedFile})"];
        }

        return ['path' => self::DEFAULT, 'source' => 'the default, '.self::DEFAULT.' in the current directory'];
    }

    public function remembered(): ?string
    {
        if ($this->rememberedFile === null || ! is_file($this->rememberedFile)) {
            return null;
        }
        $path = trim((string) file_get_contents($this->rememberedFile));

        return $path === '' ? null : $path;
    }

    /** Remember an existing seats file, as an absolute path; returns that path. */
    public function remember(string $path): string
    {
        $this->rememberedFile !== null || throw new ConfigError('this platform has no per-user configuration location to keep the path in; set '.self::ENV.' instead');
        $real = is_file($path) ? realpath($path) : false;
        $real !== false || throw new ConfigError("seats file not found: {$path}");
        $dir = dirname($this->rememberedFile);
        (is_dir($dir) || @mkdir($dir, 0777, true) || is_dir($dir)) && @file_put_contents($this->rememberedFile, $real."\n") !== false
            || throw new ConfigError("could not write {$this->rememberedFile}");

        return $real;
    }

    /** Forget the remembered path; true when there was one. */
    public function forget(): bool
    {
        if ($this->rememberedFile === null || ! is_file($this->rememberedFile)) {
            return false;
        }

        return @unlink($this->rememberedFile);
    }

    /** The error for a seats file that is not there, saying where the path came from and the one-time fix. */
    public static function missing(string $path, string $source): ConfigError
    {
        return new ConfigError("seats file not found: {$path} (from {$source}). Remember it once with `seat-stage config set <path to seats.toml>`, or pass --config, or set ".self::ENV.'.');
    }
}
