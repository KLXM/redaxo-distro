<?php

// Paket und Commit je Abhängigkeit aus der composer.lock – zum Vergleichen vor und nach einem Update.
$lock = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
foreach ($lock['packages'] as $package) {
    echo $package['name'], ' ', $package['version'], ' ', substr($package['dist']['reference'] ?? $package['source']['reference'] ?? '', 0, 12), "\n";
}
