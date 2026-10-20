<?php

declare(strict_types=1);

namespace FleetKit\Pace;

use RuntimeException;

/** A problem with the seats file or the command line: exit status 2. */
final class ConfigError extends RuntimeException {}

/** A usage figure could not be read: exit status 1. */
class ReadFailed extends RuntimeException {}

/** The credential location is not measured on this platform: exit status 1. */
final class UnsupportedPlatform extends ReadFailed
{
    public function __construct()
    {
        parent::__construct('unsupported on this platform');
    }
}
