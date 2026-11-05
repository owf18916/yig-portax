// Synthetic fixtures and SSR only; no database, browser, or external API.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { component, eligibleCase } from './support/vue-presentation-fixture.mjs'

const recorder = { name: 'Sample Operator', id: 987654, email: 'synthetic-account@example.invalid', role: 'PRIVATE_ROLE', is_active: true, created_at: 'PRIVATE_ACCOUNT_TIME' }
const submitted = { id: 7, request_number: 'SYN-1', approval_status: 'SUBMITTED', submitted_by: recorder,
  submitted_at: '2026-11-04T08:30:00.000000Z', submission_date: '2026-11-04T00:00:00.000000Z',
  requested_amount: '900.12', submission_document: { id: 10, original_filename: 'submission.pdf' } }
async function fixture(record = submitted, globals = {}) {
  const page = await component('pages/PreliminaryRefundForm.vue', globals)
  page.state.busy.value = false
  page.state.caseData.value = { ...eligibleCase }
  page.state.request.value = record
  return page
}
function safe(html) {
  assert.doesNotMatch(html, /Catat Pengajuan Pengembalian Pendahuluan|Dicatat oleh pengguna/)
  assert.doesNotMatch(html, /Catat|Dicatat|pengguna|Tanggal|Jumlah|Bukti|Dokumen|Kantor|wajib|Kembali|Memuat|Alasan|Keterangan|Sumber|disetujui|maksimal|berhasil|Anda|Tidak|\[object Object\]|987654|synthetic-account|PRIVATE_|is_active|2026-11-04T/)
}

test('D03: routed page renders English creation and decision controls in both outcomes', async () => {
  const page = await fixture(null)
  let html = await page.render()
  assert.match(html, /Record Preliminary Refund Application/)
  for (const label of ['Back to case', 'Preliminary Refund', 'Submission date (required)', 'Requested amount (required)', 'Submission evidence (PDF, maximum 10 MB, required)', 'Explanation']) assert.ok(html.includes(label), label)
  safe(html)
  page.state.request.value = submitted
  for (const outcome of ['approve', 'reject']) {
    page.state.outcome.value = outcome
    html = await page.render()
    for (const label of ['Record Tax Office Decision', 'Tax Office outcome', 'Decision date (required)', 'Decision document (PDF, maximum 10 MB, required)', 'Reason']) assert.ok(html.includes(label), label)
    assert.match(html, outcome === 'approve' ? /Approved amount \(required\)/ : /required, at least 10 characters/)
    safe(html)
  }
})

test('D04: submitted and decision recorder expose only display names and formatted UTC times', async () => {
  const page = await fixture({ ...submitted, approval_status: 'APPROVED', approved_by: recorder,
    approved_at: submitted.submitted_at, decision_date: submitted.submission_date, approved_amount: '900.12',
    decision_document: { id: 11, original_filename: 'decision.pdf' } })
  const html = await page.render()
  assert.match(html, /Recorded by Sample Operator on Nov 4, 2026, 8:30 AM UTC/)
  assert.match(html, /PorTax recorder: Sample Operator on Nov 4, 2026, 8:30 AM UTC/)
  assert.match(html, /Source: external Tax Office decision/)
  safe(html)
})

test('recorder variants and invalid dates remain safe in request and decision history', async () => {
  const invalid = [undefined, null, 42, true, [], {}, { name: 42 }, { name: '   ', email: 'PRIVATE_EMAIL' }, { name: { secret: 'PRIVATE_DATA' } }, '{"name":"PRIVATE_JSON"}', '[object Object]', '987654', 'synthetic-account@example.invalid']
  for (const value of [...invalid, 'Legacy Operator', { name: 'Sample Operator' }]) {
    const page = await fixture({ ...submitted, approval_status: 'REJECTED', submitted_by: value, approved_by: value, approved_at: 'invalid', submitted_at: null, decision_document: { id: 11 } })
    const html = await page.render()
    const name = value === 'Legacy Operator' ? 'Legacy Operator' : value?.name === 'Sample Operator' ? 'Sample Operator' : 'Unknown user'
    assert.ok(html.includes(`Recorded by ${name}`))
    assert.ok(html.includes(`PorTax recorder: ${name}`))
    assert.match(html, /Date unavailable/)
    safe(html)
  }
})

test('loading, empty, gated, evidence-only, closed and historical states use English and remain read-only', async () => {
  const page = await fixture(null)
  page.state.busy.value = true
  let html = await page.render()
  assert.match(html, /Loading or saving data/)
  assert.doesNotMatch(html, /<form/)
  safe(html)
  page.state.busy.value = false
  for (const caseData of [{ ...eligibleCase, workflow_state: { completed_stages: [] } }, { ...eligibleCase, preliminary_can_decide: false }, { ...eligibleCase, status: { code: 'CLOSED' } }, { ...eligibleCase, is_completed: true }]) {
    page.state.caseData.value = caseData
    html = await page.render()
    assert.doesNotMatch(html, /<form/)
    assert.match(html, /Complete Stage 1|You can view evidence only|Closed \(CLOSED\)/)
    safe(html)
  }
  page.state.caseData.value = eligibleCase
  for (const status of ['PENDING', 'POSTPONED', 'APPROVED', 'REJECTED']) {
    page.state.request.value = { ...submitted, approval_status: status, submission_document: null }
    html = await page.render()
    assert.match(html, /Historical evidence is read-only and requires separate review for document completeness/)
    assert.doesNotMatch(html, /<form/)
    assert.ok(html.includes(`${status[0]}${status.slice(1).toLowerCase()} (${status})`))
    safe(html)
  }
  page.state.request.value = { ...submitted, deleted_at: '2026-11-04T00:00:00Z' }
  assert.doesNotMatch(await page.render(), /<form/)
})

test('all HTTP and validation failures render English without echoing backend content', async () => {
  const messages = { 403: 'You do not have permission to perform this action.', 419: 'Your session has expired. Reload the page and try again.', 422: 'The data could not be saved. Check submission date, requested amount, PDF document, decision date, approved amount, reason, explanation.' }
  for (const status of [403, 404, 409, 419, 422, 500]) {
    const page = await fixture(null, { fetch: async () => ({ ok: false, status, json: async () => ({ message: 'PRIVATE_BACKEND', errors: Object.fromEntries(['submission_date', 'requested_amount', 'document', 'decision_date', 'approved_amount', 'reason', 'notes', 'PRIVATE_UNKNOWN_FIELD'].map(key => [key, ['PRIVATE_VALIDATION']])) }) }) })
    await assert.rejects(page.state.api('/mock'), e => {
      assert.equal(e.message, messages[status] || 'The request could not be processed. Please try again.')
      page.state.error.value = e.message
      return true
    })
    const html = await page.render()
    assert.match(html, /Unable to process request/)
    assert.match(html, /role="alert"/)
    safe(html)
  }
  const empty = await fixture(null, { fetch: async url => ({ ok: !url.endsWith('/preliminary-refund-request'), status: 404, json: async () => ({ data: eligibleCase }) }) })
  await empty.state.load()
  assert.equal(empty.state.request.value, null)
  assert.match(await empty.render(), /Submission date \(required\)/)
})

test('PDF restrictions reject missing, oversize, extension and MIME errors without API calls', async () => {
  const page = await fixture(null, { fetch: async () => assert.fail('invalid upload must not send') })
  for (const file of [null, { name: 'evidence.pdf', size: 10485761 }, { name: 'evidence.txt', size: 1 }, { name: 'evidence.pdf', size: 1, type: 'text/plain' }]) {
    page.state.chooseFile({ target: { files: file ? [file] : [] } })
    await page.state.send('create')
    assert.equal(page.state.error.value, 'A PDF document no larger than 10 MB is required.')
    safe(await page.render())
  }
})

test('approve and reject preserve multipart keys, credentials and reload; success and network failures are English', async () => {
  for (const action of ['create', 'approve', 'reject']) {
    const calls = []
    const page = await fixture(submitted, { fetch: async (url, options) => {
      calls.push({ url, ...options })
      return { ok: true, json: async () => ({ data: url.endsWith('/preliminary-refund-request') ? submitted : eligibleCase }) }
    } })
    page.state.documentFile.value = new File(['synthetic PDF'], 'evidence.PDF', { type: 'application/pdf' })
    page.state.notes.value = 'Synthetic explanation'
    page.state.reason.value = 'Synthetic decision reason'
    page.state.approvedAmount.value = '900.12'
    page.state.decisionDate.value = '2026-11-04'
    await page.state.send(action)
    assert.equal(calls.length, 3)
    assert.equal(calls[0].url, `/api/tax-cases/9/preliminary-refund-request${action === 'create' ? '' : `/7/${action}`}`)
    assert.equal(calls[0].method, 'POST')
    assert.equal(calls[0].credentials, 'include')
    assert.equal(calls[0].headers.Accept, 'application/json')
    assert.deepEqual([...calls[0].body.keys()], action === 'create' ? ['document', 'submission_date', 'requested_amount', 'notes'] : action === 'approve' ? ['document', 'decision_date', 'approved_amount', 'reason'] : ['document', 'decision_date', 'reason'])
    assert.equal(page.state.documentFile.value, null)
    assert.equal(page.state.busy.value, false)
    assert.match(await page.render(), /Success.*recorded successfully/)
    safe(await page.render())
  }
  const failed = await fixture(submitted, { fetch: async () => { throw new Error('PRIVATE_NETWORK') } })
  failed.state.documentFile.value = new File(['synthetic PDF'], 'evidence.pdf', { type: 'application/pdf' })
  await failed.state.send('approve')
  assert.equal(failed.state.error.value, 'Connection error. Please try again.')
  safe(await failed.render())
})

test('private document download, historical decision details and payment evidence remain available', async () => {
  const calls = []
  const page = await fixture({ ...submitted, approval_status: 'APPROVED', approved_by: recorder, approved_at: submitted.submitted_at, approved_amount: '900.12', notes: 'Synthetic explanation', status_comment: 'Synthetic decision', decision_document: { id: 11, original_filename: 'decision.pdf' } }, { downloadPrivateDocument: async (...args) => calls.push(args) })
  page.state.caseData.value = { ...eligibleCase, refund_processes: [{ id: 12, stage_source: 'PRELIMINARY', refund_number: 'SYN-R', refund_amount: '900.12', refund_status: 'processed' }, { id: 13, stage_id: 4, refund_number: 'UNRELATED_REFUND' }] }
  const html = await page.render()
  const visibleText = html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ')
  for (const text of ['Submission evidence:', 'submission.pdf', 'Decision document:', 'decision.pdf', 'Not recorded historically', 'Approved amount: 900.12 IDR', 'Synthetic explanation', 'Synthetic decision', 'Processed (PROCESSED)', 'View payment / receipt evidence']) assert.ok(visibleText.includes(text), text)
  assert.doesNotMatch(html, /UNRELATED_REFUND|<form/)
  safe(html)
  await page.state.downloadEvidence(submitted.submission_document)
  assert.deepEqual(calls, [[10, 'submission.pdf']])
  const failed = await fixture(submitted, { downloadPrivateDocument: async () => { throw new Error('PRIVATE_DOWNLOAD') } })
  await failed.state.downloadEvidence(submitted.submission_document)
  assert.match(await failed.render(), /Evidence could not be downloaded. Check your access permissions and try again./)
  safe(await failed.render())
})

test('timestamps use explicit UTC across offsets and reject missing or ambiguous values', async () => {
  const page = await fixture()
  assert.equal(page.state.recordedDateTime('2026-11-04T15:30:00+07:00'), 'Nov 4, 2026, 8:30 AM UTC')
  for (const value of [null, undefined, 42, {}, 'invalid', '2026-11-04T08:30:00', '2026-99-99T08:30:00Z']) assert.equal(page.state.recordedDateTime(value), 'Date unavailable')
})

test('existing shared Card, Alert and Button still compile and render their styling and accessible state', async () => {
  const card = await component('components/Card.vue')
  assert.match(await card.render({ title: 'English title', subtitle: 'English help' }), /shadow-md.*English title.*English help/)
  const alert = await component('components/Alert.vue', { defineProps: () => ({ type: 'error', title: 'Error', message: 'English error' }) })
  assert.match(await alert.render(), /border-red-500.*English error/)
  const button = await component('components/Button.vue', { defineProps: () => ({ variant: 'secondary', disabled: true, block: false }) })
  assert.match(await button.render(), /bg-gray-200/)
  assert.match(await button.render(), /disabled/)
})

test('application, decision and payment summaries have distinct labelled sections and metadata rows', async () => {
  const page = await fixture({ ...submitted, approval_status: 'APPROVED', approved_by: recorder, approved_at: submitted.submitted_at, approved_amount: '900.12', decision_document: { id: 11, original_filename: 'decision.pdf' } })
  page.state.caseData.value = { ...eligibleCase, refund_processes: [{ id: 12, stage_source: 'PRELIMINARY', refund_number: 'SYN-R', refund_amount: '900.12', refund_status: 'completed' }] }
  const html = await page.render()
  const application = html.match(/<section aria-labelledby="application-heading"[\s\S]*?<\/section>/)?.[0]
  const decision = html.match(/<section aria-labelledby="decision-heading"[\s\S]*?<\/section>/)?.[0]
  const payments = html.match(/<section aria-labelledby="payments-heading"[\s\S]*?<\/section>/)?.[0]
  assert.ok(application && decision && payments)
  assert.match(application, /<h2[^>]*>Application details<\/h2>/)
  assert.match(application, /<dt[^>]*>Submission date<\/dt><dd/)
  assert.match(application, /<dt[^>]*>Requested amount<\/dt><dd/)
  assert.match(application, /Application recorder.*Recorded by Sample Operator/)
  assert.match(decision, /<h2[^>]*>Tax Office decision<\/h2>/)
  assert.match(decision, /Decision recorder.*PorTax recorder: Sample Operator/)
  assert.match(payments, /Related refunds and payments.*SYN-R.*Refund amount/)
  assert.doesNotMatch(application, /decision.pdf|Refund amount/)
  assert.doesNotMatch(decision, /submission.pdf|Refund amount/)
  assert.match(html, /grid-cols-1[^\"]*sm:grid-cols-2/)
  safe(html)
})

test('status badges retain readable status text for every lifecycle state and unknown values', async () => {
  for (const [status, color] of Object.entries({ SUBMITTED: 'blue', PENDING: 'amber', POSTPONED: 'amber', APPROVED: 'green', REJECTED: 'red' })) {
    const page = await fixture({ ...submitted, approval_status: status })
    const html = await page.render()
    assert.match(html, new RegExp(`<span class="[^\"]*rounded-full[^\"]*bg-${color}-50[^\"]*">${status[0]}${status.slice(1).toLowerCase()} \\(${status}\\)</span>`))
    if (status !== 'SUBMITTED') assert.doesNotMatch(html, /<form/)
  }
  const page = await fixture({ ...submitted, approval_status: 'UNRECOGNIZED' })
  assert.match(await page.render(), /bg-gray-100[^\"]*">Unknown status<\/span>/)
})

test('evidence rows remain independently identifiable, keyboard focusable and safe with long filenames', async () => {
  const longName = `${'synthetic-long-filename-'.repeat(12)}.pdf`
  const page = await fixture({ ...submitted, approval_status: 'REJECTED', decision_document: { id: 11, original_filename: longName } }, { fetch: async () => assert.fail('presentation must not fetch') })
  const html = await page.render()
  const links = html.match(/<a href="#"[^>]*>[\s\S]*?<\/a>/g)
  assert.equal(links.length, 2)
  assert.match(links[0], /submission.pdf/)
  assert.ok(links[1].includes(longName))
  for (const link of links) {
    assert.match(link, /focus:ring-2/)
    assert.match(link, /focus:ring-offset-2/)
    assert.match(link, /break-all/)
    assert.match(link, /Download PDF/)
  }
  safe(html)
})

test('empty and multiline explanations and remarks have readable labelled presentation', async () => {
  const page = await fixture({ ...submitted, approval_status: 'APPROVED', approved_by: recorder })
  let html = await page.render()
  assert.match(html, /Explanation.*No explanation recorded/)
  assert.match(html, /Remarks.*No remarks recorded/)
  page.state.request.value = { ...page.state.request.value, notes: 'First synthetic line\nSecond synthetic line', status_comment: 'Short synthetic remark' }
  html = await page.render()
  assert.match(html, /whitespace-pre-wrap[^>]*>First synthetic line\nSecond synthetic line/)
  assert.match(html, /Short synthetic remark/)
  assert.doesNotMatch(html, /No explanation recorded|No remarks recorded/)
  safe(html)
})
