# Stage 5 Implementation - Objection Submission (Surat Keberatan)

**Document Type:** Implementation Guide for Stage 5

**Version:** 1.0

**Last Updated:** January 18, 2026

**Based on:** STAGE-4-SKP-IMPLEMENTATION.md Template

---

## 📋 Quick Reference

| Property | Value |
|----------|-------|
| **Stage Number** | 5 |
| **Stage Name** | Objection Submission (Surat Keberatan) |
| **Model Name** | ObjectionSubmission |
| **Table Name** | objection_submissions |
| **Previous Stage** | 4 (SKP) |
| **Next Stages** | 6 (SPUH - Summon Letter) |
| **Case Status** | OBJECTION_LETTER_SUBMITTED |
| **Flow Type** | Standard Input & Submission |

---

## 🎯 Stage 5 Overview

**Description:** Filing formal objection to SKP (Tax Assessment Letter) decision

**User Journey:**
1. User accessed Stage 5 after choosing "Proceed to Objection" at Stage 4 (SKP)
2. User fills objection details with supporting documents
3. User submits objection letter
4. System records in workflow_histories
5. Case advances to Stage 6 (SPUH)

---

## 📝 Form Fields Definition

### Fields to Implement (3 fields):

```javascript
const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'objection_number',
    label: 'Nomor Surat Keberatan (Objection Letter Number)',
    required: true,
    readonly: false,
    placeholder: 'e.g., SK-2024-001'
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
    key: 'objection_amount',
    label: 'Nilai Keberatan (Objection Amount)',
    required: true,
    readonly: false,
    placeholder: 'Enter objection amount in Rp'
  }
])
```

### Database Schema:

```php
Schema::create('objection_submissions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tax_case_id')
        ->constrained('tax_cases')
        ->cascadeOnDelete();

    $table->string('objection_number')->nullable();
    $table->date('submission_date')->nullable();
    $table->decimal('objection_amount', 20, 2)->nullable();
    
    $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
    
    $table->timestamps();
    $table->softDeletes();
});
```

### Model Implementation:

```php
<?php
namespace App\Models;

class ObjectionSubmission extends Model
{
    use SoftDeletes;
    protected $table = 'objection_submissions';
    protected $fillable = [
        'tax_case_id',
        'objection_number',
        'submission_date',
        'objection_amount',
        'status'
    ];
    protected $casts = [
        'submission_date' => 'date',
        'objection_amount' => 'decimal:2',
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
- [ ] Create migration for objection_submissions table
- [ ] Create ObjectionSubmission model with relationships
- [ ] Add objectSubmission() HasOne relationship to TaxCase
- [ ] Run migration and verify schema

### Phase 2: Backend Services
- [ ] Update RevisionService.php - Add Stage 5 to requestRevision()
- [ ] Update RevisionService.php - Add Stage 5 to approveRevision()
- [ ] Update RevisionService.php - Add Stage 5 to detectStageFromFields()
- [ ] Add stage_code: 5 for revision tracking

### Phase 3: API Endpoints
- [ ] Update routes/api.php - Add Stage 5 workflow endpoint
- [ ] Validate 3 fields: objection_number, submission_date, objection_amount
- [ ] Handle draft save and submit logic
- [ ] Update current_stage to 5 on submit

### Phase 4: Frontend Components
- [ ] Create ObjectionSubmissionForm.vue component
- [ ] Integrate StageForm with 3 fields
- [ ] Integrate RevisionHistoryPanel
- [ ] Add loadTaxCase(), loadRevisions(), loadDocuments() methods
- [ ] Add router entry: /tax-cases/:id/workflow/5

### Phase 5: Revision System
- [ ] Add objection_number to RequestRevisionModalV2 (text field)
- [ ] Add submission_date to RequestRevisionModalV2 (date field)
- [ ] Add objection_amount to RequestRevisionModalV2 (number field)
- [ ] Update fieldLabel() function with all 3 field labels

### Phase 6: Testing
- [ ] Create objection submission with all fields
- [ ] Request revision on each field type (text, date, number)
- [ ] Approve revisions and verify data updates
- [ ] Upload supporting documents
- [ ] Navigate from Stage 4 to Stage 5 successfully

---

## ⚠️ Lessons Learned from Stage 4 (To Avoid)

### Lesson 1: WHERE Clause Specificity
**Issue in Stage 4:** Initial workflow-decision endpoint didn't specify `tax_case_id` in WHERE clause, risking cross-case updates.

**Prevention for Stage 5:** ALWAYS include `WHERE tax_case_id = $caseId` when updating workflow_histories to ensure single-case isolation.

**Apply:**
```php
// ❌ WRONG - Could update other cases
WorkflowHistory::where('stage_id', 5)->update(['stage_to' => 6]);

// ✅ RIGHT - Single case only
WorkflowHistory::where('tax_case_id', $caseId)
    ->where('stage_id', 5)
    ->update(['stage_to' => 6]);
```

---

### Lesson 2: API Endpoint Naming & Purpose
**Issue in Stage 4:** Confusion about `next_stage_id` field in tax_cases vs `stage_to` in workflow_histories. Led to incorrect API design.

**Prevention for Stage 5:** 
- `tax_cases.current_stage` = Current stage user is working on (denormalized for fast reads)
- `workflow_histories.stage_to` = Next stage LOCK (workflow path decision)
- These are DIFFERENT and both needed - don't try to consolidate

**Apply:** Use dedicated `/workflow-decision` endpoint for path locking, separate from regular `/workflow/{stage}` endpoint.

---

### Lesson 3: Computed Properties for Button Visibility
**Issue in Stage 4:** Initially thought to make Refund button visible based on automatic logic, but user needed explicit choice.

**Prevention for Stage 5:** 
- Stage 5 is standard submission (no special routing yet)
- Don't add decision buttons here
- Decision buttons belong in Stage 7 (Objection Decision) where actual routing happens
- Check STAGE-5 vs STAGE-7 distinction clearly in architecture

**Apply:** Only add conditional button logic at DECISION stages (7, 10, 12), not submission stages (5, 8, 11).

---

### Lesson 4: Field Type Consistency in Revisions
**Issue in Stage 4:** Added SKP Type as select field, needed to ensure RequestRevisionModalV2 had proper select handler.

**Prevention for Stage 5:** 
- All fields are simple types (text, date, number)
- RequestRevisionModalV2 already has handlers for these
- No new handlers needed for Stage 5
- When adding new field types, update RequestRevisionModalV2 FIRST before implementing stage

**Apply:** Test field revision in RequestRevisionModalV2 early in Phase 5.

---

### Lesson 5: Database Transaction & Rollback
**Issue in Stage 4:** Workflow-decision endpoint had complex logic that could fail mid-execution without proper transaction handling.

**Prevention for Stage 5:** 
- Keep workflow-decision logic ONLY in dedicated endpoint
- Regular workflow/{stage} endpoint should have simpler logic
- If update operations are sequential, wrap in DB::beginTransaction/rollBack

**Apply:**
```php
try {
    DB::beginTransaction();
    // Update 1
    // Update 2
    // Create
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    Log::error('Error message', ['details']);
    throw $e;
}
```

---

### Lesson 6: Workflow History Entry Creation
**Issue in Stage 4:** When creating new workflow_histories entry for next stage, had to ensure status='draft' so user could still fill form.

**Prevention for Stage 5:** 
- ALWAYS set status='draft' for newly routed stage
- Don't set status='submitted' until user actually submits
- Include action='routed' or 'submitted' based on context
- Store decision info in decision_point and decision_value fields

**Apply:** Properly initialize workflow_histories entries.

---

## 🎯 Stage 5 Specific Notes

### Automatic Progression
- Stage 5 is NOT a decision stage
- After submission, automatically wait for Stage 6 (SPUH) data
- No user choice like Stage 4 - straightforward progression

### Field Amounts
- `objection_amount` typically equals or is less than the SKP amount
- Can be different if user is contesting specific portion
- Always record with 2 decimal precision

### Status After Submission
- Case Status: OBJECTION_LETTER_SUBMITTED
- User can request revisions while system waits for SPUH
- Hold at Stage 5 until SPUH data arrives (manual entry by tax authority coordinator)

---

## 📋 Implementation Order

1. **Day 1 Morning:** Database setup & Models
2. **Day 1 Afternoon:** Backend services & API endpoints
3. **Day 2 Morning:** Frontend components & Router
4. **Day 2 Afternoon:** Revision system integration & testing

---

**End of Stage 5 Implementation Template**

*Avoid Stage 4 obstacles by implementing lessons learned above*
