<?php

declare(strict_types=1);

// Finds Composer's autoloader for the tools in this directory, wherever they were started from:
//  - Composer's bin proxy, which names the project's autoloader in $GLOBALS['_composer_autoload_path'];
//  - a checkout, where vendor/ sits beside bin/;
//  - an installed package, vendor/robot-council/fleet-kit/bin, where the autoloader is three levels up.
// A package linked from a path repository is read through its link, so only the first form finds it.
foreach ([$GLOBALS['_composer_autoload_path'] ?? null, __DIR__.'/../vendor/autoload.php', __DIR__.'/../../../autoload.php'] as $autoload) {
    if (is_string($autoload) && is_file($autoload)) {
        require $autoload;

        return;
    }
}

fwrite(STDERR, "fleet-kit: Composer's autoloader was not found. Run `composer install` in a checkout, or install the package with Composer.\n");
exit(1);
