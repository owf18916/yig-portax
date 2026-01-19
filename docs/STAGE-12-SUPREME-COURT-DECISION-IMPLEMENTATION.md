# Stage 12 Implementation - Supreme Court Decision (Keputusan Peninjauan Kembali)

**Document Type:** Implementation Guide for Stage 12  
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
| **Stage Number** | 12 |
| **Stage Name** | Keputusan Peninjauan Kembali (Supreme Court Decision) |
| **Stage Display** | "Supreme Court Decision" |
| **Model Name** | SupremeCourtDecisionRecord |
| **Table Name** | supreme_court_decision_records |
| **Related Model** | TaxCase (HasOne relationship) |
| **Case Status** | SUPREME_COURT_DECISION |
| **Decision Point** | Keputusan (Decision) |
| **Decision Logic** | Routes based on user's explicit choice (Refund or KIAN) |

---

## Form Definition

### Fields (for SupremeCourtDecisionForm.vue)

```javascript
const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'keputusan_pk_number',
    label: 'Nomor Surat Keputusan Peninjauan Kembali (Supreme Court Decision Number)',
    required: true,
    readonly: false,
    placeholder: 'e.g., SK/PK/2024/0001'
  },
  {
    id: 2,
    type: 'date',
    key: 'keputusan_pk_date',
    label: 'Tanggal Keputusan (Decision Date)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'select',
    key: 'keputusan_pk',  // DECISION TRIGGER FIELD
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
    key: 'keputusan_pk_amount',
    label: 'Nilai Keputusan (Decision Amount)',
    required: true,
    readonly: false,
    placeholder: 'Enter decision amount'
  },
  {
    id: 5,
    type: 'textarea',
    key: 'keputusan_pk_notes',
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
  keputusan_pk_number: '',
  keputusan_pk_date: null,
  keputusan_pk: '',  // 'dikabulkan', 'dikabulkan_sebagian', 'ditolak'
  keputusan_pk_amount: 0,
  keputusan_pk_notes: '',
  workflowHistories: []
})
```

### Available Fields for Revision

```javascript
const availableFields = [
  'keputusan_pk_number',
  'keputusan_pk_date',
  'keputusan_pk',
  'keputusan_pk_amount',
  'keputusan_pk_notes',
  'next_action',  // User's choice: refund or kian
  'supporting_docs'
]
```

---

## Decision Logic

### Real-Time Decision Display

**Trigger Field:** `keputusan_pk` (Supreme Court Decision field)

When user selects a value in the `keputusan_pk` dropdown, immediately show the next action options:

```
User selects "Dikabulkan", "Dikabulkan Sebagian", or "Ditolak"
       ↓ (instant)
Decision Options appear:
- Option 1: Proceed to Refund (Stage 13)
- Option 2: Proceed to KIAN (Stage 16)
```

### Decision Flow

```
Stage 12: Supreme Court Decision (Final)
       ↓
User enters decision data
       ↓
User selects Keputusan Peninjauan Kembali value
       ↓
[REAL-TIME] Next Action Options appear
       ↓
User selects: Refund OR KIAN
       ↓
User clicks "Save as Draft" or "Submit & Continue"
       ↓
       ┌────────────────────────────────────┐
       │ Option A: Proceed to Refund        │
       │ → Next Stage: 13 (Bank Transfer)   │
       └────────────────────────────────────┘
              OR
       ┌────────────────────────────────────┐
       │ Option B: Proceed to KIAN          │
       │ → Next Stage: 16 (KIAN Report)     │
       └────────────────────────────────────┘
```

### Decision Routing Notes

Per PORTAX_FLOW.md (Supreme Court Decision - Decision Point 4):
- **Dikabulkan (Granted)** → Proceed to Refund (Stage 13) - Case Resolved Favorably
- **Dikabulkan Sebagian (Partially Granted)** → Proceed to Refund (Stage 13) - Accepted (Partial)
- **Ditolak (Rejected)** → Proceed to KIAN (Stage 16) - Case goes to loss recognition

**Special Note:** Unlike Stages 4, 7, and 10, this decision point typically has a predetermined path:
- Granted/Partial → Usually Refund
- Rejected → Usually KIAN

However, we still allow user choice for flexibility in case of special circumstances.

### Backend Decision Mapper

In `DecisionPointService.php`, Stage 12 mapping:

```php
12 => [  // Supreme Court Decision
    'decision_field' => 'keputusan_pk',
    'options' => [
        'refund' => [
            'label' => 'Proceed to Refund',
            'next_stage' => 13,
            'description' => 'Request Bank Transfer (Stage 13)'
        ],
        'kian' => [
            'label' => 'Proceed to KIAN',
            'next_stage' => 16,
            'description' => 'File KIAN Report (Stage 16)'
        ],
    ]
]
```

---

## Database Schema

### Migration

**File:** `database/migrations/2026_01_01_000012_create_supreme_court_decision_records.php`

```php
Schema::create('supreme_court_decision_records', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tax_case_id')
        ->constrained('tax_cases')
        ->cascadeOnDelete();

    // Supreme Court Decision specific fields
    $table->string('keputusan_pk_number')->nullable();
    $table->date('keputusan_pk_date')->nullable();
    
    // Decision field - determines routing
    $table->enum('keputusan_pk', ['dikabulkan', 'dikabulkan_sebagian', 'ditolak'])->nullable();
    
    // Amount field
    $table->decimal('keputusan_pk_amount', 15, 2)->default(0);
    
    // Notes field
    $table->text('keputusan_pk_notes')->nullable();

    $table->timestamps();
    $table->softDeletes();
});
```

### Model

**File:** `app/Models/SupremeCourtDecisionRecord.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupremeCourtDecisionRecord extends Model
{
    use SoftDeletes;

    protected $table = 'supreme_court_decision_records';

    protected $fillable = [
        'tax_case_id',
        'keputusan_pk_number',
        'keputusan_pk_date',
        'keputusan_pk',
        'keputusan_pk_amount',
        'keputusan_pk_notes'
    ];

    protected $casts = [
        'keputusan_pk_date' => 'date',
        'keputusan_pk_amount' => 'decimal:2',
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
public function supremeCourtDecisionRecord(): HasOne
{
    return $this->hasOne(SupremeCourtDecisionRecord::class);
}
```

---

## Backend Implementation

### Update RevisionService

**File:** `app/Services/RevisionService.php`

Add Stage 12 support in `requestRevision()`:

```php
elseif ((int)$stageCode === 12) {
    if (!$revisable->relationLoaded('supremeCourtDecisionRecord')) {
        $revisable->load('supremeCourtDecisionRecord');
    }
    $dataSource = $revisable->supremeCourtDecisionRecord;
}
```

Add Stage 12 support in `approveRevision()`:

```php
elseif ($stageCode == 12 && $revisable->supremeCourtDecisionRecord) {
    $updateTarget = $revisable->supremeCourtDecisionRecord;
}

// After update, handle decision routing
if ($stageCode === 12 && isset($updateData['next_action'])) {
    $revisable->update([
        'next_stage_id' => $updateData['next_action'] === 'refund' ? 13 : 16,
        'next_action' => $updateData['next_action'],
        // Also update case_status based on outcome
        'case_status' => $updateData['next_action'] === 'refund' ? 'GRANTED' : 'NOT_GRANTED_PARTIAL'
    ]);
}
```

Add Stage 12 to field detection in `detectStageFromFields()`:

```php
12 => [
    'keputusan_pk_number', 
    'keputusan_pk_date', 
    'keputusan_pk', 
    'keputusan_pk_amount', 
    'keputusan_pk_notes'
],
```

---

## Frontend Implementation

### Create SupremeCourtDecisionForm.vue

**File:** `resources/js/pages/SupremeCourtDecisionForm.vue`

```vue
<template>
  <div class="flex h-screen bg-gray-100">
    <!-- Left side: Form + Documents -->
    <div class="w-1/2 overflow-y-auto bg-white">
      <div class="p-6">
        <h1 class="text-3xl font-bold text-gray-900 mb-6">
          Stage 12: Keputusan Peninjauan Kembali (Supreme Court Decision)
        </h1>

        <!-- Important Notice -->
        <div class="mb-6 p-4 bg-purple-50 border border-purple-200 rounded-lg">
          <p class="text-sm text-purple-800">
            <strong>⚠️ Final Decision:</strong> This is the final decision point. Your choice will determine whether the case proceeds to Refund (favorable outcome) or KIAN (loss recognition).
          </p>
        </div>

        <!-- Decision Info Alert -->
        <div
          v-if="formData.keputusan_pk"
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
          :entityType="'supreme_court_decision'"
          :stage-code="12"
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
        :entityType="'supreme_court_decision'"
        :stageCode="12"
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
    key: 'keputusan_pk_number',
    label: 'Nomor Surat Keputusan Peninjauan Kembali (Supreme Court Decision Number)',
    required: true,
    readonly: false,
    placeholder: 'e.g., SK/PK/2024/0001'
  },
  {
    id: 2,
    type: 'date',
    key: 'keputusan_pk_date',
    label: 'Tanggal Keputusan (Decision Date)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'select',
    key: 'keputusan_pk',
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
    key: 'keputusan_pk_amount',
    label: 'Nilai Keputusan (Decision Amount)',
    required: true,
    readonly: false,
    placeholder: 'Enter decision amount'
  },
  {
    id: 5,
    type: 'textarea',
    key: 'keputusan_pk_notes',
    label: 'Catatan Keputusan (Decision Notes)',
    required: false,
    readonly: false,
    placeholder: 'Enter any additional notes...'
  }
])

const prefillData = ref({
  keputusan_pk_number: '',
  keputusan_pk_date: null,
  keputusan_pk: '',
  keputusan_pk_amount: 0,
  keputusan_pk_notes: '',
  workflowHistories: []
})

const formData = ref({
  keputusan_pk_number: '',
  keputusan_pk_date: null,
  keputusan_pk: '',
  keputusan_pk_amount: 0,
  keputusan_pk_notes: '',
  next_action: null
})

const formErrors = ref({})
const revisions = ref([])
const documents = ref([])
const submissionComplete = ref(false)
const fieldsDisabled = ref(false)

const decisionAlertClass = computed(() => {
  switch (formData.value.keputusan_pk) {
    case 'dikabulkan':
      return 'bg-green-50 border-green-500 text-green-800'
    case 'dikabulkan_sebagian':
      return 'bg-green-50 border-green-500 text-green-800'  // Partial = still refund
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
    'dikabulkan': '✓ Decision: Granted - Case will proceed to Refund (Favorable Outcome)',
    'dikabulkan_sebagian': '✓ Decision: Partially Granted - Case will proceed to Refund (Favorable Outcome)',
    'ditolak': '✗ Decision: Rejected - Case will proceed to KIAN (Loss Recognition)'
  }
  return messages[formData.value.keputusan_pk] || ''
}

const loadTaxCase = async () => {
  try {
    const response = await axios.get(`/api/tax-cases/${props.caseId}`)
    const data = response.data.data

    if (data.supreme_court_decision_record) {
      Object.keys(data.supreme_court_decision_record).forEach((key) => {
        if (key in prefillData.value) {
          prefillData.value[key] = data.supreme_court_decision_record[key]
          formData.value[key] = data.supreme_court_decision_record[key]
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
      `/api/tax-cases/${props.caseId}/revisions?stage_code=12`
    )
    revisions.value = response.data.data || []
  } catch (error) {
    console.error('Error loading revisions:', error)
  }
}

const loadDocuments = async () => {
  try {
    const response = await axios.get(
      `/api/tax-cases/${props.caseId}/documents?stage_code=12`
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

    await axios.post(`/api/tax-cases/${props.caseId}/workflow/12`, formData.value)
    await refreshTaxCase()
  } catch (error) {
    console.error('Error submitting:', error)
    submissionComplete.value = false
    fieldsDisabled.value = false
  }
}

const handleSaveDraft = async () => {
  try {
    await axios.patch(`/api/tax-cases/${props.caseId}/workflow/12`, formData.value)
    await refreshTaxCase()
  } catch (error) {
    console.error('Error saving draft:', error)
  }
}

const handleFieldChanged = (fieldKey) => {
  if (fieldKey === 'keputusan_pk') {
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
import SupremeCourtDecisionForm from '@/pages/SupremeCourtDecisionForm.vue'

// Add route:
{
  path: '/tax-cases/:id/workflow/12',
  component: SupremeCourtDecisionForm,
  name: 'supreme-court-decision-filing',
  meta: { requiresAuth: true },
  props: route => ({ caseId: route.params.id })
}
```

---

## API Endpoint Configuration

**File:** `routes/api.php`

```php
Route::patch('/tax-cases/{id}/workflow/12', function (Request $request, $id) {
    $taxCase = TaxCase::findOrFail($id);
    
    $data = $request->validate([
        'keputusan_pk_number' => 'nullable|string',
        'keputusan_pk_date' => 'nullable|date',
        'keputusan_pk' => 'nullable|in:dikabulkan,dikabulkan_sebagian,ditolak',
        'keputusan_pk_amount' => 'nullable|numeric|min:0',
        'keputusan_pk_notes' => 'nullable|string',
        'next_action' => 'nullable|in:refund,kian'
    ]);

    // Update or create Supreme Court Decision Record
    $taxCase->supremeCourtDecisionRecord()->updateOrCreate(
        ['tax_case_id' => $id],
        $data
    );

    // Handle decision routing
    if (isset($data['next_action'])) {
        $caseStatus = $data['next_action'] === 'refund' ? 'GRANTED' : 'NOT_GRANTED_PARTIAL';
        
        $nextStageId = match($data['next_action']) {
            'refund' => 13,    // Bank Transfer Request
            'kian' => 16,      // KIAN Report
            default => 16
        };

        $taxCase->update([
            'next_stage_id' => $nextStageId,
            'next_action' => $data['next_action'],
            'case_status' => $caseStatus
        ]);

        Log::info("Supreme Court Decision - Final Routing", [
            'tax_case_id' => $taxCase->id,
            'keputusan_pk' => $data['keputusan_pk'],
            'choice' => $data['next_action'],
            'next_stage_id' => $nextStageId,
            'case_status' => $caseStatus
        ]);
    }

    return response()->json([
        'message' => 'Supreme Court decision saved successfully',
        'next_stage_id' => $taxCase->next_stage_id,
        'next_action' => $taxCase->next_action,
        'case_status' => $taxCase->case_status
    ]);
})->middleware('auth');
```

---

## Revision System Integration

### Field Type Mapping

**File:** `resources/js/components/RequestRevisionModalV2.vue`

```javascript
const getFieldType = (field) => {
  const supremeCourtDecisionFieldTypes = {
    'keputusan_pk_number': 'text',
    'keputusan_pk_date': 'date',
    'keputusan_pk': 'select',
    'keputusan_pk_amount': 'number',
    'keputusan_pk_notes': 'textarea',
    'next_action': 'select'
  }

  return supremeCourtDecisionFieldTypes[field] || 'text'
}
```

### Field Labels

```javascript
const fieldLabel = (field) => {
  const labels = {
    'keputusan_pk_number': 'Supreme Court Decision Number',
    'keputusan_pk_date': 'Decision Date',
    'keputusan_pk': 'Supreme Court Decision',
    'keputusan_pk_amount': 'Decision Amount',
    'keputusan_pk_notes': 'Decision Notes',
    'next_action': 'Next Action (Route)'
  }

  return labels[field] || field
}
```

### Select Options

```javascript
const getFieldOptions = (field) => {
  if (field === 'keputusan_pk') {
    return [
      { value: 'dikabulkan', label: 'Dikabulkan (Granted)' },
      { value: 'dikabulkan_sebagian', label: 'Dikabulkan Sebagian (Partially Granted)' },
      { value: 'ditolak', label: 'Ditolak (Rejected)' }
    ]
  }
  
  if (field === 'next_action') {
    return [
      { value: 'refund', label: 'Proceed to Refund (Stage 13) - Favorable Outcome' },
      { value: 'kian', label: 'Proceed to KIAN (Stage 16) - Loss Recognition' }
    ]
  }

  return []
}
```

---

## Implementation Checklist

### Backend
- [ ] Create `SupremeCourtDecisionRecord` model
- [ ] Create database migration with 5 fields
- [ ] Run migration
- [ ] Update `TaxCase` model with `supremeCourtDecisionRecord()` relationship
- [ ] Update `RevisionService.php` to support Stage 12
- [ ] Update `DecisionPointService.php` Stage 12 mapping
- [ ] Update API endpoint for Stage 12 workflow
- [ ] Add case_status update logic (GRANTED vs NOT_GRANTED_PARTIAL)
- [ ] Add logging for final decision

### Frontend - Components
- [ ] Create `SupremeCourtDecisionForm.vue`
- [ ] Update `StageForm.vue` to support Stage 12 decision field
- [ ] Update `DecisionOptionsPanel.vue` configuration
- [ ] Update `RequestRevisionModalV2.vue` field mappings
- [ ] Add Stage 12 field type detection
- [ ] Add Stage 12 label mappings
- [ ] Add Stage 12 select options

### Frontend - Pages
- [ ] Update `TaxCaseDetail.vue` stage unlock logic
- [ ] Add Stage 12 to decision point stages list
- [ ] Add terminal stage logic (marks as final decision)
- [ ] Test stage navigation after decision

### Testing
- [ ] Test real-time decision display
- [ ] Test save/draft with decision choice
- [ ] Test submit & continue
- [ ] Test revision request on decision field
- [ ] Test all 3 decision types (Granted/Partial/Rejected)
- [ ] Test stage unlock logic (Refund vs KIAN)
- [ ] Test case_status update (GRANTED vs NOT_GRANTED_PARTIAL)
- [ ] Test navigation to final stage

---

**End of Stage 12 (Supreme Court Decision) Implementation Guide**

*Version 1.0 - January 18, 2026*
*Follows DECISION_POINT_PATTERN.md for consistency across all decision point stages*
*This is the FINAL decision point - no further routing options after this stage*
