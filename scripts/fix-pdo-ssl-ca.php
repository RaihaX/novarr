<?php

/**
 * Replace PDO::MYSQL_ATTR_SSL_CA references in vendor files with a
 * version-guarded expression so PHP 8.5+ uses the new Pdo\Mysql constant.
 *
 * Why: nunomaduro/collision ships scripts/fix-pdo-constant.php for this same
 * deprecation, but its relative __DIR__ path resolves wrong for end-user
 * installs, so it never actually runs. Wired into composer post-autoload-dump.
 */

if (PHP_VERSION_ID < 80500) {
    exit(0);
}

$root = dirname(__DIR__);

$targets = [
    $root.'/vendor/laravel/framework/config/database.php',
];

$search = 'PDO::MYSQL_ATTR_SSL_CA';
$replace = '(PHP_VERSION_ID >= 80500 ? Pdo\\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA)';

foreach ($targets as $file) {
    if (! file_exists($file)) {
        continue;
    }

    $contents = file_get_contents($file);

    if (strpos($contents, $replace) !== false) {
        continue;
    }

    if (strpos($contents, $search) === false) {
        continue;
    }

    file_put_contents($file, str_replace($search, $replace, $contents));
}
