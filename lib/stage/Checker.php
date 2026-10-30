<?php

declare(strict_types=1);

namespace FleetKit\Stage;

/** Every reason staging would fail, and things worth knowing that would not stop it. */
final class Report
{
    /** @var list<string> */
    public array $failures = [];

    /** @var list<string> */
    public array $notes = [];

    public function fail(string $message): void
    {
        $this->failures[] = $message;
    }

    public function note(string $message): void
    {
        $this->notes[] = $message;
    }

    public function ok(): bool
    {
        return $this->failures === [];
    }
}

/** `seat-stage check`: reports and changes nothing. */
final readonly class Checker
{
    public function __construct(
        private Fleet $fleet,
        private Shell $shell,
        private Host $host,
        private Holders $holders,
    ) {}

    public function check(Machine $machine): Report
    {
        $report = new Report;
        $seats = $this->fleet->seatsOn($machine->name);
        $multiplexer = Multiplexer::for($machine);

        $seats === [] && $report->fail("{$machine->name}: no seats are listed for this machine");

        if (! ($this->host->onPath)($machine->multiplexer)) {
            $report->fail("{$machine->multiplexer} is not on PATH");
        } elseif ($this->shell->run($multiplexer->hasSession($machine->session))->code === 0) {
            $report->fail("the {$machine->multiplexer} session \"{$machine->session}\" already exists; staging refuses to touch it");
        }

        foreach (array_unique(array_map(fn (Seat $s) => Launch::executable($s), $seats)) as $program) {
            ($this->host->onPath)($program) || $report->fail("{$program} is not on PATH");
        }

        $entering = array_map(fn (Seat $s) => $s->label(), array_values(array_filter($seats, fn (Seat $s) => $s->startMode($machine) === 'enter')));
        if ($machine->confirmChannels && $entering !== []) {
            $report->note('confirm_channels has no effect in start = "enter" ('.implode(', ', $entering).'): the window starts when the operator presses Enter');
        }

        $detect = $this->holders->readable();
        $whenFree = array_values(array_filter($seats, fn (Seat $s) => $s->startMode($machine) === 'when-free' && ! $s->codex()));
        if ($whenFree !== [] && ! $detect) {
            $report->fail("start = \"when-free\" needs the session files, which are unreadable on {$this->host->platform}; it refuses seats it cannot detect");
        } elseif (! $detect) {
            $report->note("cannot tell whether a live process already holds a conversation on {$this->host->platform}");
        }

        foreach ($seats as $seat) {
            $this->seat($seat, $machine, $report, $detect);
        }

        return $report;
    }

    private function seat(Seat $seat, Machine $machine, Report $report, bool $detect): void
    {
        $at = $seat->label();

        if (! ($this->host->isDirectory)($seat->checkout)) {
            $report->fail("{$at}: the checkout {$seat->checkout} does not exist");
        }

        if ($seat->codex()) {
            $this->codexSeat($seat, $machine, $report);

            return;
        }

        if ($seat->conversation !== null) {
            if (! ($this->host->hasTranscript)($seat->conversation)) {
                $report->fail("{$at}: conversation {$seat->conversation} has no transcript under ~/.claude/projects");
            }
            try {
                $holder = $detect ? $this->holders->holding($seat->conversation) : null;
            } catch (Unreadable $e) {
                $report->fail("{$at}: cannot tell whether a live process holds {$seat->conversation}: {$e->getMessage()}");
                $holder = null;
            }
            if ($holder !== null) {
                // when-free exists to wait for a held conversation; any other mode would start a second one.
                $seat->startMode($machine) === 'when-free'
                    ? $report->note("{$at}: conversation {$seat->conversation} is held by process {$holder->pid}; it starts once that exits")
                    : $report->fail("{$at}: conversation {$seat->conversation} is already held by process {$holder->pid}");
            }
        }

        $this->server($seat, $report);
    }

    /**
     * A Codex seat: its conversation is looked for where Codex keeps it, and the bridge in Codex's own
     * configuration. What cannot be read is said to be unchecked, never passed. Whether a live process
     * holds the conversation is not detectable (the holder files are Claude Code's), so a Codex seat
     * is refused `when-free`.
     */
    private function codexSeat(Seat $seat, Machine $machine, Report $report): void
    {
        $at = $seat->label();

        if ($seat->startMode($machine) === 'when-free') {
            $report->fail("{$at}: start = \"when-free\" cannot tell whether a live process holds a Codex conversation; use \"enter\" or \"now\" for a Codex seat");
        } elseif ($seat->conversation !== null) {
            $report->note("{$at}: cannot tell whether a live process holds Codex conversation {$seat->conversation}; not checked");
        }

        if ($seat->conversation !== null) {
            $found = $this->host->hasCodexSession === null ? null : ($this->host->hasCodexSession)($seat->conversation);
            match ($found) {
                true => null,
                false => $report->fail("{$at}: Codex conversation {$seat->conversation} has no rollout under ~/.codex/sessions"),
                null => $report->note("{$at}: could not read Codex's sessions directory; conversation {$seat->conversation} not checked"),
            };
        }

        $name = $this->fleet->mcpServer();
        if ($name === null) {
            $report->note("{$at}: the channel is a plugin entry; its MCP server was not checked");

            return;
        }

        $list = $this->shell->run(['codex', 'mcp', 'list', '--json'], $seat->checkout);
        $entries = $list->code === 0 ? json_decode($list->out, true) : null;
        if (! is_array($entries)) {
            $report->note("{$at}: `codex mcp list --json` could not be read in {$seat->checkout}; the \"{$name}\" server was not checked");

            return;
        }

        foreach ($entries as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $name) {
                $harness = $entry['transport']['env']['ROBOT_COUNCIL_HARNESS'] ?? null;
                if ($harness === null) {
                    $report->note("{$at}: the \"{$name}\" entry sets no ROBOT_COUNCIL_HARNESS in its env; its harness was not checked");
                } elseif ($harness !== 'codex') {
                    $report->fail("{$at}: the \"{$name}\" entry has ROBOT_COUNCIL_HARNESS {$harness}, the seats file says codex");
                }

                return;
            }
        }
        $report->fail("{$at}: the channel server \"{$name}\" is not in `codex mcp list`");
    }

    /** The channel's MCP server must exist, and its --project and --harness must agree with the seat. */
    private function server(Seat $seat, Report $report): void
    {
        $name = $this->fleet->mcpServer();
        if ($name === null) {
            $report->note("{$seat->label()}: the channel is a plugin entry; its MCP server was not checked");

            return;
        }
        if (! ($this->host->isDirectory)($seat->checkout)) {
            return;
        }

        $list = $this->shell->run(['claude', 'mcp', 'list'], $seat->checkout);
        if ($list->code !== 0) {
            $report->fail("{$seat->label()}: `claude mcp list` failed in {$seat->checkout}");

            return;
        }

        $entry = null;
        foreach (preg_split('/\R/', $list->out) ?: [] as $line) {
            if (str_starts_with(trim($line), $name.':')) {
                $entry = $line;
                break;
            }
        }
        if ($entry === null) {
            $report->fail("{$seat->label()}: the channel server \"{$name}\" is not in `claude mcp list`");

            return;
        }

        foreach (['project' => $seat->repository, 'harness' => $seat->harness] as $flag => $expected) {
            if (preg_match('/--'.$flag.'[= ]([^\s]+)/', $entry, $m) && $m[1] !== $expected) {
                $report->fail("{$seat->label()}: the \"{$name}\" entry has --{$flag} {$m[1]}, the seats file says {$expected}");
            }
        }
    }
}
