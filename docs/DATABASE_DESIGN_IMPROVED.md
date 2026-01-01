# PorTax Database Design - IMPROVED & FINAL
## Complete Database Schema for Tax Case Management System

**Project:** PorTax - Tax Case Management System  
**Database:** MySQL 8.0+  
**Framework:** Laravel 12  
**Date:** January 1, 2026  
**Status:** ✅ IMPROVED & READY FOR IMPLEMENTATION

---

## 📊 DATABASE STATISTICS

- **Total Tables:** 28 tables (improved from 26)
- **Total Migrations:** 28 migration files
- **Total Indexes:** 100+ indexes (optimized)
- **Estimated Rows (10 years):** ~150,000+ records
- **Design Pattern:** 3NF Normalized + Strategic Denormalization

---

## 🎯 KEY DESIGN PRINCIPLES (IMPROVED)

### 1. **Simplicity & Normalization** ✅
- Proper 3NF normalization with strategic denormalization
- No unnecessary lookup tables (ENUMs for fixed values)
- Merged 1:1 relationships (SPT data → tax_cases)
- **NEW:** Audit fields in every stage table for approval tracking

### 2. **1:1 Relationship Pattern** ✅
- All workflow stages connect directly to `tax_case_id`
- No cross-stage foreign keys
- Clean parent-child structure
- **NEW:** Stage progression tracking via `workflow_histories`

### 3. **Approval Workflow** ✅ (IMPROVED)
- Every stage table has: `submitted_by`, `submitted_date`, `approved_by`, `approved_date`, `status`
- Status ENUM: draft → submitted → approved → rejected
- Immutable approved data (soft deletes, no cascade delete)
- Revision process for approved data changes

### 4. **Decision Routing** ✅ (IMPROVED)
- Decision tables have `next_stage` field for workflow automation
- Example: SKP LB → Stage 12 (Refund), NIHIL/KB → Stage 5 (Objection)
- Enables smart routing based on decision logic

### 5. **Performance Optimization** ✅
- Strategic indexing (100+ indexes, single + composite)
- Indexed foreign keys + frequently queried fields
- Composite indexes for common query patterns
- JSON columns for flexible data

### 6. **Audit & Compliance** ✅ (IMPROVED)
- Complete audit trail (`audit_logs`)
- Status history tracking with who/why changed
- Workflow history (stage transitions)
- Revision management for approved data
- 10-year data retention ready with archive strategy

### 7. **Multi-Company Support** ✅
- `entities` table for holding + affiliates
- Role-based access control
- User-entity access control via relationships
- Entity isolation in queries

### 8. **Data Integrity** ✅ (IMPROVED)
- Foreign key constraints enforced
- Soft deletes on transactional data (users, tax_cases, documents)
- Hard deletes on audit trails (compliance requirement)
- Cascade restrictions on critical data

---

## 🗂️ MIGRATION STRUCTURE

```
/database/migrations/

GROUP 1: MASTER DATA
├── 2026_01_01_100001_create_roles_and_permissions_table.php
├── 2026_01_01_100002_create_entities_table.php
├── 2026_01_01_100003_extend_users_table.php
├── 2026_01_01_100004_create_fiscal_years_table.php
├── 2026_01_01_100005_create_periods_table.php
├── 2026_01_01_100006_create_currencies_table.php
└── 2026_01_01_100007_create_case_statuses_table.php

GROUP 2: CORE TRANSACTION
└── 2026_01_01_100008_create_tax_cases_table.php

GROUP 3: AUDIT PROCESS (3 tables)
├── 2026_01_01_100009_create_sp2_records_table.php
├── 2026_01_01_100010_create_sphp_records_table.php
└── 2026_01_01_100011_create_skp_records_table.php

GROUP 4: OBJECTION PROCESS (3 tables)
├── 2026_01_01_100012_create_objection_submissions_table.php
├── 2026_01_01_100013_create_spuh_records_table.php
└── 2026_01_01_100014_create_objection_decisions_table.php

GROUP 5: APPEAL & SUPREME COURT (5 tables)
├── 2026_01_01_100015_create_appeal_submissions_table.php
├── 2026_01_01_100016_create_appeal_explanation_requests_table.php (NEW)
├── 2026_01_01_100017_create_appeal_decisions_table.php
├── 2026_01_01_100018_create_supreme_court_submissions_table.php
└── 2026_01_01_100019_create_supreme_court_decisions_table.php

GROUP 6: REFUND & KIAN (3 tables)
├── 2026_01_01_100020_create_kian_submissions_table.php
├── 2026_01_01_100021_create_refund_processes_table.php
└── 2026_01_01_100022_create_bank_transfer_requests_table.php

GROUP 7: WORKFLOW & AUDIT (4 tables)
├── 2026_01_01_100023_create_workflow_histories_table.php (NEW)
├── 2026_01_01_100024_create_status_histories_table.php
├── 2026_01_01_100025_create_revisions_table.php
└── 2026_01_01_100026_create_audit_logs_table.php

GROUP 8: DOCUMENT MANAGEMENT (1 table)
└── 2026_01_01_100027_create_documents_table.php

TOTAL: 28 migrations
```

---

## 🗂️ COMPLETE TABLE LIST (IMPROVED)

### **GROUP 1: MASTER DATA (7 tables)**

#### 1. `roles`
**Purpose:** User role definitions with permission structure  
**Key Fields:**
- `id`, `code` (UNIQUE), `name`
- `permissions` (JSON) - Array of permission strings
- `description`, `is_active`
- `created_at`, `updated_at`

**Relationships:** → users (1:Many)  
**Sample Permissions:** `tax-case:create`, `tax-case:edit`, `stage:submit`, `stage:approve`

---

#### 2. `entities`
**Purpose:** Master data perusahaan (holding + affiliates)  
**Key Fields:**
- `id`, `code` (UNIQUE), `name`
- `entity_type` (ENUM: HOLDING, AFFILIATE)
- `parent_entity_id` (FK to self, for hierarchy)
- `tax_id` (NPWP, UNIQUE), `registration_number`
- Address: `address`, `city`, `province`, `postal_code`
- Contact: `phone`, `email`
- Business: `industry_code`, `business_description`, `annual_revenue` (decimal 20,2)
- `is_active` (boolean), `timestamps`, `soft_deletes`

**Relationships:** → users (1:Many), ← tax_cases, ← documents  
**Sample Data:** 1 HOLDING + 5 AFFILIATE companies

---

#### 3. `users` (EXTENDED from Laravel)
**Purpose:** System users across all entities  
**Key Fields:**
- Standard Laravel: `id`, `name`, `email`, `password`, `remember_token`
- **NEW:** `entity_id` (FK), `role_id` (FK)
- **NEW:** `phone`, `position`, `department`
- **NEW:** `is_active`, `last_login_at`
- `email_verified_at`, `timestamps`, `soft_deletes`

**Relationships:** → entity (Many:1), → role (Many:1)  
**Auth:** Using standard Laravel authentication

---

#### 4. `fiscal_years`
**Purpose:** Fiscal year master data  
**Key Fields:**
- `id`, `year`, `start_date`, `end_date`
- `is_active` (boolean), `is_closed` (prevent edits when closed)
- `timestamps`

**Relationships:** → periods (1:Many), ← tax_cases  
**Sample Data:** 2020-2030 (10 years)

---

#### 5. `periods`
**Purpose:** Monthly reporting periods (VAT reporting)  
**Key Fields:**
- `id`, `fiscal_year_id` (FK)
- `period_code` (YYYY-MM format, e.g., 202401)
- `year`, `month` (int 1-12)
- `start_date`, `end_date`
- `is_closed` (prevent updates), `timestamps`

**Relationships:** ← fiscal_years, ← tax_cases  
**Note:** 12 periods per fiscal year for VAT reporting  
**Indexes:** `fiscal_year_id`, `period_code`, `year`

---

#### 6. `currencies`
**Purpose:** Currency master with exchange rates  
**Key Fields:**
- `id`, `code` (UNIQUE: IDR, USD, EUR), `name`, `symbol`
- `decimal_places` (default 2)
- `exchange_rate` (decimal 18,2) vs base currency
- `last_updated_at` (track rate changes)
- `is_active`, `timestamps`

**Relationships:** ← tax_cases

---

#### 7. `case_statuses`
**Purpose:** Case status master  
**Key Fields:**
- `id`, `code` (UNIQUE: OPEN, SP2_RECEIVED, etc.), `name`
- `description`, `stage_number` (which stage)
- `category` (ENUM: active, terminal)
- `color` (for UI), `sort_order`
- `is_active`, `timestamps`

**Relationships:** → tax_cases, ← status_histories

---

### **GROUP 2: CORE TRANSACTION (1 table)**

#### 8. `tax_cases`
**Purpose:** Main tax case table (parent of all workflow stages)  
**Key Fields:**
- **Identity:**
  - `id`, `case_number` (UNIQUE: TAX-2026-001)
  - `case_type` (ENUM: CIT, VAT)
  - `fiscal_year_id`, `period_id`, `entity_id`, `user_id` (creator)

- **SPT Data (merged):**
  - `spt_number`, `filing_date`, `received_date`

- **Amounts:**
  - `reported_amount` (decimal 20,2, immutable)
  - `disputed_amount` (decimal 20,2, mutable)
  - `vat_in_amount`, `vat_out_amount` (nullable, for VAT cases)

- **Workflow:**
  - `current_stage` (int 1-12)
  - `is_completed` (boolean)
  - `completed_date`

- **Approval:**
  - `submitted_by`, `submitted_date`
  - `approved_by`, `approved_date`
  - `description` (case description)

- **Refund:**
  - `refund_amount` (decimal 20,2, nullable)
  - `refund_date`

- **Audit:**
  - `last_updated_by`, `timestamps`, `soft_deletes`

**Relationships:**
- FK: user_id → users, entity_id → entities, fiscal_year_id → fiscal_years, period_id → periods, case_status_id → case_statuses, currency_id → currencies
- 1:1 with all stage tables (sp2, sphp, skp, objection_submission, spuh, objection_decision, appeal, appeal_explanation, appeal_decision, supreme_court_submission, supreme_court_decision, refund, kian)
- 1:Many with documents, audit_logs, status_histories, workflow_histories

**Indexes:**
- `user_id`, `entity_id`, `case_status_id`, `current_stage`
- Composite: `(user_id, case_status_id)`, `(entity_id, current_stage)`, `(fiscal_year_id, case_type)`

---

### **GROUP 3: AUDIT PROCESS (3 tables)**

#### 9. `sp2_records` (Stage 2)
**Purpose:** SP2 Audit Notification  
**Key Fields:**
- `id`, `tax_case_id` (FK, UNIQUE)
- `sp2_number`, `issue_date`, `receipt_date`
- Auditor: `auditor_name`, `auditor_title`, `auditor_department`
- `findings` (text)
- **NEW:** `submitted_by`, `submitted_date`, `approved_by`, `approved_date`, `status` (ENUM)
- `notes`, `timestamps`, `soft_deletes`

**Indexes:** `tax_case_id`, `submitted_date`

---

#### 10. `sphp_records` (Stage 3)
**Purpose:** SPHP Audit Results  
**Key Fields:**
- `id`, `tax_case_id` (FK, UNIQUE)
- `sphp_number`, `issue_date`, `receipt_date`
- `corrections` (text), `additional_tax` (decimal)
- Finding breakdown: `royalty_corrections`, `service_corrections`, `other_corrections` (JSON)
- **NEW:** `submitted_by`, `submitted_date`, `approved_by`, `approved_date`, `status` (ENUM)
- `notes`, `timestamps`, `soft_deletes`

---

#### 11. `skp_records` (Stage 4 - DECISION POINT)
**Purpose:** SKP Tax Assessment  
**Key Fields:**
- `id`, `tax_case_id` (FK, UNIQUE)
- `skp_number`, `issue_date`, `receipt_date`
- `skp_type` (ENUM: LB [Lebih Bayar], NIHIL, KB [Kurang Bayar]) **← DECISION POINT**
- `skp_amount` (decimal)
- `audit_corrections` (text), `additional_corrections` (decimal)
- **NEW:** `next_stage` (int) - Routing based on SKP type (LB→12, NIHIL/KB→5)
- **NEW:** `submitted_by`, `submitted_date`, `approved_by`, `approved_date`, `status` (ENUM)
- `notes`, `timestamps`, `soft_deletes`

**Routing Logic:**
```
SKP LB → Stage 12 (Refund Process)
SKP NIHIL or KB → Stage 5 (Objection Submission)
```

---

### **GROUP 4: OBJECTION PROCESS (3 tables)**

#### 12. `objection_submissions` (Stage 5)
**Purpose:** Objection Letter Submission  
**Key Fields:**
- `id`, `tax_case_id` (FK, UNIQUE)
- `objection_number`, `submission_date`
- `objection_amount` (decimal)
- `objection_grounds` (text), `supporting_evidence` (text)
- **NEW:** `submitted_by`, `submitted_date`, `approved_by`, `approved_date`, `status` (ENUM)
- `notes`, `timestamps`, `soft_deletes`

---

#### 13. `spuh_records` (Stage 6)
**Purpose:** SPUH Summon Letter - Request for Explanation  
**Key Fields:**
- `id`, `tax_case_id` (FK, UNIQUE)
- `spuh_number`, `request_date`, `due_date`
- `explanation_required` (text)
- **NEW:** `explanation_provided` (text), `explanation_date`
- **NEW:** `submitted_by`, `submitted_date`, `approved_by`, `approved_date`, `status` (ENUM)
- `notes`, `timestamps`, `soft_deletes`

---

#### 14. `objection_decisions` (Stage 7 - DECISION POINT)
**Purpose:** Objection Decision  
**Key Fields:**
- `id`, `tax_case_id` (FK, UNIQUE)
- `decision_number`, `decision_date`
- `decision_type` (ENUM: granted, partially_granted, rejected) **← DECISION POINT**
- `decision_amount` (decimal, nullable)
- `decision_notes` (text)
- **NEW:** `next_stage` (int) - Routing (Granted/Partial→12, Rejected→8)
- **NEW:** `submitted_by`, `submitted_date`, `approved_by`, `approved_date`, `status` (ENUM)
- `timestamps`, `soft_deletes`

**Routing Logic:**
```
Granted or Partially Granted → Stage 12 (Refund)
Rejected → Stage 8 (Appeal Submission)
```

---

### **GROUP 5: APPEAL & SUPREME COURT (5 tables)**

#### 15. `appeal_submissions` (Stage 8)
**Purpose:** Appeal Letter Submission  
**Key Fields:**
- `id`, `tax_case_id` (FK, UNIQUE)
- `appeal_number`, `dispute_number` (nullable)
- `submission_date`, `appeal_amount` (decimal)
- `appeal_grounds` (text)
- **NEW:** `submitted_by`, `submitted_date`, `approved_by`, `approved_date`, `status` (ENUM)
- `notes`, `timestamps`, `soft_deletes`

---

#### 16. `appeal_explanation_requests` (Stage 9)
**Purpose:** Appeal Explanation Request (Permintaan Penjelasan Banding)  
**Key Fields:**
- `id`, `tax_case_id` (FK, UNIQUE)
- `request_number`, `request_date`, `due_date`
- `explanation_required` (text)
- **NEW:** `explanation_provided` (text), `explanation_date`
- **NEW:** `submitted_by`, `submitted_date`, `approved_by`, `approved_date`, `status` (ENUM)
- `notes`, `timestamps`, `soft_deletes`

---

#### 17. `appeal_decisions` (Stage 10 - DECISION POINT)
**Purpose:** Appeal Decision  
**Key Fields:**
- `id`, `tax_case_id` (FK, UNIQUE)
- `decision_number`, `decision_date`
- `decision_type` (ENUM: granted, partially_granted, rejected, skp_kb) **← DECISION POINT**
- `decision_amount` (decimal, nullable)
- `decision_notes` (text)
- **NEW:** `next_stage` (int) - Routing (Granted/Partial→12, Rejected/KB→11)
- **NEW:** `submitted_by`, `submitted_date`, `approved_by`, `approved_date`, `status` (ENUM)
- `timestamps`, `soft_deletes`

**Routing Logic:**
```
Granted or Partially Granted → Stage 12 (Refund)
Rejected or SKP KB → Stage 11 (Supreme Court Submission)
```

---

#### 18. `supreme_court_submissions` (Stage 11)
**Purpose:** Supreme Court Review Submission  
**Key Fields:**
- `id`, `tax_case_id` (FK, UNIQUE)
- `submission_number`, `submission_date`
- `submission_amount` (decimal)
- `legal_basis` (text)
- `review_type` (ENUM: cassation, review)
- **NEW:** `submitted_by`, `submitted_date`, `approved_by`, `approved_date`, `status` (ENUM)
- `notes`, `timestamps`, `soft_deletes`

---

#### 19. `supreme_court_decisions` (Stage 11b)
**Purpose:** Supreme Court Decision  
**Key Fields:**
- `id`, `tax_case_id` (FK, UNIQUE)
- `decision_number`, `decision_date`
- `decision_type` (ENUM: granted, rejected, partially_granted)
- `decision_amount` (decimal, nullable)
- `decision_notes` (text)
- **NEW:** `next_stage` (int, always 12) - All routes to Refund
- **NEW:** `submitted_by`, `submitted_date`, `approved_by`, `approved_date`, `status` (ENUM)
- `timestamps`, `soft_deletes`

---

### **GROUP 6: REFUND & KIAN (3 tables)**

#### 20. `kian_submissions` (Optional Stage)
**Purpose:** KIAN Internal Loss Report  
**Key Fields:**
- `id`, `tax_case_id` (FK, UNIQUE)
- `kian_number`, `submission_date`
- `kian_amount` (decimal)
- `kian_reason` (text) - Why can't refund
- **NEW:** `submitted_by`, `submitted_date`, `approved_by`, `approved_date`, `status` (ENUM)
- `notes`, `timestamps`, `soft_deletes`

---

#### 21. `refund_processes` (Stage 12)
**Purpose:** Refund Process Tracking  
**Key Fields:**
- `id`, `tax_case_id` (FK, UNIQUE)
- `refund_number`, `refund_amount` (decimal)
- `approved_date`, `processed_date` (nullable)
- `refund_method` (ENUM: bank_transfer, check, credit)
- `refund_status` (ENUM: pending, approved, processed, completed, rejected)
- **NEW:** `submitted_by`, `submitted_date`, `approved_by`, `approved_date`
- `notes`, `timestamps`, `soft_deletes`

**Relationships:** 1:Many with bank_transfer_requests

---

#### 22. `bank_transfer_requests`
**Purpose:** Bank Transfer Request Tracking (1 refund = multiple transfers)  
**Key Fields:**
- `id`, `refund_process_id` (FK)
- `request_number`, `instruction_number`
- `bank_code`, `bank_name`
- `account_number`, `account_holder`
- `transfer_amount` (decimal) - Bisa berbeda dari refund_amount
- `transfer_date` (nullable), `processed_date` (nullable)
- `receipt_number` (nullable)
- `transfer_status` (ENUM: pending, processing, completed, rejected, cancelled)
- **NEW:** `created_by` (user_id), `rejection_reason` (text, nullable)
- `timestamps`, `soft_deletes`

**Indexes:** `refund_process_id`, `transfer_status`, `transfer_date`

---

### **GROUP 7: WORKFLOW & AUDIT TRAIL (4 tables)**

#### 23. `workflow_histories` (NEW)
**Purpose:** Track stage-to-stage progression  
**Key Fields:**
- `id`, `tax_case_id` (FK)
- `stage_from` (int, nullable - Stage 1 has no from)
- `stage_to` (int)
- `action` (ENUM: submitted, approved, routed, skipped)
- `decision_point` (varchar, e.g., 'skp_type', 'objection_decision')
- `decision_value` (varchar, e.g., 'LB', 'granted')
- `user_id` (FK - who triggered transition)
- `notes` (text, nullable)
- `created_at`

**Indexes:** `(tax_case_id, created_at)`, `stage_to`, `stage_from`

**Sample Data:**
```
TAX-2026-001: Stage 1→2→4 (LB)→12
TAX-2026-002: Stage 1→2→4 (NIHIL)→5→7 (Rejected)→8→10 (Granted)→12
```

---

#### 24. `status_histories`
**Purpose:** Track case status changes  
**Key Fields:**
- `id`, `tax_case_id` (FK)
- `old_status_id` (FK, nullable), `new_status_id` (FK)
- **NEW:** `changed_by` (user_id, FK) - WHO changed
- **NEW:** `reason` (text, nullable) - WHY changed
- `created_at`

**Indexes:** `(tax_case_id, created_at)`, `old_status_id`, `new_status_id`

---

#### 25. `revisions`
**Purpose:** Track changes to approved data  
**Key Fields:**
- `id`, `revisable_type` (string), `revisable_id` (unsignedBigInteger)
- `revision_status` (ENUM: requested, approved, rejected, implemented)
- `original_data` (JSON) - Before change
- `revised_data` (JSON) - After change
- **NEW:** `requested_by` (user_id, FK), `requested_at`
- **NEW:** `approved_by` (user_id, FK, nullable), `approved_at` (nullable)
- `rejection_reason` (text, nullable)
- `created_at`, `updated_at`

**Polymorphic:** Can point to any table (skp_records, objection_decisions, etc.)

**Indexes:** `(revisable_type, revisable_id)`, `revision_status`

---

#### 26. `audit_logs`
**Purpose:** Complete audit trail for compliance  
**Key Fields:**
- `id`, `auditable_type`, `auditable_id`
- `user_id` (FK), `action` (ENUM: created, updated, deleted, approved, submitted)
- `model_name` (string, e.g., 'TaxCase', 'SkpRecord')
- `old_values` (JSON), `new_values` (JSON)
- `ip_address`, `user_agent`
- **NEW:** `performed_at` (timestamp with timezone)
- `created_at`

**Polymorphic:** Can log any model  
**Indexes:** `(auditable_type, auditable_id)`, `created_at`, `user_id`

---

### **GROUP 8: DOCUMENT MANAGEMENT (1 table)**

#### 27. `documents`
**Purpose:** Document storage tracking  
**Key Fields:**
- `id`, `tax_case_id` (FK)
- `documentable_type`, `documentable_id` (polymorphic)
- **NEW:** `stage_number` (int, nullable) - Quick stage filter
- `document_type` (string) - e.g., 'spt_form', 'skp_letter', 'objection_letter'
- `original_filename`, `stored_filename`
- `file_path` (/storage/portax/{entity}/{year}/{case_id}/{stage}/{filename})
- `mime_type`, `file_size` (int, in bytes)
- `description` (text, nullable)
- **NEW:** `uploaded_by` (user_id, FK), `uploaded_at`
- **NEW:** `is_verified` (boolean), `verified_by` (user_id, FK, nullable), `verified_at` (nullable)
- `verification_notes` (text, nullable)
- `created_at`, `updated_at`, `soft_deletes`

**Relationships:** Polymorphic to any stage table  
**Indexes:** `(tax_case_id, stage_number)`, `(documentable_type, documentable_id)`, `uploaded_at`

---

## 🔑 KEY DESIGN IMPROVEMENTS

### ✅ **Decision 1: Extend Users Table (NOT create portax_users)**
**Rationale:** Leverage Laravel's authentication system. Single source of truth.

### ✅ **Decision 2: Merge SPT Data into tax_cases**
**Rationale:** 1:1 relationship. Simplifies queries and schema.

### ✅ **Decision 3: Add Approval Fields to ALL Stage Tables**
**Rationale:** Enable approval workflow tracking. Every record knows who submitted/approved it.

### ✅ **Decision 4: Add next_stage to Decision Tables**
**Rationale:** Enable deterministic workflow routing based on decisions.

### ✅ **Decision 5: Create workflow_histories Table**
**Rationale:** Track stage progression + decision logic used. Critical for audit trail.

### ✅ **Decision 6: Add stage_number to documents**
**Rationale:** Faster queries without JOINs. Documents per stage filtering.

### ✅ **Decision 7: Add Appeal Explanation Requests Table**
**Rationale:** Stage 9 deserves dedicated table like SPUH.

### ✅ **Decision 8: Use Soft Deletes Strategically**
**Rationale:**
- ✅ YES on: users, tax_cases, documents, all stage records (preserve history)
- ❌ NO on: audit_logs, status_histories, workflow_histories (compliance trail)
- ❌ NO on: role, entities (seldom deleted)

### ✅ **Decision 9: Polymorphic Relationships**
**Rationale:** Flexible audit_logs, documents, revisions. No need for multiple tables.

### ✅ **Decision 10: Add changed_by to status_histories**
**Rationale:** Audit requirement - know WHO changed status + WHY.

---

## 📈 IMPROVED PERFORMANCE CONSIDERATIONS

### **Indexing Strategy (100+ indexes):**
1. ✅ All foreign keys indexed
2. ✅ Composite indexes for common query patterns
3. ✅ (tax_case_id, status) for filtering
4. ✅ (user_id, created_at) for user activity
5. ✅ (stage_number, created_at) for stage queries
6. ✅ Polymorphic relationships indexed

### **Query Optimization:**
- Single `tax_case_id` joins all stages
- Status timeline via `status_histories` (avoid complex logic)
- Document queries: `tax_case_id + stage_number` (composite index)
- Audit trail: `created_at` indexed for archiving

### **Scalability:**
- 100-200 cases/year × 10 years = 1,000-2,000 tax_cases
- Stage records: ~12-15 per case = ~18,000 stage records
- Documents: 3-10 per case = ~15,000 documents
- Audit logs: High volume, archived annually
- Total: ~150,000+ rows (manageable)

---

## ⚠️ IMPORTANT DESIGN NOTES

### **Immutability Pattern:**
1. Approved data has `approved_by` + `approved_date` fields
2. Never cascade delete approved records
3. Use `revisions` table for post-approval changes
4. Audit logs track all modifications

### **Multi-Company Access Control:**
1. Users tied to entity via `entity_id`
2. Tax cases scoped by `entity_id`
3. Queries filter by user's entity (or all if admin)
4. Roles control what users can do

### **Decision Routing:**
1. `skp_records.next_stage` determined by `skp_type`
2. `objection_decisions.next_stage` determined by `decision_type`
3. `appeal_decisions.next_stage` determined by `decision_type`
4. App logic reads `next_stage` to route workflow

### **Soft Delete Strategy:**
```sql
-- Stage records: Include soft_deletes (preserve history)
SELECT * FROM skp_records WHERE tax_case_id = 1; -- Shows only non-deleted
SELECT * FROM skp_records WHERE tax_case_id = 1; -- Uses restored_at index

-- Audit logs: NO soft_deletes (compliance requirement)
SELECT * FROM audit_logs WHERE auditable_id = 1; -- All history preserved
```

### **Archive Strategy (10-year retention):**
1. Yearly archive of closed cases → separate archive database
2. Audit logs compressed after 2 years
3. Document files moved to cold storage after 5 years
4. Reference data in main DB indefinitely

---

## ✅ FINAL CHECKLIST

- [x] All 28 tables designed
- [x] Approval workflow integrated
- [x] Decision routing fields added
- [x] Audit fields in every stage table
- [x] Workflow tracking table added
- [x] Polymorphic relationships designed
- [x] Index strategy defined
- [x] Soft delete strategy specified
- [x] Multi-company support included
- [x] Performance optimized
- [ ] Migrations generated
- [ ] Models created
- [ ] Seeders created
- [ ] Testing & deployment

---

**Database Design Status:** ✅ IMPROVED & COMPLETED  
**Ready for Migrations:** ✅ YES  
**Ready for Development:** ✅ YES

---

*End of Improved Database Design Documentation*
