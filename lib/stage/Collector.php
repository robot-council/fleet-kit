<?php

declare(strict_types=1);

namespace FleetKit\Stage;

use Closure;
use FleetKit\Pace\ConfigError;
use RuntimeException;

/**
 * `seat-stage collect`: reads conversation ids from live processes into the seats file.
 *
 * A live conversation belongs to a seat when its working directory is the seat's checkout. The
 * file is edited line by line, so comments and layout stay as they were.
 */
final readonly class Collector
{
    /** The same shape the seats file accepts for `conversation`. */
    private const ID = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /** @param  Closure(string): void  $out */
    public function __construct(
        private Fleet $fleet,
        private Holders $holders,
        private Closure $out,
    ) {}

    public function collect(Machine $machine, string $path, bool $dryRun = false): int
    {
        if (! $this->holders->readable()) {
            throw new ConfigError('collect needs the live session files, which are unreadable on this platform');
        }

        $live = $this->holders->live();
        $source = (string) file_get_contents($path);
        $lines = [];
        $changed = false;
        /** @var array<string, string> $claimed conversation id => label of the seat it was given to */
        $claimed = [];
        foreach ($this->fleet->seatsOn($machine->name) as $seat) {
            $seat->conversation !== null && $claimed[$seat->conversation] = $seat->label();
        }

        foreach ($this->fleet->seatsOn($machine->name) as $seat) {
            if (! $seat->capabilities()->collect) {
                $lines[] = "{$seat->label()}: collect does not read {$seat->capabilities()->title()} conversation ids; record \"conversation\" by hand";

                continue;
            }
            $matches = array_values(array_filter($live, fn (Holder $h) => self::same($h->cwd, $seat->checkout)));
            if (count($matches) === 0) {
                $lines[] = "{$seat->label()}: no live conversation in {$seat->checkout}";

                continue;
            }
            if (count($matches) > 1) {
                $lines[] = "{$seat->label()}: ".count($matches).' live conversations in '.$seat->checkout.'; not guessing';

                continue;
            }
            $id = $matches[0]->conversation;
            if ($id === $seat->conversation) {
                $lines[] = "{$seat->label()}: already {$id}";

                continue;
            }
            if (! preg_match(self::ID, $id)) {
                $lines[] = "{$seat->label()}: the live conversation id is not one the seats file accepts; not recording it";

                continue;
            }
            if (isset($claimed[$id]) && $claimed[$id] !== $seat->label()) {
                $lines[] = "{$seat->label()}: {$id} is already recorded for {$claimed[$id]}; not recording it twice";

                continue;
            }

            $source = self::set($source, $seat->machine, $seat->slot, $id);
            $claimed[$id] = $seat->label();
            $changed = true;
            $lines[] = "{$seat->label()}: ".($dryRun ? 'would record' : ($seat->conversation === null ? 'recorded' : "changed from {$seat->conversation} to"))
                .($dryRun && $seat->conversation !== null ? " (now {$seat->conversation})" : '')." {$id}";
        }

        // The file is written first: a line saying "recorded" is only true once it has been.
        if ($changed && ! $dryRun && file_put_contents($path, $source) === false) {
            throw new RuntimeException("could not write {$path}; nothing was recorded");
        }
        foreach ($lines as $line) {
            ($this->out)($line);
        }

        return 0;
    }

    /** Sets `conversation` in the `[[seat]]` block for a machine and slot, keeping everything else. */
    public static function set(string $source, string $machine, string $slot, string $id): string
    {
        preg_match(self::ID, $id) || throw new ConfigError('conversation ids are letters, digits, dot, dash and underscore');

        // Each line keeps its own terminator, so a file with mixed endings comes back byte for byte.
        $pieces = preg_split('/(?<=\n)/', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = array_map(fn (string $p) => rtrim($p, "\r\n"), $pieces);
        $ends = array_map(fn (string $p, string $l) => substr($p, strlen($l)), $pieces, $lines);
        $eol = str_contains($source, "\r\n") ? "\r\n" : "\n";

        foreach ($lines as $start => $line) {
            if (! preg_match('/^\s*\[\[\s*seat\s*\]\]/', $line)) {
                continue;
            }
            $end = $start;
            while (isset($lines[$end + 1]) && ! preg_match('/^\s*\[/', $lines[$end + 1])) {
                $end++;
            }
            $block = array_slice($lines, $start, $end - $start + 1);
            $text = implode("\n", $block);
            $has = fn (string $key, string $value) => (bool) preg_match('/^\s*'.$key.'\s*=\s*["\x27]'.preg_quote($value, '/').'["\x27]/m', $text);
            if (! $has('machine', $machine) || ! $has('slot', $slot)) {
                continue;
            }

            $replaced = false;
            foreach ($block as $j => $blockLine) {
                // Keeps a trailing comment: `conversation = "x" # why`.
                if (preg_match('/^(\s*)conversation\s*=\s*(?:"[^"]*"|\'[^\']*\')\s*(#.*)?$/', $blockLine, $m)) {
                    $block[$j] = "{$m[1]}conversation = \"{$id}\"".(isset($m[2]) && $m[2] !== '' ? " {$m[2]}" : '');
                    $replaced = true;
                }
            }
            if (! $replaced) {
                $after = 0;
                foreach ($block as $j => $blockLine) {
                    if (preg_match('/^\s*slot\s*=/', $blockLine)) {
                        $after = $j;
                    }
                }
                array_splice($block, $after + 1, 0, ["conversation = \"{$id}\""]);
                // The new line ends like the slot line it follows (or like the file, if that line was last).
                $blockEnds = array_slice($ends, $start, $end - $start + 1);
                $newEnd = $blockEnds[$after];
                if ($newEnd === '') {
                    // The slot line was the last line of the file, with no terminator: give it one.
                    $blockEnds[$after] = $eol;
                }
                array_splice($blockEnds, $after + 1, 0, [$newEnd]);
                array_splice($ends, $start, $end - $start + 1, $blockEnds);
            }
            array_splice($lines, $start, $end - $start + 1, $block);

            return implode('', array_map(fn (string $l, string $e) => $l.$e, $lines, $ends));
        }

        throw new ConfigError("no [[seat]] for {$machine}/{$slot} to record the conversation in");
    }

    private static function same(string $a, string $b): bool
    {
        $normal = fn (string $p) => strtolower(rtrim(str_replace('\\', '/', $p), '/'));

        return $a !== '' && $normal($a) === $normal($b);
    }
}
