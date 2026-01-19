# Stage 10 Implementation - Appeal Decision (Keputusan Banding)

**Document Type:** Implementation Guide for Stage 10  
**Version:** 1.0  
**Last Updated:** January 18, 2026  
**Based on:** DECISION_POINT_PATTERN.md

---

## 📋 Table of Contents

1. [Stage Identity](#stage-identity)
2. [Form Definition](#form-definition)
3. [Decision Logic](#decision-logic)
4. [Database Schema](#database-schema)
5. [Backend Implementation](#backend-implementation)
6. [Frontend Implementation](#frontend-implementation)
7. [API Endpoint Configuration](#api-endpoint-configuration)
8. [Revision System Integration](#revision-system-integration)
9. [Implementation Checklist](#implementation-checklist)

---

## Stage Identity

| Property | Value |
|----------|-------|
| **Stage Number** | 10 |
| **Stage Name** | Keputusan Banding (Appeal Decision) |
| **Stage Display** | "Appeal Decision" |
| **Model Name** | AppealDecisionRecord |
| **Table Name** | appeal_decision_records |
| **Related Model** | TaxCase (HasOne relationship) |
| **Case Status** | APPEAL_DECISION |
| **Decision Point** | Keputusan (Decision) |
| **Decision Logic** | Routes based on user's explicit choice (Refund or Supreme Court) |

---

## Form Definition

### Fields (for AppealDecisionForm.vue)

```javascript
const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'keputusan_banding_number',
    label: 'Nomor Surat Keputusan Banding (Appeal Decision Number)',
    required: true,
    readonly: false,
    placeholder: 'e.g., SK/BAND/2024/0001'
  },
  {
    id: 2,
    type: 'date',
    key: 'keputusan_banding_date',
    label: 'Tanggal Keputusan (Decision Date)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'select',
    key: 'keputusan_banding',  // DECISION TRIGGER FIELD
    label: 'Keputusan (Decision)',
    required: true,
    readonly: false,
    options: [
      { value: 'dikabulkan', label: 'Dikabulkan (Granted)' },
      { value: 'dikabulkan_sebagian', label: 'Dikabulkan Sebagian (Partially Granted)' },
      { value: 'ditolak', label: 'Ditolak (Rejected)' }
    ]
  },
  {
    id: 4,
    type: 'number',
    key: 'keputusan_banding_amount',
    label: 'Nilai Keputusan (Decision Amount)',
    required: true,
    readonly: false,
    placeholder: 'Enter decision amount'
  },
  {
    id: 5,
    type: 'textarea',
    key: 'keputusan_banding_notes',
    label: 'Catatan Keputusan (Decision Notes)',
    required: false,
    readonly: false,
    placeholder: 'Enter any additional notes...'
  }
])
```

### prefillData Structure

```javascript
const prefillData = ref({
  keputusan_banding_number: '',
  keputusan_banding_date: null,
  keputusan_banding: '',  // 'dikabulkan', 'dikabulkan_sebagian', 'ditolak'
  keputusan_banding_amount: 0,
  keputusan_banding_notes: '',
  workflowHistories: []
})
```

### Available Fields for Revision

```javascript
const availableFields = [
  'keputusan_banding_number',
  'keputusan_banding_date',
  'keputusan_banding',
  'keputusan_banding_amount',
  'keputusan_banding_notes',
  'next_action',  // User's choice: refund or supreme_court
  'supporting_docs'
]
```

---

## Decision Logic

### Real-Time Decision Display

**Trigger Field:** `keputusan_banding` (Appeal Decision field)

When user selects a value in the `keputusan_banding` dropdown, immediately show the next action options:

```
User selects "Dikabulkan", "Dikabulkan Sebagian", or "Ditolak"
       ↓ (instant)
Decision Options appear:
- Option 1: Proceed to Refund (Stage 13)
- Option 2: Proceed to Supreme Court (Stage 11)
```

### Decision Flow

```
Stage 10: Appeal Decision
       ↓
User enters decision data
       ↓
User selects Keputusan Banding value
       ↓
[REAL-TIME] Next Action Options appear
       ↓
User selects: Refund OR Supreme Court
       ↓
User clicks "Save as Draft" or "Submit & Continue"
       ↓
       ┌────────────────────────────────────┐
       │ Option A: Proceed to Refund        │
       │ → Next Stage: 13 (Bank Transfer)   │
       └────────────────────────────────────┘
              OR
       ┌────────────────────────────────────┐
       │ Option B: Proceed to Supreme Court │
       │ → Next Stage: 11 (Peninjauan KB)   │
       └────────────────────────────────────┘
```

### Decision Routing Notes

Per PORTAX_FLOW.md:
- **Dikabulkan (Granted)** → Typically Refund, but user can choose either
- **Dikabulkan Sebagian (Partially Granted)** → Could proceed to Supreme Court review OR Refund
- **Ditolak (Rejected)** → Typically Supreme Court, but user can choose either

### Backend Decision Mapper

In `DecisionPointService.php`, Stage 10 mapping:

```php
10 => [  // Appeal Decision
    'decision_field' => 'keputusan_banding',
    'options' => [
        'refund' => [
            'label' => 'Proceed to Refund',
            'next_stage' => 13,
            'description' => 'Request Bank Transfer (Stage 13)'
        ],
        'supreme_court' => [
            'label' => 'Proceed to Supreme Court',
            'next_stage' => 11,
            'description' => 'File Peninjauan Kembali (Stage 11)'
        ],
    ]
]
```

---

## Database Schema

### Migration

**File:** `database/migrations/2026_01_01_000010_create_appeal_decision_records.php`

```php
Schema::create('appeal_decision_records', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tax_case_id')
        ->constrained('tax_cases')
        ->cascadeOnDelete();

    // Appeal Decision specific fields
    $table->string('keputusan_banding_number')->nullable();
    $table->date('keputusan_banding_date')->nullable();
    
    // Decision field - determines routing
    $table->enum('keputusan_banding', ['dikabulkan', 'dikabulkan_sebagian', 'ditolak'])->nullable();
    
    // Amount field
    $table->decimal('keputusan_banding_amount', 15, 2)->default(0);
    
    // Notes field
    $table->text('keputusan_banding_notes')->nullable();

    $table->timestamps();
    $table->softDeletes();
});
```

### Model

**File:** `app/Models/AppealDecisionRecord.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppealDecisionRecord extends Model
{
    use SoftDeletes;

    protected $table = 'appeal_decision_records';

    protected $fillable = [
        'tax_case_id',
        'keputusan_banding_number',
        'keputusan_banding_date',
        'keputusan_banding',
        'keputusan_banding_amount',
        'keputusan_banding_notes'
    ];

    protected $casts = [
        'keputusan_banding_date' => 'date',
        'keputusan_banding_amount' => 'decimal:2',
    ];

    public function taxCase(): BelongsTo
    {
        return $this->belongsTo(TaxCase::class);
    }
}
```

### Update TaxCase Model

Add relationship in `app/Models/TaxCase.php`:

```php
public function appealDecisionRecord(): HasOne
{
    return $this->hasOne(AppealDecisionRecord::class);
}
```

---

## Backend Implementation

### Update RevisionService

**File:** `app/Services/RevisionService.php`

Add Stage 10 support in `requestRevision()`:

```php
elseif ((int)$stageCode === 10) {
    if (!$revisable->relationLoaded('appealDecisionRecord')) {
        $revisable->load('appealDecisionRecord');
    }
    $dataSource = $revisable->appealDecisionRecord;
}
```

Add Stage 10 support in `approveRevision()`:

```php
elseif ($stageCode == 10 && $revisable->appealDecisionRecord) {
    $updateTarget = $revisable->appealDecisionRecord;
}

// After update, handle decision routing
if ($stageCode === 10 && isset($updateData['next_action'])) {
    $revisable->update([
        'next_stage_id' => $updateData['next_action'] === 'refund' ? 13 : 11,
        'next_action' => $updateData['next_action']
    ]);
}
```

Add Stage 10 to field detection in `detectStageFromFields()`:

```php
10 => [
    'keputusan_banding_number', 
    'keputusan_banding_date', 
    'keputusan_banding', 
    'keputusan_banding_amount', 
    'keputusan_banding_notes'
],
```

---

## Frontend Implementation

### Create AppealDecisionForm.vue

**File:** `resources/js/pages/AppealDecisionForm.vue`

```vue
<template>
  <div class="flex h-screen bg-gray-100">
    <!-- Left side: Form + Documents -->
    <div class="w-1/2 overflow-y-auto bg-white">
      <div class="p-6">
        <h1 class="text-3xl font-bold text-gray-900 mb-6">
          Stage 10: Keputusan Banding (Appeal Decision)
        </h1>

        <!-- Decision Info Alert -->
        <div
          v-if="formData.keputusan_banding"
          :class="[
            'mb-6 p-4 rounded-lg border-l-4',
            decisionAlertClass
          ]"
        >
          <p class="font-medium">
            {{ getDecisionMessage() }}
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
          :entityType="'appeal_decision'"
          :stage-code="10"
          @submit="handleSubmit"
          @save-draft="handleSaveDraft"
          @update:formData="(v) => (formData = v)"
          @field-changed="handleFieldChanged"
          @decision-changed="handleDecisionChanged"
        />
      </div>
    </div>

    <!-- Right side: Revision History + Documents -->
    <div class="w-1/2 overflow-y-auto bg-gray-50">
      <RevisionHistoryPanel
        :revisions="revisions"
        :fields="fields"
        :entityType="'appeal_decision'"
        :stageCode="10"
        @revision-requested="loadRevisions"
        @refresh="refreshTaxCase"
      />
      
      <!-- Documents Section -->
      <div class="p-6 border-t">
        <h3 class="text-lg font-semibold mb-4">Supporting Documents</h3>
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
import axios from 'axios'

const props = defineProps({
  caseId: {
    type: [String, Number],
    required: true
  }
})

// State
const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'keputusan_banding_number',
    label: 'Nomor Surat Keputusan Banding (Appeal Decision Number)',
    required: true,
    readonly: false,
    placeholder: 'e.g., SK/BAND/2024/0001'
  },
  {
    id: 2,
    type: 'date',
    key: 'keputusan_banding_date',
    label: 'Tanggal Keputusan (Decision Date)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'select',
    key: 'keputusan_banding',
    label: 'Keputusan (Decision)',
    required: true,
    readonly: false,
    options: [
      { value: 'dikabulkan', label: 'Dikabulkan (Granted)' },
      { value: 'dikabulkan_sebagian', label: 'Dikabulkan Sebagian (Partially Granted)' },
      { value: 'ditolak', label: 'Ditolak (Rejected)' }
    ]
  },
  {
    id: 4,
    type: 'number',
    key: 'keputusan_banding_amount',
    label: 'Nilai Keputusan (Decision Amount)',
    required: true,
    readonly: false,
    placeholder: 'Enter decision amount'
  },
  {
    id: 5,
    type: 'textarea',
    key: 'keputusan_banding_notes',
    label: 'Catatan Keputusan (Decision Notes)',
    required: false,
    readonly: false,
    placeholder: 'Enter any additional notes...'
  }
])

const prefillData = ref({
  keputusan_banding_number: '',
  keputusan_banding_date: null,
  keputusan_banding: '',
  keputusan_banding_amount: 0,
  keputusan_banding_notes: '',
  workflowHistories: []
})

const formData = ref({
  keputusan_banding_number: '',
  keputusan_banding_date: null,
  keputusan_banding: '',
  keputusan_banding_amount: 0,
  keputusan_banding_notes: '',
  next_action: null
})

const formErrors = ref({})
const revisions = ref([])
const documents = ref([])
const submissionComplete = ref(false)
const fieldsDisabled = ref(false)

const decisionAlertClass = computed(() => {
  switch (formData.value.keputusan_banding) {
    case 'dikabulkan':
      return 'bg-green-50 border-green-500 text-green-800'
    case 'dikabulkan_sebagian':
      return 'bg-yellow-50 border-yellow-500 text-yellow-800'
    case 'ditolak':
      return 'bg-red-50 border-red-500 text-red-800'
    default:
      return 'bg-gray-50 border-gray-300 text-gray-800'
  }
})

// Lifecycle
onMounted(async () => {
  await loadTaxCase()
  await loadRevisions()
  await loadDocuments()
})

// Methods
const getDecisionMessage = () => {
  const messages = {
    'dikabulkan': '✓ Decision: Granted - Case will proceed to Refund or Supreme Court',
    'dikabulkan_sebagian': '→ Decision: Partially Granted - Choose Refund or Supreme Court',
    'ditolak': '✗ Decision: Rejected - Case will proceed to Supreme Court'
  }
  return messages[formData.value.keputusan_banding] || ''
}

const loadTaxCase = async () => {
  try {
    const response = await axios.get(`/api/tax-cases/${props.caseId}`)
    const data = response.data.data

    if (data.appeal_decision_record) {
      Object.keys(data.appeal_decision_record).forEach((key) => {
        if (key in prefillData.value) {
          prefillData.value[key] = data.appeal_decision_record[key]
          formData.value[key] = data.appeal_decision_record[key]
        }
      })
    }
    
    if (data.next_action) {
      formData.value.next_action = data.next_action
    }
  } catch (error) {
    console.error('Error loading tax case:', error)
  }
}

const loadRevisions = async () => {
  try {
    const response = await axios.get(
      `/api/tax-cases/${props.caseId}/revisions?stage_code=10`
    )
    revisions.value = response.data.data || []
  } catch (error) {
    console.error('Error loading revisions:', error)
  }
}

const loadDocuments = async () => {
  try {
    const response = await axios.get(
      `/api/tax-cases/${props.caseId}/documents?stage_code=10`
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

    await axios.post(`/api/tax-cases/${props.caseId}/workflow/10`, formData.value)
    await refreshTaxCase()
  } catch (error) {
    console.error('Error submitting:', error)
    submissionComplete.value = false
    fieldsDisabled.value = false
  }
}

const handleSaveDraft = async () => {
  try {
    await axios.patch(`/api/tax-cases/${props.caseId}/workflow/10`, formData.value)
    await refreshTaxCase()
  } catch (error) {
    console.error('Error saving draft:', error)
  }
}

const handleFieldChanged = (fieldKey) => {
  if (fieldKey === 'keputusan_banding') {
    formData.value.next_action = null
  }
}

const handleDecisionChanged = (data) => {
  formData.value.next_action = data.choice
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
/* Styles match other stage forms */
</style>
```

### Router Configuration

**File:** `resources/js/router/index.js`

```javascript
import AppealDecisionForm from '@/pages/AppealDecisionForm.vue'

// Add route:
{
  path: '/tax-cases/:id/workflow/10',
  component: AppealDecisionForm,
  name: 'appeal-decision-filing',
  meta: { requiresAuth: true },
  props: route => ({ caseId: route.params.id })
}
```

---

## API Endpoint Configuration

**File:** `routes/api.php`

```php
Route::patch('/tax-cases/{id}/workflow/10', function (Request $request, $id) {
    $taxCase = TaxCase::findOrFail($id);
    
    $data = $request->validate([
        'keputusan_banding_number' => 'nullable|string',
        'keputusan_banding_date' => 'nullable|date',
        'keputusan_banding' => 'nullable|in:dikabulkan,dikabulkan_sebagian,ditolak',
        'keputusan_banding_amount' => 'nullable|numeric|min:0',
        'keputusan_banding_notes' => 'nullable|string',
        'next_action' => 'nullable|in:refund,supreme_court'
    ]);

    // Update or create Appeal Decision Record
    $taxCase->appealDecisionRecord()->updateOrCreate(
        ['tax_case_id' => $id],
        $data
    );

    // Handle decision routing
    if (isset($data['next_action'])) {
        $nextStageId = match($data['next_action']) {
            'refund' => 13,           // Bank Transfer Request
            'supreme_court' => 11,    // Peninjauan Kembali
            default => 11
        };

        $taxCase->update([
            'next_stage_id' => $nextStageId,
            'next_action' => $data['next_action']
        ]);
    }

    return response()->json([
        'message' => 'Appeal decision saved successfully',
        'next_stage_id' => $taxCase->next_stage_id,
        'next_action' => $taxCase->next_action
    ]);
})->middleware('auth');
```

---

## Revision System Integration

### Field Type Mapping

**File:** `resources/js/components/RequestRevisionModalV2.vue`

```javascript
const getFieldType = (field) => {
  const appealDecisionFieldTypes = {
    'keputusan_banding_number': 'text',
    'keputusan_banding_date': 'date',
    'keputusan_banding': 'select',
    'keputusan_banding_amount': 'number',
    'keputusan_banding_notes': 'textarea',
    'next_action': 'select'
  }

  return appealDecisionFieldTypes[field] || 'text'
}
```

### Field Labels

```javascript
const fieldLabel = (field) => {
  const labels = {
    'keputusan_banding_number': 'Appeal Decision Number',
    'keputusan_banding_date': 'Decision Date',
    'keputusan_banding': 'Appeal Decision',
    'keputusan_banding_amount': 'Decision Amount',
    'keputusan_banding_notes': 'Decision Notes',
    'next_action': 'Next Action (Route)'
  }

  return labels[field] || field
}
```

### Select Options

```javascript
const getFieldOptions = (field) => {
  if (field === 'keputusan_banding') {
    return [
      { value: 'dikabulkan', label: 'Dikabulkan (Granted)' },
      { value: 'dikabulkan_sebagian', label: 'Dikabulkan Sebagian (Partially Granted)' },
      { value: 'ditolak', label: 'Ditolak (Rejected)' }
    ]
  }
  
  if (field === 'next_action') {
    return [
      { value: 'refund', label: 'Proceed to Refund (Stage 13)' },
      { value: 'supreme_court', label: 'Proceed to Supreme Court (Stage 11)' }
    ]
  }

  return []
}
```

---

## 🚨 Known Obstacles & Solutions (Inherited from Stage 4 Pattern)

When implementing Stage 10, be aware of these common issues that also apply to other DECISION POINT stages (7, 10, 12):

### Obstacle 1: Decision Field Not Prefilled on Page Reload
**Issue:** User selects decision, submits, then refreshes page - decision radio/select not showing selected value
**Root Cause:** Decision field not in `fields` array, so not initialized in formData during onMounted
**Fix:** Explicitly initialize all decision-related fields including those NOT in the form fields list (see Stage 4 Obstacles section 1)

### Obstacle 2: Stage Accessibility Not Updating (Stages Locked When Should Be Accessible)
**Issue:** After user makes decision, next stage remains locked/not accessible. New stage should appear based on decision outcome
**Root Cause:** API returns snake_case (`appeal_decision_record`) but frontend expects camelCase (`appealDecisionRecord`)
**Fix:** Handle BOTH naming conventions in decision getter functions and watchers (see Stage 4 Obstacles section 2)

### Obstacle 3: Watcher Not Triggering When Data Changes
**Issue:** Added watcher for decision field but it never triggers when data loaded
**Root Cause:** Watcher added BEFORE data loaded, or watcher checks wrong property name (case sensitivity)
**Fix:** 
  - Add watcher AFTER onMounted() completes
  - Watch for BOTH snake_case and camelCase naming: `() => [caseData.value?.appealDecisionRecord?.keputusan_banding, caseData.value?.appeal_decision_record?.keputusan_banding]`
  - Add logging to verify which property contains data

### Obstacle 4: Decision Routing Logic Incomplete
**Issue:** Decision made but workflow doesn't route to correct next stage (Refund vs Supreme Court), OR multiple stages appear accessible simultaneously
**Root Cause:** Accessibility logic missing explicit locking for non-chosen paths, OR missing else conditions
**Fix:** Add explicit `stage.accessible = false` statements for locked stages, not just omitting the true assignment (see Stage 4 Obstacles section 4)

### Obstacle 5: Appeal Decision Collapsible Section Disabled
**Issue:** The collapsible section for Appeal stages (8-10) appears disabled even after decision is made
**Root Cause:** Same as Obstacle 2 - decision getter function returns null due to case sensitivity mismatch
**Fix:** Fix the snake_case/camelCase handling - this automatically enables the section

---

## 📋 Implementation Checklist

### Backend
- [ ] Create `AppealDecisionRecord` model
- [ ] Create database migration with 5 fields
- [ ] Run migration
- [ ] Update `TaxCase` model with `appealDecisionRecord()` relationship
- [ ] Update `RevisionService.php` to support Stage 10
- [ ] Update `DecisionPointService.php` Stage 10 mapping
- [ ] Update API endpoint for Stage 10 workflow
- [ ] Add logging for decision changes
- [ ] **CRITICAL:** Ensure API returns complete object with all fields (including user_routing_choice or next_action field)

### Frontend - Components
- [ ] Create `AppealDecisionForm.vue`
- [ ] Update `StageForm.vue` to support Stage 10 decision field
- [ ] **CRITICAL:** Initialize ALL special decision fields in formData (not just fields from fields array)
- [ ] **CRITICAL:** Update watch to sync special fields (not in fields array)
- [ ] Update `DecisionOptionsPanel.vue` configuration
- [ ] Update `RequestRevisionModalV2.vue` field mappings
- [ ] Add Stage 10 field type detection
- [ ] Add Stage 10 label mappings
- [ ] Add Stage 10 select options

### Frontend - Pages
- [ ] Update `TaxCaseDetail.vue` stage unlock logic
- [ ] **CRITICAL:** Handle BOTH snake_case and camelCase in decision getter functions
- [ ] **CRITICAL:** Add watchers for BOTH naming conventions
- [ ] Add Stage 10 to decision point stages list
- [ ] Add Stage 10 to decision point stages list
- [ ] Test stage navigation after decision

### Testing
- [ ] Test real-time decision display
- [ ] Test save/draft with decision choice
- [ ] Test submit & continue
- [ ] Test revision request on decision field
- [ ] Test all 3 decision types (Granted/Partial/Rejected)
- [ ] Test stage unlock logic (Refund vs Supreme Court)
- [ ] Test navigation to next stage

---

**End of Stage 10 (Appeal Decision) Implementation Guide**

*Version 1.0 - January 18, 2026*
*Follows DECISION_POINT_PATTERN.md for consistency across all decision point stages*
