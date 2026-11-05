// Synthetic Vue logic, compiled event bindings and SSR. No real browser, API or database.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import * as vue from 'vue'
import { component } from './support/vue-presentation-fixture.mjs'

const pdf = () => new File(['%PDF-1.7\nsynthetic evidence'], 'office-response.pdf', { type: 'application/pdf' })
const response = outcome => ({ outcome, reference: 'OFFICE-9', issued_date: '2026-11-01', received_date: '2026-11-02', approved_amount: '0.00', notes: 'Line one\nLine two', recorded_by: { id: 2, name: 'Case Recorder', email: 'secret@example.test', role: 'SECRET_ROLE' }, recorded_at: '2026-11-03T06:07:00+00:00', document: { id: 31, original_filename: 'long-'.repeat(50) + 'response.pdf', file_mime_type: 'application/pdf', download_url: '/unsafe-url-ignored', file_path: 'C:/private/secret.pdf' } })
const model = (stage = 4, status = 'submitted') => ({ id: stage * 10 + 3, stage_id: stage, tax_case_id: 9, status, kian_number: 'KIAN-TEST', submission_date: '2026-10-30', kian_amount: '999999999999999999.99', notes: 'Retained submission', available_actions: { record_response: status === 'submitted', close: status === 'responded' }, final_response: ['responded', 'closed'].includes(status) ? response('REJECTED') : null, closure: status === 'closed' ? { closed_by: { name: 'Closing Recorder' }, closed_at: '2026-11-04T08:09:00Z' } : null })
function nodes(node, predicate) {
  if (!node || typeof node !== 'object') return []
  const children = Array.isArray(node.children) ? node.children : typeof node.children?.default === 'function' ? node.children.default() : []
  return [...(predicate(node) ? [node] : []), ...children.flatMap(child => nodes(child, predicate))]
}
function textContent(node) {
  if (typeof node === 'string') return node
  const children = node?.children
  return typeof children === 'string' ? children : (Array.isArray(children) ? children : typeof children?.default === 'function' ? children.default() : []).map(textContent).join('')
}
async function fixture({ stage = 4, record = model(stage), fetcher, download } = {}) {
  const route = vue.reactive({ params: { id: '9', stageId: String(stage) } })
  const calls = []
  let current = record
  const page = await component('pages/KianForm.vue', {
    File, useRoute: () => route, watch: (source, callback, options) => vue.watch(source, callback, { ...options, immediate: false }),
    downloadPrivateDocument: download || (async () => {}),
    fetch: async (url, options = {}) => {
      calls.push({ url, ...options })
      if (fetcher) {
        const result = await fetcher(url, options)
        if (result) return result
      }
      if (options.method === 'POST') {
        current = model(Number(route.params.stageId), url.endsWith('/close') ? 'closed' : 'responded')
        return { ok: true, json: async () => ({ data: current }) }
      }
      return { ok: true, json: async () => ({ data: url.includes('/documents?') ? [] : url.includes('/kian-submissions/') ? current : { id: 9, case_number: 'CASE-9' } }) }
    },
  })
  return { ...page, calls, route, setRecord(value) { current = value }, async load() { await page.state.loadPage() } }
}
function fill(page, amount = '') {
  page.state.responseStatus.value = 'REJECTED'
  page.state.responseReference.value = 'OFFICE-9'
  page.state.issuedDate.value = '2026-11-01'
  page.state.receivedDate.value = '2026-11-02'
  page.state.approvedAmount.value = amount
  page.state.documentFile.value = pdf()
}
const writes = page => page.calls.filter(call => call.method === 'POST')

for (const stage of [4, 7, 10, 12]) {
  test(`Stage ${stage}: compiled form and confirmation target exact submission; multipart and empty close`, async () => {
    const page = await fixture({ stage })
    await page.load()
    fill(page, '0.00')
    const forms = nodes(page.client(), node => node.type === 'form')
    assert.equal(forms.length, 1)
    await forms[0].props.onSubmit({ preventDefault() {} })
    assert.equal(writes(page)[0].url, `/api/tax-cases/9/kian-submissions/${stage * 10 + 3}/record-response`)
    assert.deepEqual([...writes(page)[0].body.keys()], ['document', 'response_status', 'response_reference', 'response_issued_date', 'response_received_date', 'approved_amount'])
    assert.equal(writes(page)[0].body.get('approved_amount'), '0.00')
    assert.equal(writes(page)[0].body.get('document').type, 'application/pdf')
    assert.equal(writes(page)[0].credentials, 'include')
    assert.equal(writes(page)[0].headers, undefined, 'browser supplies multipart boundary through session transport')
    assert.equal(page.calls.at(-1).url, `/api/tax-cases/9/kian-submissions/${stage}`)
    assert.equal(page.state.submission.value.status, 'responded')
    assert.match(page.state.success.value, /confirmed/)
    assert.equal(nodes(page.client(), n => n.type === 'form').length, 0)
    const button = nodes(page.client(), n => n.type === 'Button' && !!n.props?.onClick).find(n => textContent(n) === 'Close KIAN')
    assert.ok(button)
    button.props.onClick()
    assert.match(await page.render(), /Closure is terminal/)
    const confirm = nodes(page.client(), n => n.type === 'Button' && !!n.props?.onClick).find(n => textContent(n) === 'Confirm closure')
    await confirm.props.onClick()
    const close = writes(page)[1]
    assert.equal(close.url, `/api/tax-cases/9/kian-submissions/${stage * 10 + 3}/close`)
    assert.equal(Object.hasOwn(close, 'body'), false)
    assert.equal(page.calls.at(-1).url, `/api/tax-cases/9/kian-submissions/${stage}`)
    assert.equal(page.state.submission.value.status, 'closed')
    assert.match(await page.render(), /Closing Recorder/)
    assert.equal(nodes(page.client(), n => n.type === 'form').length, 0)
  })
}

test('state and strict capability matrix renders only appropriate controls and no guessed final outcome', async () => {
  const page = await fixture()
  assert.doesNotMatch(await page.render(), /<form/)
  await page.load()
  for (const status of ['draft', 'submitted', 'responded', 'closed', 'approved', 'rejected', 'unknown', null, '__proto__']) {
    page.setRecord(model(4, status)); await page.load()
    assert.equal(nodes(page.client(), n => n.type === 'StageForm').length, status === 'draft' ? 1 : 0)
    assert.equal(nodes(page.client(), n => n.type === 'form').length, status === 'submitted' ? 1 : 0)
    assert.equal(page.state.canClose.value, status === 'responded')
    if (['approved', 'rejected'].includes(status)) assert.match(await page.render(), /No final response is available/)
  }
  page.setRecord(null); await page.load()
  assert.equal(nodes(page.client(), n => n.type === 'StageForm').length, 1)
  assert.equal(nodes(page.client(), n => n.type === 'form').length, 0)
  for (const cap of [false, undefined, null, 1, 'true']) {
    page.setRecord({ ...model(), available_actions: { record_response: cap, close: cap } }); await page.load()
    assert.equal(nodes(page.client(), n => n.type === 'form').length, 0)
    fill(page); await page.state.send('record-response')
  }
  assert.equal(writes(page).length, 0)
})

for (const outcome of ['ACCEPTED', 'PARTIALLY_ACCEPTED', 'REJECTED']) {
  test(`${outcome}: immutable readable response has safe actors, dates, zero, PDF, notes and separate lifecycle`, async () => {
    const page = await fixture({ record: { ...model(4, 'responded'), final_response: response(outcome) } })
    await page.load()
    const html = await page.render()
    assert.match(html, /Responded/)
    assert.match(html, new RegExp(`Office outcome: ${page.state.outcomeLabel(outcome)}`))
    for (const text of ['OFFICE-9', 'Nov 1, 2026', 'Nov 2, 2026', 'Case Recorder', 'UTC', '0.00', 'Line one\nLine two', 'Download PDF', 'focus:ring-blue-500', 'break-all']) assert.ok(html.includes(text), text)
    assert.doesNotMatch(html, /secret@example|SECRET_ROLE|C:\/private|2026-11-03T|unsafe-url|<form/)
    assert.equal(page.state.canClose.value, true)
    await page.state.send('close')
    assert.equal(writes(page).length, 0, 'explicit confirmation required')
    assert.equal(page.calls.length, 3, 'render and GET do not mutate or query actors/documents individually')
  })
}

test('exact decimal payload preserves omitted, zero, precision, and notes without client lifecycle fields', async () => {
  const page = await fixture(); await page.load()
  for (const amount of ['', '0', '0.00', '9007199254740993.12']) {
    fill(page, amount); page.state.responseNotes.value = 'Original note'
    const payload = await page.state.responsePayload()
    assert.equal(payload.get('approved_amount'), amount === '' ? null : amount)
    assert.equal(payload.get('response_notes'), 'Original note')
    assert.equal([...payload.keys()].some(k => /^(recorded_by|closed_by|status|stage_id|tax_case_id|document_id)$/.test(k)), false)
  }
  for (const amount of ['-1', '1.001', '1e3', ' 0', '1,000', '1234567890123456789', null]) {
    fill(page, amount)
    await assert.rejects(page.state.responsePayload(), /exact nonnegative/)
  }
})

test('required fields, strict calendar dates, extension, MIME, PDF content and 10 MiB limit prevent writes', async () => {
  const page = await fixture(); await page.load()
  fill(page); page.state.receivedDate.value = '2026-02-30'
  await page.state.send('record-response'); assert.match(page.state.error.value, /valid dates/)
  const invalid = [new File(['%PDF-'], 'office.txt', { type: 'application/pdf' }), new File(['%PDF-'], 'office.pdf', { type: 'text/plain' }), new File(['not pdf'], 'office.pdf', { type: 'application/pdf' }), new File([], 'office.pdf', { type: 'application/pdf' }), new File(['%PDF-', new Uint8Array(10 * 1024 * 1024)], 'office.pdf', { type: 'application/pdf' })]
  for (const file of invalid) { fill(page); page.state.documentFile.value = file; await page.state.send('record-response'); assert.match(page.state.error.value, /PDF/) }
  assert.equal(writes(page).length, 0)
  fill(page); page.state.documentFile.value = new File(['%PDF-', new Uint8Array(10 * 1024 * 1024 - 5)], 'limit.pdf', { type: 'application/pdf' })
  assert.ok(await page.state.responsePayload())
  const html = await page.render()
  assert.match(html, /accept=".pdf,application\/pdf"/)
  assert.equal((html.match(/<label /g) || []).length, 7)
  assert.match(html, /type="date" required/)
  assert.match(html, /inputmode="decimal"/)
})

for (const status of [403, 419, 409, 422, 500, 'network']) {
  test(`${status}: unconfirmed write fails closed, English feedback, no optimistic record`, async () => {
    const page = await fixture({ fetcher: async (url, options) => {
      if (options.method !== 'POST') return
      if (status === 'network') throw new Error('PRIVATE_NETWORK_DETAILS')
      return { ok: false, status, json: async () => ({ message: 'PRIVATE_BACKEND_DETAILS' }) }
    } })
    await page.load(); fill(page)
    await page.state.send('record-response')
    assert.equal(writes(page).length, 1)
    assert.equal(page.state.submission.value.status, 'submitted')
    assert.equal(page.state.success.value, '')
    assert.equal(page.state.resolved.value, false)
    assert.doesNotMatch(await page.render(), /<form|PRIVATE_/)
    assert.match(page.state.error.value, /session|accepted|confirmed/)
  })
}

test('double click during async PDF preparation and command issues only one write', async () => {
  let release
  const pending = new Promise(resolve => { release = resolve })
  const page = await fixture({ fetcher: async (url, options) => { if (options.method === 'POST') await pending } })
  await page.load(); fill(page)
  const first = page.state.send('record-response')
  await page.state.send('record-response')
  assert.equal(page.state.mutationBusy.value, true)
  assert.match(await page.render(), /<fieldset disabled/)
  release(); await first
  assert.equal(writes(page).length, 1)
})

test('failed GET, wrong stage/case/ID, missing wrapper and stale route never render editable fallback', async () => {
  for (const record of [{ ...model(), stage_id: 7 }, { ...model(), tax_case_id: 8 }, { ...model(), id: null }]) {
    const page = await fixture({ record }); await page.load()
    assert.equal(page.state.resolved.value, false)
    assert.doesNotMatch(await page.render(), /<form/)
    assert.equal(nodes(page.client(), n => n.type === 'StageForm').length, 0)
  }
  for (const status of [403, 419, 500]) {
    const page = await fixture({ fetcher: async () => ({ ok: false, status }) }); await page.load()
    assert.equal(page.state.resolved.value, false)
    assert.equal(nodes(page.client(), n => n.type === 'form' || n.type === 'StageForm').length, 0)
    assert.equal(writes(page).length, 0)
  }
  const page = await fixture(); await page.load(); fill(page)
  page.route.params.stageId = '7'
  await page.state.send('record-response')
  assert.equal(writes(page).length, 0)
  assert.equal(page.state.resolved.value, false)
  await vue.nextTick()
  page.route.params.stageId = 'invalid'
  await page.state.loadPage()
  assert.equal(page.state.resolved.value, false)
  await assert.rejects(async () => page.state.scopedRecord({ id: 123 }), /projection/)
})

test('private evidence uses authenticated helper by ID, safe fallback actors and missing evidence', async () => {
  const downloads = []
  const page = await fixture({ record: model(4, 'responded'), download: async (...args) => { downloads.push(args); throw new Error('PRIVATE_DOWNLOAD') } })
  await page.load()
  const doc = page.state.finalResponse.value.document
  await page.state.downloadEvidence(doc)
  assert.equal(downloads[0][0], 31)
  assert.match(page.state.error.value, /private document could not be downloaded/)
  assert.doesNotMatch(page.state.error.value, /PRIVATE_DOWNLOAD/)
  assert.equal(writes(page).length, 0)
  for (const actor of [null, 9, { id: 1, email: 'secret@example.test' }, { name: { nested: 'private' } }, { name: 'secret@example.test' }]) assert.equal(page.state.recorderName(actor), 'Unknown user')
  assert.equal(page.state.filename({ original_filename: 'C:/private/final.pdf' }), 'final.pdf')
  page.state.submission.value.final_response.document = null
  page.state.submission.value.final_response.recorded_by = null
  page.state.submission.value.final_response.recorded_at = 'bad timestamp'
  page.state.submission.value.final_response.approved_amount = null
  const html = await page.render()
  assert.match(html, /Unknown user|Date unavailable/)
  assert.match(html, /Recognized amount<\/dt><dd>Not recorded/)
  assert.match(html, /document is unavailable/)
  assert.doesNotMatch(html, /Download PDF/)
})

test('generic StageForm protects modern/legacy/unknown KIAN lifecycle while preserving ordinary workflow gating', async () => {
  const props = vue.reactive({ stageName: 'KIAN', stageDescription: 'KIAN', exposure: null, caseNumber: 'CASE-9', stageId: 7, caseId: 9, fields: [], prefillData: { kianSubmissionStatus: 'draft' }, isLoading: false })
  const calls = []
  const page = await component('components/StageForm.vue', { defineProps: () => props, fetch: async (...args) => { calls.push(args); return { ok: true, json: async () => ({ data: {} }) } } })
  for (const status of ['submitted', 'responded', 'closed', 'approved', 'rejected', 'unknown', null]) {
    props.prefillData = { kianSubmissionStatus: status }
    assert.equal(page.state.fieldsDisabled.value, true)
    assert.equal(page.state.computedNextStageId.value, null)
    assert.doesNotMatch(await page.render(), /type="submit"/)
    await page.state.executeSubmitForm(); await page.state.executeSaveDraft()
  }
  assert.equal(calls.length, 0)
  props.prefillData = { kianSubmissionStatus: 'draft' }
  assert.equal(page.state.fieldsDisabled.value, false)
  props.prefillData = { workflowHistories: [{ stage_id: 7, status: 'submitted' }] }
  assert.equal(page.state.fieldsDisabled.value, true)
  assert.equal(page.state.computedNextStageId.value, 8)
  props.prefillData = {}
  assert.equal(page.state.fieldsDisabled.value, false)
})

test('accepted command with failed or stale confirmation read reports no success and no fabricated state', async () => {
  for (const confirmation of ['failed', 'unchanged', 'wrong-stage', 'missing-wrapper']) {
    let posted = false
    const page = await fixture({ fetcher: async (url, options) => {
      if (options.method === 'POST') { posted = true; return { ok: true, json: async () => ({ data: model(4, 'responded') }) } }
      if (posted && url.includes('/kian-submissions/')) {
        if (confirmation === 'failed') throw new Error('PRIVATE_REFRESH_FAILURE')
        return { ok: true, json: async () => confirmation === 'missing-wrapper' ? model(4, 'responded') : ({ data: confirmation === 'wrong-stage' ? model(7, 'responded') : model() }) }
      }
    } })
    await page.load(); fill(page); await page.state.send('record-response')
    assert.equal(writes(page).length, 1)
    assert.equal(page.state.success.value, '')
    assert.equal(page.state.resolved.value, false)
    assert.doesNotMatch(await page.render(), /<form|PRIVATE_/)
  }
})

test('route change during PDF preparation cancels before POST; delayed sibling read cannot replace current record', async () => {
  const page = await fixture(); await page.load(); fill(page)
  let releaseFile
  page.state.documentFile.value = { name: 'office.pdf', type: 'application/pdf', size: 10, slice: () => ({ text: () => new Promise(resolve => { releaseFile = resolve }) }) }
  const action = page.state.send('record-response')
  page.route.params.stageId = '7'
  releaseFile('%PDF-'); await action
  assert.equal(writes(page).length, 0)
  let releaseRead
  const delayed = await fixture({ fetcher: async url => {
    if (url === '/api/tax-cases/9/kian-submissions/4') return new Promise(resolve => { releaseRead = resolve })
    if (url === '/api/tax-cases/9/kian-submissions/7') return { ok: true, json: async () => ({ data: model(7) }) }
  } })
  const firstRead = delayed.load()
  delayed.route.params.stageId = '7'
  await delayed.load()
  releaseRead({ ok: true, json: async () => ({ data: model(4) }) })
  await firstRead
  assert.equal(delayed.state.submission.value.stage_id, 7)
  assert.equal(delayed.state.submission.value.id, 73)
  assert.equal(delayed.state.resolved.value, true)
  fill(delayed); await delayed.state.send('record-response')
  assert.equal(writes(delayed)[0].url, '/api/tax-cases/9/kian-submissions/73/record-response')
})

test('draft save and submit retain stage-scoped store payload, English confirmation and no workflow continuation', async () => {
  const props = vue.reactive({ stageName: 'KIAN', stageDescription: 'KIAN', exposure: null, caseNumber: 'CASE-9', stageId: 4, caseId: 9, fields: [], apiEndpoint: '/api/tax-cases/9/kian-submissions/4', prefillData: { kianSubmissionStatus: 'draft' }, isLoading: false })
  const calls = []
  const page = await component('components/StageForm.vue', { defineProps: () => props, fetch: async (url, options = {}) => { calls.push({ url, ...options }); return { ok: true, json: async () => ({ data: {} }) } } })
  page.state.formData.kian_number = 'DRAFT-4'
  page.state.formData.submission_date = '2026-11-01'
  page.state.formData.loss_amount = '9007199254740993.12'
  await page.state.executeSaveDraft()
  assert.equal(calls[0].url, props.apiEndpoint)
  assert.equal(JSON.parse(calls[0].body).action, 'draft')
  page.state.uploadedFiles.value = [{ id: 1, name: 'proof.pdf', documentType: 'supporting_document' }]
  await page.state.submitForm()
  assert.equal(page.state.confirmDialogTitle.value, 'Submit KIAN')
  assert.match(page.state.confirmDialogMessage.value, /does not advance/)
  await page.state.executeSubmitForm()
  assert.equal(calls[1].url, props.apiEndpoint)
  assert.equal(JSON.parse(calls[1].body).action, 'submit')
  assert.equal(JSON.parse(calls[1].body).stage_id, 4)
  assert.equal(page.state.computedNextStageId.value, null)
})

test('session transport exceptions retain safe 401/419 guidance before an HTTP response reaches the page', async () => {
  for (const [message, status] of [['Session/token error (419). Reload or sign in again. Your write was not retried.', 419], ['Session expired. Sign in again; no write was retried.', 401]]) {
    const page = await fixture({ fetcher: async () => { throw new Error(message) } })
    await assert.rejects(page.state.api('/synthetic'), failure => failure.status === status && /session/.test(failure.message))
    await page.load()
    assert.equal(page.state.resolved.value, false)
    assert.equal(nodes(page.client(), n => n.type === 'form' || n.type === 'StageForm').length, 0)
  }
})
