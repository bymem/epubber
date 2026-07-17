<?php

$projectRoot = __DIR__;

require $projectRoot . '/src/Prompt.php';
require $projectRoot . '/src/Config.php';
require $projectRoot . '/src/EpubPackager.php';

$config = new Config($projectRoot, $projectRoot . '/.env');

try {
    $packager = new EpubPackager($config, $projectRoot);
    $packager->run();
} catch (RuntimeException $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
