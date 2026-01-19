# Stage 4 Implementation - SKP (Surat Ketetapan Pajak)

**Document Type:** Implementation Guide for Stage 4

**Version:** 1.0

**Last Updated:** January 18, 2026

**Based on:** STAGE-4-IMPLEMENTATION.md Template

---

## 📋 Table of Contents

1. [Stage Identity](#stage-identity)
2. [Form Definition](#form-definition)
3. [Decision Logic (Special Feature)](#decision-logic-special-feature)
4. [Database Schema](#database-schema)
5. [Backend Implementation](#backend-implementation)
6. [Frontend Implementation](#frontend-implementation)
7. [API Endpoint Configuration](#api-endpoint-configuration)
8. [Revision System Integration](#revision-system-integration)
9. [Known Obstacles & Solutions](#known-obstacles--solutions)
10. [Implementation Checklist](#implementation-checklist)

---

# STAGE 4: SKP IMPLEMENTATION

---

## Stage Identity

| Property | Value |
|----------|-------|
| **Stage Number** | 4 |
| **Stage Name** | SKP (Surat Ketetapan Pajak) |
| **Stage Display** | "SKP" |
| **Model Name** | SkpRecord |
| **Table Name** | skp_records |
| **Related Model** | TaxCase (HasOne relationship) |
| **Case Status** | SKP_RECEIVED |
| **Decision Point** | Jenis SKP (SKP Type) |
| **Decision Logic** | Routes to different next stage based on skp_type |

---

## Form Definition

### B2.1 Form Fields (for SkpFilingForm.vue)

```javascript
const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'skp_number',
    label: 'Nomor SKP (SKP Number)',
    required: true,
    readonly: false,
    placeholder: 'e.g., SKP/2024/0001'
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
  },
  {
    id: 4,
    type: 'select',
    key: 'skp_type',
    label: 'Jenis SKP (SKP Type)',
    required: true,
    readonly: false,
    options: [
      { value: 'LB', label: 'SKP LB (Lebih Bayar - Overpayment)' },
      { value: 'NIHIL', label: 'NIHIL (Zero)' },
      { value: 'KB', label: 'SKP KB (Kurang Bayar - Underpayment)' }
    ]
  },
  {
    id: 5,
    type: 'number',
    key: 'skp_amount',
    label: 'Nilai SKP (SKP Amount)',
    required: true,
    readonly: false,
    placeholder: 'Enter SKP amount'
  },
  {
    id: 6,
    type: 'number',
    key: 'royalty_correction',
    label: 'Royalty Correction Amount',
    required: false,
    readonly: false,
    placeholder: 'Enter royalty correction'
  },
  {
    id: 7,
    type: 'number',
    key: 'service_correction',
    label: 'Service Correction Amount',
    required: false,
    readonly: false,
    placeholder: 'Enter service correction'
  },
  {
    id: 8,
    type: 'number',
    key: 'other_correction',
    label: 'Other Correction Amount',
    required: false,
    readonly: false,
    placeholder: 'Enter other correction'
  },
  {
    id: 9,
    type: 'textarea',
    key: 'correction_notes',
    label: 'Catatan untuk koreksi Other (Notes for Other Corrections)',
    required: false,
    readonly: false,
    placeholder: 'Provide details for other corrections...'
  }
])
```

### B2.2 prefillData Structure

```javascript
const prefillData = ref({
  skp_number: '',
  issue_date: null,
  receipt_date: null,
  skp_type: '',  // 'LB', 'NIHIL', or 'KB'
  skp_amount: 0,
  royalty_correction: 0,
  service_correction: 0,
  other_correction: 0,
  correction_notes: '',
  user_routing_choice: '', // 'refund' or 'objection' - User's explicit choice
  workflowHistories: []
})
```

### B2.3 Available Fields for Revision

```javascript
const availableFields = [
  'skp_number',
  'issue_date',
  'receipt_date',
  'skp_type',
  'skp_amount',
  'royalty_correction',
  'service_correction',
  'other_correction',
  'correction_notes',
  'user_routing_choice',
  'supporting_docs'
]
```

---

## Decision Logic (Special Feature)

### ⚠️ CRITICAL: User Choice After SKP - Not Automatic Routing

**This is a KEY DIFFERENTIATOR from previous stages. The user MUST explicitly choose between Refund and Objection paths REGARDLESS of which SKP Type they select (LB, NIHIL, or KB). The system does NOT automatically route based on skp_type.**

Unlike other stages where routing is automatic based on decision outcomes, Stage 4 (SKP) gives the user explicit control over the next path via the `user_routing_choice` field.

### B3.1 Decision Tree - Real-Time Trigger (Embedded in Form)

```
Stage 4: SKP Received
        ↓
   User enters SKP data
   (Type: LB, NIHIL, or KB)
        ↓
   User selects skp_type (dropdown change)
        ↓
   ⚡ INSTANT TRIGGER (Real-Time, NO API call needed)
        ↓
   ┌────────────────────────────────────────────────┐
   │  Decision Options APPEAR INLINE in form        │
   │  (Below SKP Type field)                        │
   │                                                │
   │  ○ Proceed to Objection (Stage 5)             │
   │  ○ Proceed to Refund (Stage 13)               │
   │                                                │
   │  User clicks one → selected instantly          │
   └────────────────────────────────────────────────┘
              ↙              ↘
      User Selects:      User Selects:
      Objection          Refund
         ↓                     ↓
      user_routing_choice:  user_routing_choice:
      'objection'          'refund'
         ↓                     ↓
      Stored in form      Stored in form
      state               state
         ↓                     ↓
   User clicks "Submit & Continue" OR "Save as Draft"
         ↓
   POST/PATCH to API with:
   - SKP data (all 9 fields)
   - user_routing_choice value
         ↓
   API updates DB:
   - skp_records table
   - tax_cases.next_stage_id (13 for 'refund', 5 for 'objection')
```

**CRITICAL DIFFERENCE**: 
- ✅ Decision Options appear INSTANTLY when skp_type is selected (Vue watcher, no API call)
- ✅ User selects choice (radio button)
- ✅ Choice stored in component state AND in `user_routing_choice` field
- ✅ Choice persisted to DB ONLY when user clicks "Submit & Continue" or "Save as Draft"
- ❌ NO auto-submission, NO page refresh needed
- ❌ Decision is NOT determined by skp_type - user has explicit choice

### B3.2 Decision Logic by Choice

| User Choice | Next Stage | Case Status | DB Field |
|------------|-----------|-------------|----------|
| **Proceed to Refund** | 13 (Bank Transfer Request) | SKP_RECEIVED | user_routing_choice = 'refund' |
| **Proceed to Objection** | 5 (Surat Keberatan) | SKP_RECEIVED | user_routing_choice = 'objection' |

**Note:** Both options are available regardless of SKP Type selected (LB, NIHIL, or KB)

### B3.3 Backend Implementation of Decision Logic

**Location:** `app/Http/Controllers/Api/SkpRecordController.php` (update() method)

```php
/**
 * Determine next stage based on user's explicit choice
 * NOT based on SKP type - user selects via user_routing_choice field
 * 
 * @param string $userChoice - 'refund' or 'objection'
 * @return int - Next stage ID (13 for refund, 5 for objection)
 */
private function getNextStageFromUserChoice(string $userChoice): int
{
    return match($userChoice) {
        'refund' => 13,      // Bank Transfer Request
        'objection' => 5,    // Surat Keberatan (Objection)
        default => 5         // Default to Objection if invalid
    };
}
```

**Usage in store() method:**

```php
// User's explicit choice is in the form data
$userChoice = $validated['user_routing_choice']; // 'refund' or 'objection'

// Determine next stage based on user's choice, NOT skp_type
if ($userChoice) {
    $nextStageId = $this->getNextStageFromUserChoice($userChoice);
    
    $taxCase->update([
        'next_stage_id' => $nextStageId
    ]);
}
```

### B3.4 Frontend Decision Handler in SkpFilingForm.vue (Embedded)

The decision options are embedded directly in the form component, not as separate component.

```javascript
/**
 * Handle user's choice for next action
 * User can choose to proceed to Refund or Objection
 * regardless of SKP type
 */
const selectedRoutingChoice = ref('') // 'refund' or 'objection'

// Watcher to show options when skp_type is selected
watch(() => formData.value.skp_type, (newType) => {
  if (newType) {
    // Show decision options when skp_type is selected
    showDecisionOptions.value = true
  }
})

const handleRoutingChoice = (choice) => {
  selectedRoutingChoice.value = choice
  formData.value.user_routing_choice = choice
  
  console.log(`User selected routing: ${choice}`)
  console.log(`Case will proceed to: ${choice === 'refund' ? 'Stage 13 (Refund)' : 'Stage 5 (Objection)'}`)
}
```

**In Form Template (Embedded):**

```vue
<!-- Decision Options - Show when skp_type is selected -->
<div v-if="formData.skp_type && showDecisionOptions" class="mt-6 p-4 bg-blue-50 border-2 border-blue-200 rounded-lg">
  <h3 class="text-lg font-semibold text-blue-900 mb-4">Select Next Action</h3>
  <p class="text-sm text-blue-600 mb-4">
    Your SKP type is: <strong>{{ skpTypeLabel }}</strong>. 
    Choose where this case will proceed next:
  </p>
  
  <div class="space-y-3">
    <label class="flex items-center p-3 border-2 rounded-lg cursor-pointer transition"
      :class="[
        selectedRoutingChoice === 'objection'
          ? 'bg-white border-blue-500 ring-2 ring-blue-300'
          : 'border-gray-300 hover:border-blue-400'
      ]">
      <input
        type="radio"
        value="objection"
        v-model="selectedRoutingChoice"
        @change="handleRoutingChoice('objection')"
        class="w-4 h-4 text-blue-600"
      />
      <div class="ml-3 flex-1">
        <p class="font-medium text-gray-900">→ Proceed to Objection (Stage 5)</p>
        <p class="text-sm text-gray-600">File Surat Keberatan</p>
      </div>
      <span v-if="selectedRoutingChoice === 'objection'" class="ml-2 px-2 py-1 bg-green-100 text-green-800 text-xs font-semibold rounded">
        SELECTED
      </span>
    </label>

    <label class="flex items-center p-3 border-2 rounded-lg cursor-pointer transition"
      :class="[
        selectedRoutingChoice === 'refund'
          ? 'bg-white border-green-500 ring-2 ring-green-300'
          : 'border-gray-300 hover:border-green-400'
      ]">
      <input
        type="radio"
        value="refund"
        v-model="selectedRoutingChoice"
        @change="handleRoutingChoice('refund')"
        class="w-4 h-4 text-green-600"
      />
      <div class="ml-3 flex-1">
        <p class="font-medium text-gray-900">✓ Proceed to Refund (Stage 13)</p>
        <p class="text-sm text-gray-600">Request Bank Transfer</p>
      </div>
      <span v-if="selectedRoutingChoice === 'refund'" class="ml-2 px-2 py-1 bg-green-100 text-green-800 text-xs font-semibold rounded">
        SELECTED
      </span>
    </label>
  </div>
  
  <div v-if="selectedRoutingChoice" class="mt-4 p-3 bg-white rounded border-l-4 border-green-500">
    <p class="text-sm text-gray-700">
      <strong>✓ Selected:</strong>
      {{ selectedRoutingChoice === 'refund' ? 'Refund (Stage 13)' : 'Objection (Stage 5)' }}
    </p>
    <p class="text-xs text-gray-600 mt-1">
      This choice will be saved when you click "Submit & Continue" or "Save as Draft"
    </p>
  </div>
</div>
```

---

## Database Schema

### B4.1 Check Existing Tables First

```sql
-- Before creating new table, check if skp_records exists:
PRAGMA table_info(skp_records);

-- If exists, check columns:
SELECT sql FROM sqlite_master WHERE type='table' AND name='skp_records';

-- If not exists, proceed to create migration
```

### B4.2 Migration File

**File:** `database/migrations/2026_01_01_000006_create_audit_process_tables.php`
(This table is likely part of the existing audit process migration)

```php
// In the up() method, add/update skp_records table:

Schema::create('skp_records', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tax_case_id')
        ->constrained('tax_cases')
        ->cascadeOnDelete();

    // SKP-specific fields
    $table->string('skp_number')->nullable();
    $table->date('issue_date')->nullable();
    $table->date('receipt_date')->nullable();
    
    // Decision field - stores SKP type
    $table->enum('skp_type', ['LB', 'NIHIL', 'KB'])->nullable();
    
    // Amount fields - all decimal for financial precision
    $table->decimal('skp_amount', 15, 2)->default(0);
    $table->decimal('royalty_correction', 15, 2)->default(0);
    $table->decimal('service_correction', 15, 2)->default(0);
    $table->decimal('other_correction', 15, 2)->default(0);
    
    // Notes field
    $table->text('correction_notes')->nullable();

    // User's explicit routing choice
    $table->enum('user_routing_choice', ['refund', 'objection'])->nullable()->comment('User explicit choice: refund or objection');

    $table->timestamps();
    $table->softDeletes();
});
```

### B4.3 Model Implementation

**File:** `app/Models/SkpRecord.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkpRecord extends Model
{
    use SoftDeletes;

    protected $table = 'skp_records';

    protected $fillable = [
        'tax_case_id',
        'skp_number',
        'issue_date',
        'receipt_date',
        'skp_type',
        'skp_amount',
        'royalty_correction',
        'service_correction',
        'other_correction',
        'correction_notes',
        'user_routing_choice'
    ];

    protected $casts = [
        'issue_date' => 'date',
        'receipt_date' => 'date',
        'skp_amount' => 'decimal:2',
        'royalty_correction' => 'decimal:2',
        'service_correction' => 'decimal:2',
        'other_correction' => 'decimal:2',
    ];

    /**
     * Relationship: SKP Record belongs to Tax Case
     */
    public function taxCase(): BelongsTo
    {
        return $this->belongsTo(TaxCase::class);
    }
}
```

### B4.4 TaxCase Model Relationship

**File:** `app/Models/TaxCase.php`

Add this relationship method:

```php
/**
 * SKP Record relationship
 */
public function skpRecord(): HasOne
{
    return $this->hasOne(SkpRecord::class);
}
```

---

## Backend Implementation

### B5.1 Update RevisionService for Stage 4

**File:** `app/Services/RevisionService.php`

#### In requestRevision() method, add Stage 4 support:

```php
// Around line where stage 2 and 3 are handled, add:
elseif ((int)$stageCode === 4) {
    if (!$revisable->relationLoaded('skpRecord')) {
        $revisable->load('skpRecord');
    }
    $dataSource = $revisable->skpRecord;
}
```

#### In approveRevision() method, add Stage 4 support:

```php
// Around line where stage updates are handled, add:
elseif ($stageCode == 4 && $revisable->skpRecord) {
    $updateTarget = $revisable->skpRecord;
}

// After update target is determined, handle user routing choice:
if ($stageCode === 4 && isset($updateData['user_routing_choice'])) {
    $nextStageId = $this->getNextStageForSkp($updateData['user_routing_choice']);
    $revisable->update(['next_stage_id' => $nextStageId]);
}
```

**Status**: 🟡 Needs update - currently uses skp_type, should use user_routing_choice

### B5.2 Stage Code Detection

**File:** `app/Services/RevisionService.php`

Update the field detection logic:

```php
private function detectStageFromFields(array $fieldNames): ?int
{
    $stageFieldMaps = [
        2 => ['sp2_number', 'issue_date', 'receipt_date', 'auditor_name', 'auditor_phone', 'auditor_email'],
        3 => ['sphp_number', 'sphp_issue_date', 'sphp_receipt_date', 'royalty_finding', 'service_finding', 'other_finding'],
        4 => ['skp_number', 'issue_date', 'receipt_date', 'skp_type', 'skp_amount', 'royalty_correction', 'service_correction', 'other_correction', 'correction_notes', 'user_routing_choice'],
        // ... other stages
    ];

    foreach ($stageFieldMaps as $stage => $fields) {
        foreach ($fieldNames as $field) {
            if (in_array($field, $fields)) {
                return $stage;
            }
        }
    }
    return null;
}
```

**Status**: ✅ Already handles user_routing_choice

### B5.3 Update SkpRecordController Decision Logic

**File:** `app/Http/Controllers/Api/SkpRecordController.php`

The controller's decision logic needs to be **UPDATED** from automatic SKP Type routing to user choice routing:

```php
/**
 * CRITICAL UPDATE: Change from automatic routing to user choice
 * 
 * OLD (Current - INCORRECT):
 * - determineNextStageFromSkpType() matches skp_type directly
 * - LB → 12, NIHIL/KB → 5 (automatic, no user choice)
 * 
 * NEW (Correct):
 * - Use user_routing_choice field to determine next stage
 * - user_routing_choice = 'refund' → Stage 13
 * - user_routing_choice = 'objection' → Stage 5
 */
private function determineNextStageFromUserChoice(string $userChoice): int
{
    return match($userChoice) {
        'refund' => 13,      // Bank Transfer Request
        'objection' => 5,    // Surat Keberatan (Objection)
        default => 5         // Default to Objection if invalid
    };
}
```

**Update validation to include user_routing_choice:**

```php
$validated = $request->validate([
    'skp_number' => 'required|string|unique:skp_records',
    'issue_date' => 'required|date',
    'receipt_date' => 'nullable|date',
    'skp_type' => 'required|in:LB,NIHIL,KB',
    'skp_amount' => 'required|numeric|min:0',
    'royalty_correction' => 'nullable|numeric|min:0',
    'service_correction' => 'nullable|numeric|min:0',
    'other_correction' => 'nullable|numeric|min:0',
    'correction_notes' => 'nullable|string',
    'user_routing_choice' => 'required|in:refund,objection',  // ← ADD THIS
]);
```

**In store() method, use user_routing_choice NOT skp_type:**

```php
// When creating SKP record, use user_routing_choice NOT skp_type
if (isset($validated['user_routing_choice']) && $validated['user_routing_choice']) {
    $nextStageId = $this->determineNextStageFromUserChoice($validated['user_routing_choice']);
    
    $taxCase->update([
        'next_stage_id' => $nextStageId
    ]);
}
```

**Status**: 🟡 CRITICAL - Needs update - currently uses skp_type for routing

---

## Frontend Implementation

### B6.1 Create SkpFilingForm.vue Component

**File:** `resources/js/pages/SkpFilingForm.vue`

```vue
<template>
  <div class="flex h-screen bg-gray-100">
    <!-- Left side: Form + Documents -->
    <div class="w-1/2 overflow-y-auto bg-white">
      <div class="p-6">
        <h1 class="text-3xl font-bold text-gray-900 mb-6">
          Stage 4: SKP (Surat Ketetapan Pajak)
        </h1>

        <!-- Main Form -->
        <StageForm
          :fields="fields"
          :formData="formData"
          :prefillData="prefillData"
          :formErrors="formErrors"
          :submissionComplete="submissionComplete"
          :fieldsDisabled="fieldsDisabled"
          :entityType="'skp'"
          @submit="handleSubmit"
          @save-draft="handleSaveDraft"
          @update:formData="(v) => (formData = v)"
          @field-changed="handleFieldChanged"
        />

        <!-- ⭐ REAL-TIME DECISION OPTIONS PANEL (Appears when skp_type is selected) -->
        <DecisionOptionsPanel
          v-if="formData.skp_type"
          :stageCode="4"
          :triggerValue="formData.skp_type"
          :currentValue="nextActionChoice"
          :options="decisionOptions"
          :displayMode="'radio'"
          class="mt-6"
          @update:modelValue="(choice) => handleDecisionChoice(choice)"
        />
      </div>
    </div>

    <!-- Right side: Revision History + Documents -->
    <div class="w-1/2 overflow-y-auto bg-gray-50">
      <RevisionHistoryPanel
        :revisions="revisions"
        :fields="fields"
        :entityType="'skp'"
        :stageCode="4"
        @revision-requested="loadRevisions"
        @refresh="refreshTaxCase"
      />
      
      <!-- Documents Section -->
      <div class="p-6 border-t">
        <h3 class="text-lg font-semibold mb-4">Supporting Documents (SKP)</h3>
        <div class="space-y-4">
          <div
            v-for="doc in documents"
            :key="doc.id"
            class="flex justify-between items-center p-3 bg-white rounded border"
          >
            <span class="text-sm">{{ doc.file_name }}</span>
            <button
              @click="deleteDocument(doc.id)"
              class="text-red-600 hover:text-red-800 text-sm"
            >
              Delete
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import StageForm from '@/components/StageForm.vue'
import RevisionHistoryPanel from '@/components/RevisionHistoryPanel.vue'
import DecisionOptionsPanel from '@/components/DecisionOptionsPanel.vue'
import { useRevisionAPI } from '@/composables/useRevisionAPI'
import axios from 'axios'

// Props & Emits
const props = defineProps({
  caseId: {
    type: [String, Number],
    required: true
  }
})

// Composition API
const { getFieldLabel } = useRevisionAPI()

// State
const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'skp_number',
    label: 'Nomor SKP (SKP Number)',
    required: true,
    readonly: false,
    placeholder: 'e.g., SKP/2024/0001'
  },
  {
    id: 2,
    type: 'date',
    key: 'skp_issue_date',
    label: 'Tanggal Diterbitkan (Issue Date)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'date',
    key: 'skp_receipt_date',
    label: 'Tanggal Diterima (Receipt Date)',
    required: true,
    readonly: false
  },
  {
    id: 4,
    type: 'select',
    key: 'skp_type',
    label: 'Jenis SKP (SKP Type)',
    required: true,
    readonly: false,
    options: [
      { value: 'LB', label: 'SKP LB (Lebih Bayar - Overpayment)' },
      { value: 'NIHIL', label: 'NIHIL (Zero)' },
      { value: 'KB', label: 'SKP KB (Kurang Bayar - Underpayment)' }
    ]
  },
  {
    id: 5,
    type: 'number',
    key: 'skp_amount',
    label: 'Nilai SKP (SKP Amount)',
    required: true,
    readonly: false,
    placeholder: 'Enter SKP amount'
  },
  {
    id: 6,
    type: 'number',
    key: 'royalty_correction',
    label: 'Royalty Correction Amount',
    required: false,
    readonly: false,
    placeholder: 'Enter royalty correction'
  },
  {
    id: 7,
    type: 'number',
    key: 'service_correction',
    label: 'Service Correction Amount',
    required: false,
    readonly: false,
    placeholder: 'Enter service correction'
  },
  {
    id: 8,
    type: 'number',
    key: 'other_correction',
    label: 'Other Correction Amount',
    required: false,
    readonly: false,
    placeholder: 'Enter other correction'
  },
  {
    id: 9,
    type: 'textarea',
    key: 'correction_notes',
    label: 'Catatan untuk koreksi Other (Notes for Other Corrections)',
    required: false,
    readonly: false,
    placeholder: 'Provide details for other corrections...'
  }
])

// ⭐ DECISION OPTIONS FOR REAL-TIME DISPLAY
const decisionOptions = ref([
  { value: 'objection', label: 'Proceed to Objection (Stage 5)', nextStage: 5, nextStageName: 'Surat Keberatan' },
  { value: 'refund', label: 'Proceed to Refund (Stage 13)', nextStage: 13, nextStageName: 'Bank Transfer Request' }
])

const prefillData = ref({
  skp_number: '',
  skp_issue_date: null,
  skp_receipt_date: null,
  skp_type: '',
  skp_amount: 0,
  royalty_correction: 0,
  service_correction: 0,
  other_correction: 0,
  correction_notes: '',
  workflowHistories: []
})

const formData = ref({
  skp_number: '',
  skp_issue_date: null,
  skp_receipt_date: null,
  skp_type: '',
  skp_amount: 0,
  royalty_correction: 0,
  service_correction: 0,
  other_correction: 0,
  correction_notes: ''
})

// ⭐ REAL-TIME DECISION STATE
const nextActionChoice = ref('') // User's choice: 'objection' or 'refund'

const formErrors = ref({})
const revisions = ref([])
const documents = ref([])
const submissionComplete = ref(false)
const fieldsDisabled = ref(false)

// Lifecycle
onMounted(async () => {
  await loadTaxCase()
  await loadRevisions()
  await loadDocuments()
})

// Methods
const loadTaxCase = async () => {
  try {
    const response = await axios.get(`/api/tax-cases/${props.caseId}`)
    const data = response.data.data

    if (data.skp_record) {
      Object.keys(data.skp_record).forEach((key) => {
        if (key in prefillData.value) {
          prefillData.value[key] = data.skp_record[key]
          formData.value[key] = data.skp_record[key]
        }
      })
    }
  } catch (error) {
    console.error('Error loading tax case:', error)
  }
}

const loadRevisions = async () => {
  try {
    const response = await axios.get(
      `/api/tax-cases/${props.caseId}/revisions?stage_code=4`
    )
    revisions.value = response.data.data || []
  } catch (error) {
    console.error('Error loading revisions:', error)
  }
}

const loadDocuments = async () => {
  try {
    const response = await axios.get(
      `/api/tax-cases/${props.caseId}/documents?stage_code=4`
    )
    documents.value = response.data.data || []
  } catch (error) {
    console.error('Error loading documents:', error)
  }
}

const handleSubmit = async () => {
  try {
    submissionComplete.value = true
    fieldsDisabled.value = true

    // ⭐ Include user's decision choice in submission
    const submitData = {
      ...formData.value,
      next_action: nextActionChoice.value // Include the user's choice
    }

    await axios.post(`/api/tax-cases/${props.caseId}/workflow/4`, submitData)
    await refreshTaxCase()
  } catch (error) {
    console.error('Error submitting:', error)
    submissionComplete.value = false
    fieldsDisabled.value = false
  }
}

const handleSaveDraft = async () => {
  try {
    // ⭐ Include user's decision choice in draft save
    const draftData = {
      ...formData.value,
      next_action: nextActionChoice.value // Include the user's choice
    }

    await axios.patch(`/api/tax-cases/${props.caseId}/workflow/4`, draftData)
    await refreshTaxCase()
  } catch (error) {
    console.error('Error saving draft:', error)
  }
}

const handleFieldChanged = (fieldKey) => {
  // ⭐ Real-time decision trigger happens here via DecisionOptionsPanel
  // No additional logic needed - DecisionOptionsPanel is tied to formData.skp_type
}

// ⭐ HANDLE USER'S DECISION CHOICE (Real-Time - No API call)
const handleDecisionChoice = (choice) => {
  nextActionChoice.value = choice
  console.log(`User selected: ${choice}`)
  // This is stored in component state and will be sent when user clicks Submit/Save Draft
}

const deleteDocument = async (docId) => {
  try {
    await axios.delete(`/api/documents/${docId}`)
    await loadDocuments()
  } catch (error) {
    console.error('Error deleting document:', error)
  }
}

const refreshTaxCase = async () => {
  await loadTaxCase()
  await loadRevisions()
  await loadDocuments()
}
</script>

<style scoped>
/* Styles match SP2FilingForm and SphpFilingForm */
</style>
```

**Status**: ✅ Component exists - needs update to embed decision options inline

### B6.2 Router Configuration

**File:** `resources/js/router/index.js`

Verify import and route exist:

```javascript
// Import statement
import SkpFilingForm from '@/pages/SkpFilingForm.vue'

// In routes array, verify this route exists:
{
  path: '/tax-cases/:id/workflow/4',
  component: SkpFilingForm,
  name: 'skp-filing',
  meta: { requiresAuth: true },
  props: route => ({ caseId: route.params.id })
}
```

**Status**: ✅ Already configured

---

## Frontend Implementation Updates

### B6.3 Update SkpFilingForm.vue - Embed Decision Options Inline

**File:** `resources/js/pages/SkpFilingForm.vue`

**CRITICAL UPDATE NEEDED:**

Current implementation shows decision buttons AFTER stage 4 is submitted (`v-if="isStage4Submitted"`).

**MUST BE CHANGED TO:**
- Show decision options INSTANTLY when skp_type is selected (watcher)
- Show BOTH options (refund and objection) regardless of skp_type
- Store choice in `formData.user_routing_choice`
- Include in form submission

**Implementation Steps:**

1. **Add watcher for real-time trigger:**
```javascript
import { ref, onMounted, watch } from 'vue'

const showDecisionOptions = ref(false)
const selectedRoutingChoice = ref('')

// Watcher to show options when skp_type changes
watch(() => formData.value.skp_type, (newType) => {
  if (newType) {
    showDecisionOptions.value = true
  }
})

const handleRoutingChoice = (choice) => {
  selectedRoutingChoice.value = choice
  formData.value.user_routing_choice = choice
}
```

2. **Update form template to embed decision options:**
```vue
<!-- Decision Options Section - EMBEDDED in form -->
<div v-if="formData.skp_type && showDecisionOptions" class="mt-6 p-4 bg-blue-50 border-2 border-blue-200 rounded-lg">
  <h3 class="text-lg font-semibold text-blue-900 mb-4">Select Next Action</h3>
  
  <div class="space-y-3">
    <label class="flex items-center p-3 border-2 rounded-lg cursor-pointer transition"
      :class="[
        selectedRoutingChoice === 'objection'
          ? 'bg-white border-blue-500 ring-2 ring-blue-300'
          : 'border-gray-300 hover:border-blue-400'
      ]">
      <input
        type="radio"
        value="objection"
        v-model="selectedRoutingChoice"
        @change="handleRoutingChoice('objection')"
        class="w-4 h-4"
      />
      <div class="ml-3 flex-1">
        <p class="font-medium">→ Proceed to Objection (Stage 5)</p>
        <p class="text-sm text-gray-600">File Surat Keberatan</p>
      </div>
    </label>

    <label class="flex items-center p-3 border-2 rounded-lg cursor-pointer transition"
      :class="[
        selectedRoutingChoice === 'refund'
          ? 'bg-white border-green-500 ring-2 ring-green-300'
          : 'border-gray-300 hover:border-green-400'
      ]">
      <input
        type="radio"
        value="refund"
        v-model="selectedRoutingChoice"
        @change="handleRoutingChoice('refund')"
        class="w-4 h-4"
      />
      <div class="ml-3 flex-1">
        <p class="font-medium">✓ Proceed to Refund (Stage 13)</p>
        <p class="text-sm text-gray-600">Request Bank Transfer</p>
      </div>
    </label>
  </div>
</div>
```

3. **Include user_routing_choice in form submission:**
```javascript
const handleSubmit = async () => {
  try {
    const submitData = {
      ...formData.value,
      user_routing_choice: selectedRoutingChoice.value  // ← Include this
    }
    
    await axios.post(`/api/tax-cases/${caseId}/workflow/4`, submitData)
    // ...
  } catch (error) {
    console.error('Error submitting:', error)
  }
}
```

**Status**: 🟡 NEEDS UPDATE - Currently shows buttons AFTER submit, should show INSTANTLY

---

## API Endpoint Configuration

### B8.1 Workflow Endpoint

**File:** `routes/api.php`

Update the generic workflow endpoint to support Stage 4 with user_routing_choice:

```php
Route::post('/tax-cases/{id}/workflow/4', function (Request $request, $id) {
    $taxCase = TaxCase::findOrFail($id);
    
    // Stage 4: SKP
    $data = $request->validate([
        'skp_number' => 'required|string',
        'issue_date' => 'required|date',
        'receipt_date' => 'nullable|date',
        'skp_type' => 'required|in:LB,NIHIL,KB',
        'skp_amount' => 'required|numeric|min:0',
        'royalty_correction' => 'nullable|numeric|min:0',
        'service_correction' => 'nullable|numeric|min:0',
        'other_correction' => 'nullable|numeric|min:0',
        'correction_notes' => 'nullable|string',
        'user_routing_choice' => 'required|in:refund,objection'  // ← REQUIRED
    ]);

    // Update or create SKP Record
    $taxCase->skpRecord()->updateOrCreate(
        ['tax_case_id' => $id],
        $data
    );

    // Handle user's choice for next stage (NOT based on skp_type)
    if (isset($data['user_routing_choice'])) {
        $nextStageId = match($data['user_routing_choice']) {
            'refund' => 13,      // Bank Transfer Request
            'objection' => 5,    // Surat Keberatan
            default => 5
        };

        $taxCase->update(['next_stage_id' => $nextStageId]);
        Log::info("SKP Completed → User chose {$data['user_routing_choice']} → Next Stage: {$nextStageId}");
    }

    return response()->json(['message' => 'SKP data saved successfully']);
})->middleware('auth');
```

**Status**: 🟡 NEEDS UPDATE - Current implementation may use skp_type for routing

````

### B8.2 API Response Structure

When fetching `/api/tax-cases/{id}`, the response should include:

```json
{
  "data": {
    "id": 1,
    "case_number": "PT24JanC",
    "case_status": "SKP_RECEIVED",
    "sp2_record": { /* ... */ },
    "sphp_record": { /* ... */ },
    "skp_record": {
      "id": 1,
      "skp_number": "SKP/2024/0001",
      "skp_issue_date": "2024-01-15",
      "skp_receipt_date": "2024-01-20",
      "skp_type": "LB",
      "skp_amount": 500000000,
      "royalty_correction": 100000000,
      "service_correction": 50000000,
      "other_correction": 25000000,
      "correction_notes": "Details about corrections"
    }
  }
}
```

---

## TaxCaseDetail.vue Verification & Enhancement

### B7.1 Check: Stage Unlock Logic Based on Decision

**File:** `resources/js/pages/TaxCaseDetail.vue`

After Stage 4 is complete, TaxCaseDetail must unlock the correct next stage based on the user's choice.

**Check these methods exist:**

```javascript
/**
 * Get the decision choice made for a specific stage
 * @param {int} stageCode - Stage number (4, 7, 10, 12)
 * @returns {string} - Decision choice ('objection', 'refund', etc.)
 */
const getDecisionForStage = (stageCode) => {
  // Check tax_cases.next_action field for stage 4
  if (stageCode === 4) {
    return taxCase.value?.next_action || null
  }
  // Similar for stages 7, 10, 12
  return null
}

/**
 * Calculate accessible stages based on decision history
 * E.g., if user chose 'objection' at Stage 4, unlock Stage 5
 * If user chose 'refund' at Stage 4, unlock Stage 13
 */
const getAccessibleStagesForDecisionPath = () => {
  const accessible = []
  const decision4 = getDecisionForStage(4)

  if (taxCase.value?.stage_code >= 4) {
    // Stage 4 completed, check user's choice
    if (decision4 === 'objection') {
      accessible.push(5) // Next: Objection
    } else if (decision4 === 'refund') {
      accessible.push(13) // Next: Refund
    }
  }

  return accessible
}

/**
 * Watch for changes in next_stage_id and next_action
 * Update stage unlock accordingly
 */
watch(
  () => [taxCase.value?.next_stage_id, taxCase.value?.next_action],
  ([nextStageId, nextAction]) => {
    console.log(`Decision updated: next_stage_id=${nextStageId}, next_action=${nextAction}`)
    updateStageAccessibility()
  }
)
```

**If these methods don't exist**, add them to TaxCaseDetail.vue.

**If they exist but don't handle Stage 4**, update them to include Stage 4 logic.

### B7.2 Update: unlockedStages Computed Property

Make sure unlockedStages includes logic for decision routing:

```javascript
const unlockedStages = computed(() => {
  const unlocked = []
  
  // Get completed stages
  const completedStages = workflowHistories.value
    .filter(wh => wh.status === 'COMPLETED')
    .map(wh => wh.stage_code)

  // Add stages up to current stage
  for (let i = 1; i <= taxCase.value?.stage_code; i++) {
    unlocked.push(i)
  }

  // Add decision-based stages
  if (completedStages.includes(4)) {
    // Stage 4 complete, check decision
    const decision = getDecisionForStage(4)
    if (decision === 'objection') {
      unlocked.push(5) // Objection
    } else if (decision === 'refund') {
      unlocked.push(13) // Refund
    }
  }

  // Similar logic for stages 7, 10, 12 when implemented

  return [...new Set(unlocked)].sort((a, b) => a - b)
})
```

---

## Revision System Integration

### B9.1 Stage 4 in Field Type Mapping

**File:** `resources/js/components/RequestRevisionModalV2.vue`

In the `getFieldType()` function, add SKP field mappings:

```javascript
const getFieldType = (field) => {
  // ... existing code for stages 1-3 ...

  // Stage 4: SKP fields
  const skpFieldTypes = {
    'skp_number': 'text',
    'issue_date': 'date',
    'receipt_date': 'date',
    'skp_type': 'select',
    'skp_amount': 'number',
    'royalty_correction': 'number',
    'service_correction': 'number',
    'other_correction': 'number',
    'correction_notes': 'textarea',
    'user_routing_choice': 'select'
  }

  return skpFieldTypes[field] || 'text'
}
```

### B9.2 Field Label Mapping for Revisions

**File:** `resources/js/components/RequestRevisionModalV2.vue`

In the `fieldLabel()` function, add SKP field labels:

```javascript
const fieldLabel = (field) => {
  const labels = {
    // ... existing labels ...
    'skp_number': 'SKP Number',
    'issue_date': 'Issue Date',
    'receipt_date': 'Receipt Date',
    'skp_type': 'SKP Type',
    'skp_amount': 'SKP Amount',
    'royalty_correction': 'Royalty Correction',
    'service_correction': 'Service Correction',
    'other_correction': 'Other Correction',
    'correction_notes': 'Correction Notes',
    'user_routing_choice': 'Routing Choice (Refund/Objection)'
  }

  return labels[field] || field
}
```

### B9.3 Select Options for SKP Type and Routing Choice in Modal

When skp_type or user_routing_choice field is selected for revision, the modal needs to show options:

```javascript
// In handleFieldToggle() or getFieldOptions()
const getFieldOptions = (field) => {
  if (field === 'skp_type') {
    return [
      { value: 'LB', label: 'SKP LB (Lebih Bayar)' },
      { value: 'NIHIL', label: 'NIHIL (Zero)' },
      { value: 'KB', label: 'SKP KB (Kurang Bayar)' }
    ]
  }
  
  if (field === 'user_routing_choice') {
    return [
      { value: 'objection', label: 'Proceed to Objection (Stage 5)' },
      { value: 'refund', label: 'Proceed to Refund (Stage 13)' }
    ]
  }
  
  return []
}
```
      { value: 'NIHIL', label: 'NIHIL (Zero)' },
      { value: 'KB', label: 'SKP KB (Kurang Bayar)' }
    ]
  }
  // ... other field options ...
}
```

---

## Known Obstacles & Solutions

### Obstacle 1: DecisionOptionsPanel Not Appearing After Selecting skp_type

**Problem:** User selects SKP type but no decision options appear below the field.

**Root Cause:**
- DecisionOptionsPanel component not imported in SkpFilingForm
- v-if condition checking formData.skp_type is not reactive
- Component not registered

**Solution:**

1. Ensure DecisionOptionsPanel is imported at top of SkpFilingForm.vue:
   ```javascript
   import DecisionOptionsPanel from '@/components/DecisionOptionsPanel.vue'
   ```

2. Check formData is reactive (should be ref, not const object):
   ```javascript
   const formData = ref({ skp_type: '', ... })
   ```

3. Verify v-if="formData.skp_type" triggers when skp_type changes

4. Test in browser DevTools:
   - Open DevTools → Components tab
   - Change skp_type value in form
   - Check if DecisionOptionsPanel appears in component tree
   - If not, check console for errors

---

### Obstacle 2: Decision Choice Not Persisting to Database

**Problem:** User selects decision choice (Refund or Objection), clicks "Submit & Continue" but next_stage_id doesn't update correctly.

**Root Cause:**
- `next_action` field not being sent in API request
- Backend validation rejecting invalid next_action value
- next_stage_id not being calculated from next_action

**Solution:**

1. Verify SkpFilingForm sends `next_action` in handleSubmit():
   ```javascript
   const submitData = {
     ...formData.value,
     next_action: nextActionChoice.value // MUST be included
   }
   await axios.post(`/api/tax-cases/${props.caseId}/workflow/4`, submitData)
   ```

2. Verify API endpoint validates next_action (see Section B8.1):
   ```php
   'next_action' => 'nullable|in:refund,objection'
   ```

3. Verify API calculates next_stage_id based on next_action:
   ```php
   $nextStageId = match($data['next_action']) {
     'refund' => 13,
     'objection' => 5,
     default => 5
   };
   ```

4. Check Network tab in DevTools:
   - Submit form
   - Inspect POST request payload (should include `next_action`)
   - Inspect response (should include updated `next_stage_id`)

---

### Obstacle 3: TaxCaseDetail Not Unlocking Correct Next Stage

**Problem:** SKP stage completes, user's decision is saved, but TaxCaseDetail doesn't show the correct next stage as unlocked.

**Root Cause:**
- TaxCaseDetail.unlockedStages doesn't check tax_cases.next_stage_id
- getDecisionForStage() or getAccessibleStagesForDecisionPath() not implemented
- Stage unlock logic only considers linear progression (1→2→3→4...)

**Solution:**

1. Add getDecisionForStage() method to TaxCaseDetail.vue (see Section B7.1):
   ```javascript
   const getDecisionForStage = (stageCode) => {
     if (stageCode === 4) {
       return taxCase.value?.next_action || null
     }
     return null
   }
   ```

2. Add getAccessibleStagesForDecisionPath() method (see Section B7.1):
   ```javascript
   const getAccessibleStagesForDecisionPath = () => {
     const accessible = []
     const decision4 = getDecisionForStage(4)
     
     if (taxCase.value?.stage_code >= 4) {
       if (decision4 === 'objection') {
         accessible.push(5)
       } else if (decision4 === 'refund') {
         accessible.push(13)
       }
     }
     return accessible
   }
   ```

3. Update unlockedStages computed property to include decision-based stages:
   ```javascript
   const unlockedStages = computed(() => {
     const unlocked = []
     for (let i = 1; i <= taxCase.value?.stage_code; i++) {
       unlocked.push(i)
     }
     const decisionStages = getAccessibleStagesForDecisionPath()
     return [...new Set([...unlocked, ...decisionStages])].sort((a,b) => a-b)
   })
   ```

4. Test by:
   - Complete Stage 4 with "Refund" choice
   - Refresh TaxCaseDetail
   - Verify Stage 13 appears as unlocked
   - Repeat with "Objection" choice
   - Verify Stage 5 appears as unlocked

---

### Obstacle 4: Revision Modal Shows Wrong Choices for next_action Field

**Problem:** When requesting revision on next_action field, modal shows skp_type options instead of action options.

**Root Cause:**
- next_action field is being treated as if it were skp_type
- getFieldOptions() doesn't distinguish between them
- RequestRevisionModalV2 doesn't have handler for action choice fields

**Solution:**

1. Update RequestRevisionModalV2.vue getFieldOptions():
   ```javascript
   const getFieldOptions = (field) => {
     if (field === 'skp_type') {
       return [
         { value: 'LB', label: 'SKP LB (Lebih Bayar)' },
         { value: 'NIHIL', label: 'NIHIL (Zero)' },
         { value: 'KB', label: 'SKP KB (Kurang Bayar)' }
       ]
     }
     
     if (field === 'next_action') {
       return [
         { value: 'objection', label: 'Proceed to Objection (Stage 5)' },
         { value: 'refund', label: 'Proceed to Refund (Stage 13)' }
       ]
     }
     
     return []
   }
   ```

2. Ensure getFieldType() handles next_action:
   ```javascript
   if (field === 'next_action') return 'select'
   ```

3. Test by requesting revision on next_action field and verifying correct options appear

---

### Obstacle 5: Form Not Showing Next Action Buttons After SKP Type Selection

**Problem:** User selects skp_type but no buttons appear to choose between Refund and Objection.

**Root Cause:** 
- Form doesn't render choice buttons
- v-if condition or state management issue

**Solution:**

Ensure SkpFilingForm.vue has:

```javascript
const nextActionChoice = ref('') // Empty until user selects

// Show buttons after SKP data is being filled
<div v-if="skp_type || skp_number" class="mt-6 p-4 bg-gray-50 border rounded-lg">
  <!-- Choice buttons here -->
</div>
```

When form is submitted, include nextActionChoice in data sent to API.

---

## Implementation Checklist

### Phase 1: Database & Models

- [ ] **Migration**: Create/Update sphp_records → skp_records table with all 9 fields
- [ ] **Model**: Create app/Models/SkpRecord.php with fillable fields and casts
- [ ] **TaxCase**: Add `skpRecord()` HasOne relationship
- [ ] **Migration Run**: Execute migration (prisma migrate dev or artisan migrate)
- [ ] **Verify**: Check database schema with 9 fields present

### Phase 2: Backend Services

- [ ] **RevisionService**: Add Stage 4 to requestRevision() method
- [ ] **RevisionService**: Add Stage 4 to approveRevision() method
- [ ] **RevisionService**: Add getNextStageForSkp() decision logic method
- [ ] **RevisionService**: Add Stage 4 to detectStageFromFields() method
- [ ] **RevisionService**: Ensure stage_code is stored in revisions table
- [ ] **Verify**: Check that all 5 methods have Stage 4 support

### Phase 3: API Endpoints

- [ ] **routes/api.php**: Update Stage 4 workflow endpoint with 9 field validation
- [ ] **routes/api.php**: Add decision logic to update next_stage_id based on skp_type
- [ ] **Test**: POST /api/tax-cases/{id}/workflow/4 with SKP LB data
- [ ] **Test**: Verify next_stage_id updated to 13 for LB, 5 for NIHIL/KB
- [ ] **Verify**: GET /api/tax-cases/{id} returns complete skp_record object

### Phase 4: Frontend Components

- [ ] **SkpFilingForm.vue**: Create with all 9 fields and decision alert
- [ ] **SkpFilingForm.vue**: Add StageForm integration
- [ ] **SkpFilingForm.vue**: Add RevisionHistoryPanel integration
- [ ] **SkpFilingForm.vue**: Add loadTaxCase(), loadRevisions(), loadDocuments() methods
- [ ] **SkpFilingForm.vue**: Add handleSkpTypeChange() for decision display
- [ ] **router/index.js**: Add import and route for /tax-cases/:id/workflow/4
- [ ] **Test**: Navigate to SKP form and verify all fields render

### Phase 5: Revision System

- [ ] **RequestRevisionModalV2**: Add skp_type to getFieldType() → 'select'
- [ ] **RequestRevisionModalV2**: Add SKP field labels to fieldLabel() function
- [ ] **RequestRevisionModalV2**: Add SKP number fields to number input handler (should already work)
- [ ] **RequestRevisionModalV2**: Add correction_notes to textarea handler (should already work)
- [ ] **RequestRevisionModalV2**: Add getFieldOptions() for skp_type select
- [ ] **Test**: Select skp_type field in revision modal, verify options appear

### Phase 6: User Choice UI

- [ ] **SkpFilingForm.vue**: Add choice buttons section (Refund vs Objection)
- [ ] **SkpFilingForm.vue**: Add nextActionChoice ref to track user's selection
- [ ] **SkpFilingForm.vue**: Style buttons to show selection state
- [ ] **SkpFilingForm.vue**: Include next_action in form submission data
- [ ] **Test**: Verify buttons appear and respond to clicks
- [ ] **Test**: Verify correct value sent to API

### Phase 7: API Decision Logic Testing

- [ ] **Test**: Create SKP with LB type + choose Refund → Verify next_stage_id=13
- [ ] **Test**: Create SKP with LB type + choose Objection → Verify next_stage_id=5
- [ ] **Test**: Create SKP with NIHIL type + choose Refund → Verify next_stage_id=13
- [ ] **Test**: Create SKP with NIHIL type + choose Objection → Verify next_stage_id=5
- [ ] **Test**: Create SKP with KB type + choose Refund → Verify next_stage_id=13
- [ ] **Test**: Create SKP with KB type + choose Objection → Verify next_stage_id=5
- [ ] **Verify**: Case Status remains SKP_RECEIVED (doesn't auto-advance)
- [ ] **Verify**: User can manually navigate to next stage based on their choice

### Phase 8: Revision Workflow Testing

- [ ] **Test**: Request revision on skp_number field
- [ ] **Test**: Request revision on skp_type field with option selection
- [ ] **Test**: Request revision on royalty_correction (number field)
- [ ] **Test**: Request revision on correction_notes (textarea field)
- [ ] **Test**: Approve all revision types and verify data updates
- [ ] **Test**: Reject revision and verify original data persists

### Phase 9: Document Management

- [ ] **Test**: Upload document with stage_code=4
- [ ] **Test**: Filter documents by stage_code=4
- [ ] **Test**: Delete uploaded documents
- [ ] **Verify**: Document count updates correctly

### Phase 10: UI/UX Polish

- [ ] **Design**: Decision alert shows correct message for each SKP type
- [ ] **Styling**: Alert background colors (green for LB, blue for NIHIL/KB)
- [ ] **Accessibility**: All field labels clear and descriptive
- [ ] **Responsiveness**: Form works on mobile/tablet/desktop
- [ ] **Error States**: Show validation errors clearly
- [ ] **Loading States**: Show spinners during API calls

### Phase 11: Integration Testing

- [ ] **Test**: Complete workflow: Fill SKP form → Select action (Refund/Objection) → Navigate to next stage
- [ ] **Test**: Cross-stage consistency (data from earlier stages loads correctly)
- [ ] **Test**: Revision history shows correct before/after for all field types
- [ ] **Test**: Field labels in revision history display correctly
- [ ] **Verify**: All CRUD operations work end-to-end

### Phase 12: Documentation & Handoff

- [ ] **Code Comments**: Add comments explaining decision logic
- [ ] **README**: Update with Stage 4 workflow description
- [ ] **CHANGELOG**: Document Stage 4 implementation
- [ ] **Training**: Prepare documentation for QA/Users
- [ ] **Git**: Commit with clear message: "Implement Stage 4 (SKP) with decision routing logic"

---

## Implementation Order Recommendation

**Recommended execution sequence:**

1. **Day 1 Morning**: Complete Phases 1-2 (Database & Services)
2. **Day 1 Afternoon**: Complete Phase 3 (API Endpoints)
3. **Day 2 Morning**: Complete Phases 4-6 (Frontend, Revision System & User Choice UI)
4. **Day 2 Afternoon**: Complete Phases 7-8 (Decision Logic & Revision Testing)
5. **Day 3 Morning**: Complete Phases 9-10 (Documents & UI Polish)
6. **Day 3 Afternoon**: Complete Phases 11-12 (Integration & Handoff)

---

## Notes for Implementation

1. **Database Migration**: Check if skp_records table already exists in migration #000006. If it does, only add/update missing fields rather than creating new table.

2. **Enum Field**: skp_type uses enum with 3 values (LB, NIHIL, KB). If database doesn't support enum (like SQLite), use string with validation instead.

3. **Decimal Precision**: All amount fields use `decimal(15,2)` for proper currency handling. Do NOT use float or double.

4. **User Choice Logic**: Unlike automatic routing in other stages, Stage 4 requires explicit user choice via form submission. The next_action field must be included in API request.

5. **Testing User Choices**: Always test all combinations:
   - Each SKP Type (LB, NIHIL, KB) with both choices (Refund and Objection)
   - Total: 6 test cases to verify routing works correctly

6. **Backward Compatibility**: Ensure changes don't break Stages 2 and 3. Test after each phase.

7. **Error Handling**: Add try-catch blocks around choice logic to prevent crashes if next_action is invalid.

---

**End of Stage 4 (SKP) Implementation Guide**

*Version 1.0 - January 18, 2026*
*Based on STAGE-4-IMPLEMENTATION.md template pattern*
*Maintains consistency with SP2 (Stage 2) and SPHP (Stage 3) implementations*
