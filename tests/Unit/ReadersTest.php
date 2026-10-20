<?php

declare(strict_types=1);

use FleetKit\Pace\ClaudeUsageReader;
use FleetKit\Pace\CredentialStore;
use FleetKit\Pace\CurlTransport;
use FleetKit\Pace\HttpTransport;
use FleetKit\Pace\KeychainCredentials;
use FleetKit\Pace\ReadFailed;
use FleetKit\Pace\UnsupportedPlatform;
use FleetKit\Pace\UnsupportedReader;

// The macOS keychain and the live endpoint are not run in these tests: a recorded credential and a
// recorded response stand in for them, so the keychain path itself is unexercised on every platform.

it('reads a recorded usage response', function () {
    $usage = ClaudeUsageReader::parse(recorded('usage-claude.json'));

    expect($usage->sevenDayUsed)->toBe(46.0)
        ->and($usage->fiveHourUsed)->toBe(12.0)
        ->and($usage->sevenDayResetsAt?->format('c'))->toBe('2026-10-26T17:00:00+00:00');
});

it('refuses a response it cannot read', function (string $body) {
    ClaudeUsageReader::parse($body);
})->throws(ReadFailed::class)->with(['not json', '{}', '{"seven_day": {"utilization": "n/a"}}']);

it('sends the recorded token as a bearer header and reads the recorded reply', function () {
    $store = new class implements CredentialStore
    {
        public function token(): string
        {
            return KeychainCredentials::tokenFrom(recorded('keychain-claude.json'));
        }
    };
    $http = new class implements HttpTransport
    {
        public array $seen = [];

        public function get(string $url, array $headers): string
        {
            $this->seen = [$url, $headers];

            return recorded('usage-claude.json');
        }
    };

    $usage = (new ClaudeUsageReader($store, $http))->read(account());

    expect($usage->sevenDayUsed)->toBe(46.0)
        ->and($http->seen[0])->toBe(ClaudeUsageReader::URL)
        ->and($http->seen[1])->toContain('Authorization: Bearer test-token-not-a-credential');
});

it('pulls the access token out of a stored credential', function () {
    expect(KeychainCredentials::tokenFrom(recorded('keychain-claude.json')))->toBe('test-token-not-a-credential');
    expect(fn () => KeychainCredentials::tokenFrom('{"other": 1}'))->toThrow(ReadFailed::class);
});

it('refuses to build a curl config from a token or url holding control characters', function (string $url, string $header) {
    (new CurlTransport)->get($url, [$header]);
})->throws(ReadFailed::class, 'control characters')->with([
    'newline in the token' => ['https://example.test/usage', "Authorization: Bearer abc\nurl = \"https://evil.test\""],
    'newline in the url' => ["https://example.test/\nheader = x", 'X: y'],
]);

it('answers "unsupported on this platform" where the credential location is unmeasured', function () {
    (new UnsupportedReader)->read(account());
})->throws(UnsupportedPlatform::class, 'unsupported on this platform');
