<?php

declare(strict_types=1);

use FleetKit\Pace\ConfigError;
use FleetKit\Stage\Collector;
use FleetKit\Stage\Fleet;
use FleetKit\Stage\Holders;
use FleetKit\Stage\Launch;
use FleetKit\Stage\Multiplexer;
use FleetKit\Stage\PsClock;
use FleetKit\Stage\Result;
use FleetKit\Stage\Unreadable;
use FleetKit\Stage\WindowsClock;
use Tests\Support\FakeClock;
use Tests\Support\FakeShell;
use Tests\Support\Fakes;

// FakeShell and FakeClock share Fakes.php, so PSR-4 finds them only once Fakes is loaded.
class_exists(Fakes::class);

it('doubles every quote PowerShell reads as a single quote', function () {
    expect(Launch::powershell("it's"))->toBe("'it''s'")
        ->and(Launch::powershell("a\u{2018}b\u{2019}c\u{201A}d\u{201B}e"))
        ->toBe("'a\u{2018}\u{2018}b\u{2019}\u{2019}c\u{201A}\u{201A}d\u{201B}\u{201B}e'");
});

it('quotes what PowerShell would read as splatting or an array', function (string $text) {
    expect(Launch::powershell($text))->toBe("'{$text}'");
})->with(['@x', '1,2', 'a b']);

it('leaves a plain word bare', function () {
    expect(Launch::powershell('server:robot-council'))->toBe('server:robot-council')
        ->and(Launch::powershell('C:\\src\\repo'))->toBe('C:\\src\\repo');
});

it('rejects a double quote in a seat prompt', function () {
    Fleet::parse(stageToml(seatToml('a', 'prompt = "say \\"hi\\""')));
})->throws(ConfigError::class, 'double quote');

it('rejects two seats on one machine with the same conversation', function () {
    Fleet::parse(stageToml(seatToml('a', 'conversation = "c-1"').seatToml('b', 'conversation = "c-1"')));
})->throws(ConfigError::class, 'already used by m/a');

it('rejects two seats whose window names collide', function () {
    // a-b + c and a + b-c both make the window a-b-c.
    $first = str_replace('org/some.repo', 'org/a-b', seatToml('c'));
    $second = str_replace('org/some.repo', 'org/a', seatToml('b-c'));

    Fleet::parse(stageToml($first.$second));
})->throws(ConfigError::class, 'window name "a-b-c"');

it('rejects values that could ride in as options or tmux expansions', function (string $extra) {
    Fleet::parse(stageToml(seatToml('a', $extra)));
})->throws(ConfigError::class)->with([
    'conversation with a leading dash' => ['conversation = "-x"'],
    'conversation with a glob' => ['conversation = "a*b"'],
    'remote_control with a leading dash' => ['remote_control = "-x"'],
]);

it('rejects a checkout that starts with a dash or holds a tmux expansion', function (string $checkout) {
    Fleet::parse(str_replace('/src/repo', $checkout, stageToml()));
})->throws(ConfigError::class)->with(['-rf', '/src/#(touch x)']);

it('rejects a session name that is not plain', function (string $session) {
    Fleet::parse(stageToml(machines: "[[machine]]\nname = \"m\"\nmultiplexer = \"tmux\"\nsession = \"{$session}\"\n"));
})->throws(ConfigError::class, 'session may hold')->with(['-x', 'a:b', 'a.b']);

it('matches a tmux session exactly, not by prefix', function () {
    $tmux = new Multiplexer('tmux');
    $psmux = new Multiplexer('psmux');

    expect($tmux->hasSession('fleet'))->toBe(['tmux', 'has-session', '-t', '=fleet'])
        ->and($tmux->key('fleet', 'w', 'Enter'))->toBe(['tmux', 'send-keys', '-t', '=fleet:=w', 'Enter'])
        ->and($psmux->hasSession('fleet'))->toBe(['psmux', 'has-session', '-t', 'fleet']);
});

it('throws, rather than call a conversation free, when the process clock fails', function () {
    $shell = new FakeShell([[['powershell'], new Result(1)]]);

    (new WindowsClock($shell))->startedAt(101);
})->throws(Unreadable::class);

it('reads an empty answer with exit 0 as a process that is not running', function () {
    $clock = new WindowsClock(new FakeShell([[['powershell'], new Result(0, '')]]));

    expect($clock->startedAt(101))->toBeNull();
});

it('reads ps exit 1 with no output as not running, and any other failure as unreadable', function () {
    expect((new PsClock(new FakeShell([[['env'], new Result(1, '')]])))->startedAt(5))->toBeNull();

    expect(fn () => (new PsClock(new FakeShell([[['env'], new Result(2, '')]])))->startedAt(5))->toThrow(Unreadable::class);
    expect(fn () => (new PsClock(new FakeShell([[['env'], new Result(0, 'garbled')]])))->startedAt(5))->toThrow(Unreadable::class);
});

it('refuses a conversation when its session file has an unreadable procStart', function () {
    $home = Fakes::sessions([101 => ['sessionId' => 'conv-1', 'procStart' => 'not a time']]);
    $holders = new Holders($home.'/.claude/sessions', new \Tests\Support\FakeClock([101 => Fakes::STARTED]), 'Darwin');

    $holders->holding('conv-1');
})->throws(Unreadable::class, 'procStart');

it('finds session files when the home directory holds a bracket', function () {
    $home = sys_get_temp_dir().'/seat[stage]-'.bin2hex(random_bytes(4));
    mkdir($home.'/sessions', 0777, true);
    file_put_contents($home.'/sessions/101.json', json_encode(['pid' => 101, 'sessionId' => 'conv-1', 'procStart' => Fakes::filetime(Fakes::STARTED)]));

    $holders = new Holders($home.'/sessions', new \Tests\Support\FakeClock([101 => Fakes::STARTED]), 'Windows');

    expect($holders->holding('conv-1')?->pid)->toBe(101);
});

it('sets conversation in a seat header written with inner spaces, and keeps a trailing comment', function () {
    $source = "[[ seat ]]\nmachine = \"m\"\nslot = \"a\"\nconversation = \"old\" # the long one\n";

    expect(Collector::set($source, 'm', 'a', 'new-1'))->toBe("[[ seat ]]\nmachine = \"m\"\nslot = \"a\"\nconversation = \"new-1\" # the long one\n");
});

it('refuses to write an id the seats file would not accept', function () {
    Collector::set(stageToml(), 'm', 'a', 'x" injected = "y');
})->throws(ConfigError::class, 'conversation ids');

it('finds a Codex rollout by conversation id under CODEX_HOME, and says when it cannot look', function () {
    $dir = sys_get_temp_dir().'/codex-home-'.bin2hex(random_bytes(4));
    mkdir($dir.'/sessions/2026/10/30', 0777, true);
    touch($dir.'/sessions/2026/10/30/rollout-2026-10-30T10-00-00-019a-uuid.jsonl');
    putenv("CODEX_HOME={$dir}");
    $host = FleetKit\Stage\Host::real();
    $found = [($host->hasCodexSession)('019a-uuid'), ($host->hasCodexSession)('other')];
    putenv("CODEX_HOME={$dir}/missing");
    $unreadable = ($host->hasCodexSession)('019a-uuid');
    putenv('CODEX_HOME');

    expect($found)->toBe([true, false])->and($unreadable)->toBeNull();
});
