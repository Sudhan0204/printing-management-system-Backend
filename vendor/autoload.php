<?php

/**
 * Local Composer-compatible autoloader for PhpSpreadsheet and dependencies.
 * Packages live under backend/packages (installed offline due to SSL restrictions).
 */

spl_autoload_register(function ($class) {
    $prefixes = [
        "PhpOffice\\PhpSpreadsheet\\" => dirname(__DIR__) . "/packages/phpoffice-phpspreadsheet/src/PhpSpreadsheet/",
        "Matrix\\" => dirname(__DIR__) . "/packages/markbaker-matrix/classes/src/",
        "Complex\\" => dirname(__DIR__) . "/packages/markbaker-complex/classes/src/",
        "ZipStream\\" => dirname(__DIR__) . "/packages/maennchen-zipstream/src/",
        "MyCLabs\\Enum\\" => dirname(__DIR__) . "/packages/myclabs-php-enum/src/",
        "Composer\\Pcre\\" => dirname(__DIR__) . "/packages/composer-pcre/src/",
        "Psr\\SimpleCache\\" => dirname(__DIR__) . "/packages/psr-simple-cache/src/",
        "Psr\\Http\\Message\\" => dirname(__DIR__) . "/packages/psr-http-message/src/",
        "Psr\\Http\\Client\\" => dirname(__DIR__) . "/packages/psr-http-client/src/",
        "Psr\\Http\\Factory\\" => dirname(__DIR__) . "/packages/psr-http-factory/src/",
        "Symfony\\Polyfill\\Mbstring\\" => dirname(__DIR__) . "/packages/symfony-polyfill-mbstring/",
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }

        $relative = str_replace("\\", "/", substr($class, $len)) . ".php";
        $file = $baseDir . $relative;

        if (is_file($file)) {
            require $file;
            return;
        }
    }

    // HTMLPurifier uses PSR-0 under library/
    if (strncmp($class, "HTMLPurifier", 12) === 0) {
        $file = dirname(__DIR__) . "/packages/ezyang-htmlpurifier/library/" . str_replace("_", "/", $class) . ".php";
        if (is_file($file)) {
            require $file;
        }
    }
});

$polyfillBootstrap = dirname(__DIR__) . "/packages/symfony-polyfill-mbstring/bootstrap.php";
if (is_file($polyfillBootstrap)) {
    require_once $polyfillBootstrap;
}

$htmlPurifierComposer = dirname(__DIR__) . "/packages/ezyang-htmlpurifier/library/HTMLPurifier.composer.php";
if (is_file($htmlPurifierComposer)) {
    require_once $htmlPurifierComposer;
}
