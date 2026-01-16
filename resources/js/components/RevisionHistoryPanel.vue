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
          <p><strong>Requested by:</strong> {{ revision.requested_by?.name || 'User #' + revision.requested_by_id }}</p>
          <p v-if="revision.reason"><strong>Reason:</strong> {{ revision.reason }}</p>

          <!-- PENDING State -->
          <div v-if="revision.revision_status === 'requested'" class="pending-section">
            <span class="status-icon warning-icon">⏳ Waiting for Holding approval...</span>
            
            <!-- Show approval button only for Holding users -->
            <div v-if="isHoldingUser" class="approval-actions mt-3">
              <button 
                @click="openApprovalModal(revision)"
                class="btn btn-sm btn-primary"
              >
                Review & Decide
              </button>
            </div>
          </div>

          <!-- APPROVED State -->
          <div v-if="revision.revision_status === 'approved'" class="approved-section">
            <span class="status-icon success-icon">✅ APPROVED - Changes Applied</span>
            <p v-if="revision.approved_at"><strong>Approved at:</strong> {{ formatDate(revision.approved_at) }}</p>
            <div v-if="revision.proposed_values" class="mt-2">
              <strong>Proposed Changes:</strong>
              <ul class="text-sm mt-1">
                <li v-for="(value, field) in revision.proposed_values" :key="field">
                  {{ fieldLabel(field) }}: {{ formatProposedValue(field, value) }}
                </li>
              </ul>
            </div>
            <!-- Document Changes -->
            <div v-if="revision.proposed_document_changes" class="mt-2">
              <strong>Document Changes:</strong>
              <div v-if="getDocumentChanges(revision).files_to_delete?.length > 0" class="text-sm mt-1">
                <span class="text-danger"><strong>Deleted:</strong></span>
                <ul class="mt-1">
                  <li v-for="docId in getDocumentChanges(revision).files_to_delete" :key="`del-${docId}`">
                    {{ getDocFileName(docId, revision) }}
                  </li>
                </ul>
              </div>
              <div v-if="getDocumentChanges(revision).files_to_add?.length > 0" class="text-sm mt-1">
                <span class="text-success"><strong>Files Added:</strong></span>
                <ul class="mt-1">
                  <li v-for="docId in getDocumentChanges(revision).files_to_add" :key="`add-${docId}`">
                    ✓ {{ getDocFileName(docId, revision) }}
                  </li>
                </ul>
              </div>
              <div v-if="!getDocumentChanges(revision).files_to_delete?.length && !getDocumentChanges(revision).files_to_add?.length && !getDocumentChanges(revision).new_files_names?.length" class="text-sm text-muted">
                No document changes
              </div>
            </div>
          </div>

          <!-- REJECTED State -->
          <div v-if="revision.revision_status === 'rejected'" class="rejected-section">
            <span class="status-icon error-icon">✗ REJECTED</span>
            <p v-if="revision.rejection_reason"><strong>Rejection Reason:</strong> {{ revision.rejection_reason }}</p>
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
    <RequestRevisionModalV2
      v-if="showRequestModal"
      :case-id="caseId"
      :stage-id="stageId"
      :available-fields="availableFields"
      :current-documents="currentDocuments"
      @submit="onRevisionRequested"
      @close="showRequestModal = false"
    />

    <!-- Before-After Comparison Modal -->
    <BeforeAfterComparison 
      v-if="selectedRevision"
      :revision="selectedRevision"
      @close="selectedRevision = null"
    />

    <!-- Revision Approval Modal (for Holding) -->
    <RevisionApprovalModalV2
      :visible="showApprovalModal"
      :revision="revisionToApprove"
      :case-id="caseId"
      :entity-type="entityType"
      :all-documents="currentDocuments"
      :tax-case="taxCase"
      :periods-list="periodsList"
      @close="showApprovalModal = false"
      @approved="onRevisionApproved"
      @rejected="onRevisionRejected"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useRevisionFields } from '@/composables/useRevisionFields'
import { useToast } from '@/composables/useToast'
import RequestRevisionModalV2 from './RequestRevisionModalV2.vue'
import BeforeAfterComparison from './BeforeAfterComparison.vue'
import RevisionApprovalModalV2 from './RevisionApprovalModalV2.vue'

const { getFieldLabel } = useRevisionFields()

const props = defineProps({
  caseId: { type: Number, required: true },
  stageId: { type: [String, Number], default: '1' },
  taxCase: { type: Object, required: true },
  revisions: { type: Array, default: () => [] },
  currentUser: { type: Object, default: null },
  currentDocuments: { type: Array, default: () => [] },
  periodsList: { type: Array, default: () => [] }, // List of periods for formatting
  availableFields: { 
    type: Array, 
    default: () => [
      'period_id',
      'currency_id',
      'disputed_amount',
      'supporting_docs'
    ]
  },
  entityType: { type: String, default: 'tax-cases' } // Support any entity type
})

const emit = defineEmits(['revision-requested', 'refresh'])
const { showSuccess, showError } = useToast()

const showRequestModal = ref(false)
const selectedRevision = ref(null)
const showApprovalModal = ref(false)
const revisionToApprove = ref(null)

// Check if user can request new revision
const canRequestRevision = computed(() => {
  // Data must be submitted - check workflow_history for stage 1 (SPT Filing)
  const isStageSubmitted = props.taxCase?.workflow_histories?.some(
    h => h.stage_id === 1 && (h.status === 'submitted' || h.status === 'approved')
  )
  
  if (!isStageSubmitted) return false
  
  // No pending revisions
  const pending = props.revisions.find(r => r.revision_status === 'requested')
  return !pending
})

// Message for why button is disabled
const revisionStatusMessage = computed(() => {
  const isStageSubmitted = props.taxCase?.workflow_histories?.some(
    h => h.stage_id === 1 && (h.status === 'submitted' || h.status === 'approved')
  )
  
  if (!isStageSubmitted) {
    return '(Submit data first to request revisions)'
  }
  
  const pending = props.revisions.find(r => r.revision_status === 'requested')
  if (pending) return `(Revision #${pending.id} awaiting review)`
  
  return ''
})

// Sort revisions by date (newest first)
const sortedRevisions = computed(() => {
  return [...props.revisions].sort((a, b) => 
    new Date(b.created_at) - new Date(a.created_at)
  )
})

// Check if current user is from Holding entity (can approve/reject revisions)
const isHoldingUser = computed(() => {
  if (!props.currentUser) return false
  return props.currentUser?.entity?.entity_type === 'HOLDING'
})

const statusClass = (status) => {
  const classMap = {
    'requested': 'warning',
    'approved': 'success',
    'rejected': 'danger',
    'implemented': 'info'
  }
  return classMap[status] || 'secondary'
}

const statusLabel = (status) => {
  const labelMap = {
    'requested': '⏳ Awaiting Review',
    'approved': '✅ APPROVED',
    'rejected': '✗ REJECTED',
    'implemented': '✓ Implemented'
  }
  return labelMap[status] || status
}

const formatDate = (date) => {
  if (!date) return '-'
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

const fieldLabel = (field) => {
  return getFieldLabel(props.entityType, field)
}

const showComparison = (revision) => {
  selectedRevision.value = revision
}

const onRevisionRequested = (revision) => {
  showRequestModal.value = false
  emit('revision-requested', revision)
  emit('refresh')
}

const openApprovalModal = (revision) => {
  revisionToApprove.value = revision
  showApprovalModal.value = true
}

const onRevisionApproved = (revision) => {
  showApprovalModal.value = false
  revisionToApprove.value = null
  emit('refresh')
  showSuccess('Revision Approved', 'Changes have been successfully applied.')
}

const onRevisionRejected = (revision) => {
  showApprovalModal.value = false
  revisionToApprove.value = null
  emit('refresh')
  showSuccess('Revision Rejected', 'The revision request has been rejected.')
}

const getDocFileName = (docId, revision) => {
  // First try to get from revision.documents (contains file details from backend)
  if (revision?.documents && revision.documents[docId]) {
    return revision.documents[docId].original_filename
  }
  // Fallback to currentDocuments prop
  const doc = props.currentDocuments.find(d => d.id === docId)
  return doc ? (doc.original_filename || doc.file_name || doc.name) : `Doc #${docId}`
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

const getDocumentChanges = (revision) => {
  if (!revision?.proposed_document_changes) {
    return { files_to_delete: [], files_to_add: [], new_files_names: [] }
  }
  const changes = revision.proposed_document_changes
  // Handle if it's a JSON string
  const parsed = typeof changes === 'string' ? JSON.parse(changes) : changes
  return parsed || { files_to_delete: [], files_to_add: [], new_files_names: [] }
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

.approval-actions {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid #e5e7eb;
}

.btn {
  padding: 8px 12px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-weight: 500;
  font-size: 0.85rem;
  transition: all 0.2s;
}

.btn-primary {
  background: #3b82f6;
  color: white;
}

.btn-primary:hover {
  background: #2563eb;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.btn-sm {
  padding: 6px 10px;
  font-size: 0.8rem;
}

.mt-3 {
  margin-top: 12px;
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
