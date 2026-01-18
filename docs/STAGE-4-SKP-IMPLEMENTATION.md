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
```

### B2.2 prefillData Structure

```javascript
const prefillData = ref({
  skp_number: '',
  skp_issue_date: null,
  skp_receipt_date: null,
  skp_type: '',  // 'LB', 'NIHIL', or 'KB'
  skp_amount: 0,
  royalty_correction: 0,
  service_correction: 0,
  other_correction: 0,
  correction_notes: '',
  workflowHistories: []
})
```

### B2.3 Available Fields for Revision

```javascript
const availableFields = [
  'skp_number',
  'skp_issue_date',
  'skp_receipt_date',
  'skp_type',
  'skp_amount',
  'royalty_correction',
  'service_correction',
  'other_correction',
  'correction_notes',
  'supporting_docs'
]
```

---

## Decision Logic (Special Feature)

### ⚠️ CRITICAL: SKP Type Determines Next Stage Routing

**This is a KEY DIFFERENTIATOR from previous stages. The skp_type field value determines which next stage the case will proceed to.**

### B3.1 Decision Tree

```
Stage 4: SKP Received
        ↓
   User selects Jenis SKP
        ↓
   ┌─────────────────────────────────────┐
   │  Jenis SKP Decision Point           │
   └─────────────────────────────────────┘
              ↙              ↘
        SKP LB         NIHIL / SKP KB
    (Overpayment)    (Zero / Underpayment)
         ↓                     ↓
    Next Stage:            Next Stage:
    13 (Refund)        5 (Objection)
```

### B3.2 Decision Logic by Type

| skp_type | Decision | Next Stage | Case Status |
|----------|----------|-----------|-------------|
| **LB** | Accepted (Overpayment) | 13 (Refund Procedure) | SKP_RECEIVED |
| **NIHIL** | Rejected (Zero) | 5 (Objection) | SKP_RECEIVED |
| **KB** | Rejected (Underpayment) | 5 (Objection) | SKP_RECEIVED |

### B3.3 Backend Implementation of Decision Logic

**Location:** `app/Services/RevisionService.php` (new method needed)

```php
/**
 * Determine next stage based on SKP type
 * Special logic for Stage 4 (SKP)
 * 
 * @param string $skpType - 'LB', 'NIHIL', or 'KB'
 * @return int - Next stage ID (13 for refund, 5 for objection)
 */
private function getNextStageForSkp(string $skpType): int
{
    return match($skpType) {
        'LB' => 13,      // Refund Procedure
        'NIHIL', 'KB' => 5,  // Objection
        default => 5     // Default to Objection if invalid
    };
}
```

**Usage in approveRevision():**

```php
// After approving revision that changes skp_type
if ($revision->stage_code === 4 && in_array('skp_type', $changedFields)) {
    $newSkpType = $updatedData['skp_type'] ?? $updateTarget->skp_type;
    $nextStageId = $this->getNextStageForSkp($newSkpType);
    
    // Update the TaxCase with next_stage_id
    $revisable->update([
        'next_stage_id' => $nextStageId
    ]);
}
```

### B3.4 Frontend Decision Handler in SkpFilingForm.vue

```javascript
/**
 * Handle SKP Type selection change
 * Update next stage routing based on selected type
 */
const handleSkpTypeChange = (selectedType) => {
  formData.value.skp_type = selectedType
  
  // Emit event to parent about decision change
  emit('decision-changed', {
    stageCode: 4,
    decision: selectedType,
    nextStage: selectedType === 'LB' ? 13 : 5,
    message: selectedType === 'LB' 
      ? 'Case will proceed to Refund Procedure (Stage 13)'
      : 'Case will proceed to Objection (Stage 5)'
  })
}
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
    $table->date('skp_issue_date')->nullable();
    $table->date('skp_receipt_date')->nullable();
    
    // Decision field - stores SKP type
    $table->enum('skp_type', ['LB', 'NIHIL', 'KB'])->nullable();
    
    // Amount fields - all decimal for financial precision
    $table->decimal('skp_amount', 15, 2)->default(0);
    $table->decimal('royalty_correction', 15, 2)->default(0);
    $table->decimal('service_correction', 15, 2)->default(0);
    $table->decimal('other_correction', 15, 2)->default(0);
    
    // Notes field
    $table->text('correction_notes')->nullable();

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
        'skp_issue_date',
        'skp_receipt_date',
        'skp_type',
        'skp_amount',
        'royalty_correction',
        'service_correction',
        'other_correction',
        'correction_notes'
    ];

    protected $casts = [
        'skp_issue_date' => 'date',
        'skp_receipt_date' => 'date',
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

// After update target is determined, handle SKP type decision:
if ($stageCode === 4 && isset($updateData['skp_type'])) {
    $nextStageId = $this->getNextStageForSkp($updateData['skp_type']);
    $revisable->update(['next_stage_id' => $nextStageId]);
}
```

### B5.2 Stage Code Detection

**File:** `app/Services/RevisionService.php`

Update the field detection logic:

```php
private function detectStageFromFields(array $fieldNames): ?int
{
    $stageFieldMaps = [
        2 => ['sp2_number', 'issue_date', 'receipt_date', 'auditor_name', 'auditor_phone', 'auditor_email'],
        3 => ['sphp_number', 'sphp_issue_date', 'sphp_receipt_date', 'royalty_finding', 'service_finding', 'other_finding'],
        4 => ['skp_number', 'skp_issue_date', 'skp_receipt_date', 'skp_type', 'skp_amount', 'royalty_correction', 'service_correction', 'other_correction'],
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

        <!-- Decision Alert -->
        <div
          v-if="formData.skp_type"
          :class="[
            'mb-6 p-4 rounded-lg',
            formData.skp_type === 'LB'
              ? 'bg-green-50 border border-green-200'
              : 'bg-blue-50 border border-blue-200'
          ]"
        >
          <p class="text-sm font-medium">
            <span v-if="formData.skp_type === 'LB'" class="text-green-800">
              ✓ SKP LB Selected: Case will proceed to Refund Procedure (Stage 13)
            </span>
            <span v-else class="text-blue-800">
              → NIHIL/KB Selected: Case will proceed to Objection (Stage 5)
            </span>
          </p>
        </div>

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
import { ref, onMounted } from 'vue'
import StageForm from '@/components/StageForm.vue'
import RevisionHistoryPanel from '@/components/RevisionHistoryPanel.vue'
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

    await axios.post(`/api/tax-cases/${props.caseId}/workflow/4`, formData.value)
    await refreshTaxCase()
  } catch (error) {
    console.error('Error submitting:', error)
    submissionComplete.value = false
    fieldsDisabled.value = false
  }
}

const handleSaveDraft = async () => {
  try {
    await axios.patch(`/api/tax-cases/${props.caseId}/workflow/4`, formData.value)
    await refreshTaxCase()
  } catch (error) {
    console.error('Error saving draft:', error)
  }
}

const handleFieldChanged = (fieldKey) => {
  // Handle SKP Type change to show decision
  if (fieldKey === 'skp_type') {
    // Decision info is shown in the alert box via reactive data
  }
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

### B6.2 Router Configuration

**File:** `resources/js/router/index.js`

Add import and route:

```javascript
// Import statement
import SkpFilingForm from '@/pages/SkpFilingForm.vue'

// In routes array, add:
{
  path: '/tax-cases/:id/workflow/4',
  component: SkpFilingForm,
  name: 'skp-filing',
  meta: { requiresAuth: true },
  props: route => ({ caseId: route.params.id })
}
```

---

## API Endpoint Configuration

### B7.1 Workflow Endpoint

**File:** `routes/api.php`

Update the generic workflow endpoint to support Stage 4:

```php
Route::patch('/tax-cases/{id}/workflow/{stage}', function (Request $request, $id, $stage) {
    $taxCase = TaxCase::findOrFail($id);
    
    // ... existing code for stages 1-3 ...

    // Stage 4: SKP
    if ($stage === 4) {
        $data = $request->validate([
            'skp_number' => 'nullable|string',
            'skp_issue_date' => 'nullable|date',
            'skp_receipt_date' => 'nullable|date',
            'skp_type' => 'nullable|in:LB,NIHIL,KB',
            'skp_amount' => 'nullable|numeric|min:0',
            'royalty_correction' => 'nullable|numeric|min:0',
            'service_correction' => 'nullable|numeric|min:0',
            'other_correction' => 'nullable|numeric|min:0',
            'correction_notes' => 'nullable|string'
        ]);

        // Update or create SKP Record
        $taxCase->skpRecord()->updateOrCreate(
            ['tax_case_id' => $id],
            $data
        );

        // Handle decision logic based on skp_type
        if (isset($data['skp_type'])) {
            $nextStageId = match($data['skp_type']) {
                'LB' => 13,           // Refund
                'NIHIL', 'KB' => 5,   // Objection
                default => 5
            };

            $taxCase->update(['next_stage_id' => $nextStageId]);
            Log::info("SKP Type: {$data['skp_type']} → Next Stage: {$nextStageId}");
        }

        return response()->json(['message' => 'SKP data saved successfully']);
    }

    // ... existing code for other stages ...
})->middleware('auth');
```

### B7.2 API Response Structure

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

## Revision System Integration

### B8.1 Stage 4 in Field Type Mapping

**File:** `resources/js/components/RequestRevisionModalV2.vue`

In the `getFieldType()` function, add SKP field mappings:

```javascript
const getFieldType = (field) => {
  // ... existing code for stages 1-3 ...

  // Stage 4: SKP fields
  const skpFieldTypes = {
    'skp_number': 'text',
    'skp_issue_date': 'date',
    'skp_receipt_date': 'date',
    'skp_type': 'select',
    'skp_amount': 'number',
    'royalty_correction': 'number',
    'service_correction': 'number',
    'other_correction': 'number',
    'correction_notes': 'textarea'
  }

  return skpFieldTypes[field] || 'text'
}
```

### B8.2 Field Label Mapping for Revisions

**File:** `resources/js/components/RequestRevisionModalV2.vue`

In the `fieldLabel()` function, add SKP field labels:

```javascript
const fieldLabel = (field) => {
  const labels = {
    // ... existing labels ...
    'skp_number': 'SKP Number',
    'skp_issue_date': 'Issue Date',
    'skp_receipt_date': 'Receipt Date',
    'skp_type': 'SKP Type',
    'skp_amount': 'SKP Amount',
    'royalty_correction': 'Royalty Correction',
    'service_correction': 'Service Correction',
    'other_correction': 'Other Correction',
    'correction_notes': 'Correction Notes'
  }

  return labels[field] || field
}
```

### B8.3 Select Options for SKP Type in Modal

When skp_type field is selected for revision, the modal needs to show options:

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
  // ... other field options ...
}
```

---

## Known Obstacles & Solutions

### Obstacle 1: Decision Logic Not Triggering Stage Routing

**Problem:** User selects SKP type and approves, but case doesn't route to correct next stage (Refund or Objection).

**Root Cause:** 
- backend might not have decision logic in approveRevision()
- next_stage_id not being updated in tax_cases table
- API endpoint not handling the decision

**Solution:**

1. Add `getNextStageForSkp()` method to RevisionService
2. Update approveRevision() to call this method when skp_type changes
3. Ensure next_stage_id is updated on TaxCase model

**Code Reference:** See Section B3.3 Backend Implementation

---

### Obstacle 2: Select Field Not Showing Options in Revision Modal

**Problem:** When user selects skp_type field to revise, modal shows empty dropdown or no input at all.

**Root Cause:** RequestRevisionModalV2 doesn't have handler for 'select' type with options, or options aren't passed correctly.

**Solution:**

1. Ensure getFieldType() returns 'select' for skp_type
2. Ensure getFieldOptions() returns array of options
3. Add select input handler in the modal template:

```vue
<!-- Select Input for skp_type -->
<div v-if="getFieldType(field) === 'select'" class="mb-3">
  <select v-model="proposedValues[field]">
    <option value="">Select an option</option>
    <option
      v-for="opt in getFieldOptions(field)"
      :key="opt.value"
      :value="opt.value"
    >
      {{ opt.label }}
    </option>
  </select>
</div>
```

---

### Obstacle 3: Number and Textarea Fields Not Showing in Revision Modal

**Problem:** When selecting royalty_correction, service_correction, other_correction (number fields) or correction_notes (textarea field), no input form appears.

**Root Cause:** This was the obstacle from Stage 3 that was fixed in RequestRevisionModalV2.

**Solution:** ✅ Already Fixed in Phase 3
- Number Input Handler exists (lines 117-127 in RequestRevisionModalV2.vue)
- Textarea Input Handler exists (lines 131-140 in RequestRevisionModalV2.vue)
- Field type mappings already updated

**Validation:** The fix from Stage 3 should handle these automatically.

**Prevention:** When adding new number/textarea fields in future stages, remember to:
1. Add handlers in RequestRevisionModalV2.vue
2. Map field types in getFieldType()
3. Map field labels in fieldLabel()

---

### Obstacle 4: SKP Type Change in Revision Not Updating Case Status

**Problem:** User revises skp_type from 'KB' to 'LB', revision is approved, but case_status doesn't update and next_stage_id might be wrong.

**Root Cause:**
- Logic exists in backend to update next_stage_id
- But might not be handling all approval workflows

**Solution:**

Ensure approveRevision() in RevisionService checks for Stage 4 + SKP Type change:

```php
// After updating skpRecord with revised data
if ($stageCode === 4 && isset($updatedData['skp_type'])) {
    $nextStageId = match($updatedData['skp_type']) {
        'LB' => 13,
        'NIHIL', 'KB' => 5,
        default => 5
    };
    
    // Update the TaxCase with new routing
    $revisable->update([
        'next_stage_id' => $nextStageId,
        'case_status' => 'SKP_RECEIVED'  // Explicit status update if needed
    ]);
    
    // Log the decision for audit trail
    Log::info("SKP Decision: {$updatedData['skp_type']} → Stage {$nextStageId}", [
        'tax_case_id' => $revisable->id,
        'revision_id' => $revision->id
    ]);
}
```

---

### Obstacle 5: Form Not Showing Decision Alert After SKP Type Selection

**Problem:** User selects skp_type but the alert box showing "Case will proceed to..." doesn't appear or update.

**Root Cause:** 
- formData.skp_type not reactive or not binding correctly
- v-if condition on alert div not working

**Solution:**

In SkpFilingForm.vue, ensure:
1. v-model binding on select field is correct
2. formData.skp_type is being updated on change
3. Decision alert has proper v-if condition

```vue
<!-- In select field handler -->
<select
  v-model="formData.skp_type"
  @change="handleSkpTypeChange"
  class="w-full px-4 py-2 border border-gray-300 rounded-lg"
>
  <option value="">Select SKP Type...</option>
  <option value="LB">SKP LB (Lebih Bayar)</option>
  <option value="NIHIL">NIHIL (Zero)</option>
  <option value="KB">SKP KB (Kurang Bayar)</option>
</select>
```

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

### Phase 6: Decision Logic Testing

- [ ] **Test**: Create SKP with type='LB' → Verify next_stage_id=13
- [ ] **Test**: Create SKP with type='NIHIL' → Verify next_stage_id=5
- [ ] **Test**: Create SKP with type='KB' → Verify next_stage_id=5
- [ ] **Test**: Revise skp_type from LB to KB → Verify next_stage_id changes to 5
- [ ] **Verify**: Case Status remains SKP_RECEIVED (doesn't auto-advance to next)
- [ ] **Verify**: User can manually navigate to next stage after SKP complete

### Phase 7: Revision Workflow Testing

- [ ] **Test**: Request revision on skp_number field
- [ ] **Test**: Request revision on skp_type field with option selection
- [ ] **Test**: Request revision on royalty_correction (number field)
- [ ] **Test**: Request revision on correction_notes (textarea field)
- [ ] **Test**: Approve all revision types and verify data updates
- [ ] **Test**: Reject revision and verify original data persists

### Phase 8: Document Management

- [ ] **Test**: Upload document with stage_code=4
- [ ] **Test**: Filter documents by stage_code=4
- [ ] **Test**: Delete uploaded documents
- [ ] **Verify**: Document count updates correctly

### Phase 9: UI/UX Polish

- [ ] **Design**: Decision alert shows correct message for each SKP type
- [ ] **Styling**: Alert background colors (green for LB, blue for NIHIL/KB)
- [ ] **Accessibility**: All field labels clear and descriptive
- [ ] **Responsiveness**: Form works on mobile/tablet/desktop
- [ ] **Error States**: Show validation errors clearly
- [ ] **Loading States**: Show spinners during API calls

### Phase 10: Integration Testing

- [ ] **Test**: Complete workflow: Fill SKP form → Request revision → Approve → Navigate to next stage
- [ ] **Test**: Cross-stage consistency (data from earlier stages loads correctly)
- [ ] **Test**: Revision history shows correct before/after for all field types
- [ ] **Test**: Field labels in revision history display correctly
- [ ] **Verify**: All CRUD operations work end-to-end

### Phase 11: Documentation & Handoff

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
3. **Day 2 Morning**: Complete Phases 4-5 (Frontend & Revision System)
4. **Day 2 Afternoon**: Complete Phases 6-7 (Decision Logic & Revision Testing)
5. **Day 3 Morning**: Complete Phases 8-9 (Documents & UI Polish)
6. **Day 3 Afternoon**: Complete Phases 10-11 (Integration & Handoff)

---

## Notes for Implementation

1. **Database Migration**: Check if skp_records table already exists in migration #000006. If it does, only add/update missing fields rather than creating new table.

2. **Enum Field**: skp_type uses enum with 3 values (LB, NIHIL, KB). If database doesn't support enum (like SQLite), use string with validation instead.

3. **Decimal Precision**: All amount fields use `decimal(15,2)` for proper currency handling. Do NOT use float or double.

4. **Next Stage ID**: The value stored must match actual Stage IDs in system (13 for Refund, 5 for Objection).

5. **Testing Decision Logic**: Always test all 3 SKP types to verify routing:
   - SKP LB → Stage 13
   - SKP NIHIL → Stage 5
   - SKP KB → Stage 5

6. **Backward Compatibility**: Ensure changes don't break Stages 2 and 3. Test after each phase.

7. **Error Handling**: Add try-catch blocks around decision logic to prevent crashes if skp_type is invalid.

---

**End of Stage 4 (SKP) Implementation Guide**

*Version 1.0 - January 18, 2026*
*Based on STAGE-4-IMPLEMENTATION.md template pattern*
*Maintains consistency with SP2 (Stage 2) and SPHP (Stage 3) implementations*
