<?php

// Included only after run-kian-disposable.php verifies the cluster and creates $fixture.
use Illuminate\Support\Facades\DB;

$config = config('database.connections.pgsql');
$config['database'] = $fixture;
config(['database.connections.kian_lock_fixture' => $config]);
$db = DB::connection('kian_lock_fixture');
if ($db->selectOne('SELECT current_database() AS db')->db !== $fixture) {
    throw new RuntimeException('Lock fixture identity mismatch.');
}
$db->statement('CREATE TABLE kian_submissions (id BIGINT PRIMARY KEY, tax_case_id BIGINT NOT NULL, stage_id SMALLINT NULL)');
$db->statement('INSERT INTO kian_submissions VALUES (1, 1, 4)');
$peer = new PDO('pgsql:host=127.0.0.1;port=55439;dbname='.$fixture, 'portax_test', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$peer->exec("SET lock_timeout = '200ms'");
$migration = require database_path('migrations/2026_10_29_000002_reconcile_kian_case_stage_uniqueness.php');
(new ReflectionProperty($migration, 'connection'))->setValue($migration, 'kian_lock_fixture');
$checks = 0;
try {
    $peer->beginTransaction();
    $peer->exec('LOCK TABLE kian_submissions IN SHARE MODE');
    $started = microtime(true);
    try {
        $migration->up();
        throw new RuntimeException('Expected migration lock timeout.');
    } catch (Illuminate\Database\QueryException $e) {
        if ($e->getCode() !== '55P03' || microtime(true) - $started > 5) {
            throw $e;
        }
        $checks++;
    }
    $peer->rollBack();
    if (count($db->getSchemaBuilder()->getIndexes('kian_submissions')) !== 1 || $db->table('kian_submissions')->count() !== 1) {
        throw new RuntimeException('Failed lock attempt changed schema/data.');
    }
    $checks++;
    $db->beginTransaction();
    $migration->up();
    try {
        $peer->exec('INSERT INTO kian_submissions VALUES (2, 1, 4)');
        throw new RuntimeException('Concurrent writer bypassed migration lock.');
    } catch (PDOException $e) {
        if ($e->getCode() !== '55P03') {
            throw $e;
        }
        $checks++;
    }
    $db->commit();
    try {
        $peer->exec('INSERT INTO kian_submissions VALUES (2, 1, 4)');
        throw new RuntimeException('Duplicate bypassed published uniqueness.');
    } catch (PDOException $e) {
        if ($e->getCode() !== '23505') {
            throw $e;
        }
        $checks++;
    }
    $peer->exec('INSERT INTO kian_submissions VALUES (2, 1, 7)');
    if ($db->table('kian_submissions')->count() !== 2) {
        throw new RuntimeException('Different stage was not preserved.');
    }
    $checks++;
    echo "PASS: {$checks} two-connection checks (bounded lock refusal, atomic failure, writer exclusion, duplicate rejection, distinct stages).".PHP_EOL;
} finally {
    if ($peer->inTransaction()) {
        $peer->rollBack();
    }
    while ($db->transactionLevel() > 0) {
        $db->rollBack();
    }
    DB::purge('kian_lock_fixture');
}
