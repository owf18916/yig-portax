# Database Schema Documentation

**Status:** Active & Current  
**Last Updated:** January 2026  
**Framework:** Laravel with Eloquent ORM

---

## Table of Contents
1. [Core Tables](#core-tables)
2. [Master Data Tables](#master-data-tables)
3. [Tax Case Management](#tax-case-management)
4. [Workflow & Audit](#workflow--audit)
5. [Relationships Overview](#relationships-overview)

---

## Core Tables

### `users`
User accounts with role and entity assignments.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| name | VARCHAR | User full name |
| email | VARCHAR | Unique, required |
| email_verified_at | TIMESTAMP | Optional verification |
| password | VARCHAR | Encrypted |
| remember_token | VARCHAR | Session token |
| entity_id | BIGINT FK | References entities |
| role_id | BIGINT FK | References roles |
| phone | VARCHAR | Contact number |
| position | VARCHAR | Job position |
| department | VARCHAR | Department name |
| last_login_at | TIMESTAMP | Last login time |
| is_active | BOOLEAN | Default: true |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Indexes:** entity_id, role_id, is_active  
**Soft Deletes:** Yes

---

### `roles`
Role definitions with permission management.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| code | VARCHAR | Unique role identifier |
| name | VARCHAR | Display name |
| description | TEXT | Role description |
| permissions | JSON | Array of permission strings |
| is_active | BOOLEAN | Default: true |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Indexes:** code

---

### `entities`
Business entities (companies, divisions) in the system.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| code | VARCHAR | Unique entity code |
| name | VARCHAR | Entity name |
| entity_type | ENUM | HOLDING \| AFFILIATE (default: AFFILIATE) |
| parent_entity_id | BIGINT FK | Self-referencing, optional |
| tax_id | VARCHAR | NPWP - unique |
| registration_number | VARCHAR | Government registration |
| business_address | TEXT | Full address |
| city, province, postal_code, country | VARCHAR | Address components |
| phone, fax, email | VARCHAR | Contact information |
| industry_code, industry_name | VARCHAR | Business classification |
| annual_revenue | DECIMAL(20,2) | Yearly revenue |
| employee_count | INT | Number of employees |
| business_status | ENUM | ACTIVE \| INACTIVE \| SUSPENDED |
| established_date | DATE | Business founding date |
| is_active | BOOLEAN | Default: true |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Indexes:** code, tax_id, entity_type  
**Soft Deletes:** Yes  
**Foreign Key:** parent_entity_id (self-referencing, onDelete: restrict)

---

## Master Data Tables

### `fiscal_years`
Tax fiscal year definitions.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| year | YEAR | Unique year |
| start_date, end_date | DATE | Year period |
| is_active | BOOLEAN | Default: true |
| is_closed | BOOLEAN | Default: false |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Indexes:** year

---

### `periods`
Monthly/period subdivisions within fiscal years.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| fiscal_year_id | BIGINT FK | References fiscal_years |
| period_code | VARCHAR | Format: YYYY-MM |
| year, month | INT | Year and month (1-12) |
| start_date, end_date | DATE | Period range |
| is_closed | BOOLEAN | Default: false |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Indexes:** year, (fiscal_year_id, month)  
**Unique:** (fiscal_year_id, period_code)

---

### `currencies`
Currency definitions and exchange rates.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| code | VARCHAR | Unique code (e.g., IDR, USD) |
| name | VARCHAR | Currency name |
| symbol | VARCHAR | Display symbol |
| decimal_places | INT | Default: 2 |
| exchange_rate | DECIMAL(18,2) | Default: 1.00 |
| last_updated_at | TIMESTAMP | Last rate update |
| is_active | BOOLEAN | Default: true |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Indexes:** code

---

### `case_statuses`
Predefined case status options for workflow.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| code | VARCHAR | Unique status code |
| name | VARCHAR | Display name |
| description | TEXT | Status description |
| stage_number | INT | Associated workflow stage |
| category | ENUM | active \| terminal |
| color | VARCHAR | UI color code |
| sort_order | INT | Display ordering |
| is_active | BOOLEAN | Default: true |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Indexes:** code, category

---

## Tax Case Management

### `tax_cases`
**MAIN TABLE** - Core tax case/dispute records.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| user_id | BIGINT FK | References users |
| entity_id | BIGINT FK | References entities |
| fiscal_year_id | BIGINT FK | References fiscal_years |
| period_id | BIGINT FK | References periods (optional) |
| currency_id | BIGINT FK | References currencies (default: IDR) |
| case_status_id | BIGINT FK | References case_statuses (default: OPEN) |
| case_number | VARCHAR | Unique case identifier |
| case_type | ENUM | CIT \| VAT |
| spt_number | VARCHAR | Tax return number (optional) |
| filing_date | DATE | SPT filing date (optional) |
| received_date | DATE | Date received (optional) |
| reported_amount | DECIMAL(20,2) | **Immutable** starting amount |
| disputed_amount | DECIMAL(20,2) | **Mutable** current value |
| vat_in_amount | DECIMAL(20,2) | VAT input amount (VAT cases) |
| vat_out_amount | DECIMAL(20,2) | VAT output amount (VAT cases) |
| current_stage | INT | Workflow stage (1-16) |
| is_completed | BOOLEAN | Default: false |
| completed_date | DATE | Completion date (optional) |
| description | TEXT | Case details |
| refund_amount | DECIMAL(20,2) | Refund/overpayment amount (optional) |
| refund_date | DATE | Refund date (optional) |
| revision_status | ENUM | CURRENT \| IN_REVISION \| REVISED |
| last_revision_id | BIGINT FK | References revisions |
| next_action | TEXT | Next action for Stage 1 |
| next_action_due_date | DATE | Due date for next action |
| status_comment | TEXT | Status comments |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Indexes:** (user_id, case_status_id), (entity_id, current_stage), (fiscal_year_id, case_type), case_number, current_stage, is_completed, revision_status  
**Soft Deletes:** Yes  
**Foreign Keys:** All with appropriate constraints

---

### `sp2_records` (Stage 2)
Surat Perintah Pemeriksaan (Audit Notice) records.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| tax_case_id | BIGINT FK | **UNIQUE** references tax_cases |
| sp2_number | VARCHAR | Document number |
| issue_date | DATE | Issued date |
| receipt_date | DATE | Received date |
| auditor_name | VARCHAR | Auditor contact |
| auditor_phone | VARCHAR | Auditor phone |
| auditor_email | VARCHAR | Auditor email |
| notes | TEXT | Additional notes |
| next_action | TEXT | Stage-specific next action |
| next_action_due_date | DATE | Due date |
| status_comment | TEXT | Status comment |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Soft Deletes:** Yes

---

### `sphp_records` (Stage 3)
Surat Pemberitahuan Hasil Pemeriksaan (Audit Results Notice).

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| tax_case_id | BIGINT FK | **UNIQUE** references tax_cases |
| sphp_number | VARCHAR | Document number |
| sphp_issue_date | DATE | Issue date |
| sphp_receipt_date | DATE | Receipt date |
| royalty_finding | DECIMAL(15,2) | Royalty audit findings (optional) |
| service_finding | DECIMAL(15,2) | Service audit findings (optional) |
| other_finding | DECIMAL(15,2) | Other findings (optional) |
| other_finding_notes | TEXT | Notes on other findings |
| next_action | TEXT | Stage-specific next action |
| next_action_due_date | DATE | Due date |
| status_comment | TEXT | Status comment |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Soft Deletes:** Yes

---

### `skp_records` (Stage 4)
Surat Ketetapan Pajak (Tax Assessment Notice) records.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| tax_case_id | BIGINT FK | **UNIQUE** references tax_cases |
| skp_number | VARCHAR | SKP number |
| issue_date | DATE | Issue date |
| receipt_date | DATE | Receipt date |
| skp_type | ENUM | LB (Lebih Bayar) \| NIHIL \| KB (Kurang Bayar) |
| skp_amount | DECIMAL(15,2) | Assessment amount |
| royalty_correction | DECIMAL(15,2) | Royalty adjustments |
| service_correction | DECIMAL(15,2) | Service adjustments |
| other_correction | DECIMAL(15,2) | Other adjustments |
| correction_notes | TEXT | Correction details |
| user_routing_choice | ENUM | refund \| objection |
| next_action | TEXT | Stage-specific next action |
| next_action_due_date | DATE | Due date |
| status_comment | TEXT | Status comment |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Indexes:** skp_type  
**Soft Deletes:** Yes  
**Decision Point:** Routes to Stage 5 (Objection) or Stage 13 (Refund)

---

### `objection_submissions` (Stage 5)
Taxpayer objection filing records.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| tax_case_id | BIGINT FK | **UNIQUE** references tax_cases |
| objection_number | VARCHAR | Filing reference number |
| submission_date | DATE | Filing date |
| objection_amount | DECIMAL(20,2) | Contested amount |
| objection_grounds | TEXT | Legal grounds for objection |
| supporting_evidence | TEXT | Evidence documentation |
| submitted_by | BIGINT FK | References users (submitter) |
| submitted_at | TIMESTAMP | Submission timestamp |
| approved_by | BIGINT FK | References users (approver) |
| approved_at | TIMESTAMP | Approval timestamp |
| status | ENUM | draft \| submitted \| approved \| rejected |
| notes | TEXT | Additional notes |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Soft Deletes:** Yes

---

### `spuh_records` (Stage 6)
Surat Permintaan Uraian (Tax Office Request for Details).

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| tax_case_id | BIGINT FK | **UNIQUE** references tax_cases |
| spuh_number | VARCHAR | Request number |
| issue_date | DATE | Request issue date |
| receipt_date | DATE | Request receipt date |
| reply_number | VARCHAR | Reply reference number |
| reply_date | DATE | Reply date |
| status | ENUM | draft \| submitted \| approved \| rejected |
| notes | TEXT | Notes |
| next_action | TEXT | Stage-specific next action |
| next_action_due_date | DATE | Due date |
| status_comment | TEXT | Status comment |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Soft Deletes:** Yes

---

### `objection_decisions` (Stage 7)
Tax office objection decision records.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| tax_case_id | BIGINT FK | **UNIQUE** references tax_cases |
| decision_number | VARCHAR | Decision reference |
| decision_date | DATE | Decision date |
| decision_type | ENUM | granted \| partially_granted \| rejected |
| decision_amount | DECIMAL(20,2) | Decision amount (optional) |
| decision_notes | TEXT | Decision details |
| next_stage | INT | Routes to Stage 8 (Appeal) or Stage 12 (Refund) |
| submitted_by | BIGINT FK | References users |
| submitted_at | TIMESTAMP | Submission time |
| approved_by | BIGINT FK | References users |
| approved_at | TIMESTAMP | Approval time |
| status | ENUM | draft \| submitted \| approved \| rejected |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Soft Deletes:** Yes

---

### `appeal_submissions` (Stage 8)
Appeal submission to court/higher authority.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| tax_case_id | BIGINT FK | **UNIQUE** references tax_cases |
| appeal_number | VARCHAR | Appeal reference number |
| dispute_number | VARCHAR | Dispute case number (optional) |
| submission_date | DATE | Appeal submission date |
| appeal_amount | DECIMAL(20,2) | Appeal amount |
| appeal_grounds | TEXT | Legal arguments |
| submitted_by | BIGINT FK | References users |
| submitted_at | TIMESTAMP | Submission time |
| approved_by | BIGINT FK | References users |
| approved_at | TIMESTAMP | Approval time |
| status | ENUM | draft \| submitted \| approved \| rejected |
| notes | TEXT | Notes |
| next_action | TEXT | Stage-specific next action |
| next_action_due_date | DATE | Due date |
| status_comment | TEXT | Status comment |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Soft Deletes:** Yes

---

### `appeal_explanation_requests` (Stage 9)
Court request for explanation during appeal process.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| tax_case_id | BIGINT FK | **UNIQUE** references tax_cases |
| request_number | VARCHAR | Request number |
| request_issue_date | DATE | Request issue date |
| request_receipt_date | DATE | Request received date |
| explanation_letter_number | VARCHAR | Explanation letter reference |
| explanation_submission_date | DATE | Explanation submitted date |
| status | ENUM | draft \| submitted \| approved \| rejected |
| notes | TEXT | Notes |
| next_action | TEXT | Stage-specific next action |
| next_action_due_date | DATE | Due date |
| status_comment | TEXT | Status comment |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Soft Deletes:** Yes

---

### `appeal_decisions` (Stage 10)
Court appeal decision/Keputusan Banding.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| tax_case_id | BIGINT FK | **UNIQUE** references tax_cases |
| keputusan_banding_number | VARCHAR | Decision number |
| keputusan_banding_date | DATE | Decision date |
| keputusan_banding | ENUM | dikabulkan \| dikabulkan_sebagian \| ditolak |
| keputusan_banding_amount | DECIMAL(20,2) | Decision amount |
| keputusan_banding_notes | TEXT | Decision notes |
| user_routing_choice | ENUM | refund \| supreme_court |
| decision_number | VARCHAR | Legacy field |
| decision_date | DATE | Legacy field |
| decision_type | ENUM | granted \| partially_granted \| rejected \| skp_kb |
| decision_amount | DECIMAL(20,2) | Legacy field |
| decision_notes | TEXT | Legacy field |
| next_stage | INT | Routes to Stage 11 (Supreme Court) or Stage 13 (Refund) |
| submitted_by | BIGINT FK | References users |
| submitted_at | TIMESTAMP | Submission time |
| approved_by | BIGINT FK | References users |
| approved_at | TIMESTAMP | Approval time |
| status | ENUM | draft \| submitted \| approved \| rejected |
| notes | TEXT | Notes |
| next_action | TEXT | Stage-specific next action |
| next_action_due_date | DATE | Due date |
| status_comment | TEXT | Status comment |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Soft Deletes:** Yes  
**Decision Point:** Routes to Stage 11 or Stage 13 based on user choice

---

### `supreme_court_submissions` (Stage 11)
Supreme court cassation/review submission (Peninjauan Kembali).

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| tax_case_id | BIGINT FK | **UNIQUE** references tax_cases |
| submission_number | VARCHAR | Submission reference |
| submission_date | DATE | Submission date |
| submission_amount | DECIMAL(20,2) | Amount in submission |
| legal_basis | TEXT | Legal arguments |
| review_type | ENUM | cassation \| review (default: cassation) |
| supreme_court_letter_number | VARCHAR | Official letter number |
| review_amount | DECIMAL(15,0) | Review amount |
| submitted_by | BIGINT FK | References users |
| submitted_at | TIMESTAMP | Submission time |
| approved_by | BIGINT FK | References users |
| approved_at | TIMESTAMP | Approval time |
| status | ENUM | draft \| submitted \| approved \| rejected |
| notes | TEXT | Notes |
| next_action | TEXT | Stage-specific next action |
| next_action_due_date | DATE | Due date |
| status_comment | TEXT | Status comment |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Soft Deletes:** Yes

---

### `supreme_court_decisions` (Stage 12)
Supreme court final decision (Keputusan Mahkamah Agung).

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| tax_case_id | BIGINT FK | **UNIQUE** references tax_cases |
| decision_number | VARCHAR | Decision number |
| decision_date | DATE | Decision date |
| decision_type | ENUM | granted \| partially_granted \| rejected |
| decision_amount | DECIMAL(20,2) | Decision amount |
| decision_notes | TEXT | Decision details |
| next_action | ENUM | refund \| kian |
| submitted_by | BIGINT FK | References users |
| submitted_at | TIMESTAMP | Submission time |
| approved_by | BIGINT FK | References users |
| approved_at | TIMESTAMP | Approval time |
| status | ENUM | draft \| submitted \| approved \| rejected |
| notes | TEXT | Notes |
| next_action_due_date | DATE | Due date (from stage table) |
| status_comment | TEXT | Status comment (from stage table) |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Soft Deletes:** Yes  
**Decision Point:** Routes to Stage 13 (Refund) or Stage 14/16 (KIAN)

---

### `refund_processes` (Stage 13)
Tax refund/overpayment processing.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| tax_case_id | BIGINT FK | **UNIQUE** references tax_cases |
| refund_number | VARCHAR | Refund reference number |
| refund_amount | DECIMAL(20,2) | Refund amount |
| refund_method | ENUM | bank_transfer \| check \| credit |
| refund_status | ENUM | pending \| approved \| processed \| completed \| rejected |
| approved_date | DATE | Approval date |
| processed_date | DATE | Processing date |
| submitted_by | BIGINT FK | References users |
| submitted_at | TIMESTAMP | Submission time |
| approved_by | BIGINT FK | References users |
| approved_at | TIMESTAMP | Approval time |
| status | ENUM | draft \| submitted \| approved \| rejected |
| notes | TEXT | Notes |
| next_action | TEXT | Stage-specific next action |
| next_action_due_date | DATE | Due date |
| status_comment | TEXT | Status comment |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Indexes:** refund_status  
**Soft Deletes:** Yes

---

### `bank_transfer_requests`
Bank transfer execution for refunds.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| refund_process_id | BIGINT FK | References refund_processes |
| request_number | VARCHAR | Transfer request reference |
| instruction_number | VARCHAR | Bank instruction number |
| bank_code | VARCHAR | Bank identifier |
| bank_name | VARCHAR | Bank name |
| account_number | VARCHAR | Recipient account |
| account_holder | VARCHAR | Account holder name |
| transfer_amount | DECIMAL(20,2) | Transfer amount |
| transfer_date | DATE | Transfer execution date |
| processed_date | DATE | Bank processing date |
| receipt_number | VARCHAR | Bank receipt/confirmation |
| transfer_status | ENUM | pending \| processing \| completed \| rejected \| cancelled |
| rejection_reason | TEXT | Reason if rejected |
| created_by | BIGINT FK | References users |
| notes | TEXT | Transfer notes |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Indexes:** (refund_process_id, transfer_status), transfer_date  
**Soft Deletes:** Yes

---

### `kian_submissions` (Stage 14/16)
Request for Court Review (Kemajuan Izin Aktivitas Negatif).

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| tax_case_id | BIGINT FK | **UNIQUE** references tax_cases |
| kian_number | VARCHAR | KIAN reference number |
| submission_date | DATE | Submission date |
| kian_amount | DECIMAL(20,2) | KIAN amount |
| kian_reason | TEXT | Reason for KIAN request |
| submitted_by | BIGINT FK | References users |
| submitted_at | TIMESTAMP | Submission time |
| approved_by | BIGINT FK | References users |
| approved_at | TIMESTAMP | Approval time |
| status | ENUM | draft \| submitted \| approved \| rejected |
| notes | TEXT | Notes |
| next_action | TEXT | Stage-specific next action |
| next_action_due_date | DATE | Due date |
| status_comment | TEXT | Status comment |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Soft Deletes:** Yes

---

## Workflow & Audit

### `workflow_histories`
Audit trail of workflow actions and stage transitions.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| tax_case_id | BIGINT FK | References tax_cases |
| stage_id | INT | Current workflow stage |
| stage_from | INT | Previous stage (optional) |
| stage_to | INT | Next stage if different (optional) |
| action | ENUM | submitted \| approved \| routed \| skipped \| rejected |
| status | ENUM | draft \| submitted \| approved \| rejected \| completed |
| decision_point | VARCHAR | Decision point name (optional) |
| decision_value | VARCHAR | Decision value taken (optional) |
| user_id | BIGINT FK | References users (action performer) |
| notes | TEXT | Action notes |
| created_at, updated_at | TIMESTAMP | Action timestamp |

**Indexes:** (tax_case_id, stage_id, created_at), stage_id, stage_from, (tax_case_id, status)

---

### `status_histories`
Historical record of case status changes.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| tax_case_id | BIGINT FK | References tax_cases |
| old_status_id | BIGINT FK | References case_statuses (optional) |
| new_status_id | BIGINT FK | References case_statuses |
| changed_by | BIGINT FK | References users |
| reason | TEXT | Change reason (optional) |
| created_at | TIMESTAMP | Change timestamp |

**Indexes:** (tax_case_id, created_at), old_status_id, new_status_id

---

### `revisions`
Document/record revision request tracking for approval workflows.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| revisable_type | VARCHAR | Polymorphic type (Model class name) |
| revisable_id | BIGINT | Polymorphic ID |
| stage_code | INT | Associated workflow stage (optional) |
| revision_status | ENUM | requested \| approved \| rejected \| implemented |
| original_data | JSON | Original field values |
| revised_data | JSON | Final approved values |
| proposed_values | JSON | User proposed changes |
| proposed_document_changes | JSON | File additions/deletions (id based) |
| requested_by | BIGINT FK | References users (requester) |
| requested_at | TIMESTAMP | Request timestamp |
| reason | TEXT | Revision reason |
| approved_by | BIGINT FK | References users (approver) |
| approved_at | TIMESTAMP | Approval timestamp |
| rejection_reason | TEXT | Rejection reason (if rejected) |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Indexes:** (revisable_type, revisable_id), revision_status

---

### `audit_logs`
Complete audit trail of all model changes.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| auditable_type | VARCHAR | Model class name (polymorphic) |
| auditable_id | BIGINT | Model ID |
| user_id | BIGINT FK | References users (action performer) |
| action | ENUM | created \| updated \| deleted \| approved \| submitted \| rejected |
| model_name | VARCHAR | Model name for tracking |
| old_values | JSON | Previous field values |
| new_values | JSON | New field values |
| ip_address | VARCHAR | Source IP |
| user_agent | VARCHAR | Browser/client info |
| performed_at | TIMESTAMP | Action timestamp |

**Indexes:** (auditable_type, auditable_id), performed_at, user_id

---

## Documents & Announcements

### `documents`
Polymorphic document/file storage with versioning.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| documentable_type | VARCHAR | Polymorphic model type (NOT NULL) |
| documentable_id | BIGINT | Polymorphic model ID (NOT NULL) |
| tax_case_id | BIGINT FK | References tax_cases (for easy filtering) |
| document_type | VARCHAR | Classification (e.g., "SKP", "Objection") |
| stage_code | VARCHAR | Associated workflow stage code |
| original_filename | VARCHAR | User-provided filename |
| file_path | VARCHAR | Storage path |
| file_mime_type | VARCHAR | MIME type (optional) |
| file_size | BIGINT | Size in bytes (optional) |
| hash | VARCHAR | File hash for duplicate detection (optional) |
| description | TEXT | Document description |
| uploaded_by | BIGINT FK | References users |
| uploaded_at | DATETIME | Upload timestamp |
| version | INT | Version number (default: 1) |
| previous_version_id | BIGINT FK | References documents (self-referencing) |
| status | ENUM | DRAFT \| ACTIVE \| ARCHIVED \| DELETED |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Indexes:** (documentable_type, documentable_id), tax_case_id, document_type, stage_code, uploaded_by, previous_version_id, status, uploaded_at  
**Soft Deletes:** Yes

---

### `announcements`
System announcements/notifications.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| title | VARCHAR | Announcement title |
| content | TEXT | Full content |
| type | ENUM | info \| success \| warning \| error (default: info) |
| is_active | BOOLEAN | Display flag (default: true) |
| published_at | TIMESTAMP | Publication time |
| expires_at | TIMESTAMP | Expiration time (optional) |
| created_by | BIGINT FK | References users |
| updated_by | BIGINT FK | References users |
| deleted_at | TIMESTAMP | Soft delete |
| created_at, updated_at | TIMESTAMP | Audit timestamps |

**Soft Deletes:** Yes

---

## Infrastructure Tables

### `cache`
Application cache key-value storage.

| Column | Type | Details |
|--------|------|---------|
| key | VARCHAR | Primary key |
| value | MEDIUMTEXT | Cached value |
| expiration | INT | Expiration timestamp |

---

### `cache_locks`
Distributed cache locks.

| Column | Type | Details |
|--------|------|---------|
| key | VARCHAR | Primary key |
| owner | VARCHAR | Lock owner identifier |
| expiration | INT | Expiration timestamp |

---

### `jobs`
Job queue records.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| queue | VARCHAR | Queue name |
| payload | LONGTEXT | Job data |
| attempts | TINYINT | Attempt count |
| reserved_at | INT | Reservation timestamp |
| available_at | INT | Available timestamp |
| created_at | INT | Creation timestamp |

**Indexes:** queue

---

### `job_batches`
Batch job grouping for coordinated processing.

| Column | Type | Details |
|--------|------|---------|
| id | VARCHAR | Primary Key |
| name | VARCHAR | Batch name |
| total_jobs | INT | Total jobs in batch |
| pending_jobs | INT | Pending count |
| failed_jobs | INT | Failed count |
| failed_job_ids | LONGTEXT | Failed job IDs |
| options | MEDIUMTEXT | Batch options |
| cancelled_at | INT | Cancellation timestamp |
| created_at | INT | Creation timestamp |
| finished_at | INT | Completion timestamp |

---

### `failed_jobs`
Failed job history and error tracking.

| Column | Type | Details |
|--------|------|---------|
| id | BIGINT | Primary Key |
| uuid | VARCHAR | Unique job identifier |
| connection | TEXT | Queue connection name |
| queue | TEXT | Queue name |
| payload | LONGTEXT | Job payload |
| exception | LONGTEXT | Exception details |
| failed_at | TIMESTAMP | Failure timestamp |

**Indexes:** uuid

---

### `password_reset_tokens`
Password reset token storage.

| Column | Type | Details |
|--------|------|---------|
| email | VARCHAR | Primary key (user email) |
| token | VARCHAR | Reset token |
| created_at | TIMESTAMP | Token creation time |

---

### `sessions`
User session management.

| Column | Type | Details |
|--------|------|---------|
| id | VARCHAR | Primary key (session ID) |
| user_id | BIGINT FK | References users (nullable) |
| ip_address | VARCHAR | Client IP (max 45 chars) |
| user_agent | TEXT | Browser/client info |
| payload | LONGTEXT | Session data |
| last_activity | INT | Last activity timestamp |

**Indexes:** user_id, last_activity

---

## Relationships Overview

### Core Entity Relationships
```
users
├── belongs_to: entities (entity_id)
├── belongs_to: roles (role_id)
└── has_many: tax_cases, documents, audit_logs, etc.

entities
├── has_many: users
├── has_many: tax_cases
└── self_reference: parent_entity_id (hierarchical)

roles
└── has_many: users
```

### Tax Case Workflow Chain
```
tax_cases (Stage 1: SPT Filing)
├── has_one: sp2_records (Stage 2)
├── has_one: sphp_records (Stage 3)
├── has_one: skp_records (Stage 4)
│   └── decision_point: refund or objection
├── has_one: objection_submissions (Stage 5)
├── has_one: spuh_records (Stage 6)
├── has_one: objection_decisions (Stage 7)
│   └── decision_point: appeal or refund
├── has_one: appeal_submissions (Stage 8)
├── has_one: appeal_explanation_requests (Stage 9)
├── has_one: appeal_decisions (Stage 10)
│   └── decision_point: supreme_court or refund
├── has_one: supreme_court_submissions (Stage 11)
├── has_one: supreme_court_decisions (Stage 12)
│   └── decision_point: refund or kian
├── has_one: refund_processes (Stage 13)
│   └── has_many: bank_transfer_requests
└── has_one: kian_submissions (Stage 14/16)
```

### Audit & Workflow
```
tax_cases
├── has_many: workflow_histories
├── has_many: status_histories
├── has_many: revisions
└── has_many: documents (polymorphic)
```

---

## Key Business Rules

### Status Management
- **Soft Deletes:** All transactional tables use soft deletes (deleted_at)
- **Workflow Stages:** Cases progress through 1-16 stages, some with decision points
- **Decision Points:** Certain stages route cases to different next stages based on outcomes

### Amounts
- `reported_amount` in tax_cases is **immutable** - represents original amount
- `disputed_amount` is **mutable** - changes throughout workflow
- All financial amounts use `DECIMAL(20,2)` for precision

### Unique Constraints
- Each tax_case can have **only ONE** record per stage (unique constraints on tax_case_id)
- Exception: bank_transfer_requests can have multiple per refund_process

### Revision Tracking
- Uses polymorphic `revisions` table for flexible revision management
- Tracks requested → approved/rejected → implemented flow
- Stores original, proposed, and revised values as JSON

### Document Versioning
- Documents support versioning via `version` number
- Previous versions linked via `previous_version_id`
- Status controls visibility: ACTIVE, ARCHIVED, DELETED

---

## Notes
- All timestamps use `created_at` and `updated_at` (except specific audit tables)
- Foreign keys generally use `restrict` (prevent accidental deletions) or `cascade` for proper cleanup
- Indexes optimized for common queries on case_id, status, user_id, and timestamps
- JSON fields used for flexible data: permissions, file changes, audit values
- Polymorphic relationships enable flexible audit and document storage

