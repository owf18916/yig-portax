<?php

// SEC01 wrapper: fresh storage and SQLite only; never accepts a database target.
require __DIR__.'/../vendor/autoload.php';

$nativeRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'portax_auth_sec01_'.bin2hex(random_bytes(10));
$root = str_replace('\\', '/', $nativeRoot);
foreach (['app/private', 'app/public', 'app/nas', 'framework/testing/disks', 'framework/views', 'framework/sessions', 'framework/cache', 'logs'] as $directory) {
    if (! mkdir($root.'/'.$directory, 0777, true)) {
        throw new RuntimeException('Could not create SEC01 disposable storage.');
    }
}
$settings = [
    'APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_PREVIOUS_KEYS' => '',
    'APP_CONFIG_CACHE' => $root.'/config.php', 'LARAVEL_STORAGE_PATH' => $nativeRoot,
    'DB_CONNECTION' => 'sqlite', 'DB_URL' => '', 'DB_DATABASE' => ':memory:',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null',
    'FILESYSTEM_DISK' => 'local', 'NAS_PATH' => $root.'/app/nas', 'VIEW_COMPILED_PATH' => $root.'/framework/views',
    'LOG_CHANNEL' => '"null"', 'LOG_LEVEL' => 'debug', 'LOG_DEPRECATIONS_CHANNEL' => '"null"',
];
// Laravel treats a Windows drive path as relative for cache environment overrides.
foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_EVENTS_CACHE'] as $key) {
    $path = $root.'/'.$key.'.php';
    $settings[$key] = preg_replace('/^[A-Za-z]:/', '', str_replace('\\', '/', $path));
}
foreach ($settings as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$require = static function (bool $safe): void {
    if (! $safe) {
        fwrite(STDERR, 'SEC01 containment preflight failed at wrapper line '.debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['line'].'; tests must not run.'.PHP_EOL);
        exit(1);
    }
};
$require($app->environment('testing') && ! $app->configurationIsCached());
$require(config('database.default') === 'sqlite' && ! config('database.connections.sqlite.url'));
$db = $app['db']->connection();
$require($db->getDriverName() === 'sqlite' && $db->getDatabaseName() === ':memory:');
$identity = $db->select('PRAGMA database_list');
$require(count($identity) === 1 && $identity[0]->name === 'main' && $identity[0]->file === '');
foreach (['cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array',
    'queue.default' => 'sync', 'broadcasting.default' => null, 'filesystems.default' => 'local', 'logging.default' => 'null'] as $key => $value) {
    $require(config($key) === $value);
}
$require(config('mail.mailers.array.transport') === 'array');
$require(config('queue.connections.sync.driver') === 'sync');
$within = static fn ($path) => str_starts_with(str_replace('\\', '/', $path), $root.'/');
$require(str_replace('\\', '/', storage_path()) === $root);
foreach (['local', 'public', 'nas'] as $disk) {
    $require(config("filesystems.disks.$disk.driver") === 'local' && $within(config("filesystems.disks.$disk.root")));
}
foreach (['session.files', 'view.compiled', 'excel.temporary_files.local_path', 'logging.channels.emergency.path'] as $key) {
    $require($within(config($key)));
}
$require(config('excel.temporary_files.remote_disk') === null);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Mail::fake();
Illuminate\Support\Facades\Queue::fake();
echo 'SEC01 preflight PASS: SQLite :memory: (empty main file); array cache/session/mail; sync queue faked in privacy/parity tests; null broadcast/log fallback; local-only storage '.$root.PHP_EOL;

$args = array_slice($argv, 1);
if (! $args) {
    $args = ['--filter=TaxCaseExportPrivacyTest|TaxCaseExportFilterParityTest|AuthenticationSessionLifecycleTest|SessionApiCsrfTest::test_real_verifier_login_rotation_precedence_logout_and_expiry|EntityAuthorizationHardeningTest::test_affiliate_cannot_reach_cross_entity_core_or_workflow_endpoints'];
}
// Match the established wrappers: PHPUnit starts without preflight error handlers.
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../vendor/phpunit/phpunit/phpunit').' --do-not-cache-result '.implode(' ', array_map('escapeshellarg', $args)), $code);
exit($code);
