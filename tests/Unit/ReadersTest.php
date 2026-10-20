<?php

declare(strict_types=1);

use FleetKit\Pace\ClaudeUsageReader;
use FleetKit\Pace\CredentialStore;
use FleetKit\Pace\CurlTransport;
use FleetKit\Pace\FileCredentials;
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

// The Windows credential file (fleet-kit#5) is replaced here by a synthetic file with fake values in the
// shape measured on Windows: key names only, never the real file's contents.

function credentialFile(string $contents): string
{
    $path = sys_get_temp_dir().'/seat-pace-credentials-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($path, $contents);

    return $path;
}

it('reads the access token from a credential file, and only that', function () {
    $token = (new FileCredentials(credentialFile(recorded('credentials-claude-windows.json'))))->token();

    expect($token)->toBe('test-token-not-a-credential');
});

it('says where the credential file is missing, and reads nothing', function () {
    $path = sys_get_temp_dir().'/seat-pace-no-such-file.json';

    expect(fn () => (new FileCredentials($path))->token())->toThrow(ReadFailed::class, "no credential file at {$path}");
});

it('never puts the file\'s contents in an error', function (string $contents) {
    try {
        (new FileCredentials(credentialFile($contents)))->token();
        $message = 'no error';
    } catch (ReadFailed $e) {
        $message = $e->getMessage();
    }

    expect($message)->not->toBe('no error')
        ->not->toContain('fake-mcp-token-not-real')->not->toContain('fake-mcp-client-secret-not-real')
        ->not->toContain('fake-refresh-not-real')->not->toContain('test-token-not-a-credential');
})->with([
    'a usable-looking token beside an empty access token' => ['{"claudeAiOauth": {"accessToken": ""}, "other": "test-token-not-a-credential"}'],
    'a non-string access token with the real-looking one elsewhere' => ['{"claudeAiOauth": {"accessToken": 12345, "refreshToken": "test-token-not-a-credential"}}'],
    'no claudeAiOauth, other secrets present' => ['{"mcpOAuth": {"s": {"accessToken": "fake-mcp-token-not-real"}}, "mcpOAuthClientConfig": {"s": {"clientSecret": "fake-mcp-client-secret-not-real"}}}'],
    'access token missing' => ['{"claudeAiOauth": {"refreshToken": "fake-refresh-not-real"}}'],
    'not json' => ['fake-refresh-not-real'],
]);

it('uses CLAUDE_CONFIG_DIR when set, else the user profile\'s .claude', function () {
    $dir = sys_get_temp_dir().'/seat-pace-config-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/.credentials.json', recorded('credentials-claude-windows.json'));

    $before = getenv('CLAUDE_CONFIG_DIR');
    putenv("CLAUDE_CONFIG_DIR={$dir}");
    try {
        expect(FileCredentials::default()->token())->toBe('test-token-not-a-credential');
    } finally {
        $before === false ? putenv('CLAUDE_CONFIG_DIR') : putenv("CLAUDE_CONFIG_DIR={$before}");
    }
});

it('says so, rather than guess a path, when no directory and no home is known', function () {
    $saved = [getenv('CLAUDE_CONFIG_DIR'), getenv('USERPROFILE'), getenv('HOME')];
    putenv('CLAUDE_CONFIG_DIR');
    putenv('USERPROFILE');
    putenv('HOME');
    try {
        $reader = FileCredentials::default();
    } finally {
        foreach (['CLAUDE_CONFIG_DIR', 'USERPROFILE', 'HOME'] as $i => $name) {
            $saved[$i] === false ? putenv($name) : putenv("{$name}={$saved[$i]}");
        }
    }

    expect(fn () => $reader->token())->toThrow(ReadFailed::class, 'neither CLAUDE_CONFIG_DIR nor a home directory');
});
