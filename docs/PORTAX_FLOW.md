# PorTax System - Complete Workflow Documentation

**Project:** PorTax - Tax Case Management System  
**Document Type:** Business Process Flow  
**Date:** December 31, 2025  
**Version:** 1.0

---

## 📋 Table of Contents

1. [Overview](#overview)
2. [Case Number Format](#case-number-format)
3. [Initial Submission](#initial-submission)
4. [Audit Process Flow](#audit-process-flow)
5. [Objection Process Flow](#objection-process-flow)
6. [Appeal Process Flow](#appeal-process-flow)
7. [Supreme Court Review Flow](#supreme-court-review-flow)
8. [Refund Procedure](#refund-procedure)
9. [KIAN Procedure](#kian-procedure)
10. [Case Status Summary](#case-status-summary)
10. [Decision Logic Summary](#decision-logic-summary)

---

## 🎯 Overview

PorTax System mengelola seluruh proses sengketa pajak untuk Corporate Income Tax (CIT) dan Value Added Tax (VAT), mulai dari pelaporan SPT hingga penyelesaian akhir melalui refund atau KIAN.

**Supported Tax Types:**
- **CIT (Corporate Income Tax)** - Annual reporting
- **VAT (Value Added Tax)** - Monthly reporting

**Key Features:**
- Form Input & Supporting Documents Upload
- Multi-stage approval workflow
- Complete audit trail
- Refund tracking
- KIAN (internal loss) documentation

---

## � Case Number Format

### Format Specification

Each tax case is assigned a unique case number following this pattern:

```
JJYYMMC
```

Where:
- **JJ** (2 characters) - First 2 letters of company/entity name (uppercase)
- **YY** (2 digits) - Last 2 digits of fiscal year (e.g., 17 for 2017, 24 for 2024)
- **MM** (3 characters) - First 3 letters of reporting period month in English (Jan, Feb, Mar, Apr, May, Jun, Jul, Aug, Sep, Oct, Nov, Dec)
- **C** (1 character) - Case type code:
  - **V** = VAT (Value Added Tax)
  - **C** = CIT (Corporate Income Tax)

### Examples

| Entity Name | Fiscal Year | Period | Type | Case Number |
|-------------|-------------|--------|------|-------------|
| PT. Maju Jaya Abadi | 2024 | March | CIT | PT24MarC |
| CV. Teknologi Indonesia | 2024 | January | VAT | CV24JanV |
| PT. Global Trade | 2023 | June | CIT | PT23JunC |
| Toko Sentosa | 2024 | December | VAT | TO24DecV |

### Auto-Generation

The case number is **automatically generated** by the system when creating a new case based on:
1. Company code derived from first 2 letters of entity name
2. Fiscal year (last 2 digits)
3. Reporting period selected by user
4. Case type (CIT or VAT) selected by user

### Validation Rules

- Case numbers are **unique** per entity per fiscal year per period per type
- Case numbers are **case-insensitive** for entity code but stored in uppercase
- Period must be a valid calendar month
- Fiscal year must be a valid 4-digit year

---

## �🚀 Initial Submission

### Stage 1: SPT Filing

**Description:** Initial tax return submission

#### Input Fields:
- **Entitas** (Entity)
- **Periode** (Period)
- **Currency**
- **Nilai Sengketa** (Dispute Amount)
  - For CIT: Single amount
  - For VAT: PPN Masukan (VAT In) and PPN Keluaran (VAT Out)

#### Actions:
- **Filing a tax return** → Proceed to Case Open
- **Not filing a tax return** → Case Closed

#### Case Status After Action:
- ✅ Filing → **Case Status: OPEN**
- ❌ Not Filing → **Case Status: CLOSE**

#### System Actions:
- Form Input & Upload SPT
- Store supporting documents

---

## 🔍 Audit Process Flow

### Stage 2: SP2 (Surat Pemberitahuan Pemeriksaan)

**Description:** Audit notification received from tax authority

#### Input Fields:
- **Nomor SP2** (SP2 Number)
- **Tanggal Diterbitkan** (Issue Date)
- **Tanggal Diterima** (Receipt Date)
- **Auditor Name**
- **Auditor Phone**
- **Auditor Email**

#### Case Status:
**Case Status: SP2 RECEIVED**

#### System Actions:
- Input & Upload Supporting Docs (SP2)

---

### Stage 3: SPHP (Surat Pemberitahuan Hasil Pemeriksaan)

**Description:** Audit findings notification

#### Input Fields:
- **Nomor SPHP** (SPHP Number)
- **Tanggal Diterbitkan** (Issue Date)
- **Tanggal Diterima** (Receipt Date)
- **Nilai Temuan Audit** (Audit Findings):
  - Royalty
  - Service
  - Other

#### Case Status:
**Case Status: SPHP RECEIVED**

#### System Actions:
- Input & Upload Supporting Docs (SPHP)

---

### Stage 4: SKP (Surat Ketetapan Pajak)

**Description:** Tax assessment letter

#### Input Fields:
- **Nomor SKP** (SKP Number)
- **Tanggal Diterbitkan** (Issue Date)
- **Tanggal Diterima** (Receipt Date)
- **Jenis SKP** (SKP Type):
  - **SKP LB** (Lebih Bayar - Overpayment)
  - **NIHIL** (Zero)
  - **SKP KB** (Kurang Bayar - Underpayment)
- **Nilai SKP** (SKP Amount)
- **Nilai Koreksi Audit** (Audit Corrections):
  - Royalty
  - Service
  - Other
- **Catatan untuk koreksi Other** (Notes for Other corrections)

#### Case Status:
**Case Status: SKP RECEIVED**

#### Decision Point 1: Jenis SKP?

**Option A: SKP LB (Lebih Bayar)**
- Result: Accepted
- Proceed to: **Refund Procedure**

**Option B: NIHIL or SKP KB**
- Result: Rejected
- Proceed to: **Submit Surat Keberatan** (Objection)

#### System Actions:
- Input & Upload Supporting Docs (SKP)

---

## 📝 Objection Process Flow

### Stage 5: Objection Submission (Surat Keberatan)

**Description:** Filing objection to SKP decision

#### Input Fields:
- **Nomor Surat Keberatan** (Objection Letter Number)
- **Tanggal Dilaporkan** (Submission Date)
- **Nilai Keberatan** (Objection Amount)

#### Case Status:
**Case Status: OBJECTION LETTER SUBMITTED**

#### System Actions:
- Input & Upload Supporting Docs (Surat Keberatan)

---

### Stage 6: SPUH (Surat Pemberitahuan Untuk Hadir)

**Description:** Summon letter to attend hearing

#### Input Fields:
- **Nomor SPUH** (SPUH Number)
- **Tanggal Diterbitkan** (Issue Date)
- **Tanggal Diterima** (Receipt Date)

**Later to be updated:**
- **Nomor Surat Balasan** (Reply Letter Number)
- **Tanggal Penyerahan** (Submission Date)

#### Case Status:
**Case Status: SPUH RECEIVED**

#### System Actions:
- Input & Upload Supporting Docs (SPUH & Surat Balasan)

---

### Stage 7: Objection Decision (Keputusan Keberatan)

**Description:** Decision on objection

#### Input Fields:
- **Nomor Surat Keputusan Keberatan** (Objection Decision Number)
- **Tanggal Keputusan** (Decision Date)
- **Keputusan** (Decision):
  - **Dikabulkan** (Granted)
  - **Dikabulkan Sebagian** (Partially Granted)
  - **Ditolak** (Rejected)
- **Nilai** (Amount)

#### Case Status:
**Case Status: OBJECTION DECISION RECEIVED**

#### Decision Point 2: Jenis Keputusan?

**Option A: Dikabulkan (Granted)**
- Result: Accepted
- Proceed to: **Refund Procedure**

**Option B: Dikabulkan Sebagian (Partially Granted)**
- Result: Accepted
- Proceed to: **Refund Procedure**

**Option C: Ditolak (Rejected)**
- Result: Rejected
- Proceed to: **Submit Banding** (Appeal)

**Option D: Dikabulkan Sebagian / Ditolak**
- Additional path: Can proceed to **Submit Banding** (Appeal)

#### System Actions:
- Input & Upload Supporting Docs (Surat Keputusan Keberatan)

---

## ⚖️ Appeal Process Flow

### Stage 8: Appeal Submission (Surat Banding)

**Description:** Filing appeal to tax court

#### Input Fields:
- **Nomor Surat Banding** (Appeal Letter Number)
- **Tanggal Dilaporkan** (Submission Date)
- **Nilai** (Amount)

**Later to be updated:**
- **Nomor Sengketa** (Dispute Number)

#### Case Status:
**Case Status: TAX APPEAL SUBMITTED**

#### System Actions:
- Input & Upload Supporting Docs (Surat Banding)

---

### Stage 9: Request for Explanation (Permintaan Penjelasan Banding)

**Description:** Tax court requests additional explanation

#### Input Fields:
- **Nomor Surat Permintaan Penjelasan Banding** (Request Number)
- **Tanggal Diterbitkan** (Issue Date)
- **Tanggal Diterima** (Receipt Date)

**Later to be updated:**
- **Nomor Surat Penjelasan** (Explanation Letter Number)
- **Tanggal Dilaporkan** (Submission Date)

#### System Actions:
- Input & Upload Supporting Docs (Surat Permintaan Penjelasan & Balasan Surat Penjelasan)

---

### Stage 10: Appeal Decision (Keputusan Banding)

**Description:** Tax court decision

#### Input Fields:
- **Nomor Surat Keputusan Banding** (Appeal Decision Number)
- **Tanggal Keputusan** (Decision Date)
- **Keputusan** (Decision):
  - **Dikabulkan** (Granted)
  - **Dikabulkan Sebagian** (Partially Granted)
  - **Ditolak** (Rejected)
- **Nilai** (Amount)

#### Decision Point 3: Jenis Keputusan?

**Option A: Dikabulkan (Granted)**
- **Case Status: GRANTED**
- Result: Accepted
- Proceed to: **Refund Procedure**

**Option B: Dikabulkan Sebagian (Partially Granted)**
- **Case Status: NOT GRANTED / PARTIALLY GRANTED**
- Can proceed to: **Submit Peninjauan Kembali** (Supreme Court Review)
- Or proceed to: **Refund Procedure**

**Option C: Ditolak (Rejected)**
- **Case Status: NOT GRANTED / PARTIALLY GRANTED**
- Proceed to: **Submit Peninjauan Kembali** (Supreme Court Review)

**Option D: SKP KB**
- Proceed to: **Submit Peninjauan Kembali** (Supreme Court Review)

#### System Actions:
- Input & Upload Supporting Docs (Surat Keputusan Banding)

---

## 🏛️ Supreme Court Review Flow

### Stage 11: Supreme Court Submission (Peninjauan Kembali)

**Description:** Final judicial review

#### Input Fields:
- **Nomor Surat Peninjauan Kembali** (Supreme Court Letter Number)
- **Tanggal Dilaporkan** (Submission Date)
- **Nilai** (Amount)

#### Case Status:
**Case Status: REVIEW BY SUPREME COURT**

#### System Actions:
- Input & Upload Supporting Docs (Surat Peninjauan Kembali)

---

### Stage 12: Supreme Court Decision (Keputusan Peninjauan Kembali)

**Description:** Final decision from Supreme Court

#### Input Fields:
- **Nomor Surat Keputusan Peninjauan Kembali** (Supreme Court Decision Number)
- **Tanggal Keputusan** (Decision Date)
- **Keputusan** (Decision):
  - **Dikabulkan** (Granted)
  - **Dikabulkan Sebagian** (Partially Granted)
  - **Ditolak** (Rejected)
- **Nilai** (Amount)

#### Decision Point 4: Jenis Keputusan?

**Option A: Dikabulkan (Granted)**
- **Case Status: GRANTED**
- Result: Accepted
- Proceed to: **Refund Procedure**

**Option B: Dikabulkan Sebagian (Partially Granted)**
- **Case Status: NOT GRANTED / PARTIALLY GRANTED**
- Result: Accepted (Partial)
- Proceed to: **Refund Procedure**

**Option C: Ditolak (Rejected)**
- **Case Status: NOT GRANTED / PARTIALLY GRANTED**
- Result: Rejected
- Proceed to: **KIAN Procedure**

#### System Actions:
- Input & Upload Supporting Docs (Surat Keputusan Peninjauan Kembali)

---

## 💰 Refund Procedure

### Stage 13: Bank Transfer Request

**Description:** Requesting refund transfer from tax authority

#### Input Fields - Surat Permintaan Transfer:
- **Nomor Surat Permintaan Transfer** (Transfer Request Number)
- **Tanggal Dilaporkan** (Report Date)

#### Input Fields - Received Bank Transfer Instruction:
- **Nomor Surat Permintaan Transfer** (Transfer Request Number)
- **Tanggal Diterbitkan** (Issue Date)
- **Tanggal Diterima** (Receipt Date)

#### System Actions:
- Input & Upload Supporting Docs

---

### Stage 14: Surat Instruksi Transfer

**Description:** Bank transfer instruction from tax authority

#### Input Fields:
- **Amount** (Transfer Amount)

#### System Actions:
- Form Input & Supporting Docs

---

### Stage 15: Refund Received

**Description:** Actual receipt of refund

#### Input Fields:
- **Tanggal Penerimaan Dana** (Fund Receipt Date)

#### Final Status:
**Refund Completed**

#### System Actions:
- Input & Upload Supporting Docs

---

## 📊 KIAN Procedure

### Stage 16: KIAN Report Submission

**Description:** Internal loss recognition document

**KIAN Definition:**  
KIAN merupakan dokumen internal perusahaan yang dipersyaratkan ketika nilai sengketa pajak yang diminta kembali hanya sepenuhnya dibayar atau ditolak sehingga dianggap merugikan perusahaan.

(KIAN is an internal company document required when the disputed tax amount cannot be refunded and is fully paid or rejected, thus considered a company loss)

#### Input Fields:
- **Nomor KIAN** (KIAN Number)
- **Tanggal Dilaporkan** (Report Date)
- **Amount** (Loss Amount)
- **Tanggal Approval** (Approval Date)

#### System Actions:
- KIAN Form Input
- Input & Upload Supporting Docs

---

## 📌 Case Status Summary

The system tracks the following case statuses throughout the workflow:

| Status Code | Status Name | Stage |
|-------------|-------------|-------|
| OPEN | Case Open | Initial Filing |
| SP2_RECEIVED | SP2 Received | Audit Notification |
| SPHP_RECEIVED | SPHP Received | Audit Findings |
| SKP_RECEIVED | SKP Received | Tax Assessment |
| OBJECTION_SUBMITTED | Objection Letter Submitted | Objection Filing |
| SPUH_RECEIVED | SPUH Received | Summon Letter |
| OBJECTION_DECISION | Objection Decision Received | Objection Decision |
| APPEAL_SUBMITTED | Tax Appeal Submitted | Appeal Filing |
| APPEAL_DECISION | Appeal Decision Received | Appeal Decision |
| SUPREME_COURT_REVIEW | Review By Supreme Court | Supreme Court Submission |
| GRANTED | Granted | Favorable Decision |
| NOT_GRANTED_PARTIAL | Not Granted / Partially Granted | Partial/Unfavorable Decision |
| REFUND_IN_PROGRESS | Refund In Progress | Refund Processing |
| REFUND_COMPLETED | Refund Completed | Refund Received |
| KIAN_SUBMITTED | KIAN Submitted | Loss Recognition |
| CLOSE | Case Closed | Final Resolution |

---

## 🎯 Decision Logic Summary

### Decision Point 1: SKP Type
- **SKP LB (Lebih Bayar)** → Refund Procedure
- **NIHIL / SKP KB** → Objection Process

### Decision Point 2: Objection Decision
- **Dikabulkan (Granted)** → Refund Procedure
- **Dikabulkan Sebagian (Partially Granted)** → Refund Procedure OR Appeal
- **Ditolak (Rejected)** → Appeal Process

### Decision Point 3: Appeal Decision
- **Dikabulkan (Granted)** → Refund Procedure
- **Dikabulkan Sebagian (Partially Granted)** → Supreme Court OR Refund Procedure
- **Ditolak (Rejected)** → Supreme Court Review
- **SKP KB** → Supreme Court Review

### Decision Point 4: Supreme Court Decision
- **Dikabulkan (Granted)** → Refund Procedure
- **Dikabulkan Sebagian (Partially Granted)** → Refund Procedure
- **Ditolak (Rejected)** → KIAN Procedure

---

## 📁 Document Management

Throughout all stages, the system supports:

1. **Form Input** - Structured data entry for each stage
2. **Supporting Documents Upload** - Attachment of official documents
3. **Document Storage** - Files stored in NAS following pattern:
   ```
   /nas/portax/{entity}/{year}/{case_type}/{case_id}/{stage}/{filename}
   ```

### Document Types by Stage:
- **SPT** - Tax return documents
- **SP2** - Audit notification letters
- **SPHP** - Audit finding reports
- **SKP** - Tax assessment letters
- **Surat Keberatan** - Objection letters
- **SPUH** - Summon letters
- **Surat Balasan** - Reply letters
- **Keputusan** - Decision letters
- **Surat Banding** - Appeal letters
- **Surat Penjelasan** - Explanation letters
- **Surat Peninjauan Kembali** - Supreme Court review letters
- **Surat Permintaan Transfer** - Transfer request letters
- **Surat Instruksi Transfer** - Transfer instruction letters
- **KIAN Form** - Internal loss recognition documents

---

## 🔄 Workflow States

### Active States (Require Action)
1. Filing Required
2. Awaiting SP2
3. Awaiting SPHP
4. Awaiting SKP
5. Objection Filing Window
6. Awaiting SPUH
7. Awaiting Objection Decision
8. Appeal Filing Window
9. Awaiting Appeal Decision
10. Supreme Court Filing Window
11. Awaiting Supreme Court Decision
12. Refund Processing
13. KIAN Processing

### Terminal States (Final)
1. Case Closed (Not Filed)
2. Refund Completed
3. KIAN Approved
4. Case Closed (Final)

---

## 🔐 Approval Workflow

All stages follow the approval pattern:

1. **Affiliate Submission**
   - Affiliate user inputs data
   - Submits to holding company
   - Status: Pending Approval

2. **Holding Approval**
   - Holding company reviews
   - Approves or requests revision
   - Status: Approved / Revision Required

3. **Data Lock**
   - Approved data is locked
   - Cannot be edited without revision request
   - Maintains audit trail

---

## 📊 Key Performance Indicators (KPIs)

The system can track:

1. **Processing Time**
   - Days from filing to resolution
   - Time spent in each stage

2. **Success Rate**
   - Granted decisions %
   - Partially granted decisions %
   - Rejected decisions %

3. **Refund Statistics**
   - Total refund amount
   - Average refund time
   - Refund success rate

4. **Loss Recognition**
   - Total KIAN amount
   - KIAN cases by entity
   - Loss percentage by case type

---

## 🎓 Business Rules

### Rule 1: Sequential Processing
Cases must proceed through stages sequentially. Cannot skip stages.

### Rule 2: Decision-Based Routing
Routing to next stage depends on decision outcomes (Granted/Partially/Rejected).

### Rule 3: Terminal State Rules
- **Refund** = Terminal state (case resolved favorably)
- **KIAN** = Terminal state (case resolved unfavorably)
- **Not Filed** = Terminal state (case never initiated)

### Rule 4: Document Requirements
Each stage requires supporting documents before approval.

### Rule 5: Time Tracking
System automatically tracks:
- Submission dates
- Receipt dates
- Processing duration
- Overdue cases

---

## 🚨 Special Scenarios

### Scenario 1: SKP LB Direct to Refund
When SKP Type is "Lebih Bayar" (Overpayment), case proceeds directly to refund without objection process.

### Scenario 2: Multiple Partial Grants
A case can receive multiple partial grants through objection → appeal → supreme court, with refunds processed at each favorable stage.

### Scenario 3: Mixed Outcome
Supreme Court "Dikabulkan Sebagian" (Partially Granted):
- Granted portion → Refund Procedure
- Rejected portion → KIAN Procedure

### Scenario 4: Voluntary Case Closure
User can close case at any stage if company decides not to pursue further.

---

## 📝 Notes

1. **"Later to be updated"** fields indicate information that becomes available after initial submission.

2. **System supports both CIT and VAT** with same workflow but different field requirements.

3. **All monetary amounts** must be recorded with currency specification.

4. **Supporting documents are mandatory** for compliance and audit purposes.

5. **Approval workflow applies to all stages** to maintain control and audit trail.

---

## 🔗 Integration Points

The workflow integrates with:

1. **Master Data**
   - Entities
   - Fiscal Years
   - Periods
   - Currencies
   - Case Statuses

2. **User Management**
   - Role-based access control
   - Approval authorities
   - Audit trail by user

3. **Document Storage**
   - NAS file system
   - Organized by entity/year/case/stage

4. **Reporting System**
   - Status reports
   - Processing time analytics
   - Refund tracking
   - Loss recognition reports

---

**End of PorTax Workflow Documentation**

*This document represents the complete business process flow as designed in the PorTax system flowchart.*
