<?php

declare(strict_types=1);

// Kirby core comes from `composer install` (getkirby/composer-installer puts it in kirby/).
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../kirby/bootstrap.php';

// Registers the plugin with Kirby::plugin() before any App exists, so every App the tests
// build picks it up without a plugins directory.
require __DIR__ . '/../index.php';
