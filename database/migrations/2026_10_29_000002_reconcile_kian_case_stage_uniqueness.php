<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEY = 'kian_submissions_tax_case_stage_unique';

    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        $driver = $db->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true) || $db->getTablePrefix() !== '') {
            throw new RuntimeException('KIAN reconciliation: unsupported driver or table prefix; no changes made.');
        }

        $busyTimeout = $driver === 'sqlite' ? $db->selectOne('PRAGMA busy_timeout')->timeout : null;
        try {
            if ($driver === 'sqlite') {
                $db->statement('PRAGMA busy_timeout = 3000');
            }
            $db->transaction(function () use ($db, $driver) {
                if ($driver === 'pgsql') {
                    if ((int) $db->selectOne('SHOW server_version_num')->server_version_num < 150000) {
                        throw new RuntimeException('KIAN reconciliation: PostgreSQL before 15 requires separate catalog verification.');
                    }
                    $db->statement("SET LOCAL lock_timeout = '3s'");
                    $db->statement("SET LOCAL statement_timeout = '30s'");
                    $table = $db->selectOne(<<<'SQL'
                        SELECT c.oid, n.nspname, c.relkind,
                               EXISTS (SELECT 1 FROM pg_inherits WHERE inhrelid = c.oid OR inhparent = c.oid) AS inherited
                        FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                        WHERE c.oid = to_regclass('kian_submissions')
                        SQL);
                    if (! $table || $table->nspname !== 'public' || $table->relkind !== 'r' || $table->inherited) {
                        throw new RuntimeException('KIAN reconciliation: expected ordinary public.kian_submissions without inheritance.');
                    }
                    $db->statement('LOCK TABLE public.kian_submissions IN ACCESS EXCLUSIVE MODE');
                    if ((int) $db->selectOne("SELECT 'public.kian_submissions'::regclass::oid AS oid")->oid !== (int) $table->oid) {
                        throw new RuntimeException('KIAN reconciliation: table identity changed while acquiring lock.');
                    }
                    $columns = $db->select("SELECT attname AS name, attnotnull, atttypid::regtype::text AS type FROM pg_attribute WHERE attrelid = 'public.kian_submissions'::regclass AND attnum > 0 AND NOT attisdropped");
                    $tableSql = 'public.kian_submissions';
                } else {
                    $table = $db->selectOne("SELECT type FROM main.sqlite_schema WHERE name = 'kian_submissions'");
                    $shadow = $db->selectOne("SELECT name FROM temp.sqlite_schema WHERE name = 'kian_submissions'");
                    if (! $table || $table->type !== 'table' || $shadow) {
                        throw new RuntimeException('KIAN reconciliation: expected unshadowed main.kian_submissions table.');
                    }
                    // Acquire the writer reservation before inspecting data or changing indexes.
                    $db->statement('UPDATE main.kian_submissions SET tax_case_id = tax_case_id WHERE 0');
                    $columns = $db->select('PRAGMA main.table_info(kian_submissions)');
                    $tableSql = 'main.kian_submissions';
                }
                $columns = collect($columns)->keyBy('name');
                foreach (['tax_case_id', 'stage_id'] as $column) {
                    if (! $columns->has($column)) {
                        throw new RuntimeException("KIAN reconciliation: missing {$column}; run stage introduction first.");
                    }
                    $metadata = $columns[$column];
                    $integer = $driver === 'pgsql'
                        ? in_array($metadata->type, ['smallint', 'integer', 'bigint'], true)
                        : str_contains(strtoupper($metadata->type), 'INT');
                    if (! $integer || ($column === 'stage_id' && ($driver === 'pgsql' ? $metadata->attnotnull : $metadata->notnull))) {
                        throw new RuntimeException('KIAN reconciliation: incompatible case/stage types or nonnullable historical stage.');
                    }
                }

                $indexes = $this->indexes($db, $driver);
                [$pairs, $old] = $this->classify($indexes);
                if (count($old) > 1) {
                    throw new RuntimeException('KIAN reconciliation: multiple obsolete case keys need manual ownership review.');
                }
                if ($old) {
                    $index = $old[0];
                    if ($driver === 'sqlite' && $index['origin'] !== 'c') {
                        throw new RuntimeException('KIAN reconciliation: table-owned SQLite case key requires an approved rebuild.');
                    }
                    if ($driver === 'pgsql') {
                        $dependency = $db->selectOne(<<<'SQL'
                            SELECT 1 AS found FROM pg_depend d
                            WHERE d.refclassid = 'pg_class'::regclass AND d.refobjid = ?
                              AND NOT (d.classid = 'pg_constraint'::regclass AND d.objid = ? AND d.deptype = 'i')
                            LIMIT 1
                            SQL, [$index['oid'], $index['constraint_oid'] ?? 0]);
                        if ($dependency || ($index['constraint'] !== null && ($index['constraint_type'] !== 'u' || $index['deferrable']))) {
                            throw new RuntimeException('KIAN reconciliation: dependent or ambiguous PostgreSQL case key; DBA review required.');
                        }
                    } else {
                        $dependency = $db->selectOne(<<<'SQL'
                            SELECT s.name FROM main.sqlite_schema s, pragma_foreign_key_list(s.name) f
                            WHERE s.type = 'table' AND f."table" = 'kian_submissions'
                              AND (f."to" = 'tax_case_id' OR f."to" IS NULL) LIMIT 1
                            SQL);
                        if ($dependency) {
                            throw new RuntimeException('KIAN reconciliation: incoming SQLite foreign key needs manual dependency review.');
                        }
                    }
                }
                $duplicate = $db->selectOne("SELECT tax_case_id, stage_id, COUNT(*) AS physical_rows FROM {$tableSql} WHERE tax_case_id IS NOT NULL AND stage_id IS NOT NULL GROUP BY tax_case_id, stage_id HAVING COUNT(*) > 1 LIMIT 1");
                if ($duplicate) {
                    throw new RuntimeException('KIAN reconciliation: duplicate physical pair '.json_encode($duplicate).'; preserve rows and reconcile separately.');
                }
                if (! $pairs) {
                    $key = $this->quote(self::KEY);
                    $db->statement($driver === 'pgsql'
                        ? "CREATE UNIQUE INDEX {$key} ON public.kian_submissions (tax_case_id, stage_id)"
                        : "CREATE UNIQUE INDEX main.{$key} ON kian_submissions (tax_case_id, stage_id)");
                }
                // Protection is established before removing any obsolete case-only key.
                if ($old) {
                    $index = $old[0];
                    if ($driver === 'pgsql' && $index['constraint'] !== null) {
                        $db->statement('ALTER TABLE public.kian_submissions DROP CONSTRAINT '.$this->quote($index['constraint']).' RESTRICT');
                    } else {
                        $db->statement('DROP INDEX '.($driver === 'pgsql' ? 'public.' : 'main.').$this->quote($index['name']));
                    }
                }
                [$verifiedPairs, $remainingOld] = $this->classify($this->indexes($db, $driver));
                if (! $verifiedPairs || $remainingOld) {
                    throw new RuntimeException('KIAN reconciliation: final catalog verification failed; transaction must roll back.');
                }
            });
        } finally {
            if ($driver === 'sqlite') {
                $db->statement('PRAGMA busy_timeout = '.(int) $busyTimeout);
            }
        }
    }

    private function indexes($db, string $driver): array
    {
        if ($driver === 'pgsql') {
            $rows = $db->select(<<<'SQL'
                SELECT i.indexrelid AS oid, c.relname AS name, i.indisunique AS unique,
                       i.indisprimary AS primary, i.indisvalid AND i.indisready AND i.indislive AS valid,
                       i.indpred IS NOT NULL AS partial, i.indexprs IS NOT NULL AS expression,
                       i.indimmediate AS immediate, am.amname AS method,
                       i.indnullsnotdistinct AS nulls_not_distinct,
                       con.oid AS constraint_oid, con.conname AS constraint, con.contype AS constraint_type,
                       con.condeferrable AS deferrable,
                       (SELECT json_agg(a.attname ORDER BY k.ordinality)
                        FROM unnest(i.indkey) WITH ORDINALITY k(attnum, ordinality)
                        LEFT JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum
                        WHERE k.ordinality <= i.indnkeyatts) AS columns,
                       EXISTS (SELECT 1 FROM unnest(i.indclass) cl(oid)
                               JOIN pg_opclass op ON op.oid = cl.oid WHERE NOT op.opcdefault) AS custom_ops
                FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
                JOIN pg_am am ON am.oid = c.relam
                LEFT JOIN pg_constraint con ON con.conindid = i.indexrelid AND con.conrelid = i.indrelid AND con.contype IN ('p', 'u', 'x')
                WHERE i.indrelid = 'public.kian_submissions'::regclass
                SQL);

            return array_map(function ($row) {
                $index = (array) $row;
                $index['columns'] = json_decode($row->columns, true) ?? [];
                $index['compatible'] = $row->valid && ! $row->partial && ! $row->expression
                    && $row->immediate && $row->method === 'btree' && ! $row->custom_ops && ! $row->nulls_not_distinct;

                return $index;
            }, $rows);
        }
        // Table-valued pragmas inspect all index columns in one metadata query.
        $rows = $db->select(<<<'SQL'
            SELECT l.name, l."unique", l.origin, l.partial, x.seqno, x.cid, x.name AS column_name, x.coll
            FROM pragma_index_list('kian_submissions', 'main') l
            JOIN pragma_index_xinfo(l.name, 'main') x ON x."key" = 1
            ORDER BY l.name, x.seqno
            SQL);
        $indexes = [];
        foreach ($rows as $row) {
            if (! isset($indexes[$row->name])) {
                $indexes[$row->name] = ['name' => $row->name, 'unique' => (bool) $row->unique,
                    'primary' => $row->origin === 'pk', 'origin' => $row->origin, 'columns' => [],
                    'compatible' => ! $row->partial];
            }
            $indexes[$row->name]['columns'][] = $row->column_name;
            $indexes[$row->name]['compatible'] = $indexes[$row->name]['compatible']
                && $row->cid >= 0 && $row->coll === 'BINARY';
        }

        return array_values($indexes);
    }

    private function classify(array $indexes): array
    {
        $pairs = $old = [];
        foreach ($indexes as $index) {
            $columns = $index['columns'];
            sort($columns);
            $pair = $columns === ['stage_id', 'tax_case_id'];
            $case = $columns === ['tax_case_id'];
            if ($index['unique'] && ! $index['primary'] && (in_array(null, $columns, true) || $columns === ['stage_id'])) {
                throw new RuntimeException('KIAN reconciliation: unexpected expression or stage-only unique key; manual review required.');
            }
            if ($index['name'] === self::KEY && (! $pair || ! $index['unique'] || ! $index['compatible'])) {
                throw new RuntimeException('KIAN reconciliation: proposed index name has incompatible ownership/definition.');
            }
            if (! $index['unique'] || (! $pair && ! $case)) {
                continue;
            }
            if (! $index['compatible'] || $index['primary']) {
                throw new RuntimeException('KIAN reconciliation: incompatible unique definition '.$index['name'].'; manual review required.');
            }
            if ($pair) {
                $pairs[] = $index;
            } else {
                $old[] = $index;
            }
        }

        return [$pairs, $old];
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    public function down(): void
    {
        throw new RuntimeException('KIAN lifetime uniqueness is intentionally irreversible. Use a reviewed forward migration; case-only rollback would invalidate multi-stage history.');
    }
};
