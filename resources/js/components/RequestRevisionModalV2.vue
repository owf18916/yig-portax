<template>
  <div class="modal-overlay" @click.self="closeModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Request Revision & Propose Changes</h2>
        <button type="button" class="close-btn" @click="closeModal">
          <span>&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <form @submit.prevent="submit">
          <!-- Field Selection -->
          <div class="form-group">
            <label class="form-label">
              <span class="required">*</span> Select fields to revise:
            </label>
            <div class="fields-checklist">
              <label v-for="field in availableFields" :key="field" class="field-checkbox">
                <input 
                  type="checkbox" 
                  :value="field"
                  v-model="selectedFields"
                  class="checkbox-input"
                  @change="handleFieldToggle"
                >
                <span class="checkbox-label">{{ fieldLabel(field) }}</span>
              </label>
            </div>
            <p v-if="fieldError" class="error-message">{{ fieldError }}</p>
          </div>

          <!-- Proposed Values Section -->
          <div v-if="selectedFields.length > 0" class="form-group border-t pt-4 mt-4">
            <label class="form-label font-bold">💡 Proposed Changes:</label>
            
            <!-- Fiscal Period -->
            <div v-if="selectedFields.includes('period_id')" class="mb-3">
              <label class="form-label text-sm">Fiscal Period</label>
              <select 
                v-model="proposedValues.period_id"
                class="form-control"
                required
              >
                <option value="">-- Select Period --</option>
                <option v-for="opt in periodOptions" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
            </div>

            <!-- Currency -->
            <div v-if="selectedFields.includes('currency_id')" class="mb-3">
              <label class="form-label text-sm">Currency</label>
              <select 
                v-model="proposedValues.currency_id"
                class="form-control"
                required
              >
                <option value="">-- Select Currency --</option>
                <option v-for="opt in currencyOptions" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
            </div>

            <!-- Disputed Amount -->
            <div v-if="selectedFields.includes('disputed_amount')" class="mb-3">
              <label class="form-label text-sm">Disputed Amount (Nilai Sengketa)</label>
              <input 
                v-model.number="proposedValues.disputed_amount"
                type="number"
                min="0"
                placeholder="Enter new amount"
                class="form-control"
                required
              />
            </div>

            <!-- Supporting Documents -->
            <div v-if="selectedFields.includes('supporting_docs')" class="mb-3">
              <label class="form-label text-sm">Supporting Documents Management</label>
              
              <!-- Current Documents -->
              <div class="sub-section">
                <label class="font-semibold text-xs">Current Documents:</label>
                <div v-if="currentDocuments.length > 0" class="space-y-1 mb-2">
                  <label v-for="doc in currentDocuments" :key="doc.id" class="flex items-center gap-2 text-sm">
                    <input 
                      type="checkbox"
                      :value="doc.id"
                      v-model="proposedDocChanges.files_to_delete"
                      class="checkbox-input"
                    />
                    <span v-if="proposedDocChanges.files_to_delete.includes(doc.id)" class="text-red-600">
                      🗑️ {{ doc.name }} (will be deleted)
                    </span>
                    <span v-else>
                      📄 {{ doc.name }}
                    </span>
                  </label>
                </div>
                <p v-else class="text-xs text-gray-500">No existing documents</p>
              </div>

              <!-- Upload New Files -->
              <div class="sub-section mt-3">
                <label class="font-semibold text-xs">Add New Documents:</label>
                <input
                  type="file"
                  multiple
                  accept=".pdf"
                  @change="handleFileSelect"
                  :disabled="uploadProgress > 0"
                  class="form-control text-xs"
                />
                <p class="text-xs text-gray-500 mt-1">PDF files only, max 10MB each</p>

                <!-- New Files Selected -->
                <div v-if="newFilesSelected.length > 0" class="mt-2 space-y-1">
                  <p class="text-xs font-semibold">Ready to upload:</p>
                  <div v-for="(file, idx) in newFilesSelected" :key="idx" class="text-xs flex items-center gap-2">
                    <span>✓ {{ file.name }}</span>
                    <button type="button" @click="removeNewFile(idx)" class="text-red-600 text-xs">✕</button>
                  </div>
                </div>

                <!-- Upload Progress -->
                <div v-if="uploadProgress > 0" class="mt-2">
                  <div class="flex items-center justify-between mb-1">
                    <span class="text-xs">Uploading files...</span>
                    <span class="text-xs font-semibold">{{ uploadProgress }}%</span>
                  </div>
                  <div class="w-full bg-gray-200 rounded-full h-1.5">
                    <div 
                      class="bg-blue-500 h-full rounded-full transition-all"
                      :style="{ width: `${uploadProgress}%` }"
                    ></div>
                  </div>
                </div>

                <!-- Uploaded File IDs -->
                <div v-if="proposedDocChanges.files_to_add.length > 0" class="mt-2 text-xs text-green-600">
                  ✓ {{ proposedDocChanges.files_to_add.length }} file(s) uploaded
                </div>
              </div>
            </div>
          </div>

          <!-- Reason Input -->
          <div class="form-group">
            <label class="form-label">
              <span class="required">*</span> Explanation / Reason:
            </label>
            <textarea 
              v-model="reason"
              placeholder="Explain why revision is needed (minimum 10 characters)"
              rows="4"
              maxlength="1000"
              class="form-control textarea"
              @blur="validateReason"
              required
            ></textarea>
            <div class="character-count">
              <span :class="{ 'error': reason.length < 10 }">
                {{ reason.length }}/1000 characters
              </span>
              <span v-if="reason.length < 10" class="error-text">(minimum 10 required)</span>
            </div>
            <p v-if="reasonError" class="error-message">{{ reasonError }}</p>
          </div>

          <!-- Error Alert -->
          <div v-if="error" class="alert alert-danger">
            <strong>Error:</strong> {{ error }}
          </div>

          <!-- Loading State -->
          <div v-if="loading" class="alert alert-info">
            ⏳ Submitting revision request...
          </div>

          <!-- Modal Footer -->
          <div class="modal-footer">
            <button 
              type="button" 
              @click="closeModal" 
              class="btn btn-secondary"
              :disabled="loading"
            >
              Cancel
            </button>
            <button 
              type="submit" 
              class="btn btn-primary"
              :disabled="!canSubmit || loading"
            >
              <span v-if="!loading">Submit Request</span>
              <span v-else>⏳ Submitting...</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'

const props = defineProps({
  caseId: { type: Number, required: true },
  currentPeriod: { type: Number },
  currentCurrency: { type: Number },
  currentAmount: { type: Number },
  currentDocuments: { type: Array, default: () => [] },
  availableFields: { 
    type: Array, 
    default: () => ['period_id', 'currency_id', 'disputed_amount', 'supporting_docs']
  }
})

const emit = defineEmits(['submit', 'close'])

// Form state
const selectedFields = ref([])
const proposedValues = ref({
  period_id: null,
  currency_id: null,
  disputed_amount: null
})
const proposedDocChanges = ref({
  files_to_delete: [],
  files_to_add: []
})
const reason = ref('')

// UI state
const error = ref('')
const fieldError = ref('')
const reasonError = ref('')
const loading = ref(false)
const uploadProgress = ref(0)
const newFilesSelected = ref([])

// Dropdown options
const periodOptions = ref([])
const currencyOptions = ref([])

const canSubmit = computed(() => {
  return selectedFields.value.length > 0 && 
         reason.value.length >= 10 && 
         !error.value &&
         uploadProgress.value === 0
})

const fieldLabel = (field) => {
  const labels = {
    'period_id': 'Fiscal Period',
    'currency_id': 'Currency',
    'disputed_amount': 'Disputed Amount (Nilai Sengketa)',
    'supporting_docs': 'Supporting Documents'
  }
  return labels[field] || field
}

const handleFieldToggle = () => {
  fieldError.value = ''
}

const handleFileSelect = (event) => {
  const files = event.target.files
  newFilesSelected.value = Array.from(files)
}

const removeNewFile = (idx) => {
  newFilesSelected.value.splice(idx, 1)
}

const validateReason = () => {
  if (reason.value.length > 0 && reason.value.length < 10) {
    reasonError.value = 'Reason must be at least 10 characters'
  } else {
    reasonError.value = ''
  }
}

// NOTE: Files are NOT uploaded here - they are uploaded by backend after Holding approves
// This prevents orphan files if revision is rejected
const submit = async () => {
  error.value = ''
  fieldError.value = ''

  // Validation
  if (selectedFields.value.length === 0) {
    fieldError.value = 'Please select at least one field'
    return
  }

  if (reason.value.length < 10) {
    reasonError.value = 'Reason must be at least 10 characters'
    return
  }

  // Validate files if supporting_docs selected
  if (selectedFields.value.includes('supporting_docs')) {
    // Check if at least one action is specified (delete or add new)
    const hasDeleteAction = proposedDocChanges.value.files_to_delete.length > 0
    const hasAddAction = newFilesSelected.value.length > 0
    
    if (!hasDeleteAction && !hasAddAction) {
      error.value = 'Please select files to delete or add new files for document revision'
      return
    }
  }

  loading.value = true

  try {
    // Get CSRF token
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    
    if (!token) {
      throw new Error('CSRF token not found')
    }

    // Prepare payload - DON'T include actual file objects
    // Filter out null values from proposed_values
    const filteredProposedValues = Object.keys(proposedValues.value).reduce((acc, key) => {
      if (proposedValues.value[key] !== null) {
        acc[key] = proposedValues.value[key]
      }
      return acc
    }, {})

    const payload = {
      fields: selectedFields.value,
      reason: reason.value,
      proposed_values: filteredProposedValues,
      proposed_document_changes: proposedDocChanges.value,
      // Pass file info but NOT the actual files
      new_files_count: newFilesSelected.value.length,
      new_files_names: newFilesSelected.value.map(f => f.name)
    }

    const response = await fetch(
      `/api/tax-cases/${props.caseId}/revisions/request`,
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': token
        },
        credentials: 'include',
        body: JSON.stringify(payload)
      }
    )

    const data = await response.json()

    if (!response.ok) {
      throw new Error(data.error || data.message || 'Failed to submit request')
    }

    // SUCCESS: Now store files temporarily in memory/localStorage for the revision
    // Backend will handle actual file upload when Holding approves
    if (newFilesSelected.value.length > 0) {
      const revisionId = data.revision.id
      // Store files reference (can use IndexedDB or temp storage)
      sessionStorage.setItem(
        `revision_pending_files_${revisionId}`,
        JSON.stringify({
          files: newFilesSelected.value.map(f => ({
            name: f.name,
            size: f.size,
            type: f.type
          }))
        })
      )
    }

    emit('submit', data.revision)
    closeModal()
  } catch (err) {
    error.value = err.message || 'An error occurred while submitting the request'
    console.error('Revision request error:', err)
  } finally {
    loading.value = false
  }
}

const closeModal = () => {
  emit('close')
}

// Load dropdown options on mount
onMounted(async () => {
  try {
    const [currRes, periodRes] = await Promise.all([
      fetch('/api/currencies'),
      fetch('/api/periods')
    ])

    if (currRes.ok) {
      const currencies = await currRes.json()
      currencyOptions.value = currencies.map(c => ({
        value: c.id,
        label: `${c.code} - ${c.name}`
      }))
    }

    if (periodRes.ok) {
      const periods = await periodRes.json()
      periodOptions.value = periods.map(p => ({
        value: p.id,
        label: p.period_code
      }))
    }
  } catch (err) {
    console.error('Failed to load dropdown options:', err)
  }
})
</script>

<style scoped>
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-content {
  background: white;
  border-radius: 8px;
  width: 90%;
  max-width: 700px;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px;
  border-bottom: 1px solid #e5e7eb;
  background: #f9fafb;
  position: sticky;
  top: 0;
}

.modal-header h2 {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
  color: #1f2937;
}

.close-btn {
  background: none;
  border: none;
  font-size: 24px;
  cursor: pointer;
  color: #6b7280;
  padding: 0;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.close-btn:hover {
  color: #1f2937;
  background: #e5e7eb;
  border-radius: 4px;
}

.modal-body {
  padding: 20px;
}

.form-group {
  margin-bottom: 20px;
}

.form-label {
  display: block;
  margin-bottom: 8px;
  font-weight: 600;
  color: #374151;
  font-size: 14px;
}

.required {
  color: #ef4444;
}

.fields-checklist {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px;
  margin-bottom: 10px;
}

.field-checkbox {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  user-select: none;
}

.checkbox-input {
  width: 18px;
  height: 18px;
  cursor: pointer;
}

.checkbox-label {
  font-size: 14px;
  color: #374151;
}

.form-control {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  font-size: 14px;
  font-family: inherit;
}

.form-control:focus {
  outline: none;
  ring: 2px;
  ring-color: #3b82f6;
  border-color: #3b82f6;
}

.textarea {
  resize: vertical;
  font-family: inherit;
}

.character-count {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  color: #6b7280;
  margin-top: 4px;
}

.character-count .error {
  color: #ef4444;
  font-weight: 600;
}

.error-text {
  color: #ef4444;
  font-size: 12px;
}

.error-message {
  color: #ef4444;
  font-size: 12px;
  margin-top: 4px;
}

.alert {
  padding: 12px;
  border-radius: 6px;
  font-size: 14px;
  margin-bottom: 16px;
}

.alert-danger {
  background: #fee2e2;
  color: #991b1b;
  border: 1px solid #fecaca;
}

.alert-info {
  background: #dbeafe;
  color: #1e40af;
  border: 1px solid #bfdbfe;
}

.sub-section {
  background: #f9fafb;
  padding: 10px 12px;
  border-radius: 4px;
  margin: 8px 0;
}

.sub-section .font-semibold {
  display: block;
  margin-bottom: 8px;
  color: #374151;
}

.border-t {
  border-top: 1px solid #e5e7eb;
}

.modal-footer {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  padding-top: 20px;
  border-top: 1px solid #e5e7eb;
  margin-top: 20px;
}

.btn {
  padding: 8px 16px;
  border: none;
  border-radius: 6px;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-primary {
  background: #3b82f6;
  color: white;
}

.btn-primary:hover:not(:disabled) {
  background: #2563eb;
}

.btn-secondary {
  background: #e5e7eb;
  color: #374151;
}

.btn-secondary:hover:not(:disabled) {
  background: #d1d5db;
}
</style>
