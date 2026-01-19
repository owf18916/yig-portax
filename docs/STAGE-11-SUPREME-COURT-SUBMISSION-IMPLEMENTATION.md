# Stage 11 Implementation - Supreme Court Submission (Peninjauan Kembali)

**Document Type:** Implementation Guide for Stage 11

**Version:** 1.0

**Last Updated:** January 19, 2026

**Based on:** STAGE-6-SPUH-IMPLEMENTATION.md Template

---

## 📋 Quick Reference

| Property | Value |
|----------|-------|
| **Stage Number** | 11 |
| **Stage Name** | Supreme Court Submission (Peninjauan Kembali) |
| **Model Name** | SupremeCourtSubmission |
| **Table Name** | supreme_court_submissions |
| **Previous Stage** | 10 (Appeal Decision) |
| **Next Stages** | 12 (Supreme Court Decision) |
| **Case Status** | REVIEW_BY_SUPREME_COURT |
| **Flow Type** | Single-Phase Submission |

---

## 🎯 Stage 11 Overview

**Description:** Filing final judicial review with Supreme Court after unfavorable appeal decision

**Single-Phase Submission:**
- System records: nomor_surat_peninjauan_kembali, tanggal_dilaporkan, nilai
- Status: submitted for final review
- No additional updates expected after initial submission

---

## 📝 Form Fields Definition

### Form Fields (3 fields):

```javascript
const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'supreme_court_letter_number',
    label: 'Nomor Surat Peninjauan Kembali (Supreme Court Letter Number)',
    required: true,
    readonly: false,
    placeholder: 'e.g., PK/2024/001'
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
    key: 'review_amount',
    label: 'Nilai (Review Amount)',
    required: true,
    readonly: false,
    placeholder: 'e.g., 500000000'
  }
])
```

### Database Schema:

```php
Schema::create('supreme_court_submissions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tax_case_id')
        ->constrained('tax_cases')
        ->cascadeOnDelete();

    // Supreme Court Submission Fields
    $table->string('supreme_court_letter_number')->nullable();
    $table->date('submission_date')->nullable();
    $table->decimal('review_amount', 15, 0)->nullable();
    
    $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
    
    $table->timestamps();
    $table->softDeletes();
});
```

### Model Implementation:

```php
<?php
namespace App\Models;

class SupremeCourtSubmission extends Model
{
    use SoftDeletes;
    protected $table = 'supreme_court_submissions';
    protected $fillable = [
        'tax_case_id',
        'supreme_court_letter_number',
        'submission_date',
        'review_amount',
        'status'
    ];
    protected $casts = [
        'submission_date' => 'date',
        'review_amount' => 'integer',
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
- [ ] Create migration for supreme_court_submissions table with 3 fields
- [ ] Create SupremeCourtSubmission model with relationships
- [ ] Add supremeCourtSubmission() HasOne relationship to TaxCase
- [ ] Run migration and verify schema

### Phase 2: Backend Services
- [ ] Update RevisionService.php - Add Stage 11 to requestRevision()
- [ ] Update RevisionService.php - Add Stage 11 to approveRevision()
- [ ] Update RevisionService.php - Add Stage 11 to detectStageFromFields()
- [ ] Ensure all 3 fields tracked for revisions

### Phase 3: API Endpoints
- [ ] Update routes/api.php - Add Stage 11 workflow endpoint
- [ ] Validate supreme_court_letter_number, submission_date, review_amount
- [ ] Enforce single submission per case (no re-submission allowed)

### Phase 4: Frontend Components
- [ ] Create SupremeCourtSubmissionForm.vue component
- [ ] Display all 3 fields in single form
- [ ] Add loadTaxCase(), loadRevisions(), loadDocuments()
- [ ] Add router entry: /tax-cases/:id/workflow/11

### Phase 5: Revision System
- [ ] Add all 3 fields to RequestRevisionModalV2
  - supreme_court_letter_number (text)
  - submission_date (date)
  - review_amount (number)
- [ ] Update fieldLabel() function with all labels

### Phase 6: Testing
- [ ] Submit form with all 3 fields
- [ ] Request revision on one or more fields
- [ ] Update submitted data
- [ ] Verify complete form update works
- [ ] Verify workflow_histories updated correctly
- [ ] Verify case progresses to Stage 12

---

## ⚠️ Lessons Learned from Stage 4, 5, 6, 8 & 9 (To Avoid)

### Lesson 1: Single-Phase Submission
**Important for Stage 11:** This is a single-phase submission, unlike multi-phase stages (6, 8, 9).

**Prevention:**
- All fields required at once
- No partial submission allowed
- Once submitted, cannot change without revision request
- Simpler logic than multi-phase stages

**Apply:**
```php
// All fields required - single complete submission
SupremeCourtSubmission::create([
    'tax_case_id' => $caseId,
    'supreme_court_letter_number' => $validated['supreme_court_letter_number'],
    'submission_date' => $validated['submission_date'],
    'review_amount' => $validated['review_amount'],
    'status' => 'submitted'
]);
```

---

### Lesson 2: Single Submission Per Case
**Critical Rule for Stage 11:** Only one Supreme Court submission allowed per case.

**Prevention:**
- Check if record exists before creating
- If exists, use update instead of create
- Prevent duplicate submissions
- Validate uniqueness at database level

**Apply:**
```php
// Prevent duplicate submissions
$existing = SupremeCourtSubmission::where('tax_case_id', $caseId)->first();
if ($existing) {
    // Update existing, don't create new
    $existing->update($validatedData);
} else {
    // Create new
    SupremeCourtSubmission::create([...]);
}
```

---

### Lesson 3: Amount Validation
**Issue:** Review amount should be reasonable (not negative, not exceed previous amounts).

**Prevention:**
- Validate amount > 0
- Can optionally validate against appeal amount
- Consider if amount should be <= to previous appeal/objection amount

**Apply:**
```php
$validated = $request->validate([
    'supreme_court_letter_number' => 'required|string',
    'submission_date' => 'required|date',
    'review_amount' => 'required|integer|min:1',  // Must be positive
]);
```

---

### Lesson 4: WHERE Clause Specificity
**Issue:** Must ensure WHERE clauses specify `tax_case_id`.

**Prevention:** Always include `WHERE tax_case_id = $caseId`.

---

### Lesson 5: Case Status Transition
**New Consideration:** This stage transitions case to "REVIEW_BY_SUPREME_COURT" status.

**Prevention:**
- Update case status when submission approved
- Ensure status transitions are sequential
- Don't allow backward status transitions

---

## 🎯 Stage 11 Specific Notes

### Eligibility Criteria
- Can only submit if Stage 10 (Appeal Decision) resulted in:
  - Ditolak (Rejected), OR
  - Dikabulkan Sebagian (Partially Granted) when user chooses Supreme Court review
- Cannot submit if previous decision was Dikabulkan (Granted) - goes to refund instead

### Real-World Scenario
1. Appeal decision is unfavorable (ditolak/rejected)
2. Company decides to file Supreme Court review
3. Coordinator enters Stage 11:
   - Nomor Surat Peninjauan Kembali (letter number)
   - Tanggal Dilaporkan (submission date to court)
   - Nilai (amount being reviewed)
4. System records submission
5. Case awaits Supreme Court decision (Stage 12)

### Business Logic
- This is the FINAL judicial level
- After Supreme Court decision, no further appeals allowed
- Decision leads to either Refund or KIAN procedure

### Document Upload
- Upload Surat Peninjauan Kembali (Supreme Court review letter) as supporting doc
- Document proves formal submission to Supreme Court
- Essential for audit trail

### Timeline Consideration
- This is typically the final step in dispute resolution
- Processing time can be lengthy (months to years)
- System should track time in this stage for performance analysis

---

## 📋 Implementation Order

1. **Day 1 Morning:** Database setup & Models
2. **Day 1 Afternoon:** Backend services & API endpoints
3. **Day 2 Morning:** Frontend components & Router
4. **Day 2 Afternoon:** Revision system & testing

---

## 🚨 Critical Implementation Point

**Terminal Stage Logic:** Stage 11 is critical because it's the LAST judicial review level.

**Important Considerations:**
1. After Supreme Court decision (Stage 12), case goes to final terminal state
2. No more appeals possible after Stage 12
3. Decision determines: Refund Procedure (Granted) OR KIAN Procedure (Rejected)

**Test Scenario:**
1. Create case in Stage 10 with Ditolak decision
2. Submit Stage 11 form with all 3 fields
3. Verify case status changes to "REVIEW_BY_SUPREME_COURT"
4. Request revision on one field
5. Update and verify data persists correctly
6. Verify workflow_histories updated correctly
7. Verify case ready for Stage 12 progression

---

## 🔗 Related Stages

| Stage | Description | Next Options |
|-------|-------------|--------------|
| 10 | Appeal Decision | → Refund (if Granted) → Stage 11 (if Rejected/Partial) |
| **11** | **Supreme Court Submission** | **→ Stage 12** |
| 12 | Supreme Court Decision | → Refund (if Granted) → KIAN (if Rejected) |

---

**End of Stage 11 Implementation Template**

*Single-phase submission for final judicial review - apply lessons from previous stages + enforce single submission per case rule*
