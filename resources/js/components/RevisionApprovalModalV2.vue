<template>
  <div v-if="visible" class="modal-overlay" @click.self="emit('close')">
    <div class="modal-content modal-lg">
      <div class="modal-header">
        <h3>Review Revision Request</h3>
        <button class="close-btn" @click="emit('close')">×</button>
      </div>

      <div class="modal-body">
        <!-- Requested by info -->
        <div class="info-section">
          <p><strong>Requested by:</strong> {{ revision?.requested_by?.name }}</p>
          <p><strong>Date:</strong> {{ formatDate(revision?.created_at) }}</p>
          <p v-if="revision?.reason"><strong>Reason:</strong> {{ revision.reason }}</p>
        </div>

        <!-- Before-After Comparison -->
        <div class="comparison-section">
          <h4>Proposed Changes</h4>
          
          <div v-if="revision?.proposed_values && Object.keys(revision.proposed_values).length > 0" class="changes-list">
            <div v-for="(newValue, field) in revision.proposed_values" :key="field" class="change-item">
              <div class="field-name">{{ fieldLabel(field) }}</div>
              <div class="before-after">
                <div class="before">
                  <span class="label">Current:</span>
                  <span class="value">{{ getOriginalValue(field) }}</span>
                </div>
                <div class="arrow">→</div>
                <div class="after">
                  <span class="label">Proposed:</span>
                  <span class="value">{{ formatProposedValue(field, newValue) }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Document changes -->
          <div v-if="hasDocumentChanges" class="doc-changes">
            <h5>Document Changes</h5>
            
            <div v-if="getDocumentChanges().files_to_delete?.length > 0" class="delete-section">
              <p class="text-danger"><strong>Files to delete:</strong></p>
              <ul class="text-sm">
                <li v-for="docId in getDocumentChanges().files_to_delete" :key="docId">
                  {{ getDocFileName(docId) }}
                </li>
              </ul>
            </div>

            <div v-if="getDocumentChanges().files_to_add?.length > 0" class="add-section">
              <p class="text-success"><strong>Files to add/link:</strong></p>
              <ul class="text-sm">
                <li v-for="docId in getDocumentChanges().files_to_add" :key="`add-${docId}`">
                  ✓ {{ getDocFileName(docId) }}
                </li>
              </ul>
            </div>
          </div>
          
          <div v-else-if="props.revision?.proposed_document_changes !== undefined" class="doc-changes text-muted text-sm">
            <p>No document changes</p>
          </div>
        </div>

        <!-- Decision Section -->
        <div class="decision-section">
          <h4>Your Decision</h4>
          
          <div class="decision-options">
            <button 
              @click="selectedDecision = 'approve'" 
              :class="['btn-option', { active: selectedDecision === 'approve' }]"
            >
              ✅ Approve & Apply Changes
            </button>
            <button 
              @click="selectedDecision = 'reject'" 
              :class="['btn-option', { active: selectedDecision === 'reject' }]"
            >
              ✗ Reject Changes
            </button>
          </div>

          <!-- Rejection reason (if rejecting) -->
          <div v-if="selectedDecision === 'reject'" class="rejection-reason">
            <label>Rejection Reason</label>
            <textarea 
              v-model="rejectionReason" 
              placeholder="Explain why you're rejecting this revision request..."
              class="form-control"
              rows="4"
            />
          </div>
        </div>

        <!-- Error message -->
        <div v-if="localError" class="alert alert-danger" role="alert">
          {{ localError }}
        </div>

        <!-- Action buttons -->
        <div class="modal-footer">
          <button 
            @click="submitDecision" 
            :disabled="!selectedDecision || (selectedDecision === 'reject' && !rejectionReason) || isSubmitting"
            :class="['btn', selectedDecision === 'approve' ? 'btn-success' : 'btn-danger']"
          >
            <span v-if="isSubmitting">Processing...</span>
            <span v-else>{{ selectedDecision === 'approve' ? 'Approve & Apply' : 'Reject' }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useRevisionAPI } from '@/composables/useRevisionAPI'
import { useRevisionFields } from '@/composables/useRevisionFields'
import { useToast } from '@/composables/useToast'

const props = defineProps({
  visible: { type: Boolean, default: false },
  revision: { type: Object, default: null },
  caseId: { type: [String, Number], required: true },
  allDocuments: { type: Array, default: () => [] },
  entityType: { type: String, default: 'tax-cases' }, // Support any entity type
  taxCase: { type: Object, default: null }, // For accessing related data like periods
  periodsList: { type: Array, default: () => [] }, // List of periods for formatting
  fields: { type: Array, default: () => [] } // Full field definitions with labels
})

const emit = defineEmits(['close', 'approved', 'rejected'])

const { decideRevision, loading: apiLoading, error: apiError } = useRevisionAPI()
const { getFieldLabel } = useRevisionFields()
const { showSuccess, showError } = useToast()

const selectedDecision = ref(null)
const rejectionReason = ref('')
const localError = ref(null)

const isSubmitting = computed(() => apiLoading.value)

// Debug: Log revision data when it changes
watch(() => props.revision, (newVal) => {
  if (newVal) {
    console.log('Revision data received:', newVal)
    console.log('Original data:', newVal.original_data)
    console.log('Proposed values:', newVal.proposed_values)
    console.log('Document changes:', newVal.proposed_document_changes)
  }
}, { deep: true })

const formatDate = (date) => {
  if (!date) return ''
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

const fieldLabel = (field) => {
  // Look for the field in the fields array first
  if (props.fields && props.fields.length > 0) {
    const fieldDef = props.fields.find(f => f.key === field)
    if (fieldDef && fieldDef.label) {
      return fieldDef.label
    }
  }
  
  // Fallback to getFieldLabel from composable
  return getFieldLabel(props.entityType, field)
}

const getOriginalValue = (field) => {
  console.log(`[getOriginalValue] Getting value for field: ${field}`)
  console.log(`[getOriginalValue] original_data keys:`, Object.keys(props.revision?.original_data || {}))
  console.log(`[getOriginalValue] full original_data:`, props.revision?.original_data)
  
  // First try to get from revision.original_data (backend provided)
  if (props.revision?.original_data) {
    console.log(`[getOriginalValue] Checking if "${field}" in original_data:`, field in props.revision.original_data)
    if (field in props.revision.original_data) {
      const value = props.revision.original_data[field]
      console.log(`[getOriginalValue] Found in original_data:`, value)
      
      // Handle null/undefined - display as "Not set"
      if (value === null || value === undefined) {
        return '(Not set)'
      }
      
      let displayValue = value
      
      // For period_id, show the period_code instead of the ID
      if (field === 'period_id' && props.periodsList && props.periodsList.length > 0) {
        const period = props.periodsList.find(p => p.id === displayValue)
        if (period) {
          return period.period_code
        }
      }
      
      return displayValue
    }
  }
  
  // Fallback to taxCase data if available
  if (props.taxCase && field in props.taxCase) {
    const value = props.taxCase[field]
    console.log(`[getOriginalValue] Found in taxCase:`, value)
    
    // Handle null/undefined - display as "Not set"
    if (value === null || value === undefined) {
      return '(Not set)'
    }
    
    let displayValue = value
    
    // For period_id, show the period_code instead of the ID
    if (field === 'period_id' && props.periodsList && props.periodsList.length > 0) {
      const period = props.periodsList.find(p => p.id === displayValue)
      if (period) {
        return period.period_code
      }
    }
    
    return displayValue
  }
  
  console.log(`[getOriginalValue] Not found, returning N/A`)
  return 'N/A'
}

const formatProposedValue = (field, value) => {
  // For period_id, show the period_code instead of the ID
  if (field === 'period_id' && props.periodsList && props.periodsList.length > 0) {
    const period = props.periodsList.find(p => p.id === value)
    if (period) {
      return period.period_code
    }
  }
  
  // For other fields, return value as is
  return value
}

const getDocFileName = (docId) => {
  // First try to get from revision.documents (contains file details from backend)
  if (props.revision?.documents && props.revision.documents[docId]) {
    return props.revision.documents[docId].original_filename
  }
  // Fallback to allDocuments prop
  const doc = props.allDocuments.find(d => d.id === docId)
  return doc?.original_filename || doc?.name || `Document #${docId}`
}

const getDocumentChanges = () => {
  if (!props.revision?.proposed_document_changes) {
    return { files_to_delete: [], files_to_add: [], new_files_names: [] }
  }
  const changes = props.revision.proposed_document_changes
  // Handle if it's a JSON string
  const parsed = typeof changes === 'string' ? JSON.parse(changes) : changes
  console.log('Parsed document changes:', parsed)
  return parsed || { files_to_delete: [], files_to_add: [], new_files_names: [] }
}

const hasDocumentChanges = computed(() => {
  const changes = getDocumentChanges()
  return (
    (changes.files_to_delete?.length > 0) ||
    (changes.files_to_add?.length > 0)
  )
})

const submitDecision = async () => {
  if (!selectedDecision.value) {
    localError.value = 'Please select approve or reject'
    return
  }

  localError.value = null
  
  try {
    const revision = await decideRevision(
      props.entityType,
      props.caseId,
      props.revision.id,
      selectedDecision.value,
      selectedDecision.value === 'reject' ? rejectionReason.value : null
    )
    
    if (selectedDecision.value === 'approve') {
      showSuccess('Revision Approved', 'The changes have been approved and applied.')
      emit('approved', revision)
    } else {
      showSuccess('Revision Rejected', 'The revision request has been rejected.')
      emit('rejected', revision)
    }

    emit('close')
  } catch (error) {
    console.error('Error submitting decision:', error)
    const errorMsg = error.message || 'Failed to process decision'
    localError.value = errorMsg
    showError('Decision Failed', errorMsg)
  }
}
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
  max-height: 80vh;
  overflow-y: auto;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.modal-lg {
  width: 90%;
  max-width: 900px;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px;
  border-bottom: 1px solid #e0e0e0;
}

.modal-header h3 {
  margin: 0;
  font-size: 1.25rem;
}

.close-btn {
  background: none;
  border: none;
  font-size: 1.5rem;
  cursor: pointer;
  color: #666;
}

.modal-body {
  padding: 20px;
}

.modal-footer {
  padding: 20px;
  border-top: 1px solid #e0e0e0;
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

.info-section {
  background: #f5f5f5;
  padding: 15px;
  border-radius: 4px;
  margin-bottom: 20px;
}

.info-section p {
  margin: 8px 0;
  font-size: 0.9rem;
}

.comparison-section {
  margin: 20px 0;
}

.comparison-section h4 {
  font-size: 1rem;
  margin-bottom: 15px;
  color: #333;
}

.changes-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.change-item {
  background: #fafafa;
  padding: 12px;
  border-radius: 4px;
  border-left: 3px solid #007bff;
}

.field-name {
  font-weight: 600;
  margin-bottom: 8px;
  color: #333;
}

.before-after {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 0.85rem;
}

.before,
.after {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.label {
  font-size: 0.75rem;
  color: #999;
  text-transform: uppercase;
}

.value {
  font-weight: 500;
  color: #333;
}

.arrow {
  color: #999;
  font-weight: bold;
}

.doc-changes {
  margin-top: 15px;
  padding: 12px;
  background: #f9f9f9;
  border-radius: 4px;
}

.doc-changes h5 {
  margin: 0 0 10px 0;
  font-size: 0.9rem;
  color: #555;
}

.delete-section,
.add-section {
  margin-bottom: 8px;
}

.delete-section p {
  margin: 5px 0;
  font-size: 0.85rem;
}

.add-section p {
  margin: 5px 0;
  font-size: 0.85rem;
}

.text-danger {
  color: #dc3545;
}

.text-success {
  color: #28a745;
}

.text-sm {
  font-size: 0.85rem;
}

ul {
  margin: 5px 0;
  padding-left: 20px;
}

li {
  margin: 3px 0;
}

.decision-section {
  margin: 25px 0;
  padding: 20px;
  background: #f5f9ff;
  border-radius: 4px;
  border: 1px solid #d0e0ff;
}

.decision-section h4 {
  margin-top: 0;
  color: #333;
}

.decision-options {
  display: flex;
  gap: 10px;
  margin: 15px 0;
}

.btn-option {
  flex: 1;
  padding: 12px;
  border: 2px solid #ddd;
  background: white;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.9rem;
  font-weight: 500;
  transition: all 0.3s ease;
}

.btn-option:hover {
  border-color: #007bff;
  background: #f0f8ff;
}

.btn-option.active {
  border-color: #007bff;
  background: #007bff;
  color: white;
}

.rejection-reason {
  margin-top: 15px;
}

.rejection-reason label {
  display: block;
  margin-bottom: 8px;
  font-weight: 500;
  font-size: 0.9rem;
}

.form-control {
  width: 100%;
  padding: 10px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-family: inherit;
  font-size: 0.9rem;
  resize: vertical;
}

.form-control:focus {
  outline: none;
  border-color: #007bff;
  box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}

.btn {
  padding: 10px 20px;
  border: none;
  border-radius: 4px;
  font-weight: 500;
  cursor: pointer;
  font-size: 0.9rem;
  transition: all 0.3s ease;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-secondary {
  background: #6c757d;
  color: white;
}

.btn-secondary:hover:not(:disabled) {
  background: #5a6268;
}

.btn-success {
  background: #28a745;
  color: white;
}

.btn-success:hover:not(:disabled) {
  background: #218838;
}

.btn-danger {
  background: #dc3545;
  color: white;
}

.btn-danger:hover:not(:disabled) {
  background: #c82333;
}
</style>
