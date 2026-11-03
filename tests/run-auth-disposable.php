<?php

// Add fresh task-owned filesystem isolation to the existing DB preflight.
// No application .env database connection is used by run-kian-disposable.php.
$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'portax_auth_'.bin2hex(random_bytes(10));
foreach (['app/private', 'app/public', 'app/nas', 'framework/testing/disks', 'framework/views', 'framework/sessions', 'framework/cache', 'logs'] as $directory) {
    if (! mkdir($root.DIRECTORY_SEPARATOR.$directory, 0777, true) && ! is_dir($root.DIRECTORY_SEPARATOR.$directory)) {
        throw new RuntimeException('Could not create isolated test storage.');
    }
}
foreach (['LARAVEL_STORAGE_PATH' => $root, 'FILESYSTEM_DISK' => 'local', 'NAS_PATH' => $root.'/app/nas', 'VIEW_COMPILED_PATH' => $root.'/framework/views'] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
echo 'Verified fresh task-owned temporary storage; fake disks are confined to it.'.PHP_EOL;
require __DIR__.'/run-kian-disposable.php';
