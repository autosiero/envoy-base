<?php

// Determine root path, if not set
if (empty($codeRoot)) {
    if (!file_exists(__DIR__ . '/.git/')) {
        \chdir(__DIR__ . '/../');
    }
    $codeRoot = trim(`git rev-parse --show-toplevel 2>/dev/null`);
}

// Change to code root dir
\chdir($codeRoot);

// Determine file locations
$environmentConfig = $codeRoot . '/.envoy/environments.json';
$storageConfig = $codeRoot . '/.envoy/storages.json';

return [
    'root' => $codeRoot,
    'config' => [
        'environment' => $environmentConfig,
        'storage' => $storageConfig,
    ]
];
