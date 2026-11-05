<?php

declare(strict_types=1);

use FleetKit\ConfigLocator;
use FleetKit\Pace\ConfigError;

function locatorDir(): string
{
    $dir = strtr(sys_get_temp_dir(), [chr(92) => '/']).'/fleet-kit-locator-'.bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

it('reads the seats file from --config, then the environment, then the remembered path, then the current directory', function () {
    $dir = locatorDir();
    file_put_contents($dir.'/seats.toml', '');
    $locator = new ConfigLocator($dir.'/home/seats-path');
    $locator->remember($dir.'/seats.toml');

    $flag = $locator->resolve('flag.toml', 'env.toml');
    $env = $locator->resolve(null, 'env.toml');
    $remembered = $locator->resolve(null, null);
    $locator->forget();
    $default = $locator->resolve(null, null);

    expect([$flag['path'], $flag['source']])->toBe(['flag.toml', '--config'])
        ->and([$env['path'], $env['source']])->toBe(['env.toml', 'FLEET_KIT_CONFIG'])
        ->and($remembered['path'])->toBe(realpath($dir.'/seats.toml'))
        ->and($remembered['source'])->toContain('config set')->toContain($dir.'/home/seats-path')
        ->and([$default['path'], $default['source']])->toBe(['seats.toml', 'the default, seats.toml in the current directory']);
});

it('treats empty values as not given', function () {
    $locator = new ConfigLocator(null);

    expect($locator->resolve('', '')['path'])->toBe('seats.toml');
});

it('remembers an absolute path, whatever it was given as', function () {
    $dir = locatorDir();
    file_put_contents($dir.'/seats.toml', '');
    $locator = new ConfigLocator($dir.'/a/b/seats-path');
    $cwd = getcwd();
    chdir($dir);
    try {
        $saved = $locator->remember('seats.toml');
    } finally {
        chdir($cwd);
    }

    expect($saved)->toBe(realpath($dir.'/seats.toml'))
        ->and($locator->remembered())->toBe($saved);
});

it('refuses to remember a file that is not there, or to write where there is nowhere to write', function () {
    $dir = locatorDir();
    file_put_contents($dir.'/seats.toml', '');

    expect(fn () => (new ConfigLocator($dir.'/p'))->remember($dir.'/nope.toml'))->toThrow(ConfigError::class, 'seats file not found')
        ->and(fn () => (new ConfigLocator(null))->remember($dir.'/seats.toml'))->toThrow(ConfigError::class, 'FLEET_KIT_CONFIG');
});

it('forgets, and says whether there was anything to forget', function () {
    $dir = locatorDir();
    file_put_contents($dir.'/seats.toml', '');
    $locator = new ConfigLocator($dir.'/p');

    expect($locator->forget())->toBeFalse();
    $locator->remember($dir.'/seats.toml');
    expect($locator->forget())->toBeTrue()->and($locator->remembered())->toBeNull();
});

it('keeps the remembered path in a per-user file named for the platform', function () {
    expect(ConfigLocator::userFile())->toEndWith('fleet-kit/seats-path');
});

it('names the one-time command when the seats file is missing', function () {
    $error = ConfigLocator::missing('/x/seats.toml', 'the default, seats.toml in the current directory');

    expect($error->getMessage())->toContain('seats file not found: /x/seats.toml')->toContain('seat-stage config set')->toContain('FLEET_KIT_CONFIG');
});
