<?php

declare(strict_types=1);

use FleetKit\Release;

/** @return array{0: Release, 1: object} the release and a counter of lookups */
function release(string $installed, ?string $latest, ?string $cache = null, int $now = 1_000_000, bool $enabled = true): array
{
    $calls = new class
    {
        public int $lookups = 0;
    };
    $release = new Release(
        installed: fn () => $installed,
        latest: function () use ($latest, $calls) {
            $calls->lookups++;

            return $latest;
        },
        cacheFile: $cache,
        now: fn () => $now,
        enabled: $enabled,
    );

    return [$release, $calls];
}

function releaseCache(): string
{
    return sys_get_temp_dir().'/fleet-kit-release-'.bin2hex(random_bytes(4)).'/latest-release.json';
}

it('says so, once, when the installed release is behind the latest', function () {
    [$release] = release('v0.1.3', 'v0.1.5');
    $line = $release->behindNotice();

    expect($line)->toContain('v0.1.3 is installed and v0.1.5 is out')
        ->toContain('composer global update robot-council/fleet-kit')
        ->toContain('docs/getting-started.md#updating')
        ->toContain('FLEET_KIT_NO_UPDATE_CHECK=1')
        ->not->toContain("\n");
});

it('is silent when up to date, ahead, on a development version, or when the lookup fails', function (string $installed, ?string $latest) {
    [$release] = release($installed, $latest);

    expect($release->behindNotice())->toBeNull();
})->with([
    'up to date' => ['v0.1.5', 'v0.1.5'],
    'a tag without the v' => ['0.1.5', 'v0.1.5'],
    'ahead' => ['v0.2.0', 'v0.1.5'],
    'a checkout past the latest tag' => ['v0.1.5-2-g0fc1e3e', 'v0.1.5'],
    'a development version' => ['dev-main', 'v0.1.5'],
    'unknown' => ['unknown', 'v0.1.5'],
    'offline' => ['v0.1.3', null],
]);

it('compares a checkout behind the latest tag by its tag', function () {
    [$release] = release('v0.1.3-4-gabcdef0', 'v0.1.5');

    expect($release->behindNotice())->toContain('v0.1.3-4-gabcdef0 is installed and v0.1.5 is out');
});

it('compares versions as numbers, not text', function () {
    [$release] = release('v0.9.0', 'v0.10.0');

    expect($release->behindNotice())->not->toBeNull();
});

it('looks the latest release up once a day and keeps the answer', function () {
    $cache = releaseCache();
    [$first, $calls] = release('v0.1.3', 'v0.1.5', $cache, now: 1_000_000);
    $first->behindNotice();
    [$sameDay, $again] = release('v0.1.3', 'v0.9.9', $cache, now: 1_000_000 + 3600);
    $line = $sameDay->behindNotice();
    [$nextDay, $later] = release('v0.1.3', 'v0.9.9', $cache, now: 1_000_000 + 86400);

    expect($calls->lookups)->toBe(1)
        ->and($again->lookups)->toBe(0)
        ->and($line)->toContain('v0.1.5 is out')
        ->and($nextDay->behindNotice())->toContain('v0.9.9 is out')
        ->and($later->lookups)->toBe(1);
});

it('does not retry a failed lookup until the cache expires, and keeps the last known release', function () {
    $cache = releaseCache();
    release('v0.1.3', 'v0.1.5', $cache, now: 1_000_000)[0]->behindNotice();
    [$offline, $calls] = release('v0.1.3', null, $cache, now: 1_000_000 + 86400);
    $first = $offline->behindNotice();
    [$next, $more] = release('v0.1.3', null, $cache, now: 1_000_000 + 86400 + 60);

    expect($first)->toContain('v0.1.5 is out')
        ->and($calls->lookups)->toBe(1)
        ->and($next->behindNotice())->toContain('v0.1.5 is out')
        ->and($more->lookups)->toBe(0);
});

it('stays quiet and tries once when it has nowhere to cache or the cache is unreadable', function () {
    $cache = releaseCache();
    mkdir(dirname($cache), 0777, true);
    file_put_contents($cache, 'not json');
    [$broken, $calls] = release('v0.1.3', 'v0.1.5', $cache);
    [$none] = release('v0.1.3', 'v0.1.5', null);

    expect($broken->behindNotice())->toContain('v0.1.5 is out')
        ->and($calls->lookups)->toBe(1)
        ->and($none->behindNotice())->toContain('v0.1.5 is out');
});

it('can be turned off, and then does not look anything up', function () {
    [$release, $calls] = release('v0.1.3', 'v0.1.5', releaseCache(), enabled: false);

    expect($release->behindNotice())->toBeNull()
        ->and($calls->lookups)->toBe(0);
});

it('never throws when the lookup does', function () {
    $release = new Release(fn () => 'v0.1.3', fn () => throw new RuntimeException('no network'), null, fn () => 1);

    expect($release->behindNotice())->toBeNull();
});

it('reads the version of this checkout', function () {
    expect(Release::detect(dirname(__DIR__, 2)))->toMatch('/^(v?\d+\.\d+\.\d+.*|[0-9a-f]{7,}|unknown)$/');
});

it('reports unknown for a directory that is neither installed nor a checkout', function () {
    $dir = sys_get_temp_dir().'/fleet-kit-not-a-checkout-'.bin2hex(random_bytes(4));
    mkdir($dir);

    // Composer's data for this test run names fleet-kit as the root package at dev-main, so Composer gives nothing
    // here and the empty directory has no .git: nothing is claimed.
    expect(Release::detect($dir))->toBe('unknown');
});
