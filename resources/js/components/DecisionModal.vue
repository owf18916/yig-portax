<template>
  <div class="modal-overlay" @click.self="closeModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Review Revision Decision</h2>
        <button type="button" class="close-btn" @click="closeModal">
          <span>&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <!-- Revision Summary -->
        <div class="revision-summary">
          <div class="summary-item">
            <span class="label">Revision ID:</span>
            <span class="value">#{{ revision.id }}</span>
          </div>
          <div class="summary-item">
            <span class="label">Requested by:</span>
            <span class="value">{{ revision.requested_by_details?.name || 'User #' + revision.requested_by }}</span>
          </div>
          <div v-if="revision.reason" class="summary-item">
            <span class="label">Reason:</span>
            <span class="value">{{ revision.reason }}</span>
          </div>
          <div class="summary-item">
            <span class="label">Submitted at:</span>
            <span class="value">{{ formatDate(revision.submitted_at) }}</span>
          </div>
        </div>

        <!-- Before-After Comparison -->
        <div class="comparison-section">
          <h3>Changes to Review</h3>
          <div class="comparison-table-wrapper">
            <table class="comparison-table">
              <thead>
                <tr>
                  <th class="col-field">Field</th>
                  <th class="col-original">Current Value</th>
                  <th class="col-revised">Proposed Value</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(change, field) in changes" :key="field" class="changed-row">
                  <td class="col-field">
                    <span class="field-name">{{ fieldLabel(field) }}</span>
                  </td>
                  <td class="col-original">
                    <span class="value-badge old-value">{{ formatValue(change.original) }}</span>
                  </td>
                  <td class="col-revised">
                    <span class="value-badge new-value">{{ formatValue(change.revised) }}</span>
                  </td>
                </tr>
              </tbody>
            </table>

            <div v-if="Object.keys(changes).length === 0" class="no-changes">
              <p>No changes recorded in this revision</p>
            </div>
          </div>
        </div>

        <!-- Decision Form -->
        <form @submit.prevent="submit">
          <div class="decision-section">
            <h3>Your Decision</h3>
            
            <div class="form-group">
              <label class="form-label">
                <span class="required">*</span> Decision:
              </label>
              <div class="decision-options">
                <label class="decision-radio">
                  <input 
                    type="radio" 
                    value="grant"
                    v-model="decision"
                    @change="decisionReasonError = ''"
                  >
                  <span class="radio-label">
                    <span class="radio-title">✅ GRANT</span>
                    <span class="radio-desc">Approve the revised data and update it in the system</span>
                  </span>
                </label>

                <label class="decision-radio">
                  <input 
                    type="radio" 
                    value="not_grant"
                    v-model="decision"
                    @change="decisionReasonError = ''"
                  >
                  <span class="radio-label">
                    <span class="radio-title">✗ NOT GRANT</span>
                    <span class="radio-desc">Reject the revision and allow requester to submit again</span>
                  </span>
                </label>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">
                <span class="required">*</span> Decision Reason:
              </label>
              <textarea 
                v-model="decisionReason"
                placeholder="Explain your decision (minimum 10 characters)"
                rows="4"
                maxlength="1000"
                class="form-control textarea"
                @blur="validateDecisionReason"
                required
              ></textarea>
              <div class="character-count">
                <span :class="{ 'error': decisionReason.length < 10 }">
                  {{ decisionReason.length }}/1000 characters
                </span>
                <span v-if="decisionReason.length < 10" class="error-text">(minimum 10 required)</span>
              </div>
              <p v-if="decisionReasonError" class="error-message">{{ decisionReasonError }}</p>
            </div>

            <!-- Error Alert -->
            <div v-if="error" class="alert alert-danger">
              <strong>Error:</strong> {{ error }}
            </div>

            <!-- Loading State -->
            <div v-if="loading" class="alert alert-info">
              Processing decision...
            </div>
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
              class="btn"
              :class="decision === 'grant' ? 'btn-success' : 'btn-danger'"
              :disabled="!canSubmit || loading"
            >
              <span v-if="!loading">
                {{ decision === 'grant' ? '✅ Grant Revision' : '✗ Not Grant' }}
              </span>
              <span v-else>⏳ Processing...</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  revision: { type: Object, required: true }
})

const emit = defineEmits(['submit', 'close'])

const decision = ref('')
const decisionReason = ref('')
const error = ref('')
const decisionReasonError = ref('')
const loading = ref(false)

const canSubmit = computed(() => {
  return decision.value && decisionReason.value.length >= 10 && !error.value
})

// Calculate changes between original and revised data
const changes = computed(() => {
  if (!props.revision.revised_data) return {}
  
  return Object.entries(props.revision.original_data || {}).reduce((acc, [field, original]) => {
    const revised = props.revision.revised_data[field]
    if (revised !== original) {
      acc[field] = {
        original: original,
        revised: revised
      }
    }
    return acc
  }, {})
})

const fieldLabel = (field) => {
  const labels = {
    'spt_number': 'SPT Number',
    'filing_date': 'Filing Date',
    'received_date': 'Received Date',
    'reported_amount': 'Reported Amount',
    'disputed_amount': 'Disputed Amount',
    'vat_in_amount': 'VAT In Amount',
    'vat_out_amount': 'VAT Out Amount',
    'description': 'Description'
  }
  return labels[field] || field
}

const formatValue = (value) => {
  if (value === null || value === undefined || value === '') {
    return '(empty)'
  }
  
  // Format currency if numeric
  if (!isNaN(value) && value !== '') {
    const num = parseFloat(value)
    if (num > 999) {
      return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0
      }).format(num)
    }
  }
  
  return String(value)
}

const formatDate = (date) => {
  if (!date) return '-'
  return new Date(date).toLocaleDateString('id-ID', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

const validateDecisionReason = () => {
  if (decisionReason.value.length > 0 && decisionReason.value.length < 10) {
    decisionReasonError.value = 'Reason must be at least 10 characters'
  } else {
    decisionReasonError.value = ''
  }
}

const submit = async () => {
  error.value = ''

  // Validation
  if (!decision.value) {
    error.value = 'Please select a decision'
    return
  }

  if (decisionReason.value.length < 10) {
    decisionReasonError.value = 'Decision reason must be at least 10 characters'
    return
  }

  loading.value = true

  try {
    const token = localStorage.getItem('auth_token') || 
                  document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')

    const response = await fetch(
      `/api/revisions/${props.revision.id}/decide`,
      {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          ...(token ? { 'Authorization': `Bearer ${token}` } : {
            'X-CSRF-Token': token
          })
        },
        body: JSON.stringify({
          decision: decision.value,
          reason: decisionReason.value
        })
      }
    )

    const data = await response.json()

    if (!response.ok) {
      throw new Error(data.error || data.message || 'Failed to submit decision')
    }

    emit('submit', data.revision)
    closeModal()
  } catch (err) {
    error.value = err.message || 'An error occurred while submitting your decision'
    console.error('Decision submission error:', err)
  } finally {
    loading.value = false
  }
}

const closeModal = () => emit('close')
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
  z-index: 1001;
}

.modal-content {
  background: white;
  border-radius: 8px;
  width: 90%;
  max-width: 800px;
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

.revision-summary {
  background: #f3f4f6;
  border-radius: 6px;
  padding: 12px;
  margin-bottom: 20px;
  font-size: 13px;
}

.summary-item {
  display: flex;
  gap: 16px;
  padding: 6px 0;
  align-items: flex-start;
}

.summary-item .label {
  font-weight: 600;
  color: #374151;
  min-width: 120px;
}

.summary-item .value {
  color: #1f2937;
  flex: 1;
}

.comparison-section {
  margin-bottom: 24px;
}

.comparison-section h3,
.decision-section h3 {
  font-size: 15px;
  font-weight: 600;
  margin: 0 0 12px 0;
  color: #1f2937;
  padding-bottom: 10px;
  border-bottom: 2px solid #e5e7eb;
}

.comparison-table-wrapper {
  overflow-x: auto;
  margin-bottom: 10px;
}

.comparison-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  background: white;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  overflow: hidden;
}

.comparison-table thead {
  background: #f3f4f6;
  border-bottom: 2px solid #e5e7eb;
}

.comparison-table th {
  padding: 12px;
  text-align: left;
  font-weight: 600;
  color: #374151;
}

.comparison-table td {
  padding: 12px;
  border-bottom: 1px solid #e5e7eb;
}

.changed-row {
  background: #fffbf0;
}

.col-field {
  width: 25%;
  min-width: 150px;
}

.col-original {
  width: 37.5%;
  min-width: 180px;
}

.col-revised {
  width: 37.5%;
  min-width: 180px;
}

.field-name {
  font-weight: 600;
  color: #374151;
}

.value-badge {
  display: inline-block;
  padding: 6px 10px;
  border-radius: 4px;
  word-break: break-word;
  font-size: 12px;
  max-width: 100%;
}

.value-badge.old-value {
  background: #fee2e2;
  color: #7f1d1d;
}

.value-badge.new-value {
  background: #dcfce7;
  color: #166534;
}

.no-changes {
  text-align: center;
  padding: 30px;
  color: #9ca3af;
  background: #f9fafb;
  border-radius: 4px;
}

.decision-section {
  background: #f9fafb;
  border-radius: 6px;
  padding: 16px;
}

.form-group {
  margin-bottom: 16px;
}

.form-label {
  display: block;
  margin-bottom: 10px;
  font-weight: 600;
  color: #374151;
  font-size: 14px;
}

.required {
  color: #ef4444;
  margin-right: 4px;
}

.decision-options {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.decision-radio {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 12px;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s;
}

.decision-radio:hover {
  border-color: #3b82f6;
  background: #eff6ff;
}

.decision-radio input[type="radio"] {
  cursor: pointer;
  margin-top: 4px;
  width: 18px;
  height: 18px;
}

.radio-label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  flex: 1;
}

.radio-title {
  font-weight: 600;
  color: #1f2937;
  font-size: 14px;
}

.radio-desc {
  color: #6b7280;
  font-size: 12px;
}

.form-control {
  width: 100%;
  padding: 10px;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  font-family: inherit;
  font-size: 13px;
  transition: border-color 0.2s;
}

.form-control:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-control.textarea {
  resize: vertical;
  min-height: 100px;
}

.character-count {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
  color: #6b7280;
  margin-top: 6px;
}

.character-count .error {
  color: #ef4444;
  font-weight: 600;
}

.error-text {
  color: #ef4444;
  font-weight: 600;
}

.error-message {
  margin: 8px 0 0 0;
  color: #dc2626;
  font-size: 12px;
}

.alert {
  padding: 12px;
  border-radius: 6px;
  font-size: 13px;
  margin-bottom: 16px;
}

.alert-danger {
  background: #fee2e2;
  color: #7f1d1d;
  border: 1px solid #fecaca;
}

.alert-info {
  background: #dbeafe;
  color: #0c2d6b;
  border: 1px solid #bfdbfe;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding: 20px;
  border-top: 1px solid #e5e7eb;
  background: #f9fafb;
}

.btn {
  padding: 10px 20px;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  cursor: pointer;
  font-size: 14px;
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

.btn-danger {
  background: #ef4444;
  color: white;
  border-color: #ef4444;
}

.btn-danger:hover:not(:disabled) {
  background: #dc2626;
}
</style>
