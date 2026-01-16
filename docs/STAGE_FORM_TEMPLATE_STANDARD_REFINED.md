# Stage Form Template Standard - REFINED

**Version:** 2.0 | **Date:** January 16, 2026  
**Framework:** Vue.js 3 + Laravel 11  
**Component:** `StageForm.vue` and derivatives

---

## QUICK START

This document has **TWO main sections**:

1. **GENERIC** (BASE TEMPLATE) - Copy as-is for all stages
2. **CUSTOMIZE** (STAGE-SPECIFIC) - Modify based on your stage design

---

# SECTION 1: GENERIC BASE TEMPLATE (USE FOR ALL STAGES)

## 1. Layout Architecture

### 1.1 CSS Classes (FIXED)
```tailwind
Container:      flex h-screen bg-gray-100
Left (Form):    w-1/2 overflow-y-auto flex flex-col bg-white p-2
Right (PDF):    w-1/2 bg-gray-100 flex flex-col border-l border-gray-300

Internal:       space-y-1 (4px gap)  |  p-2 (8px padding)  |  text-xs (10px) / text-sm (14px)
```

### 1.2 Parent App.vue (FIXED)
```javascript
const getMainClasses = () => {
  if (route.path.includes('/workflow/') || route.path.includes('/spt-filing')) {
    return 'px-2 py-2 h-full'  // Workflow routes: minimal padding
  }
  return 'max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12'  // Others: normal padding
}
```

---

## 2. Component Props & Emits (FIXED)

```javascript
// Props - ALL REQUIRED
const props = defineProps({
  stageName: { type: String, required: true },              // e.g., "Stage 1 - Entity Information"
  stageDescription: { type: String, required: true },       // Brief description
  stageId: { type: Number, required: true },                // 1-12
  caseId: { type: String, required: true },                 // UUID
  caseNumber: { type: String, required: true },             // e.g., "PASI26MarC"
  fields: { type: Array, required: true },                  // [{key, type, label, required, readonly}, ...]
  apiEndpoint: { type: String, required: true },            // /api/tax-cases/{id}/workflow/{stage}
  caseStatus: { type: Number, default: null },              // 1=CREATED, 2+=SUBMITTED/LOCKED
  isLoading: { type: Boolean, default: false },             // Parent loading state
  isReviewMode: { type: Boolean, default: false },          // If true, show read-only version
  prefillData: { type: Object, default: () => ({}) },       // Pre-filled values
  nextStageId: { type: Number, default: null }              // Next stage after submit
})

// Emits - Send to parent after submit/draft
const emit = defineEmits(['submit', 'saveDraft'])
```

---

## 3. Reactive State (FIXED)

```javascript
// Form state
const submitting = ref(false)
const formData = reactive({})
const formErrors = reactive({})
const submissionComplete = ref(false)

// Document state
const uploadedFiles = ref([])
const uploadProgress = ref(0)

// PDF Viewer state
const selectedPdfId = ref(null)
const selectedPdfName = ref('')
const pdfViewerUrl = ref('')

// Confirmation Dialog (UNIFIED PATTERN)
const showConfirmDialog = ref(false)
const confirmDialogTitle = ref('')
const confirmDialogMessage = ref('')
const confirmDialogVariant = ref('default')  // 'default' or 'danger'
const pendingAction = ref(null)  // Identifies which action: 'submit', 'draft', 'delete'

// Computed: Fields should be disabled if case locked OR form submitted
const fieldsDisabled = computed(() => props.caseStatus > 1)
```

---

## 4. Validation (FIXED)

```javascript
// Generic validation for ANY fields
const validateForm = () => {
  Object.keys(formErrors).forEach(key => delete formErrors[key])
  let isValid = true

  props.fields.forEach(field => {
    if (field.readonly) return
    const value = formData[field.key]

    // Required check
    if (field.required && (value === null || value === undefined || value === '')) {
      formErrors[field.key] = `${field.label} is required`
      isValid = false
      return
    }

    // Type-specific validation
    if (field.type === 'number' && value && (isNaN(parseFloat(value)) || parseFloat(value) < 0)) {
      formErrors[field.key] = `${field.label} must be a valid positive number`
      isValid = false
    }
  })

  return isValid
}
```

---

## 5. Document Handling (FIXED)

### 5.1 Upload Flow
```javascript
const handleFileUpload = async (event) => {
  const files = event.target.files
  
  for (let file of files) {
    // Validation
    if (file.type !== 'application/pdf') {
      showToast('Invalid File', 'Only PDF files allowed', 'error', 4000)
      continue
    }
    if (file.size > 10 * 1024 * 1024) {
      showToast('File Too Large', 'Max 10MB per file', 'error', 4000)
      continue
    }
    
    await uploadFile(file)
  }
  
  event.target.value = ''
}

// XMLHttpRequest for progress tracking
const uploadFile = (file) => {
  return new Promise((resolve, reject) => {
    try {
      const formData = new FormData()
      formData.append('file', file)
      formData.append('tax_case_id', props.caseId)
      formData.append('documentable_type', 'App\\Models\\WorkflowHistory')
      formData.append('stage_code', props.stageId)
      formData.append('document_type', 'supporting_document')

      const xhr = new XMLHttpRequest()
      
      // STEP 1: Add listeners FIRST
      xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
          uploadProgress.value = Math.round((e.loaded / e.total) * 100)
        }
      })
      
      xhr.addEventListener('load', () => {
        try {
          if (xhr.status >= 200 && xhr.status < 300) {
            const result = JSON.parse(xhr.responseText)
            uploadedFiles.value.push(result.data)
            showToast('Uploaded', `${file.name} uploaded`, 'success', 2500)
            resolve(result)
          } else {
            reject(new Error('Upload failed'))
          }
        } catch (error) {
          reject(error)
        }
      })
      
      xhr.addEventListener('error', () => reject(new Error('Network error')))
      xhr.addEventListener('abort', () => reject(new Error('Upload cancelled')))
      
      // STEP 2: Then open
      xhr.open('POST', '/api/documents')
      xhr.withCredentials = true
      
      // STEP 3: Set headers
      const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
      if (token) xhr.setRequestHeader('X-CSRF-TOKEN', token)
      
      // STEP 4: Send
      xhr.send(formData)
      uploadProgress.value = 0
      
    } catch (error) {
      reject(error)
    }
  })
}
```

### 5.2 Document Lock Logic (FIXED)
```javascript
// Remove button: ONLY when case in DRAFT (status 1) AND form not submitted
// Lock icon: when case LOCKED (status > 1) OR form submitted

// Document status badges:
// DRAFT: yellow | ACTIVE/ARCHIVED: green
```

---

## 6. Form Submission (FIXED)

```javascript
// Step 1: Initiate (Show confirmation)
const submitForm = async () => {
  if (!validateForm()) return
  
  // CUSTOM: Add stage-specific validations here
  // (See SECTION 2: CUSTOMIZE)
  
  if (uploadedFiles.value.length === 0) {
    showToast('Missing Documents', 'Upload at least one supporting document', 'error', 5000)
    return
  }

  confirmDialogTitle.value = 'Submit Form'
  confirmDialogMessage.value = 'You are about to submit this form. Your data will be saved and you will proceed to the next stage. This action cannot be undone.'
  confirmDialogVariant.value = 'default'
  pendingAction.value = 'submit'
  showConfirmDialog.value = true
}

// Step 2: Execute (On confirmation)
const handleConfirm = async () => {
  if (pendingAction.value === 'submit') {
    await executeSubmitForm()
  } else if (pendingAction.value === 'draft') {
    await executeSaveDraft()
  } else if (pendingAction.value === 'delete') {
    await confirmDelete()
  }
  
  showConfirmDialog.value = false
  pendingAction.value = null
}

// Step 3: Backend call
const executeSubmitForm = async () => {
  submitting.value = true
  
  try {
    const response = await fetch(props.apiEndpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        ...formData,
        status: 'submitted'
      })
    })
    
    if (!response.ok) throw new Error('Submit failed')
    
    submissionComplete.value = true
    showToast('Submitted', `Stage ${props.stageId} submitted successfully`, 'success', 4000)
    
    emit('submit')  // Notify parent to refresh case data
    
  } catch (error) {
    showToast('Error', error.message, 'error', 5000)
  } finally {
    submitting.value = false
  }
}

// Draft save (similar, but status: 'draft')
const executeSaveDraft = async () => {
  submitting.value = true
  
  try {
    const response = await fetch(`${props.apiEndpoint}?draft=true`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        ...formData,
        status: 'draft'
      })
    })
    
    if (!response.ok) throw new Error('Save failed')
    
    showToast('Saved', 'Form saved as draft. You can continue editing anytime.', 'success', 4000)
    emit('saveDraft')
    
  } catch (error) {
    showToast('Error', error.message, 'error', 5000)
  } finally {
    submitting.value = false
  }
}
```

---

## 7. Notifications (FIXED)

```javascript
// Use Toast ONLY (no Alert components)
const showToast = (title, message, type = 'info', duration = 4000) => {
  // Calculate dynamic duration if needed
  if (type === 'success' && duration === 4000) {
    duration = (title + message).length > 50 ? 3000 : 2500
  }
  if (type === 'error' && duration === 5000) {
    duration = (title + message).length > 80 ? 7000 : 5000
  }
  
  // Show toast via ref
  toastRef.value?.addToast(title, message, type, duration)
}

// Toast messages use English titles, bilingual messages
const TOAST_MESSAGES = {
  SUBMIT_SUCCESS: { title: 'Submitted', message: (stageId) => `Stage ${stageId} berhasil disubmit` },
  DRAFT_SUCCESS: { title: 'Saved', message: 'Form disimpan sebagai draft' },
  UPLOAD_ERROR: { title: 'Invalid File', message: 'Only PDF files allowed. Max 10MB.' },
  VALIDATION_ERROR: { title: 'Validation Error', message: 'Please check marked fields' }
}
```

---

## 8. UI Components (FIXED)

### 8.1 Header Section
```vue
<div class="flex items-center justify-between mb-2">
  <div class="flex items-center space-x-1">
    <Button @click="$router.back()" variant="secondary" class="text-xs px-2 py-1">← Back</Button>
    <h1 class="text-lg font-bold text-gray-900">{{ stageName }}</h1>
  </div>
</div>
```

### 8.2 Case Info Display
```vue
<div class="bg-blue-50 p-2 rounded-lg border border-blue-200 mb-2">
  <div class="grid grid-cols-2 gap-2 text-xs">
    <div><p class="text-gray-600">Case #</p><p class="font-bold text-sm">{{ caseNumber }}</p></div>
    <div><p class="text-gray-600">Stage</p><p class="font-bold text-sm">{{ stageId }} / 12</p></div>
  </div>
</div>
```

### 8.3 Form Card
```vue
<div class="bg-white rounded-lg border border-gray-200">
  <div class="px-3 py-2 border-b border-gray-200">
    <h3 class="font-semibold text-base text-gray-900">{{ stageName }} - Information</h3>
    <p class="text-xs text-gray-600 mt-0.5">{{ stageDescription }}</p>
  </div>
  <div class="p-3">
    <form @submit.prevent="submitForm" class="space-y-2">
      <!-- CUSTOMIZE: Add your field components here -->
    </form>
  </div>
</div>
```

### 8.4 Submit Buttons (CRITICAL: Button Types!)
```vue
<div class="flex gap-1 pt-2 border-t">
  <!-- MUST have type="submit" -->
  <Button type="submit" 
    :disabled="submitting || isLoading || caseStatus > 1" 
    class="text-xs px-2 py-1.5">
    {{ submitting ? 'Submitting...' : 'Submit & Continue' }}
  </Button>
  
  <!-- MUST have type="button" (not type="submit"!) -->
  <Button type="button" @click="saveDraft" 
    :disabled="submitting || isLoading || caseStatus > 1" 
    class="text-xs px-2 py-1.5">
    Save as Draft
  </Button>
  
  <!-- MUST have type="button" -->
  <Button type="button" @click="$router.back()" 
    :disabled="isLoading" 
    class="text-xs px-2 py-1.5">
    Cancel
  </Button>
</div>
```

### 8.5 Document Upload Section
```vue
<div class="border-t pt-2 mt-2">
  <h3 class="text-sm font-medium text-gray-900 mb-1">📎 Supporting Documents</h3>
  
  <!-- File input (disabled while uploading or form submitted) -->
  <input type="file" multiple @change="handleFileUpload"
    :disabled="uploadProgress > 0 || submissionComplete || fieldsDisabled"
    class="block w-full text-sm text-gray-500
      file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0
      file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700
      hover:file:bg-blue-100 disabled:opacity-50 disabled:cursor-not-allowed" />
  
  <!-- Progress bar -->
  <div v-if="uploadProgress > 0" class="space-y-1 mt-1">
    <div class="flex justify-between text-xs"><span>Uploading...</span><span class="font-semibold text-blue-600">{{ uploadProgress }}%</span></div>
    <div class="w-full bg-gray-200 rounded-full h-1"><div class="bg-blue-500 h-full rounded-full transition-all" :style="{ width: `${uploadProgress}%` }"></div></div>
  </div>
  
  <!-- Document list -->
  <div v-if="uploadedFiles.length > 0" class="mt-1 space-y-0.5">
    <p class="text-xs font-medium text-gray-700">{{ uploadedFiles.length }} file(s):</p>
    <div v-for="file in uploadedFiles" :key="file.id"
      class="flex items-center justify-between p-1.5 bg-gray-50 rounded border border-gray-200 hover:bg-blue-50 cursor-pointer text-xs transition"
      @click="viewDocument(file.id, file.name)">
      
      <div class="flex items-center space-x-1.5 flex-1 min-w-0">
        <span class="text-base shrink-0">📄</span>
        <div class="flex-1 min-w-0">
          <p class="font-medium text-gray-900 truncate">{{ file.name }}</p>
          <p class="text-xs text-gray-500">{{ file.size }} MB • 
            <span :class="file.status === 'DRAFT' ? 'text-yellow-600' : 'text-green-600'">{{ file.status }}</span>
          </p>
        </div>
      </div>
      
      <!-- Remove button: ONLY when case = CREATED (1) AND form not submitted -->
      <!-- Lock icon: when case > CREATED OR form submitted -->
      <div class="flex items-center space-x-0.5 shrink-0 ml-1">
        <button v-if="caseStatus === 1 && !submissionComplete && !fieldsDisabled"
          @click.stop="removeFile(file.id)"
          class="px-1.5 py-0.5 text-xs bg-red-50 text-red-700 rounded hover:bg-red-100 transition">
          🗑️ Remove
        </button>
        <span v-else class="px-1.5 py-0.5 text-xs bg-gray-100 text-gray-500 rounded">🔒 Locked</span>
      </div>
    </div>
  </div>
</div>
```

### 8.6 PDF Viewer (Right Panel)
```vue
<div class="w-1/2 bg-gray-100 flex flex-col border-l border-gray-300">
  <!-- Header -->
  <div class="flex items-center justify-between p-2 bg-white border-b border-gray-300">
    <div class="flex-1">
      <p class="text-xs font-medium text-gray-700">
        <span v-if="selectedPdfId">📄 {{ selectedPdfName }}</span>
        <span v-else class="text-gray-500">No document selected</span>
      </p>
    </div>
    <button v-if="selectedPdfId" @click="closePdfViewer"
      class="px-2 py-1 text-xs text-gray-600 hover:bg-gray-100 rounded transition" title="Close">
      ✕
    </button>
  </div>
  
  <!-- Viewer -->
  <div class="flex-1 flex items-center justify-center overflow-hidden">
    <div v-if="selectedPdfId" class="w-full h-full">
      <iframe :src="pdfViewerUrl" class="w-full h-full border-none" title="PDF Viewer"></iframe>
    </div>
    <div v-else class="text-center text-gray-400">
      <p class="text-base font-medium">📄</p>
      <p class="text-xs mt-1">Select a document from the left to view</p>
    </div>
  </div>
</div>
```

---

## 9. Initialization (FIXED)

```javascript
onMounted(async () => {
  try {
    // Load prefill data if provided
    if (Object.keys(props.prefillData).length > 0) {
      Object.assign(formData, props.prefillData)
      showToast('Pre-filled', 'Beberapa field sudah diisi sebelumnya', 'info', 3000)
    }
    
    // Load existing documents for this case/stage
    const docsRes = await fetch(`/api/documents?tax_case_id=${props.caseId}&stage_code=${props.stageId}`)
    if (docsRes.ok) {
      const docsData = await docsRes.json()
      uploadedFiles.value = docsData.data || []
    }
    
  } catch (error) {
    console.error('Initialization error:', error)
  }
})
```

---

## 10. API Endpoints (FIXED)

| Operation | Method | Endpoint | Payload |
|-----------|--------|----------|---------|
| Submit Form | POST | `/api/tax-cases/{caseId}/workflow/{stageId}` | `{...formData, status: 'submitted'}` |
| Save Draft | POST | `/api/tax-cases/{caseId}/workflow/{stageId}?draft=true` | `{...formData, status: 'draft'}` |
| Upload Document | POST | `/api/documents` | FormData with file + metadata |
| List Documents | GET | `/api/documents?tax_case_id={id}&stage_code={code}` | - |
| View PDF | GET | `/api/documents/{id}/view` | - (returns inline PDF) |
| Delete Document | DELETE | `/api/documents/{id}` | - |

---

## 11. Prevention Checklist (FIXED)

### Button Types (CRITICAL!)
- [ ] Primary button: `type="submit"` inside `<form @submit.prevent="submitForm">`
- [ ] Draft button: **`type="button"`** + `@click="saveDraft"` (NOT type="submit"!)
- [ ] Cancel button: **`type="button"`** + `@click="$router.back()"` (NOT type="submit"!)
- [ ] Test: Draft button should NOT trigger form submit event

### Fields Disabled Check
- [ ] All inputs have: `:disabled="submissionComplete || fieldsDisabled"`
- [ ] fieldsDisabled computed property uses: `props.caseStatus > 1`
- [ ] Disabled styling: `class="disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"`
- [ ] Test: Load case with caseStatus > 1 → all fields show gray, disabled state

### Document Lock Check
- [ ] Remove button only shows when: `caseStatus === 1 && !submissionComplete && !fieldsDisabled`
- [ ] Lock icon shows otherwise
- [ ] Test: Submit form → Remove button disappears, Lock icon appears

### Form Submission
- [ ] Validation before confirmation dialog
- [ ] Document count check before submission
- [ ] Confirmation dialog with distinct message
- [ ] State reset in `finally` block (not just `try`)
- [ ] Test: Submit → fails → can retry (button not stuck)

### PDF Viewer
- [ ] Use `/view` endpoint (not `/download`)
- [ ] Iframe has: `w-full h-full` + no padding
- [ ] Parent container: `flex flex-col` + `flex-1`
- [ ] Test: PDF loads and fills right panel completely

### Notifications
- [ ] ONLY Toast notifications (no Alerts)
- [ ] English titles, bilingual messages
- [ ] Success: 2-3s | Error: 5-7s | Info: 4s
- [ ] All actions (submit, draft, upload, delete) have Toast feedback

---

---

# SECTION 2: CUSTOMIZE FOR YOUR STAGE

## Instructions

Copy the structure below and modify sections marked **[CUSTOMIZE]** based on your stage design.

Do NOT modify the GENERIC sections above - those are the same for all stages.

---

## C1. Form Fields [CUSTOMIZE]

Define your stage-specific form fields. Keep field definitions minimal.

```javascript
// Add this in your stage form component
const fields = [
  {
    key: 'entity_name',
    type: 'text',
    label: 'Entity Name',
    required: true,
    readonly: false,
    placeholder: 'Enter entity name'
  },
  {
    key: 'fiscal_period',
    type: 'select',
    label: 'Fiscal Period',
    required: true,
    readonly: false,
    options: [
      { value: 1, label: 'January' },
      { value: 2, label: 'February' },
      // ... 12 months
    ]
  },
  {
    key: 'disputed_amount',
    type: 'number',
    label: 'Disputed Amount',
    required: false,
    readonly: false,
    min: 0,
    step: 1000
  },
  // Add more fields for your stage...
]
```

---

## C2. Custom Validation [CUSTOMIZE]

Add validation rules specific to your stage after generic validation.

```javascript
// Extend validateForm() with stage-specific rules
const validateFormWithCustomRules = () => {
  // First: Run generic validation
  const isGenericValid = validateForm()
  
  // Then: Add custom rules
  // Example 1: Cross-field validation
  if (formData.dispute_type === 'refund' && !formData.refund_amount) {
    formErrors.refund_amount = 'Refund amount required for refund disputes'
    return false
  }
  
  // Example 2: Business logic validation
  if (formData.disputed_amount > 1000000000) {
    formErrors.disputed_amount = 'Amount cannot exceed 1 billion'
    return false
  }
  
  // Example 3: Temporal validation
  const caseYear = new Date(formData.case_date).getFullYear()
  if (caseYear < 2020) {
    formErrors.case_date = 'Case year cannot be before 2020'
    return false
  }
  
  return isGenericValid
}

// Replace validateForm() call in submitForm():
const submitForm = async () => {
  if (!validateFormWithCustomRules()) return  // Use custom validation
  
  // ... rest of submission flow
}
```

---

## C3. Pre-filled Data Handling [CUSTOMIZE]

Handle how data should be pre-filled from database or previous stages.

```javascript
// When initializing form, map API data to formData
const mapApiDataToFormData = (apiData) => {
  formData.entity_name = apiData.entity?.name || ''
  formData.fiscal_period = apiData.period_id || null
  formData.disputed_amount = apiData.disputed_amount ? parseFloat(apiData.disputed_amount) : null
  formData.case_status = apiData.case_status_id || 1
  
  // For complex fields, transform as needed
  formData.supporting_docs = Array.isArray(apiData.documents)
    ? apiData.documents.map(d => ({ id: d.id, name: d.name }))
    : []
}

// In onMounted:
onMounted(async () => {
  try {
    // If prefillData provided, use it
    if (Object.keys(props.prefillData).length > 0) {
      mapApiDataToFormData(props.prefillData)
    }
    
    // Load documents
    // ... existing code ...
  } catch (error) {
    console.error('Init error:', error)
  }
})
```

---

## C4. Watchers & Side Effects [CUSTOMIZE]

Add watchers for form fields that trigger calculations or other fields.

```javascript
// Example 1: When period changes, recalculate something
watch(
  () => formData.fiscal_period,
  (newPeriod) => {
    if (!newPeriod) return
    
    // Recalculate case number, fetch period data, etc.
    const period = periodsList.find(p => p.id === newPeriod)
    if (period) {
      // Generate new case number from period
      formData.case_number = generateCaseNumber(period)
      console.log('Case number updated:', formData.case_number)
    }
  }
)

// Example 2: When dispute type changes, show/hide relevant fields
watch(
  () => formData.dispute_type,
  (newType) => {
    if (newType === 'objection') {
      // Show objection-specific fields
      showObjectionFields.value = true
    } else if (newType === 'appeal') {
      // Show appeal-specific fields
      showAppealFields.value = true
    }
  }
)

// Example 3: When amount changes, calculate tax implications
watch(
  () => formData.disputed_amount,
  (newAmount) => {
    if (newAmount) {
      const interestAmount = calculateInterest(newAmount)
      formData.total_with_interest = newAmount + interestAmount
    }
  }
)
```

---

## C5. Computed Properties [CUSTOMIZE]

Create computed properties for derived/calculated UI state.

```javascript
// Example 1: Show/hide sections based on form state
const showRefundSection = computed(() => {
  return formData.dispute_type === 'refund'
})

const showAppealSection = computed(() => {
  return formData.has_appealed === true
})

// Example 2: Format displays
const formattedAmount = computed(() => {
  if (!formData.disputed_amount) return '—'
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR'
  }).format(formData.disputed_amount)
})

// Example 3: Dynamic button text
const submitButtonText = computed(() => {
  if (props.stageId === 1) return 'Submit & Continue to Stage 2'
  if (props.stageId === 12) return 'Complete Filing'
  return `Submit & Continue to Stage ${props.stageId + 1}`
})
```

---

## C6. Custom Actions [CUSTOMIZE]

Implement stage-specific actions beyond standard submit/draft.

```javascript
// Example 1: Calculate derived values before submit
const calculateAndValidate = () => {
  // Calculate interest on disputed amount
  const rate = 0.02  // 2% monthly
  const months = calculateMonthsDifference(formData.case_date)
  formData.interest = formData.disputed_amount * rate * months
  formData.total_liability = formData.disputed_amount + formData.interest
  
  // Validate calculation
  if (formData.total_liability < formData.disputed_amount) {
    console.error('Invalid liability calculation')
    return false
  }
  return true
}

// Example 2: Export or generate documents
const generateSummaryDocument = () => {
  const summary = {
    case_number: formData.case_number,
    disputed_amount: formData.disputed_amount,
    period: formData.fiscal_period,
    // ... more fields
  }
  
  // Format and download
  const blob = new Blob([JSON.stringify(summary, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `${formData.case_number}_summary.json`
  a.click()
}

// Example 3: Call custom API endpoints
const validateWithThirdParty = async () => {
  const res = await fetch('/api/validation/check-case-status', {
    method: 'POST',
    body: JSON.stringify({ case_number: formData.case_number })
  })
  return res.ok
}

// Integrate into submission flow:
const executeSubmitForm = async () => {
  if (!calculateAndValidate()) return  // Custom validation
  if (!(await validateWithThirdParty())) return  // Third-party check
  
  // ... continue with standard submit
}
```

---

## C7. Error & Edge Cases [CUSTOMIZE]

Handle stage-specific error scenarios.

```javascript
// Example 1: Check for duplicate submissions
const checkDuplicateSubmission = async () => {
  const existing = await fetch(`/api/tax-cases/${props.caseId}/workflow/${props.stageId}/check`)
  if (existing.ok) {
    const data = await existing.json()
    if (data.submitted_at) {
      showToast('Already Submitted', `This stage was submitted on ${data.submitted_at}`, 'warning', 5000)
      return false
    }
  }
  return true
}

// Example 2: Network error recovery
const executeSubmitFormWithRetry = async (maxRetries = 3) => {
  for (let attempt = 1; attempt <= maxRetries; attempt++) {
    try {
      const response = await fetch(props.apiEndpoint, { /* ... */ })
      if (response.ok) return await response.json()
      throw new Error(`HTTP ${response.status}`)
    } catch (error) {
      if (attempt === maxRetries) throw error
      
      // Wait before retry (exponential backoff)
      const delay = Math.pow(2, attempt) * 1000
      await new Promise(r => setTimeout(r, delay))
    }
  }
}

// Example 3: Handle missing/invalid data gracefully
const sanitizeFormData = () => {
  // Remove undefined/null values
  const sanitized = {}
  Object.entries(formData).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') {
      sanitized[key] = value
    }
  })
  return sanitized
}
```

---

## C8. Testing Scenarios [CUSTOMIZE]

Define test cases specific to your stage.

```javascript
// Test data for development
const TEST_CASES = {
  valid: {
    entity_name: 'PT. Test Company',
    fiscal_period: 3,  // March
    disputed_amount: 50000000,
    case_date: '2024-03-15'
  },
  
  invalid: {
    entity_name: '',  // Missing required
    fiscal_period: null,
    disputed_amount: -1000,  // Negative amount
    case_date: '2020-01-01'  // Before 2020
  },
  
  edge: {
    entity_name: 'A'.repeat(500),  // Very long name
    disputed_amount: 999999999999,  // Very large amount
    case_date: new Date().toISOString()  // Today
  }
}

// Manual test function for development
const runManualTests = () => {
  console.log('Testing valid case...')
  formData = { ...TEST_CASES.valid }
  console.log('Valid result:', validateFormWithCustomRules())
  
  console.log('Testing invalid case...')
  formData = { ...TEST_CASES.invalid }
  console.log('Invalid result:', validateFormWithCustomRules(), formErrors)
  
  console.log('Testing edge cases...')
  formData = { ...TEST_CASES.edge }
  console.log('Edge result:', validateFormWithCustomRules())
}
```

---

## C9. Parent Page Component [CUSTOMIZE]

Show how to use this stage form in parent component.

```vue
<!-- Example: SptFilingForm.vue or similar -->
<template>
  <div class="h-full">
    <div v-if="isLoading" class="fixed inset-0 backdrop-blur-sm bg-white/30 flex items-center justify-center z-50">
      <LoadingSpinner />
    </div>
    
    <StageForm
      :stage-name="`Stage ${stageId} - ${stageName}`"
      :stage-description="stageDescription"
      :stage-id="stageId"
      :case-id="caseId"
      :case-number="caseNumber"
      :fields="fields"
      :api-endpoint="apiEndpoint"
      :case-status="caseStatus"
      :is-loading="isLoading"
      :prefill-data="prefillData"
      :next-stage-id="nextStageId"
      @submit="onFormSubmit"
      @save-draft="onSaveDraft"
    />
  </div>
</template>

<script setup>
const route = useRoute()
const router = useRouter()

const stageId = computed(() => parseInt(route.params.stageId, 10) || 1)
const caseId = route.params.caseId

const isLoading = ref(false)
const caseData = ref(null)
const prefillData = ref({})

// [CUSTOMIZE] Add stage-specific fields
const fields = ref([])
const stageName = 'Stage Name Here'  // e.g., "Entity Information"
const stageDescription = 'Short description'  // e.g., "Enter core entity details"
const apiEndpoint = computed(() => `/api/tax-cases/${caseId}/workflow/${stageId}`)
const nextStageId = computed(() => stageId.value < 12 ? stageId.value + 1 : null)

const caseNumber = computed(() => caseData.value?.case_number || 'N/A')
const caseStatus = computed(() => caseData.value?.case_status_id || 1)

// Load case data
onMounted(async () => {
  try {
    isLoading.value = true
    
    const res = await fetch(`/api/tax-cases/${caseId}`)
    if (!res.ok) throw new Error('Failed to load case')
    
    const data = await res.json()
    caseData.value = data.data || data
    
    // [CUSTOMIZE] Map API data to prefillData
    prefillData.value = {
      entity_id: caseData.value.entity_id,
      entity_name: caseData.value.entity?.name,
      fiscal_period: caseData.value.period_id,
      disputed_amount: caseData.value.disputed_amount
    }
    
    // [CUSTOMIZE] Set up stage fields based on stage type
    fields.value = buildFieldsForStage(stageId.value, caseData.value)
    
  } catch (error) {
    console.error('Load error:', error)
    showToast('Error', 'Failed to load case data', 'error')
  } finally {
    isLoading.value = false
  }
})

// [CUSTOMIZE] Handle form submission
const onFormSubmit = async () => {
  // Refresh case data after submission
  await loadCaseData()
  
  // Navigate to next stage if available
  if (nextStageId.value) {
    await router.push({
      name: 'stage-form',
      params: { caseId, stageId: nextStageId.value }
    })
  }
}

// [CUSTOMIZE] Handle draft save
const onSaveDraft = async () => {
  // Optional: Refresh case data
  await loadCaseData()
  
  // Show notification or navigate
  showToast('Saved', 'Draft saved successfully', 'success')
}

const loadCaseData = async () => {
  try {
    const res = await fetch(`/api/tax-cases/${caseId}`)
    if (res.ok) {
      const data = await res.json()
      caseData.value = data.data || data
    }
  } catch (error) {
    console.warn('Failed to refresh case:', error)
  }
}
</script>
```

---

# SECTION 3: OBSTACLES & LESSONS LEARNED

*See separate document: [STAGE_FORM_OBSTACLES.md](STAGE_FORM_OBSTACLES.md)*

---

# SECTION 4: QUICK REFERENCE

## Did You Remember?

- [ ] ✅ Button types: `type="submit"` ONLY on primary, `type="button"` on others
- [ ] ✅ Fields disabled: `:disabled="submissionComplete || fieldsDisabled"`
- [ ] ✅ File upload: Use XMLHttpRequest, NOT Fetch API
- [ ] ✅ Notifications: Toast ONLY, no Alerts
- [ ] ✅ PDF viewer: Use `/view` endpoint, not `/download`
- [ ] ✅ Confirmation dialog: Unified with `pendingAction` dispatcher
- [ ] ✅ Document lock: Remove only when `caseStatus === 1 && !submissionComplete`
- [ ] ✅ Form validation: Check documents count before submit
- [ ] ✅ Async cleanup: Always use `finally` block
- [ ] ✅ Error recovery: Allow retries after failed submission

---

**Version History:**
- **2.0** (Jan 16, 2026): REFINED - Separated GENERIC (base template) from CUSTOMIZE (stage-specific), removed redundancy, improved obstacle prevention through better prompts, condensed verbose sections
- **1.0** (Jan 13, 2026): Initial comprehensive template

