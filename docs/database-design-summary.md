# PorTax Database Design - FINAL SUMMARY
## Complete Database Schema for Tax Case Management System

**Project:** PorTax - Tax Case Management System  
**Database:** MySQL 8.0+  
**Framework:** Laravel (with Livewire)  
**Date:** 2024-11-23  
**Status:** ✅ COMPLETED

---

## 📊 DATABASE STATISTICS

- **Total Tables:** 26 tables
- **Total SQL Files:** 10 files
- **Total Migrations:** 26 migration files
- **Total Indexes:** 80+ indexes (single + composite)
- **Estimated Rows (10 years):** ~100,000+ records

---

## 🎯 KEY DESIGN PRINCIPLES

### 1. **Simplicity & Normalization**
- ✅ Proper 3NF normalization
- ✅ No unnecessary lookup tables (ENUMs for fixed values)
- ✅ Merged 1:1 relationships (spt_filings → tax_cases)

### 2. **1:1 Relationship Pattern**
- ✅ All stages connect directly to `tax_case_id`
- ✅ No cross-stage foreign keys
- ✅ Clean parent-child structure

### 3. **Performance Optimization**
- ✅ Strategic indexing (single + composite)
- ✅ Indexed foreign keys
- ✅ Indexed frequently queried fields

### 4. **Audit & Compliance**
- ✅ Complete audit trail (`audit_logs`)
- ✅ Status history tracking
- ✅ Revision management
- ✅ 10-year data retention ready

### 5. **Flexibility**
- ✅ Polymorphic relationships (documents, revisions, audit_logs)
- ✅ JSON fields for flexible data storage
- ✅ Soft deletes where appropriate

---

## 📁 FILE STRUCTURE

```
/outputs/
├── sql/
│   ├── 01_master_data.sql (7 tables)
│   ├── 02_core_transaction.sql (1 table)
│   ├── 03_audit_process.sql (3 tables)
│   ├── 04_objection_process.sql (3 tables)
│   ├── 05_appeal_supreme_court.sql (4 tables)
│   ├── 06_refund_kian.sql (3 tables)
│   ├── 07_workflow_audit.sql (3 tables)
│   ├── 08_documents.sql (1 table)
│   ├── 09_additional_indexes.sql (optimization)
│   └── 10_seed_data.sql (initial data)
│
├── migrations/
│   ├── 2024_01_01_000001 to 000007 (Master Data)
│   ├── 2024_01_02_000001 (Core Transaction)
│   ├── 2024_01_03_000002 to 000004 (Audit Process)
│   ├── 2024_01_04_000001 to 000003 (Objection)
│   ├── 2024_01_05_000001 to 000004 (Appeal & Supreme Court)
│   ├── 2024_01_06_000001 to 000003 (Refund & KIAN)
│   ├── 2024_01_07_000002 to 000004 (Workflow & Audit)
│   └── 2024_01_08_000002 (Documents)
│
└── seeders/
    └── DatabaseSeeder.php
```

---

## 🗂️ COMPLETE TABLE LIST

### **GROUP 1: MASTER DATA (7 tables)**

#### 1. `entities`
**Purpose:** Master data perusahaan (holding + 5 affiliates)  
**Key Fields:** code, name, type (HOLDING/AFFILIATE)  
**Relationships:** → users, tax_cases

#### 2. `roles`
**Purpose:** User role definitions  
**Key Fields:** code, name, permissions (JSON)  
**Relationships:** → users

#### 3. `users`
**Purpose:** System users (holding + affiliate users)  
**Key Fields:** entity_id, role_id, email, password  
**Relationships:** ← entities, roles

#### 4. `currencies`
**Purpose:** Currency master  
**Key Fields:** code (IDR, USD, EUR), exchange_rate_to_idr  
**Relationships:** → tax_cases

#### 5. `fiscal_years`
**Purpose:** Fiscal year master  
**Key Fields:** year, start_date, end_date, is_current  
**Relationships:** → periods, tax_cases

#### 6. `periods`
**Purpose:** Monthly reporting periods (YYYY-MM format)  
**Key Fields:** fiscal_year_id, period_code, is_closed  
**Relationships:** ← fiscal_years, → tax_cases  
**Note:** CIT uses 1 period/year, VAT uses 12 periods/year

#### 7. `case_statuses`
**Purpose:** Case status master  
**Key Fields:** code, name, category, sort_order, color  
**Relationships:** → tax_cases, status_histories

---

### **GROUP 2: CORE TRANSACTION (1 table)**

#### 8. `tax_cases`
**Purpose:** Main tax case table (parent of all stages)  
**Key Fields:**
- Basic: case_number, entity_id, fiscal_year_id, period_id, case_type (CIT/VAT)
- SPT: spt_number, filing_date, received_date
- Amounts: reported_amount (immutable), dispute_amount (mutable)
- VAT: vat_in_amount, vat_out_amount (nullable)
- Workflow: submitted_by, approved_by, approved_date  

**Relationships:** 1:1 with all stage tables  
**Note:** Merged with spt_filings for simplicity

---

### **GROUP 3: AUDIT PROCESS (3 tables)**

#### 9. `sp2_records`
**Purpose:** SP2 (Audit Notification) records  
**Key Fields:** tax_case_id, sp2_number, auditor info (denormalized)  
**Relationships:** 1:1 with tax_cases

#### 10. `sphp_records`
**Purpose:** SPHP (Audit Results) records  
**Key Fields:** tax_case_id, sphp_number, audit findings (royalty, service, other)  
**Relationships:** 1:1 with tax_cases

#### 11. `skp_records`
**Purpose:** SKP (Tax Assessment) records  
**Key Fields:** tax_case_id, skp_number, skp_type (ENUM: LB/NIHIL/KB), corrections  
**Relationships:** 1:1 with tax_cases  
**Note:** skp_type is ENUM (not lookup table)

---

### **GROUP 4: OBJECTION PROCESS (3 tables)**

#### 12. `objection_submissions`
**Purpose:** Objection letter submissions  
**Key Fields:** tax_case_id, objection_number, objection_amount  
**Relationships:** 1:1 with tax_cases

#### 13. `spuh_records`
**Purpose:** SPUH (Summon Letter) records  
**Key Fields:** tax_case_id, spuh_number, reply_letter_number  
**Relationships:** 1:1 with tax_cases

#### 14. `objection_decisions`
**Purpose:** Objection decisions  
**Key Fields:** tax_case_id, decision_number, decision_type (ENUM: GRANTED/PARTIALLY_GRANTED/REJECTED)  
**Relationships:** 1:1 with tax_cases  
**Note:** decision_type is ENUM (not lookup table)

---

### **GROUP 5: APPEAL & SUPREME COURT (4 tables)**

#### 15. `appeal_submissions`
**Purpose:** Appeal letter submissions  
**Key Fields:** tax_case_id, appeal_number, dispute_number, explanation requests  
**Relationships:** 1:1 with tax_cases

#### 16. `appeal_decisions`
**Purpose:** Appeal decisions  
**Key Fields:** tax_case_id, decision_number, decision_type (ENUM)  
**Relationships:** 1:1 with tax_cases

#### 17. `supreme_court_submissions`
**Purpose:** Supreme Court review submissions  
**Key Fields:** tax_case_id, submission_number, submission_amount  
**Relationships:** 1:1 with tax_cases

#### 18. `supreme_court_decisions`
**Purpose:** Supreme Court decisions  
**Key Fields:** tax_case_id, decision_number, decision_type (ENUM)  
**Relationships:** 1:1 with tax_cases

---

### **GROUP 6: REFUND & KIAN (3 tables)**

#### 19. `kian_submissions`
**Purpose:** KIAN (Internal Loss Report) submissions  
**Key Fields:** tax_case_id, kian_number, kian_amount, kian_reason  
**Relationships:** 1:1 with tax_cases  
**Note:** KIAN = dokumen internal untuk kerugian yang tidak bisa di-refund

#### 20. `refund_processes`
**Purpose:** Refund process tracking  
**Key Fields:** tax_case_id, refund_number, refund_amount, refund_status (ENUM)  
**Relationships:** 1:1 with tax_cases, 1:Many with bank_transfer_requests

#### 21. `bank_transfer_requests`
**Purpose:** Bank transfer request tracking  
**Key Fields:** refund_process_id, request_number, instruction_number, transfer_status (ENUM)  
**Relationships:** Many:1 with refund_processes  
**Note:** 1 refund can have multiple transfer requests

---

### **GROUP 7: WORKFLOW & AUDIT TRAIL (3 tables)**

#### 22. `revisions`
**Purpose:** Revision request tracking (polymorphic)  
**Key Fields:** revisable_type/id, revision_status, original_data (JSON), revised_data (JSON)  
**Relationships:** Polymorphic to any table  
**Note:** For approved data that needs revision

#### 23. `audit_logs`
**Purpose:** Complete audit trail (polymorphic)  
**Key Fields:** auditable_type/id, action_type, old_values (JSON), new_values (JSON), user_id, ip_address  
**Relationships:** Polymorphic to any table  
**Note:** Automatic logging for compliance

#### 24. `status_histories`
**Purpose:** Tax case status change history  
**Key Fields:** tax_case_id, old_status_id, new_status_id, changed_by, changed_at  
**Relationships:** → tax_cases, case_statuses

---

### **GROUP 8: DOCUMENT MANAGEMENT (1 table)**

#### 25. `documents`
**Purpose:** Document storage tracking (polymorphic)  
**Key Fields:** documentable_type/id, tax_case_id, document_type (VARCHAR), stage_code, file_path  
**File Path:** `/nas/portax/{entity}/{year}/{case_type}/{case_id}/{stage}/{filename}`  
**Relationships:** Polymorphic to any table  
**Note:** document_type managed in app (no lookup table)

---

## 🔑 KEY DESIGN DECISIONS & RATIONALE

### ✅ **Decision 1: Merge spt_filings into tax_cases**
**Rationale:** 1:1 relationship, no need for separate table. Simplifies schema and queries.

### ✅ **Decision 2: All stages 1:1 with tax_cases**
**Rationale:** Clean structure, no cross-stage FK. Easy to query all stages by tax_case_id.

### ✅ **Decision 3: Use ENUM for fixed values (skp_type, decision_type)**
**Rationale:** Values never change. ENUM is faster than JOIN to lookup tables.

### ✅ **Decision 4: Remove approvals table**
**Rationale:** Redundant. Each stage table has approved_by/approved_date fields.

### ✅ **Decision 5: Remove document_types table**
**Rationale:** Keep it simple. Document types managed in application constants.

### ✅ **Decision 6: Denormalize auditor info in sp2_records**
**Rationale:** External data we can't control. No need for master table.

### ✅ **Decision 7: Separate periods table**
**Rationale:** Proper normalization. Better indexing. Flexible period management.

### ✅ **Decision 8: Keep both audit_logs and revisions**
**Rationale:** Different purposes. audit_logs = technical audit. revisions = business workflow.

### ✅ **Decision 9: Polymorphic for documents, revisions, audit_logs**
**Rationale:** Flexible. Can attach to any record type without creating multiple tables.

### ✅ **Decision 10: reported_amount vs dispute_amount**
**Rationale:** reported_amount = immutable starting point. dispute_amount = mutable current value.

---

## 📈 PERFORMANCE CONSIDERATIONS

### **Indexing Strategy:**
1. ✅ All foreign keys indexed
2. ✅ Frequently queried fields indexed (dates, statuses, types)
3. ✅ Composite indexes for common query patterns
4. ✅ Polymorphic relationships indexed
5. ✅ Additional performance indexes in Group 9

### **Query Optimization:**
- Use `tax_case_id` for joining all stages (single FK)
- Status timeline via `status_histories` (avoid complex JOINs)
- Document queries optimized with composite indexes
- Audit trail queries indexed by type + date

### **Scalability:**
- 100 cases/year × 10 years = 1,000 tax_cases
- Each case can have up to 8 stage records = ~8,000 stage records
- Documents: 1-15 per case = ~15,000 documents
- Audit logs: High volume, indexed by date for archiving
- Total estimated: ~100,000 rows over 10 years (manageable)

---

## 🚀 DEPLOYMENT INSTRUCTIONS

### **Step 1: Create Database**
```sql
CREATE DATABASE portax CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### **Step 2: Execute SQL Scripts (in order)**
```bash
mysql portax < sql/01_master_data.sql
mysql portax < sql/02_core_transaction.sql
mysql portax < sql/03_audit_process.sql
mysql portax < sql/04_objection_process.sql
mysql portax < sql/05_appeal_supreme_court.sql
mysql portax < sql/06_refund_kian.sql
mysql portax < sql/07_workflow_audit.sql
mysql portax < sql/08_documents.sql
mysql portax < sql/09_additional_indexes.sql
mysql portax < sql/10_seed_data.sql
```

### **Step 3: Laravel Migrations (alternative)**
```bash
php artisan migrate
php artisan db:seed
```

---

## ⚠️ IMPORTANT NOTES

### **Security:**
- ✅ Change default passwords in production
- ✅ Implement proper authentication/authorization
- ✅ Encrypt sensitive data if required
- ✅ Regular security audits

### **Maintenance:**
- ✅ Monitor query performance with EXPLAIN
- ✅ Archive old audit_logs periodically
- ✅ Update exchange rates regularly
- ✅ Review indexes based on actual usage patterns
- ✅ Backup strategy for 10-year retention

### **Data Integrity:**
- ✅ Approved data immutable (revision process required)
- ✅ Cascade deletes on tax_cases (all stages deleted)
- ✅ Soft deletes on tax_cases and documents
- ✅ Foreign key constraints enforced

---

## 📚 ADDITIONAL RESOURCES

### **Entity Relationship Diagram:**
- View complete ERD: `/outputs/portax-erd-viewer.html`
- Interactive diagram with all 26 tables and relationships

### **Revision History:**
- View changes: `/outputs/REVISION_SUMMARY.md`
- All design decisions documented

---

## ✅ COMPLETION CHECKLIST

- [x] Database schema designed (26 tables)
- [x] SQL scripts created (10 files)
- [x] Laravel migrations created (26 files)
- [x] Indexes optimized (80+ indexes)
- [x] Seed data prepared
- [x] ERD diagram created
- [x] Documentation complete
- [ ] Code review
- [ ] Testing
- [ ] Production deployment

---

**Database Design Status:** ✅ COMPLETED  
**Ready for Implementation:** ✅ YES  
**Estimated Implementation Time:** 4-6 weeks for full application

---

*End of Database Design Documentation*
