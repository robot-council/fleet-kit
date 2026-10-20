<?php

declare(strict_types=1);

namespace FleetKit\Stage;

use FleetKit\Pace\ConfigError;
use FleetKit\Pace\Toml;

/** One seat: a Claude Code conversation in one checkout, with the role it joins the fleet in. */
final readonly class Seat
{
    /** @param  list<string>  $after  slots on the same machine that start before this one */
    public function __construct(
        public string $machine,
        public string $slot,
        public string $repository,
        public string $checkout,
        public string $role,
        public string $workLocation,
        public string $harness,
        public ?int $capacity,
        public ?string $conversation,
        public string $remoteControl,
        public array $after,
        public string $prompt,
    ) {}

    public function label(): string
    {
        return "{$this->machine}/{$this->slot}";
    }

    /** A window name a multiplexer target can carry: no dots, colons or spaces. */
    public function window(): string
    {
        return ltrim(preg_replace('/[^A-Za-z0-9_-]+/', '-', basename($this->repository).'-'.$this->slot) ?? $this->slot, '-');
    }
}

/** A machine's staging settings. */
final readonly class Machine
{
    public const MODES = ['enter', 'now', 'when-free'];

    public function __construct(
        public string $name,
        public string $multiplexer,
        public string $session,
        public string $start,
        public int $startGap,
        public bool $confirmChannels,
        public bool $remoteControl,
    ) {}

    /** psmux is the Windows multiplexer; tmux serves macOS and Linux. */
    public function windows(): bool
    {
        return $this->multiplexer === 'psmux';
    }
}

/** The seats file, reduced to what seat-stage reads: the channel, the machines and the seats. */
final readonly class Fleet
{
    /** @param  array<string, Machine>  $machines
     * @param  list<Seat>  $seats */
    public function __construct(
        public string $channel,
        public array $machines,
        public array $seats,
    ) {}

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigError("seats file not found: {$path}");
        }

        return self::parse((string) file_get_contents($path));
    }

    public static function parse(string $source): self
    {
        $data = Toml::parse($source);

        $channel = $data['channel'] ?? 'server:robot-council';
        if (! is_string($channel) || ! preg_match('/^(server:[^\s]+|plugin:[^\s@]+@[^\s]+)$/', $channel)) {
            throw new ConfigError('channel must be a tagged entry: server:<name> or plugin:<name>@<marketplace>');
        }

        $machines = [];
        foreach ($data['machine'] ?? [] as $row) {
            $machine = self::machine($row);
            isset($machines[$machine->name]) && throw new ConfigError("machine \"{$machine->name}\" is listed twice");
            $machines[$machine->name] = $machine;
        }

        $seats = [];
        foreach ($data['seat'] ?? [] as $row) {
            $seat = self::seat($row, $machines);
            foreach ($seats as $other) {
                if ($other->machine !== $seat->machine) {
                    continue;
                }
                $other->slot === $seat->slot && throw new ConfigError("{$seat->label()}: slot is listed twice");
                $other->workLocation === $seat->workLocation && throw new ConfigError("{$seat->label()}: work_location \"{$seat->workLocation}\" is already used on this machine");
                $other->window() === $seat->window() && throw new ConfigError("{$seat->label()}: window name \"{$seat->window()}\" is already used by {$other->label()}; rename a slot");
                $seat->conversation !== null && $other->conversation === $seat->conversation && throw new ConfigError("{$seat->label()}: conversation {$seat->conversation} is already used by {$other->label()}");
            }
            $seats[] = $seat;
        }

        $fleet = new self($channel, $machines, $seats);
        $fleet->orderCheck();

        return $fleet;
    }

    /** @return list<Seat> */
    public function seatsOn(string $machine): array
    {
        return array_values(array_filter($this->seats, fn (Seat $s) => $s->machine === $machine));
    }

    public function machineNamed(string $name): Machine
    {
        return $this->machines[$name] ?? throw new ConfigError('no machine named "'.$name.'"'.($this->machines === [] ? '' : '; known: '.implode(', ', array_keys($this->machines))));
    }

    /** The server name `claude mcp list` shows for a server: channel entry, or null for a plugin. */
    public function mcpServer(): ?string
    {
        return str_starts_with($this->channel, 'server:') ? substr($this->channel, 7) : null;
    }

    /** A slot's `after` list must name slots on its machine, and must not loop. */
    private function orderCheck(): void
    {
        foreach ($this->machines as $name => $machine) {
            $seats = $this->seatsOn($name);
            $slots = array_map(fn (Seat $s) => $s->slot, $seats);
            foreach ($seats as $seat) {
                foreach ($seat->after as $slot) {
                    in_array($slot, $slots, true) || throw new ConfigError("{$seat->label()}: after names \"{$slot}\", which is not a slot on {$name}");
                }
            }
            self::order($seats);
        }
    }

    /**
     * Seats in start order: those named by another's `after` come first; otherwise file order.
     *
     * @param  list<Seat>  $seats
     * @return list<Seat>
     */
    public static function order(array $seats): array
    {
        $done = [];
        $ordered = [];
        $pending = $seats;
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $i => $seat) {
                if (array_diff($seat->after, $done) === []) {
                    $ordered[] = $seat;
                    $done[] = $seat->slot;
                    unset($pending[$i]);
                    $progress = true;
                    break;
                }
            }
            $progress || throw new ConfigError('after loops among: '.implode(', ', array_map(fn (Seat $s) => $s->label(), $pending)));
        }

        return $ordered;
    }

    /** @param  array<string, mixed>  $row */
    private static function machine(array $row): Machine
    {
        $name = self::text($row, 'name', null, 'machine');
        $at = "machine \"{$name}\"";

        $multiplexer = self::text($row, 'multiplexer', PHP_OS_FAMILY === 'Windows' ? 'psmux' : 'tmux', $at);
        in_array($multiplexer, ['tmux', 'psmux'], true) || throw new ConfigError("{$at}: multiplexer must be \"tmux\" or \"psmux\"");

        $start = self::text($row, 'start', 'enter', $at);
        in_array($start, Machine::MODES, true) || throw new ConfigError("{$at}: start must be one of ".implode(', ', Machine::MODES));

        $gap = self::text($row, 'start_gap', '5s', $at);
        preg_match('/^(\d+)(s|m)$/', $gap, $m) || throw new ConfigError("{$at}: start_gap must look like 5s or 1m");

        foreach (['confirm_channels', 'remote_control'] as $flag) {
            isset($row[$flag]) && ! is_bool($row[$flag]) && throw new ConfigError("{$at}: {$flag} must be true or false");
        }

        return new Machine(
            name: $name,
            multiplexer: $multiplexer,
            session: self::session(self::text($row, 'session', 'fleet', $at), $at),
            start: $start,
            startGap: (int) $m[1] * ($m[2] === 'm' ? 60 : 1),
            confirmChannels: (bool) ($row['confirm_channels'] ?? false),
            remoteControl: (bool) ($row['remote_control'] ?? true),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, Machine>  $machines
     */
    private static function seat(array $row, array $machines): Seat
    {
        $machine = self::text($row, 'machine', null, 'seat');
        $slot = self::text($row, 'slot', null, "seat on \"{$machine}\"");
        $at = "{$machine}/{$slot}";

        isset($machines[$machine]) || throw new ConfigError("{$at}: machine \"{$machine}\" is not defined");
        preg_match('/^[A-Za-z0-9._-]+$/', $slot) || throw new ConfigError("{$at}: slot may hold letters, digits, dot, dash and underscore");

        $repository = self::text($row, 'repository', null, $at);
        preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $repository) || throw new ConfigError("{$at}: repository must look like owner/name");

        $role = self::text($row, 'role', null, $at);
        in_array($role, ['build', 'ci', 'coordinator'], true) || throw new ConfigError("{$at}: role must be build, ci or coordinator");

        $workLocation = self::text($row, 'work_location', $slot, $at);
        preg_match('/^[a-z0-9._-]+$/', $workLocation) || throw new ConfigError("{$at}: work_location is a short label of [a-z0-9._-], never a path");

        $harness = self::text($row, 'harness', 'claude', $at);
        $harness === 'claude' || throw new ConfigError("{$at}: harness \"{$harness}\" is not stageable; only claude is");

        $capacity = $row['capacity'] ?? null;
        if ($capacity !== null && (! is_int($capacity) || $capacity < 1)) {
            throw new ConfigError("{$at}: capacity must be a whole number, 1 or more");
        }

        $conversation = $row['conversation'] ?? null;
        if ($conversation !== null && (! is_string($conversation) || $conversation === '')) {
            throw new ConfigError("{$at}: conversation must be the id as given");
        }

        $conversation !== null && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $conversation) && throw new ConfigError("{$at}: conversation must be an id of letters, digits, dot, dash and underscore");

        $remoteControl = self::text($row, 'remote_control', basename($repository)."/{$machine}/{$slot}", $at);
        str_starts_with($remoteControl, '-') && throw new ConfigError("{$at}: remote_control may not start with a dash");

        $checkout = self::text($row, 'checkout', null, $at);
        str_starts_with($checkout, '-') && throw new ConfigError("{$at}: checkout may not start with a dash");
        preg_match('/#\(|[\x00-\x1f]/', $checkout) && throw new ConfigError("{$at}: checkout may not hold control characters or a tmux #( expansion");

        $prompt = isset($row['prompt']) ? self::text($row, 'prompt', null, $at) : '';
        str_contains($prompt, '"') && throw new ConfigError("{$at}: prompt may not hold a double quote; Windows PowerShell 5.1 splits an argument on it");

        $after = [];
        foreach ((array) ($row['after'] ?? []) as $slotName) {
            is_string($slotName) || throw new ConfigError("{$at}: after is a list of slots");
            $after[] = $slotName;
        }

        return new Seat(
            machine: $machine,
            slot: $slot,
            repository: $repository,
            checkout: $checkout,
            role: $role,
            workLocation: $workLocation,
            harness: $harness,
            capacity: $capacity,
            conversation: $conversation,
            remoteControl: $remoteControl,
            after: $after,
            prompt: $prompt,
        );
    }

    /** A multiplexer session name: letters, digits, dash and underscore, so no target syntax can ride in it. */
    private static function session(string $session, string $at): string
    {
        preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]*$/', $session) || throw new ConfigError("{$at}: session may hold letters, digits, dash and underscore, and does not start with a dash");

        return $session;
    }

    /** @param  array<string, mixed>  $row */
    private static function text(array $row, string $key, ?string $default, string $at): string
    {
        $value = $row[$key] ?? $default;
        if (! is_string($value) || $value === '') {
            throw new ConfigError("{$at}: {$key} is required");
        }

        return $value;
    }
}
