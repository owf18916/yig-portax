<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KianMultiStageConstraintTest extends TestCase
{
    private $db;

    private $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $driver = DB::getDriverName();
        $config = config('database.connections.'.$driver);
        $config['url'] = null;
        if ($driver === 'sqlite') {
            $config['database'] = ':memory:';
        } else {
            $fixture = getenv('PORTAX_KIAN_FIXTURE_DATABASE');
            if ($driver !== 'pgsql' || ! $fixture || ! preg_match('/^kian_3c1_test_fixture_[a-f0-9]{16}$/D', $fixture)) {
                throw new \RuntimeException('Run PostgreSQL fixtures through tests/run-kian-disposable.php.');
            }
            $config['database'] = $fixture;
        }
        config(['database.connections.kian_fixture' => $config]);
        $this->db = DB::connection('kian_fixture');
        if ($driver === 'pgsql' && $this->db->selectOne('SELECT current_database() AS db')->db !== $config['database']) {
            throw new \RuntimeException('Fixture identity mismatch.');
        }
        $this->db->beginTransaction();
        $this->db->statement('CREATE TABLE tax_cases (id BIGINT PRIMARY KEY)');
        $this->db->statement('INSERT INTO tax_cases (id) VALUES (1), (2)');
        $this->db->statement('CREATE TABLE kian_submissions (id BIGINT PRIMARY KEY, tax_case_id BIGINT NOT NULL REFERENCES tax_cases(id), stage_id SMALLINT NULL, status VARCHAR(20) NOT NULL DEFAULT \'draft\', deleted_at TIMESTAMP NULL, CHECK (id > 0))');
        $this->db->statement('CREATE TABLE kian_documents (id BIGINT PRIMARY KEY, kian_id BIGINT REFERENCES kian_submissions(id))');
        $this->migration = require database_path('migrations/2026_10_29_000002_reconcile_kian_case_stage_uniqueness.php');
        (new \ReflectionProperty($this->migration, 'connection'))->setValue($this->migration, 'kian_fixture');
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            while ($this->db->transactionLevel() > 0) {
                $this->db->rollBack();
            }
            DB::purge('kian_fixture');
        }
        parent::tearDown();
    }

    public static function variants(): array
    {
        return ['neither' => [false, false], 'old only' => [true, false],
            'equivalent only' => [false, true], 'both' => [true, true]];
    }

    #[DataProvider('variants')]
    public function test_reconciliation_preserves_history_and_is_idempotent(bool $old, bool $pair): void
    {
        if ($old) {
            // Laravel's observed SQLite standalone index; PG constraint ownership path.
            $this->db->statement($this->db->getDriverName() === 'pgsql'
                ? 'ALTER TABLE kian_submissions ADD CONSTRAINT legacy_case UNIQUE (tax_case_id)'
                : 'CREATE UNIQUE INDEX legacy_case ON kian_submissions (tax_case_id)');
        }
        if ($pair) {
            $this->db->statement('CREATE UNIQUE INDEX equivalent_reverse_pair ON kian_submissions (stage_id, tax_case_id)');
        }
        $this->insert(1, 1, 4, 'approved', '2026-01-01 00:00:00');
        $this->insert(2, 2, null, 'submitted');
        $this->db->table('kian_documents')->insert(['id' => 1, 'kian_id' => 1]);
        $before = $this->db->table('kian_submissions')->orderBy('id')->get()->toJson();
        $this->migration->up();
        $this->migration->up();
        $this->assertSame($before, $this->db->table('kian_submissions')->orderBy('id')->get()->toJson());
        $indexes = $this->db->getSchemaBuilder()->getIndexes('kian_submissions');
        $unique = array_values(array_filter($indexes, fn ($i) => $i['unique'] && ! $i['primary']));
        $this->assertCount(1, $unique);
        $this->assertSame($pair ? 'equivalent_reverse_pair' : 'kian_submissions_tax_case_stage_unique', $unique[0]['name']);
        $this->assertSame(1, $this->db->table('kian_documents')->where('kian_id', 1)->count());
        $this->insert(3, 1, 7);
        $this->insert(4, 1, 10);
        $this->insert(5, 1, 12);
        $this->insert(6, 2, null);
        $this->assertSame([1, 3], $this->db->table('kian_submissions')->whereIn('stage_id', [4, 7])->orderBy('id')->pluck('id')->all());
        $this->assertRejected(fn () => $this->insert(7, 1, 4), 'unique');
        $this->assertSame(6, $this->db->table('kian_submissions')->count());
        $this->assertRejected(fn () => $this->insert(8, 999, 7), 'foreign key');
        $this->assertRejected(fn () => $this->insert(-1, 1, 7), 'check');
    }

    public function test_duplicate_physical_pairs_fail_without_schema_or_data_changes(): void
    {
        $this->insert(1, 1, 4, 'submitted', '2026-01-01 00:00:00');
        $this->insert(2, 1, 4);
        $before = $this->db->table('kian_submissions')->orderBy('id')->get()->toJson();
        $this->assertRefused('duplicate physical pair');
        $this->assertSame($before, $this->db->table('kian_submissions')->orderBy('id')->get()->toJson());
        $this->assertCount(1, $this->db->getSchemaBuilder()->getIndexes('kian_submissions'));
    }

    public function test_partial_pair_definition_fails_closed(): void
    {
        $this->db->statement('CREATE UNIQUE INDEX active_pair ON kian_submissions(tax_case_id, stage_id) WHERE deleted_at IS NULL');
        $this->assertRefused('incompatible unique definition');
    }

    public function test_standalone_old_index_is_removed(): void
    {
        $this->db->statement('CREATE UNIQUE INDEX standalone_case ON kian_submissions(tax_case_id)');
        $this->migration->up();
        $this->insert(1, 1, 4);
        $this->insert(2, 1, 7);
        $this->assertSame(2, $this->db->table('kian_submissions')->count());
        $this->assertRejected(fn () => $this->insert(3, 1, 4), 'unique');
    }

    public function test_engine_specific_unsafe_ownership_is_refused(): void
    {
        if ($this->db->getDriverName() === 'sqlite') {
            $this->db->statement('DROP TABLE kian_documents');
            $this->db->statement('DROP TABLE kian_submissions');
            $this->db->statement('CREATE TABLE kian_submissions (id BIGINT PRIMARY KEY, tax_case_id BIGINT UNIQUE, stage_id SMALLINT NULL)');
            $this->assertRefused('table-owned SQLite');
        } else {
            $this->db->statement('ALTER TABLE kian_submissions ADD CONSTRAINT referenced_case UNIQUE(tax_case_id)');
            $this->db->statement('CREATE TABLE case_key_consumer (case_id BIGINT REFERENCES kian_submissions(tax_case_id))');
            $this->assertRefused('dependent or ambiguous');
        }
        $indexes = $this->db->getSchemaBuilder()->getIndexes('kian_submissions');
        $this->assertFalse(collect($indexes)->contains('name', 'kian_submissions_tax_case_stage_unique'));
    }

    public function test_equivalent_constraint_or_index_is_preserved(): void
    {
        $this->db->statement($this->db->getDriverName() === 'pgsql'
            ? 'ALTER TABLE kian_submissions ADD CONSTRAINT owned_pair UNIQUE(tax_case_id, stage_id)'
            : 'CREATE UNIQUE INDEX owned_pair ON kian_submissions(tax_case_id, stage_id)');
        $this->migration->up();
        $indexes = $this->db->getSchemaBuilder()->getIndexes('kian_submissions');
        $this->assertTrue(collect($indexes)->contains('name', 'owned_pair'));
        $this->assertFalse(collect($indexes)->contains('name', 'kian_submissions_tax_case_stage_unique'));
    }

    public function test_conflicting_name_fails_closed(): void
    {
        $this->db->statement('CREATE INDEX kian_submissions_tax_case_stage_unique ON kian_submissions(stage_id)');
        $this->assertRefused('incompatible ownership/definition');
    }

    public function test_nonstandard_null_or_collation_semantics_are_refused(): void
    {
        $this->db->statement($this->db->getDriverName() === 'pgsql'
            ? 'CREATE UNIQUE INDEX unusual_pair ON kian_submissions(tax_case_id, stage_id) NULLS NOT DISTINCT'
            : 'CREATE UNIQUE INDEX unusual_pair ON kian_submissions(tax_case_id COLLATE NOCASE, stage_id)');
        $this->assertRefused('incompatible unique definition');
    }

    public function test_missing_stage_is_refused_without_guessing_history(): void
    {
        $this->insert(1, 1, null, 'submitted');
        $this->db->statement('ALTER TABLE kian_submissions DROP COLUMN stage_id');
        $this->assertRefused('missing stage_id');
        $this->assertSame('submitted', $this->db->table('kian_submissions')->where('id', 1)->value('status'));
    }

    public function test_down_refuses_to_remove_lifetime_protection(): void
    {
        $this->migration->up();
        try {
            $this->migration->down();
            $this->fail('Rollback must be explicitly refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('intentionally irreversible', $e->getMessage());
        }
        $this->insert(1, 1, 4);
        $this->assertRejected(fn () => $this->insert(2, 1, 4), 'unique');
    }

    private function insert(int $id, int $case, ?int $stage, string $status = 'draft', ?string $deleted = null): void
    {
        $this->db->table('kian_submissions')->insert(['id' => $id, 'tax_case_id' => $case,
            'stage_id' => $stage, 'status' => $status, 'deleted_at' => $deleted]);
    }

    private function assertRefused(string $diagnostic): void
    {
        try {
            $this->migration->up();
            $this->fail('Unsafe migration must fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($diagnostic, $e->getMessage());
        }
    }

    private function assertRejected(callable $write, string $diagnostic): void
    {
        try {
            $this->db->transaction($write);
            $this->fail('The database must reject this write.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString($diagnostic, strtolower($e->getMessage()));
        }
    }
}
