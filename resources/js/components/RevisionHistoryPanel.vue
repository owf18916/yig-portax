<template>
  <div class="revision-history-panel">
    <div class="panel-header">
      <h3>📋 Revision History</h3>
      <button 
        v-if="canRequestRevision" 
        @click="showRequestModal = true"
        class="btn btn-primary btn-sm"
      >
        🔄 Request Revision
      </button>
      <span v-else class="text-muted text-sm">
        {{ revisionStatusMessage }}
      </span>
    </div>

    <!-- Revisions List -->
    <div v-if="revisions.length > 0" class="revisions-list">
      <div 
        v-for="revision in sortedRevisions" 
        :key="revision.id"
        class="revision-item"
        :class="`status-${revision.revision_status.toLowerCase()}`"
      >
        <div class="revision-header">
          <span class="revision-id">#{{ revision.id }}</span>
          <span class="status-badge" :class="statusClass(revision.revision_status)">
            {{ statusLabel(revision.revision_status) }}
          </span>
          <span class="revision-date">{{ formatDate(revision.created_at) }}</span>
        </div>

        <div class="revision-details">
          <p><strong>Requested by:</strong> {{ revision.requested_by_details?.name || 'User #' + revision.requested_by }}</p>
          <p v-if="revision.reason"><strong>Reason:</strong> {{ revision.reason }}</p>

          <!-- PENDING_APPROVAL State -->
          <div v-if="revision.revision_status === 'PENDING_APPROVAL'" class="pending-section">
            <span class="status-icon warning-icon">⏳ Waiting for Holding approval...</span>
          </div>

          <!-- APPROVED State -->
          <div v-if="revision.revision_status === 'APPROVED'" class="approved-section">
            <span class="status-icon success-icon">✓ Approved</span>
            <p v-if="revision.approved_at"><strong>Approved at:</strong> {{ formatDate(revision.approved_at) }}</p>
            <p v-if="revision.approval_reason"><strong>Approval Reason:</strong> {{ revision.approval_reason }}</p>
          </div>

          <!-- REJECTED State -->
          <div v-if="revision.revision_status === 'REJECTED'" class="rejected-section">
            <span class="status-icon error-icon">✗ Rejected</span>
            <p v-if="revision.rejection_reason"><strong>Rejection Reason:</strong> {{ revision.rejection_reason }}</p>
          </div>

          <!-- SUBMITTED State -->
          <div v-if="revision.revision_status === 'SUBMITTED'" class="submitted-section">
            <span class="status-icon warning-icon">⏳ Submitted, awaiting Holding decision...</span>
            <p v-if="revision.submitted_at"><strong>Submitted at:</strong> {{ formatDate(revision.submitted_at) }}</p>
          </div>

          <!-- GRANTED State -->
          <div v-if="revision.revision_status === 'GRANTED'" class="granted-section">
            <span class="status-icon success-icon">✅ GRANTED - Data updated successfully</span>
            <p v-if="revision.decided_at"><strong>Decided at:</strong> {{ formatDate(revision.decided_at) }}</p>
            <p v-if="revision.decision_reason"><strong>Decision:</strong> {{ revision.decision_reason }}</p>
            <button @click="showComparison(revision)" class="btn btn-info btn-sm">
              📊 View Changes
            </button>
          </div>

          <!-- NOT_GRANTED State -->
          <div v-if="revision.revision_status === 'NOT_GRANTED'" class="not-granted-section">
            <span class="status-icon error-icon">✗ NOT GRANTED</span>
            <p v-if="revision.decision_reason"><strong>Reason:</strong> {{ revision.decision_reason }}</p>
            <p class="text-muted text-sm">You can request a new revision with updated information</p>
          </div>
        </div>
      </div>
    </div>

    <!-- No Revisions -->
    <div v-else class="no-revisions">
      <p class="text-muted">No revisions yet</p>
    </div>

    <!-- Request Revision Modal -->
    <RequestRevisionModal 
      v-if="showRequestModal"
      :case-id="caseId"
      :available-fields="availableFields"
      @submit="onRevisionRequested"
      @close="showRequestModal = false"
    />

    <!-- Before-After Comparison Modal -->
    <BeforeAfterComparison 
      v-if="selectedRevision"
      :revision="selectedRevision"
      @close="selectedRevision = null"
    />
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import RequestRevisionModal from './RequestRevisionModal.vue'
import BeforeAfterComparison from './BeforeAfterComparison.vue'

const props = defineProps({
  caseId: { type: Number, required: true },
  taxCase: { type: Object, required: true },
  revisions: { type: Array, default: () => [] },
  currentUser: { type: Object, required: true },
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

const emit = defineEmits(['revision-requested', 'refresh'])

const showRequestModal = ref(false)
const selectedRevision = ref(null)

// Check if user can request new revision
const canRequestRevision = computed(() => {
  // Data must be submitted
  if (!props.taxCase.submitted_at) return false
  
  // No in-progress revisions
  const inProgress = props.revisions.find(r => 
    ['PENDING_APPROVAL', 'APPROVED', 'SUBMITTED'].includes(r.revision_status)
  )
  return !inProgress
})

// Message for why button is disabled
const revisionStatusMessage = computed(() => {
  if (!props.taxCase.submitted_at) {
    return '(Submit data first to request revisions)'
  }
  
  const pending = props.revisions.find(r => r.revision_status === 'PENDING_APPROVAL')
  if (pending) return `(Revision #${pending.id} awaiting approval)`
  
  const approved = props.revisions.find(r => r.revision_status === 'APPROVED')
  if (approved) return `(Revision #${approved.id} awaiting submission)`
  
  const submitted = props.revisions.find(r => r.revision_status === 'SUBMITTED')
  if (submitted) return `(Revision #${submitted.id} awaiting decision)`
  
  return ''
})

// Sort revisions by date (newest first)
const sortedRevisions = computed(() => {
  return [...props.revisions].sort((a, b) => 
    new Date(b.created_at) - new Date(a.created_at)
  )
})

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

const showComparison = (revision) => {
  selectedRevision.value = revision
}

const onRevisionRequested = (revision) => {
  showRequestModal.value = false
  emit('revision-requested', revision)
  emit('refresh')
}
</script>

<style scoped>
.revision-history-panel {
  border: 1px solid #ddd;
  border-radius: 6px;
  padding: 16px;
  margin-top: 24px;
  background: #f9fafb;
}

.panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
  padding-bottom: 12px;
  border-bottom: 2px solid #e5e7eb;
}

.panel-header h3 {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  color: #1f2937;
}

.text-muted {
  color: #6b7280;
}

.text-sm {
  font-size: 12px;
}

.revisions-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.revision-item {
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  padding: 12px;
  background: white;
  transition: box-shadow 0.2s;
}

.revision-item:hover {
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.revision-item.status-pending_approval {
  border-left: 4px solid #fbbf24;
}

.revision-item.status-approved {
  border-left: 4px solid #3b82f6;
}

.revision-item.status-rejected {
  border-left: 4px solid #ef4444;
}

.revision-item.status-submitted {
  border-left: 4px solid #f59e0b;
}

.revision-item.status-granted {
  border-left: 4px solid #10b981;
}

.revision-item.status-not_granted {
  border-left: 4px solid #ef4444;
}

.revision-header {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-bottom: 10px;
  flex-wrap: wrap;
}

.revision-id {
  font-weight: 600;
  color: #6b7280;
  font-size: 13px;
}

.status-badge {
  padding: 3px 8px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
}

.status-badge.warning {
  background: #fef3c7;
  color: #92400e;
}

.status-badge.danger {
  background: #fee2e2;
  color: #7f1d1d;
}

.status-badge.success {
  background: #dcfce7;
  color: #166534;
}

.status-badge.info {
  background: #dbeafe;
  color: #0c2d6b;
}

.revision-date {
  margin-left: auto;
  font-size: 11px;
  color: #9ca3af;
}

.revision-details {
  font-size: 13px;
  line-height: 1.6;
}

.revision-details p {
  margin: 6px 0;
}

.revision-details strong {
  color: #374151;
}

.status-icon {
  display: inline-block;
  margin: 8px 0;
  font-weight: 600;
  padding: 6px 10px;
  border-radius: 4px;
  background: #f3f4f6;
}

.status-icon.warning-icon {
  background: #fef3c7;
  color: #92400e;
}

.status-icon.success-icon {
  background: #dcfce7;
  color: #166534;
}

.status-icon.error-icon {
  background: #fee2e2;
  color: #7f1d1d;
}

.no-revisions {
  text-align: center;
  padding: 20px;
  color: #9ca3af;
}

.btn {
  padding: 6px 12px;
  border: 1px solid #d1d5db;
  border-radius: 4px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  transition: all 0.2s;
}

.btn-primary {
  background: #3b82f6;
  color: white;
  border-color: #3b82f6;
}

.btn-primary:hover {
  background: #2563eb;
}

.btn-info {
  background: #06b6d4;
  color: white;
  border-color: #06b6d4;
}

.btn-sm {
  padding: 4px 8px;
  font-size: 12px;
}

.pending-section,
.approved-section,
.submitted-section,
.granted-section,
.rejected-section,
.not-granted-section {
  margin-top: 8px;
  padding: 8px;
  border-radius: 4px;
  background: #f3f4f6;
}
</style>
