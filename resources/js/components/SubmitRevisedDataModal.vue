<template>
  <div class="modal-overlay" @click.self="close">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Submit Revised Data</h2>
        <button type="button" class="close-btn" @click="close">×</button>
      </div>

      <div class="modal-body">
        <form @submit.prevent="submit">
          <!-- Info Section -->
          <div class="info-section">
            <p class="mb-2">
              <strong>Revision #{{ revision?.id }}</strong> - Fields approved for revision:
            </p>
            <ul class="approved-fields-list">
              <li v-for="(value, field) in revision?.proposed_values" :key="field">
                {{ fieldLabel(field) }}
              </li>
            </ul>
          </div>

          <!-- Form Fields for Approved Fields -->
          <div class="form-section mt-4">
            <h3 class="form-section-title">📝 Enter Revised Values:</h3>
            
            <!-- Period ID -->
            <div v-if="selectedFields.includes('period_id')" class="form-group">
              <label class="form-label">Fiscal Period</label>
              <select 
                v-model="revisedData.period_id"
                class="form-control"
                required
              >
                <option value="">-- Select Period --</option>
                <option v-for="opt in periodOptions" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
            </div>

            <!-- Currency ID -->
            <div v-if="selectedFields.includes('currency_id')" class="form-group">
              <label class="form-label">Currency</label>
              <select 
                v-model="revisedData.currency_id"
                class="form-control"
                required
              >
                <option value="">-- Select Currency --</option>
                <option v-for="opt in currencyOptions" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
            </div>

            <!-- Text Fields -->
            <div v-if="selectedFields.includes('spt_number')" class="form-group">
              <label class="form-label">{{ fieldLabel('spt_number') }}</label>
              <input 
                v-model="revisedData.spt_number"
                type="text"
                class="form-control"
                required
              />
            </div>

            <div v-if="selectedFields.includes('skp_number')" class="form-group">
              <label class="form-label">{{ fieldLabel('skp_number') }}</label>
              <input 
                v-model="revisedData.skp_number"
                type="text"
                class="form-control"
                required
              />
            </div>

            <!-- Date Fields -->
            <div v-if="selectedFields.includes('filing_date')" class="form-group">
              <label class="form-label">{{ fieldLabel('filing_date') }}</label>
              <input 
                v-model="revisedData.filing_date"
                type="date"
                class="form-control"
                required
              />
            </div>

            <div v-if="selectedFields.includes('issue_date')" class="form-group">
              <label class="form-label">{{ fieldLabel('issue_date') }}</label>
              <input 
                v-model="revisedData.issue_date"
                type="date"
                class="form-control"
                required
              />
            </div>

            <div v-if="selectedFields.includes('receipt_date')" class="form-group">
              <label class="form-label">{{ fieldLabel('receipt_date') }}</label>
              <input 
                v-model="revisedData.receipt_date"
                type="date"
                class="form-control"
                required
              />
            </div>

            <!-- Number Fields -->
            <div v-if="selectedFields.includes('disputed_amount')" class="form-group">
              <label class="form-label">{{ fieldLabel('disputed_amount') }}</label>
              <input 
                v-model.number="revisedData.disputed_amount"
                type="number"
                class="form-control"
                step="0.01"
                required
              />
            </div>

            <div v-if="selectedFields.includes('skp_amount')" class="form-group">
              <label class="form-label">{{ fieldLabel('skp_amount') }}</label>
              <input 
                v-model.number="revisedData.skp_amount"
                type="number"
                class="form-control"
                step="0.01"
                required
              />
            </div>

            <!-- Textarea Fields -->
            <div v-if="selectedFields.includes('description')" class="form-group">
              <label class="form-label">{{ fieldLabel('description') }}</label>
              <textarea 
                v-model="revisedData.description"
                class="form-control"
                rows="3"
              ></textarea>
            </div>

            <div v-if="selectedFields.includes('correction_notes')" class="form-group">
              <label class="form-label">{{ fieldLabel('correction_notes') }}</label>
              <textarea 
                v-model="revisedData.correction_notes"
                class="form-control"
                rows="3"
              ></textarea>
            </div>

            <!-- Select Fields -->
            <div v-if="selectedFields.includes('skp_type')" class="form-group">
              <label class="form-label">{{ fieldLabel('skp_type') }}</label>
              <select 
                v-model="revisedData.skp_type"
                class="form-control"
                required
              >
                <option value="">-- Select --</option>
                <option value="LB">SKP LB (Lebih Bayar - Overpayment)</option>
                <option value="NIHIL">NIHIL (Zero)</option>
                <option value="KB">SKP KB (Kurang Bayar - Underpayment)</option>
              </select>
            </div>

            <div v-if="selectedFields.includes('user_routing_choice')" class="form-group">
              <label class="form-label">{{ fieldLabel('user_routing_choice') }}</label>
              <select 
                v-model="revisedData.user_routing_choice"
                class="form-control"
                required
              >
                <option value="">-- Select --</option>
                <option value="objection">Proceed to Objection (Stage 5)</option>
                <option value="refund">Proceed to Refund (Stage 13)</option>
              </select>
            </div>

            <!-- Checkbox Fields -->
            <div v-if="selectedFields.includes('create_refund')" class="form-group">
              <label class="flex items-center">
                <input 
                  type="checkbox" 
                  v-model="revisedData.create_refund"
                  class="checkbox-input"
                >
                <span class="ml-2 form-label">{{ fieldLabel('create_refund') }}</span>
              </label>
            </div>

            <div v-if="selectedFields.includes('continue_to_next_stage')" class="form-group">
              <label class="flex items-center">
                <input 
                  type="checkbox" 
                  v-model="revisedData.continue_to_next_stage"
                  class="checkbox-input"
                >
                <span class="ml-2 form-label">{{ fieldLabel('continue_to_next_stage') }}</span>
              </label>
            </div>

            <!-- Document Changes -->
            <div v-if="revision?.proposed_document_changes" class="form-group mt-4">
              <label class="form-label">📎 Document Changes:</label>
              <div class="document-changes-list">
                <div v-if="getDocumentChanges(revision).files_to_delete?.length > 0" class="mb-3">
                  <strong class="text-danger">Files to Delete:</strong>
                  <ul class="text-sm">
                    <li v-for="docId in getDocumentChanges(revision).files_to_delete" :key="`del-${docId}`">
                      {{ getDocFileName(docId, revision) }}
                    </li>
                  </ul>
                </div>
                <div v-if="getDocumentChanges(revision).files_to_add?.length > 0" class="mb-3">
                  <strong class="text-success">Files to Add:</strong>
                  <ul class="text-sm">
                    <li v-for="docId in getDocumentChanges(revision).files_to_add" :key="`add-${docId}`">
                      ✓ {{ getDocFileName(docId, revision) }}
                    </li>
                  </ul>
                </div>
              </div>
            </div>
          </div>

          <!-- Form Actions -->
          <div class="modal-footer">
            <button 
              type="button"
              class="btn btn-secondary"
              @click="close"
              :disabled="isSubmitting"
            >
              Cancel
            </button>
            <button 
              type="submit"
              class="btn btn-success"
              :disabled="isSubmitting"
            >
              <span v-if="isSubmitting">Submitting...</span>
              <span v-else>✓ Submit Revised Data</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRevisionFields } from '@/composables/useRevisionFields'
import { useToast } from '@/composables/useToast'

const props = defineProps({
  caseId: { type: Number, required: true },
  revision: { type: Object, default: null },
  availableFields: { type: Array, default: () => [] },
  fields: { type: Array, default: () => [] },
  currentDocuments: { type: Array, default: () => [] }
})

const emit = defineEmits(['submit', 'close'])
const { getFieldLabel } = useRevisionFields()
const { showSuccess, showError } = useToast()

const isSubmitting = ref(false)
const revisedData = ref({})

// Extract approved field keys from proposed_values
const selectedFields = computed(() => {
  if (!props.revision?.proposed_values) return []
  return Object.keys(props.revision.proposed_values)
})

// Generate period options (mock - should come from backend)
const periodOptions = computed(() => {
  return [
    { value: 1, label: 'Period 1 (2026)' },
    { value: 2, label: 'Period 2 (2026)' },
    { value: 3, label: 'Period 3 (2026)' }
  ]
})

// Generate currency options (mock - should come from backend)
const currencyOptions = computed(() => {
  return [
    { value: 1, label: 'IDR (Indonesian Rupiah)' },
    { value: 2, label: 'USD (US Dollar)' }
  ]
})

// Helper function to get field label
const fieldLabel = (field) => {
  // Special labels for decision checkbox fields
  if (field === 'create_refund') return 'Create Refund Process'
  if (field === 'continue_to_next_stage') return 'Continue to Next Stage'
  
  const fieldDef = props.fields.find(f => f.key === field)
  if (fieldDef && fieldDef.label) {
    return fieldDef.label
  }
  return getFieldLabel('tax-cases', field)
}

// Helper function to get document changes
const getDocumentChanges = (revision) => {
  if (!revision?.proposed_document_changes) return {}
  return revision.proposed_document_changes
}

// Helper function to get document file name
const getDocFileName = (docId, revision) => {
  if (revision?.documents && revision.documents[docId]) {
    return revision.documents[docId].original_filename
  }
  const doc = props.currentDocuments.find(d => d.id === docId)
  return doc ? (doc.original_filename || doc.file_name || doc.name) : `Doc #${docId}`
}

// Submit revised data
const submit = async () => {
  try {
    isSubmitting.value = true

    // Prepare payload with only the revised fields
    const payload = {
      action: 'submit_revised',
      revised_data: {},
      revision_id: props.revision?.id
    }

    // Only include fields that are in the approved list
    selectedFields.value.forEach(field => {
      const value = revisedData.value[field]
      // For checkbox fields, allow false values
      if (field === 'create_refund' || field === 'continue_to_next_stage') {
        // Always include checkbox fields if they're defined (even if false)
        if (value !== undefined) {
          payload.revised_data[field] = value === true ? true : false
        }
      } else if (value !== undefined && value !== '') {
        payload.revised_data[field] = value
      }
    })

    const response = await fetch(`/api/tax-cases/${props.caseId}/revisions/${props.revision?.id}/submit`, {
      method: 'PATCH',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
      },
      body: JSON.stringify(payload)
    })

    if (!response.ok) {
      const errorData = await response.json()
      throw new Error(errorData.message || 'Failed to submit revised data')
    }

    showSuccess('Success', 'Revised data has been submitted for approval.')
    emit('submit', payload)
  } catch (error) {
    showError('Error', error.message)
    console.error('Error submitting revised data:', error)
  } finally {
    isSubmitting.value = false
  }
}

// Close modal
const close = () => {
  emit('close')
}
</script>

<style scoped>
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-content {
  background: white;
  border-radius: 8px;
  box-shadow: 0 20px 25px rgba(0, 0, 0, 0.15);
  max-width: 700px;
  width: 90%;
  max-height: 85vh;
  overflow-y: auto;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 24px;
  border-bottom: 1px solid #e5e7eb;
  background-color: #f9fafb;
}

.modal-header h2 {
  margin: 0;
  font-size: 20px;
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
}

.modal-body {
  padding: 24px;
}

.modal-footer {
  display: flex;
  gap: 12px;
  justify-content: flex-end;
  padding: 16px 24px;
  border-top: 1px solid #e5e7eb;
  background-color: #f9fafb;
}

.info-section {
  background-color: #eff6ff;
  border-left: 4px solid #3b82f6;
  padding: 12px 16px;
  border-radius: 4px;
  margin-bottom: 16px;
}

.info-section p {
  margin: 0;
  font-size: 14px;
}

.approved-fields-list {
  list-style: none;
  padding-left: 16px;
  margin-top: 8px;
}

.approved-fields-list li {
  font-size: 13px;
  color: #374151;
  margin: 4px 0;
}

.approved-fields-list li:before {
  content: "✓ ";
  color: #10b981;
  font-weight: bold;
  margin-right: 4px;
}

.form-section {
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  padding: 16px;
  background-color: #fafafa;
}

.form-section-title {
  font-size: 14px;
  font-weight: 600;
  color: #1f2937;
  margin: 0 0 16px 0;
}

.form-group {
  margin-bottom: 16px;
}

.form-label {
  display: block;
  margin-bottom: 6px;
  font-size: 13px;
  font-weight: 500;
  color: #374151;
}

.form-control {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid #d1d5db;
  border-radius: 4px;
  font-size: 13px;
  font-family: inherit;
}

.form-control:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-control[required]:invalid {
  border-color: #ef4444;
}

.btn {
  padding: 8px 16px;
  border: 1px solid #d1d5db;
  border-radius: 4px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  transition: all 0.2s;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-secondary {
  background: white;
  color: #374151;
  border-color: #d1d5db;
}

.btn-secondary:hover:not(:disabled) {
  background: #f3f4f6;
}

.btn-success {
  background: #10b981;
  color: white;
  border-color: #10b981;
}

.btn-success:hover:not(:disabled) {
  background: #059669;
}

.document-changes-list {
  margin-top: 8px;
  padding: 12px;
  background-color: white;
  border-radius: 4px;
  border: 1px solid #e5e7eb;
}

.text-danger {
  color: #ef4444;
}

.text-success {
  color: #10b981;
}

.text-sm {
  font-size: 12px;
}

.mb-2 {
  margin-bottom: 8px;
}

.mb-3 {
  margin-bottom: 12px;
}

.mt-3 {
  margin-top: 12px;
}

.mt-4 {
  margin-top: 16px;
}
</style>
