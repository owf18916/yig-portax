# Database Migrations - Complete List

**Total Migrations:** 11 files (28 tables)

---

## 📋 Migration Files

### Group 1: Master Data Setup

#### 1. `2026_01_01_000001_create_roles_table.php`
**Tables Created:** 1
- `roles` - User role definitions with JSON permissions

**Key Fields:**
- code, name, description, permissions (JSON), is_active
- Indexes: code

---

#### 2. `2026_01_01_000002_create_entities_table.php`
**Tables Created:** 1
- `entities` - Company master (holding + affiliates)

**Key Fields:**
- code (UNIQUE), name, entity_type (ENUM), parent_entity_id (self-FK for hierarchy)
- tax_id (NPWP, UNIQUE), registration_number
- Address, contact, business info
- is_active, timestamps, soft_deletes

**Indexes:** code, tax_id, entity_type

---

#### 3. `2026_01_01_000003_extend_users_table.php`
**Tables Modified:** 1
- `users` - Extended with new fields

**Added Fields:**
- entity_id (FK → entities)
- role_id (FK → roles)
- phone, position, department
- last_login_at, is_active, deleted_at (soft_deletes)

**Indexes:** entity_id, role_id, is_active

---

#### 4. `2026_01_01_000004_create_master_data_tables.php`
**Tables Created:** 4
- `fiscal_years` - Year master (2020-2030)
- `periods` - Monthly/quarterly reporting periods
- `currencies` - Currency master with exchange rates
- `case_statuses` - Case status master (OPEN, SP2_RECEIVED, etc.)

**Key Features:**
- Fiscal years: year, start_date, end_date, is_active, is_closed
- Periods: period_code (YYYY-MM), period_type, month_start/end
- Currencies: code, exchange_rate, last_updated_at
- Case Statuses: code, stage_number, category (active/terminal), color for UI

**Indexes:** Comprehensive single + composite indexes

---

### Group 2: Core Transaction

#### 5. `2026_01_01_000005_create_tax_cases_table.php`
**Tables Created:** 1
- `tax_cases` - Main case table (parent of all workflow stages)

**Key Fields:**
- Identity: case_number (UNIQUE), case_type (CIT/VAT/PPh), fiscal_year_id, period_id, entity_id
- **SPT Merged:** spt_number, filing_date, received_date
- Amounts: reported_amount (immutable), disputed_amount (mutable), vat_in/out_amount
- Workflow: current_stage (1-12), is_completed, submitted_by, approved_by, submitted_at, approved_at
- Refund: refund_amount, refund_date
- Timestamps, soft_deletes

**Relationships:**
- 1:1 with all stage tables
- 1:Many with documents, audit_logs, status_histories, workflow_histories

**Indexes:** (user_id, case_status_id), (entity_id, current_stage), (fiscal_year_id, case_type), case_number, current_stage

---

### Group 3: Audit Process

#### 6. `2026_01_01_000006_create_audit_process_tables.php`
**Tables Created:** 3
- `sp2_records` (Stage 2) - Audit notification
- `sphp_records` (Stage 3) - Audit results
- `skp_records` (Stage 4) - Tax assessment (DECISION POINT)

**Common Fields to All:**
- tax_case_id (UNIQUE)
- submitted_by, submitted_at, approved_by, approved_at
- status (ENUM: draft, submitted, approved, rejected)
- notes, timestamps, soft_deletes

**SP2 Specific:**
- sp2_number, issue_date, receipt_date
- auditor_name, auditor_title, auditor_department
- findings (text)

**SPHP Specific:**
- sphp_number, issue_date, receipt_date
- corrections (text), additional_tax (decimal)
- findings_breakdown (JSON)

**SKP Specific (DECISION POINT):**
- skp_number, issue_date, receipt_date
- skp_type (ENUM: LB, NIHIL, KB) ← DECISION POINT
- skp_amount, audit_corrections
- **next_stage** (5=Objection, 12=Refund) ← ROUTING

**Indexes:** tax_case_id, submitted_at, skp_type

---

### Group 4: Objection Process

#### 7. `2026_01_01_000007_create_objection_process_tables.php`
**Tables Created:** 3
- `objection_submissions` (Stage 5)
- `spuh_records` (Stage 6) - Summon letter (Permintaan Penjelasan Uraian Halaman)
- `objection_decisions` (Stage 7) - DECISION POINT

**Common Fields to All:**
- Same approval workflow as stage tables
- tax_case_id (UNIQUE), submitted_by, approved_by, status

**Objection Submissions:**
- objection_number, submission_date, objection_amount
- objection_grounds, supporting_evidence

**SPUH Records:**
- spuh_number, request_date, due_date
- explanation_required, explanation_provided, explanation_date

**Objection Decisions (DECISION POINT):**
- decision_number, decision_date
- decision_type (ENUM: granted, partially_granted, rejected) ← DECISION POINT
- decision_amount
- **next_stage** (8=Appeal, 12=Refund) ← ROUTING

---

### Group 5: Appeal & Supreme Court

#### 8. `2026_01_01_000008_create_appeal_supreme_court_tables.php`
**Tables Created:** 5
- `appeal_submissions` (Stage 8)
- `appeal_explanation_requests` (Stage 9) - **NEW TABLE**
- `appeal_decisions` (Stage 10) - DECISION POINT
- `supreme_court_submissions` (Stage 11)
- `supreme_court_decisions` (Stage 11b) - DECISION POINT

**Appeal Submissions:**
- appeal_number, dispute_number, submission_date, appeal_amount
- appeal_grounds
- Same workflow fields (submitted_by, approved_by, status)

**Appeal Explanation Requests (NEW):**
- request_number, request_date, due_date
- explanation_required, explanation_provided, explanation_date

**Appeal Decisions (DECISION POINT):**
- decision_number, decision_date
- decision_type (ENUM: granted, partially_granted, rejected, skp_kb)
- **next_stage** (11=Supreme Court, 12=Refund)

**Supreme Court Submissions:**
- submission_number, submission_date, submission_amount
- legal_basis, review_type (ENUM: cassation, review)

**Supreme Court Decisions (DECISION POINT):**
- decision_number, decision_date
- decision_type (ENUM: granted, rejected, partially_granted)
- **next_stage** (always 12=Refund)

---

### Group 6: Refund & KIAN

#### 9. `2026_01_01_000009_create_refund_kian_tables.php`
**Tables Created:** 3
- `kian_submissions` - KIAN internal loss report
- `refund_processes` (Stage 12) - Refund process
- `bank_transfer_requests` - Bank transfer tracking (1:Many with refund)

**KIAN Submissions:**
- kian_number, submission_date, kian_amount
- kian_reason, workflow fields

**Refund Processes:**
- refund_number, refund_amount
- refund_method (ENUM: bank_transfer, check, credit)
- refund_status (ENUM: pending, approved, processed, completed, rejected)
- approved_date, processed_date, workflow fields

**Bank Transfer Requests:**
- request_number, instruction_number
- bank_code, bank_name, account_number, account_holder
- transfer_amount (can differ from refund_amount)
- transfer_date, processed_date, receipt_number
- transfer_status (ENUM: pending, processing, completed, rejected, cancelled)
- rejection_reason, created_by (user_id)

**Indexes:** refund_process_id + transfer_status, transfer_date

---

### Group 7: Workflow & Audit

#### 10. `2026_01_01_000010_create_workflow_audit_tables.php`
**Tables Created:** 4
- `workflow_histories` - **NEW** Track stage-to-stage progression
- `status_histories` - Case status change history
- `revisions` - Revision tracking (polymorphic)
- `audit_logs` - Complete audit trail (polymorphic)

**Workflow Histories (NEW):**
- tax_case_id, stage_from, stage_to
- action (ENUM: submitted, approved, routed, skipped, rejected)
- decision_point (e.g., 'skp_type', 'objection_decision')
- decision_value (e.g., 'LB', 'granted')
- user_id (who triggered)
- created_at only (immutable)

**Status Histories:**
- tax_case_id, old_status_id, new_status_id
- changed_by (user_id) ← WHO changed
- reason ← WHY changed
- created_at only (immutable)

**Revisions:**
- revisable_type/id (polymorphic)
- revision_status (ENUM: requested, approved, rejected, implemented)
- original_data, revised_data (JSON)
- requested_by, requested_at, approved_by, approved_at
- rejection_reason

**Audit Logs:**
- auditable_type/id (polymorphic)
- user_id, action (ENUM: created, updated, deleted, approved, submitted, rejected)
- model_name (what was modified)
- old_values, new_values (JSON)
- ip_address, user_agent
- performed_at (timestamp)

**Indexes:** Composite indexes on type+id, created_at, user_id

---

### Group 8: Document Management

#### 11. `2026_01_01_000011_create_documents_table.php`
**Tables Created:** 1
- `documents` - Document tracking (polymorphic)

**Key Fields:**
- tax_case_id, documentable_type/id (polymorphic)
- **stage_number** (int) - Quick stage filtering without JOIN
- document_type (e.g., 'spt_form', 'skp_letter')
- File info: original_filename, stored_filename, file_path, mime_type, file_size
- Upload tracking: uploaded_by (user_id), uploaded_at
- Verification: is_verified, verified_by, verified_at, verification_notes
- Description, timestamps, soft_deletes

**Relationships:**
- FK to tax_cases
- FK to users (uploaded_by, verified_by)
- Polymorphic to any stage table

**Indexes:** (tax_case_id, stage_number), (documentable_type, documentable_id), uploaded_at, is_verified

---

## 🔑 Key Design Patterns

### Approval Workflow
Every stage table has identical workflow structure:
```sql
submitted_by (FK to users)
submitted_at (timestamp)
approved_by (FK to users, nullable)
approved_at (timestamp, nullable)
status (ENUM: draft → submitted → approved → rejected)
```

### Decision Routing
Decision tables have:
```sql
decision_type (ENUM with options)
next_stage (int 1-12, determined by decision)
```

### Polymorphic Relationships
- documents: Can attach to any stage table
- revisions: Track changes to any record type
- audit_logs: Log actions on any model

### Soft Delete Strategy
**Include soft_deletes:**
- users, tax_cases, all stage records, documents

**NO soft_deletes:**
- audit_logs, status_histories, workflow_histories (compliance)
- roles, entities, fiscal_years (reference data)

---

## 📊 Statistics

**Migrations by Group:**
- Master Data: 4 files (6 tables)
- Core: 1 file (1 table)
- Audit Process: 1 file (3 tables)
- Objection: 1 file (3 tables)
- Appeal & Supreme Court: 1 file (5 tables)
- Refund & KIAN: 1 file (3 tables)
- Workflow & Audit: 1 file (4 tables)
- Documents: 1 file (1 table)

**Totals:**
- 11 migration files
- 28 tables
- 100+ foreign keys
- 100+ indexes

---

## ✅ Ready for Execution

```bash
# Run all migrations
php artisan migrate

# Or rollback if needed
php artisan migrate:rollback

# Specific migration
php artisan migrate --path=database/migrations/2026_01_01_000001_create_roles_table.php
```

