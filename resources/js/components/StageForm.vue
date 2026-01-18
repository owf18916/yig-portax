<template>
  <!-- Full height container with side-by-side layout -->
  <div class="flex h-screen bg-gray-100">
    <!-- Toast Notifications -->
    <Toast ref="toastRef" />

    <!-- Confirmation Dialog -->
    <ConfirmationDialog
      :is-open="showConfirmDialog"
      :title="pendingFileId ? 'Delete Document' : confirmDialogTitle"
      :message="pendingFileId ? 'Are you sure you want to delete this document? This action cannot be undone.' : confirmDialogMessage"
      :confirm-label="pendingFileId ? 'Delete' : (pendingAction === 'submit' ? 'Submit' : 'Save')"
      cancel-label="Cancel"
      :variant="pendingFileId ? 'danger' : confirmDialogVariant"
      @confirm="handleConfirm"
      @cancel="cancelDelete"
    />

    <!-- LEFT SIDE: SPT Filing Form (50%) -->
    <div class="w-1/2 overflow-y-auto flex flex-col bg-white">
      <div class="flex-1 space-y-1 p-2">
        <!-- Header with Case Info -->
        <div class="flex items-center justify-between mb-2">
          <div class="flex items-center space-x-1">
            <Button @click="$router.back()" variant="secondary" class="text-xs px-2 py-1">← Back</Button>
            <h1 class="text-lg font-bold text-gray-900">{{ stageName }}</h1>
          </div>
        </div>

        <!-- Case Info Display (moved from bottom) -->
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

        <!-- Success Alert (in page, not toast) -->
        <Alert
          v-if="successMessage"
          type="success"
          title="Success"
          :message="successMessage"
        />
        
        <!-- Continue to Next Stage Button (shown after successful submission) -->
        <div v-if="submissionComplete && computedNextStageId" class="flex gap-1">
          <Button @click="continueToNextStage" variant="primary" class="text-xs px-2 py-1.5">
            Continue to Stage {{ computedNextStageId }} →
          </Button>
          <Button @click="viewCaseDetail" variant="secondary" class="text-xs px-2 py-1.5">
            View Case Detail
          </Button>
        </div>

        <!-- Main Form -->
        <div class="bg-white rounded-lg border border-gray-200">
          <div class="px-3 py-2 border-b border-gray-200">
            <h3 class="font-semibold text-base text-gray-900">{{ stageName }} - Information</h3>
            <p class="text-xs text-gray-600 mt-0.5">{{ stageDescription }}</p>
          </div>
          <div class="p-3">
            <form @submit.prevent="submitForm" class="space-y-2">
            
            <!-- Dynamic Fields -->
            <div v-for="field in fields" :key="field.id" class="space-y-1">
              <!-- Text Input -->
              <FormField
                v-if="field.type === 'text'"
                :label="field.label"
                type="text"
                v-model="formData[field.key]"
                :placeholder="field.placeholder"
                :required="field.required"
                :error="formErrors[field.key]"
                :disabled="field.readonly || submissionComplete || fieldsDisabled"
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
                :disabled="field.readonly || submissionComplete || fieldsDisabled"
              />

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

              <!-- Date Input -->
              <FormField
                v-if="field.type === 'date'"
                :label="field.label"
                type="date"
                v-model="formData[field.key]"
                :required="field.required"
                :error="formErrors[field.key]"
                :disabled="submissionComplete || fieldsDisabled"
              />

              <!-- Month Input -->
              <FormField
                v-if="field.type === 'month'"
                :label="field.label"
                type="month"
                v-model="formData[field.key]"
                :required="field.required"
                :error="formErrors[field.key]"
                :disabled="submissionComplete || fieldsDisabled"
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
                  :disabled="submissionComplete || fieldsDisabled"
                  rows="4"
                  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
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
                  :disabled="submissionComplete || fieldsDisabled"
                  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
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
                      :disabled="submissionComplete || fieldsDisabled"
                      class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 disabled:opacity-50 disabled:cursor-not-allowed"
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
                    :disabled="submissionComplete || fieldsDisabled"
                    class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded disabled:opacity-50 disabled:cursor-not-allowed"
                  />
                  <label :for="field.key" class="ml-2 text-sm text-gray-700">
                    {{ field.label }}
                  </label>
                </div>
              </div>
            </div>

            <!-- Document Upload Section -->
            <div class="border-t pt-2 mt-2">
              <h3 class="text-sm font-medium text-gray-900 mb-1">📎 Supporting Documents</h3>
              
              <!-- Loading indicator -->
              <div v-if="loadingDocuments" class="flex items-center space-x-2 mb-1 text-gray-500">
                <div class="animate-spin h-3 w-3 border-2 border-gray-300 border-t-blue-500 rounded-full"></div>
                <span class="text-xs">Loading documents...</span>
              </div>

              <div class="space-y-1">
                <input
                  type="file"
                  multiple
                  @change="handleFileUpload"
                  :disabled="uploadProgress > 0 || submissionComplete || fieldsDisabled"
                  class="block w-full text-sm text-gray-500
                    file:mr-4 file:py-2 file:px-4
                    file:rounded-lg file:border-0
                    file:text-sm file:font-medium
                    file:bg-blue-50 file:text-blue-700
                    hover:file:bg-blue-100
                    disabled:opacity-50 disabled:cursor-not-allowed"
                />
                
                <!-- Upload Progress Bar -->
                <div v-if="uploadProgress > 0" class="space-y-1">
                  <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-gray-700">Uploading...</span>
                    <span class="text-xs font-semibold text-blue-600">{{ uploadProgress }}%</span>
                  </div>
                  <div class="w-full bg-gray-200 rounded-full h-1 overflow-hidden">
                    <div 
                      class="bg-blue-500 h-full rounded-full transition-all duration-300"
                      :style="{ width: `${uploadProgress}%` }"
                    ></div>
                  </div>
                </div>
                
                <!-- Uploaded Files List (Click to view in PDF viewer) -->
                <div v-if="uploadedFiles.length > 0" class="mt-1 space-y-0.5">
                  <p class="text-xs font-medium text-gray-700">{{ uploadedFiles.length }} file(s):</p>
                  <div v-for="file in uploadedFiles" :key="file.id" class="flex items-center justify-between p-1.5 bg-gray-50 rounded border border-gray-200 hover:bg-blue-50 cursor-pointer transition text-xs" @click="viewDocument(file.id, file.name)">
                    <div class="flex items-center space-x-1.5 flex-1 min-w-0">
                      <span class="text-base shrink-0">📄</span>
                      <div class="flex-1 min-w-0">
                        <p class="font-medium text-gray-900 truncate text-xs">{{ file.name }}</p>
                        <p class="text-xs text-gray-500">{{ file.size }} MB • <span :class="[
                          file.status === 'DRAFT' ? 'text-yellow-600 font-semibold' : 'text-green-600 font-semibold'
                        ]">{{ file.status }}</span></p>
                      </div>
                    </div>
                    <div class="flex items-center space-x-0.5 shrink-0 ml-1">
                      <!-- Remove button (only when current stage is not submitted and form not submitted) -->
                      <button
                        v-if="!fieldsDisabled && !submissionComplete"
                        @click.stop="removeFile(file.id)"
                        class="px-1.5 py-0.5 text-xs bg-red-50 text-red-700 rounded hover:bg-red-100 transition whitespace-nowrap"
                        title="Remove document"
                      >
                        🗑️ Remove
                      </button>
                      
                      <!-- Lock icon (when current stage is submitted or form is submitted) -->
                      <span
                        v-else
                        class="px-1.5 py-0.5 text-xs bg-gray-100 text-gray-500 rounded cursor-not-allowed whitespace-nowrap"
                        title="Stage has been submitted - documents are locked"
                      >
                        🔒 Locked
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Submit Buttons -->
            <div class="flex gap-1 pt-2 border-t">
              <Button type="submit" variant="primary" :disabled="submitting || isLoading || fieldsDisabled || submissionComplete" class="text-xs px-2 py-1.5">
                {{ submitting ? 'Submitting...' : 'Submit & Continue' }}
              </Button>
              <Button type="button" @click="saveDraft" variant="secondary" :disabled="submitting || isLoading || fieldsDisabled || submissionComplete" class="text-xs px-2 py-1.5">
                Save as Draft
              </Button>
              <Button type="button" @click="$router.back()" variant="secondary" :disabled="isLoading" class="text-xs px-2 py-1.5">
                Cancel
              </Button>
            </div>
            </form>
          </div>
        </div>

        <!-- Loading Spinner -->
        <LoadingSpinner v-if="submitting" message="Processing form..." />
      </div>
    </div>

    <!-- RIGHT SIDE: PDF Viewer (50%) -->
    <div class="w-1/2 bg-gray-100 flex flex-col border-l border-gray-300">
      <!-- PDF Viewer Header -->
      <div class="flex items-center justify-between p-2 bg-white border-b border-gray-300">
        <div class="flex-1">
          <p class="text-xs font-medium text-gray-700">
            <span v-if="selectedPdfId">📄 {{ selectedPdfName }}</span>
            <span v-else class="text-gray-500">No document selected</span>
          </p>
        </div>
        <button
          v-if="selectedPdfId"
          @click="closePdfViewer"
          class="px-2 py-1 text-xs text-gray-600 hover:bg-gray-100 rounded transition"
          title="Close"
        >
          ✕
        </button>
      </div>

      <!-- PDF Viewer Container -->
      <div class="flex-1 flex items-center justify-center overflow-hidden">
        <div v-if="selectedPdfId" class="w-full h-full">
          <iframe
            :src="pdfViewerUrl"
            class="w-full h-full border-none"
            title="PDF Viewer"
          ></iframe>
        </div>
        <div v-else class="text-center text-gray-400">
          <p class="text-base font-medium">📄</p>
          <p class="text-xs mt-1">Select a document from the left to view</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, watch, computed } from 'vue'
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
    type: Number,
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

// PDF Viewer state
const selectedPdfId = ref(null)
const selectedPdfName = ref('')
const pdfViewerUrl = ref('')

// Confirmation dialog state
const showConfirmDialog = ref(false)
const pendingFileId = ref(null)
const confirmDialogTitle = ref('Confirm')
const confirmDialogMessage = ref('')
const confirmDialogVariant = ref('default')
const pendingAction = ref(null) // 'submit' or 'draft'

// Compute next stage ID if not provided via props
const computedNextStageId = computed(() => {
  return props.nextStageId || (props.stageId < 12 ? props.stageId + 1 : null)
})

// Compute whether fields should be disabled based on case status
const fieldsDisabled = computed(() => {
  // Check if THIS STAGE has been submitted via workflow_history
  const isStageSubmitted = props.prefillData?.workflowHistories?.some(
    h => h.stage_id === props.stageId && (h.status === 'submitted' || h.status === 'approved')
  )
  return isStageSubmitted || false
})

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

  // Show pre-filled notification as toast
  if (props.preFilledMessage && props.preFilledMessage.trim()) {
    toastRef.value?.addToast('Form Pre-filled', props.preFilledMessage, 'success', 5000)
  }

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

      // IMPORTANT: Set up ALL event listeners BEFORE calling open()
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
        try {
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
            try {
              const errorData = JSON.parse(xhr.responseText)
              reject(new Error(errorData.message || 'Upload failed'))
            } catch (parseError) {
              reject(new Error('Upload failed with status ' + xhr.status))
            }
          }
        } catch (error) {
          reject(error)
        }
      })

      xhr.addEventListener('error', () => {
        toastRef.value?.addToast('Upload Error', 'Network error during upload', 'error', 5000)
        delete uploadingFiles.value[fileId]
        uploadProgress.value = 0
        reject(new Error('Network error during upload'))
      })

      xhr.addEventListener('abort', () => {
        toastRef.value?.addToast('Upload Cancelled', 'Upload was cancelled', 'error', 3000)
        delete uploadingFiles.value[fileId]
        uploadProgress.value = 0
        reject(new Error('Upload cancelled'))
      })

      // NOW open the request
      xhr.open('POST', '/api/documents')
      xhr.withCredentials = true // Include cookies for session auth
      
      // Set headers AFTER open() but BEFORE send()
      if (csrfToken) {
        xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken)
      }
      
      // Finally send the request
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
  pendingAction.value = null
}

const handleConfirm = async () => {
  if (pendingAction.value === 'submit') {
    await executeSubmitForm()
  } else if (pendingAction.value === 'draft') {
    await executeSaveDraft()
  } else if (pendingFileId.value) {
    // Delete file
    await confirmDelete()
    return // Don't reset state here as confirmDelete handles it
  }
  
  // Reset confirmation state
  showConfirmDialog.value = false
  pendingAction.value = null
}

const viewDocument = (fileId, fileName) => {
  // Set the PDF viewer with the document
  selectedPdfId.value = fileId
  selectedPdfName.value = fileName
  pdfViewerUrl.value = `/api/documents/${fileId}/view`
}

const closePdfViewer = () => {
  // Close the PDF viewer
  selectedPdfId.value = null
  selectedPdfName.value = ''
  pdfViewerUrl.value = ''
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

  // Show confirmation dialog
  confirmDialogTitle.value = 'Submit & Continue to Next Stage'
  confirmDialogMessage.value = 'You are about to submit this form to the system. Your data will be saved and you will proceed to the next stage. This action cannot be undone.'
  confirmDialogVariant.value = 'default'
  pendingAction.value = 'submit'
  showConfirmDialog.value = true
}

const executeSubmitForm = async () => {
  submitting.value = true
  apiError.value = ''
  successMessage.value = ''

  try {
    // Get CSRF token from meta tag
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')

    const payload = {
      ...formData,
      stage_id: props.stageId,
      case_id: props.caseId,
      action: 'submit',
      is_draft: false
    }
    
    // DEBUG: Log the request payload
    console.log('📤 Sending SUBMIT request with payload:', payload)

    // Submit form to backend
    const response = await fetch(`/api/tax-cases/${props.caseId}/workflow/${props.stageId}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...(csrfToken && { 'X-CSRF-TOKEN': csrfToken })
      },
      credentials: 'include',
      body: JSON.stringify(payload)
    })

    if (!response.ok) {
      const errorData = await response.json().catch(() => ({}))
      throw new Error(errorData.message || `Failed to submit form (${response.status})`)
    }

    const result = await response.json()

    // Show success toast for submission
    toastRef.value?.addToast('Form Submitted', `Stage ${props.stageId} berhasil disubmit dan data telah disimpan ke database.`, 'success', 5000)
    
    // Emit submit event to parent
    emit('submit', { stageId: props.stageId, data: formData })
    
    // Mark submission as complete and show continue button
    submitting.value = false
    submissionComplete.value = true
  } catch (error) {
    apiError.value = error.message
    toastRef.value?.addToast('Submission Error', error.message || 'Failed to submit form', 'error', 5000)
    submitting.value = false
  }
}

const continueToNextStage = () => {
  if (computedNextStageId.value) {
    router.push(`/tax-cases/${props.caseId}/workflow/${computedNextStageId.value}`)
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

  // Show confirmation dialog
  confirmDialogTitle.value = 'Save as Draft'
  confirmDialogMessage.value = 'Your form will be saved as a draft. You can continue editing it anytime without needing to complete all required fields.'
  confirmDialogVariant.value = 'default'
  pendingAction.value = 'draft'
  showConfirmDialog.value = true
}

const executeSaveDraft = async () => {
  submitting.value = true
  apiError.value = ''
  successMessage.value = ''

  try {
    // Get CSRF token from meta tag
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')

    const payload = {
      ...formData,
      stage_id: props.stageId,
      case_id: props.caseId,
      action: 'draft',
      is_draft: true
    }
    
    // DEBUG: Log the request payload
    // console.log('📤 Sending DRAFT request with payload:', payload)

    // Save draft to backend
    const response = await fetch(`/api/tax-cases/${props.caseId}/workflow/${props.stageId}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...(csrfToken && { 'X-CSRF-TOKEN': csrfToken })
      },
      credentials: 'include',
      body: JSON.stringify(payload)
    })

    if (!response.ok) {
      const errorData = await response.json().catch(() => ({}))
      throw new Error(errorData.message || `Failed to save draft (${response.status})`)
    }

    const result = await response.json()
    
    toastRef.value?.addToast('Draft Saved', 'Form disimpan sebagai draft. Anda dapat melanjutkan pengeditan nanti.', 'success', 4000)
    emit('saveDraft', { stageId: props.stageId, data: formData })
  } catch (error) {
    apiError.value = error.message
    toastRef.value?.addToast('Save Draft Error', error.message || 'Failed to save draft', 'error', 5000)
  } finally {
    submitting.value = false
  }
}


</script>
