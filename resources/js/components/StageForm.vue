<template>
  <div class="space-y-6 max-w-3xl">
    <!-- Toast Notifications -->
    <Toast ref="toastRef" />

    <!-- Confirmation Dialog -->
    <ConfirmationDialog
      :is-open="showConfirmDialog"
      title="Delete Document"
      message="Are you sure you want to delete this document? This action cannot be undone."
      confirm-label="Delete"
      cancel-label="Cancel"
      variant="danger"
      @confirm="confirmDelete"
      @cancel="cancelDelete"
    />

    <!-- Pre-filled Status Alert -->
    <Alert
      v-if="preFilledMessage && preFilledMessage.trim()"
      type="success"
      title="✅ Form Pre-filled"
      :message="preFilledMessage"
    />

    <!-- Header -->
    <div class="flex items-center space-x-4">
      <Button @click="$router.back()" variant="secondary">← Back</Button>
      <h1 class="text-3xl font-bold text-gray-900">{{ stageName }}</h1>
    </div>

    <!-- Success Alert (in page, not toast) -->
    <Alert
      v-if="successMessage"
      type="success"
      title="Success"
      :message="successMessage"
    />
    
    <!-- Continue to Next Stage Button (shown after successful submission) -->
    <div v-if="submissionComplete && nextStageId" class="flex space-x-4 pt-4">
      <Button @click="continueToNextStage" variant="primary">
        Continue to Stage {{ nextStageId }} →
      </Button>
      <Button @click="viewCaseDetail" variant="secondary">
        View Case Detail
      </Button>
    </div>

    <!-- Main Form -->
    <Card :title="`${stageName} - Information`" :subtitle="stageDescription">
      <form @submit.prevent="submitForm" :class="{ 'opacity-50 pointer-events-none': isLoading }" class="space-y-4">
        
        <!-- Dynamic Fields -->
        <div v-for="field in fields" :key="field.id" class="space-y-2">
          <!-- Text Input -->
          <FormField
            v-if="field.type === 'text'"
            :label="field.label"
            type="text"
            v-model="formData[field.key]"
            :placeholder="field.placeholder"
            :required="field.required"
            :error="formErrors[field.key]"
            :disabled="field.readonly"
          />

          <!-- Number Input -->
          <FormField
            v-if="field.type === 'number'"
            :label="field.label"
            type="number"
            v-model="formData[field.key]"
            :placeholder="field.placeholder"
            :required="field.required"
            :error="formErrors[field.key]"
            :disabled="field.readonly"
          />

          <!-- Date Input -->
          <FormField
            v-if="field.type === 'date'"
            :label="field.label"
            type="date"
            v-model="formData[field.key]"
            :required="field.required"
            :error="formErrors[field.key]"
          />

          <!-- Month Input -->
          <FormField
            v-if="field.type === 'month'"
            :label="field.label"
            type="month"
            v-model="formData[field.key]"
            :required="field.required"
            :error="formErrors[field.key]"
          />

          <!-- Textarea -->
          <div v-if="field.type === 'textarea'">
            <label :for="field.key" class="block text-sm font-medium text-gray-700 mb-2">
              {{ field.label }}
              <span v-if="field.required" class="text-red-500">*</span>
            </label>
            <textarea
              :id="field.key"
              v-model="formData[field.key]"
              :placeholder="field.placeholder"
              rows="4"
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <p v-if="formErrors[field.key]" class="text-red-500 text-sm mt-1">
              {{ formErrors[field.key] }}
            </p>
          </div>

          <!-- Select Dropdown -->
          <div v-if="field.type === 'select'">
            <label :for="field.key" class="block text-sm font-medium text-gray-700 mb-2">
              {{ field.label }}
              <span v-if="field.required" class="text-red-500">*</span>
            </label>
            <select
              :id="field.key"
              v-model="formData[field.key]"
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">-- Select {{ field.label }} --</option>
              <option v-for="option in field.options" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
            <p v-if="formErrors[field.key]" class="text-red-500 text-sm mt-1">
              {{ formErrors[field.key] }}
            </p>
          </div>

          <!-- Radio Buttons -->
          <div v-if="field.type === 'radio'">
            <label class="block text-sm font-medium text-gray-700 mb-2">
              {{ field.label }}
              <span v-if="field.required" class="text-red-500">*</span>
            </label>
            <div class="space-y-2">
              <div v-for="option in field.options" :key="option.value" class="flex items-center">
                <input
                  :id="`${field.key}_${option.value}`"
                  :name="field.key"
                  type="radio"
                  :value="option.value"
                  v-model="formData[field.key]"
                  class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300"
                />
                <label :for="`${field.key}_${option.value}`" class="ml-2 text-sm text-gray-700">
                  {{ option.label }}
                </label>
              </div>
            </div>
            <p v-if="formErrors[field.key]" class="text-red-500 text-sm mt-1">
              {{ formErrors[field.key] }}
            </p>
          </div>

          <!-- Checkbox -->
          <div v-if="field.type === 'checkbox'">
            <div class="flex items-center">
              <input
                :id="field.key"
                type="checkbox"
                v-model="formData[field.key]"
                class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
              />
              <label :for="field.key" class="ml-2 text-sm text-gray-700">
                {{ field.label }}
              </label>
            </div>
          </div>
        </div>

        <!-- Document Upload Section -->
        <div class="border-t pt-4 mt-4">
          <h3 class="text-lg font-medium text-gray-900 mb-3">📎 Supporting Documents</h3>
          
          <!-- Loading indicator -->
          <div v-if="loadingDocuments" class="flex items-center space-x-2 mb-3 text-gray-500">
            <div class="animate-spin h-4 w-4 border-2 border-gray-300 border-t-blue-500 rounded-full"></div>
            <span class="text-sm">Loading documents...</span>
          </div>

          <div class="space-y-3">
            <input
              type="file"
              multiple
              @change="handleFileUpload"
              :disabled="uploadProgress > 0"
              class="block w-full text-sm text-gray-500
                file:mr-4 file:py-2 file:px-4
                file:rounded-lg file:border-0
                file:text-sm file:font-medium
                file:bg-blue-50 file:text-blue-700
                hover:file:bg-blue-100
                disabled:opacity-50 disabled:cursor-not-allowed"
            />
            
            <!-- Upload Progress Bar -->
            <div v-if="uploadProgress > 0" class="space-y-2">
              <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-700">Uploading...</span>
                <span class="text-sm font-semibold text-blue-600">{{ uploadProgress }}%</span>
              </div>
              <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                <div 
                  class="bg-blue-500 h-full rounded-full transition-all duration-300"
                  :style="{ width: `${uploadProgress}%` }"
                ></div>
              </div>
            </div>
            
            <!-- Uploaded Files List -->
            <div v-if="uploadedFiles.length > 0" class="mt-4 space-y-2">
              <p class="text-sm font-medium text-gray-700">{{ uploadedFiles.length }} file(s) attached:</p>
              <div v-for="file in uploadedFiles" :key="file.id" class="flex items-center justify-between p-3 bg-gray-50 rounded border border-gray-200">
                <div class="flex items-center space-x-3 flex-1">
                  <span class="text-lg">📄</span>
                  <div class="flex-1">
                    <p class="text-sm font-medium text-gray-900">{{ file.name }}</p>
                    <p class="text-xs text-gray-500">{{ file.size }} MB • Status: <span :class="[
                      file.status === 'DRAFT' ? 'text-yellow-600 font-semibold' : 'text-green-600 font-semibold'
                    ]">{{ file.status }}</span></p>
                  </div>
                </div>
                <div class="flex items-center space-x-2">
                  <!-- Download button (always available) -->
                  <button
                    @click="downloadFile(file.id, file.name)"
                    class="px-3 py-1 text-sm bg-blue-50 text-blue-700 rounded hover:bg-blue-100 transition"
                    title="Download document"
                  >
                    ⬇️ Download
                  </button>
                  
                  <!-- Remove button (only when case status is 1 = CREATED) -->
                  <button
                    v-if="caseStatus === 1"
                    @click="removeFile(file.id)"
                    class="px-3 py-1 text-sm bg-red-50 text-red-700 rounded hover:bg-red-100 transition"
                    title="Remove document"
                  >
                    🗑️ Remove
                  </button>
                  
                  <!-- Lock icon (when case status is > 1 = submitted or beyond) -->
                  <span
                    v-else
                    class="px-3 py-1 text-sm bg-gray-100 text-gray-500 rounded cursor-not-allowed"
                    title="Case has been submitted - documents are locked"
                  >
                    🔒 Locked
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Case Info Display -->
        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
          <h3 class="text-sm font-medium text-gray-700 mb-2">📋 Case Information</h3>
          <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
              <p class="text-gray-600">Case Number</p>
              <p class="font-bold">{{ caseNumber }}</p>
            </div>
            <div>
              <p class="text-gray-600">Stage</p>
              <p class="font-bold">{{ stageId }} / 12</p>
            </div>
          </div>
        </div>

        <!-- Submit Buttons -->
        <div class="flex space-x-4 pt-4 border-t">
          <Button type="submit" variant="primary" :disabled="submitting || isLoading || (caseStatus && caseStatus > 1)">
            {{ submitting ? 'Submitting...' : 'Submit & Continue' }}
          </Button>
          <Button @click="saveDraft" variant="secondary" :disabled="submitting || isLoading || (caseStatus && caseStatus > 1)">
            Save as Draft
          </Button>
          <Button @click="$router.back()" variant="secondary" :disabled="isLoading">
            Cancel
          </Button>
        </div>
      </form>
    </Card>

    <!-- Loading Spinner -->
    <LoadingSpinner v-if="submitting" message="Processing form..." />
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import Card from './Card.vue'
import Button from './Button.vue'
import FormField from './FormField.vue'
import Alert from './Alert.vue'
import Toast from './Toast.vue'
import LoadingSpinner from './LoadingSpinner.vue'
import ConfirmationDialog from './ConfirmationDialog.vue'

const router = useRouter()
const route = useRoute()

// Props - passed from parent form component
const props = defineProps({
  stageName: {
    type: String,
    required: true
  },
  stageDescription: {
    type: String,
    required: true
  },
  stageId: {
    type: Number,
    required: true
  },
  caseId: {
    type: String,
    required: true
  },
  caseNumber: {
    type: String,
    required: true
  },
  fields: {
    type: Array,
    required: true
  },
  apiEndpoint: {
    type: String,
    required: true
  },
  isReviewMode: {
    type: Boolean,
    default: false
  },
  isLoading: {
    type: Boolean,
    default: false
  },
  caseStatus: {
    type: Number,
    default: null
  },
  preFilledMessage: {
    type: String,
    default: null
  },
  nextStageId: {
    type: Number,
    default: null
  },
  prefillData: {
    type: Object,
    default: () => ({})
  }
})

const emit = defineEmits(['submit', 'saveDraft'])

// Toast reference
const toastRef = ref(null)

// State
const submitting = ref(false)
const apiError = ref('')
const successMessage = ref('')
const submissionComplete = ref(false)
const formData = reactive({})
const formErrors = reactive({})
const uploadedFiles = ref([])
const loadingDocuments = ref(false)
const uploadProgress = ref(0)
const uploadingFiles = ref({}) // Track progress per file: { fileId: percentage }

// Confirmation dialog state
const showConfirmDialog = ref(false)
const pendingFileId = ref(null)

// Initialize form data from fields
onMounted(() => {
  props.fields.forEach(field => {
    // Priority: 1) prefillData dari parent, 2) field.value, 3) empty string
    if (props.prefillData && props.prefillData[field.key] !== undefined && props.prefillData[field.key] !== null) {
      formData[field.key] = props.prefillData[field.key]
    } else if (field.value !== undefined && field.value !== null) {
      formData[field.key] = field.value
    } else {
      formData[field.key] = ''
    }
  })

  // Fetch existing documents for this case and stage
  fetchDocuments()
})

// Re-initialize form data when fields or prefillData change
watch(() => [props.fields, props.prefillData], ([newFields, newPrefillData]) => {
  newFields.forEach(field => {
    if (newPrefillData && newPrefillData[field.key] !== undefined && newPrefillData[field.key] !== null) {
      formData[field.key] = newPrefillData[field.key]
    } else if (field.value !== undefined && field.value !== null) {
      formData[field.key] = field.value
    }
  })
}, { deep: true })

const fetchDocuments = async () => {
  loadingDocuments.value = true
  try {
    const response = await fetch(`/api/documents?tax_case_id=${props.caseId}&stage_code=${props.stageId}&status=DRAFT,ACTIVE,ARCHIVED`)
    
    if (!response.ok) {
      throw new Error('Failed to fetch documents')
    }

    const result = await response.json()
    
    if (result.data && Array.isArray(result.data)) {
      uploadedFiles.value = result.data.map(doc => ({
        id: doc.id,
        name: doc.original_filename,
        size: (doc.file_size / 1024 / 1024).toFixed(2), // Convert to MB
        uploadedAt: doc.uploaded_at,
        status: doc.status,
        isUploaded: true
      }))
    }
  } catch (error) {
    console.error('Error fetching documents:', error)
    // Don't show error toast, silently fail
  } finally {
    loadingDocuments.value = false
  }
}

const handleFileUpload = async (event) => {
  const files = event.target.files
  
  for (let file of files) {
    // Validate file type (PDF only)
    if (file.type !== 'application/pdf') {
      toastRef.value?.addToast('Invalid File Type', 'Only PDF files are allowed', 'error', 4000)
      continue
    }

    // Validate file size (max 10MB)
    const maxSize = 10 * 1024 * 1024 // 10MB
    if (file.size > maxSize) {
      toastRef.value?.addToast('File Too Large', 'Maximum file size is 10MB', 'error', 4000)
      continue
    }

    // Upload file via API
    await uploadFile(file)
  }

  // Reset input
  event.target.value = ''
}

const uploadFile = async (file) => {
  return new Promise((resolve, reject) => {
    try {
      const formData = new FormData()
      formData.append('file', file)
      formData.append('tax_case_id', props.caseId)
      formData.append('documentable_type', 'App\\Models\\WorkflowHistory')
      formData.append('documentable_id', props.stageId)
      formData.append('stage_code', props.stageId.toString())
      formData.append('document_type', 'supporting_document')

      // Get CSRF token from meta tag
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')

      // Use XMLHttpRequest for progress tracking
      const xhr = new XMLHttpRequest()
      const fileId = `${file.name}-${Date.now()}` // Temporary ID for progress tracking

      // Track upload progress
      xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
          const percentComplete = Math.round((e.loaded / e.total) * 100)
          uploadingFiles.value[fileId] = percentComplete
          // Also update overall progress for the current file
          uploadProgress.value = percentComplete
        }
      })

      xhr.addEventListener('load', () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          const result = JSON.parse(xhr.responseText)

          // Add to uploaded files list with the document ID
          uploadedFiles.value.push({
            id: result.data.id,
            name: result.data.original_filename,
            size: (result.data.file_size / 1024 / 1024).toFixed(2), // MB
            uploadedAt: result.data.uploaded_at,
            status: result.data.status, // Include status (DRAFT)
            isUploaded: true // Mark as successfully uploaded
          })

          toastRef.value?.addToast('Success', `${result.data.original_filename} uploaded`, 'success', 3000)
          
          // Clean up
          delete uploadingFiles.value[fileId]
          uploadProgress.value = 0
          resolve(result)
        } else {
          const errorData = JSON.parse(xhr.responseText)
          throw new Error(errorData.message || 'Upload failed')
        }
      })

      xhr.addEventListener('error', () => {
        toastRef.value?.addToast('Upload Error', 'Network error during upload', 'error', 5000)
        delete uploadingFiles.value[fileId]
        uploadProgress.value = 0
        reject(new Error('Upload failed'))
      })

      xhr.addEventListener('abort', () => {
        toastRef.value?.addToast('Upload Cancelled', 'Upload was cancelled', 'error', 3000)
        delete uploadingFiles.value[fileId]
        uploadProgress.value = 0
        reject(new Error('Upload cancelled'))
      })

      // Set headers and send
      if (csrfToken) {
        xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken)
      }
      xhr.open('POST', '/api/documents')
      xhr.withCredentials = true // Include cookies for session auth
      xhr.send(formData)
    } catch (error) {
      toastRef.value?.addToast('Upload Error', error.message, 'error', 5000)
      uploadProgress.value = 0
      reject(error)
    }
  })
}

const removeFile = async (fileId) => {
  try {
    // Find the file
    const file = uploadedFiles.value.find(f => f.id === fileId)
    
    // Only allow deletion if case status is 1 (CREATED/DRAFT)
    // If case_status_id > 1, case has been submitted and all documents are locked
    if (props.caseStatus !== 1) {
      toastRef.value?.addToast('Cannot Delete', 'Case has been submitted - documents are locked', 'error', 3000)
      return
    }
    
    if (!file) {
      toastRef.value?.addToast('Not Found', 'Document not found', 'error', 3000)
      return
    }

    // Show confirmation dialog
    pendingFileId.value = fileId
    showConfirmDialog.value = true

  } catch (error) {
    toastRef.value?.addToast('Delete Error', error.message, 'error', 4000)
  }
}

const confirmDelete = async () => {
  if (!pendingFileId.value) return

  try {
    const fileId = pendingFileId.value
    const file = uploadedFiles.value.find(f => f.id === fileId)

    if (file && file.isUploaded) {
      // Get CSRF token from meta tag
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')

      // Delete from server
      const response = await fetch(`/api/documents/${fileId}`, {
        method: 'DELETE',
        credentials: 'include',
        headers: {
          ...(csrfToken && { 'X-CSRF-TOKEN': csrfToken })
        }
      })

      if (!response.ok) {
        throw new Error('Failed to delete document')
      }
    }

    // Remove from UI
    uploadedFiles.value = uploadedFiles.value.filter(f => f.id !== fileId)
    toastRef.value?.addToast('Deleted', 'Document removed', 'success', 2000)

    // Close dialog
    showConfirmDialog.value = false
    pendingFileId.value = null

  } catch (error) {
    toastRef.value?.addToast('Delete Error', error.message, 'error', 4000)
    showConfirmDialog.value = false
    pendingFileId.value = null
  }
}

const cancelDelete = () => {
  showConfirmDialog.value = false
  pendingFileId.value = null
}

const downloadFile = (fileId, fileName) => {
  // Construct the download URL
  const downloadUrl = `/api/documents/${fileId}/download`
  
  // Create a temporary link and click it
  const link = document.createElement('a')
  link.href = downloadUrl
  link.download = fileName || `document-${fileId}`
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

const validateForm = () => {
  // Clear errors
  Object.keys(formErrors).forEach(key => {
    delete formErrors[key]
  })

  let isValid = true

  // Validate required fields
  props.fields.forEach(field => {
    // Skip validation for readonly fields
    if (field.readonly) {
      return
    }

    const value = formData[field.key]

    // Check if required field is empty
    if (field.required) {
      if (value === null || value === undefined || value === '') {
        formErrors[field.key] = `${field.label} is required`
        isValid = false
        return
      }

      // Additional check for numbers - must be numeric and positive
      if (field.type === 'number') {
        const numValue = parseFloat(value)
        if (isNaN(numValue)) {
          formErrors[field.key] = `${field.label} must be a valid number`
          isValid = false
        } else if (numValue < 0) {
          formErrors[field.key] = `${field.label} cannot be negative`
          isValid = false
        }
      }

      // Check for select/dropdown - ensure option is selected
      if (field.type === 'select' && !value) {
        formErrors[field.key] = `${field.label} must be selected`
        isValid = false
      }
    }
  })

  return isValid
}

const submitForm = async () => {
  if (!validateForm()) {
    // Validation errors are shown inline under each field, no need for toast
    return
  }

  // Validate that at least one document is uploaded
  if (uploadedFiles.value.length === 0) {
    toastRef.value?.addToast('Missing Documents', 'Please upload at least one supporting document before submitting', 'error', 5000)
    return
  }

  submitting.value = true
  apiError.value = ''
  successMessage.value = ''

  try {
    // PREVIEW MODE: Show message instead of actual submission
    successMessage.value = `
      ✅ Form validation passed!
      
      In Phase 3 (starting Jan 2, 2026), this form will be submitted to the backend.
      
      Your form data is ready to be saved. The database integration will be available soon.
      
      For now, this is preview mode for UI/UX testing only.
    `
    
    // Mark submission as complete and show continue button
    submitting.value = false
    submissionComplete.value = true
  } catch (error) {
    toastRef.value?.addToast('Error', error.message || 'Failed to process form', 'error', 5000)
    submitting.value = false
  }
}

const continueToNextStage = () => {
  if (props.nextStageId) {
    router.push(`/tax-cases/${props.caseId}/workflow/${props.nextStageId}`)
  }
}

const viewCaseDetail = () => {
  router.push(`/tax-cases/${props.caseId}`)
}

const saveDraft = async () => {
  // Validate form before saving draft
  if (!validateForm()) {
    // Validation errors are shown inline under each field, no need for toast
    return
  }

  submitting.value = true
  apiError.value = ''
  successMessage.value = ''

  try {
    const response = await fetch(`${props.apiEndpoint}?draft=true`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        ...formData,
        stage_id: props.stageId,
        case_id: props.caseId,
        status: 'draft'
      })
    })

    if (!response.ok) {
      const errorData = await response.json().catch(() => ({}))
      throw new Error(errorData.message || 'Failed to save draft')
    }
    
    toastRef.value?.addToast('Success', 'Draft saved successfully', 'success', 4000)
    emit('saveDraft', { stageId: props.stageId, data: formData })
  } catch (error) {
    toastRef.value?.addToast('Error', error.message || 'Failed to save draft', 'error', 5000)
  } finally {
    submitting.value = false
  }
}


</script>
