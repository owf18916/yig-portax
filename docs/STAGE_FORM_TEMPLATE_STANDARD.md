# Stage Form Template Standard - Refined Version

**Document Version:** 1.0  
**Date:** January 13, 2026  
**Applicable Component:** `StageForm.vue` and derivative stage form components

---

## 1. Overview

Unified template standard untuk semua stage form dalam aplikasi PORTAX. Dokumen ini mendefinisikan styling, behavior, state management, dan UX patterns yang konsisten di seluruh workflow stages (Stage 1, 4, 7, dll).

---

## 2. Architecture & Layout

### 2.1 Container Structure

```vue
<!-- Full-height 50-50 split layout -->
<div class="flex h-screen bg-gray-100">
  <!-- LEFT: Form (50%) -->
  <div class="w-1/2 overflow-y-auto flex flex-col bg-white">
    <div class="flex-1 space-y-1 p-2">
      <!-- Content here -->
    </div>
  </div>
  
  <!-- RIGHT: PDF Viewer (50%) -->
  <div class="w-1/2 bg-gray-100 flex flex-col border-l border-gray-300">
    <!-- PDF Viewer Content -->
  </div>
</div>
```

**Key Properties:**
- `h-screen` - Full viewport height
- `flex` - Side-by-side layout
- `w-1/2` - Exact 50% split
- `overflow-y-auto` - Left side scrollable
- `bg-gray-100` - Right side background
- **Padding:** `p-2` (minimal, only 8px)
- **Gap:** `space-y-1` between sections (minimal, only 4px)

### 2.2 Parent Page Wrapper

**In parent page component** (e.g., `SptFilingForm.vue`):

```vue
<template>
  <div class="h-full">
    <!-- Loading overlay -->
    <div v-if="isLoading" class="fixed inset-0 backdrop-blur-sm bg-white/30 ...">
      <!-- Loading spinner -->
    </div>
    
    <StageForm ... />
  </div>
</template>
```

**In App.vue main layout:**

```vue
<main :class="getMainClasses()">
  <router-view />
</main>

<script setup>
const getMainClasses = () => {
  if (route.path === '/login') return ''
  
  // Workflow routes: minimal padding
  if (route.path.includes('/workflow/') || 
      route.path.includes('/spt-filing') ||
      route.path.includes('/[stage-name]')) {
    return 'px-2 py-2 h-full'
  }
  
  // Other routes: normal padding
  return 'max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12'
}
</script>
```

---

## 3. Component Sections (Left Side)

### 3.1 Header Section

```vue
<div class="flex items-center justify-between mb-2">
  <div class="flex items-center space-x-1">
    <Button @click="$router.back()" variant="secondary" class="text-xs px-2 py-1">
      ← Back
    </Button>
    <h1 class="text-lg font-bold text-gray-900">{{ stageName }}</h1>
  </div>
</div>
```

**Rules:**
- Back button + Title only
- Font size: `text-lg` (tight)
- Space between: `space-x-1` (minimal)
- Button size: `text-xs px-2 py-1` (compact)

### 3.2 Case Information Display

```vue
<div class="bg-blue-50 p-2 rounded-lg border border-blue-200 mb-2">
  <div class="grid grid-cols-2 gap-2 text-xs">
    <div>
      <p class="text-gray-600 text-xs">Case #</p>
      <p class="font-bold text-sm text-gray-900">{{ caseNumber }}</p>
    </div>
    <div>
      <p class="text-gray-600 text-xs">Stage</p>
      <p class="font-bold text-sm text-gray-900">{{ stageId }} / 12</p>
    </div>
  </div>
</div>
```

**Rules:**
- Location: Top of form (immediately after header)
- Background: `bg-blue-50` with `border-blue-200`
- Layout: 2-column grid
- Padding: `p-2` (minimal)
- Font: Label `text-xs`, Value `text-sm font-bold`
- Purpose: Quick reference for user's current position in workflow

### 3.3 Form Card Container

```vue
<div class="bg-white rounded-lg border border-gray-200">
  <div class="px-3 py-2 border-b border-gray-200">
    <h3 class="font-semibold text-base text-gray-900">{{ stageName }} - Information</h3>
    <p class="text-xs text-gray-600 mt-0.5">{{ stageDescription }}</p>
  </div>
  <div class="p-3">
    <form @submit.prevent="submitForm" class="space-y-2">
      <!-- Form fields here -->
    </form>
  </div>
</div>
```

**Rules:**
- Card padding: `p-3`
- Header padding: `px-3 py-2`
- Title: `text-base font-semibold`
- Description: `text-xs text-gray-600`
- Field spacing: `space-y-2` between fields

### 3.4 Form Fields Pattern

**All form inputs must:**

```vue
<!-- Text/Number Input -->
<FormField
  v-if="field.type === 'text'"
  :label="field.label"
  type="text"
  v-model="formData[field.key]"
  :required="field.required"
  :error="formErrors[field.key]"
  :disabled="field.readonly || submissionComplete"
/>

<!-- Textarea -->
<textarea
  :disabled="submissionComplete"
  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
/>

<!-- Select -->
<select
  :disabled="submissionComplete"
  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
>
  <option value="">-- Select {{ field.label }} --</option>
</select>
```

**Rules:**
- **Disabled State:** Add `:disabled="submissionComplete"` to all inputs
- **Styling:** Consistent focus ring `focus:ring-2 focus:ring-blue-500`
- **Error Display:** Via `formErrors` object
- **Validation:** On blur and submit

### 3.5 Document Upload Section

```vue
<div class="border-t pt-2 mt-2">
  <h3 class="text-sm font-medium text-gray-900 mb-1">📎 Supporting Documents</h3>
  
  <!-- File input -->
  <input
    type="file"
    multiple
    @change="handleFileUpload"
    :disabled="uploadProgress > 0 || submissionComplete"
    class="block w-full text-sm text-gray-500
      file:mr-4 file:py-2 file:px-4
      file:rounded-lg file:border-0
      file:text-sm file:font-medium
      file:bg-blue-50 file:text-blue-700
      hover:file:bg-blue-100
      disabled:opacity-50 disabled:cursor-not-allowed"
  />
  
  <!-- Upload Progress Bar (if uploading) -->
  <div v-if="uploadProgress > 0" class="space-y-1 mt-1">
    <div class="flex items-center justify-between">
      <span class="text-xs font-medium text-gray-700">Uploading...</span>
      <span class="text-xs font-semibold text-blue-600">{{ uploadProgress }}%</span>
    </div>
    <div class="w-full bg-gray-200 rounded-full h-1 overflow-hidden">
      <div class="bg-blue-500 h-full rounded-full transition-all duration-300"
        :style="{ width: `${uploadProgress}%` }"></div>
    </div>
  </div>
  
  <!-- Document List -->
  <div v-if="uploadedFiles.length > 0" class="mt-1 space-y-0.5">
    <p class="text-xs font-medium text-gray-700">{{ uploadedFiles.length }} file(s):</p>
    <div v-for="file in uploadedFiles" :key="file.id" 
      class="flex items-center justify-between p-1.5 bg-gray-50 rounded border border-gray-200 hover:bg-blue-50 cursor-pointer transition text-xs"
      @click="viewDocument(file.id, file.name)">
      
      <div class="flex items-center space-x-1.5 flex-1 min-w-0">
        <span class="text-base flex-shrink-0">📄</span>
        <div class="flex-1 min-w-0">
          <p class="font-medium text-gray-900 truncate text-xs">{{ file.name }}</p>
          <p class="text-xs text-gray-500">
            {{ file.size }} MB • 
            <span :class="file.status === 'DRAFT' ? 'text-yellow-600' : 'text-green-600'">
              {{ file.status }}
            </span>
          </p>
        </div>
      </div>
      
      <div class="flex items-center space-x-0.5 flex-shrink-0 ml-1">
        <!-- Remove button (locked after submission) -->
        <button v-if="caseStatus === 1 && !submissionComplete"
          @click.stop="removeFile(file.id)"
          class="px-1.5 py-0.5 text-xs bg-red-50 text-red-700 rounded hover:bg-red-100 transition whitespace-nowrap">
          🗑️ Remove
        </button>
        
        <!-- Lock icon (after submission) -->
        <span v-else class="px-1.5 py-0.5 text-xs bg-gray-100 text-gray-500 rounded whitespace-nowrap">
          🔒 Locked
        </span>
      </div>
    </div>
  </div>
</div>
```

**Rules:**
- **File Types:** PDF only (`mimes:pdf`)
- **Max Size:** 10MB per file
- **Progress:** XMLHttpRequest with real-time percentage tracking
- **Clickable:** Each file opens PDF viewer in right panel
- **Remove Button:** 
  - Available only if `caseStatus === 1` (CREATED/DRAFT)
  - Hidden after form submission (`submissionComplete === true`)
  - Shows lock icon when documents are locked
- **Status Badges:** 
  - DRAFT: `text-yellow-600`
  - ACTIVE/ARCHIVED: `text-green-600`

### 3.6 Submit Buttons

```vue
<div class="flex gap-1 pt-2 border-t">
  <Button type="submit" variant="primary" 
    :disabled="submitting || isLoading || (caseStatus && caseStatus > 1)" 
    class="text-xs px-2 py-1.5">
    {{ submitting ? 'Submitting...' : 'Submit & Continue' }}
  </Button>
  
  <Button @click="saveDraft" variant="secondary" 
    :disabled="submitting || isLoading || (caseStatus && caseStatus > 1)" 
    class="text-xs px-2 py-1.5">
    Save as Draft
  </Button>
  
  <Button @click="$router.back()" variant="secondary" 
    :disabled="isLoading" 
    class="text-xs px-2 py-1.5">
    Cancel
  </Button>
</div>
```

**Rules:**
- **Gap:** `gap-1` (minimal spacing)
- **Sizing:** `text-xs px-2 py-1.5` (compact)
- **Disabled Conditions:**
  - `:disabled="submitting || isLoading || (caseStatus && caseStatus > 1)"`
  - Prevent double-submit
  - Prevent edit when case already submitted
- **Button Order:** Submit → Draft → Cancel
- **Loading State:** Show "Submitting..." text

---

## 4. Right Side: PDF Viewer

### 4.1 PDF Viewer Container

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
    <button v-if="selectedPdfId"
      @click="closePdfViewer"
      class="px-2 py-1 text-xs text-gray-600 hover:bg-gray-100 rounded transition"
      title="Close">
      ✕
    </button>
  </div>
  
  <!-- Viewer Container -->
  <div class="flex-1 flex items-center justify-center overflow-hidden">
    <!-- PDF loaded -->
    <div v-if="selectedPdfId" class="w-full h-full">
      <iframe :src="pdfViewerUrl" class="w-full h-full border-none" title="PDF Viewer"></iframe>
    </div>
    
    <!-- Empty state -->
    <div v-else class="text-center text-gray-400">
      <p class="text-base font-medium">📄</p>
      <p class="text-xs mt-1">Select a document from the left to view</p>
    </div>
  </div>
</div>
```

**Rules:**
- **PDF Endpoint:** `/api/documents/{id}/view` (inline, not download)
- **Browser Native Viewer:** Firefox, Chrome, Safari built-in PDF support
- **Header Padding:** `p-2` (minimal)
- **Close Button:** Text-only `✕`
- **Empty State:** Show helpful message

---

## 5. State Management

### 5.1 Required Refs

```javascript
// Form state
const submitting = ref(false)
const formData = reactive({})
const formErrors = reactive({})
const successMessage = ref('')
const submissionComplete = ref(false)

// Document state
const uploadedFiles = ref([])
const loadingDocuments = ref(false)
const uploadProgress = ref(0)
const uploadingFiles = ref({})

// PDF Viewer state
const selectedPdfId = ref(null)
const selectedPdfName = ref('')
const pdfViewerUrl = ref('')

// Confirmation Dialog state
const showConfirmDialog = ref(false)
const pendingFileId = ref(null)
const confirmDialogTitle = ref('Confirm')
const confirmDialogMessage = ref('')
const confirmDialogVariant = ref('default') // 'default' or 'danger'
const pendingAction = ref(null) // 'submit', 'draft', or null
```

### 5.2 Required Props

```javascript
const props = defineProps({
  stageName: { type: String, required: true },
  stageDescription: { type: String, required: true },
  stageId: { type: Number, required: true },
  caseId: { type: String, required: true },
  caseNumber: { type: String, required: true },
  fields: { type: Array, required: true },
  apiEndpoint: { type: String, required: true },
  isReviewMode: { type: Boolean, default: false },
  isLoading: { type: Boolean, default: false },
  caseStatus: { type: Number, default: null },
  preFilledMessage: { type: String, default: null },
  nextStageId: { type: Number, default: null },
  prefillData: { type: Object, default: () => ({}) }
})

const emit = defineEmits(['submit', 'saveDraft'])
```

---

## 6. Behavior & Patterns

### 6.1 Form Submission Flow

```
User clicks "Submit & Continue"
    ↓
Show Confirmation Dialog (confirmDialogTitle, confirmDialogMessage)
    ↓
User confirms
    ↓
executeSubmitForm() → POST to /api/tax-cases/{caseId}/workflow/{stageId}
    ↓
Success: Show toast + Set submissionComplete = true
    ↓
User sees "Continue to Stage X" button
    ↓
User clicks → router.push(`/tax-cases/{caseId}/workflow/{nextStageId}`)
```

### 6.2 Draft Save Flow

```
User clicks "Save as Draft"
    ↓
Show Confirmation Dialog (different message)
    ↓
User confirms
    ↓
executeSaveDraft() → POST to /api/tax-cases/{caseId}/workflow/{stageId}?draft=true
    ↓
Success: Show toast + Emit 'saveDraft' event
    ↓
User can continue editing or leave page
```

### 6.3 Document Upload Flow

```
User selects file(s)
    ↓
Validate: PDF only, max 10MB
    ↓
Show upload progress (0-100%)
    ↓
XMLHttpRequest → POST to /api/documents
    ↓
Success: Add to uploadedFiles array, show toast
    ↓
File appears in "Supporting Documents" section
    ↓
User can click to view in PDF viewer
    ↓
User can remove if caseStatus === 1
```

### 6.4 Document Delete Flow

```
User clicks "Remove" button
    ↓
Check: caseStatus === 1? (if not, show error)
    ↓
Show Confirmation Dialog (danger variant)
    ↓
User confirms
    ↓
DELETE /api/documents/{id}
    ↓
Success: Remove from uploadedFiles, show toast
```

### 6.5 Confirmation Dialog Pattern

```javascript
// For any action that needs confirmation:
const handleAction = () => {
  // Set dialog content
  confirmDialogTitle.value = 'Action Title'
  confirmDialogMessage.value = 'Detailed message explaining the action'
  confirmDialogVariant.value = 'default' // or 'danger'
  pendingAction.value = 'action_name' // to identify in handleConfirm
  
  // Show dialog
  showConfirmDialog.value = true
}

const handleConfirm = async () => {
  if (pendingAction.value === 'action_name') {
    await executeAction()
  }
  
  // Reset state
  showConfirmDialog.value = false
  pendingAction.value = null
}
```

---

## 7. Validation Rules

### 7.1 Form Validation

```javascript
const validateForm = () => {
  // Clear previous errors
  Object.keys(formErrors).forEach(key => {
    delete formErrors[key]
  })

  let isValid = true

  props.fields.forEach(field => {
    if (field.readonly) return

    const value = formData[field.key]

    // Required field check
    if (field.required && (value === null || value === undefined || value === '')) {
      formErrors[field.key] = `${field.label} is required`
      isValid = false
      return
    }

    // Type-specific validation
    if (field.type === 'number' && value) {
      const numValue = parseFloat(value)
      if (isNaN(numValue)) {
        formErrors[field.key] = `${field.label} must be a valid number`
        isValid = false
      } else if (numValue < 0) {
        formErrors[field.key] = `${field.label} cannot be negative`
        isValid = false
      }
    }

    // Select validation
    if (field.type === 'select' && field.required && !value) {
      formErrors[field.key] = `${field.label} must be selected`
      isValid = false
    }
  })

  return isValid
}
```

### 7.2 Pre-Submit Validation

**Before submitting form:**

```javascript
const submitForm = async () => {
  // 1. Validate all form fields
  if (!validateForm()) return

  // 2. Validate at least one document uploaded
  if (uploadedFiles.value.length === 0) {
    toastRef.value?.addToast(
      'Missing Documents', 
      'Please upload at least one supporting document before submitting', 
      'error', 
      5000
    )
    return
  }

  // 3. Show confirmation dialog
  // ...
}
```

---

## 8. Document Handling Rules

### 8.1 Document Lock Logic

```javascript
// Remove button visible when:
// - caseStatus === 1 (case in CREATED/DRAFT state)
// - submissionComplete === false (form not yet submitted)
<button v-if="caseStatus === 1 && !submissionComplete"
  @click.stop="removeFile(file.id)"
  ...>
  🗑️ Remove
</button>

// Lock icon shown when:
// - caseStatus > 1 (case SUBMITTED or beyond)
// - OR submissionComplete === true (form submission complete)
<span v-else class="...">
  🔒 Locked
</span>
```

**caseStatus values:**
- `1` = CREATED (editable, documents can be removed)
- `2` = SUBMITTED (locked, no document changes)
- `3+` = ARCHIVED/REVIEWED (locked)

### 8.2 File Upload Validation

```javascript
const handleFileUpload = async (event) => {
  const files = event.target.files

  for (let file of files) {
    // Type validation
    if (file.type !== 'application/pdf') {
      toastRef.value?.addToast('Invalid File Type', 'Only PDF files are allowed', 'error', 4000)
      continue
    }

    // Size validation
    const maxSize = 10 * 1024 * 1024 // 10MB
    if (file.size > maxSize) {
      toastRef.value?.addToast('File Too Large', 'Maximum file size is 10MB', 'error', 4000)
      continue
    }

    // Upload
    await uploadFile(file)
  }

  event.target.value = '' // Reset input
}
```

---

## 9. Styling Standards

### 9.1 Typography

| Element | Size | Weight | Class |
|---------|------|--------|-------|
| Page Title | text-lg | bold | `text-lg font-bold` |
| Section Header | text-sm | semibold | `text-sm font-medium` |
| Label | text-xs | normal | `text-xs` |
| Value | text-sm | bold | `text-sm font-bold` |
| Helper Text | text-xs | normal | `text-xs` |
| Button | text-xs | normal | `text-xs` |

### 9.2 Spacing Scale

| Level | Value | Utility |
|-------|-------|---------|
| Minimal | 4px | `p-1`, `gap-1`, `space-y-1` |
| Small | 8px | `p-2`, `gap-2`, `space-y-2` |
| Medium | 12px | `p-3`, `gap-3`, `space-y-3` |
| Large | 16px | `p-4`, `gap-4`, `space-y-4` |

**For stage forms: Use Minimal-Small only** (p-2, gap-1, space-y-1)

### 9.3 Color Palette

| Usage | Color | Tailwind Class |
|-------|-------|----------------|
| Primary Action | Blue | `bg-blue-600`, `text-blue-600` |
| Secondary Action | Gray | `bg-gray-100`, `text-gray-600` |
| Success | Green | `text-green-600`, `bg-green-50` |
| Warning | Yellow | `text-yellow-600`, `bg-yellow-50` |
| Error | Red | `text-red-700`, `bg-red-50` |
| Info Box | Blue | `bg-blue-50`, `border-blue-200` |
| Disabled | Gray | `text-gray-500`, `bg-gray-100` |
| Border | Gray | `border-gray-200`, `border-gray-300` |

### 9.4 Button Sizing

**Compact buttons** (for stage forms):
```
text-xs px-2 py-1.5
```

**Normal buttons** (for other pages):
```
text-sm px-3 py-2
```

---

## 10. Accessibility & UX Rules

### 10.1 Keyboard Navigation

- ✅ Tab through form fields in order
- ✅ Enter key submits form
- ✅ Escape closes PDF viewer
- ✅ Space activates buttons/checkboxes

### 10.2 Disabled States

```vue
<!-- All inputs must support disabled state -->
:disabled="field.readonly || submissionComplete"

<!-- Disabled styling applied automatically -->
class="disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
```

### 10.3 Error Messages

- Display inline below form field
- Color: `text-red-500`
- Size: `text-sm`
- Show on validation error and blur

### 10.4 Loading States

- Form buttons: Show "Submitting..." text
- File input: Disabled during upload
- Fields: All disabled after submission
- Overall: LoadingSpinner component

### 10.5 Toast Notifications

```javascript
// Success (auto-dismiss 3-5s)
toastRef.value?.addToast('Title', 'Message', 'success', 5000)

// Error (stay longer 5s)
toastRef.value?.addToast('Title', 'Message', 'error', 5000)

// Info (4s)
toastRef.value?.addToast('Title', 'Message', 'info', 4000)
```

---

## 11. API Integration

### 11.1 Form Submission Endpoint

**POST** `/api/tax-cases/{caseId}/workflow/{stageId}`

```json
{
  "field_key": "value",
  "field_key_2": "value_2",
  "stage_id": 1,
  "case_id": "abc-123",
  "status": "submitted"
}
```

### 11.2 Draft Save Endpoint

**POST** `/api/tax-cases/{caseId}/workflow/{stageId}?draft=true`

Same payload as submission but with `status: "draft"`

### 11.3 Document Upload Endpoint

**POST** `/api/documents`

```multipart/form-data
- file (required): PDF file
- tax_case_id (required): {caseId}
- documentable_type (required): "App\Models\WorkflowHistory"
- documentable_id (required): {stageId}
- stage_code (required): "{stageId}"
- document_type (required): "supporting_document"
- description (optional): string
```

### 11.4 Document List Endpoint

**GET** `/api/documents?tax_case_id={id}&stage_code={code}&status=DRAFT,ACTIVE,ARCHIVED`

Response: Array of document objects with:
- `id`, `original_filename`, `file_size`, `status`, `uploaded_at`, etc.

### 11.5 Document Delete Endpoint

**DELETE** `/api/documents/{documentId}`

### 11.6 Document View Endpoint

**GET** `/api/documents/{documentId}/view`

Returns PDF file inline (not download)

---

## 12. Implementation Checklist

### 12.1 Component Setup

- [ ] Create new `[StageName]Form.vue` component
- [ ] Extend from StageForm.vue template
- [ ] Set all required props in parent page component
- [ ] Define stage-specific fields array
- [ ] Set correct `apiEndpoint` URL
- [ ] Define `nextStageId` if applicable

### 12.2 Styling & Layout

- [ ] Use 50-50 split layout (`w-1/2`)
- [ ] Apply minimal padding (`p-2`, `space-y-1`)
- [ ] Set all typography to `text-xs` and `text-sm`
- [ ] Use compact button sizing
- [ ] Apply color palette correctly

### 12.3 Form Fields

- [ ] Validate all field types work correctly
- [ ] Test disabled state after submission
- [ ] Implement proper error display
- [ ] Test keyboard navigation (Tab, Enter)
- [ ] Test form validation

### 12.4 Document Handling

- [ ] Document upload with PDF validation
- [ ] Upload progress bar working
- [ ] Document list displays correctly
- [ ] Remove button visible only when `caseStatus === 1 && !submissionComplete`
- [ ] Lock icon shows when documents locked
- [ ] Click document opens PDF viewer

### 12.5 PDF Viewer

- [ ] PDF loads in right panel via iframe
- [ ] Close button functional
- [ ] Empty state shows helpful message
- [ ] PDF displays correctly in all browsers

### 12.6 Submission & Draft Save

- [ ] Confirmation dialog shows before submit
- [ ] Form validation works
- [ ] Document requirement validation works
- [ ] Buttons disabled after submission
- [ ] Form fields become read-only after submission
- [ ] Success message displays
- [ ] "Continue to Next Stage" button appears
- [ ] Draft save works independently

### 12.7 Confirmation Dialogs

- [ ] Document delete: Shows danger variant
- [ ] Form submit: Shows default variant with action details
- [ ] Draft save: Shows default variant with draft explanation
- [ ] Cancel button properly resets state

---

## 13. Common Patterns Reference

### 13.1 Accessing Form Values

```javascript
// Get all form data
console.log(formData) // Reactive object with all values

// Get single field
const fieldValue = formData['field_key']

// Set field value programmatically
formData['field_key'] = 'new_value'
```

### 13.2 Adding Form Validation

```javascript
// For custom validation, extend validateForm():
const validateForm = () => {
  // ... existing validation ...

  // Add custom rules
  if (formData.amount && formData.amount > 1000000) {
    formErrors.amount = 'Amount cannot exceed 1,000,000'
    isValid = false
  }

  return isValid
}
```

### 13.3 Pre-filling Form Data

```javascript
// In parent page component, pass prefillData prop:
<StageForm
  :prefillData="{
    entity_name: 'PT. Example Corp',
    fiscal_period: '2024-03',
    disputed_amount: 500000
  }"
/>
```

### 13.4 Conditional Field Display

```vue
<!-- Hide/show fields based on condition -->
<FormField
  v-if="formData.type === 'dispute'"
  :label="'Dispute Amount'"
  ...
/>
```

### 13.5 Dynamic Form Fields

```javascript
// In parent component, build fields array dynamically:
const fields = computed(() => {
  return [
    { id: 1, key: 'name', type: 'text', label: 'Name', required: true },
    { id: 2, key: 'period', type: 'month', label: 'Period', required: true },
    // ... more fields
  ]
})
```

---

## 14. Troubleshooting Guide

### Issue: Buttons disabled and can't submit

**Solution:** Check:
1. `submitting` is being reset to false after request completes
2. No infinite loops in computed properties
3. `isLoading` prop from parent is not stuck as true

### Issue: PDF viewer not loading

**Solution:**
1. Verify `/api/documents/{id}/view` endpoint exists
2. Check CORS headers allow iframe loading
3. Ensure PDF file path is correct in database
4. Test with direct URL in browser first

### Issue: Document upload progress stuck

**Solution:**
1. Verify XMLHttpRequest creation correct
2. Check server timeout not too short
3. Ensure CSRF token is included in headers
4. Check file size under 10MB limit

### Issue: Form doesn't show validation errors

**Solution:**
1. Verify `formErrors` reactive object is being populated
2. Check field has `:error="formErrors[field.key]"` binding
3. Ensure `validateForm()` called before submit
4. Check field.required is true in fields array

### Issue: Confirmation dialog doesn't appear

**Solution:**
1. Verify `showConfirmDialog.value = true` being set
2. Check ConfirmationDialog component imported
3. Ensure `:is-open="showConfirmDialog"` prop bound correctly
4. Check no console errors blocking dialog

---

## 15. Lessons Learned & Solved Obstacles

**IMPORTANT:** This section documents errors, bugs, and design decisions that were encountered during StageForm.vue implementation. Study these carefully to avoid repeating the same mistakes in subsequent stage implementations.

### 15.1 Layout & Spacing Issues

#### Obstacle 1: Excessive Padding Wasted Space
**Problem:** Initial implementation had `p-8` (32px padding) on left container and `max-w-7xl` constraint in parent App.vue. This left large empty spaces on both sides of the 50-50 split layout, making the form and PDF viewer appear cramped.

**Symptoms:**
- Form and PDF viewer not utilizing full available width
- Visible empty gray space on left and right borders
- User complained about wasted screen real estate

**Root Cause:** Applied typical page padding rules (suitable for normal CRUD pages) to a special 50-50 split layout component that needs every pixel.

**Solution Implemented:**
```javascript
// In App.vue - Detect workflow routes and remove max-width constraint
const getMainClasses = () => {
  if (route.path === '/login') return ''
  
  // Workflow routes: minimal padding only
  if (route.path.includes('/workflow/') || 
      route.path.includes('/spt-filing')) {
    return 'px-2 py-2 h-full'  // Reduced from px-4 py-12
  }
  
  // Other routes: normal padding and max-width
  return 'max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12'
}
```

```vue
<!-- In StageForm.vue container -->
<div class="flex-1 space-y-1 p-2">  <!-- Reduced from p-8, space-y-6 -->
```

**Lesson for Next Stages:** Special layout components need special treatment in parent layouts. Don't assume all pages follow the same padding/constraint rules.

---

#### Obstacle 2: Internal Component Padding Stacking
**Problem:** Every child component (Card, Alert, Button, etc.) had its own padding, causing nested padding to stack up:
- Card had `p-4` internally
- Form field wrapper had spacing
- Buttons had `px-3 py-2`

This resulted in a visually bloated form that looked unprofessional.

**Symptoms:**
- Form fields looked disproportionately large
- Too much vertical scrolling needed
- Form didn't fit cohesively in half the screen

**Root Cause:** Using pre-built components designed for standard pages without adjusting sizing for compact layout.

**Solution Implemented:**
- Reduced internal padding in Card: `p-4` → `p-3`
- Reduced form field wrapper spacing: `space-y-4` → `space-y-2`
- Reduced button sizing: `px-3 py-2` → `px-2 py-1.5` with `text-xs`
- Removed Card component wrapper entirely, built inline with minimal padding

```vue
<!-- OLD: Using Card component -->
<Card :title="`${stageName} - Information`" :subtitle="stageDescription">
  <form class="space-y-4">...</form>
</Card>

<!-- NEW: Direct div with minimal padding -->
<div class="bg-white rounded-lg border border-gray-200">
  <div class="px-3 py-2 border-b border-gray-200">
    <h3 class="font-semibold text-base text-gray-900">{{ stageName }} - Information</h3>
    <p class="text-xs text-gray-600 mt-0.5">{{ stageDescription }}</p>
  </div>
  <div class="p-3">
    <form class="space-y-2">...</form>  <!-- Reduced spacing -->
  </div>
</div>
```

**Lesson for Next Stages:** Be aggressive with padding reduction. When building compact layouts, every pixel counts. Test on actual design to verify spacing looks balanced.

---

#### Obstacle 3: Typography Size Mismatch
**Problem:** Used standard typography sizes (`text-lg`, `text-sm`, `text-base`) which looked too large in the compact layout. Headers consumed too much vertical space.

**Symptoms:**
- Section headers took 30-40px of vertical space
- Form looked cluttered and hard to scan
- Not enough fields visible without scrolling

**Root Cause:** Copied typography from standard form pages without considering the space constraints.

**Solution Implemented:**
```
Page Title:        text-lg font-bold      (was text-3xl)
Section Header:    text-sm font-medium    (was text-lg)
Form Label:        text-xs               (was text-sm)
Helper Text:       text-xs               (no change)
Button Text:       text-xs               (was text-sm)
```

**Lesson for Next Stages:** Typography sizing is not just about aesthetics—it directly impacts layout density. Reduce sizes more aggressively than you think when building compact layouts.

---

### 15.2 Document Handling Issues

#### Obstacle 4: Document Lock Logic Confusion
**Problem:** Multiple iterations on document lock logic—kept changing the business rule:
1. First attempt: Lock based on `document.status` (DRAFT vs ACTIVE) 
2. Second attempt: Lock based on `caseStatus < 2`
3. Final implementation: Lock based on `caseStatus === 1`

This caused confusion in both backend and frontend code.

**Symptoms:**
- Remove button appearing/disappearing unexpectedly
- Lock icon showing at wrong times
- Inconsistent behavior across test cases

**Root Cause:** Business logic was not clearly defined upfront. Multiple clarification rounds were needed with product owner.

**Solution Implemented:**
```javascript
// CORRECT: Based on case_status_id, NOT document status
// Remove button shows when BOTH conditions true:
// 1. caseStatus === 1 (case still in CREATED/DRAFT state)
// 2. submissionComplete === false (current form not yet submitted)

<button v-if="caseStatus === 1 && !submissionComplete"
  @click.stop="removeFile(file.id)"
  ...>
  🗑️ Remove
</button>

// Lock icon shows when EITHER condition true:
// 1. caseStatus > 1 (case already SUBMITTED or beyond)
// 2. OR submissionComplete === true (form submission complete)

<span v-else class="...">
  🔒 Locked
</span>
```

**Documented Rule:**
```
caseStatus values:
- 1 = CREATED → User can edit and remove documents
- 2 = SUBMITTED → All documents locked, no removal allowed
- 3+ = ARCHIVED/REVIEWED → Still locked
```

**Lesson for Next Stages:** 
1. Get crystal clear business logic BEFORE coding
2. Document the exact conditions/rules in code comments
3. Use semantic variable names that clearly indicate what they control
4. Test all state combinations (locked/unlocked, submitted/draft)

---

#### Obstacle 5: Upload Progress Bar Not Showing
**Problem:** Used Fetch API initially which doesn't support upload progress events. Progress bar was stuck at 0% or never showed at all.

**Symptoms:**
- Upload progress bar remained at 0%
- User had no feedback during file upload
- No way to know if upload was happening or stuck

**Root Cause:** Fetch API doesn't expose progress events. Needed XMLHttpRequest instead.

**Solution Implemented:**
```javascript
// WRONG: Using Fetch API (no progress support)
const response = await fetch('/api/documents', {
  method: 'POST',
  body: formData
})

// CORRECT: Using XMLHttpRequest (supports progress)
const xhr = new XMLHttpRequest()

xhr.upload.addEventListener('progress', (e) => {
  if (e.lengthComputable) {
    const percentComplete = Math.round((e.loaded / e.total) * 100)
    uploadProgress.value = percentComplete
  }
})

xhr.open('POST', '/api/documents')
xhr.send(formData)
```

**Lesson for Next Stages:** For file uploads, always use XMLHttpRequest, not Fetch API. Fetch API does not support progress tracking, which is essential for user experience with large files.

---

### 15.3 Confirmation Dialog Issues

#### Obstacle 6: Single Confirmation Dialog for Multiple Actions
**Problem:** Initially had separate state for different confirmation dialogs (deleteConfirm, submitConfirm, draftConfirm). This led to code duplication and complexity.

**Symptoms:**
- Multiple boolean flags for different dialogs
- Duplicated dialog template code
- Hard to maintain and test all scenarios

**Root Cause:** Each action had its own dialog state instead of using a unified pattern.

**Solution Implemented:**
```javascript
// UNIFIED approach: Single dialog with dynamic content
const showConfirmDialog = ref(false)
const confirmDialogTitle = ref('')
const confirmDialogMessage = ref('')
const confirmDialogVariant = ref('default') // 'default' or 'danger'
const pendingAction = ref(null) // Identifies which action to execute

// For ANY action:
const handleAction = () => {
  confirmDialogTitle.value = 'Delete Document'
  confirmDialogMessage.value = 'Are you sure...'
  confirmDialogVariant.value = 'danger'
  pendingAction.value = 'delete'
  showConfirmDialog.value = true
}

// Single handler for all confirmations:
const handleConfirm = async () => {
  if (pendingAction.value === 'delete') {
    await confirmDelete()
  } else if (pendingAction.value === 'submit') {
    await executeSubmitForm()
  } else if (pendingAction.value === 'draft') {
    await executeSaveDraft()
  }
  
  // Reset
  showConfirmDialog.value = false
  pendingAction.value = null
}
```

**Lesson for Next Stages:** Use unified confirmation dialog pattern with `pendingAction` dispatcher. Much cleaner than separate dialogs for each action.

---

#### Obstacle 7: Confirmation Dialog Not Closing Properly
**Problem:** Dialog state (`showConfirmDialog`) wasn't being reset in all code paths, causing dialog to stay open or appear stuck after user interaction.

**Symptoms:**
- Dialog remaining visible after cancel
- Multiple dialogs overlaying
- User had to close modal multiple times

**Root Cause:** Forgot to reset `showConfirmDialog = false` and `pendingAction = null` in some async operations, particularly in error paths.

**Solution Implemented:**
```javascript
// Ensure dialog state reset in ALL paths:
const confirmDelete = async () => {
  try {
    await fetch(`/api/documents/${fileId}`, { method: 'DELETE' })
    uploadedFiles.value = uploadedFiles.value.filter(f => f.id !== fileId)
    toastRef.value?.addToast('Deleted', 'Document removed', 'success', 2000)
  } catch (error) {
    toastRef.value?.addToast('Delete Error', error.message, 'error', 4000)
  } finally {
    // IMPORTANT: Reset in finally block so it runs regardless of success/error
    showConfirmDialog.value = false
    pendingFileId.value = null
    pendingAction.value = null
  }
}

// Also have separate cancel handler:
const cancelDelete = () => {
  showConfirmDialog.value = false
  pendingFileId.value = null
  pendingAction.value = null
}
```

**Lesson for Next Stages:** Always use `finally` block to reset dialog state. Never rely on happy path only. Test cancel, confirm, and error paths separately.

---

### 15.4 Form Submission & Validation Issues

#### Obstacle 8: Form Fields Not Disabled After Submission
**Problem:** After successful form submission, form fields remained editable. User could modify data after submission was complete, causing confusion.

**Symptoms:**
- User editing fields after seeing "Success" message
- Form state inconsistent with submission state
- Potential data loss if user modified and left page

**Root Cause:** Didn't bind `:disabled` attribute to `submissionComplete` state variable on all form inputs.

**Solution Implemented:**
```vue
<!-- ALL inputs must have this disabled binding: -->
<FormField
  :disabled="field.readonly || submissionComplete"
  ...
/>

<textarea
  :disabled="submissionComplete"
  ...
/>

<select
  :disabled="submissionComplete"
  ...
/>

<!-- AND disable file upload input: -->
<input
  type="file"
  :disabled="uploadProgress > 0 || submissionComplete"
  ...
/>
```

**AND applied disabled styling:**
```vue
class="disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
```

**Lesson for Next Stages:** Implement the "read-only after submission" pattern consistently:
1. Track submission state with `submissionComplete` ref
2. Bind to all inputs: `:disabled="submissionComplete"`
3. Apply visual disabled styles
4. Test that every field type (text, select, textarea, etc.) responds correctly

---

#### Obstacle 9: Submit Button Disabled Condition Too Restrictive
**Problem:** Submit button disabled condition prevented re-submission after errors:
```javascript
:disabled="submitting || isLoading || (caseStatus && caseStatus > 1)"
```

If a submission failed with error, user couldn't retry because no path to reset `submitting` flag in error scenario.

**Symptoms:**
- Submit button stuck disabled after error
- User couldn't retry submission
- No error recovery mechanism

**Root Cause:** Didn't account for error cases when designing state machine.

**Solution Implemented:**
```javascript
// Always reset in try-catch-finally:
const executeSubmitForm = async () => {
  submitting.value = true
  
  try {
    const response = await fetch(...)
    if (!response.ok) throw new Error('...')
    // Success handling
    submissionComplete.value = true
  } catch (error) {
    toastRef.value?.addToast('Error', error.message, 'error', 5000)
    // DON'T set submissionComplete - let user retry
  } finally {
    submitting.value = false  // ALWAYS reset here
  }
}
```

**Lesson for Next Stages:** Design your async state machine to allow retries. Don't lock user out after first error. Use finally blocks to ensure cleanup happens.

---

#### Obstacle 10: Document Requirement Not Validated
**Problem:** Allowed form submission without uploading any documents. Backend would accept it but frontend didn't enforce the requirement.

**Symptoms:**
- Form submitting with no documents
- Inconsistent state with backend expectations
- User confused why submission "succeeded" but no documents saved

**Root Cause:** Validation only checked form fields, not document count.

**Solution Implemented:**
```javascript
const submitForm = async () => {
  // 1. Validate form fields
  if (!validateForm()) return
  
  // 2. IMPORTANT: Validate at least one document uploaded
  if (uploadedFiles.value.length === 0) {
    toastRef.value?.addToast(
      'Missing Documents',
      'Please upload at least one supporting document before submitting',
      'error',
      5000
    )
    return  // Don't proceed
  }
  
  // 3. Only then show confirmation dialog
  confirmDialogTitle.value = '...'
  showConfirmDialog.value = true
}
```

**Lesson for Next Stages:** Document uploads are part of form validation. Check document count before allowing submission. Consider if all stages require documents or if some are optional.

---

### 15.5 PDF Viewer Issues

#### Obstacle 11: PDF Not Loading in Iframe
**Problem:** PDF viewer iframe was created but not loading PDFs. Result was blank right panel.

**Symptoms:**
- Right panel showing empty state even when file selected
- No PDF visible
- No error messages in console

**Root Cause:** 
1. Created route `/api/documents/{id}/download` with `attachment` disposition (force download)
2. Needed separate route `/api/documents/{id}/view` with `inline` disposition
3. Browser iframe requires inline disposition to display

**Solution Implemented:**
```php
// In DocumentController.php - Add NEW view() method
public function view(Document $document)
{
  // ... validation ...
  
  // Return inline (display in browser) not download
  return response()->file(
    Storage::path($document->file_path),
    [
      'Content-Type' => $document->file_mime_type,
      'Content-Disposition' => 'inline; filename="' . $document->original_filename . '"'
    ]
  );
}
```

```javascript
// In Vue - Use view endpoint, not download
const viewDocument = (fileId, fileName) => {
  pdfViewerUrl.value = `/api/documents/${fileId}/view`  // Not /download
}
```

```vue
<!-- In template -->
<iframe :src="pdfViewerUrl" class="w-full h-full border-none"></iframe>
```

**Lesson for Next Stages:** 
1. Understand HTTP Content-Disposition header: `attachment` = download, `inline` = display
2. Create separate endpoints if you need both download and view functionality
3. Test PDF viewing in actual browser before declaring done
4. Browser native PDF viewer works with iframes using inline disposition

---

#### Obstacle 12: PDF Viewer Size Issues
**Problem:** PDF viewer iframe not filling the available space. Had extra margins/padding causing squished layout.

**Symptoms:**
- Gray space around PDF in iframe
- PDF not using full right panel height
- Visual imbalance in 50-50 split

**Root Cause:** CSS sizing issues on iframe container and parent flexbox.

**Solution Implemented:**
```vue
<!-- RIGHT SIDE: PDF Viewer Container -->
<div class="w-1/2 bg-gray-100 flex flex-col border-l border-gray-300">
  <!-- Header -->
  <div class="flex-1 flex items-center justify-center overflow-hidden">
    <!-- IMPORTANT CSS: w-full h-full, no margin/padding -->
    <div v-if="selectedPdfId" class="w-full h-full">
      <iframe
        :src="pdfViewerUrl"
        class="w-full h-full border-none"
        title="PDF Viewer"
      ></iframe>
    </div>
  </div>
</div>
```

**Key CSS:**
- Parent flex container: `flex flex-col` ensures children can stretch
- Viewer container: `flex-1` to fill remaining space
- Iframe: `w-full h-full` to fill container exactly
- No padding/margin on iframe or direct parent

**Lesson for Next Stages:** For fullscreen/maximized elements, pay attention to:
1. Parent must be `flex flex-col`
2. Container must be `flex-1` or `h-full`
3. Element must be `w-full h-full`
4. Remove all padding/margin
5. Test actual browser rendering, not just visual inspection

---

### 15.6 State Management Issues

#### Obstacle 13: Race Conditions in Async Operations
**Problem:** If user clicked upload multiple times quickly, multiple XMLHttpRequest instances would be created, causing progress bar to jump around and files to upload multiple times.

**Symptoms:**
- Upload progress bar jumping (0% → 100% → 50% → 100%)
- Files uploaded multiple times
- Inconsistent uploadedFiles array

**Root Cause:** No prevention of concurrent uploads. Each button click created new XMLHttpRequest immediately.

**Solution Implemented:**
```javascript
// Prevent concurrent uploads - disable input during upload
<input
  type="file"
  :disabled="uploadProgress > 0 || submissionComplete"  // Disable while uploading
  ...
/>

// Also track in-progress state
const uploadProgress = ref(0)

const handleFileUpload = async (event) => {
  const files = event.target.files
  
  for (let file of files) {
    if (!validateFile(file)) continue
    
    // This sets uploadProgress > 0, disabling further uploads
    await uploadFile(file)
    
    // Progress reaches 0 only after completion
  }
  
  event.target.value = '' // Reset input
}
```

**Lesson for Next Stages:** Prevent user from triggering same async action multiple times:
1. Disable input/button while operation in progress
2. Use meaningful state variable (`uploadProgress > 0`)
3. Only re-enable when operation truly complete
4. Test rapid clicking to ensure no race conditions

---

#### Obstacle 14: Form Validation State Not Clearing
**Problem:** Form validation errors persisted after user fixed them and refocused. Old errors not clearing from `formErrors` object.

**Symptoms:**
- Red error messages staying even after user entered valid data
- User confusion about whether field is still invalid
- Visual error state not matching actual validation state

**Root Cause:** `formErrors` was only cleared in `validateForm()` at START of validation, but not cleared on individual field changes.

**Solution Implemented:**
```javascript
// Option 1: Clear on field change
watch(() => formData[field.key], () => {
  // Clear this field's error when user modifies it
  if (formErrors[field.key]) {
    delete formErrors[field.key]
  }
})

// Option 2: Clear all at validation start (current implementation)
const validateForm = () => {
  // Clear ALL errors first
  Object.keys(formErrors).forEach(key => {
    delete formErrors[key]
  })
  
  let isValid = true
  
  // Then validate and populate only active errors
  // ...
  
  return isValid
}
```

**Lesson for Next Stages:** Form error UX is important. Consider clearing errors:
1. On field change (real-time feedback)
2. OR only validate on submit (less intrusive)
3. Be consistent across all fields
4. Test that correcting an error actually removes the error message

---

### 15.7 Parent/Child Component Communication

#### Obstacle 15: NextStageId Undefined Issues
**Problem:** After form submission, "Continue to Stage X" button showed undefined stage ID because `nextStageId` prop was sometimes not passed from parent.

**Symptoms:**
- Button showing "Continue to Stage undefined"
- User can't navigate to next stage
- Need to check browser console to see issue

**Root Cause:** Some parent components didn't pass `nextStageId` prop. Component had no fallback logic.

**Solution Implemented:**
```javascript
// Use computed property with fallback logic
const computedNextStageId = computed(() => {
  // Use prop if provided, otherwise calculate
  return props.nextStageId || (props.stageId < 12 ? props.stageId + 1 : null)
})
```

```vue
<!-- In template, use computed value -->
<div v-if="submissionComplete && computedNextStageId" class="flex gap-1">
  <Button @click="continueToNextStage" variant="primary">
    Continue to Stage {{ computedNextStageId }} →
  </Button>
</div>

<script>
const continueToNextStage = () => {
  if (computedNextStageId.value) {
    router.push(`/tax-cases/${props.caseId}/workflow/${computedNextStageId.value}`)
  }
}
</script>
```

**Lesson for Next Stages:**
1. Define smart defaults for props that might be missing
2. Use computed properties for calculated/fallback values
3. Always validate before using prop values in navigation
4. Test with and without each optional prop
5. Log warnings if required data is missing

---

### 15.8 Notification System Issues

#### Obstacle 16: Mixed Alert and Toast Notifications
**Problem:** Form was mixing two different notification systems - Alert components for some messages and Toast notifications for others. This created inconsistent user experience with different behaviors (some modals, some auto-dismiss).

**Symptoms:**
- Pre-filled data showing Alert component (modal-style, requires click to dismiss)
- Submission showing Toast (auto-dismiss)
- Users confused by inconsistent notification patterns
- Alert blocking form interaction while Toast allows continued work

**Root Cause:** Historical development - started with Alert components, gradually migrated to Toast but didn't complete migration everywhere.

**Solution Implemented:**
```javascript
// WRONG: Mixing notification systems
onMounted(() => {
  if (props.preFilledMessage) {
    // Using Alert - modal-style, blocks interaction
    showAlert.value = true
    alertMessage.value = props.preFilledMessage
  }
})

const executeSubmitForm = () => {
  // Using Toast - auto-dismiss, allows continued work
  toastRef.value?.addToast('Form Submitted', 'Data saved successfully', 'success', 5000)
}

// CORRECT: Standardized to Toast everywhere
onMounted(() => {
  if (props.preFilledMessage) {
    // Using Toast - consistent auto-dismiss behavior
    toastRef.value?.addToast('Form Pre-filled', props.preFilledMessage, 'success', 5000)
  }
})

const executeSubmitForm = () => {
  // Same Toast system
  toastRef.value?.addToast('Form Submitted', 'Data saved successfully', 'success', 5000)
}
```

**Implementation Checklist:**
- [ ] Remove all Alert component usage from StageForm.vue
- [ ] Convert ALL notifications to Toast: pre-filled data, submit success, draft save, errors, etc.
- [ ] Define consistent Toast duration: Success 3-5s, Error 5s, Info 4s
- [ ] Toast types: only 'success' (green) and 'error' (red)
- [ ] Ensure Toast component ref (`toastRef`) available and properly initialized
- [ ] Test pre-filled message appears as Toast on component mount
- [ ] Test all actions (submit, draft, delete, upload) show Toast notifications

**Lesson for Next Stages:** 
1. Standardize on ONE notification system for entire form
2. Toast is better for form-heavy UX (non-blocking, auto-dismiss)
3. Alert is better for critical system-level messages (requires acknowledgment)
4. Define notification standards document-wide before coding
5. Audit all message code paths to ensure consistency

---

#### Obstacle 17: Notification Message Language Inconsistency
**Problem:** Notification messages mixed Indonesian and English, and messages didn't differentiate between similar actions (submit vs save draft).

**Symptoms:**
- Pre-filled toast showing Indonesian: "Form sudah diisi sebelumnya"
- Submit toast showing English: "Form Submitted"
- Users confused about language standards
- "Save Draft" and "Submit" messages too similar, users unsure which action they confirmed

**Root Cause:** Messages added incrementally without language/style guidelines. Confirmation dialogs and toasts not coordinated.

**Solution Implemented:**
```javascript
// WRONG: Mixed languages and unclear messages
toastRef.value?.addToast('Data Tersimpan', 'Form sudah disimpan ke database', 'success', 5000)  // Indonesian

// Also unclear - both say something about saving:
// Confirmation 1: "Simpan form ini?"  // Indonesian
// Confirmation 2: "Save as draft?"     // English

// CORRECT: All English with clear differentiation
toastRef.value?.addToast('Form Submitted', 'Stage 1 berhasil disubmit. Lanjut ke stage berikutnya...', 'success', 5000)

toastRef.value?.addToast('Draft Saved', 'Form disimpan sebagai draft. Anda dapat melanjutkan editing kapan saja.', 'success', 5000)

// Confirmation dialogs - distinct messages:
// Submit dialog:
// Title: "Submit Form"
// Message: "You are about to submit this form to the system. Your data will be saved and you will proceed to the next stage. This action cannot be undone."

// Draft dialog:
// Title: "Save as Draft"  
// Message: "Your form will be saved as a draft. You can continue editing it anytime without needing to complete all required fields."
```

**Language Standard Rules:**
- **Toast Titles:** English only (short 1-2 words): "Form Submitted", "Draft Saved", "Error", "Upload Complete"
- **Toast Messages:** Can be bilingual (English + Indonesian): Explain what happened in user's language
- **Confirmation Titles:** English only
- **Confirmation Messages:** English only (these are critical, must be unambiguous)
- **Form Labels/Fields:** Bilingual where applicable, but consistent across stages
- **Error Messages:** English only for technical errors, bilingual for user-facing errors

**Implementation:**
```javascript
// Define message constants
const MESSAGES = {
  SUBMIT_TITLE: 'Form Submitted',
  SUBMIT_SUCCESS: 'Stage X berhasil disubmit. Lanjut ke stage berikutnya.',
  DRAFT_TITLE: 'Draft Saved',
  DRAFT_SUCCESS: 'Form disimpan sebagai draft. Anda dapat melanjutkan editing kapan saja.',
  PREFILL_TITLE: 'Form Pre-filled',
  PREFILL_MESSAGE: 'Beberapa field sudah diisi berdasarkan data sebelumnya.',
  UPLOAD_ERROR: 'Only PDF files are allowed. Maximum size: 10MB.',
  DELETE_ERROR: 'Failed to delete document. Please try again.'
}

// Use constants in code
toastRef.value?.addToast(MESSAGES.SUBMIT_TITLE, MESSAGES.SUBMIT_SUCCESS.replace('X', stageId), 'success', 5000)
```

**Lesson for Next Stages:**
1. Define language and message standards BEFORE coding
2. Keep technical messages English-only
3. Keep user messages bilingual when possible (explain in user's language)
4. Make messages distinct between different actions
5. Use message constants to avoid inconsistency
6. Audit all messages for consistency before launch

---

#### Obstacle 18: Toast Duration Not Optimized for Message Length
**Problem:** Short success messages (2-3 words) with 5-second duration felt too long and cluttered the screen. Long error messages needed more time to read but had standard 3-second duration.

**Symptoms:**
- Success toast lingering unnecessarily ("Deleted!" stays 5s)
- Error toast disappearing before user could read ("Database connection failed. Check your internet and retry." gone in 3s)
- User missing important information
- Inconsistent toast visibility across different message types

**Root Cause:** Used fixed duration for all toast types without considering message length.

**Solution Implemented:**
```javascript
// WRONG: Fixed duration regardless of message length
toastRef.value?.addToast('Deleted', 'File removed', 'success', 5000)  // Too long
toastRef.value?.addToast('Error', 'Database connection failed. Please check...', 'error', 3000)  // Too short

// CORRECT: Dynamic duration based on message length and type
const showToast = (title, message, type, durationMs = null) => {
  // Calculate optimal duration if not provided
  if (!durationMs) {
    const totalLength = (title + message).length
    if (type === 'success') {
      durationMs = totalLength > 50 ? 4000 : 2500  // Short success: 2.5s, long: 4s
    } else if (type === 'error') {
      durationMs = totalLength > 80 ? 7000 : 5000  // Short error: 5s, long: 7s
    } else {
      durationMs = 4000  // Info: standard 4s
    }
  }
  
  toastRef.value?.addToast(title, message, type, durationMs)
}

// Usage
showToast('Submitted', 'Stage 1 completed successfully', 'success')  // Auto 2.5s
showToast('Error', 'Database connection failed. Please check your internet connection and try again.', 'error')  // Auto 7s
showToast('Error', 'Upload failed', 'error')  // Auto 5s
```

**Duration Guidelines:**
| Type | Short Message | Long Message | Guideline |
|------|---|---|---|
| Success | 2-3 seconds | 3-4 seconds | Users happy, quick dismiss OK |
| Error | 4-5 seconds | 6-7 seconds | Users need time to read & act |
| Info/Warning | 3-4 seconds | 4-5 seconds | Informational, moderate duration |

**Lesson for Next Stages:**
1. Toast duration should correlate with message length
2. Success can be shorter (user already knows it worked)
3. Error should be longer (user needs to read and potentially act)
4. Provide manual override ability (let parent component pass custom duration)
5. Test actual reading time - some users need more time than others

---

## 16. Prevention Checklist

**Before implementing next stage, ensure you've learned from these obstacles:**

### Layout & Styling Checks
- [ ] Layout: Adjust padding to `px-2 py-2` in App.vue for workflow routes
- [ ] Spacing: Use `space-y-1`, `p-2`, `gap-1` (minimal spacing throughout)
- [ ] Typography: Keep headers small (`text-base`), use `text-xs` for buttons
- [ ] Container: Use 50-50 split with `w-1/2` and `flex` layout
- [ ] Remove max-width constraints for workflow routes

### Document Management Checks
- [ ] Documents: Clear business logic documented in comments about lock behavior
- [ ] Lock logic: Remove button only when `caseStatus === 1 && !submissionComplete`
- [ ] Lock icon: Show when documents locked (caseStatus > 1 OR submissionComplete)
- [ ] Upload: Use XMLHttpRequest for progress tracking, never Fetch API
- [ ] PDF upload validation: PDF only, max 10MB file size
- [ ] Validation: Check documents uploaded before form submission

### Form Submission & Validation Checks
- [ ] Confirmation: Use unified dialog pattern with `pendingAction` dispatcher
- [ ] Submission: Always reset state in `finally` block, not just `try`
- [ ] Disabled: Bind `:disabled="submissionComplete"` to ALL form inputs
- [ ] Error messages: Clear, distinct messages for submit vs draft actions
- [ ] Error recovery: Allow retries after submission errors (don't lock user out)
- [ ] Validation state: Clear errors when user modifies field or clear all at start of validation

### PDF Viewer Checks
- [ ] PDF: Use `/view` endpoint with `inline` disposition, not `/download`
- [ ] Iframe: Set `w-full h-full` with no padding/margin on container
- [ ] Parent: Use `flex flex-col` to allow children to stretch properly
- [ ] Empty state: Show helpful message when no document selected

### State Management Checks
- [ ] Race Conditions: Disable inputs during async operations (uploadProgress > 0)
- [ ] Computed Props: Add fallback logic for optional props like `nextStageId`
- [ ] Input reset: Always reset file input after upload completes
- [ ] Cleanup: Reset all modal/confirmation state in finally blocks

### Notification Checks
- [ ] Standardization: Use ONLY Toast notifications, never Alert components
- [ ] Language: English titles, bilingual messages (English + Indonesian)
- [ ] Differentiation: Distinct messages for submit vs draft vs pre-filled
- [ ] Duration: Success 2-4s, Error 5-7s, Info 4s (adjust for message length)
- [ ] Pre-filled: Show Toast in onMounted hook if preFilledMessage prop provided
- [ ] All actions: Ensure submit, draft save, upload, delete all show Toast feedback

### Testing Checks
- [ ] Test all state combinations: submitted/draft, locked/unlocked, uploading/idle
- [ ] Test error paths: submission error, upload error, delete error
- [ ] Test rapid interactions: double-click submit, rapid file uploads
- [ ] Test keyboard navigation: Tab through fields, Enter submits, Escape closes
- [ ] Test accessibility: Color contrast, disabled states visually clear
- [ ] Browser testing: PDF loading, file uploads, responsive layout

---

## 17. Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.1 | Jan 13, 2026 | Added Obstacles 16-18 (notification system issues) and expanded Prevention Checklist with detailed category organization |
| 1.0 | Jan 13, 2026 | Initial refined template based on StageForm.vue with Obstacles 1-15 |

---

## 18. Author & Support

**Created by:** AI Assistant  
**For:** PORTAX Tax Case Management System  
**Framework:** Vue.js 3 + Laravel 11  
**Last Updated:** January 13, 2026  
**Total Obstacles Documented:** 18  
**Total Lessons Learned:** 18

For questions or updates to this template, refer to the base `StageForm.vue` implementation or contact the development team.
