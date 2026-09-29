# Phase 1C.3 — Historical Refund Backfill

## 1. Command Usage

```bash
# Read-only; this is the default.
php artisan portax:backfill-historical-refunds

# Writes only still-eligible rows after transactional revalidation.
php artisan portax:backfill-historical-refunds --apply

# Optional operational narrowing.
php artisan portax:backfill-historical-refunds --case=CASE_NUMBER
php artisan portax:backfill-historical-refunds --stage=4
```

`--stage` accepts only 4, 7, 10, or 12. Apply refuses an atomic manifest larger than 500 rows; an operator must review and narrow such a run with `--case` or `--stage` rather than creating an unbounded transaction.

## 2. Eligibility Logic

A `(tax_case_id, stage_id)` pair is eligible only when:

1. Its TaxCase is not soft-deleted.
2. Its origin is Stage 4, 7, 10, or 12.
3. Exactly one active stage-specific decision exists.
4. The decision explicitly stores `create_refund=true` and, where the decision table has workflow status, is submitted or approved.
5. Submitted same-stage history contains parseable JSON with an explicit boolean `create_refund` value.
6. The latest explicit submitted history value is `true`.
7. No active or soft-deleted RefundProcess exists for the pair.
8. No RefundProcess at another stage points to the same decision.
9. No Stage 13–15 history or bank-transfer evidence makes the missing representation ambiguous.

Discovery is shared by dry-run and apply. It derives the amount from the decision record, including a valid zero, and the submitter/time from the qualifying submitted history.

## 3. Ambiguous / Exclusion Logic

The command reports `MANUAL REVIEW` and does not insert when it finds a deleted case or decision, no or multiple active decisions, draft-only state, missing/unparseable/contradictory history, a soft-deleted or mismatched same-stage RefundProcess, cross-stage trigger linkage, legacy Stage 13–15 evidence, or bank-transfer evidence. A matching active canonical RefundProcess is reported as `SKIPPED`/already satisfied.

Pairs where both the decision and history explicitly say no Refund are not repair evidence and are omitted from the manifest.

## 4. Dry-Run Output

The console manifest contains only the TaxCase ID/reference, stage, decision type/ID, history ID, historical amount, submitter/time, existing RefundProcess state, classification, and concise reason. It also prints environment/database context and candidate, skipped, manual-review, collision, and would-insert counts.

Representative pre-apply summary from development:

```text
READ-ONLY DRY RUN
Candidate count: 2
Skipped count: 2
Manual-review count: 0
Collision count: 0
Would-insert count: 2
Dry run complete: zero writes performed.
```

## 5. Apply Transaction / Locking

Apply uses the reviewed dry-run manifest and one whole-set transaction. It locks TaxCases in ID order (the command's stage-identity key space), then the exact decision rows and existing RefundProcess rows. It reruns shared discovery while locked and compares the decision, history, amount, submitter, and timestamp signature for every intended insert. Any changed, missing, or newly colliding candidate throws and rolls back the entire batch.

Two concurrent command runs serialize on the TaxCase lock; the later run revalidates after the earlier commit and rolls back its stale manifest instead of duplicating it. Existing schema intentionally permits multiple preliminary (`stage_id=0`) refunds and therefore has a regular `(tax_case_id, stage_id)` index rather than a blanket unique constraint. No incompatible constraint was added.

Insertion uses the query builder, so RefundProcess model events and unrelated notifications do not run. Verification before commit checks inserted count, exact case/stage/trigger/amount/status mapping, and absence of duplicate decision-stage identity. The command prints exact created RefundProcess IDs for any separately reviewed compensating operation; it never auto-deletes or revives rows.

## 6. RefundProcess Mapping

| RefundProcess field | Historical source |
|---|---|
| `tax_case_id` | stage-specific decision |
| `stage_id` | fixed decision origin: 4/7/10/12 |
| `stage_source` | `SKP`/`OBJECTION`/`APPEAL`/`SUPREME_COURT` |
| `triggered_by_decision_id` | exact qualifying decision ID |
| `triggered_by_decision_type` | exact decision model class |
| `refund_amount` | decision `refund_amount`; null follows the normalized initial value of `0.00`; explicit zero remains zero |
| `refund_number` | deterministic `HIST-{stage}-{tax_case_id}-{decision_id}` |
| `refund_method` / `refund_status` / `status` | `bank_transfer` / `pending` / `draft`, matching canonical initiation |
| `submitted_by` | qualifying submitted workflow-history user |
| `submitted_at`, `created_at`, `updated_at` | qualifying submitted workflow-history timestamp |
| `sequence_number` | next sequence within the locked TaxCase |

No “latest decision” lookup or unrelated financial amount is used.

## 7. Idempotency

An active exact case/stage process with matching trigger linkage is already satisfied and cannot re-enter the eligible set. Apply also performs locked revalidation. Thus the first successful development apply inserted two rows and the next dry run reported zero eligible rows.

## 8. Development Verification

Connected database: `yig_portax` on PostgreSQL, local environment.

```text
pre-apply dry run:  2 eligible, 2 already satisfied, 0 manual review
successful apply:   2 inserted; verification passed; IDs 5 and 6
post-apply dry run: 0 eligible, 4 already satisfied, 0 would insert
```

Both inserted rows are Stage 4 SKP-linked processes with amount `0.00`, pending refund status, draft workflow status, and the historical submitter/timestamp. Candidate TaxCases retained `current_stage=4`, `case_status_id=2`, and their prior `updated_at` values. Workflow histories remained at 23 rows; KIAN and bank-transfer rows remained at zero.

During development verification, the first PostgreSQL apply attempt exposed an invalid grouped verification query. The transaction rolled back completely (RefundProcess count remained 2), the query was corrected, focused tests were rerun, and only then did the successful apply create IDs 5 and 6.

## 9. Production Runbook

1. Deploy the command and confirm database backup/restore readiness.
2. Run `php artisan portax:backfill-historical-refunds`.
3. Save and review the complete console manifest and all manual-review/collision rows.
4. Do not apply if the safe candidate set has not been accepted. Resolve ambiguous rows separately; do not broaden the heuristic.
5. Run `php artisan portax:backfill-historical-refunds --apply`.
6. Preserve the exact created IDs printed by the command.
7. Run the dry-run command again; eligible and would-insert counts should be zero.
8. Verify repaired TaxCases in the UI. Any post-commit correction requires a separately reviewed operation against the exact printed IDs—never a broad delete.

## 10. Files Changed

- `app/Console/Commands/BackfillHistoricalRefunds.php`
- `app/Services/HistoricalRefundBackfillService.php`
- `tests/Feature/BackfillHistoricalRefundsCommandTest.php`
- `docs/implementation/phase-1c3-historical-refund-backfill.md`

## 11. Tests

```text
targeted command tests: 9 passed, 64 assertions
Phase 1C regression:    15 passed (included in 29-test focused regression run)
Phase 1A regression:     5 passed (included in 29-test focused regression run)
focused regressions:    29 passed, 197 assertions
full suite:             87 passed, 668 assertions
format check:           passed
```

## 12. Performance Review

Discovery uses a bounded number of set-based queries. It first scans only submitted histories in decision stages whose payload contains the Refund key and the four small decision tables for explicit Refund intent. It then loads TaxCases, full relevant histories, RefundProcesses, and transfer evidence only for signal-bearing case IDs. Classification is in memory, with no query inside candidate loops and no N+1 pattern. Apply adds ordered locking queries, one shared rediscovery, one grouped sequence query, inserts, and set-based verification.

## 13. Scope Confirmation

The implementation changes no main workflow state, Decision Point record/history, case status, KIAN data, bank-transfer data, Refund Stage 1–4 lifecycle, UI, legacy Stage 13–15 data, revision flow, or Phase 2 architecture. It inserts only canonical missing RefundProcess rows after explicit `--apply` and locked revalidation.
