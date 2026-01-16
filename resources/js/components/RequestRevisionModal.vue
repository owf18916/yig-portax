<template>
  <div class="modal-overlay" @click.self="closeModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Request Revision</h2>
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
                >
                <span class="checkbox-label">{{ fieldLabel(field) }}</span>
              </label>
            </div>
            <p v-if="fieldError" class="error-message">{{ fieldError }}</p>
          </div>

          <!-- Reason Input -->
          <div class="form-group">
            <label class="form-label">
              <span class="required">*</span> Explanation / Reason:
            </label>
            <textarea 
              v-model="reason"
              placeholder="Explain why revision is needed (minimum 10 characters)"
              rows="5"
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
            Submitting request...
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
import { ref, computed } from 'vue'

const props = defineProps({
  caseId: { type: Number, required: true },
  availableFields: { 
    type: Array, 
    default: () => [
      'spt_number',
      'filing_date',
      'received_date',
      'reported_amount',
      'disputed_amount',
      'vat_in_amount',
      'vat_out_amount',
      'description'
    ]
  }
})

const emit = defineEmits(['submit', 'close'])

const selectedFields = ref([])
const reason = ref('')
const error = ref('')
const fieldError = ref('')
const reasonError = ref('')
const loading = ref(false)

const canSubmit = computed(() => {
  return selectedFields.value.length > 0 && reason.value.length >= 10 && !error.value
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

const validateReason = () => {
  if (reason.value.length > 0 && reason.value.length < 10) {
    reasonError.value = 'Reason must be at least 10 characters'
  } else {
    reasonError.value = ''
  }
}

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

  loading.value = true

  try {
    const token = localStorage.getItem('auth_token') || 
                  document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')

    const response = await fetch(
      `/api/tax-cases/${props.caseId}/revisions/request`,
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          ...(token ? { 'Authorization': `Bearer ${token}` } : {
            'X-CSRF-Token': token
          })
        },
        body: JSON.stringify({
          fields: selectedFields.value,
          reason: reason.value
        })
      }
    )

    const data = await response.json()

    if (!response.ok) {
      throw new Error(data.error || data.message || 'Failed to submit request')
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
  max-width: 600px;
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
  margin-bottom: 10px;
  font-weight: 600;
  color: #374151;
  font-size: 14px;
}

.required {
  color: #ef4444;
  margin-right: 4px;
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
  cursor: pointer;
  width: 16px;
  height: 16px;
}

.checkbox-label {
  color: #374151;
  font-size: 13px;
}

.field-checkbox:hover .checkbox-label {
  color: #1f2937;
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

.btn-primary {
  background: #3b82f6;
  color: white;
  border-color: #3b82f6;
}

.btn-primary:hover:not(:disabled) {
  background: #2563eb;
  border-color: #2563eb;
}

.btn-secondary {
  background: white;
  color: #374151;
  border-color: #d1d5db;
}

.btn-secondary:hover:not(:disabled) {
  background: #f3f4f6;
}
</style>
