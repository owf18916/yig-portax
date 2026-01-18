# Stage 6 Implementation - SPUH (Surat Pemberitahuan Untuk Hadir)

**Document Type:** Implementation Guide for Stage 6

**Version:** 1.0

**Last Updated:** January 18, 2026

**Based on:** STAGE-4-SKP-IMPLEMENTATION.md Template

---

## 📋 Quick Reference

| Property | Value |
|----------|-------|
| **Stage Number** | 6 |
| **Stage Name** | SPUH (Summon Letter) |
| **Model Name** | SpuhRecord |
| **Table Name** | spuh_records |
| **Previous Stage** | 5 (Objection Submission) |
| **Next Stages** | 7 (Objection Decision) |
| **Case Status** | SPUH_RECEIVED |
| **Flow Type** | Multi-Phase Input (Initial + Update) |

---

## 🎯 Stage 6 Overview

**Description:** Tax authority sends summon letter for hearing; later user uploads reply

**Two-Phase Submission:**

**Phase 1: Initial SPUH Receipt**
- System records: nomor_spuh, tanggal_diterbitkan, tanggal_diterima
- Status: awaiting user's response

**Phase 2: Reply Upload**
- Later: User submits nomor_surat_balasan, tanggal_penyerahan
- Status: completed

---

## 📝 Form Fields Definition

### Phase 1 Fields (Initial SPUH - 3 fields):

```javascript
const fieldsPhase1 = ref([
  {
    id: 1,
    type: 'text',
    key: 'spuh_number',
    label: 'Nomor SPUH (SPUH Number)',
    required: true,
    readonly: false,
    placeholder: 'e.g., SPUH/2024/001'
  },
  {
    id: 2,
    type: 'date',
    key: 'issue_date',
    label: 'Tanggal Diterbitkan (Issue Date)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'date',
    key: 'receipt_date',
    label: 'Tanggal Diterima (Receipt Date)',
    required: true,
    readonly: false
  }
])
```

### Phase 2 Fields (Reply - 2 fields, optional):

```javascript
const fieldsPhase2 = ref([
  {
    id: 4,
    type: 'text',
    key: 'reply_number',
    label: 'Nomor Surat Balasan (Reply Letter Number)',
    required: false,
    readonly: false,
    placeholder: 'e.g., BAL-2024-001'
  },
  {
    id: 5,
    type: 'date',
    key: 'reply_date',
    label: 'Tanggal Penyerahan (Submission Date)',
    required: false,
    readonly: false
  }
])
```

### Database Schema:

```php
Schema::create('spuh_records', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tax_case_id')
        ->constrained('tax_cases')
        ->cascadeOnDelete();

    // Phase 1: SPUH Receipt
    $table->string('spuh_number')->nullable();
    $table->date('issue_date')->nullable();
    $table->date('receipt_date')->nullable();
    
    // Phase 2: Reply (filled later)
    $table->string('reply_number')->nullable();
    $table->date('reply_date')->nullable();
    
    $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
    
    $table->timestamps();
    $table->softDeletes();
});
```

### Model Implementation:

```php
<?php
namespace App\Models;

class SpuhRecord extends Model
{
    use SoftDeletes;
    protected $table = 'spuh_records';
    protected $fillable = [
        'tax_case_id',
        'spuh_number',
        'issue_date',
        'receipt_date',
        'reply_number',
        'reply_date',
        'status'
    ];
    protected $casts = [
        'issue_date' => 'date',
        'receipt_date' => 'date',
        'reply_date' => 'date',
    ];
    public function taxCase(): BelongsTo
    {
        return $this->belongsTo(TaxCase::class);
    }
}
```

---

## 🔧 Implementation Checklist

### Phase 1: Database Setup
- [ ] Create migration for spuh_records table with 5 fields
- [ ] Create SpuhRecord model with relationships
- [ ] Add spuhRecord() HasOne relationship to TaxCase
- [ ] Run migration and verify schema

### Phase 2: Backend Services
- [ ] Update RevisionService.php - Add Stage 6 to requestRevision()
- [ ] Update RevisionService.php - Add Stage 6 to approveRevision()
- [ ] Update RevisionService.php - Add Stage 6 to detectStageFromFields()
- [ ] Ensure all 5 fields tracked for revisions

### Phase 3: API Endpoints
- [ ] Update routes/api.php - Add Stage 6 workflow endpoint
- [ ] Phase 1: Validate spuh_number, issue_date, receipt_date
- [ ] Phase 2: Accept optional reply_number, reply_date
- [ ] Allow partial updates (user can submit phase 1, then phase 2 later)

### Phase 4: Frontend Components
- [ ] Create SpuhRecordForm.vue component
- [ ] Display Phase 1 fields (required) in main form
- [ ] Display Phase 2 fields (optional) in collapsible/secondary section
- [ ] Add loadTaxCase(), loadRevisions(), loadDocuments()
- [ ] Add router entry: /tax-cases/:id/workflow/6

### Phase 5: Revision System
- [ ] Add all 5 fields to RequestRevisionModalV2
  - spuh_number (text)
  - issue_date (date)
  - receipt_date (date)
  - reply_number (text)
  - reply_date (date)
- [ ] Update fieldLabel() function with all labels

### Phase 6: Testing
- [ ] Submit Phase 1 only (spuh_number, issue_date, receipt_date)
- [ ] Request revision on Phase 1 fields
- [ ] Later: Submit Phase 2 fields (reply_number, reply_date)
- [ ] Request revision on Phase 2 fields
- [ ] Verify partial update logic works

---

## ⚠️ Lessons Learned from Stage 4 & 5 (To Avoid)

### Lesson 1: Partial Update Strategy
**New for Stage 6:** This stage has optional Phase 2 fields that get filled later.

**Prevention:** 
- Don't require all fields at once
- Allow status='submitted' after Phase 1
- Allow user to add Phase 2 data later without full re-submission
- Use updateOrCreate() pattern for graceful updates

**Apply:**
```php
// Accept partial updates
SpuhRecord::updateOrCreate(
    ['tax_case_id' => $caseId],
    $validatedData // Only provided fields update
);
```

---

### Lesson 2: Optional vs Required Fields
**Issue:** Must distinguish between Phase 1 (required for submission) and Phase 2 (optional, filled later).

**Prevention:**
- Frontend: Show Phase 1 fields prominently, Phase 2 in secondary section
- Backend: Validate Phase 1 fields required, Phase 2 optional
- API: Accept requests with only Phase 1 or both phases

**Apply:**
```php
$validated = $request->validate([
    'spuh_number' => 'required|string',
    'issue_date' => 'required|date',
    'receipt_date' => 'required|date',
    'reply_number' => 'nullable|string',      // Optional
    'reply_date' => 'nullable|date',           // Optional
]);
```

---

### Lesson 3: Revision Tracking for Multi-Phase Fields
**Issue:** When revising Phase 2 fields, need to preserve Phase 1 data that's already submitted.

**Prevention:**
- Load complete record before updating
- Only update fields in request, preserve others
- Track revision at field-level, not phase-level

**Apply:** RevisionService already handles this with field-level tracking.

---

### Lesson 4: WHERE Clause Specificity (from Stage 4)
**Issue:** Must still ensure WHERE clauses specify `tax_case_id`.

**Prevention:** Apply same rule as Stage 4/5 - always include `WHERE tax_case_id = $caseId`.

---

### Lesson 5: Status Transitions
**New Consideration:** With multi-phase submission, status should reflect most recent phase.

**Prevention:**
- Phase 1 Submit: status='submitted'
- Phase 2 Submit: status='submitted' (no change, already submitted)
- Don't change status to 'approved' until holding company approves ALL phases

---

## 🎯 Stage 6 Specific Notes

### Field Dependencies
- Phase 1 fields are always shown
- Phase 2 fields become relevant after Phase 1 submission
- Can show Phase 2 as "To be filled when available"

### Real-World Scenario
1. Tax authority issues SPUH (Phase 1 data arrives)
2. Coordinator enters Phase 1 and submits Stage 6
3. Company attends hearing and gets receipt
4. Coordinator later enters Phase 2 (reply letter number and date)
5. Coordinator updates Stage 6 with Phase 2 data

### Document Upload
- Upload SPUH letter as supporting doc for Phase 1
- Upload Surat Balasan (reply letter) as supporting doc for Phase 2
- Documents can be uploaded at any time

---

## 📋 Implementation Order

1. **Day 1 Morning:** Database setup & Models
2. **Day 1 Afternoon:** Backend services & API endpoints with partial update logic
3. **Day 2 Morning:** Frontend components & Router with Phase separation
4. **Day 2 Afternoon:** Revision system & testing (test both phases)

---

## 🚨 Critical Implementation Point

**Multi-Phase Logic:** This is Stage 6's unique challenge. Don't oversimplify to single-phase. The ability to submit Phase 1, then add Phase 2 later is essential to business process.

**Test Scenario:**
1. Submit Form with Phase 1 fields only
2. System records status='submitted'
3. Later, return to form
4. Add Phase 2 fields
5. Update submission
6. Verify Phase 1 data preserved
7. Verify workflow_histories updated correctly

---

**End of Stage 6 Implementation Template**

*Apply lessons from Stage 4/5 + implement multi-phase logic correctly*
