<template>
  <div class="mx-auto w-full max-w-5xl space-y-6">
    <Button @click="$router.push(`/tax-cases/${caseId}`)" variant="secondary">Back to case</Button>
    <header class="space-y-3">
      <h1 class="text-2xl sm:text-3xl font-bold tracking-tight text-gray-900">Record Preliminary Refund Application</h1>
      <p class="max-w-3xl text-sm leading-6 text-gray-600">Record an application already submitted to the Tax Office. An approved decision enables one Refund; receipt of the full amount closes the case.</p>
    </header>
    <Alert v-if="error" type="error" title="Unable to process request" :message="error" role="alert" />
    <Alert v-if="success" type="success" title="Success" :message="success" role="status" />
    <p v-if="busy" role="status" class="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-600">Loading or saving data...</p>
    <div v-else class="space-y-6">
      <p v-if="closed" class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm font-medium leading-6 text-green-800">Closed (CLOSED): evidence remains available below.</p>
      <p v-if="!eligible && !closed" role="status" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">Complete Stage 1 for a VAT LB case with the Preliminary Refund option before recording an application.</p>
      <p v-if="eligible && !caseData.preliminary_can_decide && !closed" role="status" class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm leading-6 text-blue-900">You can view evidence only. Permission to record applications and decisions is required to save changes.</p>
      <Card v-if="eligible && caseData.preliminary_can_decide && !request && !closed" title="Preliminary Refund" subtitle="Application details and Tax Office decision evidence">
        <form v-if="eligible && caseData.preliminary_can_decide && !request && !closed" @submit.prevent="send('create')" class="space-y-6">
          <p class="inline-flex rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset" :class="statusClasses('SUBMITTED')">Status: Submitted (SUBMITTED)</p>
          <label class="block text-sm font-medium text-gray-700 space-y-2">Submission date (required) <input v-model="submissionDate" type="date" required class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" /></label>
          <label class="block text-sm font-medium text-gray-700 space-y-2">Requested amount (required) <input v-model="requestedAmount" type="text" inputmode="decimal" required pattern="[0-9]+(\.[0-9]{1,2})?" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" /></label>
          <label class="block text-sm font-medium text-gray-700 space-y-2">Submission evidence (PDF, maximum 10 MB, required) <input type="file" accept=".pdf,application/pdf" required class="block w-full rounded-lg border border-dashed border-gray-300 p-4 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="chooseFile" /></label>
          <label class="block text-sm font-medium text-gray-700 space-y-2">Explanation <textarea v-model="notes" maxlength="5000" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" /></label>
          <Button type="submit" :disabled="busy">Record Preliminary Refund Application</Button>
        </form>
      </Card>
      <template v-if="request">
        <Card>
          <section aria-labelledby="application-heading" class="min-w-0 space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 pb-4">
              <div class="min-w-0">
                <h2 id="application-heading" class="text-lg font-semibold text-gray-900">Application details</h2>
                <p class="mt-1 break-words text-sm text-gray-500">Application {{ request.request_number }}</p>
              </div>
              <span class="inline-flex rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset" :class="statusClasses(request.approval_status)">{{ statusLabel(request.approval_status) }}</span>
            </div>
            <dl class="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2">
              <div><dt class="text-sm font-medium text-gray-500">Submission date</dt><dd class="mt-1 text-sm font-medium text-gray-900">{{ request.submission_date?.slice(0, 10) }}</dd></div>
              <div><dt class="text-sm font-medium text-gray-500">Requested amount</dt><dd class="mt-1 text-lg font-semibold text-gray-900">{{ request.requested_amount }} {{ caseData.currency?.code }}</dd></div>
              <div class="sm:col-span-2"><dt class="text-sm font-medium text-gray-500">Application recorder</dt><dd class="mt-1 break-words text-sm leading-6 text-gray-700">Recorded by {{ recorderName(request.submitted_by) }} on {{ recordedDateTime(request.submitted_at) }}</dd></div>
            </dl>
            <div class="border-t border-gray-100 pt-4">
              <h3 class="text-sm font-medium text-gray-500">Explanation</h3>
              <p class="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-gray-700">{{ request.notes || 'No explanation recorded.' }}</p>
            </div>
            <div v-if="request.submission_document" class="border-t border-gray-100 pt-4">
              <h3 class="mb-2 text-sm font-medium text-gray-700">Submission evidence:</h3>
              <a href="#" @click.prevent="downloadEvidence(request.submission_document)" class="flex min-w-0 flex-wrap items-center justify-between gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 transition-colors hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                <span class="min-w-0 break-all font-medium underline underline-offset-2">{{ request.submission_document.original_filename }}</span>
                <span class="shrink-0 text-xs font-medium">Download PDF</span>
              </a>
            </div>
            <p v-if="historical" role="status" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">Historical evidence is read-only and requires separate review for document completeness. Decision outcomes cannot be changed here.</p>
          </section>
        </Card>
        <Card>
          <section aria-labelledby="decision-heading" class="min-w-0 space-y-5">
            <div class="border-b border-gray-100 pb-4">
              <h2 id="decision-heading" class="text-lg font-semibold text-gray-900">Tax Office decision</h2>
              <p class="mt-1 text-sm leading-6 text-gray-500">Source: external Tax Office decision.</p>
            </div>
            <template v-if="request.approved_by || ['APPROVED', 'REJECTED'].includes(request.approval_status)">
              <dl class="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2">
                <div><dt class="text-sm font-medium text-gray-500">Decision date</dt><dd class="mt-1 text-sm font-medium text-gray-900">{{ request.decision_date?.slice(0, 10) || 'Not recorded historically' }}</dd></div>
                <div v-if="request.approval_status === 'APPROVED'"><dt class="text-sm font-medium text-gray-500">Approved amount:</dt><dd class="mt-1 text-lg font-semibold text-gray-900">{{ request.approved_amount }} {{ caseData.currency?.code }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-sm font-medium text-gray-500">Decision recorder</dt><dd class="mt-1 break-words text-sm leading-6 text-gray-700">PorTax recorder: {{ recorderName(request.approved_by) }} on {{ recordedDateTime(request.approved_at) }}</dd></div>
              </dl>
              <div class="border-t border-gray-100 pt-4">
                <h3 class="text-sm font-medium text-gray-500">Remarks</h3>
                <p class="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-gray-700">{{ request.status_comment || 'No remarks recorded.' }}</p>
              </div>
            </template>
            <p v-else class="text-sm leading-6 text-gray-500">No decision recorded.</p>
            <div v-if="request.decision_document" class="border-t border-gray-100 pt-4">
              <h3 class="mb-2 text-sm font-medium text-gray-700">Decision document:</h3>
              <a href="#" @click.prevent="downloadEvidence(request.decision_document)" class="flex min-w-0 flex-wrap items-center justify-between gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 transition-colors hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                <span class="min-w-0 break-all font-medium underline underline-offset-2">{{ request.decision_document.original_filename }}</span>
                <span class="shrink-0 text-xs font-medium">Download PDF</span>
              </a>
            </div>
            <form v-if="request.approval_status === 'SUBMITTED' && !historical && caseData.preliminary_can_decide && !closed" @submit.prevent="send(outcome)" class="space-y-6">
              <h3 class="text-lg font-semibold text-gray-900">Record Tax Office Decision</h3>
              <label class="block text-sm font-medium text-gray-700 space-y-2">Tax Office outcome <select v-model="outcome" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"><option value="approve">Approved (APPROVED)</option><option value="reject">Rejected (REJECTED)</option></select></label>
              <label class="block text-sm font-medium text-gray-700 space-y-2">Decision date (required) <input v-model="decisionDate" type="date" required class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" /></label>
              <label class="block text-sm font-medium text-gray-700 space-y-2">Decision document (PDF, maximum 10 MB, required) <input type="file" accept=".pdf,application/pdf" required class="block w-full rounded-lg border border-dashed border-gray-300 p-4 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="chooseFile" /></label>
              <label v-if="outcome === 'approve'" class="block text-sm font-medium text-gray-700 space-y-2">Approved amount (required) <input v-model="approvedAmount" type="text" inputmode="decimal" required pattern="[0-9]+(\.[0-9]{1,2})?" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" /></label>
              <label class="block text-sm font-medium text-gray-700 space-y-2">Reason {{ outcome === 'reject' ? '(required, at least 10 characters)' : '(optional)' }} <textarea v-model="reason" :minlength="outcome === 'reject' ? 10 : undefined" :required="outcome === 'reject'" maxlength="5000" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" /></label>
              <Button type="submit" :disabled="busy">Record Tax Office Decision</Button>
            </form>
          </section>
        </Card>
      </template>
      <section v-if="refunds.length" aria-labelledby="payments-heading" class="space-y-4">
        <h2 id="payments-heading" class="text-lg font-semibold text-gray-900">Related refunds and payments</h2>
        <Card v-for="refund in refunds" :key="refund.id">
          <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 pb-4">
            <h3 class="min-w-0 break-words text-base font-semibold text-gray-900">{{ refund.refund_number }}</h3>
            <span class="inline-flex rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset" :class="statusClasses(refund.refund_status)">{{ statusLabel(refund.refund_status) }}</span>
          </div>
          <dl class="my-4"><dt class="text-sm font-medium text-gray-500">Refund amount</dt><dd class="mt-1 text-lg font-semibold text-gray-900">{{ refund.refund_amount }} {{ caseData.currency?.code }}</dd></dl>
          <Button @click="$router.push(`/tax-cases/${caseId}/refunds/${refund.id}/refund-stages/${refund.current_refund_stage >= 2 ? refund.current_refund_stage : 2}`)">View payment / receipt evidence</Button>
        </Card>
      </section>
    </div>
  </div>
</template>

<script setup>
import { apiFetch as fetch, downloadPrivateDocument } from '../composables/useSessionApi.js'

import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import Button from '../components/Button.vue'
import Card from '../components/Card.vue'
import Alert from '../components/Alert.vue'
const caseId = useRoute().params.id
const caseData = ref({})
const request = ref(null)
const busy = ref(true)
const error = ref('')
const success = ref('')
const requestedAmount = ref('')
const approvedAmount = ref('')
const reason = ref('')
const notes = ref('')
const submissionDate = ref('')
const decisionDate = ref('')
const outcome = ref('approve')
const documentFile = ref(null)
const base = `/api/tax-cases/${caseId}`
const closed = computed(() => caseData.value.is_completed || caseData.value.status?.code === 'CLOSED')
const eligible = computed(() => caseData.value.case_type === 'VAT' && caseData.value.spt_position_type === 'LB' && caseData.value.spt_type === 'Pengembalian Pendahuluan' && caseData.value.workflow_state?.completed_stages?.includes(1))
const historical = computed(() => request.value && (request.value.deleted_at || ['PENDING', 'POSTPONED'].includes(request.value.approval_status) || !request.value.submission_document || (['APPROVED', 'REJECTED'].includes(request.value.approval_status) && !request.value.decision_document)))
const refunds = computed(() => (caseData.value.refund_processes || []).filter(r => Number(r.stage_id) === 0 || r.stage_source === 'PRELIMINARY'))
// Eager-loaded relationships serialize as submitted_by / approved_by, replacing FK values.
// Read only the display name; legacy numeric IDs and serialized objects are never fallbacks.
function recorderName(value) {
  const name = typeof value === 'string' ? value : value && !Array.isArray(value) && typeof value === 'object' ? value.name : null
  if (typeof name !== 'string') return 'Unknown user'
  const display = name.trim()
  return display && display.length <= 200 && !/[{}\[\]@\x00-\x1f]/.test(display) && !/^\d+$/.test(display) ? display : 'Unknown user'
}
// Match the existing English short-month display pattern; UTC avoids device-dependent dates.
function recordedDateTime(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T.*(?:Z|[+-]\d{2}:\d{2})$/i.test(value)) return 'Date unavailable'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return 'Date unavailable'
  return date.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: 'UTC', timeZoneName: 'short' })
}
function statusLabel(value) {
  const labels = { SUBMITTED: 'Submitted', APPROVED: 'Approved', REJECTED: 'Rejected', PENDING: 'Pending', POSTPONED: 'Postponed', INITIATED: 'Initiated', PROCESSING: 'Processing', PROCESSED: 'Processed', COMPLETED: 'Completed' }
  const code = typeof value === 'string' ? value.toUpperCase() : ''
  return Object.hasOwn(labels, code) ? `${labels[code]} (${code})` : 'Unknown status'
}
// Colors supplement the existing status text; unknown values remain neutral.
function statusClasses(value) {
  const code = typeof value === 'string' ? value.toUpperCase() : ''
  if (['APPROVED', 'COMPLETED'].includes(code)) return 'bg-green-50 text-green-800 ring-green-600/20'
  if (code === 'REJECTED') return 'bg-red-50 text-red-800 ring-red-600/20'
  if (['PENDING', 'POSTPONED'].includes(code)) return 'bg-amber-50 text-amber-900 ring-amber-600/20'
  if (['SUBMITTED', 'INITIATED', 'PROCESSING', 'PROCESSED'].includes(code)) return 'bg-blue-50 text-blue-800 ring-blue-600/20'
  return 'bg-gray-100 text-gray-700 ring-gray-500/20'
}
async function downloadEvidence(doc) {
  try { await downloadPrivateDocument(doc.id, doc.original_filename) }
  catch (e) { error.value = 'Evidence could not be downloaded. Check your access permissions and try again.' }
}
function chooseFile(event) { documentFile.value = event.target.files?.[0] || null }
async function api(url, options = {}) {
  const response = await fetch(url, { credentials: 'include', ...options, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
  const result = await response.json()
  if (!response.ok) {
    const labels = { submission_date: 'submission date', requested_amount: 'requested amount', document: 'PDF document', decision_date: 'decision date', approved_amount: 'approved amount', reason: 'reason', notes: 'explanation' }
    const fields = Object.keys(result.errors || {}).map(key => labels[key]).filter(Boolean)
    const message = response.status === 403 ? 'You do not have permission to perform this action.'
      : response.status === 419 ? 'Your session has expired. Reload the page and try again.'
      : response.status === 422 ? `The data could not be saved. Check ${fields.join(', ') || 'the case status, required evidence, and requested amount'}.`
      : 'The request could not be processed. Please try again.'
    throw Object.assign(new Error(message), { status: response.status })
  }
  return result.data
}
async function load() {
  caseData.value = await api(base)
  try { request.value = await api(`${base}/preliminary-refund-request`) }
  catch (e) { if (e.status !== 404) throw e }
}
async function send(action) {
  error.value = ''
  success.value = ''
  if (!documentFile.value || documentFile.value.size > 10485760 || !documentFile.value.name.toLowerCase().endsWith('.pdf') || (documentFile.value.type && documentFile.value.type !== 'application/pdf')) {
    error.value = 'A PDF document no larger than 10 MB is required.'
    return
  }
  const payload = new FormData()
  payload.append('document', documentFile.value)
  if (action === 'create') {
    payload.append('submission_date', submissionDate.value)
    payload.append('requested_amount', requestedAmount.value)
    if (notes.value) payload.append('notes', notes.value)
  } else {
    payload.append('decision_date', decisionDate.value)
    if (action === 'approve') payload.append('approved_amount', approvedAmount.value)
    if (reason.value) payload.append('reason', reason.value)
  }
  busy.value = true
  try {
    const url = `${base}/preliminary-refund-request` + (action === 'create' ? '' : `/${request.value.id}/${action}`)
    await api(url, { method: 'POST', body: payload })
    documentFile.value = null
    await load()
    success.value = action === 'create' ? 'Application recorded successfully.' : 'Tax Office decision recorded successfully.'
  } catch (e) { error.value = e.status ? e.message : 'Connection error. Please try again.' }
  finally { busy.value = false }
}
onMounted(async () => {
  try { await load() } catch (e) { error.value = e.status ? e.message : 'Connection error. Please try again.' } finally { busy.value = false }
})
</script>
