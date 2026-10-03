<?php

// Checked out on its own (its own vendor/), or installed in an application's
// vendor/omnibase/agenda (a path repository's symlink: /srv/omnibase/agenda in
// the containers) - the application's autoloader then, which does not know
// this bundle's autoload-dev: the test namespace is registered here.
foreach ([
    __DIR__.'/../vendor/autoload.php',
    getcwd().'/vendor/autoload.php',
    __DIR__.'/../../../autoload.php',
] as $autoload) {
    if (is_file($autoload)) {
        $loader = require $autoload;
        if ($loader instanceof \Composer\Autoload\ClassLoader) {
            $loader->addPsr4('Base\\Agenda\\Tests\\', __DIR__);
        }

        return;
    }
}

throw new RuntimeException('No composer autoloader found.');
