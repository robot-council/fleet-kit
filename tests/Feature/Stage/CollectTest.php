<?php

declare(strict_types=1);

use FleetKit\Pace\ConfigError;
use FleetKit\Stage\Collector;
use FleetKit\Stage\Fleet;
use FleetKit\Stage\Holders;
use Tests\Support\FakeClock;
use Tests\Support\Fakes;

function collectWith(string $toml, array $sessionFiles, array $alive, string $platform = 'Windows'): array
{
    $home = Fakes::sessions($sessionFiles);
    $path = $home.'/seats.toml';
    file_put_contents($path, $toml);
    $fleet = Fleet::load($path);
    $out = new ArrayObject;

    $code = (new Collector($fleet, new Holders($home.'/.claude/sessions', new FakeClock($alive), $platform), function (string $l) use ($out) { $out[] = $l; }))
        ->collect($fleet->machineNamed('m'), $path);

    return [$code, $out->getArrayCopy(), (string) file_get_contents($path)];
}

function live(string $id, string $cwd): array
{
    return ['sessionId' => $id, 'cwd' => $cwd, 'procStart' => Fakes::filetime(Fakes::STARTED)];
}

it('records a live conversation in the seat whose checkout it runs in, keeping comments and layout', function () {
    $toml = "# my fleet\n".stageToml(seatToml('a', "# the build seat\nprompt = \"hi\""));
    [$code, $out, $after] = collectWith($toml, [201 => live('conv-new', '/src/repo/')], [201 => Fakes::STARTED]);

    expect($code)->toBe(0)
        ->and($out)->toBe(['m/a: recorded conv-new'])
        ->and($after)->toContain("slot = \"a\"\nconversation = \"conv-new\"\n")
        ->toStartWith("# my fleet\n")->toContain("# the build seat\nprompt = \"hi\"");

    // And the result still reads.
    expect(Fleet::parse($after)->seats[0]->conversation)->toBe('conv-new');
});

it('replaces a recorded conversation that has changed, and leaves an unchanged one', function () {
    $toml = stageToml(seatToml('a', 'conversation = "old"'));

    [, $out, $after] = collectWith($toml, [201 => live('conv-new', '/src/repo')], [201 => Fakes::STARTED]);
    expect($out)->toBe(['m/a: changed from old to conv-new'])->and($after)->toContain('conversation = "conv-new"')->not->toContain('"old"');

    [, $out, $same] = collectWith($toml, [201 => live('old', '/src/repo')], [201 => Fakes::STARTED]);
    expect($out)->toBe(['m/a: already old'])->and($same)->toBe($toml);
});

it('keeps Windows line endings', function () {
    $toml = str_replace("\n", "\r\n", stageToml());
    [, , $after] = collectWith($toml, [201 => live('conv-new', '/src/repo')], [201 => Fakes::STARTED]);

    expect($after)->toContain("slot = \"a\"\r\nconversation = \"conv-new\"\r\n")->not->toMatch('/[^\r]\n/');
});

it('matches a checkout whatever the slash direction or case', function () {
    $toml = str_replace('/src/repo', 'C:/Src/Repo', stageToml());
    [, $out] = collectWith($toml, [201 => live('conv-new', 'c:\\src\\repo')], [201 => Fakes::STARTED]);

    expect($out)->toBe(['m/a: recorded conv-new']);
});

it('records nothing for a seat with no live conversation, a dead process, or two candidates', function () {
    $toml = stageToml();

    [, $none, $same] = collectWith($toml, [], []);
    expect($none)->toBe(['m/a: no live conversation in /src/repo'])->and($same)->toBe($toml);

    [, $dead] = collectWith($toml, [201 => live('conv-1', '/src/repo')], []);
    expect($dead)->toBe(['m/a: no live conversation in /src/repo']);

    [, $two, $unchanged] = collectWith($toml, [201 => live('c1', '/src/repo'), 202 => live('c2', '/src/repo')], [201 => Fakes::STARTED, 202 => Fakes::STARTED]);
    expect($two[0])->toContain('2 live conversations')->toContain('not guessing')->and($unchanged)->toBe($toml);
});

it('picks the right seat block among several', function () {
    $set = Collector::set(stageToml(seatToml('a').seatToml('b')), 'm', 'b', 'conv-b');

    expect(Fleet::parse($set)->seats[1]->conversation)->toBe('conv-b')
        ->and(Fleet::parse($set)->seats[0]->conversation)->toBeNull();
});

it('refuses where the session files cannot be read', function () {
    collectWith(stageToml(), [], [], 'Linux');
})->throws(ConfigError::class, 'unreadable on this platform');

it('refuses a seat that is not in the file', function () {
    Collector::set(stageToml(), 'm', 'ghost', 'x');
})->throws(ConfigError::class, 'no [[seat]] for m/ghost');

it('records nothing, and writes nothing, in a dry run', function () {
    $toml = stageToml();
    $home = Fakes::sessions([201 => live('conv-new', '/src/repo')]);
    $path = $home.'/seats.toml';
    file_put_contents($path, $toml);
    $fleet = Fleet::load($path);
    $out = new ArrayObject;

    (new Collector($fleet, new Holders($home.'/.claude/sessions', new FakeClock([201 => Fakes::STARTED]), 'Windows'), function (string $l) use ($out) { $out[] = $l; }))
        ->collect($fleet->machineNamed('m'), $path, dryRun: true);

    expect($out->getArrayCopy())->toBe(['m/a: would record conv-new'])->and(file_get_contents($path))->toBe($toml);
});

it('does not record one conversation for two seats', function () {
    $toml = stageToml(seatToml('a', 'conversation = "conv-new"').seatToml('b'));
    [, $out, $after] = collectWith($toml, [201 => live('conv-new', '/src/repo')], [201 => Fakes::STARTED]);

    expect(implode("\n", $out))->toContain('m/b: conv-new is already recorded for m/a')
        ->and(Fleet::parse($after)->seats[1]->conversation)->toBeNull();
});

it('keeps mixed line endings byte for byte, and ends a new line like the line before it', function () {
    $source = "[[seat]]\r\nmachine = \"m\"\nslot = \"a\"\r\ncheckout = \"/x\"\n";

    expect(Collector::set($source, 'm', 'a', 'c-1'))
        ->toBe("[[seat]]\r\nmachine = \"m\"\nslot = \"a\"\r\nconversation = \"c-1\"\r\ncheckout = \"/x\"\n");

    // A slot line that ends the file gets a terminator, and the new last line has none.
    expect(Collector::set("[[seat]]\nmachine = \"m\"\nslot = \"a\"", 'm', 'a', 'c-1'))
        ->toBe("[[seat]]\nmachine = \"m\"\nslot = \"a\"\nconversation = \"c-1\"");
});
