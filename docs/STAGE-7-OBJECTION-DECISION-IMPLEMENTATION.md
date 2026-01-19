# Stage 7 Implementation - Objection Decision (Keputusan Keberatan)

**Document Type:** Implementation Guide for Stage 7

**Version:** 1.0

**Last Updated:** January 18, 2026

**Based on:** STAGE-4-SKP-IMPLEMENTATION.md Template

---

## 📋 Quick Reference

| Property | Value |
|----------|-------|
| **Stage Number** | 7 |
| **Stage Name** | Objection Decision (Keputusan Keberatan) |
| **Model Name** | ObjectionDecision |
| **Table Name** | objection_decisions |
| **Previous Stage** | 6 (SPUH) |
| **Next Stages** | 8 (Appeal) OR 13 (Refund) - USER CHOICE |
| **Case Status** | OBJECTION_DECISION_RECEIVED |
| **Flow Type** | Decision + Decision-Based Routing (LIKE STAGE 4) |
| **Critical Feature** | WORKFLOW PATH LOCKING (Decision determines accessible stages) |

---

## 🎯 Stage 7 Overview

**Description:** Tax authority's decision on objection with routing to next stage

**Decision-Based Routing (SAME AS STAGE 4):**
- Unlike Stage 4 (user choice), Stage 7 routing is AUTOMATIC based on decision outcome
- Different decision → different next stage
- User CANNOT override (system determines next stage)
- WORKFLOW PATH LOCKING applies (stage_to is set by decision)

---

## 📝 Form Fields Definition

### Fields to Implement (4 fields):

```javascript
const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'decision_number',
    label: 'Nomor Surat Keputusan Keberatan (Decision Number)',
    required: true,
    readonly: false,
    placeholder: 'e.g., KPB/2024/001'
  },
  {
    id: 2,
    type: 'date',
    key: 'decision_date',
    label: 'Tanggal Keputusan (Decision Date)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'select',
    key: 'decision_type',
    label: 'Keputusan (Decision)',
    required: true,
    readonly: false,
    options: [
      { value: 'granted', label: 'Dikabulkan (Granted - Accepted)' },
      { value: 'partially_granted', label: 'Dikabulkan Sebagian (Partially Granted)' },
      { value: 'rejected', label: 'Ditolak (Rejected)' }
    ]
  },
  {
    id: 4,
    type: 'number',
    key: 'decision_amount',
    label: 'Nilai (Decision Amount)',
    required: true,
    readonly: false,
    placeholder: 'Enter decision amount in Rp'
  }
])
```

### Database Schema:

```php
Schema::create('objection_decisions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tax_case_id')
        ->constrained('tax_cases')
        ->cascadeOnDelete();

    $table->string('decision_number')->nullable();
    $table->date('decision_date')->nullable();
    $table->enum('decision_type', ['granted', 'partially_granted', 'rejected'])->nullable();
    $table->decimal('decision_amount', 20, 2)->nullable();
    
    // Workflow routing (auto-set by decision_type)
    $table->integer('next_stage')->nullable()->comment('8=Appeal or 13=Refund, auto-set by system');
    
    $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
    
    $table->timestamps();
    $table->softDeletes();
});
```

### Model Implementation:

```php
<?php
namespace App\Models;

class ObjectionDecision extends Model
{
    use SoftDeletes;
    protected $table = 'objection_decisions';
    protected $fillable = [
        'tax_case_id',
        'decision_number',
        'decision_date',
        'decision_type',
        'decision_amount',
        'next_stage',
        'status'
    ];
    protected $casts = [
        'decision_date' => 'date',
        'decision_amount' => 'decimal:2',
        'next_stage' => 'integer',
    ];
    public function taxCase(): BelongsTo
    {
        return $this->belongsTo(TaxCase::class);
    }
}
```

---

## 🎯 CRITICAL: Automatic Routing Logic (Like Stage 4)

### Decision Point: Auto-Routing Based on decision_type

| decision_type | Next Stage | Description | stage_to Value |
|---|---|---|---|
| **granted** | 13 | Refund Process | 13 |
| **partially_granted** | 8 OR 13 | Appeal OR Refund (USER CHOICE) | Depends on user |
| **rejected** | 8 | Appeal Process | 8 |

### Implementation Strategy

**For "granted" (Auto → Refund):**
```php
if ($decisionType === 'granted') {
    $nextStage = 13;  // Auto-route to Refund
}
```

**For "rejected" (Auto → Appeal):**
```php
if ($decisionType === 'rejected') {
    $nextStage = 8;   // Auto-route to Appeal
}
```

**For "partially_granted" (User Must Choose):**
```php
if ($decisionType === 'partially_granted') {
    // Show decision buttons (Appeal or Refund)
    // User chooses via button click
    // NOT automatic like "granted" or "rejected"
}
```

### Template Implementation:

```vue
<!-- Show buttons ONLY if partially_granted -->
<div v-if="isStage7Submitted && currentDecisionType === 'partially_granted'" class="px-4 py-6">
  <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6">
    <h3 class="text-lg font-semibold text-yellow-900 mb-2">
      ⚠️ Objection Partially Granted
    </h3>
    <p class="text-sm text-yellow-700 mb-4">
      Decision: {{ currentDecisionType }} | Amount: Rp {{ decisionAmount }}
    </p>
    <p class="text-yellow-700 mb-6">
      Select where to proceed next:
    </p>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <!-- Option 1: Appeal -->
      <button @click="proceedToAppeal" class="p-4 border-2 border-blue-300 rounded-lg hover:bg-blue-100">
        <div class="font-semibold text-blue-900 mb-2">
          📋 Proceed to Appeal (Stage 8)
        </div>
      </button>

      <!-- Option 2: Refund -->
      <button @click="proceedToRefund" class="p-4 border-2 border-green-300 rounded-lg hover:bg-green-100">
        <div class="font-semibold text-green-900 mb-2">
          💰 Proceed to Refund (Stage 13)
        </div>
      </button>
    </div>
  </div>
</div>

<!-- Show auto-routing message for granted/rejected -->
<div v-if="isStage7Submitted && currentDecisionType !== 'partially_granted'" class="px-4 py-6">
  <div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
    <h3 class="text-lg font-semibold text-blue-900 mb-2">
      ✅ Decision Recorded
    </h3>
    <p class="text-blue-700">
      <span v-if="currentDecisionType === 'granted'">
        Status: GRANTED → Proceeding to Refund (Stage 13)
      </span>
      <span v-else-if="currentDecisionType === 'rejected'">
        Status: REJECTED → Proceeding to Appeal (Stage 8)
      </span>
    </p>
  </div>
</div>
```

---

## 🔧 Implementation Checklist

### Phase 1: Database Setup
- [ ] Create migration for objection_decisions table (5 fields + next_stage)
- [ ] Create ObjectionDecision model with relationships
- [ ] Add objectionDecision() HasOne relationship to TaxCase
- [ ] Run migration and verify schema

### Phase 2: Backend Services
- [ ] Update RevisionService.php - Add Stage 7 to requestRevision()
- [ ] Update RevisionService.php - Add Stage 7 to approveRevision()
- [ ] Update RevisionService.php - Add Stage 7 to detectStageFromFields()
- [ ] Add stage_code: 7 for revision tracking

### Phase 3: API Endpoints - NEW Workflow-Decision Endpoint
- [ ] Update routes/api.php - Add Stage 7 workflow endpoint
- [ ] Validate 4 fields: decision_number, decision_date, decision_type, decision_amount
- [ ] **Implement Auto-Routing Logic:**
  ```php
  $nextStage = match($decision_type) {
      'granted' => 13,              // Auto to Refund
      'rejected' => 8,              // Auto to Appeal
      'partially_granted' => null   // User must choose
  };
  
  if ($nextStage) {
      // Auto-route: Update workflow_histories with stage_to
      // Create next stage entry
      // Auto-advance case
  }
  ```

### Phase 4: API Endpoints - Decision Choice Endpoint
- [ ] Create POST `/api/tax-cases/{id}/decision-choice` endpoint
- [ ] Validate: current_stage_id=7, choice='appeal' or 'refund'
- [ ] Only accepts choice if current decision_type='partially_granted'
- [ ] Sets stage_to based on choice
- [ ] Validates WHERE tax_case_id = specific case

### Phase 5: Frontend Components
- [ ] Create ObjectionDecisionForm.vue component
- [ ] Integrate StageForm with 4 fields (including select for decision_type)
- [ ] Add computed properties:
  - `isStage7Submitted` - checks if submitted
  - `currentDecisionType` - gets selected decision from form
  - `showChoiceButtons` - true if partially_granted AND submitted
  - `decisionAmount` - displays current decision amount
- [ ] Add conditional rendering: auto-message OR choice buttons
- [ ] Add methods: `proceedToAppeal()` and `proceedToRefund()` (like Stage 4)
- [ ] Integrate RevisionHistoryPanel
- [ ] Add loadTaxCase(), loadRevisions(), loadDocuments()
- [ ] Add router entry: /tax-cases/:id/workflow/7

### Phase 6: Revision System
- [ ] Add decision_number to RequestRevisionModalV2 (text)
- [ ] Add decision_date to RequestRevisionModalV2 (date)
- [ ] Add decision_type to RequestRevisionModalV2 (select with 3 options)
- [ ] Add decision_amount to RequestRevisionModalV2 (number)
- [ ] Update fieldLabel() with all 4 field labels
- [ ] Update getFieldType() with decision_type as select
- [ ] Update getFieldOptions() for decision_type select options

### Phase 7: Testing - Core Functionality
- [ ] Test decision_type='granted' → Auto-route to Stage 13 (Refund)
- [ ] Test decision_type='rejected' → Auto-route to Stage 8 (Appeal)
- [ ] Test decision_type='partially_granted' → Show choice buttons
- [ ] Test choice button click: Appeal → Route to Stage 8
- [ ] Test choice button click: Refund → Route to Stage 13

### Phase 8: Testing - Revisions
- [ ] Request revision on decision_number field
- [ ] Request revision on decision_date field
- [ ] Request revision on decision_type select field (verify options)
- [ ] Request revision on decision_amount field
- [ ] Approve revisions and verify data updates

### Phase 9: Testing - Edge Cases
- [ ] Test changing decision from 'granted' to 'partially_granted' (workflow reset?)
- [ ] Test multiple decision attempts (prevent double-submission)
- [ ] Test navigation to Stage 8 after 'rejected'
- [ ] Test navigation to Stage 13 after 'granted'

---

## ⚠️ Lessons Learned from Stage 4 (To Apply)

### Lesson 1: Workflow Path Locking - CRITICAL
**From Stage 4:** Apply same workflow path locking mechanism here.

**Implementation:**
- Update `workflow_histories` with `stage_to` value
- WHERE clause MUST include `tax_case_id = specific case`
- Set `decision_point` and `decision_value` fields
- Create next stage entry with status='draft'

**Apply:**
```php
// Update Stage 7 history with routing decision
WorkflowHistory::where('tax_case_id', $caseId)
    ->where('stage_id', 7)
    ->latest('created_at')
    ->first()
    ->update([
        'stage_to' => $nextStage,
        'decision_point' => 'objection_decision',
        'decision_value' => $decisionType,
    ]);
```

---

### Lesson 2: Computed Properties for Conditional Buttons
**From Stage 4:** Use Vue computed properties to control button visibility.

**Apply:**
```javascript
const showChoiceButtons = computed(() => {
    return isStage7Submitted.value && 
           currentDecisionType.value === 'partially_granted'
})

const currentDecisionType = computed(() => {
    return prefillData.value.decision_type || null
})
```

---

### Lesson 3: Decision Button Methods
**From Stage 4:** Two decision methods (proceedToAppeal, proceedToRefund) with proper error handling.

**Key Points:**
- Call `/api/tax-cases/{id}/decision-choice` endpoint
- Send: current_stage_id=7, choice='appeal' or 'refund'
- Handle response: success toast and navigate
- Handle errors: show toast with error message

---

### Lesson 4: Auto-Routing vs User Choice
**Key Distinction for Stage 7:**
- `granted` → AUTO-route to Refund (NO choice)
- `rejected` → AUTO-route to Appeal (NO choice)  
- `partially_granted` → USER CHOOSES between Appeal or Refund

Don't add buttons for all decisions, only for `partially_granted`.

---

### Lesson 5: WHERE Clause Isolation
**From Stage 4:** ALWAYS use WHERE tax_case_id to prevent cross-case corruption.

---

## 🎯 Stage 7 Specific Notes

### Decision Type Business Logic
- **Granted** = Tax authority accepts objection completely → Refund authorized
- **Partially Granted** = Accepts part, rejects part → User must choose next action
- **Rejected** = Tax authority rejects objection → User must appeal

### Amount Field
- Represents the APPROVED amount from decision
- Can be less than objection_amount if only partially granted
- Always record with 2 decimal precision

### Case Status Progression
- Before: SPUH_RECEIVED
- After Submit: OBJECTION_DECISION_RECEIVED
- After Routing Decision: GRANTED OR NOT_GRANTED_PARTIAL

---

## � Known Obstacles & Solutions (Inherited from Stage 4 Pattern)

When implementing Stage 7, be aware of these common issues that also apply to other DECISION POINT stages (7, 10, 12):

### Obstacle 1: Decision Field Not Prefilled on Page Reload
**Issue:** User selects decision, submits, then refreshes page - decision radio/select not showing selected value
**Root Cause:** Decision field not in `fields` array, so not initialized in formData during onMounted
**Fix:** Explicitly initialize all decision-related fields including those NOT in the form fields list (see Stage 4 Obstacles section 1)

### Obstacle 2: Stage Accessibility Not Updating (Stages Locked When Should Be Accessible)
**Issue:** After user makes decision, next stage remains locked/not accessible. New stage should appear based on decision outcome
**Root Cause:** API returns snake_case (`objection_decision`) but frontend expects camelCase (`objectionDecision`)
**Fix:** Handle BOTH naming conventions in getUserRoutingChoice() and watchers (see Stage 4 Obstacles section 2)

### Obstacle 3: Watcher Not Triggering When Data Changes
**Issue:** Added watcher for decision field but it never triggers when data loaded
**Root Cause:** Watcher added BEFORE data loaded, or watcher checks wrong property name (case sensitivity)
**Fix:** 
  - Add watcher AFTER onMounted() completes
  - Watch for BOTH snake_case and camelCase: `() => [caseData.value?.objectionDecision?.decision, caseData.value?.objection_decision?.decision]`
  - Add logging to verify which property contains data

### Obstacle 4: Decision Routing Logic Incomplete
**Issue:** Decision made but workflow doesn't route to correct next stage, OR multiple stages appear accessible
**Root Cause:** Accessibility logic missing explicit locking for non-chosen paths
**Fix:** Add explicit `stage.accessible = false` statements for locked stages, not just omitting the true assignment (see Stage 4 Obstacles section 4)

---

## 📋 Implementation Order

1. **Day 1 Morning:** Database setup & Models
2. **Day 1 Afternoon:** Backend services + workflow decision endpoint
3. **Day 2 Morning:** Backend decision-choice endpoint + Frontend components
4. **Day 2 Afternoon:** Revision system integration + Testing (all 3 decision types)

---

## 🚨 CRITICAL SUCCESS FACTORS

1. **Workflow Path Locking:** Must set stage_to correctly for access control to work
2. **WHERE Clause Safety:** All database updates must specify tax_case_id
3. **Decision Logic:** Auto-routing for granted/rejected, user choice for partially_granted
4. **Button Visibility:** ONLY show choice buttons when partially_granted
5. **Computed Properties:** Use Vue reactivity for decision-type monitoring
6. **Field Initialization:** Initialize ALL decision-related fields in formData, not just those in fields list
7. **API Response Handling:** Support BOTH snake_case and camelCase property names
8. **Watcher Timing:** Add watchers AFTER data is loaded, not before

---

**End of Stage 7 Implementation Template**

*Apply workflow path locking + decision-based routing patterns from Stage 4*

*CRITICAL: Implement BOTH auto-routing AND user-choice logic correctly*
*REFER TO STAGE-4-SKP-IMPLEMENTATION.md obstacles section for detailed solutions*
