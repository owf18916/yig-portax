# Stage 8 Implementation - Appeal Submission (Surat Banding)

**Document Type:** Implementation Guide for Stage 8

**Version:** 1.0

**Last Updated:** January 19, 2026

**Based on:** STAGE-6-SPUH-IMPLEMENTATION.md Template

---

## 📋 Quick Reference

| Property | Value |
|----------|-------|
| **Stage Number** | 8 |
| **Stage Name** | Appeal Submission (Surat Banding) |
| **Model Name** | AppealSubmission |
| **Table Name** | appeal_submissions |
| **Previous Stage** | 7 (Objection Decision) |
| **Next Stages** | 9 (Request for Explanation) or Direct Decision |
| **Case Status** | APPEAL_SUBMITTED |
| **Flow Type** | Multi-Phase Input (Initial + Update) |

---

## 🎯 Stage 8 Overview

**Description:** Filing appeal to tax court after unfavorable objection decision

**Two-Phase Submission:**

**Phase 1: Initial Appeal Filing**
- System records: nomor_surat_banding, tanggal_dilaporkan, nilai
- Status: awaiting court processing

**Phase 2: Dispute Number Assignment**
- Later: Tax court assigns nomor_sengketa
- Status: in review

---

## 📝 Form Fields Definition

### Phase 1 Fields (Initial Appeal - 3 fields):

```javascript
const fieldsPhase1 = ref([
  {
    id: 1,
    type: 'text',
    key: 'appeal_letter_number',
    label: 'Nomor Surat Banding (Appeal Letter Number)',
    required: true,
    readonly: false,
    placeholder: 'e.g., SB/2024/001'
  },
  {
    id: 2,
    type: 'date',
    key: 'submission_date',
    label: 'Tanggal Dilaporkan (Submission Date)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'number',
    key: 'appeal_amount',
    label: 'Nilai (Appeal Amount)',
    required: true,
    readonly: false,
    placeholder: 'e.g., 500000000'
  }
])
```

### Phase 2 Fields (Dispute Number Assignment - 1 field, optional):

```javascript
const fieldsPhase2 = ref([
  {
    id: 4,
    type: 'text',
    key: 'dispute_number',
    label: 'Nomor Sengketa (Dispute Number)',
    required: false,
    readonly: false,
    placeholder: 'e.g., 001/BDG/2024'
  }
])
```

### Database Schema:

```php
Schema::create('appeal_submissions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tax_case_id')
        ->constrained('tax_cases')
        ->cascadeOnDelete();

    // Phase 1: Initial Appeal
    $table->string('appeal_letter_number')->nullable();
    $table->date('submission_date')->nullable();
    $table->decimal('appeal_amount', 15, 0)->nullable();
    
    // Phase 2: Dispute Number (assigned by court)
    $table->string('dispute_number')->nullable();
    
    $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
    
    $table->timestamps();
    $table->softDeletes();
});
```

### Model Implementation:

```php
<?php
namespace App\Models;

class AppealSubmission extends Model
{
    use SoftDeletes;
    protected $table = 'appeal_submissions';
    protected $fillable = [
        'tax_case_id',
        'appeal_letter_number',
        'submission_date',
        'appeal_amount',
        'dispute_number',
        'status'
    ];
    protected $casts = [
        'submission_date' => 'date',
        'appeal_amount' => 'integer',
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
- [ ] Create migration for appeal_submissions table with 4 fields
- [ ] Create AppealSubmission model with relationships
- [ ] Add appealSubmission() HasOne relationship to TaxCase
- [ ] Run migration and verify schema

### Phase 2: Backend Services
- [ ] Update RevisionService.php - Add Stage 8 to requestRevision()
- [ ] Update RevisionService.php - Add Stage 8 to approveRevision()
- [ ] Update RevisionService.php - Add Stage 8 to detectStageFromFields()
- [ ] Ensure all 4 fields tracked for revisions

### Phase 3: API Endpoints
- [ ] Update routes/api.php - Add Stage 8 workflow endpoint
- [ ] Phase 1: Validate appeal_letter_number, submission_date, appeal_amount
- [ ] Phase 2: Accept optional dispute_number
- [ ] Allow partial updates (user can submit phase 1, then phase 2 later)

### Phase 4: Frontend Components
- [ ] Create AppealSubmissionForm.vue component
- [ ] Display Phase 1 fields (required) in main form
- [ ] Display Phase 2 fields (optional) in collapsible/secondary section
- [ ] Add loadTaxCase(), loadRevisions(), loadDocuments()
- [ ] Add router entry: /tax-cases/:id/workflow/8

### Phase 5: Revision System
- [ ] Add all 4 fields to RequestRevisionModalV2
  - appeal_letter_number (text)
  - submission_date (date)
  - appeal_amount (number)
  - dispute_number (text)
- [ ] Update fieldLabel() function with all labels

### Phase 6: Testing
- [ ] Submit Phase 1 only (appeal_letter_number, submission_date, appeal_amount)
- [ ] Request revision on Phase 1 fields
- [ ] Later: Submit Phase 2 field (dispute_number)
- [ ] Request revision on Phase 2 fields
- [ ] Verify partial update logic works

---

## ⚠️ Lessons Learned from Stage 4, 5 & 6 (To Avoid)

### Lesson 1: Partial Update Strategy
**Apply to Stage 8:** This stage has optional Phase 2 field that gets filled later by tax court.

**Prevention:** 
- Don't require all fields at once
- Allow status='submitted' after Phase 1
- Allow tax court to later assign dispute_number without full re-submission
- Use updateOrCreate() pattern for graceful updates

**Apply:**
```php
// Accept partial updates
AppealSubmission::updateOrCreate(
    ['tax_case_id' => $caseId],
    $validatedData // Only provided fields update
);
```

---

### Lesson 2: Optional vs Required Fields
**Issue:** Must distinguish between Phase 1 (required, user-submitted) and Phase 2 (optional, assigned by court).

**Prevention:**
- Frontend: Show Phase 1 fields prominently, Phase 2 in secondary section
- Backend: Validate Phase 1 fields required, Phase 2 optional
- API: Accept requests with only Phase 1 or both phases

**Apply:**
```php
$validated = $request->validate([
    'appeal_letter_number' => 'required|string',
    'submission_date' => 'required|date',
    'appeal_amount' => 'required|integer|min:1',
    'dispute_number' => 'nullable|string',  // Optional, assigned later
]);
```

---

### Lesson 3: Revision Tracking for Multi-Phase Fields
**Issue:** When revising Phase 2 field, need to preserve Phase 1 data that's already submitted.

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

### Lesson 5: Currency/Numeric Field Handling
**New Consideration:** Appeal amount should be stored as numeric value (can be large).

**Prevention:**
- Use DECIMAL(15,0) for amounts
- Validate numeric input
- Format display for readability

---

## 🎯 Stage 8 Specific Notes

### Field Dependencies
- Phase 1 fields (appeal_letter_number, submission_date, appeal_amount) are always required
- Phase 2 field (dispute_number) optional, typically assigned by tax court after initial review

### Real-World Scenario
1. Objection decision is unfavorable (ditolak/rejected)
2. Company decides to appeal to tax court
3. Coordinator enters Phase 1: Surat Banding details and appeal amount
4. System records submission
5. Tax court processes appeal and assigns dispute number
6. Later: Coordinator enters Phase 2 dispute number

### Business Logic
- Appeal can only happen after objection decision (not granted)
- Appeal amount should not exceed original disputed amount
- Multiple appeals not allowed (only one appeal per case)

---

## 📋 Implementation Order

1. **Day 1 Morning:** Database setup & Models
2. **Day 1 Afternoon:** Backend services & API endpoints with partial update logic
3. **Day 2 Morning:** Frontend components & Router with Phase separation
4. **Day 2 Afternoon:** Revision system & testing (test both phases)

---

## 🚨 Critical Implementation Point

**Multi-Phase Logic:** The unique challenge is that Phase 1 is user-submitted but Phase 2 is system-generated (by tax court).

**Test Scenario:**
1. Submit Form with Phase 1 fields only
2. System records status='submitted'
3. Later, tax court processes and assigns dispute_number
4. Admin updates record with Phase 2 field
5. Verify Phase 1 data preserved
6. Verify workflow_histories updated correctly
7. Verify case progresses to Stage 9

---

**End of Stage 8 Implementation Template**

*Apply lessons from Stage 4/5/6 + implement multi-phase logic with partial updates*
