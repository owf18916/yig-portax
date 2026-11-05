<template>
  <main class="kian-page mx-auto w-full max-w-6xl space-y-6 p-4 sm:p-6">
    <Button variant="secondary" class="focus:ring-2 focus:ring-blue-500" @click="router.push(`/tax-cases/${caseId}`)">Back to case</Button>
    <header><h1 class="text-2xl font-bold text-gray-900">Stage {{ stageId }} KIAN</h1><p class="mt-2 text-sm text-gray-600">{{ caseNumber }} · Internal Loss Recognition. KIAN response and closure do not advance or close the tax case.</p></header>
    <Alert v-if="error" type="error" title="Unable to complete request" :message="error" role="alert" />
    <Alert v-if="success" type="success" title="Success" :message="success" role="status" />
    <p v-if="isLoading" role="status">Loading KIAN details...</p>
    <Button v-if="!isLoading" variant="secondary" :disabled="mutationBusy" @click="loadPage">Reload KIAN details</Button>
    <template v-if="resolved">
      <Card title="KIAN submission details">
        <dl class="grid min-w-0 grid-cols-1 gap-5 text-sm sm:grid-cols-2">
          <div><dt class="text-gray-500">Lifecycle</dt><dd class="mt-1"><span class="inline-flex rounded-full bg-gray-100 px-3 py-1 font-medium text-gray-800">{{ lifecycleLabel(submission?.status) }}</span></dd></div>
          <div><dt class="text-gray-500">KIAN number</dt><dd class="mt-1 break-all">{{ safeText(submission?.kian_number, 'Not recorded') }}</dd></div>
          <div><dt class="text-gray-500">Submission date</dt><dd>{{ dateLabel(submission?.submission_date) }}</dd></div>
          <div><dt class="text-gray-500">Submitted amount</dt><dd>{{ amountLabel(submission?.kian_amount) }}</dd></div>
          <div class="sm:col-span-2"><dt class="text-gray-500">Submission notes</dt><dd class="whitespace-pre-wrap break-words">{{ safeText(submission?.notes, 'No notes recorded.') }}</dd></div>
        </dl>
        <p v-if="!draftEditable" class="mt-5 text-sm leading-6 text-gray-600">Submission details are read-only. Historical statuses do not establish a final Tax Office response. Actions depend on the current server state and your permissions.</p>
        <p v-if="!submission" class="mt-4 text-sm text-gray-600">No KIAN submission is recorded for this stage.</p>
      </Card>
      <StageForm v-if="draftEditable" :key="scopeKey" :stageName="`Stage ${stageId}: KIAN Submission`"
        stageDescription="KIAN submission details" :stageId="stageId" :nextStageId="null" :caseId="caseId"
        :caseNumber="caseNumber" :fields="fields" :apiEndpoint="stageUrl" :isReviewMode="false"
        :isLoading="isLoading" :caseStatus="caseStatus" :prefillData="prefillData"
        preFilledMessage="Review the current stage details before saving or submitting."
        @submit="loadPage" @saveDraft="loadPage" />
      <Card title="Tax Office final response" subtitle="Record the external response exactly as issued. Saved evidence cannot be edited or replaced.">
        <template v-if="finalResponse">
          <p class="mb-5"><span class="inline-flex rounded-full px-3 py-1 text-sm font-medium" :class="outcomeClass(finalResponse.outcome)">Office outcome: {{ outcomeLabel(finalResponse.outcome) }}</span></p>
          <dl class="grid min-w-0 grid-cols-1 gap-5 text-sm sm:grid-cols-2">
            <div><dt class="text-gray-500">Response reference</dt><dd class="break-all">{{ safeText(finalResponse.reference, 'Not available') }}</dd></div>
            <div><dt class="text-gray-500">Recognized amount</dt><dd>{{ amountLabel(finalResponse.approved_amount) }}</dd></div>
            <div><dt class="text-gray-500">Office issued date</dt><dd>{{ dateLabel(finalResponse.issued_date) }}</dd></div>
            <div><dt class="text-gray-500">Received date</dt><dd>{{ dateLabel(finalResponse.received_date) }}</dd></div>
            <div><dt class="text-gray-500">Response recorder</dt><dd class="break-words">{{ recorderName(finalResponse.recorded_by) }}</dd></div>
            <div><dt class="text-gray-500">Recorded at</dt><dd>{{ recordedDateTime(finalResponse.recorded_at) }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-gray-500">Response notes</dt><dd class="whitespace-pre-wrap break-words">{{ safeText(finalResponse.notes, 'No notes recorded.') }}</dd></div>
          </dl>
          <div class="mt-5 border-t border-gray-100 pt-4"><h3 class="mb-2 text-sm font-medium">Private response PDF</h3>
            <button v-if="documentId(finalResponse.document)" type="button" :disabled="downloading" @click="downloadEvidence(finalResponse.document)" :class="documentClass"><span class="min-w-0 break-all underline">{{ filename(finalResponse.document) }}</span><span>Download PDF</span></button>
            <p v-else class="text-sm text-gray-600">Response document is unavailable in this view. This does not establish eligibility for further actions.</p>
          </div>
        </template>
        <p v-else class="text-sm text-gray-600">No final response is available in this view. Historical evidence and lifecycle status do not imply a final outcome.</p>
        <form v-if="canRecord" class="mt-6 space-y-5 border-t border-gray-100 pt-5" @submit.prevent="send('record-response')">
          <h3 class="text-lg font-semibold">Record Tax Office Response</h3>
          <fieldset :disabled="mutationBusy" class="space-y-5">
            <label class="block text-sm font-medium">Office outcome (required)<select v-model="responseStatus" required :class="inputClass"><option value="">Select outcome</option><option value="ACCEPTED">Accepted</option><option value="PARTIALLY_ACCEPTED">Partially accepted</option><option value="REJECTED">Rejected</option></select></label>
            <label class="block text-sm font-medium">Response reference (required)<input v-model="responseReference" type="text" required maxlength="255" :class="inputClass" /></label>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
              <label class="block text-sm font-medium">Office issued date (required)<input v-model="issuedDate" type="date" required :class="inputClass" /></label>
              <label class="block text-sm font-medium">Received date (required)<input v-model="receivedDate" type="date" required :class="inputClass" /></label>
            </div>
            <label class="block text-sm font-medium">Recognized amount (optional)<input v-model="approvedAmount" type="text" inputmode="decimal" pattern="[0-9]{1,18}(\.[0-9]{1,2})?" :class="inputClass" /><span class="mt-1 block text-xs text-gray-500">Amount recognized by the Tax Office, up to the submitted amount. Leave blank if not recorded; zero is a recorded amount.</span></label>
            <label class="block text-sm font-medium">Private response PDF (required, maximum 10 MiB)<input :key="fileKey" type="file" accept=".pdf,application/pdf" required :class="inputClass" @change="chooseFile" /></label>
            <label class="block text-sm font-medium">Response notes (optional)<textarea v-model="responseNotes" maxlength="5000" rows="4" :class="inputClass" /></label>
            <Button type="submit" :disabled="mutationBusy" class="focus:ring-2 focus:ring-blue-500">{{ mutationBusy ? 'Recording response...' : 'Record Tax Office Response' }}</Button>
          </fieldset>
        </form>
      </Card>
      <Card title="KIAN closure">
        <dl v-if="submission?.closure" class="grid grid-cols-1 gap-5 text-sm sm:grid-cols-2">
          <div><dt class="text-gray-500">Closed by</dt><dd class="break-words">{{ recorderName(submission.closure.closed_by) }}</dd></div>
          <div><dt class="text-gray-500">Closed at</dt><dd>{{ recordedDateTime(submission.closure.closed_at) }}</dd></div>
        </dl>
        <p v-else class="text-sm text-gray-600">No closure is available in this view. A complete final response, including Rejected, may permit closure when authorized by the server.</p>
        <div v-if="canClose" class="mt-5 space-y-4">
          <Button v-if="!confirmClose" type="button" :disabled="mutationBusy" class="focus:ring-2 focus:ring-blue-500" @click="confirmClose = true">Close KIAN</Button>
          <section v-else aria-labelledby="close-confirm-heading" class="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
            <h3 id="close-confirm-heading" class="font-semibold">Confirm KIAN closure</h3>
            <p class="text-sm leading-6">Closure is terminal. The response and evidence remain readable. This closes only this stage's KIAN and does not advance or close the tax case.</p>
            <div class="flex flex-wrap gap-3"><Button type="button" :disabled="mutationBusy" @click="send('close')">{{ mutationBusy ? 'Closing KIAN...' : 'Confirm closure' }}</Button><Button type="button" variant="secondary" :disabled="mutationBusy" @click="confirmClose = false">Cancel</Button></div>
          </section>
        </div>
      </Card>
      <Card title="Submission documents and historical evidence">
        <div class="space-y-3"><button v-for="doc in currentDocuments" :key="doc.id" type="button" :disabled="downloading" @click="downloadEvidence(doc)" :class="documentClass"><span class="min-w-0 break-all underline">{{ filename(doc) }}</span><span>Download document</span></button></div>
        <p v-if="!currentDocuments.length" class="text-sm text-gray-600">No submission documents are available in this view.</p>
        <p v-if="documentsError" class="mt-3 text-sm text-gray-600" role="status">Submission documents could not be loaded. Reload details to try again.</p>
      </Card>
    </template>
  </main>
</template>
<script setup>
import { ref, computed, watch, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiFetch as fetch, downloadPrivateDocument } from '../composables/useSessionApi.js'
import StageForm from '../components/StageForm.vue'
import Card from '../components/Card.vue'
import Alert from '../components/Alert.vue'
import Button from '../components/Button.vue'

const route = useRoute()
const router = useRouter()
const caseId = computed(() => Number(route.params.id))
const stageId = computed(() => Number(route.params.stageId))
const scopeKey = computed(() => `${caseId.value}:${stageId.value}`)
const validScope = computed(() => Number.isSafeInteger(caseId.value) && caseId.value > 0 && [4, 7, 10, 12].includes(stageId.value))
const stageUrl = computed(() => `/api/tax-cases/${caseId.value}/kian-submissions/${stageId.value}`)
const submission = ref(null)
const draftActions = ref({})
const caseData = ref({})
const loadedScope = ref('')
const isLoading = ref(true)
const mutationBusy = ref(false)
const error = ref('')
const success = ref('')
const confirmClose = ref(false)
const currentDocuments = ref([])
const documentsError = ref(false)
const downloading = ref(false)
const responseStatus = ref('')
const responseReference = ref('')
const issuedDate = ref('')
const receivedDate = ref('')
const approvedAmount = ref('')
const responseNotes = ref('')
const documentFile = ref(null)
const fileKey = ref(0)
let readGeneration = 0
const resolved = computed(() => validScope.value && !isLoading.value && loadedScope.value === scopeKey.value)
const caseNumber = computed(() => safeText(caseData.value.case_number, 'Case details'))
const caseStatus = computed(() => caseData.value.case_status_id)
const draftEditable = computed(() => resolved.value && !mutationBusy.value && draftActions.value.save_draft === true && draftActions.value.submit === true && (!submission.value ? draftActions.value.create === true : submission.value.status === 'draft'))
const finalResponse = computed(() => submission.value?.final_response && typeof submission.value.final_response === 'object' ? submission.value.final_response : null)
const canRecord = computed(() => resolved.value && submission.value?.status === 'submitted' && !finalResponse.value && submission.value?.available_actions?.record_response === true)
const canClose = computed(() => resolved.value && submission.value?.status === 'responded' && !!finalResponse.value && !submission.value?.closure && submission.value?.available_actions?.close === true)
const prefillData = computed(() => ({
  kian_number: submission.value?.kian_number || '', submission_date: submission.value?.submission_date?.slice(0, 10) || null,
  loss_amount: submission.value?.loss_amount ?? submission.value?.kian_amount ?? caseData.value.kian_status_by_stage?.[stageId.value]?.lossAmount ?? '0',
  notes: submission.value?.notes || '', kianSubmissionStatus: submission.value?.status ?? 'draft',
  kianDraftActions: resolved.value ? draftActions.value : {},
}))
const fields = [
  { id: 1, key: 'kian_number', label: 'KIAN number', type: 'text', required: true, order: 1 },
  { id: 2, key: 'submission_date', label: 'Submission date', type: 'date', required: true, order: 2 },
  { id: 3, key: 'loss_amount', label: 'Loss amount (pre-calculated)', type: 'text', readonly: true, order: 3 },
  { id: 4, key: 'notes', label: 'Notes', type: 'textarea', placeholder: 'Optional notes', order: 4 },
]
const inputClass = 'mt-2 block w-full min-w-0 rounded-lg border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-60'
const documentClass = 'flex w-full min-w-0 flex-wrap items-center justify-between gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-left text-sm text-blue-800 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-60'
function safeText(value, fallback) { return typeof value === 'string' && value.trim() ? value : fallback }
function lifecycleLabel(value) {
  const labels = { draft: 'Draft', submitted: 'Submitted', responded: 'Responded', closed: 'Closed', approved: 'Historical approved', rejected: 'Historical rejected' }
  return Object.hasOwn(labels, value) ? labels[value] : submission.value ? 'Unknown lifecycle — read-only' : 'Not recorded'
}
function outcomeLabel(value) {
  const labels = { ACCEPTED: 'Accepted', PARTIALLY_ACCEPTED: 'Partially accepted', REJECTED: 'Rejected' }
  return Object.hasOwn(labels, value) ? labels[value] : 'Unknown outcome'
}
function outcomeClass(value) { return value === 'REJECTED' ? 'bg-red-50 text-red-800' : value === 'ACCEPTED' ? 'bg-green-50 text-green-800' : 'bg-gray-100 text-gray-800' }
function amountLabel(value) {
  if (value === null || value === undefined || value === '') return 'Not recorded'
  const text = String(value)
  if (!/^\d+(?:\.\d{1,2})?$/.test(text)) return 'Amount unavailable'
  const [whole, fraction = ''] = text.split('.')
  return `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${fraction.padEnd(2, '0')}`
}
function dateLabel(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}(?:T|$)/.test(value)) return 'Date unavailable'
  if (!validDate(value.slice(0, 10))) return 'Date unavailable'
  const date = new Date(`${value.slice(0, 10)}T00:00:00Z`)
  return Number.isNaN(date.getTime()) ? 'Date unavailable' : date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC' })
}
function recorderName(value) {
  const name = value && typeof value === 'object' && !Array.isArray(value) ? value.name : null
  return typeof name === 'string' && name.trim() && name.length <= 200 && !/[{}\[\]@\x00-\x1f\\/]/.test(name) && !/^\d+$/.test(name) ? name.trim() : 'Unknown user'
}
// Follow the adjacent Preliminary Refund display convention: English short month, explicit UTC.
function recordedDateTime(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T.*(?:Z|[+-]\d{2}:\d{2})$/i.test(value)) return 'Date unavailable'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? 'Date unavailable' : date.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: 'UTC', timeZoneName: 'short' })
}
function documentId(doc) { return Number.isSafeInteger(Number(doc?.id)) && Number(doc?.id) > 0 ? Number(doc.id) : null }
function filename(doc) { return safeText(doc?.original_filename, 'Document').split(/[\\/]/).pop() || 'Document' }
function failureMessage(status) {
  if (status === 403 || status === 401) return 'Your session or permissions do not allow this request. Verify your session and reload details.'
  if (status === 419) return 'Your session has expired. Sign in again and reload details before retrying.'
  if (status === 409 || status === 422) return 'The request was not accepted. Check the required fields, PDF, dates and recognized amount, then reload the current stage before retrying.'
  return 'The result could not be confirmed. Reload the current stage to check its recorded state before retrying.'
}
async function api(url, options = {}) {
  let response
  try { response = await fetch(url, { credentials: 'include', ...options }) }
  catch (failure) {
    // The session transport classifies 401/419 before returning a Response.
    const status = failure.message?.startsWith('Session/token error (419)') ? 419 : failure.message?.startsWith('Session expired.') ? 401 : undefined
    const safeFailure = new Error(failureMessage(status)); safeFailure.status = status; throw safeFailure
  }
  if (!response.ok) { const failure = new Error(failureMessage(response.status)); failure.status = response.status; throw failure }
  return response.json()
}
function scopedRecord(result) {
  if (!result || !Object.hasOwn(result, 'data')) throw new Error('Invalid KIAN projection')
  const record = result.data
  if (record !== null && (!documentId(record) || Number(record.stage_id) !== stageId.value || Number(record.tax_case_id) !== caseId.value)) throw new Error('KIAN scope mismatch')
  return record
}
function resetResponse() {
  responseStatus.value = ''; responseReference.value = ''; issuedDate.value = ''; receivedDate.value = ''
  approvedAmount.value = ''; responseNotes.value = ''; documentFile.value = null; fileKey.value++
  confirmClose.value = false
}
async function loadPage() {
  const generation = ++readGeneration
  const key = scopeKey.value
  loadedScope.value = ''; submission.value = null; draftActions.value = {}; currentDocuments.value = []; caseData.value = {}
  isLoading.value = true; error.value = ''; success.value = ''; resetResponse()
  if (!validScope.value) { error.value = 'Select a valid case and KIAN stage (4, 7, 10 or 12).'; isLoading.value = false; return }
  try {
    const [caseResult, result] = await Promise.all([api(`/api/tax-cases/${caseId.value}`), api(stageUrl.value)])
    if (generation !== readGeneration || key !== scopeKey.value) return
    const model = caseResult.data ?? caseResult
    if (Number(model?.id) !== caseId.value) throw new Error('Case scope mismatch')
    submission.value = scopedRecord(result); draftActions.value = result.draft_actions ?? {}; caseData.value = model; loadedScope.value = key
    // Historical documents use the established bounded stage/type read; no per-document requests.
    documentsError.value = false
    try {
      const docs = await api(`/api/tax-cases/${caseId.value}/documents?stage_code=${stageId.value}&documentable_type=App%5CModels%5CKianSubmission`)
      if (generation !== readGeneration || key !== scopeKey.value) return
      currentDocuments.value = (Array.isArray(docs.data) ? docs.data : []).filter(doc => documentId(doc) && Number(doc.stage_code) === stageId.value)
    } catch { if (generation === readGeneration && key === scopeKey.value) documentsError.value = true }
  } catch (failure) { if (generation === readGeneration && key === scopeKey.value) { loadedScope.value = ''; error.value = failureMessage(failure.status) } }
  finally { if (generation === readGeneration && key === scopeKey.value) isLoading.value = false }
}
function chooseFile(event) { documentFile.value = event.target.files?.[0] ?? null }
function validDate(value) { return /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(Date.parse(value)) && new Date(value).toISOString().slice(0, 10) === value }
async function responsePayload() {
  if (!['ACCEPTED', 'PARTIALLY_ACCEPTED', 'REJECTED'].includes(responseStatus.value) || !responseReference.value.trim() || responseReference.value.length > 255 || !validDate(issuedDate.value) || !validDate(receivedDate.value) || responseNotes.value.length > 5000) throw new Error('Complete the required response fields and use valid dates.')
  const amount = approvedAmount.value
  if (amount !== '' && !/^\d{1,18}(?:\.\d{1,2})?$/.test(amount)) throw new Error('Use an exact nonnegative amount with up to two decimal places, or leave it blank.')
  const file = documentFile.value
  if (!file || !/\.pdf$/i.test(file.name) || file.type !== 'application/pdf' || file.size <= 0 || file.size > 10 * 1024 * 1024) throw new Error('Choose a PDF document of at most 10 MiB.')
  const header = await file.slice(0, 5).text()
  if (header !== '%PDF-') throw new Error('Choose a genuine PDF document. The server also validates the file content.')
  const payload = new FormData()
  payload.append('document', file)
  payload.append('response_status', responseStatus.value)
  payload.append('response_reference', responseReference.value)
  payload.append('response_issued_date', issuedDate.value)
  payload.append('response_received_date', receivedDate.value)
  if (amount !== '') payload.append('approved_amount', amount)
  if (responseNotes.value !== '') payload.append('response_notes', responseNotes.value)
  return payload
}
async function send(command) {
  if (mutationBusy.value || !resolved.value || (command === 'record-response' ? !canRecord.value : command !== 'close' || !canClose.value || !confirmClose.value)) return
  mutationBusy.value = true; error.value = ''; success.value = ''
  const key = scopeKey.value
  const generation = readGeneration
  const id = submission.value.id
  const url = `/api/tax-cases/${caseId.value}/kian-submissions/${id}/${command}`
  let sent = false
  try {
    const body = command === 'record-response' ? await responsePayload() : undefined
    if (key !== scopeKey.value || generation !== readGeneration || !resolved.value || submission.value?.id !== id) return
    sent = true
    await api(url, command === 'close' ? { method: 'POST' } : { method: 'POST', body })
    if (key !== scopeKey.value || generation !== readGeneration) return
    // Only the current stage read confirms the result; never fabricate a response from the command.
    loadedScope.value = ''
    const result = await api(stageUrl.value)
    if (key !== scopeKey.value || generation !== readGeneration) return
    submission.value = scopedRecord(result); draftActions.value = result.draft_actions ?? {}; loadedScope.value = key
    const confirmed = command === 'close' ? submission.value?.status === 'closed' && submission.value?.closure : ['responded', 'closed'].includes(submission.value?.status) && submission.value?.final_response
    if (!confirmed) throw new Error('Unconfirmed command')
    resetResponse()
    success.value = command === 'close' ? 'KIAN closure confirmed.' : 'Tax Office response recorded and confirmed.'
  } catch (failure) {
    if (key !== scopeKey.value || generation !== readGeneration) return
    error.value = sent ? failureMessage(failure.status) : failure.message
    if (sent) loadedScope.value = ''
  } finally { mutationBusy.value = false }
}
async function downloadEvidence(doc) {
  if (!resolved.value || downloading.value || !documentId(doc)) return
  const key = scopeKey.value
  downloading.value = true
  try { await downloadPrivateDocument(documentId(doc), filename(doc)) }
  catch { if (key === scopeKey.value) error.value = 'The private document could not be downloaded. Verify your session and document access, then try again.' }
  finally { downloading.value = false }
}
watch(scopeKey, loadPage, { immediate: true, flush: 'sync' })
onUnmounted(() => { readGeneration++; loadedScope.value = '' })
</script>
<style scoped>
/* Keep the existing submission form usable within this stage-scoped page on narrow screens. */
.kian-page :deep(.h-screen) { height: auto; min-height: 36rem; }
@media (max-width: 767px) {
  .kian-page :deep(.h-screen) { flex-direction: column; }
  .kian-page :deep(.w-1\/2) { width: 100%; }
}
</style>
