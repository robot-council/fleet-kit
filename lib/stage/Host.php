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
     */
    public function __construct(
        public string $home,
        public string $platform,
        public Closure $onPath,
        public Closure $isDirectory,
        public Closure $hasTranscript,
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
        );
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
