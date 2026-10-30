<?php

declare(strict_types=1);

namespace FleetKit\Stage;

use FleetKit\Pace\ConfigError;
use FleetKit\Pace\Toml;

/** What one harness can do: a row of `harnesses.toml`. */
final readonly class Harness
{
    /**
     * @param  list<string>  $launch
     * @param  list<string>  $resume
     */
    public function __construct(
        public string $name,
        public string $executable,
        public array $launch,
        public array $resume,
        public ?string $remoteControlFlag,
        public ?string $channelFlag,
        public bool $confirmChannels,
        public string $conversations,
        public string $holders,
        public bool $collect,
        public string $mcp,
        public string $wake,
        public string $usage,
    ) {}

    /** The name as a sentence uses it: "Codex". */
    public function title(): string
    {
        return ucfirst($this->name);
    }

    /**
     * The arguments after the program for one seat, from the launch or resume template.
     *
     * @return list<string>
     */
    public function arguments(string $prompt, ?string $conversation): array
    {
        $template = $conversation === null ? $this->launch : $this->resume;

        return array_map(fn (string $a) => strtr($a, ['{prompt}' => $prompt, '{conversation}' => (string) $conversation]), $template);
    }
}

/** The capability table, `lib/stage/harnesses.toml`, keyed by harness. */
final class Harnesses
{
    private const CONVERSATIONS = ['claude-projects', 'codex-rollouts'];

    private const HOLDERS = ['claude-sessions', 'none'];

    private const MCP = ['claude-mcp-list', 'codex-mcp-list-json'];

    private const WAKE = ['channel', 'codex-queue', 'none'];

    private const USAGE = ['oauth-endpoint', 'rollout-token-count', 'none'];

    /** @var array<string, Harness>|null */
    private static ?array $table = null;

    /** @return list<string> the stageable harnesses */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $name): bool
    {
        return isset(self::all()[$name]);
    }

    public static function get(string $name): Harness
    {
        return self::all()[$name] ?? throw new ConfigError("harness \"{$name}\" is not in harnesses.toml");
    }

    /** @return array<string, Harness> */
    public static function all(): array
    {
        return self::$table ??= self::parse((string) file_get_contents(__DIR__.'/harnesses.toml'));
    }

    /** @return array<string, Harness> */
    public static function parse(string $source): array
    {
        $table = [];
        foreach (Toml::parse($source) as $name => $row) {
            is_array($row) || throw new ConfigError("harnesses.toml: {$name} is not a table");
            $table[(string) $name] = self::harness((string) $name, $row);
        }
        $table === [] && throw new ConfigError('harnesses.toml lists no harness');

        return $table;
    }

    /** @param  array<string, mixed>  $row */
    private static function harness(string $name, array $row): Harness
    {
        $at = "harnesses.toml: {$name}";

        return new Harness(
            name: $name,
            executable: self::text($row, 'executable', $at),
            launch: self::template($row, 'launch', ['{prompt}'], $at),
            resume: self::template($row, 'resume', ['{prompt}', '{conversation}'], $at),
            remoteControlFlag: self::flag($row, 'remote_control_flag', $at),
            channelFlag: self::flag($row, 'channel_flag', $at),
            confirmChannels: self::bool($row, 'confirm_channels', $at),
            conversations: self::choice($row, 'conversations', self::CONVERSATIONS, $at),
            holders: self::choice($row, 'holders', self::HOLDERS, $at),
            collect: self::bool($row, 'collect', $at),
            mcp: self::choice($row, 'mcp', self::MCP, $at),
            wake: self::choice($row, 'wake', self::WAKE, $at),
            usage: self::choice($row, 'usage', self::USAGE, $at),
        );
    }

    /** @param  array<string, mixed>  $row */
    private static function text(array $row, string $key, string $at): string
    {
        $value = $row[$key] ?? null;
        (is_string($value) && preg_match('/^[A-Za-z0-9._-]+$/', $value)) || throw new ConfigError("{$at}: {$key} is required, letters, digits, dot, dash and underscore");

        return $value;
    }

    /** @param  array<string, mixed>  $row */
    private static function flag(array $row, string $key, string $at): ?string
    {
        if (! isset($row[$key])) {
            return null;
        }
        (is_string($row[$key]) && preg_match('/^--[A-Za-z0-9-]+$/', $row[$key])) || throw new ConfigError("{$at}: {$key} must be a long flag like --name");

        return $row[$key];
    }

    /** @param  array<string, mixed>  $row */
    private static function bool(array $row, string $key, string $at): bool
    {
        is_bool($row[$key] ?? null) || throw new ConfigError("{$at}: {$key} must be true or false");

        return $row[$key];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $choices
     */
    private static function choice(array $row, string $key, array $choices, string $at): string
    {
        $value = $row[$key] ?? null;
        (is_string($value) && in_array($value, $choices, true)) || throw new ConfigError("{$at}: {$key} must be one of ".implode(', ', $choices));

        return $value;
    }

    /**
     * An argument list in which each of $required appears and the only placeholders are {prompt} and {conversation}.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $required
     * @return list<string>
     */
    private static function template(array $row, string $key, array $required, string $at): array
    {
        $value = $row[$key] ?? null;
        (is_array($value) && $value !== [] && array_is_list($value)) || throw new ConfigError("{$at}: {$key} must be a list of arguments");
        foreach ($value as $argument) {
            is_string($argument) && $argument !== '' || throw new ConfigError("{$at}: {$key} holds an argument that is not text");
            preg_match_all('/\{[^}]*\}/', $argument, $found);
            foreach ($found[0] as $placeholder) {
                in_array($placeholder, ['{prompt}', '{conversation}'], true) || throw new ConfigError("{$at}: {$key} has the placeholder {$placeholder}; only {prompt} and {conversation} exist");
            }
        }
        foreach ($required as $placeholder) {
            in_array($placeholder, $value, true) || throw new ConfigError("{$at}: {$key} must hold {$placeholder} as an argument of its own");
        }

        return array_values($value);
    }
}
