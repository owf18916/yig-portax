-- MANUAL PRODUCTION REVIEW DELIVERABLE. This script COMMITS on success.
-- No Production connection has been used to prepare or test this file.
-- Change ONLY the keeper setting for another environment (local test: JA18MarC).
-- Run as one script with stop-on-error enabled (psql: -X -v ON_ERROR_STOP=1 -f ...).
-- Stop application writers, scheduler and queue workers before running; no jobs are dispatched here.
-- Unknown dependencies/payloads and ambiguous ownership abort the entire transaction.
-- Uploaded files are deliberately retained; review storage ownership separately.
BEGIN;
SET LOCAL search_path = public, pg_temp;
SET LOCAL lock_timeout = '10s';
SET LOCAL statement_timeout = '120s';
CREATE TEMP TABLE _reset_config(keeper text NOT NULL) ON COMMIT DROP;
INSERT INTO _reset_config VALUES ('PA18MarC');

CREATE TEMP TABLE _reset_map(tbl text PRIMARY KEY, model text UNIQUE, delete_order int) ON COMMIT DROP;
INSERT INTO _reset_map VALUES
 ('tax_cases','TaxCase',100),
 ('sp2_records','Sp2Record',70), ('sphp_records','SphpRecord',70),
 ('skp_records','SkpRecord',70), ('objection_submissions','ObjectionSubmission',70),
 ('spuh_records','SpuhRecord',70), ('objection_decisions','ObjectionDecision',70),
 ('appeal_submissions','AppealSubmission',70), ('appeal_explanation_requests','AppealExplanationRequest',70),
 ('appeal_decisions','AppealDecision',70), ('supreme_court_submissions','SupremeCourtSubmission',70),
 ('supreme_court_decisions','SupremeCourtDecision',70),
 ('preliminary_refund_requests','PreliminaryRefundRequest',40),
 ('refund_processes','RefundProcess',50), ('bank_transfer_requests','BankTransferRequest',30),
 ('kian_submissions','KianSubmission',40), ('workflow_histories','WorkflowHistory',70),
 ('status_histories','StatusHistory',70), ('notification_logs','NotificationLog',50),
 ('notification_attempts','NotificationAttempt',30), ('documents','Document',60),
 ('revisions','Revision',80), ('audit_logs','AuditLog',20),
 ('jobs',NULL,10), ('failed_jobs',NULL,10);
CREATE TEMP TABLE _reset_scope(tbl text, id bigint, case_id bigint,
 PRIMARY KEY(tbl,id,case_id)) ON COMMIT DROP;
CREATE TEMP TABLE _reset_links(tbl text,id bigint,parent_tbl text,parent_id bigint) ON COMMIT DROP;
CREATE TEMP TABLE _reset_before(tbl text PRIMARY KEY, n bigint, digest text) ON COMMIT DROP;
-- Retained for inspection in the executing session after COMMIT.
CREATE TEMP TABLE _reset_report(item text, details jsonb);

DO $reset$
DECLARE v record; keeper_id bigint; matches bigint; changed bigint; n bigint;
        expected_n bigint; fingerprint text; before_total bigint;
BEGIN
 -- Lock existing tables to make previews, ownership, and preservation checks consistent.
 -- No persistent schema changes, sequences, reference rows or application hooks are used.
 FOR v IN SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename LOOP
   EXECUTE format('LOCK TABLE public.%I IN SHARE ROW EXCLUSIVE MODE',v.tablename);
 END LOOP;
 SELECT count(*),min(id) INTO matches,keeper_id FROM tax_cases
 WHERE case_number=(SELECT keeper FROM _reset_config); -- Includes soft-deleted rows.
 IF matches <> 1 THEN
   RAISE EXCEPTION 'Keeper must match exactly once including soft-deleted records; found %',matches;
 END IF;
 SELECT count(*) INTO before_total FROM tax_cases;
 INSERT INTO _reset_report SELECT 'preview',jsonb_build_object('database',current_database(),
   'keeper',(SELECT keeper FROM _reset_config),'keeper_id',keeper_id,'cases_before',before_total,
   'remove',coalesce(jsonb_agg(jsonb_build_object('id',id,'case_number',case_number,'deleted_at',deleted_at)
     ORDER BY id) FILTER(WHERE id<>keeper_id),'[]'::jsonb)) FROM tax_cases;

 -- Required tables fail at resolution; do not silently skip missing dependencies.
 FOR v IN SELECT * FROM _reset_map LOOP
   EXECUTE format('SELECT count(*) FROM public.%I',v.tbl) INTO n;
 END LOOP;
 IF EXISTS (SELECT 1 FROM information_schema.columns c WHERE c.table_schema='public'
   AND c.column_name='tax_case_id' AND NOT EXISTS(SELECT 1 FROM _reset_map m WHERE m.tbl=c.table_name)) THEN
   RAISE EXCEPTION 'Unmapped table with tax_case_id; review dependency map';
 END IF;
 IF EXISTS (SELECT 1 FROM pg_constraint c JOIN pg_class p ON p.oid=c.confrelid
   JOIN pg_namespace ns ON ns.oid=p.relnamespace
   WHERE c.contype='f' AND ns.nspname='public' AND p.relname IN(SELECT tbl FROM _reset_map)
   AND NOT EXISTS(SELECT 1 FROM _reset_map m WHERE c.conrelid=to_regclass('public.'||m.tbl))) THEN
   RAISE EXCEPTION 'Unmapped inbound foreign key; review dependency map';
 END IF;
 IF EXISTS(SELECT 1 FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid
   WHERE NOT t.tgisinternal AND c.relnamespace='public'::regnamespace) THEN
   RAISE EXCEPTION 'Application database triggers require separate review';
 END IF;

 INSERT INTO _reset_scope SELECT 'tax_cases',id,id FROM tax_cases;
 FOR v IN SELECT m.tbl FROM _reset_map m JOIN information_schema.columns c
   ON c.table_schema='public' AND c.table_name=m.tbl AND c.column_name='tax_case_id' LOOP
   EXECUTE format('INSERT INTO _reset_scope SELECT %L,id,tax_case_id FROM public.%I WHERE tax_case_id IS NOT NULL',v.tbl,v.tbl);
 END LOOP;
 INSERT INTO _reset_links SELECT 'bank_transfer_requests',id,'refund_processes',refund_process_id FROM bank_transfer_requests;
 INSERT INTO _reset_links SELECT 'notification_attempts',id,'notification_logs',notification_log_id FROM notification_attempts;

 -- Explicit type aliases: legacy short names and fully qualified Laravel model names.
 -- WorkflowHistory documentable_id is sometimes a STAGE ID, not a history-row ID.
 INSERT INTO _reset_links
 SELECT 'documents',d.id,m.tbl,d.documentable_id FROM documents d JOIN _reset_map m
   ON d.documentable_type IN(m.model,'App\Models\'||m.model)
 WHERE m.tbl<>'workflow_histories' AND d.tax_case_id IS NULL;
 IF EXISTS(SELECT 1 FROM documents WHERE tax_case_id IS NULL
   AND (documentable_type IN('WorkflowHistory','App\Models\WorkflowHistory')
     OR NOT EXISTS(SELECT 1 FROM _reset_map m WHERE documentable_type IN(m.model,'App\Models\'||m.model)))) THEN
   RAISE EXCEPTION 'Document without provable case ownership';
 END IF;
 INSERT INTO _reset_links SELECT 'documents',id,'documents',previous_version_id FROM documents WHERE previous_version_id IS NOT NULL;
 INSERT INTO _reset_links SELECT 'documents',previous_version_id,'documents',id FROM documents WHERE previous_version_id IS NOT NULL;
 INSERT INTO _reset_links SELECT 'documents',response_document_id,'kian_submissions',id FROM kian_submissions WHERE response_document_id IS NOT NULL;
 INSERT INTO _reset_links SELECT 'documents',submission_document_id,'preliminary_refund_requests',id FROM preliminary_refund_requests WHERE submission_document_id IS NOT NULL;
 INSERT INTO _reset_links SELECT 'documents',decision_document_id,'preliminary_refund_requests',id FROM preliminary_refund_requests WHERE decision_document_id IS NOT NULL;
 INSERT INTO _reset_links
 SELECT 'revisions',r.id,m.tbl,r.revisable_id FROM revisions r JOIN _reset_map m
   ON r.revisable_type IN(m.model,'App\Models\'||m.model);
 INSERT INTO _reset_links
 SELECT 'revisions',r.id,m.tbl,r.target_id FROM revisions r JOIN _reset_map m
   ON r.target_type IN(m.model,'App\Models\'||m.model);
 INSERT INTO _reset_links SELECT 'revisions',last_revision_id,'tax_cases',id FROM tax_cases WHERE last_revision_id IS NOT NULL;
 INSERT INTO _reset_links
 SELECT 'audit_logs',a.id,m.tbl,a.auditable_id FROM audit_logs a JOIN _reset_map m
   ON a.auditable_type IN(m.model,'App\Models\'||m.model);
 INSERT INTO _reset_links
 SELECT 'refund_processes',r.id,m.tbl,r.triggered_by_decision_id FROM refund_processes r JOIN _reset_map m
   ON r.triggered_by_decision_type IN(m.model,'App\Models\'||m.model);
 IF EXISTS(SELECT 1 FROM revisions r WHERE NOT EXISTS(SELECT 1 FROM _reset_map m
   WHERE r.revisable_type IN(m.model,'App\Models\'||m.model)))
   OR EXISTS(SELECT 1 FROM revisions r WHERE r.target_type IS NOT NULL AND NOT EXISTS
     (SELECT 1 FROM _reset_map m WHERE r.target_type IN(m.model,'App\Models\'||m.model))) THEN
   RAISE EXCEPTION 'Unknown revision owner type';
 END IF;
 IF EXISTS(SELECT 1 FROM refund_processes r WHERE r.triggered_by_decision_type IS NOT NULL
   AND NOT EXISTS(SELECT 1 FROM _reset_map m WHERE r.triggered_by_decision_type IN(m.model,'App\Models\'||m.model))) THEN
   RAISE EXCEPTION 'Unknown refund trigger type';
 END IF;
 LOOP
   INSERT INTO _reset_scope SELECT DISTINCT l.tbl,l.id,s.case_id FROM _reset_links l
     JOIN _reset_scope s ON s.tbl=l.parent_tbl AND s.id=l.parent_id ON CONFLICT DO NOTHING;
   GET DIAGNOSTICS changed=ROW_COUNT;
   EXIT WHEN changed=0;
 END LOOP;
 IF EXISTS(SELECT 1 FROM _reset_scope GROUP BY tbl,id HAVING count(*)>1)
   OR EXISTS(SELECT 1 FROM _reset_scope s WHERE NOT EXISTS(SELECT 1 FROM tax_cases c WHERE c.id=s.case_id)) THEN
   RAISE EXCEPTION 'Conflicting or dangling case ownership';
 END IF;
 FOR v IN SELECT tbl FROM _reset_map WHERE tbl IN('documents','revisions','bank_transfer_requests','notification_attempts') LOOP
   EXECUTE format('SELECT count(*) FROM public.%I t WHERE NOT EXISTS(SELECT 1 FROM _reset_scope s WHERE s.tbl=%L AND s.id=t.id)',v.tbl,v.tbl) INTO n;
   IF n<>0 THEN RAISE EXCEPTION 'Unresolved ownership in %: % rows',v.tbl,n; END IF;
 END LOOP;
 IF EXISTS(SELECT 1 FROM audit_logs a JOIN _reset_map m
   ON a.auditable_type IN(m.model,'App\Models\'||m.model)
   WHERE NOT EXISTS(SELECT 1 FROM _reset_scope s WHERE s.tbl='audit_logs' AND s.id=a.id)) THEN
   RAISE EXCEPTION 'Unresolved transactional audit owner';
 END IF;

 -- Only this proven serialized job shape is decoded. Never unserialize or execute PHP.
 -- Any unfamiliar jobs/batches stop this script for a precise manual queue review.
 IF EXISTS(SELECT 1 FROM job_batches) THEN RAISE EXCEPTION 'Job batches require manual review'; END IF;
 FOR v IN SELECT tbl FROM _reset_map WHERE tbl IN('jobs','failed_jobs') LOOP
   EXECUTE format($q$
     INSERT INTO _reset_links
     SELECT %L,j.id,'notification_attempts',
       ((regexp_match(j.payload::jsonb->'data'->>'command',
         '^O:28:"App\\Jobs\\SendKianReminderJob":2:\{s:21:"notificationAttemptId";i:([0-9]+);s:11:"afterCommit";b:1;\}$'))[1])::bigint
     FROM public.%I j WHERE j.payload::jsonb->'data'->>'commandName'='App\Jobs\SendKianReminderJob'
   $q$,v.tbl,v.tbl);
   EXECUTE format('INSERT INTO _reset_scope SELECT l.tbl,l.id,s.case_id FROM _reset_links l JOIN _reset_scope s ON s.tbl=l.parent_tbl AND s.id=l.parent_id WHERE l.tbl=%L ON CONFLICT DO NOTHING',v.tbl);
   EXECUTE format('SELECT count(*) FROM public.%I j WHERE NOT EXISTS(SELECT 1 FROM _reset_scope s WHERE s.tbl=%L AND s.id=j.id)',v.tbl,v.tbl) INTO n;
   IF n<>0 THEN RAISE EXCEPTION 'Unrecognized or orphan queue payload in %: % rows',v.tbl,n; END IF;
 END LOOP;
 IF EXISTS(SELECT 1 FROM jobs WHERE reserved_at IS NOT NULL) THEN RAISE EXCEPTION 'Reserved jobs: stop workers and review before cleanup'; END IF;
 IF EXISTS(SELECT 1 FROM _reset_links l WHERE l.parent_id IS NOT NULL
   AND NOT EXISTS(SELECT 1 FROM _reset_scope s WHERE s.tbl=l.parent_tbl AND s.id=l.parent_id)) THEN
   RAISE EXCEPTION 'Dangling mapped relationship requires review';
 END IF;

 -- Snapshot the complete surviving rows, including the keeper, master/reference tables,
 -- users and unrelated audit records. Hashes compare all column values, not just counts.
 FOR v IN SELECT tablename AS tbl FROM pg_tables WHERE schemaname='public' ORDER BY tablename LOOP
   EXECUTE format($q$SELECT count(*),md5(coalesce(string_agg(v,E'\n' ORDER BY v),''))
     FROM(SELECT to_jsonb(t)::text v FROM public.%I t WHERE NOT EXISTS
       (SELECT 1 FROM _reset_scope s WHERE s.tbl=%L AND s.id::text=to_jsonb(t)->>'id' AND s.case_id<>%s)) x$q$,
       v.tbl,v.tbl,keeper_id) INTO n,fingerprint;
   INSERT INTO _reset_before VALUES(v.tbl,n,fingerprint);
 END LOOP;
 INSERT INTO _reset_report SELECT 'retained_case_rows',coalesce(jsonb_object_agg(x.tbl,x.n),'{}'::jsonb)
 FROM(SELECT tbl,count(*) n FROM _reset_scope WHERE case_id=keeper_id GROUP BY tbl) x;
 INSERT INTO _reset_report SELECT 'document_storage',jsonb_build_object('deleted_metadata_rows',count(*),
   'physical_files_deleted',false) FROM documents d JOIN _reset_scope s
   ON s.tbl='documents' AND s.id=d.id WHERE s.case_id<>keeper_id;

 -- Delete leaves before parents. Self-linked document versions are removed in one statement.
 -- last_revision_id for deleted cases may be SET NULL by its FK; keeper stays byte-for-byte intact.
 FOR v IN SELECT * FROM _reset_map ORDER BY delete_order,tbl LOOP
   SELECT count(*) INTO expected_n FROM _reset_scope WHERE tbl=v.tbl AND case_id<>keeper_id;
   EXECUTE format('DELETE FROM public.%I t USING _reset_scope s WHERE s.tbl=%L AND s.id=t.id AND s.case_id<>%s',v.tbl,v.tbl,keeper_id);
   GET DIAGNOSTICS n=ROW_COUNT;
   IF n<>expected_n THEN RAISE EXCEPTION 'Delete count mismatch in %: expected %, got %',v.tbl,expected_n,n; END IF;
   INSERT INTO _reset_report VALUES('deleted',jsonb_build_object('table',v.tbl,'rows',n));
 END LOOP;
 IF (SELECT count(*) FROM tax_cases)<>1 OR NOT EXISTS(SELECT 1 FROM tax_cases
   WHERE id=keeper_id AND case_number=(SELECT keeper FROM _reset_config)) THEN
   RAISE EXCEPTION 'Keeper postcondition failed';
 END IF;
 FOR v IN SELECT * FROM _reset_before ORDER BY tbl LOOP
   EXECUTE format($q$SELECT count(*),md5(coalesce(string_agg(to_jsonb(t)::text,E'\n' ORDER BY to_jsonb(t)::text),'')) FROM public.%I t$q$,v.tbl) INTO n,fingerprint;
   IF n<>v.n OR fingerprint<>v.digest THEN RAISE EXCEPTION 'Surviving rows changed or mapped rows remain in %',v.tbl; END IF;
 END LOOP;
 INSERT INTO _reset_report VALUES('verification',jsonb_build_object('status','SUCCESS',
   'cases_before',before_total,'cases_after',1,'keeper_id',keeper_id,
   'keeper',(SELECT keeper FROM _reset_config),'all_surviving_rows_unchanged',true,
   'unmapped_queue_jobs',0,'mapped_trial_rows_remaining',0));
END
$reset$;
SELECT item,details FROM _reset_report ORDER BY item,details::text;
COMMIT;
