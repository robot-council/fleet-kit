<?php

declare(strict_types=1);

use FleetKit\Pace\Account;
use FleetKit\Pace\Config;

/**
 * The fixture week: reset Mon 12:00 America/Chicago, so the window runs Mon 2026-10-19 12:00 to
 * Mon 2026-10-26 12:00 (CDT, no DST change inside it). Over working hours (Mon-Fri 09:00-17:00)
 * that is exactly 40 working hours: Mon 5h, Tue-Fri 8h each, the next Mon 3h.
 */
function account(string $hours = 'working', string $extra = ''): Account
{
    return Config::parse(accountToml($hours, $extra))->accounts[0];
}

function accountToml(string $hours = 'working', string $extra = '', string $name = 'main'): string
{
    return <<<TOML
        [[account]]
        name = "{$name}"
        harness = "claude"
        machine = "office"
        timezone = "America/Chicago"
        reset = "Mon 12:00"
        hours = "{$hours}"
        {$extra}
        TOML;
}

function recorded(string $name): string
{
    return (string) file_get_contents(__DIR__.'/Fixtures/'.$name);
}
