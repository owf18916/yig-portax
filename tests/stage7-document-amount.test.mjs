// Actual Vue logic and compiled controls; mocked transport, no browser or DB.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import * as vue from 'vue'
import { component } from './support/vue-presentation-fixture.mjs'

function findField(node) {
  if (node?.type === 'FormField' && node.props?.label === 'Decision Amount') return node
  return Array.isArray(node?.children) ? node.children.map(findField).find(Boolean) : undefined
}

async function fixture(stageId = 7) {
  const props = vue.reactive({ stageId, caseId: 9, stageName: 'Decision', caseNumber: 'TEST', stageDescription: 'Document', isLoading: false,
    fields: [{ key: 'decision_type', type: 'text' }, { key: 'decision_amount', label: 'Decision Amount', type: 'number', required: true }],
    prefillData: { decision_type: 'granted', decision_amount: '90000.12' }, exposure: { previousExposure: '30000.00' } })
  const calls = []
  const watchers = []
  const page = await component('components/StageForm.vue', {
    defineProps: () => props,
    watch: (source, callback, options) => { watchers.push({ source, callback }); if (options?.immediate) callback(source()) },
    fetch: async (url, options) => { calls.push({ url, payload: JSON.parse(options.body) }); return { ok: true, json: async () => ({}) } },
    setTimeout() {},
  })
  // Exercise actual prefill watcher, including subsequent reloads.
  const reload = () => watchers.find(w => w.source()[0] === props.fields).callback([props.fields, props.prefillData])
  reload()
  return { ...page, props, calls, watchers, reload }
}

test('Stage 7 load, outcome edits, draft/submit payloads and reload preserve exact document amount', async () => {
  const page = await fixture()
  const state = page.state
  assert.equal(state.formData.decision_amount, '90000.12')
  for (const outcome of ['granted', 'rejected', 'partially_granted']) {
    state.formData.decision_type = outcome
    page.watchers[0].callback([outcome, '30000.00'])
    assert.equal(state.fixedRecovery.value, false)
    assert.equal(state.formData.decision_amount, '90000.12')
    const field = findField(page.client())
    assert.equal(field.props.type, 'text')
    assert.equal(field.props.max, undefined)
    assert.equal(field.props.disabled, false)
    assert.equal(state.validateForm(), true)
  }
  await state.executeSaveDraft()
  assert.equal(page.calls[0].payload.decision_amount, '90000.12')
  state.uploadedFiles.value = [{ id: 1 }]
  await state.executeSubmitForm()
  assert.equal(page.calls[1].payload.decision_amount, '90000.12')
  page.props.prefillData = { decision_type: 'rejected', decision_amount: '90000.12' }
  page.reload()
  assert.equal(state.formData.decision_amount, '90000.12')
  state.submissionComplete.value = true
  assert.equal(findField(page.client()).props.disabled, true)
  const html = await page.render()
  assert.match(html, /value stated in the decision document/)
  assert.doesNotMatch(html, /Recovery must be greater/)
})

test('Stage 7 rejects invalid exact-money text without changing it', async () => {
  const { state } = await fixture()
  for (const amount of ['-1', 'NaN', 'Infinity', 'abc', '1e5', '0.001', '1.000', '']) {
    state.formData.decision_amount = amount
    assert.equal(state.validateForm(), false, amount)
    assert.equal(state.formData.decision_amount, amount)
  }
  for (const amount of ['0', '0.10', '90000.12']) {
    state.formData.decision_amount = amount
    assert.equal(state.validateForm(), true, amount)
  }
})

test('Stage 10/12 keep fixed outcome input and prior-exposure cap', async () => {
  for (const stage of [10, 12]) {
    const page = await fixture(stage)
    assert.equal(page.state.fixedRecovery.value, true)
    page.watchers[0].callback(['granted', '30000.00'])
    assert.equal(page.state.formData.decision_amount, '30000.00')
    const field = findField(page.client())
    assert.equal(field.props.type, 'number')
    assert.equal(field.props.max, '30000.00')
    assert.equal(field.props.disabled, true)
  }
})
