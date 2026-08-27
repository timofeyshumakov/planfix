<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';

if (is_readable($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

require_once $root . '/crest.php';
