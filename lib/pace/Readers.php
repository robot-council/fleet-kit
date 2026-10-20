<?php

declare(strict_types=1);

namespace FleetKit\Pace;

use DateTimeImmutable;
use JsonException;

/** Reads an account's usage. The only part of seat-pace that touches a credential or the network. */
interface UsageReader
{
    /** @throws ReadFailed */
    public function read(Account $account): Usage;
}

/** Returns the harness's own credential as the token alone; it never leaves the reader. */
interface CredentialStore
{
    /** @throws ReadFailed */
    public function token(): string;
}

interface HttpTransport
{
    /**
     * @param  list<string>  $headers  sent without appearing on a command line
     *
     * @throws ReadFailed
     */
    public function get(string $url, array $headers): string;
}

/**
 * The Claude adapter: the OAuth usage endpoint, with the token read from the harness's own
 * credential store. The endpoint and the response shape are unmeasured against a live account
 * in this repository; the tests replay recorded responses.
 */
final class ClaudeUsageReader implements UsageReader
{
    public const URL = 'https://api.anthropic.com/api/oauth/usage';

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly HttpTransport $http,
    ) {}

    public function read(Account $account): Usage
    {
        $body = $this->http->get(self::URL, [
            'Authorization: Bearer '.$this->credentials->token(),
            'anthropic-beta: oauth-2025-04-20',
        ]);

        return self::parse($body);
    }

    public static function parse(string $body): Usage
    {
        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ReadFailed('usage response is not JSON');
        }

        $week = $data['seven_day'] ?? null;
        $session = $data['five_hour'] ?? null;
        if (! is_array($week) || ! is_numeric($week['utilization'] ?? null)) {
            throw new ReadFailed('usage response has no seven_day utilization');
        }

        return new Usage(
            fiveHourUsed: is_array($session) && is_numeric($session['utilization'] ?? null) ? (float) $session['utilization'] : 0.0,
            fiveHourResetsAt: self::time($session['resets_at'] ?? null),
            sevenDayUsed: (float) $week['utilization'],
            sevenDayResetsAt: self::time($week['resets_at'] ?? null),
        );
    }

    private static function time(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}

/** The macOS keychain. Untested here: recorded credentials stand in for it. */
final class KeychainCredentials implements CredentialStore
{
    public function __construct(private readonly string $service = 'Claude Code-credentials') {}

    public function token(): string
    {
        $process = proc_open(['security', 'find-generic-password', '-s', $this->service, '-w'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new ReadFailed('could not run the keychain');
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new ReadFailed('the keychain has no credential for the harness');
        }

        return self::tokenFrom($out);
    }

    public static function tokenFrom(string $stored): string
    {
        $data = json_decode(trim($stored), true);
        $token = is_array($data) ? ($data['claudeAiOauth']['accessToken'] ?? null) : null;
        if (! is_string($token) || $token === '') {
            throw new ReadFailed('the stored credential has no access token');
        }

        return $token;
    }
}

/** curl, with the headers (and so the token) passed on stdin so they stay off the command line. */
final class CurlTransport implements HttpTransport
{
    public function get(string $url, array $headers): string
    {
        foreach ([$url, ...$headers] as $line) {
            if (preg_match('/[\x00-\x1f\x7f]/', $line)) {
                throw new ReadFailed('refusing a request line with control characters');
            }
        }

        $config = 'url = "'.addcslashes($url, '"\\')."\"\n";
        foreach ($headers as $header) {
            $config .= 'header = "'.addcslashes($header, '"\\')."\"\n";
        }

        $process = proc_open(['curl', '--silent', '--show-error', '--fail', '--max-time', '20', '--config', '-'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new ReadFailed('could not run curl');
        }
        fwrite($pipes[0], $config);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new ReadFailed('usage request failed'.($err === '' ? '' : ': '.$err));
        }

        return $out;
    }
}

/** Answers "unsupported on this platform" wherever the credential location is unmeasured. */
final class UnsupportedReader implements UsageReader
{
    public function read(Account $account): Usage
    {
        throw new UnsupportedPlatform;
    }
}
