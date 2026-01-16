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
        <span class="text-base shrink-0">📄</span>
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
      
      <div class="flex items-center space-x-0.5 shrink-0 ml-1">
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
  
  <Button type="button" @click="saveDraft" variant="secondary" 
    :disabled="submitting || isLoading || (caseStatus && caseStatus > 1)" 
    class="text-xs px-2 py-1.5">
    Save as Draft
  </Button>
  
  <Button type="button" @click="$router.back()" variant="secondary" 
    :disabled="isLoading" 
    class="text-xs px-2 py-1.5">
    Cancel
  </Button>
</div>
```

**Rules:**
- **Gap:** `gap-1` (minimal spacing)
- **Sizing:** `text-xs px-2 py-1.5` (compact)
- **Button Types - CRITICAL:**
  - Submit button: `type="submit"` (triggers form submission and calls submitForm handler)
  - Draft button: `type="button"` (calls saveDraft handler ONLY, does NOT trigger form submission)
  - Cancel button: `type="button"` (calls router.back ONLY, does NOT trigger form submission)
- **Why This Matters:** Without explicit `type="button"`, buttons inside `<form>` default to `type="submit"` behavior, causing unintended form submission
- **Disabled Conditions:**
  - `:disabled="submitting || isLoading || (caseStatus && caseStatus > 1)"`
  - Prevent double-submit
  - Prevent edit when case already submitted
- **Button Order:** Submit → Draft → Cancel
- **Loading State:** Show "Submitting..." text

**Common Mistake (See Obstacle 19):**
```vue
<!-- ❌ WRONG - Save as Draft implicitly type="submit" -->
<Button @click="saveDraft" ...>Save as Draft</Button>

<!-- ✅ CORRECT - Explicit type="button" -->
<Button type="button" @click="saveDraft" ...>Save as Draft</Button>
```

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
- [ ] **Test fieldsDisabled state when caseStatus > 1** (Obstacle 28)
  - [ ] Load case with caseStatus = 1 → Fields editable
  - [ ] Load case with caseStatus = 2 → Fields disabled (gray, unclickable)
  - [ ] Verify ALL field types disabled (text, select, textarea, checkbox, radio, date, file)
  - [ ] Verify disabled styling applied (gray background, gray text, not-allowed cursor)
- [ ] Implement proper error display
- [ ] Test keyboard navigation (Tab, Enter)
- [ ] Test form validation
- [ ] **If form has period field linked to case number** (Obstacle 27):
  - [ ] Implement case number generation from period fields (year + month)
  - [ ] Test period change updates case number immediately (watcher working)
  - [ ] Verify case number follows format: [PREFIX][YY][MMM][SUFFIX]
  - [ ] Test with all 12 months - abbreviations correct (Jan, Feb, Mar, etc)
  - [ ] Test case number refresh after form submit (Obstacle 29)
  - [ ] Verify backend response includes updated case_number
  - [ ] Verify case number persists after page refresh

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
- [ ] **Form refresh after submit** (Obstacle 29):
  - [ ] @submit event listener triggers refreshTaxCase() or equivalent
  - [ ] @saveDraft event listener triggers refreshTaxCase() or equivalent
  - [ ] After submit, fetch /api/tax-cases/{id} to get latest data
  - [ ] Update all reactive values from API response:
    - [ ] prefillData (period_id, currency_id, etc)
    - [ ] caseNumber (important if backend updates it)
    - [ ] caseStatus (important for field disabling)
  - [ ] Verify case data matches backend after refresh
  - [ ] If period changed, verify case number updated from API response

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

### Issue: Form fields remain editable when case is locked (caseStatus > 1)

**Solution (Obstacle 28):**
1. Verify `fieldsDisabled` computed property exists:
   ```javascript
   const fieldsDisabled = computed(() => {
     return props.caseStatus > 1
   })
   ```
2. Check ALL form inputs have disabled binding:
   ```vue
   :disabled="submissionComplete || fieldsDisabled"  <!-- for each field -->
   ```
3. Verify disabled styling applied to all inputs:
   ```vue
   class="disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
   ```
4. Test with actual case that has caseStatus > 1 (not just form submission)
5. Check browser DevTools - verify :disabled binding shows true

### Issue: Case number doesn't update when period changes (Obstacle 27)

**Solution:**
1. Verify watcher on period_id exists:
   ```javascript
   watch(() => prefillData.value.period_id, (newPeriodId) => {
     const newCaseNum = generateCaseNumberFromPeriod(newPeriodId)
     caseNumber.value = newCaseNum
   })
   ```
2. Check period data loaded includes `year` and `month` fields
3. Verify `originalCaseNumber` stored in onMounted
4. Check month mapping is correct (1='Jan', 2='Feb', 3='Mar', etc)
5. Verify case number pattern is [PREFIX][YY][MMM][SUFFIX]
6. Test by selecting different period - case number should update immediately

### Issue: Case number reverts after form submit (Obstacle 29)

**Solution:**
1. Verify submit listeners on StageForm component:
   ```vue
   <StageForm
     @submit="refreshTaxCase"
     @saveDraft="refreshTaxCase"
     ...
   />
   ```
2. Check refreshTaxCase() function exists and fetches from API:
   ```javascript
   const refreshTaxCase = async () => {
     const res = await fetch(`/api/tax-cases/${caseId}`)
     const data = await res.json()
     // Update all reactive values from API response
   }
   ```
3. Verify API response includes `case_number` field
4. Check Network tab - see what API actually returns
5. If backend updates case_number automatically:
   - Verify it's returned in API response
   - Update frontend: `caseNumber.value = data.case_number`
6. If frontend generates case_number:
   - refreshTaxCase must also update prefillData.period_id
   - This triggers watcher to regenerate case number

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

#### Obstacle 19: Non-Submit Buttons Triggering Form Submit Event
**Problem:** "Save as Draft" and "Cancel" buttons were placed inside `<form @submit.prevent="submitForm">` without explicit `type="button"` attribute. This caused them to implicitly act as submit buttons, triggering the form's submit event handler even though their `@click` handlers were different.

---

#### Obstacle 20: Missing Model Relationships Referenced in API Responses
**Problem:** Backend code tried to load relationship `appealDecision` on TaxCase model that was never defined. This caused `RelationNotFoundException` when API attempted to load related data.

**Symptoms:**
- API error: "Call to undefined relationship [appealDecision] on model [App\Models\TaxCase]"
- 500 error response when loading tax case details
- Cannot fetch revisions or related data

**Root Cause:** 
1. Frontend expected `appealDecision` relationship but it was never created in model
2. Controller tried to load non-existent relationship
3. Backend response incomplete

**Solution Implemented:**
```php
// In app/Models/TaxCase.php - Add missing relationship
public function appealDecision(): HasOne
{
    return $this->hasOne(AppealDecision::class);
}
```

**Prevention Rule:**
1. Before using any relationship in API response (with/load), ensure it's defined in model
2. Match relationship name EXACTLY as written in with() clause
3. Audit all controller load() calls against model relationships
4. Add new relationships alongside existing ones, group by type (HasOne, HasMany, BelongsTo)

**Lesson for Next Stages:** 
- Always define relationships before referencing them
- Check Model relationships list before writing API code
- Test relationship loading with actual data to catch undefined relationships early
- Use Laravel debugbar or logs to verify relationships are loaded

---

#### Obstacle 21: Authorization Policy Overly Complex
**Problem:** Implemented complex RevisionPolicy with multiple role checks, case ownership checks, entity matching, etc. Despite this complexity, it failed mysteriously with 403 Forbidden errors that were hard to debug.

**Symptoms:**
- 403 Forbidden responses with minimal error information
- Authorization succeeds sometimes, fails other times
- Difficult to debug which condition was failing
- Complex logic hard to test thoroughly

**Root Cause:**
1. Built multi-layered authorization logic (before() method + viewAny() method)
2. Laravel's `before()` only works with single-parameter methods, not methods with model parameters
3. Role name case sensitivity issues ('Administrator' vs 'ADMIN')
4. Too many conditional branches, hard to test all combinations

**Solution Implemented:**
Instead of complex policy with multiple conditions, use **simple authentication check**:
```php
// Simple approach: User just needs to be authenticated
public function listRevisions(TaxCase $taxCase): JsonResponse
{
    // Ensure user is authenticated
    if (!auth()->user()) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }
    
    // Load role relationship
    $user = auth()->user();
    if (!$user->relationLoaded('role')) {
        $user->load('role');
    }
    
    // That's it - allow all authenticated users to view revisions
    // Actual access control happens at tax case level (can they access the case?)
    
    $revisions = $taxCase->revisions()
        ->with(['requestedBy', 'approvedBy', 'submittedBy', 'decidedBy'])
        ->orderByDesc('created_at')
        ->get();
    
    return response()->json(['data' => $revisions, 'success' => true]);
}
```

**Why Simple is Better:**
1. **Easier to debug:** One simple condition to check
2. **Fewer edge cases:** No complex role/entity matching logic
3. **Safer layered approach:** Tax case access is already controlled elsewhere (routes/middleware), so additional authorization here is redundant
4. **Faster to audit:** Can quickly verify authentication is working

**Don't Use Policy For:**
- ❌ Revisions access (already protected by tax case access)
- ❌ General list endpoints (authentication sufficient)
- ❌ Resource-level checks that depend on multiple factors

**DO Use Policy For:**
- ✅ Sensitive operations (delete, approve, publish)
- ✅ Role-specific actions (only admin can edit, only holding can approve)
- ✅ Complex ownership/entity checks

**Lesson for Next Stages:**
1. Start with simple authentication, add complexity only if needed
2. Don't stack policies (before() + method-level) - use one clear path
3. Test policy with actual role data, not assumptions
4. Log authorization attempts for debugging
5. Consider if policy is needed at all - sometimes authentication is enough

---

#### Obstacle 22: XMLHttpRequest Event Listener Setup Order Issues
**Problem:** PDF file upload progress bar wasn't tracking, and unhandled promise rejections were occurring. The issue was in the order of XMLHttpRequest setup.

**Symptoms:**
- Upload progress bar stuck at 0%
- "Unhandled Promise Rejection: InvalidStateError: The object is in an invalid state" in console
- File uploads sometimes failing silently
- No error feedback to user

**Root Cause:**
```javascript
// WRONG ORDER: Error in load event handler not caught
xhr.upload.addEventListener('progress', (e) => { ... })

xhr.addEventListener('load', () => {
  // ❌ Throwing error directly, not properly rejecting promise
  throw new Error('...')  
})

xhr.addEventListener('error', () => { ... })

// ❌ Call open() AFTER adding listeners (sometimes listeners missed)
xhr.open('POST', '/api/documents')
xhr.send(formData)
```

Issues:
1. `throw` in event handler doesn't properly reject promise
2. Error handling in load event not wrapped in try-catch
3. Proper lifecycle: listeners THEN open THEN send

**Solution Implemented:**
```javascript
const uploadFile = async (file) => {
  return new Promise((resolve, reject) => {
    try {
      const formData = new FormData()
      // ... form data setup ...
      
      const xhr = new XMLHttpRequest()
      const fileId = `${file.name}-${Date.now()}`
      
      // STEP 1: Set up ALL event listeners FIRST
      xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
          const percentComplete = Math.round((e.loaded / e.total) * 100)
          uploadProgress.value = percentComplete
        }
      })
      
      xhr.addEventListener('load', () => {
        try {
          // ✅ Wrap handler logic in try-catch
          if (xhr.status >= 200 && xhr.status < 300) {
            const result = JSON.parse(xhr.responseText)
            uploadedFiles.value.push({ ... })
            resolve(result)
          } else {
            // ✅ Use reject(), not throw
            try {
              const errorData = JSON.parse(xhr.responseText)
              reject(new Error(errorData.message || 'Upload failed'))
            } catch (parseError) {
              reject(new Error('Upload failed with status ' + xhr.status))
            }
          }
        } catch (error) {
          // ✅ Catch any errors in load handler
          reject(error)
        }
      })
      
      xhr.addEventListener('error', () => {
        reject(new Error('Network error during upload'))
      })
      
      xhr.addEventListener('abort', () => {
        reject(new Error('Upload cancelled'))
      })
      
      // STEP 2: THEN call open()
      xhr.open('POST', '/api/documents')
      xhr.withCredentials = true
      
      // STEP 3: Set headers (after open, before send)
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
      if (csrfToken) {
        xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken)
      }
      
      // STEP 4: Finally send()
      xhr.send(formData)
      
    } catch (error) {
      reject(error)
    }
  })
}
```

**XMLHttpRequest Lifecycle (Critical Order):**
```
1. Create xhr = new XMLHttpRequest()
2. Attach event listeners (progress, load, error, abort)
3. Call xhr.open(method, url)
4. Set request headers
5. Call xhr.send(data)
```

**If you deviate from this order:**
- Listeners attached after open() might not trigger
- Calling open() again throws "InvalidStateError"
- Headers set after send() are ignored

**Lesson for Next Stages:**
1. **Always set up listeners BEFORE open()** - this is critical
2. **Never throw in event handlers** - use reject() for promises
3. **Wrap all handler logic in try-catch** - errors in handlers can be swallowed
4. **Test upload progress** - actually verify progress bar moves, not just exists
5. **Test error scenarios** - test network errors, server errors, client validation
6. **Test large files** - verify progress bar with files > 1MB to see actual progress events

---

#### Obstacle 23: Role Name Case Sensitivity in Authorization
**Problem:** Authorization checks assumed exact role name match ('ADMIN'), but database had role name as 'Administrator' with mixed case. This caused all authorization checks to fail silently.

**Symptoms:**
- 403 Forbidden error for user with Administrator role
- Error only appeared in logs with role name: "Administrator"
- Authorization worked for some operations, failed for others
- Difficult to debug because role was loaded correctly, just name didn't match

**Root Cause:**
```php
// WRONG: Assumes exact case match
if ($user->hasRole('ADMIN')) {  // Checks for UPPERCASE
    return true;
}

// But database has:
// roles table: name = 'Administrator'  // Mixed case

// Result: 'Administrator' !== 'ADMIN' → Authorization failed
```

**Solution Implemented:**
```php
// CORRECT: Handle multiple case variations
$roleName = $user->role ? $user->role->name : null;

// Check multiple variations
if ($roleName && in_array($roleName, [
    'ADMIN', 
    'Administrator',  // Mixed case
    'admin',          // Lowercase
    'HOLDING',
    'Holding'
])) {
    return true;
}

// OR: Case-insensitive comparison
if ($roleName && strtolower($roleName) === strtolower('administrator')) {
    return true;
}
```

**User Model hasRole() Method:**
```php
public function hasRole($roles): bool
{
    if (!$this->role) return false;
    
    if (is_string($roles)) {
        // Case-insensitive comparison for single role
        return strtolower($this->role->name) === strtolower($roles);
    }
    
    if (is_array($roles)) {
        // Check against array of role names
        $userRoleLower = strtolower($this->role->name);
        return in_array(
            $userRoleLower, 
            array_map('strtolower', $roles)
        );
    }
    
    return false;
}
```

**How to Prevent:**
1. **Document all valid role names** - create constant in codebase:
   ```php
   const ROLE_ADMIN = 'Administrator';
   const ROLE_HOLDING = 'Holding';
   const ROLE_USER = 'User';
   
   // Then use constants everywhere
   if ($user->hasRole(self::ROLE_ADMIN)) { ... }
   ```

2. **Always load role relationship** - ensure role is loaded before checking:
   ```php
   $user = auth()->user();
   if (!$user->relationLoaded('role')) {
       $user->load('role');
   }
   ```

3. **Add debug logging** - log what you're actually comparing:
   ```php
   Log::debug('Authorization check', [
       'user_id' => $user->id,
       'role_name' => $user->role?->name,
       'role_loaded' => (bool)$user->role,
   ]);
   ```

4. **Test with actual data** - don't assume role names, verify in database:
   ```sql
   SELECT DISTINCT name FROM roles;
   -- Verify exact case and spelling
   ```

**Lesson for Next Stages:**
1. **Never hardcode role names** - use constants
2. **Always load role relationship** before checking roles
3. **Handle case variations** in authorization checks
4. **Log authorization attempts** for debugging
5. **Test with actual production role data** - don't assume
6. **Database audit** - verify all role names in production match your code expectations

---

#### Obstacle 24: Vue Component Prop Type Mismatch
**Problem:** Parent component passed `caseId` as String but child component expected Number. This caused Vue warnings and potential bugs.

**Symptoms:**
- Vue console warning: "Invalid prop: type check failed for prop 'caseId'. Expected Number with value 1, got String with value '1'."
- Child component received string "1" instead of number 1
- String/number comparisons would fail in conditionals

**Root Cause:**
```javascript
// In parent (SptFilingForm.vue)
const route = useRoute()
const caseId = route.params.id  // ❌ Always string from URL params

// In child (StageForm.vue)
const props = defineProps({
  caseId: { type: String, required: true }  // ❌ Different type!
})

// Result: String mismatch warning
```

**Solution Implemented:**
```javascript
// In parent (SptFilingForm.vue)
const route = useRoute()
const caseId = parseInt(route.params.id, 10)  // ✅ Convert to number

// Pass to child
<StageForm :case-id="caseId" ... />

// In child (StageForm.vue)
const props = defineProps({
  caseId: { type: Number, required: true }  // ✅ Match parent type
})

// Now usages work correctly
const url = `/api/tax-cases/${props.caseId}/documents`  // Proper number
```

**Why This Matters:**
1. **Type safety:** Vue catches type errors at development time
2. **Bug prevention:** String "5" !== Number 5 in comparisons
3. **Code clarity:** Type hints document expected data
4. **Component reusability:** Clear contract between parent/child

**Best Practice Template:**
```javascript
// Always convert route params from string
const caseId = parseInt(route.params.id, 10) || 0
const stageId = parseInt(route.params.stageId, 10) || 1

// Validate conversion worked
if (isNaN(caseId)) {
  console.error('Invalid caseId:', route.params.id)
  router.push('/') // Navigate to safe page
}

// Pass to child with correct type
<ChildComponent :caseId="caseId" :stageId="stageId" />
```

**Lesson for Next Stages:**
1. **URL params are always strings** - always convert them
2. **Match prop types between parent/child** - Vue will warn if mismatch
3. **Use Number for IDs** - use String only for truly string values (codes, names)
4. **Validate conversions** - check isNaN after parseInt
5. **Test prop passing** - verify Vue devtools shows correct types

**Symptoms:**
- Click "Save as Draft" button → `submitForm()` triggered instead of `saveDraft()`
- Backend receives `action: 'submit'` instead of `action: 'draft'`
- Case status changed to SUBMITTED instead of staying in DRAFT
- `submitted_at` and `submitted_by` fields populated when they should remain NULL
- User confused: clicked draft button but form got submitted

**Flow of Bug:**
```
User clicks "Save as Draft" button
    ↓
Vue calls @click="saveDraft"
    ↓
BUT: Button is inside <form @submit.prevent="submitForm">
    ↓
Form submit event also fires (because button has no type attribute)
    ↓
BOTH handlers execute:
    - saveDraft() → sets pendingAction = 'draft'
    - submitForm() → sets pendingAction = 'submit' (overwrites!)
    ↓
Final pendingAction = 'submit'
    ↓
Confirmation dialog shows SUBMIT dialog (not draft)
    ↓
User confirms
    ↓
Backend gets action: 'submit'
    ↓
❌ Case status incorrectly changed to SUBMITTED
```

**Root Cause:** 
1. Buttons placed inside `<form>` without explicit `type` attribute
2. In HTML, buttons default to `type="submit"` when no type specified
3. All buttons inside form trigger form submission unless explicitly marked `type="button"`
4. Race condition: Multiple handlers executed, submit overwrote draft

**Solution Implemented:**
```vue
<!-- WRONG: No type attribute on buttons inside form -->
<form @submit.prevent="submitForm" class="space-y-2">
  <!-- form fields -->
  
  <div class="flex gap-1 pt-2 border-t">
    <Button @click="submitForm" ...>Submit & Continue</Button>
    <Button @click="saveDraft" ...>Save as Draft</Button>  <!-- ❌ Implicitly type="submit"! -->
    <Button @click="$router.back()" ...>Cancel</Button>    <!-- ❌ Implicitly type="submit"! -->
  </div>
</form>

<!-- CORRECT: Explicit type attributes -->
<form @submit.prevent="submitForm" class="space-y-2">
  <!-- form fields -->
  
  <div class="flex gap-1 pt-2 border-t">
    <Button type="submit" @click="submitForm" ...>Submit & Continue</Button>
    <Button type="button" @click="saveDraft" ...>Save as Draft</Button>  <!-- ✅ Explicit type="button" -->
    <Button type="button" @click="$router.back()" ...>Cancel</Button>    <!-- ✅ Explicit type="button" -->
  </div>
</form>
```

**HTML Button Type Rules:**
| Type | Behavior | Use Case |
|------|----------|----------|
| `type="submit"` | Triggers form submit event | Primary action button in form |
| `type="button"` | Does NOT trigger form submit | Secondary actions (cancel, draft, etc) |
| `type="reset"` | Resets form to initial state | Usually not used in modern apps |
| (no type) | **Defaults to `type="submit"`** | ❌ Never rely on this - ALWAYS be explicit |

**Critical Rule:**
```
Inside <form>: 
- ONLY the primary action button should have type="submit" (or no type)
- ALL other buttons MUST have type="button" to prevent form submission
```

**Lesson for Next Stages:**
1. **Always use explicit `type` attributes on buttons inside forms**
2. Only primary action (`submit`) gets `type="submit"`
3. All secondary actions (cancel, draft, delete) get `type="button"`
4. Never rely on default HTML behavior - be explicit
5. Test each button's behavior independently:
   - Submit button → triggers form submit AND button click
   - Other buttons → trigger only button click, NOT form submit
6. Verify in Vue devtools that correct handler was called
7. Check backend logs to confirm correct action received

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
- [ ] Button Types: **CRITICAL** - Use `type="submit"` ONLY on submit button, `type="button"` on all others (cancel, draft, etc)
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

#### Obstacle 25: Form Buttons Disabled When They Should Be Enabled - Debug Checklist
**Problem:** Form submission buttons ("Submit & Continue" and "Save as Draft") remain disabled even though conditions appear correct. User can't interact with form.

**Symptoms:**
- Both buttons stuck in disabled state with gray appearance
- Unable to submit or save form
- No obvious errors in console
- Refreshing page doesn't help
- Debug info shows normal values but buttons still disabled

**Root Cause - Multiple Possibilities (Debug in Order):**

1. **`isLoading` prop stuck at `true`**
   - Parent component (`SptFilingForm.vue`) passed `isLoading="true"` but never reset it to false
   - Check: `onMounted` hook should set `isLoading.value = false` after data loads
   - Verify: All async operations complete with `finally { isLoading.value = false }`

2. **`caseStatus` has wrong value**
   - API returning `case_status_id = 2` when database shows `case_status_id = 1`
   - Condition `caseStatus > 1` evaluates true, button disabled
   - Check: Log API response to see actual value: `console.log('caseStatus:', caseData.case_status_id)`
   - Verify database directly: Is the actual value in DB really 1, or is it 2?

3. **Props not being passed correctly from parent**
   - Parent component not passing required props
   - Vue prop binding syntax error (`:caseStatus="..." ` vs `caseStatus="..."`)
   - Check: Browser DevTools Vue extension → Component props showing correct values?

4. **Reactive state not updating**
   - `submitting` ref stuck at true
   - `submissionComplete` incorrectly set to true
   - Check: Look at Debug Info box - what values are displayed?

**Debug Strategy - Step by Step:**

```javascript
// Step 1: Add prominent debug info to template (already done)
// Shows: isLoading, caseStatus, submissionComplete, submitting

// Step 2: Check each condition individually in console
console.log('submitting:', submitting.value)          // Should be false
console.log('isLoading:', isLoading.value)           // Should be false
console.log('caseStatus:', caseStatus.value)         // Should be 1 (or null)
console.log('submissionComplete:', submissionComplete.value)  // Should be false

// Step 3: If caseStatus wrong, check what API returned
console.log('From API response:', caseData.case_status_id)  // What is actual value?

// Step 4: Check if parent passed props correctly
// In parent component (SptFilingForm.vue):
<StageForm
  :isLoading="isLoading"
  :caseStatus="caseStatus"
  /* ... */
/>

// Verify onMounted sets isLoading to false:
onMounted(async () => {
  try {/* Lines 2397-2400 omitted */}
  } finally {
    isLoading.value = false  // ← Must be here!
  }
})
```

**Button Disabled Condition Analysis:**
```vue
:disabled="submitting || isLoading || caseStatus > 1 || submissionComplete"
```

**Each condition breakdown:**
| Condition | Should Be | Debug Log |
|-----------|-----------|-----------|
| `submitting` | `false` | `console.log('submitting:', submitting.value)` |
| `isLoading` | `false` | `console.log('isLoading:', isLoading.value)` |
| `caseStatus > 1` | `false` | `console.log('caseStatus > 1:', caseStatus.value > 1, 'value:', caseStatus.value)` |
| `submissionComplete` | `false` | `console.log('submissionComplete:', submissionComplete.value)` |

**If one condition is true, button will be disabled!**

**Solution Path:**

1. **Add console logs to Debug Info:**
```javascript
// In StageForm.vue, add to a computed property:
const debugButtonState = computed(() => {
  return {
    submitting: submitting.value,
    isLoading: isLoading.value,
    caseStatus: caseStatus.value,
    caseStatusCheck: caseStatus.value > 1,
    submissionComplete: submissionComplete.value,
    willBeDisabled: submitting.value || isLoading.value || caseStatus.value > 1 || submissionComplete.value
  }
})

// Log it:
console.log('Button state:', debugButtonState.value)
```

2. **Check parent component (SptFilingForm.vue):**
   - Is `isLoading` being set to false after data loads?
   - Is `caseStatus` being set to actual API value?
   - Are props being passed with `:` syntax (reactive binding)?

3. **Verify API response:**
   - Open Network tab in DevTools
   - Check `/api/tax-cases/{id}` response
   - Look for `case_status_id` field
   - What is the actual value returned?

4. **Check database directly:**
   - Connect to database
   - Query: `SELECT id, case_status_id FROM tax_cases WHERE id = {id}`
   - Compare: Database value vs API response value
   - Are they different? (This would indicate API query issue)

**Most Common Cause (Based on Session):**
- `caseStatus` receiving value from API that doesn't match expectations
- Database shows 1, but API returns 2
- Solution: Check which value is correct - trust the API, not the viewer

**Prevention for Next Developer:**
- Always add Debug Info panel during development
- Check condition values BEFORE blaming component logic
- Never trust visual database inspection - verify with actual query or API response
- Log API responses to see what backend actually returned

**Lesson for Next Stages:**
1. **Build debug UI early** - helps catch state issues faster
2. **Log API responses** in development
3. **Validate prop passing** - use browser DevTools Vue extension
4. **Test each condition independently** - don't assume all are correct
5. **Check parent component** first - most issues come from prop passing

---

#### Obstacle 26: Database Value vs API Response Mismatch
**Problem:** Database viewer shows `case_status_id = 1`, but API returns `case_status_id = 2`. Frontend receives wrong value, causing button to disable when it shouldn't.

**Symptoms:**
- Debug info shows `caseStatus: 2`
- But database query shows `case_status_id = 1`
- Form buttons stay disabled because `caseStatus > 1` is true
- Refreshing page doesn't help - same value
- Values inconsistent between database tool and API

**Root Cause - Investigation Path:**

This is a data integrity issue. The discrepancy could come from:

1. **Database query returning wrong value:**
   ```php
   // In TaxCaseController.php, show() method
   $taxCase = TaxCase::find($caseId);
   // What does it actually return?
   ```

2. **API appending/modifying the value:**
   ```php
   // Check if there's any transformation happening
   // in resource or controller after querying
   ```

3. **Multiple database connections:**
   - Reading from one DB in viewer
   - Writing to/reading from different DB in API
   - Data out of sync

4. **Caching issue:**
   - Old cached value being returned by API
   - Database updated but cache not cleared

**Debug Steps:**

```php
// In controller - log what's being retrieved
public function show($id)
{
    $taxCase = TaxCase::find($id);
    \Log::info('TaxCase data:', [
        'id' => $taxCase->id,
        'case_status_id' => $taxCase->case_status_id,
        'case_number' => $taxCase->case_number
    ]);
    
    return response()->json($taxCase);
}

// Check logs to see actual value
```

**Verify with Database Query:**
```sql
-- Direct query (most trustworthy)
SELECT id, case_status_id, case_number FROM tax_cases WHERE id = {YOUR_CASE_ID};

-- Compare with API response in Network tab
-- If database shows 1 but API returns 2, something is transforming the value
```

**Possible Root Causes:**

1. **Database viewer showing wrong value:**
   - Some DB tools cache results
   - Solution: Refresh database viewer, or query with CLI
   
2. **API transforming the value:**
   - Check `TaxCaseResource.php` if using API resources
   - Check controller modifying data before returning
   - Check middleware adding/changing values

3. **Multiple submissions happened:**
   - Case status changed between when user first loaded and when they checked DB
   - Solution: Timestamp check - when was case last updated?

4. **Wrong case ID being queried:**
   - User loading case 1, but API loading case 2
   - Solution: Verify case IDs match in URL and requests

**Solution Pattern:**

```php
// Ensure you're returning the correct data:
public function show(TaxCase $taxCase)
{
    // Verify this is the right case
    \Log::info('Retrieved case:', [
        'requested_id' => request()->route('taxCase')->id,
        'actual_case_status_id' => $taxCase->case_status_id
    ]);

    // Return as-is, don't transform
    return response()->json([
        'success' => true,
        'data' => [
            'id' => $taxCase->id,
            'case_status_id' => $taxCase->case_status_id,
            /* Lines 2490-2495 omitted */
        ]
    ]);
}
```

**Prevention Checklist:**

- [ ] **Before declaring "bug fixed":** Verify BOTH database AND API return same value
- [ ] **Log API responses** during development - don't trust visual inspection
- [ ] **Test with fresh data** - create new record and test end-to-end
- [ ] **Check database timestamps** - when was record last updated?
- [ ] **Verify record IDs** - ensure you're querying the right record
- [ ] **Check database connection** - are reads and writes using same connection?
- [ ] **Clear any caches** - if using caching layer, clear it
- [ ] **Query multiple ways** - check with CLI, DB tool, and API - they should match

**Lesson for Next Stages:**
1. **Trust the API first** - API is what application uses
2. **Database viewers can be misleading** - they might show cached/old data
3. **Always log API responses** - this is your single source of truth
4. **Verify data integrity** - check database query directly if API seems wrong
5. **Never assume values** - always check before making decisions based on them

---

#### Obstacle 27: Case Number Not Syncing When Period Changes
**Problem:** User changes fiscal period in a form that has case number field linked to period. User selects different period (e.g., Mar-25 instead of Mar-26), but case number doesn't update to reflect the new year. Case number remains stuck with old year value until page refresh.

**Symptoms:**
- User changes period from Mar-26 to Mar-25
- Case number should become PASI25MarC
- But still shows PASI26MarC (old year)
- Other data saves correctly
- Only case number stuck on old value
- Page refresh fixes it temporarily

**Root Cause:**

Multi-layered issue:

1. **No watcher on period_id change**
   ```javascript
   // Parent component only loads data ONCE in onMounted
   // When user changes period_id in form, no code triggers case number regeneration
   // Case number computed once, never updated again
   ```

2. **Case number generated from API response, not recalculated locally**
   ```javascript
   // Initial load: API returns case_number = "PASI26MarC"
   // User changes period: case_number still shows "PASI26MarC"
   // No re-fetch from API after period change
   ```

3. **Logic for period → case number transformation not implemented**
   ```javascript
   // If you have period object with year and month fields
   // No helper function to generate new case number based on new period
   ```

**Solution Implemented:**

```javascript
// 1. Store original case number to preserve pattern
const originalCaseNumber = ref('')
const periodsList = ref([])  // Store all periods for lookup

// 2. Add month mapping for numeric to abbreviation
const generateCaseNumberFromPeriod = (periodId) => {
  if (!periodId || !originalCaseNumber.value) return originalCaseNumber.value

  // Find selected period from periods list
  const selectedPeriod = periodsList.value.find(p => p.id === periodId)
  if (!selectedPeriod || !selectedPeriod.year || !selectedPeriod.month) {
    return originalCaseNumber.value
  }

  // Convert month number (1-12) to abbreviation
  const monthAbbr = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                     'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
  const newMonth = monthAbbr[selectedPeriod.month]
  const newYear = selectedPeriod.year.toString().slice(-2)  // Last 2 digits

  // Extract original pattern: PREFIX + YEAR(2) + MONTH(3) + SUFFIX
  const regex = /^([A-Z]+)(\d{2})([A-Z][a-z]{2})(.*)$/
  const match = originalCaseNumber.value.match(regex)
  
  if (!match) return originalCaseNumber.value

  const [, prefix, , , suffix] = match
  return `${prefix}${newYear}${newMonth}${suffix}`  // New: PASI25MarC
}

// 3. Add watcher to update case number when period changes
watch(
  () => prefillData.value.period_id,
  (newPeriodId) => {
    if (newPeriodId && originalCaseNumber.value) {
      const newCaseNum = generateCaseNumberFromPeriod(newPeriodId)
      caseNumber.value = newCaseNum
      console.log('Case number updated from period change:', newCaseNum)
    }
  }
)

// 4. In onMounted, store periods list for later reference
onMounted(async () => {
  // ... fetch data ...
  periodsList.value = periods  // Store all periods
  originalCaseNumber.value = caseData.case_number || 'N/A'  // Store original
})
```

**Key Implementation Details:**

1. **Period Model Expected Fields:**
   ```php
   // In Period model, must have these fields:
   - id: integer (unique identifier)
   - year: integer (e.g., 2026, 2025)
   - month: integer (1-12, January = 1)
   - period_code: string (e.g., "2026-03" or "03-25")
   ```

2. **Case Number Pattern:**
   ```
   Format: [PREFIX][YEAR(2 digits)][MONTH(3 letters)][SUFFIX]
   Example: PASI26MarC
   - PREFIX = PASI (or whatever prefix, extracted from original)
   - YEAR = 26 (last 2 digits of year, e.g., 2026 → 26)
   - MONTH = Mar (abbreviation, never numeric like 03 or 3)
   - SUFFIX = C (or other, extracted from original)
   ```

3. **Month Number to Abbreviation Mapping:**
   ```javascript
   // ALWAYS use index-based mapping to avoid off-by-one errors
   const monthAbbr = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                      'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
   // Index 0 = '' (unused)
   // Index 1 = 'Jan' (month 1)
   // ... etc
   monthAbbr[selectedPeriod.month]  // month=3 → 'Mar'
   ```

4. **Watcher vs Computed:**
   - Use **watch** when you need to trigger side effects (update UI, API calls)
   - Use **computed** for calculated values that update automatically
   - Here we use watch because we're updating `caseNumber` ref (side effect)

**Common Mistakes to Avoid:**

```javascript
// ❌ WRONG: Parsing period_code instead of using year/month fields
const periodCode = selectedPeriod.period_code  // "03-25" or "2026-03"
const [monthPart, yearPart] = periodCode.split('-')  // Fragile, format dependent

// ✅ CORRECT: Use structured fields directly
const { year, month } = selectedPeriod
const newYear = year.toString().slice(-2)  // 2026 → "26"
const newMonth = monthAbbr[month]  // 3 → "Mar"

// ❌ WRONG: Numeric month instead of abbreviation
return `${prefix}${newYear}${month}${suffix}`  // PASI26-3-C (wrong format)

// ✅ CORRECT: Always convert to 3-letter abbreviation
return `${prefix}${newYear}${monthAbbr[month]}${suffix}`  // PASI26MarC (correct)

// ❌ WRONG: No validation that period has required fields
const newYear = selectedPeriod.year  // What if year is null or undefined?

// ✅ CORRECT: Validate before using
if (!selectedPeriod || !selectedPeriod.year || !selectedPeriod.month) {
  return originalCaseNumber.value  // Fallback to original
}
```

**Testing Checklist:**

- [ ] Load form with case number PASI26MarC
- [ ] Change period dropdown from Mar-26 to Mar-25
- [ ] Verify case number immediately changes to PASI25MarC (no page refresh needed)
- [ ] Change period to Jan-26
- [ ] Verify case number becomes PASI26JanC
- [ ] Change back to original period
- [ ] Verify case number returns to original value
- [ ] Test with all 12 months - verify abbreviations correct
- [ ] Submit form after changing period
- [ ] Verify server-side case number matches what user sees
- [ ] Verify old case number in database updated to new one

**Lesson for Next Stages:**

1. **Case number is derived from period** - when period changes, case number MUST change
2. **Use watch, not computed** - needs side effect (updating caseNumber ref)
3. **Extract pattern from original** - don't hardcode PREFIX/SUFFIX, parse from existing value
4. **Always map month number to abbreviation** - never send numeric month to case number
5. **Validate period data** - ensure year and month fields exist before using
6. **Test all 12 months** - verify month mapping works for all values
7. **Test period changes** - test only submitting form after change, not page reload

---

#### Obstacle 28: Form Fields Not Disabled When Case Status > 1
**Problem:** When a tax case's status is updated to SUBMITTED or beyond (caseStatus > 1), form fields should be completely disabled to prevent accidental editing. However, fields remain editable even though the case is locked.

**Symptoms:**
- Case status shows value > 1 (case submitted or beyond)
- Form fields still appear editable (white background, cursor allowed)
- User can type in fields without seeing visual feedback that form is locked
- Fields don't have grayed out appearance
- Disabled styling not applied
- Only reads as disabled after form submit, not based on case status

**Root Cause:**

```javascript
// WRONG: Only disable based on submissionComplete
:disabled="submissionComplete"

// But submissionComplete only set to true AFTER form submission
// If case_status_id changes from database update (not form submission),
// submissionComplete remains false even though form should be locked
```

**Flow of Bug:**

```
1. User loads case with case_status_id = 2 (SUBMITTED)
2. Component initializes: submissionComplete = false (hasn't submitted in THIS form yet)
3. Fields bind to :disabled="submissionComplete"
4. submissionComplete = false → fields enabled (WRONG!)
5. Case is locked, but user can still edit fields
```

**Solution Implemented:**

```javascript
// Add computed property that checks BOTH conditions:
const fieldsDisabled = computed(() => {
  return props.caseStatus > 1  // Disable if case status > 1
})

// Update ALL field bindings to use fieldsDisabled:
<FormField
  :disabled="field.readonly || submissionComplete || fieldsDisabled"
/>

<textarea
  :disabled="submissionComplete || fieldsDisabled"
/>

<select
  :disabled="submissionComplete || fieldsDisabled"
/>

<input type="file"
  :disabled="uploadProgress > 0 || submissionComplete || fieldsDisabled"
/>

<!-- AND apply disabled styling -->
class="disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
```

**Where fieldsDisabled Matters:**

| Field Type | Disabled Binding |
|-----------|-----------------|
| Text Input | `:disabled="field.readonly \|\| submissionComplete \|\| fieldsDisabled"` |
| Number Input | `:disabled="field.readonly \|\| submissionComplete \|\| fieldsDisabled"` |
| Select Dropdown | `:disabled="submissionComplete \|\| fieldsDisabled"` |
| Textarea | `:disabled="submissionComplete \|\| fieldsDisabled"` |
| Date Input | `:disabled="submissionComplete \|\| fieldsDisabled"` |
| Month Input | `:disabled="submissionComplete \|\| fieldsDisabled"` |
| Radio Button | `:disabled="submissionComplete \|\| fieldsDisabled"` |
| Checkbox | `:disabled="submissionComplete \|\| fieldsDisabled"` |
| File Upload | `:disabled="uploadProgress > 0 \|\| submissionComplete \|\| fieldsDisabled"` |

**Critical Implementation Points:**

1. **fieldsDisabled is independent of submissionComplete:**
   ```javascript
   // submissionComplete = "Did user click submit button in THIS component?"
   // fieldsDisabled = "Is the case locked? (caseStatus > 1)"
   // These are NOT the same thing!

   // Case locked by backend → fieldsDisabled should be true, submissionComplete might be false
   // User submitted form → submissionComplete should be true, fieldsDisabled might be false
   // BOTH can be true (case locked AND user also submitted) → still disabled
   ```

2. **Use OR logic (||) not AND logic (&&):**
   ```javascript
   // WRONG: Both must be true to disable
   :disabled="submissionComplete && fieldsDisabled"
   // Result: User can edit locked case fields until they submit

   // CORRECT: Either one being true disables field
   :disabled="submissionComplete || fieldsDisabled"
   // Result: Fields disabled if either user submitted OR case is locked
   ```

3. **All fields must be updated consistently:**
   ```vue
   <!-- If you forget ONE field, that field will appear editable -->
   <FormField :disabled="submissionComplete" />  <!-- ❌ Missing fieldsDisabled -->
   <textarea :disabled="submissionComplete || fieldsDisabled" />  <!-- ✅ Correct -->
   <!-- First field is editable while locked! -->
   ```

**Testing Checklist:**

- [ ] Load case with caseStatus = 1 (CREATED)
  - All fields should be editable
  - Background white, text normal color
  - Cursor shows text input cursor

- [ ] Load case with caseStatus = 2 (SUBMITTED)
  - All fields should be disabled
  - Background gray (#f3f4f6)
  - Text color gray (#6b7280)
  - Cursor shows "not-allowed"
  - Visual feedback clear that case is locked

- [ ] Test ALL field types:
  - Text inputs
  - Number inputs
  - Selects/dropdowns
  - Textareas
  - Date pickers
  - Checkboxes
  - Radio buttons
  - File upload

- [ ] Submit form while case has caseStatus = 1
  - After submit, fields should still be disabled
  - submissionComplete = true, fieldsDisabled might be false
  - But submissionComplete || fieldsDisabled = true → disabled

- [ ] Dynamic case status change:
  - Load case with caseStatus = 1 (editable)
  - Backend updates case status to 2
  - Refresh page
  - Fields should now be disabled (fieldsDisabled recognizes caseStatus = 2)

**CSS Disabled Styling - Must Include:**

```vue
class="disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
```

**Without this styling:**
- Field IS disabled (can't type) but doesn't LOOK disabled
- Users confused: can't interact but looks interactive
- Poor UX, appears broken

**Lesson for Next Stages:**

1. **fieldsDisabled is separate from submissionComplete** - they serve different purposes
2. **Always use OR logic with disabled states** - `||` not `&&`
3. **Update ALL fields** - one missed field breaks the pattern
4. **Include disabled styling** - visual feedback is essential
5. **Test each field type** - different input types might behave differently
6. **Test both conditions independently:**
   - Test case status locking fields
   - Test form submission locking fields
   - Test both happening together
7. **Consider context for each stage** - some stages might have different rules (e.g., Stage 4 might only disable certain fields)

---

#### Obstacle 29: Case Number Not Persisting After Form Submit Due to Missing Refresh Trigger
**Problem:** User changes period and saves form. Period changes correctly in form data, but case number in header doesn't update until page refresh. The case number update logic works, but it's not triggered at the right time.

**Symptoms:**
- User changes period from Mar-26 to Mar-25
- User clicks "Submit & Continue" or "Save as Draft"
- Form data saves correctly (backend confirms new period)
- Case number in header still shows PASI26MarC (old value)
- Page refresh shows PASI25MarC (correct value)
- Watcher on period_id works fine (case number updates during editing)
- But doesn't refresh after backend save completes

**Root Cause:**

```javascript
// The problem: Period updates trigger watcher
watch(() => prefillData.value.period_id, (newPeriodId) => {
  // This updates LOCAL case number value
  caseNumber.value = generateCaseNumberFromPeriod(newPeriodId)
})

// But after form submit:
// 1. Form submits to backend
// 2. Backend saves updated period
// 3. prefillData hasn't been refreshed from backend yet
// 4. Watcher still holds old period_id value
// 5. After backend save, prefillData might not be updated
// 6. So watcher never fires with NEW value

// Flow:
// Form load: period_id = 3 (Mar-26)
// User selects: period_id = 2 (Mar-25) ← Watcher fires, case number updates
// User submits: prefillData sent to backend
// Backend saves successfully
// NO REFRESH of prefillData from API
// Watcher doesn't re-fire (period_id still = 2 in memory)
// But frontend never confirmed backend saved the new value
```

**Solution Implemented:**

```javascript
// Listen for form submission events and refresh after successful save
<StageForm
  @submit="refreshTaxCase"
  @saveDraft="refreshTaxCase"
  /* ... other props ... */
/>

// The refreshTaxCase function:
const refreshTaxCase = async () => {
  try {
    const caseRes = await fetch(`/api/tax-cases/${caseId}`)
    
    if (!caseRes.ok) {
      console.warn(`Failed to refresh tax case: ${caseRes.status}`)
      return
    }
    
    const caseResponse = await caseRes.json()
    const caseData = caseResponse.data ? caseResponse.data : caseResponse
    
    // Update prefillData with values from backend
    prefillData.value = {
      entity_name: caseData.entity_name || '',
      period_id: caseData.period_id,  // ← This change triggers watcher
      currency_id: caseData.currency_id,
      disputed_amount: caseData.disputed_amount ? parseFloat(caseData.disputed_amount) : null,
      submitted_at: caseData.submitted_at || null
    }
    
    // Update case number from backend response
    caseNumber.value = caseData.case_number || 'N/A'
    caseStatus.value = caseData.case_status_id
    
  } catch (err) {
    console.error('Failed to refresh tax case:', err)
  }
}
```

**Why This Works:**

```
1. User changes period → Watcher fires → Case number updates (LOCAL)
2. User submits form → Submit event fires → refreshTaxCase() called
3. refreshTaxCase() fetches updated case data from backend
4. Backend returns: case_number = "PASI25MarC" (already updated by backend)
5. caseNumber.value = "PASI25MarC" ← Header updates immediately
6. ALSO: prefillData.value.period_id updated → Watcher fires again as confirmation
7. Both paths ensure case number is correct
```

**Alternative Approach (If Backend Updates Case Number):**

If your backend automatically recalculates and updates case_number when period changes:

```php
// In TaxCaseController.php update() method
public function update(UpdateTaxCaseRequest $request, TaxCase $taxCase)
{
    // Save period change
    $taxCase->update(['period_id' => $request->period_id]);
    
    // Recalculate case number based on new period
    $newCaseNumber = $this->generateCaseNumber(
        $taxCase->entity_code,
        $taxCase->period  // reloaded with new period
    );
    $taxCase->update(['case_number' => $newCaseNumber]);
    
    return response()->json(['success' => true, 'data' => $taxCase]);
}
```

Then frontend can trust backend response:

```javascript
const refreshTaxCase = async () => {
  const caseRes = await fetch(`/api/tax-cases/${caseId}`)
  const caseData = await caseRes.json()
  
  // Backend already updated case_number, just use it
  caseNumber.value = caseData.data.case_number
}
```

**Implementation Decision Tree:**

```
Question: Where should case number be generated/updated?

├─ If backend auto-calculates case number:
│  └─ Just refresh from API after form submit
│     └─ No watcher needed on frontend
│
└─ If frontend generates case number:
   └─ Use watcher to update during editing
   └─ AND call refreshTaxCase() after submit
   └─ Gives immediate feedback + backend confirmation
```

**Recommended Approach (What We Implemented):**

- **Frontend:** Watcher updates case number during period selection (instant feedback)
- **Backend:** Stores original case number, doesn't recalculate
- **On Submit:** Frontend calls refreshTaxCase() to confirm backend accepted the change

**Benefits:**
- Instant visual feedback to user (watcher fires immediately)
- Confirmation from backend (refreshTaxCase verifies save)
- If backend differs, refreshTaxCase corrects it
- Clear separation of concerns

**Testing Checklist:**

- [ ] Load case number PASI26MarC
- [ ] Change period dropdown to Mar-25
- [ ] Verify case number immediately shows PASI25MarC (watcher working)
- [ ] Click "Submit & Continue"
- [ ] Verify form submits successfully
- [ ] Verify case number in header still shows PASI25MarC (refreshTaxCase updated it)
- [ ] Check browser Network tab - API response contains new case_number
- [ ] Refresh page manually
- [ ] Verify case number still PASI25MarC (confirms backend saved it)
- [ ] Test with "Save as Draft" button
- [ ] Verify same behavior - case number persists

**Troubleshooting:**

| Problem | Cause | Solution |
|---------|-------|----------|
| Case number updates during editing but reverts after submit | refreshTaxCase() not called or not working | Check @submit binding, check API endpoint returns correct data |
| Case number shows undefined after submit | Backend not returning case_number field | Check API response includes case_number |
| Case number flickers (updates twice) | Both watcher and refreshTaxCase updating | Normal - watcher updates UI, refreshTaxCase confirms from backend |
| No response 500 from refreshTaxCase | API endpoint not accessible | Check /api/tax-cases/{id} endpoint exists and is accessible without auth issues |

**Lesson for Next Stages:**

1. **Use watchers for immediate local feedback** - updates UI instantly
2. **Use submit listeners for backend confirmation** - refreshes data after save
3. **Combine both patterns** - best UX with instant feedback + backend confirmation
4. **Always refresh after async operations** - never assume frontend state matches backend
5. **Test actual form submission** - not just component state changes
6. **Verify API response** - check Network tab that API returns all needed fields
7. **Consider layered updates** - watcher for UX, API refresh for accuracy

---

| Version | Date | Changes |
|---------|------|---------|
| 1.6 | Jan 16, 2026 | **MAJOR UPDATE**: Added comprehensive obstacle documentation from SPT Filing Form implementation. Added Obstacles 27-29 with complete implementation patterns, testing checklists, and troubleshooting guides. Updated section 12 (Implementation Checklist) and 14 (Troubleshooting Guide) with field-disabling checks, period-sync testing, and case number refresh verification. **Total Obstacles: 29**. |
| 1.5 | Jan 16, 2026 | Added Obstacles 27-29 (Case number period sync, Form field disabling by case status, Case number refresh after submit). These are real obstacles encountered during SPT Filing Form development with actual implementation patterns and testing checklists. |
| 1.4 | Jan 16, 2026 | Added Obstacles 25-26 (Form buttons disabled debugging checklist, Database vs API mismatch). These obstacles document real debugging challenges encountered during SPT Filing Form development. Includes systematic debug strategies and prevention checklists. |
| 1.3 | Jan 16, 2026 | Added Obstacles 20-24 (model relationships, authorization policy complexity, XMLHttpRequest listener order, role name case sensitivity, Vue prop types). These obstacles document real bugs found during SPT Filing Form implementation with actual production solutions. |
| 1.2 | Jan 16, 2026 | Added Obstacle 19 (Non-Submit Buttons Triggering Form Submit Event) - critical bug found in Save as Draft functionality. Updated Prevention Checklist with explicit button type requirements. |
| 1.1 | Jan 13, 2026 | Added Obstacles 16-18 (notification system issues) and expanded Prevention Checklist with detailed category organization |
| 1.0 | Jan 13, 2026 | Initial refined template based on StageForm.vue with Obstacles 1-15 |

---

## 18. Author & Support

**Created by:** AI Assistant  
**For:** PORTAX Tax Case Management System  
**Framework:** Vue.js 3 + Laravel 11  
**Last Updated:** January 16, 2026  
**Total Obstacles Documented:** 29  
**Total Lessons Learned:** 29

For questions or updates to this template, refer to the base `StageForm.vue` implementation or contact the development team.
