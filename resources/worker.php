<?php

/**
 * Worker entrypoint for projects. This TOML block adds the extension:
 *
 *     [extension-hosts.phpunit]
 *     command = ["php", "-d", "xdebug.mode=off", "-d", "pcov.enabled=0", "vendor/friendsofmago/mago-phpunit/resources/worker.php"]
 *
 * `-d xdebug.mode=off` turns off Xdebug, and `-d pcov.enabled=0` turns off
 * the pcov coverage driver. Either one slows the worker down while it's on.
 * PHP ignores each option when its extension isn't installed.
 */

declare(strict_types=1);

use Mago\Sdk\Worker;
use MagoPHPUnit\PHPUnitExtension;

// stdout is Mago's protocol stream. The SDK only redirects stray output
// after the plugins are registered. Before that, a PHP warning would be
// printed into the stream, since the CLI prints errors by default.
// @mago-expect lint:no-ini-set
ini_set('display_errors', value: 'stderr');

// This buffer sends text printed by a project's autoloader to stderr too,
// such as an `autoload.files` bootstrap that echoes. The protocol frames
// don't go through the buffer, since the SDK writes them to the STDOUT
// stream directly.
ob_start(static function (string $output): string {
    if ($output !== '') {
        fwrite(STDERR, $output);
    }

    return '';
}, chunk_size: 1);

(static function (string $script): void {
    $cwd = getcwd();
    $candidates = [
        // The package is a dependency: vendor/friendsofmago/mago-phpunit/resources.
        dirname(__DIR__, levels: 3) . '/autoload.php',
        // The package is a symlinked path repository. __DIR__ resolves to the
        // clone, but the worker path on the command line keeps the symlink,
        // so it still points into the project's vendor directory.
        dirname($script, levels: 4) . '/autoload.php',
        // The command points at a clone of this package, and the project in
        // the worker's cwd has it installed as a symlinked path repository.
        ($cwd === false ? '.' : $cwd) . '/vendor/autoload.php',
        // The worker runs from a clone of this package.
        dirname(__DIR__) . '/vendor/autoload.php',
    ];

    // A project can have another copy of this package, such as a release in
    // its vendor directory while the command points at a clone. An
    // autoloader is only used when it maps the package's namespace to this
    // copy. A class loaded from the other copy can't be unloaded.
    $own = realpath(dirname(__DIR__) . '/src');
    $supplies = static function (string $autoloader) use ($own): bool {
        $psr4 = dirname($autoloader) . '/composer/autoload_psr4.php';
        /** @var array<string, list<string>> $map */
        $map = is_file($psr4) ? (require $psr4) : [];

        return in_array($own, array_map(realpath(...), $map['MagoPHPUnit\\'] ?? []), strict: true);
    };

    foreach ($candidates as $autoloader) {
        if (!is_file($autoloader) || !$supplies($autoloader)) {
            continue;
        }

        require $autoloader;

        // An autoloader can map this package but come from an install
        // without the SDK, such as a copy that skipped dev dependencies.
        if (!class_exists(Worker::class) || !class_exists(PHPUnitExtension::class)) {
            continue;
        }

        (new Worker(PHPUnitExtension::create()))->run();

        return;
    }

    // stdout is the protocol stream, so the error goes to stderr.
    $message = "mago-phpunit: could not locate the Composer autoloader.\n";
    fwrite(STDERR, $message);
    exit(1);
})($argv[0]);
