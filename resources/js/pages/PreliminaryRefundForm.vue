<template>
  <div class="space-y-5">
    <Button @click="$router.push(`/tax-cases/${caseId}`)" variant="secondary">Back to case</Button>
    <h1 class="text-2xl font-bold">Catat Pengajuan Pengembalian Pendahuluan</h1>
    <p>Record an application already submitted to the Tax Office. Approval enables one Refund; a full received payment closes the case.</p>
    <p v-if="error" role="alert" class="text-red-700">{{ error }}</p>
    <p v-if="busy" role="status">Loading...</p>
    <template v-else>
      <p v-if="closed" class="font-bold">CLOSED: evidence remains available below.</p>
      <p v-if="!eligible && !closed">Submit Stage 1 for an explicit VAT LB preliminary case before recording the application.</p>
      <form v-if="eligible && caseData.preliminary_can_decide && !request && !closed" @submit.prevent="send('create')" class="space-y-3">
        <p>Status: SUBMITTED</p>
        <label class="block">Submission date (required) <input v-model="submissionDate" type="date" required class="border p-2" /></label>
        <label class="block">Requested amount (required) <input v-model="requestedAmount" type="text" inputmode="decimal" required pattern="[0-9]+(\.[0-9]{1,2})?" class="border p-2" /></label>
        <label class="block">Bukti Pengajuan (PDF, max 10 MB, required) <input type="file" accept=".pdf,application/pdf" required @change="chooseFile" /></label>
        <label class="block">Explanation <textarea v-model="notes" maxlength="5000" class="border p-2" /></label>
        <Button type="submit">Catat Pengajuan Pengembalian Pendahuluan</Button>
      </form>
      <div v-if="request" class="space-y-3 rounded border p-4">
        <p>Request {{ request.request_number }} / {{ request.approval_status }}</p>
        <p>Submission date: {{ request.submission_date?.slice(0, 10) }}; requested: {{ request.requested_amount }} {{ caseData.currency?.code }}</p>
        <p>Recorded by user {{ request.submitted_by }} at {{ request.submitted_at }}</p>
        <p v-if="request.notes">{{ request.notes }}</p>
        <p v-if="historical" role="status">Historical evidence is read-only and has not been asserted compliant with the current document requirements. Separate review is required; outcomes cannot be amended here.</p>
        <p v-if="request.submission_document">Bukti Pengajuan:
          <a :href="download(request.submission_document.id)" class="text-blue-700 underline">{{ request.submission_document.original_filename }}</a>
        </p>
        <template v-if="request.approved_by">
          <p>Source: external Tax Office decision. PorTax recorder: {{ request.approved_by }} at {{ request.approved_at }}</p>
          <p>Decision date: {{ request.decision_date?.slice(0, 10) || 'Not recorded historically' }}</p>
          <p v-if="request.status_comment">{{ request.status_comment }}</p>
          <p v-if="request.approval_status === 'APPROVED'">Approved: {{ request.approved_amount }} {{ caseData.currency?.code }}</p>
        </template>
        <p v-if="request.decision_document">Dokumen Keputusan:
          <a :href="download(request.decision_document.id)" class="text-blue-700 underline">{{ request.decision_document.original_filename }}</a>
        </p>
        <form v-if="request.approval_status === 'SUBMITTED' && !historical && caseData.preliminary_can_decide && !closed" @submit.prevent="send(outcome)" class="space-y-3">
          <h2 class="text-xl font-bold">Catat Keputusan Kantor Pajak</h2>
          <label class="block">External outcome <select v-model="outcome" class="border p-2"><option value="approve">APPROVED</option><option value="reject">REJECTED</option></select></label>
          <label class="block">Decision date (required) <input v-model="decisionDate" type="date" required class="border p-2" /></label>
          <label class="block">Dokumen Keputusan (PDF, max 10 MB, required) <input type="file" accept=".pdf,application/pdf" required @change="chooseFile" /></label>
          <label v-if="outcome === 'approve'" class="block">Approved amount (required) <input v-model="approvedAmount" type="text" inputmode="decimal" required pattern="[0-9]+(\.[0-9]{1,2})?" class="border p-2" /></label>
          <label class="block">Reason {{ outcome === 'reject' ? '(required, at least 10 characters)' : '(optional)' }} <textarea v-model="reason" :minlength="outcome === 'reject' ? 10 : undefined" :required="outcome === 'reject'" maxlength="5000" class="border p-2" /></label>
          <Button type="submit">Catat Keputusan Kantor Pajak</Button>
        </form>
      </div>
      <div v-for="refund in refunds" :key="refund.id" class="rounded border p-4 space-y-3">
        <p>{{ refund.refund_number }} / {{ refund.refund_amount }} / {{ refund.refund_status }}</p>
        <Button @click="$router.push(`/tax-cases/${caseId}/refunds/${refund.id}/refund-stages/${refund.current_refund_stage >= 2 ? refund.current_refund_stage : 2}`)">View payment / receipt evidence</Button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import Button from '../components/Button.vue'
const caseId = useRoute().params.id
const caseData = ref({})
const request = ref(null)
const busy = ref(true)
const error = ref('')
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
const download = id => `/api/documents/${id}/download`
function chooseFile(event) { documentFile.value = event.target.files?.[0] || null }
async function api(url, options = {}) {
  const response = await fetch(url, { credentials: 'include', ...options, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(document.querySelector('meta[name="csrf-token"]')?.content ? { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } : {}) } })
  const result = await response.json()
  if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Request failed')
  return result.data
}
async function load() {
  caseData.value = await api(base)
  try { request.value = await api(`${base}/preliminary-refund-request`) }
  catch (e) { if (!e.message.includes('No preliminary refund request found')) throw e }
}
async function send(action) {
  error.value = ''
  if (!documentFile.value || documentFile.value.size > 10485760 || !documentFile.value.name.toLowerCase().endsWith('.pdf') || (documentFile.value.type && documentFile.value.type !== 'application/pdf')) {
    error.value = 'A PDF document up to 10 MB is required.'
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
  } catch (e) { error.value = e.message }
  finally { busy.value = false }
}
onMounted(async () => {
  try { await load() } catch (e) { error.value = e.message } finally { busy.value = false }
})
</script>
