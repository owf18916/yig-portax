# Stage Implementation Template - PorTax System

**Document Type:** Implementation Guide Template  
**Version:** 1.0  
**Last Updated:** January 17, 2026

---

## 📋 Table of Contents

1. [PART A: Generic Foundation](#part-a-generic-foundation)
2. [PART B: Unique Section (Example: Stage 3 - SPHP)](#part-b-unique-section-example-stage-3-sphp)
3. [PART C: Implementation Checklist](#part-c-implementation-checklist)

---

# PART A: GENERIC FOUNDATION

*These sections apply to ALL stages (1-16). Follow this pattern for consistency.*

---

## A1. ARCHITECTURE & DATABASE PATTERNS

### A1.1 Database Naming Convention
```
Table names: {stage_slug}_records
  - sp2_records (Stage 2)
  - sphp_records (Stage 3)
  - skp_records (Stage 4)
  - objection_submissions (Stage 5)
  - spuh_records (Stage 6)
  - objection_decisions (Stage 7)
  - appeal_submissions (Stage 8)
  - appeal_explanation_requests (Stage 9)
  - appeal_decisions (Stage 10)
  - supreme_court_submissions (Stage 11)
  - supreme_court_decisions (Stage 12)
  - bank_transfer_requests (Stage 13-14)
  - refund_processes (Stage 15)
  - kian_submissions (Stage 16)

Foreign key: tax_case_id (references tax_cases.id)
Timestamp columns: created_at, updated_at, deleted_at (soft delete)
```

### A1.2 Model Relationship Pattern
```php
// In TaxCase model:
public function sp2Record(): HasOne {
    return $this->hasOne(Sp2Record::class);
}

// In Sp2Record model (or equivalent):
public function taxCase(): BelongsTo {
    return $this->belongsTo(TaxCase::class);
}

// In Revision model:
public function revisable(): MorphTo {
    return $this->morphTo();
}
```

### A1.3 Migration Pattern
**IMPORTANT: Check existing related tables FIRST before creating new migration**

```php
// Before creating new migration, check if stage_code column exists in revisions table
// If not, modify existing migration file:
// database/migrations/2026_01_16_235127_add_stage_code_to_revisions_table.php

// For stage-specific tables, create only if not exists:
Schema::create('{stage}_records', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tax_case_id')->constrained('tax_cases')->cascadeOnDelete();
    
    // Stage-specific fields here
    
    $table->timestamps();
    $table->softDeletes();
});
```

### A1.4 API Endpoint Pattern
```
GET    /api/tax-cases/{id}                          (includes stage models)
PATCH  /api/tax-cases/{id}/workflow/{stageId}       (submit/save draft)
POST   /api/tax-cases/{id}/revisions/request         (request revision)
PATCH  /api/tax-cases/{id}/revisions/{revisionId}/decide (approve/reject)
GET    /api/tax-cases/{id}/documents?stage_code={X} (stage-specific docs)
```

### A1.5 Component Hierarchy Pattern
```
Parent: [StageName]FilingForm.vue
├─ StageForm.vue (reusable form container)
│  └─ FormField.vue (individual field input)
├─ RevisionHistoryPanel.vue (revision management)
│  ├─ RequestRevisionModalV2.vue
│  ├─ RevisionApprovalModalV2.vue
│  └─ BeforeAfterComparison.vue
└─ Document Upload Section (built-in to StageForm)
```

---

## A2. REVISION SYSTEM INTEGRATION

### A2.1 Stage Detection (Backend)
```php
// In RevisionService.php:
private function detectStageFromFields(array $fieldNames): ?int {
    $stageFieldMaps = [
        2 => ['sp2_number', 'issue_date', 'receipt_date', 'auditor_name', 'auditor_phone', 'auditor_email'],
        3 => ['sphp_number', 'sphp_issue_date', 'sphp_receipt_date', 'royalty_finding', 'service_finding', 'other_finding'],
        4 => ['skp_number', 'skp_issue_date', 'skp_receipt_date', 'skp_type', 'skp_amount'],
        // Add more stages...
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

### A2.2 Stage-Specific Data Source Selection
```php
// In RevisionService.requestRevision():
private function getDataSourceForStage(TaxCase $taxCase, int $stageCode): Model {
    return match($stageCode) {
        2 => $taxCase->sp2Record,
        3 => $taxCase->sphpRecord,
        4 => $taxCase->skpRecord,
        // Add more stages...
        default => $taxCase
    };
}
```

### A2.3 Update Target Detection (Approval)
```php
// In RevisionService.approveRevision():
$stageCode = $revision->stage_code;

if ($stageCode && $revisable instanceof TaxCase) {
    if ($stageCode == 2 && $revisable->sp2Record) {
        $updateTarget = $revisable->sp2Record;
    }
    // Add more stage conditions...
}
```

---

## A3. COMPONENT PATTERNS

### A3.1 Field Types Supported in StageForm
- ✅ `text` - Text input
- ✅ `email` - Email input  
- ✅ `date` - Date picker
- ✅ `month` - Month picker
- ✅ `number` - Number input
- ✅ `textarea` - Multi-line text
- ✅ `select` - Dropdown
- ✅ `radio` - Radio buttons
- ✅ `checkbox` - Checkbox

**Location:** `resources/js/components/StageForm.vue` (lines ~70-200)

### A3.2 Form Data Structure
```javascript
// prefillData - loaded from database
{
  field1: value1,
  field2: value2,
  workflowHistories: []
}

// formData - current user input (v-model binding)
{
  field1: 'current value',
  field2: 'current value'
}

// formErrors - validation errors
{
  field1: 'Error message',
  field2: 'Error message'
}
```

### A3.3 Fields Array Definition
```javascript
const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'field_key',
    label: 'User Friendly Label',
    required: true,
    readonly: false,
    placeholder: 'Placeholder text',
    options: [] // for select/radio only
  },
  // ... more fields
])
```

### A3.4 Revision Modal Integration
- **RequestRevisionModalV2**: Allow user to propose changes
- **RevisionApprovalModalV2**: Show before/after comparison
- **fieldLabel()**: Use `props.fields` array first, then fallback to composable

---

## A4. FORM DATA FLOW (Complete Lifecycle)

```
1. Component Mounts (onMounted)
   ↓
2. Fetch TaxCase + Stage Model from API
   ↓
3. Populate prefillData from stage model
   ↓
4. User edits formData (v-model binding)
   ↓
5. User clicks Submit / Save Draft / Approve Revision
   ↓
6. Validate formData
   ↓
7. POST to API endpoint
   ↓
8. API updates database (stage-specific table)
   ↓
9. Trigger refreshTaxCase()
   ↓
10. Re-fetch all data from API
    ↓
11. Update prefillData with new values
    ↓
12. Form displays new values
```

---

## A5. KNOWN OBSTACLES & SOLUTIONS

### Obstacle 1: Stage Data in Related Table, Not Main tax_cases Table
**Problem:** Stage-specific data (auditor_name, auditor_phone, etc) are stored in separate tables (sp2_records, sphpRecord, etc), not in tax_cases table.

**Solution:**
- Create stage-specific Model + Table
- Add HasOne relationship in TaxCase model
- In API: Return stage model data with case data
- In Revision: Use `getDataSourceForStage()` to determine which table to update

**Example:**
```php
// API Response
{
  data: {
    id: 1,
    case_number: "PT24JanC",
    sp2_record: {
      sp2_number: "SP0021",
      auditor_phone: "09977881",
      // ... other SP2 fields
    }
  }
}
```

### Obstacle 2: Data Not Updating When Revision Approved
**Problem:** Revision approved but data still shows old values.

**Causes:**
- stage_code column missing in revisions table
- Approval logic updating wrong table (tax_cases instead of sp2_records)
- prefillData not refreshed after approval

**Solution:**
- ✅ Add stage_code column to revisions table (migration)
- ✅ Store stage_code when creating revision
- ✅ Use stage_code in approveRevision() to select correct table
- ✅ Call refreshTaxCase() to reload form data after approval

**Code Flow:**
```php
// requestRevision() - save stage_code
$revision->update([
    'stage_code' => (int)$stageCode,
    // ... other fields
]);

// approveRevision() - use stage_code
$stageCode = $revision->stage_code;
$updateTarget = $this->getDataSourceForStage($revisable, $stageCode);
$updateTarget->update($updates);
```

### Obstacle 3: Field Labels Showing Database Names
**Problem:** Revision history shows "auditor_phone" instead of "Auditor Phone"

**Solution:**
- Pass `fields` array prop from parent component
- In fieldLabel() function, check props.fields first for proper label
- Fallback to composable only if not found

**Implementation:**
```javascript
const fieldLabel = (field) => {
  // Priority 1: Check fields array prop
  if (props.fields && props.fields.length > 0) {
    const fieldDef = props.fields.find(f => f.key === field)
    if (fieldDef?.label) {
      return fieldDef.label
    }
  }
  
  // Priority 2: Fallback to composable
  return getFieldLabel(props.entityType, field)
}
```

### Obstacle 4: Email Input Type Not Supported
**Problem:** Stage with email field (auditor_email) shows text input instead of email validation.

**Solution:**
- Add email type handling to StageForm.vue
- Location: `resources/js/components/StageForm.vue` (after number input)

```vue
<!-- Email Input -->
<FormField
  v-if="field.type === 'email'"
  :label="field.label"
  type="email"
  v-model="formData[field.key]"
  :placeholder="field.placeholder"
  :required="field.required"
  :error="formErrors[field.key]"
  :disabled="field.readonly || submissionComplete || fieldsDisabled"
/>
```

### Obstacle 5: Form Not Refreshing After Change
**Problem:** After submit/save draft/approve revision, form still shows old values.

**Solution:**
- Trigger `refreshTaxCase()` on all update events
- refreshTaxCase must re-fetch from API and update prefillData
- In parent component, hook RevisionHistoryPanel events to refreshTaxCase

**Implementation:**
```vue
<!-- In Sp2FilingForm.vue -->
<RevisionHistoryPanel 
  @revision-requested="refreshTaxCase"
  @refresh="refreshTaxCase"
/>

<!-- refreshTaxCase function -->
const refreshTaxCase = async () => {
  // 1. Fetch updated data from API
  // 2. Update caseData
  // 3. Update prefillData with sp2_record values
  // 4. Call loadRevisions() and loadDocuments()
}
```

---

## A6. FRONTEND STYLING PATTERNS

### A6.1 Color & Status Coding
```scss
// Status colors (used consistently across all stages)
$status-open: #3B82F6;        // Blue - Active
$status-pending: #F59E0B;     // Amber - Waiting
$status-approved: #10B981;    // Green - Accepted
$status-rejected: #EF4444;    // Red - Rejected
$status-processing: #8B5CF6;  // Purple - In Progress

// Revision status colors
.revision-status {
  &.requested { color: $status-pending; }
  &.approved { color: $status-approved; }
  &.rejected { color: $status-rejected; }
}
```

### A6.2 Layout Pattern
```vue
<!-- Left side: Form (50%) + Documents -->
<!-- Right side: Revision History (50%) -->
<div class="flex h-screen bg-gray-100">
  <div class="w-1/2 overflow-y-auto bg-white">
    <!-- Form content -->
  </div>
  <div class="w-1/2 overflow-y-auto bg-gray-50">
    <!-- Revision history + modals -->
  </div>
</div>
```

### A6.3 Form Styling
```vue
<!-- Field container -->
<div class="space-y-2">
  <label class="block text-sm font-medium text-gray-700">
    {{ field.label }}
    <span v-if="field.required" class="text-red-500">*</span>
  </label>
  
  <!-- Input -->
  <input 
    v-model="formData[field.key]"
    class="w-full px-4 py-2 border border-gray-300 rounded-lg 
           focus:outline-none focus:ring-2 focus:ring-blue-500 
           disabled:bg-gray-100 disabled:text-gray-500"
    :disabled="field.readonly || submissionComplete"
  />
  
  <!-- Error message -->
  <p v-if="formErrors[field.key]" class="text-red-500 text-sm mt-1">
    {{ formErrors[field.key] }}
  </p>
</div>
```

### A6.4 Modal Styling
```vue
<!-- Modal overlay -->
<div class="modal-overlay" @click.self="close">
  <div class="modal-content modal-lg">
    <!-- Header -->
    <div class="modal-header">
      <h3>{{ title }}</h3>
      <button class="close-btn">×</button>
    </div>
    
    <!-- Body with scrollable content -->
    <div class="modal-body">
      <!-- Content -->
    </div>
    
    <!-- Footer with actions -->
    <div class="modal-footer">
      <button class="btn btn-secondary">Cancel</button>
      <button class="btn btn-primary">Confirm</button>
    </div>
  </div>
</div>
```

### A6.5 Button States
```scss
// Button styling
.btn {
  &.btn-primary {
    @apply bg-blue-600 hover:bg-blue-700 text-white;
    &:disabled { @apply bg-gray-300 cursor-not-allowed; }
  }
  
  &.btn-secondary {
    @apply bg-gray-300 hover:bg-gray-400 text-gray-900;
    &:disabled { @apply opacity-50 cursor-not-allowed; }
  }
  
  &.btn-success {
    @apply bg-green-600 hover:bg-green-700 text-white;
  }
  
  &.btn-danger {
    @apply bg-red-600 hover:bg-red-700 text-white;
  }
}
```

### A6.6 Status Badge Styling
```vue
<!-- Revision status badge -->
<span v-if="revision.revision_status === 'approved'" class="badge badge-success">
  ✅ APPROVED
</span>
<span v-else-if="revision.revision_status === 'requested'" class="badge badge-pending">
  ⏳ PENDING REVIEW
</span>
<span v-else-if="revision.revision_status === 'rejected'" class="badge badge-danger">
  ✗ REJECTED
</span>
```

---

## A7. COMMON TESTING CHECKLIST

```
✓ CRUD Operations:
  [ ] Can create new stage record
  [ ] Can read/display stage data
  [ ] Can update stage fields
  [ ] Can save as draft
  [ ] Can submit for approval

✓ Revision Workflow:
  [ ] Can request revision for stage fields
  [ ] Can see before/after comparison
  [ ] Can approve revision
  [ ] Can reject revision
  [ ] Rejection reason shows correctly

✓ Data Updates:
  [ ] Approved revision updates database (correct table)
  [ ] Form refreshes with new values
  [ ] Revision history shows applied changes
  [ ] Field labels display correctly (not database names)

✓ Auto-Refresh:
  [ ] Form refreshes after Submit
  [ ] Form refreshes after Save Draft
  [ ] Form refreshes after Approve Revision
  [ ] prefillData updates correctly

✓ Field Types:
  [ ] Text input works
  [ ] Email input works with validation
  [ ] Date picker works
  [ ] Number input works
  [ ] Dropdown (select) works
  [ ] Radio buttons work
  [ ] Checkbox works

✓ Documents:
  [ ] Can upload documents
  [ ] Stage-specific documents filter correctly
  [ ] Can delete uploaded documents
  [ ] Document count displays correctly

✓ UI/UX:
  [ ] Form loads smoothly
  [ ] Modals open/close correctly
  [ ] Status indicators display correctly
  [ ] Error messages show clearly
  [ ] Loading states show when fetching
```

---

# PART B: UNIQUE SECTION (Example: Stage 3 - SPHP)

*Copy this section and modify for each new stage. Refer to PORTAX_FLOW.md for stage details.*

---

## B1. STAGE IDENTITY

| Property | Value |
|----------|-------|
| **Stage Number** | 3 |
| **Stage Name** | SPHP (Surat Pemberitahuan Hasil Pemeriksaan) |
| **Stage Display** | "Audit Findings" |
| **Model Name** | SphpRecord |
| **Table Name** | sphp_records |
| **Related Model** | TaxCase (HasOne relationship) |
| **Case Status** | SPHP_RECEIVED |

---

## B2. FORM DEFINITION

### B2.1 Form Fields (for Sp2FilingForm.vue equivalent - SphpFilingForm.vue)

```javascript
const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'sphp_number',
    label: 'Nomor SPHP (SPHP Number)',
    required: true,
    readonly: false,
    placeholder: 'e.g., SPHP/2024/0001'
  },
  {
    id: 2,
    type: 'date',
    key: 'sphp_issue_date',
    label: 'Tanggal Diterbitkan (Issue Date)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'date',
    key: 'sphp_receipt_date',
    label: 'Tanggal Diterima (Receipt Date)',
    required: true,
    readonly: false
  },
  {
    id: 4,
    type: 'number',
    key: 'royalty_finding',
    label: 'Royalty Finding Amount',
    required: false,
    readonly: false,
    placeholder: 'Enter royalty finding amount'
  },
  {
    id: 5,
    type: 'number',
    key: 'service_finding',
    label: 'Service Finding Amount',
    required: false,
    readonly: false,
    placeholder: 'Enter service finding amount'
  },
  {
    id: 6,
    type: 'number',
    key: 'other_finding',
    label: 'Other Finding Amount',
    required: false,
    readonly: false,
    placeholder: 'Enter other finding amount'
  },
  {
    id: 7,
    type: 'textarea',
    key: 'other_finding_notes',
    label: 'Notes for Other Findings',
    required: false,
    readonly: false,
    placeholder: 'Provide details for other findings...'
  }
])
```

### B2.2 prefillData Structure

```javascript
const prefillData = ref({
  sphp_number: '',
  sphp_issue_date: null,
  sphp_receipt_date: null,
  royalty_finding: 0,
  service_finding: 0,
  other_finding: 0,
  other_finding_notes: '',
  workflowHistories: []
})
```

### B2.3 Available Fields for Revision

```javascript
const availableFields = [
  'sphp_number',
  'sphp_issue_date',
  'sphp_receipt_date',
  'royalty_finding',
  'service_finding',
  'other_finding',
  'other_finding_notes',
  'supporting_docs'
]
```

---

## B3. DATABASE SCHEMA

### B3.1 Check Existing Tables First

```sql
-- Before creating new table, check if sphp_records exists:
PRAGMA table_info(sphp_records);

-- If exists, check columns:
SELECT sql FROM sqlite_master WHERE type='table' AND name='sphp_records';

-- If not exists, proceed to create migration
```

### B3.2 Migration File

**File:** `database/migrations/YYYY_MM_DD_XXXXXX_create_sphp_records_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // First, check if table already exists
        if (!Schema::hasTable('sphp_records')) {
            Schema::create('sphp_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tax_case_id')
                    ->constrained('tax_cases')
                    ->cascadeOnDelete();
                
                // SPHP-specific fields
                $table->string('sphp_number')->nullable();
                $table->date('sphp_issue_date')->nullable();
                $table->date('sphp_receipt_date')->nullable();
                
                // Audit finding amounts
                $table->decimal('royalty_finding', 15, 2)->nullable();
                $table->decimal('service_finding', 15, 2)->nullable();
                $table->decimal('other_finding', 15, 2)->nullable();
                $table->text('other_finding_notes')->nullable();
                
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sphp_records');
    }
};
```

### B3.3 Model Creation

**File:** `app/Models/SphpRecord.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SphpRecord extends Model
{
    use SoftDeletes;

    protected $table = 'sphp_records';

    protected $fillable = [
        'tax_case_id',
        'sphp_number',
        'sphp_issue_date',
        'sphp_receipt_date',
        'royalty_finding',
        'service_finding',
        'other_finding',
        'other_finding_notes',
    ];

    protected $casts = [
        'royalty_finding' => 'decimal:2',
        'service_finding' => 'decimal:2',
        'other_finding' => 'decimal:2',
        'sphp_issue_date' => 'date',
        'sphp_receipt_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relationships
    public function taxCase(): BelongsTo
    {
        return $this->belongsTo(TaxCase::class);
    }
}
```

### B3.4 Update TaxCase Model

```php
// Add to app/Models/TaxCase.php

public function sphpRecord(): HasOne
{
    return $this->hasOne(SphpRecord::class);
}
```

---

## B4. BACKEND: API ENDPOINTS & CONTROLLERS

### B4.1 Update API Response (TaxCaseController)

```php
// In show() method, ensure sphpRecord is included:
$taxCase->load([
    'sp2Record',
    'sphpRecord',      // Add this
    'workflowHistories',
    'documents'
]);

return response()->json([
    'data' => $taxCase
]);
```

### B4.2 Update Revision Service

```php
// In RevisionService.php:

// 1. Update detectStageFromFields():
private function detectStageFromFields(array $fieldNames): ?int {
    $sp2Fields = [...];
    $sphpFields = [
        'sphp_number', 'sphp_issue_date', 'sphp_receipt_date',
        'royalty_finding', 'service_finding', 'other_finding', 'other_finding_notes'
    ];
    
    // Check SPHP
    foreach ($fieldNames as $field) {
        if (in_array($field, $sphpFields)) {
            return 3;
        }
    }
    
    // ... rest of checks
}

// 2. Update approveRevision():
if ($stageCode == 3 && $revisable->sphpRecord) {
    $updateTarget = $revisable->sphpRecord;
    Log::info('RevisionService: Using sphpRecord as update target');
}

// 3. Add to requestRevision():
if ((int)$stageCode === 3 && $sp2) {
    $dataSource = $revisable->sphpRecord;
}
```

---

## B5. FRONTEND: FORM COMPONENT

### B5.1 Create SphpFilingForm.vue

**File:** `resources/js/pages/SphpFilingForm.vue`

```vue
<template>
  <div class="h-full">
    <!-- Loading Overlay -->
    <div v-if="isLoading" class="fixed inset-0 backdrop-blur-sm bg-white/30 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl p-8 text-center shadow-2xl border border-white/50">
        <div class="animate-spin rounded-full h-12 w-12 border-4 border-blue-200 border-t-blue-500 mx-auto mb-4"></div>
        <p class="text-gray-700 font-medium">Loading form data...</p>
      </div>
    </div>

    <!-- STAGE FORM -->
    <StageForm
      :stageName="`Stage 3: SPHP - Audit Findings`"
      :stageDescription="`Input audit findings notification received from tax authority`"
      :stageId="3"
      :nextStageId="4"
      :caseId="caseId"
      :caseNumber="caseNumber"
      :fields="fields"
      :apiEndpoint="`/api/tax-cases/${caseId}/workflow/3`"
      :isReviewMode="true"
      :isLoading="isLoading"
      :caseStatus="caseStatus"
      :preFilledMessage="preFilledMessage"
      :prefillData="prefillData"
      @submit="refreshTaxCase"
      @saveDraft="refreshTaxCase"
    />

    <!-- REVISION HISTORY PANEL -->
    <div class="px-4 py-6">
      <RevisionHistoryPanel 
        :case-id="caseId"
        :stage-id="3"
        :tax-case="caseData"
        :revisions="revisions"
        :current-user="currentUser"
        :current-documents="currentDocuments"
        :available-fields="availableFields"
        :fields="fields"
        @revision-requested="refreshTaxCase"
        @refresh="refreshTaxCase"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useRevisionAPI } from '@/composables/useRevisionAPI'
import StageForm from '../components/StageForm.vue'
import RevisionHistoryPanel from '../components/RevisionHistoryPanel.vue'

const route = useRoute()
const caseId = ref(parseInt(route.params.caseId))
const { listRevisions } = useRevisionAPI()

const caseNumber = ref('TAX-2026-001')
const preFilledMessage = ref('Loading...')
const isLoading = ref(true)
const caseStatus = ref(null)
const caseData = ref({})
const revisions = ref([])
const currentUser = ref(null)
const currentDocuments = ref([])

// Available fields untuk revisi
const availableFields = [
  'sphp_number',
  'sphp_issue_date',
  'sphp_receipt_date',
  'royalty_finding',
  'service_finding',
  'other_finding',
  'other_finding_notes',
  'supporting_docs'
]

const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'sphp_number',
    label: 'Nomor SPHP (SPHP Number)',
    required: true,
    readonly: false
  },
  {
    id: 2,
    type: 'date',
    key: 'sphp_issue_date',
    label: 'Tanggal Diterbitkan (Issue Date)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'date',
    key: 'sphp_receipt_date',
    label: 'Tanggal Diterima (Receipt Date)',
    required: true,
    readonly: false
  },
  {
    id: 4,
    type: 'number',
    key: 'royalty_finding',
    label: 'Royalty Finding Amount',
    required: false,
    readonly: false
  },
  {
    id: 5,
    type: 'number',
    key: 'service_finding',
    label: 'Service Finding Amount',
    required: false,
    readonly: false
  },
  {
    id: 6,
    type: 'number',
    key: 'other_finding',
    label: 'Other Finding Amount',
    required: false,
    readonly: false
  },
  {
    id: 7,
    type: 'textarea',
    key: 'other_finding_notes',
    label: 'Notes for Other Findings',
    required: false,
    readonly: false
  }
])

const prefillData = ref({
  sphp_number: '',
  sphp_issue_date: null,
  sphp_receipt_date: null,
  royalty_finding: 0,
  service_finding: 0,
  other_finding: 0,
  other_finding_notes: '',
  workflowHistories: []
})

const formatDateForInput = (date) => {
  if (!date) return null
  const d = new Date(date)
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${d.getFullYear()}-${month}-${day}`
}

onMounted(async () => {
  try {
    const response = await fetch(`/api/tax-cases/${caseId.value}`)
    if (!response.ok) {
      throw new Error('Failed to load case')
    }

    const caseResponse = await response.json()
    const caseFetchedData = caseResponse.data ? caseResponse.data : caseResponse

    if (!caseFetchedData || !caseFetchedData.id) {
      throw new Error('Case data not found')
    }

    caseData.value = caseFetchedData

    // Pre-fill dengan existing SPHP record jika ada
    const sphpRecord = caseFetchedData.sphp_record
    if (sphpRecord) {
      prefillData.value = {
        sphp_number: sphpRecord.sphp_number || '',
        sphp_issue_date: formatDateForInput(sphpRecord.sphp_issue_date),
        sphp_receipt_date: formatDateForInput(sphpRecord.sphp_receipt_date),
        royalty_finding: sphpRecord.royalty_finding || 0,
        service_finding: sphpRecord.service_finding || 0,
        other_finding: sphpRecord.other_finding || 0,
        other_finding_notes: sphpRecord.other_finding_notes || '',
        workflowHistories: caseFetchedData.workflow_histories || []
      }
    } else {
      prefillData.value.workflowHistories = caseFetchedData.workflow_histories || []
    }

    caseNumber.value = caseFetchedData.case_number || 'N/A'
    caseStatus.value = caseFetchedData.case_status_id
    preFilledMessage.value = `✅ Case: ${caseFetchedData.case_number}`

    await loadRevisions()
    await loadDocuments()

    // Load current user
    try {
      const userRes = await fetch('/api/user')
      if (userRes.ok) {
        const userData = await userRes.json()
        currentUser.value = userData.data || userData
      }
    } catch (err) {
      console.error('Failed to load user:', err)
    }
  } catch (error) {
    preFilledMessage.value = '❌ Error loading case data'
    console.error('Error:', error)
  } finally {
    isLoading.value = false
  }
})

const loadRevisions = async () => {
  try {
    const revisionsData = await listRevisions('tax-cases', caseId.value)
    revisions.value = revisionsData
  } catch (error) {
    console.error('Failed to load revisions:', error)
  }
}

const loadDocuments = async () => {
  try {
    const docsRes = await fetch(`/api/tax-cases/${caseId.value}/documents?stage_code=3`)
    if (docsRes.ok) {
      const docsData = await docsRes.json()
      let allDocs = docsData.data || docsData
      allDocs = Array.isArray(allDocs) ? allDocs : []
      
      const stageDocs = allDocs.filter(doc => doc.stage_code === '3' || doc.stage_code === 3)
      currentDocuments.value = stageDocs
    }
  } catch (error) {
    console.error('Failed to load documents:', error)
  }
}

const refreshTaxCase = async () => {
  try {
    const response = await fetch(`/api/tax-cases/${caseId.value}`)
    if (response.ok) {
      const caseResponse = await response.json()
      const caseFetchedData = caseResponse.data ? caseResponse.data : caseResponse
      caseData.value = caseFetchedData
      
      const sphpRecord = caseFetchedData.sphp_record
      if (sphpRecord) {
        prefillData.value = {
          sphp_number: sphpRecord.sphp_number || '',
          sphp_issue_date: formatDateForInput(sphpRecord.sphp_issue_date),
          sphp_receipt_date: formatDateForInput(sphpRecord.sphp_receipt_date),
          royalty_finding: sphpRecord.royalty_finding || 0,
          service_finding: sphpRecord.service_finding || 0,
          other_finding: sphpRecord.other_finding || 0,
          other_finding_notes: sphpRecord.other_finding_notes || '',
          workflowHistories: caseFetchedData.workflow_histories || []
        }
      }
      
      await loadRevisions()
      await loadDocuments()
    }
  } catch (error) {
    console.error('Failed to refresh case:', error)
  }
}
</script>
```

### B5.2 Add Route

**File:** `resources/js/router/index.js`

```javascript
import SphpFilingForm from '@/pages/SphpFilingForm.vue'

// In routes array:
{
  path: '/case/:caseId/stage/3',
  name: 'sphp-filing',
  component: SphpFilingForm,
  meta: { requiresAuth: true }
}
```

---

## B6. INTEGRATION CHECKLIST FOR STAGE 3

```
✓ Backend Setup:
  [ ] Create SphpRecord model
  [ ] Create migration (check existing first)
  [ ] Add sphpRecord relationship to TaxCase
  [ ] Run: php artisan migrate
  [ ] Test: Check database has sphp_records table

✓ API Integration:
  [ ] Update TaxCaseController show() to include sphpRecord
  [ ] Test: GET /api/tax-cases/{id} returns sphp_record
  [ ] Create/test workflow endpoint for stage 3

✓ Revision System:
  [ ] Update detectStageFromFields() in RevisionService
  [ ] Add stage 3 condition in approveRevision()
  [ ] Test: Request revision for SPHP fields
  [ ] Test: Approve revision updates sphp_records table

✓ Frontend:
  [ ] Create SphpFilingForm.vue
  [ ] Add route in router
  [ ] Test: Form loads and displays fields
  [ ] Test: Can submit/save draft
  [ ] Test: Can request/approve revisions
  [ ] Test: Form auto-refreshes after changes

✓ Styling:
  [ ] Verify form uses consistent styling
  [ ] Check revision history displays properly
  [ ] Verify modal styling matches other stages

✓ Testing:
  [ ] CRUD on SPHP data works
  [ ] Revision workflow complete
  [ ] Field labels display correctly
  [ ] Auto-refresh works
  [ ] Document upload for stage 3 works
```

---

# PART C: IMPLEMENTATION CHECKLIST

Use this checklist for EACH new stage implementation (stages 4-16).

## C1. PRE-IMPLEMENTATION

```
[ ] Read PORTAX_FLOW.md for stage details
[ ] Check existing database schema (sphp_records, skp_records, etc.)
[ ] Identify stage-specific fields from PORTAX_FLOW
[ ] Determine if migration needed or modification
[ ] List all field types needed
```

## C2. BACKEND IMPLEMENTATION

### Step 1: Database & Model
```
[ ] Check existing table: PRAGMA table_info({table_name})
[ ] If exists, skip migration. If not:
    [ ] Create migration file
    [ ] Define all stage fields
    [ ] Run: php artisan migrate
[ ] Create/Update Model file
[ ] Add relationship to TaxCase model
```

### Step 2: API Integration
```
[ ] Update TaxCaseController::show()
  [ ] Add stage model to loaded relationships
  [ ] Verify API returns stage model data
  [ ] Test: GET /api/tax-cases/{id}
```

### Step 3: Revision System
```
[ ] Update RevisionService::detectStageFromFields()
  [ ] Add stage fields array
  [ ] Add condition check
[ ] Update RevisionService::approveRevision()
  [ ] Add stage condition to update target
  [ ] Add logging
[ ] Test revision workflow
```

## C3. FRONTEND IMPLEMENTATION

### Step 1: Create Form Component
```
[ ] Copy Sp2FilingForm.vue as template
[ ] Rename to [StageName]FilingForm.vue
[ ] Update fields array with correct stage fields
[ ] Update prefillData structure
[ ] Update available fields for revision
[ ] Update API calls (stage_code parameter)
```

### Step 2: Add Routing
```
[ ] Add route in router/index.js
[ ] Test route navigation
```

### Step 3: Component Styling
```
[ ] Verify form layout matches design
[ ] Check colors/status indicators
[ ] Verify modal styling
[ ] Test responsive layout
```

## C4. TESTING

```
✓ Functionality:
  [ ] Form loads without errors
  [ ] Can input all fields
  [ ] Can upload documents
  [ ] Can submit/save draft
  [ ] Database updates correctly
  [ ] Form refreshes after submit

✓ Revision System:
  [ ] Can request revision
  [ ] Can see before/after
  [ ] Can approve/reject
  [ ] Database updates to correct table
  [ ] Field labels display properly

✓ Data Validation:
  [ ] Required fields validated
  [ ] Date format validation works
  [ ] Number format validation works
  [ ] Email format validation works

✓ Edge Cases:
  [ ] No data loaded (new stage)
  [ ] Partial data (some fields filled)
  [ ] Concurrent revisions
  [ ] Form disable after submit
```

## C5. DOCUMENTATION

```
[ ] Update this template with stage-specific info
[ ] Add to REVISION_FEATURE.md if needed
[ ] Document any custom business logic
[ ] List any deviations from pattern
```

---

## 🎯 Quick Reference: Stage Field Detection

Add these entries to detectStageFromFields() as stages are implemented:

```php
private function detectStageFromFields(array $fieldNames): ?int {
    $stageFieldMaps = [
        2 => ['sp2_number', 'issue_date', 'receipt_date', 'auditor_name', 'auditor_phone', 'auditor_email'],
        3 => ['sphp_number', 'sphp_issue_date', 'sphp_receipt_date', 'royalty_finding', 'service_finding', 'other_finding'],
        4 => ['skp_number', 'skp_issue_date', 'skp_receipt_date', 'skp_type', 'skp_amount', 'skp_correction_royalty'],
        // Stages 5-16 to be filled as implemented
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

## 📝 Notes

- Always check existing schema before creating migrations
- Use consistent naming: stage_code = stage number (not stage name)
- All monetary amounts use decimal(15,2)
- All date fields nullable (some info comes later)
- Always include stage_code in revision requests
- Field labels in fields array MUST match form display exactly
- Test revisions immediately after implementation
- Document any custom business logic clearly

---

**End of Stage Implementation Template**

*Last Updated: January 17, 2026*
