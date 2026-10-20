<?php

declare(strict_types=1);

namespace FleetKit\Pace;

/** The seats file, reduced to what seat-pace reads: its accounts and where the log goes. */
final readonly class Config
{
    /** @param  list<Account>  $accounts */
    public function __construct(public array $accounts, public string $log) {}

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigError("seats file not found: {$path}");
        }

        return self::parse((string) file_get_contents($path), dirname($path));
    }

    public static function parse(string $source, string $base = '.'): self
    {
        $data = Toml::parse($source);

        $accounts = [];
        foreach ($data['account'] ?? [] as $row) {
            $account = Account::fromArray($row);
            if (isset($accounts[$account->name])) {
                throw new ConfigError("account \"{$account->name}\" is listed twice");
            }
            $accounts[$account->name] = $account;
        }
        if ($accounts === []) {
            throw new ConfigError('seats file has no [[account]]');
        }

        $log = is_string($data['log'] ?? null) ? $data['log'] : 'seat-pace.tsv';
        if (! preg_match('#^([A-Za-z]:)?[\\\\/]#', $log)) {
            $log = rtrim($base, '/\\').DIRECTORY_SEPARATOR.$log;
        }

        return new self(array_values($accounts), $log);
    }

    public function account(?string $name): Account
    {
        if ($name === null) {
            if (count($this->accounts) !== 1) {
                throw new ConfigError('name an account: '.implode(', ', array_map(fn (Account $a) => $a->name, $this->accounts)));
            }

            return $this->accounts[0];
        }
        foreach ($this->accounts as $account) {
            if ($account->name === $name) {
                return $account;
            }
        }

        throw new ConfigError("no account named \"{$name}\"");
    }
}
