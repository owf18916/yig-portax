# Stage 9 Implementation - Request for Explanation (Permintaan Penjelasan Banding)

**Document Type:** Implementation Guide for Stage 9

**Version:** 1.0

**Last Updated:** January 19, 2026

**Based on:** STAGE-6-SPUH-IMPLEMENTATION.md Template

---

## 📋 Quick Reference

| Property | Value |
|----------|-------|
| **Stage Number** | 9 |
| **Stage Name** | Request for Explanation (Permintaan Penjelasan Banding) |
| **Model Name** | AppealExplanationRequest |
| **Table Name** | appeal_explanation_requests |
| **Previous Stage** | 8 (Appeal Submission) |
| **Next Stages** | 10 (Appeal Decision) |
| **Case Status** | APPEAL_EXPLANATION_REQUESTED |
| **Flow Type** | Multi-Phase Input (Initial + Update) |

---

## 🎯 Stage 9 Overview

**Description:** Tax court requests additional explanation for appeal; later user submits explanation letter

**Two-Phase Submission:**

**Phase 1: Explanation Request Receipt**
- System records: nomor_surat_permintaan_penjelasan_banding, tanggal_diterbitkan, tanggal_diterima
- Status: awaiting user's explanation

**Phase 2: Explanation Submission**
- Later: User submits nomor_surat_penjelasan, tanggal_dilaporkan
- Status: completed

---

## 📝 Form Fields Definition

### Phase 1 Fields (Request Receipt - 3 fields):

```javascript
const fieldsPhase1 = ref([
  {
    id: 1,
    type: 'text',
    key: 'request_number',
    label: 'Nomor Surat Permintaan Penjelasan Banding (Request Number)',
    required: true,
    readonly: false,
    placeholder: 'e.g., SPB/2024/001'
  },
  {
    id: 2,
    type: 'date',
    key: 'request_issue_date',
    label: 'Tanggal Diterbitkan (Issue Date)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'date',
    key: 'request_receipt_date',
    label: 'Tanggal Diterima (Receipt Date)',
    required: true,
    readonly: false
  }
])
```

### Phase 2 Fields (Explanation Submission - 2 fields, optional):

```javascript
const fieldsPhase2 = ref([
  {
    id: 4,
    type: 'text',
    key: 'explanation_letter_number',
    label: 'Nomor Surat Penjelasan (Explanation Letter Number)',
    required: false,
    readonly: false,
    placeholder: 'e.g., PEN-2024-001'
  },
  {
    id: 5,
    type: 'date',
    key: 'explanation_submission_date',
    label: 'Tanggal Dilaporkan (Submission Date)',
    required: false,
    readonly: false
  }
])
```

### Database Schema:

```php
Schema::create('appeal_explanation_requests', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tax_case_id')
        ->constrained('tax_cases')
        ->cascadeOnDelete();

    // Phase 1: Explanation Request Receipt
    $table->string('request_number')->nullable();
    $table->date('request_issue_date')->nullable();
    $table->date('request_receipt_date')->nullable();
    
    // Phase 2: Explanation Submission (filled later by user)
    $table->string('explanation_letter_number')->nullable();
    $table->date('explanation_submission_date')->nullable();
    
    $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
    
    $table->timestamps();
    $table->softDeletes();
});
```

### Model Implementation:

```php
<?php
namespace App\Models;

class AppealExplanationRequest extends Model
{
    use SoftDeletes;
    protected $table = 'appeal_explanation_requests';
    protected $fillable = [
        'tax_case_id',
        'request_number',
        'request_issue_date',
        'request_receipt_date',
        'explanation_letter_number',
        'explanation_submission_date',
        'status'
    ];
    protected $casts = [
        'request_issue_date' => 'date',
        'request_receipt_date' => 'date',
        'explanation_submission_date' => 'date',
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
- [ ] Create migration for appeal_explanation_requests table with 5 fields
- [ ] Create AppealExplanationRequest model with relationships
- [ ] Add appealExplanationRequest() HasOne relationship to TaxCase
- [ ] Run migration and verify schema

### Phase 2: Backend Services
- [ ] Update RevisionService.php - Add Stage 9 to requestRevision()
- [ ] Update RevisionService.php - Add Stage 9 to approveRevision()
- [ ] Update RevisionService.php - Add Stage 9 to detectStageFromFields()
- [ ] Ensure all 5 fields tracked for revisions

### Phase 3: API Endpoints
- [ ] Update routes/api.php - Add Stage 9 workflow endpoint
- [ ] Phase 1: Validate request_number, request_issue_date, request_receipt_date
- [ ] Phase 2: Accept optional explanation_letter_number, explanation_submission_date
- [ ] Allow partial updates (user can submit phase 1, then phase 2 later)

### Phase 4: Frontend Components
- [ ] Create AppealExplanationRequestForm.vue component
- [ ] Display Phase 1 fields (required) in main form
- [ ] Display Phase 2 fields (optional) in collapsible/secondary section
- [ ] Add loadTaxCase(), loadRevisions(), loadDocuments()
- [ ] Add router entry: /tax-cases/:id/workflow/9

### Phase 5: Revision System
- [ ] Add all 5 fields to RequestRevisionModalV2
  - request_number (text)
  - request_issue_date (date)
  - request_receipt_date (date)
  - explanation_letter_number (text)
  - explanation_submission_date (date)
- [ ] Update fieldLabel() function with all labels

### Phase 6: Testing
- [ ] Submit Phase 1 only (request_number, request_issue_date, request_receipt_date)
- [ ] Request revision on Phase 1 fields
- [ ] Later: Submit Phase 2 fields (explanation_letter_number, explanation_submission_date)
- [ ] Request revision on Phase 2 fields
- [ ] Verify partial update logic works

---

## ⚠️ Lessons Learned from Stage 4, 5, 6 & 8 (To Avoid)

### Lesson 1: Partial Update Strategy
**New for Stage 9:** This stage has optional Phase 2 fields that get filled later.

**Prevention:** 
- Don't require all fields at once
- Allow status='submitted' after Phase 1
- Allow user to add Phase 2 data later without full re-submission
- Use updateOrCreate() pattern for graceful updates

**Apply:**
```php
// Accept partial updates
AppealExplanationRequest::updateOrCreate(
    ['tax_case_id' => $caseId],
    $validatedData // Only provided fields update
);
```

---

### Lesson 2: Optional vs Required Fields
**Issue:** Must distinguish between Phase 1 (required receipt of court request) and Phase 2 (optional, filled later by user).

**Prevention:**
- Frontend: Show Phase 1 fields prominently, Phase 2 in secondary section
- Backend: Validate Phase 1 fields required, Phase 2 optional
- API: Accept requests with only Phase 1 or both phases

**Apply:**
```php
$validated = $request->validate([
    'request_number' => 'required|string',
    'request_issue_date' => 'required|date',
    'request_receipt_date' => 'required|date',
    'explanation_letter_number' => 'nullable|string',    // Optional
    'explanation_submission_date' => 'nullable|date',     // Optional
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

### Lesson 4: WHERE Clause Specificity
**Issue:** Must ensure WHERE clauses specify `tax_case_id`.

**Prevention:** Always include `WHERE tax_case_id = $caseId`.

---

### Lesson 5: Date Sequencing Validation
**New Consideration:** Receipt dates should not be before issue dates.

**Prevention:**
- Validate request_receipt_date >= request_issue_date
- Validate explanation_submission_date >= request_receipt_date (when both filled)

---

## 🎯 Stage 9 Specific Notes

### Field Dependencies
- Phase 1 fields (request details) are always shown and required
- Phase 2 fields (explanation submission) become relevant after court request
- Should show as "To be filled when explanation is prepared"

### Real-World Scenario
1. Tax court receives appeal and needs more explanation
2. Court issues "Permintaan Penjelasan Banding" (SPB)
3. Company receives request (Phase 1 data arrives)
4. Coordinator enters Phase 1 and submits Stage 9
5. Company prepares detailed explanation
6. Company submits explanation letter to court
7. Coordinator later enters Phase 2 (letter number and date)
8. Coordinator updates Stage 9 with Phase 2 data

### Optional Stage
- This stage only occurs if tax court requests explanation
- Not all appeals go through this stage
- Some appeals proceed directly to decision (Stage 10)

### Document Upload
- Upload court's request letter (Surat Permintaan Penjelasan Banding) as supporting doc for Phase 1
- Upload company's explanation letter (Surat Penjelasan) as supporting doc for Phase 2
- Documents can be uploaded at any time

---

## 📋 Implementation Order

1. **Day 1 Morning:** Database setup & Models
2. **Day 1 Afternoon:** Backend services & API endpoints with partial update logic
3. **Day 2 Morning:** Frontend components & Router with Phase separation
4. **Day 2 Afternoon:** Revision system & testing (test both phases)

---

## 🚨 Critical Implementation Point

**Multi-Phase Logic:** Stage 9's unique challenge is managing the conditional occurrence and multi-phase nature.

**Important:** Stage 9 is NOT mandatory for all appeals. Some appeals skip directly from Stage 8 to Stage 10.

**Implementation Consideration:**
- In workflow progression, check if Stage 9 is needed
- If court requests explanation → must complete Stage 9
- If court doesn't request explanation → skip to Stage 10
- This decision logic should be handled in the workflow routing

**Test Scenario:**
1. Submit Form with Phase 1 fields only
2. System records status='submitted'
3. Later, return to form
4. Add Phase 2 fields
5. Update submission
6. Verify Phase 1 data preserved
7. Verify workflow_histories updated correctly
8. Verify case can progress to Stage 10

---

**End of Stage 9 Implementation Template**

*Apply lessons from Stage 4/5/6/8 + implement multi-phase logic correctly + handle optional stage routing*
