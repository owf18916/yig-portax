<template>
  <main class="kian-page mx-auto w-full max-w-6xl space-y-6 p-4 sm:p-6">
    <Button variant="secondary" class="focus:ring-2 focus:ring-blue-500" @click="router.push(`/tax-cases/${caseId}`)">Back to case</Button>
    <header><h1 class="text-2xl font-bold text-gray-900">Stage {{ stageId }} KIAN</h1><p class="mt-2 text-sm text-gray-600">{{ caseNumber }} · Internal Loss Recognition. Upload the internally signed and approved document, then submit to complete the KIAN record.</p></header>
    <Alert v-if="error" type="error" title="Unable to complete request" :message="error" role="alert" />
    <p v-if="isLoading" role="status">Loading KIAN details...</p>
    <Button v-if="!isLoading" variant="secondary" @click="loadPage">Reload KIAN details</Button>
    <template v-if="resolved">
      <Card title="KIAN submission details">
        <dl class="grid min-w-0 grid-cols-1 gap-5 text-sm sm:grid-cols-2">
          <div><dt class="text-gray-500">Lifecycle</dt><dd class="mt-1"><span class="inline-flex rounded-full bg-gray-100 px-3 py-1 font-medium text-gray-800">{{ lifecycleLabel(submission?.status) }}</span></dd></div>
          <div><dt class="text-gray-500">KIAN number</dt><dd class="mt-1 break-all">{{ safeText(submission?.kian_number, 'Not recorded') }}</dd></div>
          <div><dt class="text-gray-500">Submission date</dt><dd>{{ dateLabel(submission?.submission_date) }}</dd></div>
          <div><dt class="text-gray-500">Submitted amount</dt><dd>{{ amountLabel(submission?.kian_amount) }}</dd></div>
          <div class="sm:col-span-2"><dt class="text-gray-500">Submission notes</dt><dd class="whitespace-pre-wrap break-words">{{ safeText(submission?.notes, 'No notes recorded.') }}</dd></div>
        </dl>
        <p v-if="!draftEditable" class="mt-5 text-sm leading-6 text-gray-600">Submission details are read-only. Submission completes the internal KIAN process.</p>
        <p v-if="!submission" class="mt-4 text-sm text-gray-600">No KIAN submission is recorded for this stage.</p>
      </Card>
      <StageForm v-if="draftEditable" :key="scopeKey" :stageName="`Stage ${stageId}: KIAN Submission`"
        :stageDescription="Number(stageId) === 4 ? 'Enter the signed internal KIAN amount.' : 'Enter the incremental additional signed KIAN amount, independent of the Stage 4 baseline.'" :stageId="stageId" :nextStageId="null" :caseId="caseId"
        :caseNumber="caseNumber" :fields="fields" :apiEndpoint="stageUrl" :isReviewMode="false"
        :isLoading="isLoading" :caseStatus="caseStatus" :prefillData="prefillData"
        preFilledMessage="Review the current stage details before saving or submitting."
        @submit="loadPage" @saveDraft="loadPage" />
      <Card v-if="finalResponse" title="Historical response evidence" subtitle="Previously recorded evidence is retained read-only.">
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
      </Card>
      <Card v-if="submission?.closure" title="Historical KIAN closure">
        <dl v-if="submission?.closure" class="grid grid-cols-1 gap-5 text-sm sm:grid-cols-2">
          <div><dt class="text-gray-500">Closed by</dt><dd class="break-words">{{ recorderName(submission.closure.closed_by) }}</dd></div>
          <div><dt class="text-gray-500">Closed at</dt><dd>{{ recordedDateTime(submission.closure.closed_at) }}</dd></div>
        </dl>
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
const error = ref('')
const currentDocuments = ref([])
const documentsError = ref(false)
const downloading = ref(false)
let readGeneration = 0
const resolved = computed(() => validScope.value && !isLoading.value && loadedScope.value === scopeKey.value)
const caseNumber = computed(() => safeText(caseData.value.case_number, 'Case details'))
const caseStatus = computed(() => caseData.value.case_status_id)
const draftEditable = computed(() => resolved.value && draftActions.value.save_draft === true && draftActions.value.submit === true && (!submission.value ? draftActions.value.create === true : submission.value.status === 'draft'))
const finalResponse = computed(() => submission.value?.final_response && typeof submission.value.final_response === 'object' ? submission.value.final_response : null)
const prefillData = computed(() => ({
  kian_number: submission.value?.kian_number || '', submission_date: submission.value?.submission_date?.slice(0, 10) || null,
  loss_amount: submission.value?.kian_amount ?? submission.value?.loss_amount ?? '',
  notes: submission.value?.notes || '', kianSubmissionStatus: submission.value?.status ?? 'draft',
  kianDraftActions: resolved.value ? draftActions.value : {},
}))
const fields = computed(() => [
  { id: 1, key: 'kian_number', label: 'KIAN number', type: 'text', required: true, order: 1 },
  { id: 2, key: 'submission_date', label: 'Submission date', type: 'date', required: true, order: 2 },
  { id: 3, key: 'loss_amount', label: `Signed internal KIAN amount (${caseData.value.currency?.code ?? 'Currency unavailable'})`, type: 'text', required: true, placeholder: 'Enter the signed document amount; up to two decimal places', order: 3 },
  { id: 4, key: 'notes', label: 'Notes', type: 'textarea', placeholder: 'Optional notes', order: 4 },
])
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
  return `${caseData.value.currency?.code ?? 'Currency unavailable'} ${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${fraction.padEnd(2, '0')}`
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
  if (status === 409 || status === 422) return 'The request was not accepted. Reload the current stage before retrying.'
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
async function loadPage() {
  const generation = ++readGeneration
  const key = scopeKey.value
  loadedScope.value = ''; submission.value = null; draftActions.value = {}; currentDocuments.value = []; caseData.value = {}
  isLoading.value = true; error.value = ''
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
function validDate(value) { return /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(Date.parse(value)) && new Date(value).toISOString().slice(0, 10) === value }
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
