# Stage 16 Implementation - KIAN Report Submission

**Document Type:** Implementation Guide for Stage 16

**Version:** 1.0

**Last Updated:** January 22, 2026

**Based on:** STAGE-5-OBJECTION-IMPLEMENTATION.md Template

---

## 📋 Quick Reference

| Property | Value |
|----------|-------|
| **Stage Number** | 16 |
| **Stage Name** | KIAN Report Submission |
| **Model Name** | KianSubmission |
| **Table Name** | kian_submissions |
| **Previous Stages** | 4 (SKP KB), 7 (Objection Decision), 10 (Appeal Decision), 12 (Supreme Court Decision) |
| **Next Stages** | None (Terminal Stage) |
| **Case Status** | KIAN_SUBMITTED |
| **Flow Type** | Single-Phase Independent Submission |

---

## 🎯 Stage 16 Overview

**Description:** Internal loss recognition document submission

**KIAN Definition:**  
KIAN merupakan dokumen internal perusahaan yang dipersyaratkan ketika nilai sengketa pajak yang diminta kembali hanya sepenuhnya dibayar atau ditolak sehingga dianggap merugikan perusahaan.

(KIAN is an internal company document required when the disputed tax amount cannot be refunded and is fully paid or rejected, thus considered a company loss)

**Terminal Stage Characteristics:**
- No conditional routing based on decisions
- Direct submission form accessible from workflow
- Approval workflow same as other stages (Affiliate → Holding)
- Final case status after approval

---

## 📝 Form Fields Definition

### KIAN Form Fields (4 fields):

```javascript
const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'kian_number',
    label: 'Nomor KIAN (KIAN Number)',
    required: true,
    readonly: false,
    placeholder: 'e.g., KIAN-2024-001'
  },
  {
    id: 2,
    type: 'date',
    key: 'submission_date',
    label: 'Tanggal Dilaporkan (Report Date)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'number',
    key: 'loss_amount',
    label: 'Amount (Loss Amount)',
    required: true,
    readonly: false,
    placeholder: 'Enter loss amount in Rp'
  },
  {
    id: 4,
    type: 'date',
    key: 'approval_date',
    label: 'Tanggal Approval (Approval Date)',
    required: true,
    readonly: false
  }
])
```

### Database Schema:

The migration already exists in `database/migrations/2026_01_01_000009_create_refund_kian_tables.php`:

```php
Schema::create('kian_submissions', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('tax_case_id')->unique();
    
    $table->string('kian_number');
    $table->date('submission_date');
    $table->decimal('kian_amount', 20, 2);
    $table->text('kian_reason')->nullable();
    
    // Workflow
    $table->unsignedBigInteger('submitted_by')->nullable();
    $table->timestamp('submitted_at')->nullable();
    $table->unsignedBigInteger('approved_by')->nullable();
    $table->timestamp('approved_at')->nullable();
    $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
    
    $table->text('notes')->nullable();
    $table->timestamps();
    $table->softDeletes();
    
    $table->foreign('tax_case_id')->references('id')->on('tax_cases')->onDelete('cascade');
    $table->foreign('submitted_by')->references('id')->on('users')->onDelete('set null');
    $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
});
```

### Model Implementation:

The model already exists in `app/Models/KianSubmission.php` - verify it has:

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class KianSubmission extends Model
{
    use SoftDeletes;

    protected $table = 'kian_submissions';

    protected $fillable = [
        'tax_case_id',
        'kian_number',
        'submission_date',
        'kian_amount',
        'kian_reason',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'submission_date' => 'date',
        'kian_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function taxCase(): BelongsTo
    {
        return $this->belongsTo(TaxCase::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
```

---

## 🔧 Implementation Checklist

### Phase 1: Backend Setup ✅
- [x] Migration for kian_submissions table (already exists)
- [x] KianSubmission model with relationships (already exists)
- [x] Add kianSubmission() HasOne relationship to TaxCase
- [x] KianSubmissionController (already exists)

### Phase 2: API Endpoints ✅
- [x] Endpoints in routes/api.php (already exists)
- [x] Validate: kian_number, submission_date, kian_amount, approval_date
- [x] Support form input & document upload

### Phase 3: Revision System Integration
- [ ] Update RevisionService.php - Add Stage 16 to requestRevision()
- [ ] Update RevisionService.php - Add Stage 16 to approveRevision()
- [ ] Update RevisionService.php - Add Stage 16 to detectStageFromFields()
- [ ] Ensure all 4 fields tracked for revisions

### Phase 4: Frontend Components (NEW)
- [ ] Create KianSubmissionForm.vue component
- [ ] Display all 4 fields in main form
- [ ] Add loadTaxCase(), loadRevisions(), loadDocuments()
- [ ] Add router entry: /tax-cases/:id/workflow/16

### Phase 5: Revision System Integration
- [ ] Add all 4 fields to RequestRevisionModalV2
  - kian_number (text)
  - submission_date (date)
  - loss_amount (number)
  - approval_date (date)
- [ ] Update fieldLabel() function with all labels

### Phase 6: Workflow Routing
- [ ] Update workflow-decision endpoint to support Stage 16 routing
- [ ] No conditional routing (terminal stage)
- [ ] Update next_action fields mapping for kian-submissions

### Phase 7: Testing
- [ ] Submit KIAN form from Stage 4 (SKP KB path)
- [ ] Submit KIAN form from Stage 7 (Objection Decision path)
- [ ] Submit KIAN form from Stage 10 (Appeal Decision path)
- [ ] Submit KIAN form from Stage 12 (Supreme Court Decision path)
- [ ] Request revision on KIAN fields
- [ ] Verify document upload functionality
- [ ] Verify case status updates to KIAN_SUBMITTED

---

## 🎯 KIAN-Specific Characteristics

### 1. Terminal Stage
- KIAN is always the **final stage** in the workflow
- No routing to other stages after KIAN submission
- Case status becomes KIAN_SUBMITTED

### 2. Independent Entry Points
Unlike regular sequential stages, KIAN can be entered from multiple decision points:
- **From Stage 4 (SKP)**: When SKP Type = KB and user chooses KIAN over Objection
- **From Stage 7 (Objection Decision)**: When decision = Rejected or Partially Granted
- **From Stage 10 (Appeal Decision)**: When decision = Partially Granted or Rejected
- **From Stage 12 (Supreme Court Decision)**: When decision = Rejected or Partially Granted

### 3. No Conditional Buttons in Form
Since KIAN is always terminal:
- Do NOT add routing buttons
- No "Proceed to Next Stage" buttons
- No decision choice buttons
- Only show: Submit, Save Draft, Request Revision

### 4. Loss Amount Tracking
- `kian_amount` represents the total loss amount
- Should be documented with supporting materials
- Part of financial reconciliation

---

## 🚨 Special Implementation Notes

### Note 1: Field Mapping Update
The existing migration uses:
- `kian_amount` (instead of `loss_amount` from PORTAX_FLOW.md)
- This is acceptable and used consistently

Ensure frontend form uses correct database field names.

### Note 2: Approval Workflow
Despite being terminal, KIAN still follows approval pattern:
```
1. Affiliate creates KIAN (status: draft)
2. Affiliate submits KIAN (status: submitted, submitted_by, submitted_at)
3. Holding company approves/rejects (status: approved/rejected, approved_by, approved_at)
4. Case locked until revision request
```

### Note 3: Document Storage Path
Follows standard pattern:
```
/nas/portax/{entity}/{year}/{case_type}/{case_id}/stage-16/{filename}
```

### Note 4: Revision System Integration
All 4 fields must be trackable:
- kian_number → text field
- submission_date → date field
- kian_amount → number field
- approval_date → date field

Update RevisionService with stage 16 detection.

---

## 📋 Implementation Order

1. **Phase 1:** Update TaxCase model - Add kianSubmission relationship
2. **Phase 2:** Create KianSubmissionForm.vue component
3. **Phase 3:** Update RevisionService.php with Stage 16
4. **Phase 4:** Update router with /tax-cases/:id/workflow/16
5. **Phase 5:** Test submission from all 4 entry points
6. **Phase 6:** Test revision workflow
7. **Phase 7:** Verify document upload & case status

---

## ✨ Key Differences from Other Stages

| Aspect | Other Stages | KIAN (Stage 16) |
|--------|-------------|------------------|
| Entry Points | Sequential from previous | Multiple decision points |
| Routing | Conditional based on decisions | No routing (terminal) |
| Buttons | Multiple choice buttons | Only submit/draft/revise |
| Case Status | Intermediate | KIAN_SUBMITTED (final) |
| Next Stage | Various | None (terminal) |
| Component Complexity | Complex with conditions | Simple submission |

---

**End of Stage 16 KIAN Implementation Guide**

*KIAN is a terminal stage focused on documenting losses when tax appeals are unsuccessful.*
