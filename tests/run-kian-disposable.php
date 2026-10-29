<?php

// Run from the repository root. Never reads the application's database credentials.
require __DIR__.'/../vendor/autoload.php';

$driver = $argv[1] ?? 'sqlite';
if (! in_array($driver, ['sqlite', 'pgsql'], true)) {
    throw new RuntimeException('Only sqlite and pgsql disposable targets are supported.');
}
$settings = [
    'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => sys_get_temp_dir().'/portax_no_config_'.bin2hex(random_bytes(8)).'.php',
    'DB_CONNECTION' => $driver, 'DB_URL' => '', 'DB_DATABASE' => ':memory:',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
];
if ($driver === 'pgsql') {
    $database = getenv('PORTAX_TEST_PG_DATABASE');
    $directory = getenv('PORTAX_TEST_PG_DIRECTORY');
    if (! $database || ! preg_match('/^kian_3c1_test_[a-z0-9_]+$/D', $database) || ! $directory) {
        throw new RuntimeException('Supply PORTAX_TEST_PG_DATABASE (kian_3c1_test_*) and PORTAX_TEST_PG_DIRECTORY for the isolated cluster.');
    }
    $settings += ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '55439', 'DB_USERNAME' => 'portax_test', 'DB_PASSWORD' => ''];
    $settings['DB_DATABASE'] = $database;
    $pdo = new PDO('pgsql:host=127.0.0.1;port=55439;dbname='.$database, 'portax_test', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pdo->query("SELECT current_database() AS db, current_user AS usr, inet_server_port() AS port, current_setting('data_directory') AS directory")->fetch(PDO::FETCH_ASSOC);
    if ($identity['db'] !== $database || $identity['usr'] !== 'portax_test' || (int) $identity['port'] !== 55439
        || str_replace('\\', '/', $identity['directory']) !== str_replace('\\', '/', $directory)) {
        throw new RuntimeException('Disposable PostgreSQL identity mismatch.');
    }
    echo 'Verified isolated PostgreSQL: '.json_encode($identity).PHP_EOL;
}
foreach ($settings as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = $app->make('db')->connection();
if (! $app->environment('testing') || $app->configurationIsCached() || config('database.connections.'.$driver.'.url')
    || $connection->getDriverName() !== $driver || $connection->getDatabaseName() !== $settings['DB_DATABASE']) {
    throw new RuntimeException('Unsafe resolved Laravel test configuration.');
}
if ($driver === 'sqlite') {
    $identity = $connection->select('PRAGMA database_list');
    if (count($identity) !== 1 || $identity[0]->name !== 'main' || $identity[0]->file !== '') {
        throw new RuntimeException('SQLite must resolve to a new in-memory database.');
    }
    echo 'Verified SQLite :memory:; testing; no cached config or DB_URL.'.PHP_EOL;
}
if (($argv[2] ?? '') === 'migrate') {
    $code = Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true], new Symfony\Component\Console\Output\ConsoleOutput);
    echo json_encode($connection->getSchemaBuilder()->getIndexes('kian_submissions'), JSON_PRETTY_PRINT).PHP_EOL;
    echo json_encode(array_values(array_filter($connection->getSchemaBuilder()->getColumns('kian_submissions'), fn ($column) => in_array($column['name'], ['tax_case_id', 'stage_id'], true))), JSON_PRETTY_PRINT).PHP_EOL;
    exit($code);
}
if ($driver === 'pgsql') {
    $fixture = 'kian_3c1_test_fixture_'.bin2hex(random_bytes(8));
    $pdo->exec('CREATE DATABASE "'.$fixture.'"');
    putenv('PORTAX_KIAN_FIXTURE_DATABASE='.$fixture);
}
if (($argv[2] ?? '') === 'concurrency') {
    if ($driver !== 'pgsql') {
        throw new RuntimeException('The two-connection fixture requires isolated PostgreSQL.');
    }
    require __DIR__.'/support/kian-pg-lock-check.php';
    exit(0);
}
// The PHPUnit application inherits exactly the verified environment above.
$args = array_slice($argv, 2);
if (($args[0] ?? '') === 'artisan-test') {
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../artisan').' test --compact', $code);
    exit($code);
}
if (! $args) {
    $args = ['--filter=KianMultiStageConstraintTest|DecisionPointContractNormalizationTest|EntityAuthorizationHardeningTest'];
}
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../vendor/phpunit/phpunit/phpunit').' '.implode(' ', array_map('escapeshellarg', $args)), $code);
exit($code);
