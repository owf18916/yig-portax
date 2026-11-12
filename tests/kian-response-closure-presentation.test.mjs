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
async function fixture({ stage = 4, record = model(stage), fetcher, download, draftActions } = {}) {
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
      return { ok: true, json: async () => ({ draft_actions: draftActions ?? { create: current === null, save_draft: !current || current.status === 'draft', submit: !current || current.status === 'draft' }, data: url.includes('/documents?') ? [] : url.includes('/kian-submissions/') ? current : { id: 9, case_number: 'CASE-9' } }) }
    },
  })
  return { ...page, calls, route, setRecord(value) { current = value }, async load() { await page.state.loadPage() } }
}
const writes = page => page.calls.filter(call => call.method === 'POST')

test('legacy KIAN selection requires a current scoped capability and direct save fails closed', async () => {
  const route = vue.reactive({ params: { id: '9' } })
  let grant = false
  const calls = []
  const page = await component('pages/KianSubmissionForm.vue', { useRoute: () => route, fetch: async (url, options = {}) => {
    calls.push({ url, ...options })
    return { ok: true, json: async () => ({ data: model(4, 'draft'), ...(grant ? { draft_actions: { save_draft: true, submit: true } } : {}) }) }
  } })
  page.state.isLoading.value = false
  await page.state.selectStageForKian(4)
  assert.equal(page.state.selectedDraftAllowed.value, false)
  assert.doesNotMatch(await page.render(), /<form/)
  await page.state.submitKianForStage()
  assert.equal(calls.some(c => c.method === 'POST'), false)
  grant = true
  await page.state.selectStageForKian(4)
  assert.equal(page.state.selectedDraftAllowed.value, true)
  route.params.id = '10'
  assert.equal(page.state.selectedDraftAllowed.value, false)
  await page.state.submitKianForStage()
  assert.equal(calls.some(c => c.method === 'POST'), false)
})

test('delayed granted draft read cannot enable a different KIAN stage', async () => {
  let release
  const page = await fixture({ fetcher: async url => {
    if (url.endsWith('/kian-submissions/4')) return new Promise(resolve => { release = resolve })
    if (url.endsWith('/kian-submissions/7')) return { ok: true, json: async () => ({ data: model(7, 'draft') }) }
  } })
  const pending = page.load()
  page.route.params.stageId = '7'
  await page.load()
  release({ ok: true, json: async () => ({ data: model(4, 'draft'), draft_actions: { create: true, save_draft: true, submit: true } }) })
  await pending
  assert.equal(page.state.submission.value.stage_id, 7)
  assert.equal(page.state.draftEditable.value, false)
  assert.equal(nodes(page.client(), n => n.type === 'StageForm').length, 0)
})

for (const stage of [4, 7, 10, 12]) {
  test(`Stage ${stage}: draft and absent capability fail closed, including refresh and route change`, async () => {
    for (const record of [null, model(stage, 'draft')]) {
      for (const cap of [false, undefined, null, 1, 'true', true]) {
        const page = await fixture({ stage, record, draftActions: { create: cap, save_draft: cap, submit: cap } })
        assert.equal(page.state.draftEditable.value, false)
        await page.load()
        assert.equal(page.state.draftEditable.value, cap === true)
        assert.equal(nodes(page.client(), n => n.type === 'StageForm').length, cap === true ? 1 : 0)
        if (cap !== true) assert.match(await page.render(), /Submission details are read-only/)
        page.route.params.stageId = String(stage === 12 ? 4 : 12)
        assert.equal(page.state.draftEditable.value, false)
      }
    }
    let grant = true
    const page = await fixture({ stage, record: model(stage, 'draft'), fetcher: async url => {
      if (url.includes('/kian-submissions/')) return { ok: true, json: async () => ({ data: model(stage, 'draft'), ...(grant ? { draft_actions: { save_draft: true, submit: true } } : {}) }) }
    } })
    await page.load(); assert.equal(page.state.draftEditable.value, true)
    grant = false
    await page.load(); assert.equal(page.state.draftEditable.value, false)
    for (const status of ['submitted', 'responded', 'closed', 'approved', 'rejected', 'unknown']) {
      const final = await fixture({ stage, record: model(stage, status), draftActions: { create: true, save_draft: true, submit: true } })
      await final.load(); assert.equal(final.state.draftEditable.value, false)
    }
  })

  test(`Stage ${stage}: shared form denies missing draft capability and direct handlers`, async () => {
    const props = vue.reactive({ stageName: 'KIAN', stageDescription: 'KIAN', exposure: null, caseNumber: 'CASE-9', stageId: stage, caseId: 9, fields: [], prefillData: { kianSubmissionStatus: 'draft' }, isLoading: false })
    const calls = []
    const page = await component('components/StageForm.vue', { defineProps: () => props, fetch: async (...args) => { calls.push(args) } })
    for (const cap of [undefined, false, null, 1, 'true']) {
      props.prefillData.kianDraftActions = { save_draft: cap, submit: cap }
      assert.equal(page.state.fieldsDisabled.value, true)
      assert.doesNotMatch(await page.render(), /Save as Draft|Submit &amp; Continue/)
      await page.state.executeSubmitForm(); await page.state.executeSaveDraft()
    }
    assert.equal(calls.length, 0)
    props.prefillData.kianDraftActions = { save_draft: true, submit: true }
    assert.equal(page.state.fieldsDisabled.value, false)
    props.isLoading = true
    assert.equal(page.state.fieldsDisabled.value, true)
  })
}

for (const stage of [4, 7, 10, 12]) {
  test(`Stage ${stage}: submission and historical views expose no response or closure command even with stale grants`, async () => {
    for (const status of ['submitted', 'responded', 'closed', 'approved', 'rejected', 'unknown']) {
      const page = await fixture({ stage, record: model(stage, status) })
      await page.load(); await page.load()
      const html = await page.render()
      assert.equal(nodes(page.client(), n => n.type === 'form' || n.type === 'StageForm').length, 0)
      assert.doesNotMatch(html, /Record Tax Office Response|Close KIAN|Confirm closure|<select|<input/)
      assert.match(html, /Submission completes the internal KIAN process/)
      if (status === 'submitted') assert.doesNotMatch(html, /Tax Office|Recognized amount|Historical response evidence|Historical KIAN closure/)
      if (status === 'responded' || status === 'closed') assert.match(html, /Historical response evidence|OFFICE-9/)
      if (status === 'closed') assert.match(html, /Closing Recorder/)
      assert.equal(page.state.send, undefined)
      assert.equal(writes(page).length, 0)
    }
  })
}

for (const outcome of ['ACCEPTED', 'PARTIALLY_ACCEPTED', 'REJECTED']) {
  test(`${outcome}: immutable readable response has safe actors, dates, zero, PDF, notes and separate lifecycle`, async () => {
    const page = await fixture({ record: { ...model(4, 'responded'), final_response: response(outcome) } })
    await page.load()
    const html = await page.render()
    assert.match(html, /Responded/)
    assert.match(html, new RegExp(`Office outcome: ${page.state.outcomeLabel(outcome)}`))
    for (const text of ['OFFICE-9', 'Nov 1, 2026', 'Nov 2, 2026', 'Case Recorder', 'UTC', '0.00', 'Line one\nLine two', 'Download PDF', 'focus:ring-blue-500', 'break-all']) assert.ok(html.includes(text), text)
    assert.doesNotMatch(html, /secret@example|SECRET_ROLE|C:\/private|2026-11-03T|unsafe-url|<form/)
    assert.equal(writes(page).length, 0, 'explicit confirmation required')
    assert.equal(page.calls.length, 3, 'render and GET do not mutate or query actors/documents individually')
  })
}

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
  props.prefillData = { kianSubmissionStatus: 'draft', kianDraftActions: { save_draft: true, submit: true } }
  assert.equal(page.state.fieldsDisabled.value, false)
  props.prefillData = { workflowHistories: [{ stage_id: 7, status: 'submitted' }] }
  assert.equal(page.state.fieldsDisabled.value, true)
  assert.equal(page.state.computedNextStageId.value, 8)
  props.prefillData = {}
  assert.equal(page.state.fieldsDisabled.value, false)
})

test('draft save and submit retain stage-scoped store payload, English confirmation and no workflow continuation', async () => {
  const props = vue.reactive({ stageName: 'KIAN', stageDescription: 'KIAN', exposure: null, caseNumber: 'CASE-9', stageId: 4, caseId: 9, fields: [], apiEndpoint: '/api/tax-cases/9/kian-submissions/4', prefillData: { kianSubmissionStatus: 'draft', kianDraftActions: { save_draft: true, submit: true } }, isLoading: false })
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
  const page = await fixture(); await page.load()
  page.route.params.stageId = 'invalid'
  await page.state.loadPage()
  assert.equal(page.state.resolved.value, false)
  await assert.rejects(async () => page.state.scopedRecord({ id: 123 }), /projection/)
})
