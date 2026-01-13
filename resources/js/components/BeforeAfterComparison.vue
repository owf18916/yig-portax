<template>
  <div class="modal-overlay" @click.self="closeModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Before & After Comparison</h2>
        <button type="button" class="close-btn" @click="closeModal">
          <span>&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <!-- Revision Info -->
        <div class="revision-info">
          <div class="info-row">
            <span class="label">Revision ID:</span>
            <span class="value">#{{ revision.id }}</span>
          </div>
          <div class="info-row">
            <span class="label">Status:</span>
            <span class="value">
              <span class="status-badge" :class="statusClass(revision.revision_status)">
                {{ statusLabel(revision.revision_status) }}
              </span>
            </span>
          </div>
          <div class="info-row">
            <span class="label">Requested by:</span>
            <span class="value">{{ revision.requested_by_details?.name || 'User #' + revision.requested_by }}</span>
          </div>
          <div v-if="revision.reason" class="info-row">
            <span class="label">Reason:</span>
            <span class="value">{{ revision.reason }}</span>
          </div>
        </div>

        <!-- Comparison Table -->
        <div class="comparison-table-wrapper">
          <table class="comparison-table">
            <thead>
              <tr>
                <th class="col-field">Field</th>
                <th class="col-original">Original Value</th>
                <th class="col-revised">Revised Value</th>
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

          <!-- No Changes Message -->
          <div v-if="Object.keys(changes).length === 0" class="no-changes">
            <span class="icon">📋</span>
            <p>No changes found in this revision</p>
          </div>
        </div>

        <!-- Decision Info (if granted/not_granted) -->
        <div v-if="revision.revision_status === 'GRANTED' || revision.revision_status === 'NOT_GRANTED'" class="decision-info">
          <div class="decision-header" :class="revision.revision_status.toLowerCase()">
            <span class="decision-icon">
              {{ revision.revision_status === 'GRANTED' ? '✅' : '✗' }}
            </span>
            <span class="decision-text">
              {{ revision.revision_status === 'GRANTED' ? 'Changes Approved & Applied' : 'Changes Not Approved' }}
            </span>
          </div>
          <div v-if="revision.decision_reason" class="decision-reason">
            <strong>Decision Reason:</strong>
            <p>{{ revision.decision_reason }}</p>
          </div>
          <div class="decision-date">
            <strong>Decided at:</strong>
            <span>{{ formatDate(revision.decided_at) }}</span>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button @click="closeModal" class="btn btn-secondary">Close</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  revision: { type: Object, required: true }
})

const emit = defineEmits(['close'])

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
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

const statusClass = (status) => {
  const classMap = {
    'PENDING_APPROVAL': 'warning',
    'APPROVED': 'info',
    'REJECTED': 'danger',
    'SUBMITTED': 'warning',
    'GRANTED': 'success',
    'NOT_GRANTED': 'danger'
  }
  return classMap[status] || 'secondary'
}

const statusLabel = (status) => {
  const labelMap = {
    'PENDING_APPROVAL': '⏳ Awaiting Approval',
    'APPROVED': '✓ Approved',
    'REJECTED': '✗ Rejected',
    'SUBMITTED': '⏳ Awaiting Decision',
    'GRANTED': '✅ GRANTED',
    'NOT_GRANTED': '✗ NOT GRANTED'
  }
  return labelMap[status] || status
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
  z-index: 1000;
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

.revision-info {
  background: #f3f4f6;
  border-radius: 6px;
  padding: 12px;
  margin-bottom: 20px;
  font-size: 13px;
}

.info-row {
  display: flex;
  gap: 16px;
  padding: 6px 0;
  align-items: flex-start;
}

.info-row .label {
  font-weight: 600;
  color: #374151;
  min-width: 120px;
}

.info-row .value {
  color: #1f2937;
  flex: 1;
}

.status-badge {
  padding: 3px 8px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  display: inline-block;
}

.status-badge.success {
  background: #dcfce7;
  color: #166534;
}

.status-badge.warning {
  background: #fef3c7;
  color: #92400e;
}

.status-badge.danger {
  background: #fee2e2;
  color: #7f1d1d;
}

.status-badge.info {
  background: #dbeafe;
  color: #0c2d6b;
}

.comparison-table-wrapper {
  overflow-x: auto;
  margin-bottom: 20px;
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
  padding: 40px 20px;
  color: #9ca3af;
}

.no-changes .icon {
  font-size: 32px;
  display: block;
  margin-bottom: 10px;
}

.decision-info {
  margin-top: 20px;
  padding: 16px;
  border-radius: 6px;
  background: #f9fafb;
  border: 1px solid #e5e7eb;
}

.decision-header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
  padding: 10px;
  border-radius: 4px;
}

.decision-header.granted {
  background: #dcfce7;
  color: #166534;
}

.decision-header.not_granted {
  background: #fee2e2;
  color: #7f1d1d;
}

.decision-icon {
  font-size: 18px;
}

.decision-text {
  font-weight: 600;
}

.decision-reason {
  margin-bottom: 10px;
  font-size: 13px;
}

.decision-reason p {
  margin: 6px 0 0 0;
  color: #374151;
}

.decision-date {
  font-size: 12px;
  color: #6b7280;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
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

.btn-secondary {
  background: white;
  color: #374151;
  border-color: #d1d5db;
}

.btn-secondary:hover {
  background: #f3f4f6;
}
</style>
