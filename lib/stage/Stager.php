<?php

declare(strict_types=1);

namespace FleetKit\Stage;

use Closure;
use FleetKit\Pace\ConfigError;
use RuntimeException;

/**
 * `seat-stage stage`: one multiplexer session with a window per seat.
 *
 * Starting multiplexer and Claude Code sessions is the operator's act. `--dry-run` only prints.
 */
final readonly class Stager
{
    /** The two screens measured in fleet-kit#7. Only the channels warning is ever answered. */
    private const CHANNELS = ['Loading development channels', 'I am using this for local development'];

    private const TRUST = 'Is this a project you created or one you trust?';

    private const POLLS = 20;

    /** Seconds `when-free` waits on one held conversation before it gives up. */
    private const MAX_WAIT = 3600;

    private const WAIT_STEP = 5;

    /**
     * @param  Closure(string): void  $out
     * @param  Closure(int): void  $sleep
     */
    public function __construct(
        private Fleet $fleet,
        private Shell $shell,
        private Host $host,
        private Holders $holders,
        private Closure $out,
        private Closure $sleep,
    ) {}

    /** Prints, for each seat, the exact command and the first prompt it would send. Touches nothing. */
    public function dryRun(Machine $machine): void
    {
        $multiplexer = Multiplexer::for($machine);
        ($this->out)("machine {$machine->name}: {$machine->multiplexer} session \"{$machine->session}\", start = {$machine->start}"
            .($machine->start === 'when-free' ? ", start_gap = {$machine->startGap}s" : '')
            .($machine->confirmChannels ? ', confirm_channels' : ''));

        foreach (Fleet::order($this->fleet->seatsOn($machine->name)) as $i => $seat) {
            $command = Launch::command($seat, $machine, $this->fleet);
            $window = $this->windowCommand($command, $seat, $machine);
            $argv = $i === 0
                ? $multiplexer->newSession($machine->session, $seat->window(), $seat->checkout, $window)
                : $multiplexer->newWindow($machine->session, $seat->window(), $seat->checkout, $window);

            ($this->out)('');
            ($this->out)(sprintf('[%d] %s  %s  (%s, work_location %s, start = %s)', $i + 1, $seat->label(), $seat->repository, $seat->role, $seat->workLocation, $seat->startMode($machine)));
            ($this->out)("    multiplexer: ".self::display($argv, $machine));
            if ($window === null) {
                ($this->out)('    then, once no live process holds the conversation: send-keys the command below');
            }
            ($this->out)("    command:     {$command}");
            ($this->out)('    prompt:      '.Launch::prompt($seat));
        }
    }

    public function stage(Machine $machine): int
    {
        $report = (new Checker($this->fleet, $this->shell, $this->host, $this->holders))->check($machine);
        foreach ($report->notes as $note) {
            ($this->out)("note: {$note}");
        }
        if (! $report->ok()) {
            foreach ($report->failures as $failure) {
                ($this->out)("refusing: {$failure}");
            }

            return 1;
        }

        $multiplexer = Multiplexer::for($machine);
        $seats = Fleet::order($this->fleet->seatsOn($machine->name));

        foreach ($seats as $i => $seat) {
            $window = $this->windowCommand(Launch::command($seat, $machine, $this->fleet), $seat, $machine);
            $argv = $i === 0
                ? $multiplexer->newSession($machine->session, $seat->window(), $seat->checkout, $window)
                : $multiplexer->newWindow($machine->session, $seat->window(), $seat->checkout, $window);
            $this->run($argv, "could not create the window for {$seat->label()}");
            ($this->out)("created {$seat->label()}");
        }

        $this->startWhenFree($machine, array_values(array_filter($seats, fn (Seat $s) => $s->startMode($machine) === 'when-free')), $multiplexer);
        if ($machine->confirmChannels) {
            foreach ($seats as $seat) {
                // A seat in `enter` is started by the operator, who answers its prompts; some harnesses show no channels warning.
                $seat->startMode($machine) === 'enter' || ! $seat->capabilities()->confirmChannels || $this->confirm($machine, $seat, $multiplexer);
            }
        }

        ($this->out)("staged {$machine->session}: attach with `{$machine->multiplexer} attach -t {$machine->session}`");

        return 0;
    }

    /** The command a window is created with; null leaves an idle shell for `when-free` to type into. */
    private function windowCommand(string $command, Seat $seat, Machine $machine): ?string
    {
        return match ($seat->startMode($machine)) {
            'now' => $command,
            'enter' => Launch::waiting($command, $seat, $machine),
            'when-free' => null,
        };
    }

    /**
     * Starts each seat once no live process holds its conversation, in `after` order, a
     * `start_gap` apart. A seat with no conversation yet is free at once. Only the seats whose own
     * start mode is `when-free` come here: a seat left waiting for the operator, or started at once,
     * neither waits on nor holds back the others.
     *
     * @param  list<Seat>  $seats
     */
    private function startWhenFree(Machine $machine, array $seats, Multiplexer $multiplexer): void
    {
        foreach ($seats as $i => $seat) {
            $waited = 0;
            while ($seat->conversation !== null && ($holder = $this->holders->holding($seat->conversation)) !== null) {
                if ($waited >= self::MAX_WAIT) {
                    throw new RuntimeException("gave up on {$seat->label()}: process {$holder->pid} still holds its conversation after ".self::MAX_WAIT.' seconds. The seats before it started; it and the seats after it were not started and sit at idle shells.');
                }
                ($this->out)("waiting: {$seat->label()}'s conversation is held by process {$holder->pid}");
                ($this->sleep)(self::WAIT_STEP);
                $waited += self::WAIT_STEP;
            }
            $this->run($multiplexer->type($machine->session, $seat->window(), Launch::command($seat, $machine, $this->fleet)), "could not type into the window for {$seat->label()}");
            $this->run($multiplexer->key($machine->session, $seat->window(), 'Enter'), "could not start {$seat->label()}");
            ($this->out)("started {$seat->label()}");

            if ($i < count($seats) - 1) {
                ($this->sleep)($machine->startGap);
            }
        }
    }

    /**
     * Answers the channels warning with its local-development option, and nothing else: the pane is
     * read, the exact text matched, and read again to see it cleared. Any other screen is left for
     * the operator, with what was seen printed.
     */
    private function confirm(Machine $machine, Seat $seat, Multiplexer $multiplexer): void
    {
        $seen = '';
        $screen = false;
        $unread = null;
        for ($poll = 0; $poll < self::POLLS; $poll++) {
            $capture = $this->shell->run($multiplexer->capture($machine->session, $seat->window()));
            $seen = $capture->out;
            $unread = $capture->code === 0 ? null : $capture->code;

            if (self::shows($seen, self::TRUST)) {
                $screen = true;
                break;
            }
            if (self::shows($seen, self::CHANNELS[0]) && self::shows($seen, self::CHANNELS[1])) {
                $screen = true;
                $this->run($multiplexer->key($machine->session, $seat->window(), 'Enter'), "could not answer the channels prompt for {$seat->label()}");
                ($this->sleep)(2);
                $after = $this->shell->run($multiplexer->capture($machine->session, $seat->window()))->out;
                if (! self::shows($after, self::CHANNELS[1])) {
                    ($this->out)("confirmed the channels prompt for {$seat->label()}");

                    return;
                }
                $seen = $after;
                break;
            }
            ($this->sleep)(2);
        }

        if (! $screen && $unread !== null) {
            ($this->out)("could not read the pane for {$seat->label()} ({$multiplexer->binary} capture-pane exited {$unread}). Nothing was answered; check the window yourself.");

            return;
        }

        if (! $screen) {
            // Seen once the warning had been acknowledged (fleet-kit#7): nothing to answer.
            ($this->out)("no channels prompt seen for {$seat->label()}; it may already be acknowledged. Nothing was answered.");

            return;
        }

        $lines = array_slice(array_filter(preg_split('/\R/', rtrim($seen)) ?: [], fn (string $l) => trim($l) !== ''), -12);
        ($this->out)("left for the operator: {$seat->label()} is waiting at a screen seat-stage will not answer. Last lines seen:");
        foreach ($lines as $line) {
            ($this->out)('    | '.$line);
        }
    }

    /**
     * Whether a pane shows a text. Whitespace is ignored on both sides: a pane narrower than the
     * line wraps it, even in the middle of a word, and psmux's `capture-pane -J` does not join it
     * again (measured in fleet-kit#4, a 36-column pane split "channels" into "chann" and "els").
     */
    private static function shows(string $pane, string $text): bool
    {
        return str_contains(preg_replace('/\s+/', '', $pane) ?? '', preg_replace('/\s+/', '', $text) ?? '');
    }

    /** @param  list<string>  $argv */
    private function run(array $argv, string $failure): void
    {
        if ($this->shell->run($argv)->code !== 0) {
            throw new RuntimeException("{$failure}: ".self::display($argv, null).'. The session may be partly built; nothing was removed.');
        }
    }

    /** @param  list<string>  $argv */
    private static function display(array $argv, ?Machine $machine): string
    {
        return implode(' ', array_map(
            fn (string $a) => $machine?->windows() ? Launch::powershell($a) : Launch::posix($a),
            $argv,
        ));
    }
}
