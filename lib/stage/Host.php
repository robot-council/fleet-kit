<?php

declare(strict_types=1);

namespace FleetKit\Stage;

use Closure;

/** The machine seat-stage runs on: what it can see of the file system and PATH. Fakeable. */
final readonly class Host
{
    /**
     * @param  Closure(string): bool  $onPath
     * @param  Closure(string): bool  $isDirectory
     * @param  Closure(string): bool  $hasTranscript
     * @param  ?Closure(string): ?bool  $hasCodexSession  whether Codex keeps this conversation; null where it can't be read
     */
    public function __construct(
        public string $home,
        public string $platform,
        public Closure $onPath,
        public Closure $isDirectory,
        public Closure $hasTranscript,
        public ?Closure $hasCodexSession = null,
    ) {}

    public static function real(): self
    {
        $home = (string) (getenv('USERPROFILE') ?: getenv('HOME') ?: '');
        $windows = PHP_OS_FAMILY === 'Windows';

        return new self(
            home: $home,
            platform: PHP_OS_FAMILY,
            onPath: function (string $command) use ($windows): bool {
                $extensions = $windows ? array_merge([''], explode(';', (string) getenv('PATHEXT'))) : [''];
                foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
                    foreach ($extensions as $extension) {
                        if ($directory !== '' && is_file($directory.DIRECTORY_SEPARATOR.$command.$extension)) {
                            return true;
                        }
                    }
                }

                return false;
            },
            isDirectory: is_dir(...),
            hasTranscript: function (string $id) use ($home): bool {
                // No glob: an id is a file name here, and a glob would read `*` or `[` in it as a pattern.
                $projects = self::claudeDirectory($home).DIRECTORY_SEPARATOR.'projects';
                foreach (scandir($projects) ?: [] as $directory) {
                    if ($directory !== '.' && $directory !== '..' && is_file($projects.DIRECTORY_SEPARATOR.$directory.DIRECTORY_SEPARATOR.$id.'.jsonl')) {
                        return true;
                    }
                }

                return false;
            },
            hasCodexSession: function (string $id) use ($home): ?bool {
                // Rollouts are `sessions/YYYY/MM/DD/rollout-<timestamp>-<id>.jsonl` (openai/codex, codex-rs/rollout).
                $sessions = self::codexDirectory($home).DIRECTORY_SEPARATOR.'sessions';
                if (! is_dir($sessions)) {
                    return null;
                }
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sessions, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isFile() && str_starts_with($file->getFilename(), 'rollout-') && str_contains($file->getFilename(), $id)) {
                        return true;
                    }
                }

                return false;
            },
        );
    }

    /** Codex's data directory: `CODEX_HOME` when set, else `~/.codex`. */
    public static function codexDirectory(string $home): string
    {
        $configured = (string) getenv('CODEX_HOME');

        return $configured !== '' ? $configured : $home.DIRECTORY_SEPARATOR.'.codex';
    }

    /** Claude Code's data directory: `CLAUDE_CONFIG_DIR` when set, else `~/.claude`. */
    public static function claudeDirectory(string $home): string
    {
        $configured = (string) getenv('CLAUDE_CONFIG_DIR');

        return $configured !== '' ? $configured : $home.DIRECTORY_SEPARATOR.'.claude';
    }

    public function sessionsDirectory(): string
    {
        return self::claudeDirectory($this->home).DIRECTORY_SEPARATOR.'sessions';
    }
}
