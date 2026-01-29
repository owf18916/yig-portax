# DETAIL TEKNIS - SETIAP ASPEK YANG DITANYAKAN

---

## 1. STACK TECH DETAIL

### Backend Stack

```php
// composer.json
{
  "name": "laravel/laravel",
  "require": {
    "php": "^8.2",
    "laravel/framework": "^12.0",
    "maatwebsite/excel": "^3.1",
    "laravel/tinker": "^2.10.1"
  }
}
```

**Why Laravel 12?**
- Latest LTS-like stable version
- Modern PHP features (8.2+)
- Built-in API support
- Excellent ecosystem

**Additional Libraries:**
- `maatwebsite/excel` - Export/import Excel (for tax documents)

---

### Frontend Stack

```json
{
  "dependencies": {
    "vue": "^3.5.26",           // Latest Vue 3
    "vue-router": "^4.6.4",     // Client-side routing
    "pinia": "^3.0.4",          // State management
    "axios": "^1.13.2",         // HTTP client
    "chart.js": "^4.5.1"        // Analytics charts
  },
  "devDependencies": {
    "@vitejs/plugin-vue": "^6.0.3",
    "@tailwindcss/vite": "^4.0.0",
    "laravel-vite-plugin": "^2.0.0",
    "tailwindcss": "^4.0.0",
    "vite": "^7.0.7"
  }
}
```

**Key Decisions:**
- ✅ Vue 3 (NOT Vue 2) - Composition API, better performance
- ✅ Vue Router 4 - Official router for Vue 3
- ✅ Pinia 3 - Modern store (NOT Vuex - deprecated)
- ✅ Axios - Industry standard HTTP client
- ✅ Chart.js - Lightweight charting library
- ✅ Vite - Next-gen build tool (faster than Webpack)

---

## 2. PROJECT STRUCTURE DETAIL

### App Structure Breakdown

```
app/Models/
├── User.php                    # Authentication user
├── Role.php                    # Role model
├── Entity.php                  # Organization unit
├── TaxCase.php                 # Main tax case
│
├── SkpRecord.php              # Stage 4 - SKP Filing
├── Sp2Record.php              # Stage 2 - SP2 Filing  
├── SphpRecord.php             # Stage 3 - SPHP Filing
├── SpuhRecord.php             # Stage 6 - SPUH Record
│
├── ObjectionSubmission.php     # Stage 5 - Objection
├── ObjectionDecision.php       # Stage 7 - Objection Decision
│
├── AppealSubmission.php        # Stage 8 - Appeal Submission
├── AppealExplanationRequest.php # Stage 9 - Appeal Explanation
├── AppealDecision.php          # Stage 10 - Appeal Decision
│
├── SupremeCourtSubmission.php  # Stage 11 - SC Submission
├── SupremeCourtDecision.php    # Stage 12 - SC Decision
│
├── KianSubmission.php          # Stage 16 - KIAN submission
│
├── Revision.php                # Revision request workflow
├── Document.php                # Attached documents
├── AuditLog.php               # System audit trail
├── StatusHistory.php           # Case status changes
├── WorkflowHistory.php         # Workflow progression
│
├── Announcement.php            # System announcements
├── FiscalYear.php              # Tax fiscal years
├── Period.php                  # Tax periods
├── Currency.php                # Exchange rates
├── CaseStatus.php              # Status options
├── BankTransferRequest.php     # Bank transfer
└── RefundProcess.php           # Refund handling
```

**Model Relationships:**
```
User
  ├─ hasOne Role
  ├─ hasOne Entity
  └─ hasMany TaxCase

TaxCase
  ├─ belongsTo User (creator)
  ├─ belongsTo Entity
  ├─ hasMany WorkflowHistory
  ├─ hasMany StatusHistory
  ├─ hasMany AuditLog
  ├─ hasMany Document
  ├─ hasMany Revision
  ├─ hasOne Sp2Record (or null)
  ├─ hasOne SphpRecord (or null)
  ├─ hasOne SkpRecord (or null)
  ├─ ... (all stage records as hasOne relationships)
  └─ belongsTo CaseStatus

Revision
  ├─ belongsTo TaxCase
  ├─ belongsTo User (requester)
  ├─ hasMany Comment
  └─ hasMany AttachmentRev
```

---

### Routes Structure

```php
// routes/api.php - 712 lines

Authentication Routes:
  POST   /api/login              // {email, password}
  POST   /api/logout             // require auth
  GET    /api/me                 // require auth

Tax Case Management:
  GET    /api/tax-cases          // List with filters
  POST   /api/tax-cases          // Create new
  GET    /api/tax-cases/{id}     // Get detail
  PUT    /api/tax-cases/{id}     // Update case
  GET    /api/tax-cases/{id}/audit-log
  GET    /api/tax-cases/{id}/status-history
  GET    /api/tax-cases/export   // Excel export

Stage Records (Sp2, SPHP, SKP, etc.):
  GET    /api/sp2-records
  POST   /api/sp2-records
  PUT    /api/sp2-records/{id}
  GET    /api/sphp-records
  POST   /api/sphp-records
  ... (similar for each stage)

Supporting Endpoints:
  GET    /api/entities           // List organizations
  GET    /api/fiscal-years       // Tax years
  GET    /api/currencies         // Exchange rates
  GET    /api/exchange-rates
  POST   /api/exchange-rates
  GET    /api/announcements      // System notices
  POST   /api/announcements      // Create (admin only)

Revision Workflow:
  POST   /api/revisions
  GET    /api/revisions/{id}
  POST   /api/revisions/{id}/approve
  POST   /api/revisions/{id}/reject
  GET    /api/revisions/{id}/history

Dashboard & Analytics:
  GET    /api/dashboard/analytics // Charts data
  GET    /api/dashboard/statistics
```

---

## 3. AUTHENTICATION SYSTEM

### Session-Based Implementation

```javascript
// resources/js/pages/Login.vue

const handleLogin = async () => {
  const response = await fetch('/api/login', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': getCsrfToken()  // ← Session CSRF protection
    },
    body: JSON.stringify({
      email: email.value,
      password: password.value
    })
  })

  if (response.ok) {
    // Session cookie automatically set by browser
    // No need to store token in localStorage
    router.push('/')
  }
}
```

### Backend Authentication

```php
// app/Http/Controllers/Api/AuthController.php

public function login(Request $request): JsonResponse
{
    $validated = $request->validate([
        'email' => 'required|email',
        'password' => 'required|string',
    ]);

    // Find user
    $user = User::where('email', $validated['email'])->first();

    // Verify password
    if (!$user || !Hash::check($validated['password'], $user->password)) {
        return $this->error('Invalid email or password', 401);
    }

    // Check if active
    if (!$user->is_active) {
        return $this->error('User account is not active', 403);
    }

    // Create session (THIS is the key difference from API tokens)
    Auth::login($user);

    // Return user data
    $user = User::with('role', 'entity')->find($user->id);
    return $this->success(['user' => $user]);
}

public function logout(Request $request): JsonResponse
{
    Auth::logout();                      // Destroy session
    $request->session()->invalidate();   // Invalidate session
    $request->session()->regenerateToken(); // New CSRF token
    return $this->success(null, 'Logout successful');
}
```

### Session vs Sanctum Comparison

```
CURRENT (Session-Based):
✅ Used: Auth::login($user)
✅ Session cookie stored automatically
✅ CSRF protection via X-CSRF-TOKEN header
✅ Perfect for same-origin SPA
❌ Not suitable for mobile apps
❌ Not suitable for 3rd party integrations

IF IT WERE SANCTUM (Token-Based):
❌ Would need: Auth::guard('sanctum')->user()
❌ Would require: 'Authorization: Bearer {token}' header
❌ Token stored in localStorage (security risk)
✅ Better for mobile apps
✅ Better for 3rd party API access
```

**Conclusion:** ✅ Session-based is CORRECT for this Vue SPA

---

## 4. EXISTING CODE ANALYSIS

### API Controllers Count & Location

```
app/Http/Controllers/Api/

Total: 24 Controllers

Core:
1.  ApiController.php           (Base class with response methods)
2.  AuthController.php          (Login, logout, me)
3.  TaxCaseController.php       (Core case management)

Stage Controllers (8):
4.  SkpRecordController.php     (Stage 4)
5.  Sp2RecordController.php     (Stage 2)
6.  SphpRecordController.php    (Stage 3)
7.  SpuhRecordController.php    (Stage 6)
8.  ObjectionSubmissionController.php   (Stage 5)
9.  ObjectionDecisionController.php     (Stage 7)
10. AppealSubmissionController.php      (Stage 8)
11. AppealExplanationRequestController.php (Stage 9)
12. AppealDecisionController.php        (Stage 10)
13. SupremeCourtSubmissionController.php (Stage 11)
14. SupremeCourtDecisionController.php   (Stage 12)
15. KianSubmissionController.php        (Stage 16)

Feature Controllers:
16. RevisionController.php      (Revision workflow)
17. EntityController.php        (Organization management)
18. DashboardAnalyticsController.php (Analytics)
19. AnnouncementController.php  (Announcements)

Supporting Controllers:
20. ExchangeRateController.php  (Currency rates)
21. FiscalYearController.php    (Tax years)
22. RefundProcessController.php (Refunds)
23. TaxCaseExportController.php (Excel export)
24. DocumentController.php      (Document management)
```

### Controller Pattern Example

```php
// app/Http/Controllers/Api/TaxCaseController.php

class TaxCaseController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        // Authorization check
        $this->authorize('viewAny', TaxCase::class);

        // Entity filtering for non-admins
        $query = TaxCase::query();
        if (!auth()->user()->isAdmin()) {
            $query->where('entity_id', auth()->user()->entity_id);
        }

        // Get with relationships
        $cases = $query->with('entity', 'status', 'user')
            ->paginate(15);

        return $this->success($cases);
    }

    public function store(Request $request): JsonResponse
    {
        // Authorization
        $this->authorize('create', TaxCase::class);

        // Validation
        $validated = $request->validate([
            'case_number' => 'required|unique:tax_cases',
            'case_type' => 'required|in:CIT,VAT',
            'description' => 'required|string',
        ]);

        // Create
        $taxCase = TaxCase::create(array_merge(
            $validated,
            ['user_id' => auth()->id()]
        ));

        return $this->success($taxCase->load('entity', 'status'), 'Case created', 201);
    }

    public function show(int $id): JsonResponse
    {
        $taxCase = TaxCase::findOrFail($id);

        $this->authorize('view', $taxCase);

        // Load all relationships
        return $this->success($taxCase->load([
            'entity', 'status', 'user', 'workflowHistory',
            'sp2Record', 'sphpRecord', 'skpRecord', // ... more
        ]));
    }
}
```

### Base ApiController Methods

```php
// app/Http/Controllers/Api/ApiController.php

class ApiController extends Controller
{
    // Success response
    protected function success($data, string $message = '', int $code = 200)
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
        ], $code);
    }

    // Error response
    protected function error(string $message, int $code = 400)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $code);
    }
}
```

---

## 5. FORM HANDLING DETAIL

### Manual Validation Example

```vue
<!-- resources/js/pages/Sp2FilingForm.vue -->

<template>
  <div class="form-container">
    <h1>SP2 Filing Form</h1>

    <!-- Error Display -->
    <div v-if="errors" class="bg-red-50 border border-red-200 rounded p-4 mb-4">
      <ul>
        <li v-for="(message, field) in errors" :key="field">
          <strong>{{ field }}:</strong> {{ message }}
        </li>
      </ul>
    </div>

    <!-- Form -->
    <form @submit.prevent="submitForm" class="space-y-4">
      <!-- Text Input -->
      <div>
        <label class="block text-sm font-medium">SP2 Number</label>
        <input
          v-model="form.sp2_number"
          type="text"
          required
          class="w-full px-3 py-2 border rounded"
          @blur="validateField('sp2_number')"
        />
        <p v-if="fieldErrors.sp2_number" class="text-red-500 text-sm">
          {{ fieldErrors.sp2_number }}
        </p>
      </div>

      <!-- Number Input -->
      <div>
        <label class="block text-sm font-medium">Assessment Value</label>
        <input
          v-model.number="form.assessment_value"
          type="number"
          step="0.01"
          required
          class="w-full px-3 py-2 border rounded"
        />
      </div>

      <!-- Date Input -->
      <div>
        <label class="block text-sm font-medium">SP2 Date</label>
        <input
          v-model="form.sp2_date"
          type="date"
          required
          class="w-full px-3 py-2 border rounded"
        />
      </div>

      <!-- Select -->
      <div>
        <label class="block text-sm font-medium">Status</label>
        <select
          v-model="form.status"
          class="w-full px-3 py-2 border rounded"
        >
          <option value="DRAFT">Draft</option>
          <option value="SUBMITTED">Submitted</option>
        </select>
      </div>

      <!-- Submit -->
      <button
        type="submit"
        :disabled="loading"
        class="px-4 py-2 bg-blue-600 text-white rounded"
      >
        {{ loading ? 'Saving...' : 'Save' }}
      </button>
    </form>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'

const router = useRouter()
const route = useRoute()

const form = ref({
  sp2_number: '',
  assessment_value: null,
  sp2_date: null,
  status: 'DRAFT',
})

const errors = ref(null)
const fieldErrors = ref({})
const loading = ref(false)

// Basic client-side validation
const validateField = (field) => {
  const value = form.value[field]

  switch(field) {
    case 'sp2_number':
      if (!value) {
        fieldErrors.value[field] = 'SP2 number is required'
      } else if (!/^\d{8}$/.test(value)) {
        fieldErrors.value[field] = 'Invalid SP2 format'
      } else {
        delete fieldErrors.value[field]
      }
      break;
    case 'assessment_value':
      if (value && value < 0) {
        fieldErrors.value[field] = 'Cannot be negative'
      } else {
        delete fieldErrors.value[field]
      }
      break;
  }
}

const submitForm = async () => {
  errors.value = null
  loading.value = true

  try {
    // POST to API
    const response = await fetch(`/api/sp2-records`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': getCsrfToken(),
      },
      body: JSON.stringify({
        tax_case_id: route.params.id,
        ...form.value,
      })
    })

    const data = await response.json()

    if (!response.ok) {
      // Server validation errors
      if (data.errors) {
        errors.value = data.errors
      } else {
        errors.value = { general: data.message }
      }
      return
    }

    // Success
    await router.push(`/tax-cases/${route.params.id}`)
  } catch (error) {
    errors.value = { general: 'Network error' }
  } finally {
    loading.value = false
  }
}

const getCsrfToken = () => {
  return document.querySelector('meta[name="csrf-token"]')?.content || ''
}
</script>
```

### Server-Side Validation

```php
// In Controller
public function store(Request $request): JsonResponse
{
    // Server-side validation (authoritative!)
    $validated = $request->validate([
        'sp2_number' => [
            'required',
            'string',
            'regex:/^\d{8}$/',
            Rule::unique('sp2_records')->where('tax_case_id', $request->tax_case_id),
        ],
        'assessment_value' => 'required|numeric|min:0',
        'sp2_date' => 'required|date|before_or_equal:today',
        'status' => 'required|in:DRAFT,SUBMITTED',
    ]);

    // If validation fails, Laravel auto-returns 422 with errors
    // Frontend receives:
    // {
    //   "message": "The given data was invalid.",
    //   "errors": {
    //     "sp2_number": ["The sp2 number has already been taken."],
    //     "assessment_value": ["The assessment value must be at least 0."]
    //   }
    // }
}
```

### Document Upload (Current Implementation)

```javascript
// Assumed from Document model and DocumentController

// File upload endpoint (likely in api.php)
POST /api/tax-cases/{id}/documents
  body: FormData {
    file: File,
    document_type: 'INVOICE' | 'RECEIPT' | etc
  }

// Response:
{
  "success": true,
  "data": {
    "id": 1,
    "filename": "invoice_2024.pdf",
    "url": "/storage/documents/invoice_2024.pdf",
    "size": 102400,
    "mime_type": "application/pdf"
  }
}
```

**Note:** No signed URLs yet (could be enhancement)

---

## 6. UI COMPONENTS DETAIL

### Component Architecture

```
resources/js/components/

Base Components:
├── Alert.vue                    # Alert messages
├── Button.vue                   # Reusable button
├── Card.vue                     # Card wrapper
├── Toast.vue                    # Toast notifications
├── LoadingSpinner.vue           # Loading indicator
└── FormField.vue                # Form field wrapper

Form Components:
├── StageForm.vue                # Base for stage forms
└── forms/                       # (empty - forms in pages/)

Modal Components:
├── NextActionModal.vue          # Set next action
├── RequestRevisionModal.vue      # Request revision
├── RequestRevisionModalV2.vue    # V2 of above
├── SubmitRevisedDataModal.vue    # Submit revised
├── RevisionApprovalModalV2.vue   # Approve revision
├── DecisionModal.vue            # Decision dialog
├── ConfirmationDialog.vue        # Confirmation
├── AnnouncementModal.vue         # Show announcements
└── ExchangeRateModal.vue         # Exchange rate

Data Display:
├── RevisionHistoryPanel.vue     # Revision history
├── BeforeAfterComparison.vue    # Diff viewer
├── ExchangeRateTable.vue        # Exchange rates table
└── WorkflowStageDrawer.vue      # Stage sidebar

Chart Components:
└── charts/
    ├── PieChart.vue
    ├── BarChart.vue
    └── LineChart.vue             (Chart.js wrappers)
```

### Component Example - FormField

```vue
<!-- resources/js/components/FormField.vue -->

<template>
  <div class="mb-4">
    <!-- Label -->
    <label v-if="label" :for="name" class="block text-sm font-medium text-gray-700 mb-1">
      {{ label }}
      <span v-if="required" class="text-red-500">*</span>
    </label>

    <!-- Input variants -->
    <div v-if="type === 'textarea'">
      <textarea
        :id="name"
        v-model="localValue"
        :disabled="disabled"
        :required="required"
        class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
        :rows="rows"
        @blur="$emit('blur')"
      />
    </div>

    <select
      v-else-if="type === 'select'"
      :id="name"
      v-model="localValue"
      :disabled="disabled"
      :required="required"
      class="w-full px-3 py-2 border border-gray-300 rounded"
      @blur="$emit('blur')"
    >
      <option value="">Select...</option>
      <option v-for="option in options" :key="option.value" :value="option.value">
        {{ option.label }}
      </option>
    </select>

    <input
      v-else
      :id="name"
      :type="type"
      v-model="localValue"
      :disabled="disabled"
      :required="required"
      :min="min"
      :max="max"
      :step="step"
      class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
      @blur="$emit('blur')"
    />

    <!-- Error message -->
    <p v-if="error" class="mt-1 text-sm text-red-500">{{ error }}</p>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  modelValue: [String, Number, Boolean],
  name: { type: String, required: true },
  label: String,
  type: { type: String, default: 'text' },
  required: Boolean,
  disabled: Boolean,
  error: String,
  options: Array,
  rows: { type: Number, default: 3 },
  min: [String, Number],
  max: [String, Number],
  step: [String, Number],
})

const emit = defineEmits(['update:modelValue', 'blur'])

const localValue = ref(props.modelValue)

watch(() => props.modelValue, (newVal) => {
  localValue.value = newVal
})

watch(localValue, (newVal) => {
  emit('update:modelValue', newVal)
})
</script>

<style scoped>
input:invalid {
  @apply border-red-300;
}

input:valid {
  @apply border-green-300;
}
</style>
```

### Component Usage Pattern

```vue
<!-- In form pages -->
<template>
  <div class="max-w-2xl mx-auto p-6">
    <h1>SP2 Filing</h1>

    <form @submit.prevent="submitForm" class="space-y-4">
      <!-- Using FormField component -->
      <FormField
        v-model="form.sp2_number"
        name="sp2_number"
        label="SP2 Number"
        type="text"
        required
        :error="errors.sp2_number"
        @blur="validateField('sp2_number')"
      />

      <FormField
        v-model="form.assessment_value"
        name="assessment_value"
        label="Assessment Value"
        type="number"
        step="0.01"
        required
        :error="errors.assessment_value"
      />

      <FormField
        v-model="form.status"
        name="status"
        label="Status"
        type="select"
        required
        :options="statusOptions"
      />

      <!-- Button component -->
      <Button
        type="submit"
        :loading="loading"
        size="lg"
      >
        Save SP2 Record
      </Button>
    </form>
  </div>
</template>

<script setup>
import FormField from '@/components/FormField.vue'
import Button from '@/components/Button.vue'
// ... rest of script
</script>
```

---

## 7. STATE MANAGEMENT (Pinia)

### Store Structure

```javascript
// resources/js/stores/taxCaseStore.js

import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useTaxCaseApi } from '../composables/useTaxCaseApi'

export const useTaxCaseStore = defineStore('taxCase', () => {
  const api = useTaxCaseApi()

  // ========== STATE ==========
  const currentCase = ref(null)
  const cases = ref([])
  const loading = ref(false)
  const error = ref(null)
  const success = ref(null)

  // Workflow data
  const sp2Data = ref(null)
  const sphpData = ref(null)
  const skpData = ref(null)
  const objectionSubmissionData = ref(null)
  // ... more stage data

  // Supporting data
  const entities = ref([])
  const fiscalYears = ref([])
  const currencies = ref([])
  const workflowHistory = ref([])
  const documents = ref([])

  // ========== COMPUTED ==========
  const currentStage = computed(() => currentCase.value?.current_stage || 0)
  const caseStatus = computed(() => currentCase.value?.status || 'DRAFT')
  const isLoading = computed(() => loading.value)
  const hasError = computed(() => error.value !== null)

  // ========== ACTIONS (async) ==========

  /**
   * Fetch all tax cases
   */
  const fetchTaxCases = async (page = 1, filters = {}) => {
    loading.value = true
    try {
      const response = await api.getTaxCases(page, filters)
      cases.value = response.data
      error.value = null
    } catch (err) {
      error.value = err.message
    } finally {
      loading.value = false
    }
  }

  /**
   * Fetch single tax case with all data
   */
  const fetchTaxCaseDetail = async (id) => {
    loading.value = true
    try {
      const response = await api.getTaxCase(id)
      currentCase.value = response.data
      error.value = null
    } catch (err) {
      error.value = err.message
    } finally {
      loading.value = false
    }
  }

  /**
   * Create new tax case
   */
  const createTaxCase = async (data) => {
    loading.value = true
    try {
      const response = await api.createTaxCase(data)
      cases.value.push(response.data)
      currentCase.value = response.data
      success.value = 'Tax case created successfully'
      return response.data
    } catch (err) {
      error.value = err.message
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Update SP2 record
   */
  const updateSp2Record = async (taxCaseId, sp2Data) => {
    loading.value = true
    try {
      const response = await api.updateSp2Record(taxCaseId, sp2Data)
      sp2Data.value = response.data
      success.value = 'SP2 record saved'
      return response.data
    } catch (err) {
      error.value = err.message
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Request revision
   */
  const requestRevision = async (taxCaseId, revisionData) => {
    loading.value = true
    try {
      const response = await api.requestRevision(taxCaseId, revisionData)
      success.value = 'Revision requested'
      return response.data
    } catch (err) {
      error.value = err.message
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Fetch supporting data (entities, fiscal years, etc.)
   */
  const fetchSupportingData = async () => {
    loading.value = true
    try {
      const [entitiesRes, yearsRes, currenciesRes] = await Promise.all([
        api.getEntities(),
        api.getFiscalYears(),
        api.getCurrencies(),
      ])
      entities.value = entitiesRes.data
      fiscalYears.value = yearsRes.data
      currencies.value = currenciesRes.data
      error.value = null
    } catch (err) {
      error.value = err.message
    } finally {
      loading.value = false
    }
  }

  /**
   * Clear notifications
   */
  const clearNotifications = () => {
    error.value = null
    success.value = null
  }

  // ========== RETURN (expose to components) ==========
  return {
    // State
    currentCase,
    cases,
    loading,
    error,
    success,
    sp2Data,
    sphpData,
    skpData,
    entities,
    fiscalYears,

    // Computed
    currentStage,
    caseStatus,
    isLoading,
    hasError,

    // Actions
    fetchTaxCases,
    fetchTaxCaseDetail,
    createTaxCase,
    updateSp2Record,
    requestRevision,
    fetchSupportingData,
    clearNotifications,
  }
})
```

### Store Usage in Components

```vue
<template>
  <div v-if="!store.loading" class="space-y-4">
    <!-- Error -->
    <Alert v-if="store.error" type="error">
      {{ store.error }}
    </Alert>

    <!-- Success -->
    <Alert v-if="store.success" type="success">
      {{ store.success }}
    </Alert>

    <!-- List -->
    <div v-if="store.cases.length">
      <div v-for="taxCase in store.cases" :key="taxCase.id" class="p-4 border rounded">
        <h3>{{ taxCase.case_number }}</h3>
        <p>Status: {{ store.caseStatus }}</p>
      </div>
    </div>
  </div>

  <LoadingSpinner v-else />
</template>

<script setup>
import { onMounted } from 'vue'
import { useTaxCaseStore } from '@/stores/taxCaseStore'

const store = useTaxCaseStore()

onMounted(async () => {
  await store.fetchTaxCases()
  await store.fetchSupportingData()
})
</script>
```

---

## 8. API COMMUNICATION PATTERN

### Axios Setup

```javascript
// resources/js/bootstrap.js

import axios from 'axios'
window.axios = axios

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'

// Set CSRF token
const token = document.querySelector('meta[name="csrf-token"]')?.content
if (token) {
  window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token
}

// Response interceptor
window.axios.interceptors.response.use(
  response => response,
  error => {
    if (error.response?.status === 401) {
      // Redirect to login
      window.location.href = '/login'
    }
    return Promise.reject(error)
  }
)
```

### Composable for API

```javascript
// resources/js/composables/useTaxCaseApi.js

import axios from 'axios'

export const useTaxCaseApi = () => {
  const baseURL = '/api'

  return {
    // Tax Cases
    getTaxCases: (page = 1, filters = {}) =>
      axios.get(`${baseURL}/tax-cases`, { params: { page, ...filters } }),

    getTaxCase: (id) =>
      axios.get(`${baseURL}/tax-cases/${id}`),

    createTaxCase: (data) =>
      axios.post(`${baseURL}/tax-cases`, data),

    updateTaxCase: (id, data) =>
      axios.put(`${baseURL}/tax-cases/${id}`, data),

    // SP2 Records
    getSp2Records: (taxCaseId) =>
      axios.get(`${baseURL}/sp2-records?tax_case_id=${taxCaseId}`),

    createSp2Record: (data) =>
      axios.post(`${baseURL}/sp2-records`, data),

    updateSp2Record: (id, data) =>
      axios.put(`${baseURL}/sp2-records/${id}`, data),

    // ... similar for other endpoints

    // Revisions
    requestRevision: (taxCaseId, data) =>
      axios.post(`${baseURL}/tax-cases/${taxCaseId}/revisions`, data),

    approveRevision: (revisionId) =>
      axios.post(`${baseURL}/revisions/${revisionId}/approve`),

    rejectRevision: (revisionId, reason) =>
      axios.post(`${baseURL}/revisions/${revisionId}/reject`, { reason }),

    // Supporting
    getEntities: () =>
      axios.get(`${baseURL}/entities`),

    getFiscalYears: () =>
      axios.get(`${baseURL}/fiscal-years`),

    getCurrencies: () =>
      axios.get(`${baseURL}/currencies`),
  }
}
```

### API Response Pattern

```javascript
// All API responses follow this pattern:
{
  success: true,              // Boolean
  data: { ... },             // Actual data
  message: "Success message"  // Optional message
}

// Error responses:
{
  success: false,
  message: "Error message",
  errors: {                   // For validation errors
    field_name: ["Error message 1", "Error message 2"]
  }
}

// Examples:
// ✅ Login success:
POST /api/login
→ {
  success: true,
  data: {
    user: {
      id: 1,
      name: "John Doe",
      email: "john@example.com",
      role: { id: 1, name: "STAFF" },
      entity: { id: 1, name: "PASI" }
    },
    message: "Login successful"
  }
}

// ✅ Tax case list:
GET /api/tax-cases
→ {
  success: true,
  data: {
    data: [ { id: 1, case_number: "2024-001", ... } ],
    current_page: 1,
    total: 50,
    per_page: 15
  }
}

// ❌ Validation error:
POST /api/sp2-records
→ {
  success: false,
  message: "The given data was invalid.",
  errors: {
    sp2_number: ["The sp2 number has already been taken."],
    assessment_value: ["The assessment value must be at least 0."]
  }
}

// ❌ Unauthorized:
GET /api/tax-cases
→ {
  success: false,
  message: "Unauthenticated"
}
// Status: 401 → Redirects to /login
```

---

## SUMMARY TABLE

| Category | Current | Recommended |
|----------|---------|-------------|
| **Backend Framework** | Laravel 12 | ✅ Keep |
| **Frontend Framework** | Vue 3 | ✅ Keep |
| **State Management** | Pinia | ✅ Keep |
| **HTTP Client** | Axios | ✅ Keep |
| **Authentication** | Session-based | ✅ Keep (correct for SPA) |
| **Form Validation** | Manual + Server | ✅ Keep or add VeeValidate |
| **Component Library** | Custom + TailwindCSS | ✅ Keep (lightweight) |
| **API Resources** | None | ❓ Consider adding |
| **Build Tool** | Vite | ✅ Keep |
| **Database** | MySQL 8 | ✅ Keep |

---

**Generated:** January 27, 2026  
**Based on:** Codebase analysis + best practices
