<?php

declare(strict_types=1);

namespace FleetKit\Pace;

/**
 * The subset of TOML a seats file uses: [table] and [[array of tables]] headers, and
 * key = value lines holding a string, an integer, a float, a boolean or a one-line array of those.
 * Anything else is refused rather than guessed at.
 */
final class Toml
{
    /** @return array<string, mixed> */
    public static function parse(string $source): array
    {
        $root = [];
        $target = &$root;

        foreach (preg_split('/\r\n|\n|\r/', $source) ?: [] as $number => $raw) {
            $line = trim(self::stripComment($raw));
            if ($line === '') {
                continue;
            }
            $where = 'line '.($number + 1);

            if (preg_match('/^\[\[\s*([A-Za-z0-9_-]+)\s*\]\]$/', $line, $m)) {
                $root[$m[1]] ??= [];
                $root[$m[1]][] = [];
                $target = &$root[$m[1]][array_key_last($root[$m[1]])];

                continue;
            }
            if (preg_match('/^\[\s*([A-Za-z0-9_-]+)\s*\]$/', $line, $m)) {
                $root[$m[1]] ??= [];
                $target = &$root[$m[1]];

                continue;
            }
            if (! preg_match('/^([A-Za-z0-9_-]+)\s*=\s*(.+)$/', $line, $m)) {
                throw new ConfigError("seats file: cannot read {$where}");
            }
            $target[$m[1]] = self::value($m[2], $where);
        }

        return $root;
    }

    private static function stripComment(string $line): string
    {
        $quote = null;
        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            $c = $line[$i];
            if ($quote !== null) {
                if ($c === '\\' && $quote === '"') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '#') {
                return substr($line, 0, $i);
            }
        }

        return $line;
    }

    /** TOML's escapes only; a lone backslash (a Windows path, say) is an error, not a guess. */
    private static function unescape(string $body, string $where): string
    {
        return (string) preg_replace_callback('/\\\\(?:(["\\\\bfnrt])|u([0-9A-Fa-f]{4})|(.?))/s', function (array $m) use ($where): string {
            if (($m[3] ?? '') !== '' || ($m[1] ?? '') === '' && ($m[2] ?? '') === '') {
                throw new ConfigError("seats file: invalid escape in string on {$where}; write a Windows path with forward slashes or a literal 'string'");
            }
            if (($m[2] ?? '') !== '') {
                return mb_chr(hexdec($m[2]), 'UTF-8') ?: '';
            }

            return ['"' => '"', '\\' => '\\', 'b' => "\x08", 'f' => "\x0c", 'n' => "\n", 'r' => "\r", 't' => "\t"][$m[1]];
        }, $body);
    }

    private static function value(string $text, string $where): mixed
    {
        $text = trim($text);

        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/', $text, $m)) {
            return self::unescape($m[1], $where);
        }
        if (preg_match("/^'([^']*)'$/", $text, $m)) {
            return $m[1];
        }
        if ($text === 'true' || $text === 'false') {
            return $text === 'true';
        }
        if (preg_match('/^[+-]?\d+$/', $text)) {
            return (int) $text;
        }
        if (preg_match('/^[+-]?\d+\.\d+$/', $text)) {
            return (float) $text;
        }
        if (preg_match('/^\[(.*)\]$/', $text, $m)) {
            $items = [];
            $body = trim($m[1]);
            while ($body !== '') {
                if (! preg_match('/^("(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|[^,\s]+)\s*(?:,\s*|$)/', $body, $item)) {
                    throw new ConfigError("seats file: cannot read array on {$where}");
                }
                $items[] = self::value($item[1], $where);
                $body = trim(substr($body, strlen($item[0])));
            }

            return $items;
        }

        throw new ConfigError("seats file: unsupported value on {$where}");
    }
}
