// Synthetic Vue logic/rendering only; no browser, network, or database.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { reactive } from 'vue'
import { component } from './support/vue-presentation-fixture.mjs'
import { nextActionPolicy, initializeNextAction } from '../resources/js/utils/nextActionCatalog.mjs'

const workflow = () => ({ stages: [{ stage: 10, status: 'completed', draft: false }], diagnostics: [] })
async function editor(stage, saved = '', extra = {}) {
  const events = [], focus = []
  const document = { activeElement: { focus: () => focus.push('restored') } }
  let opened
  const props = reactive({ stage: { id: stage }, isOpen: true, isSaving: false, errorMessage: '',
    initialData: { next_action: saved, next_action_due_date: '2026-12-01T00:00:00Z', status_comment: 'Keep comment' }, ...extra })
  const modal = await component('components/NextActionModal.vue', {
    nextActionPolicy, initializeNextAction, defineProps: () => props,
    defineEmits: () => (...args) => events.push(args), watch: (source, callback) => { opened = callback },
    document, nextTick: callback => callback()
  })
  modal.state.dialog.value = { querySelector: selector => ({ focus: () => focus.push(selector) }) }
  opened(true)
  return { ...modal, props, events, focus, document, reopen: () => opened(true), close: () => opened(false) }
}
function nodes(vnode) {
  if (!vnode || typeof vnode !== 'object') return []
  const children = typeof vnode.children?.default === 'function' ? vnode.children.default() : vnode.children
  return [vnode, ...(Array.isArray(children) ? children.flatMap(nodes) : [])]
}

const expectedDefaults = {
  2: 'Preparing data and documents for submission to the tax auditor',
  3: 'Drafting a response to the SPHP', 5: 'Awaiting SPUH',
  6: 'Drafting a Response to the SPUH', 8: 'Awaiting SUB / Appeal Description Letter',
  11: 'Awaiting Judicial Review Decision'
}
for (const [stage, expected] of Object.entries(expectedDefaults)) {
  test(`Stage ${stage}: unsaved editable default, custom reopening, explicit clear and date/comment preservation`, async () => {
    const modal = await editor(Number(stage))
    assert.equal(modal.state.formData.value.next_action, expected)
    assert.equal(modal.events.length, 0)
    modal.state.formData.value.next_action = 'Custom action'
    modal.state.save()
    assert.deepEqual(JSON.parse(JSON.stringify(modal.events[0][1])), {
      next_action: 'Custom action', next_action_due_date: '2026-12-01', status_comment: 'Keep comment'
    })
    modal.props.initialData.next_action = 'Saved custom action'
    modal.reopen()
    assert.equal(modal.state.formData.value.next_action, 'Saved custom action')
    modal.state.formData.value.next_action = ''
    modal.state.save()
    assert.equal(modal.events.at(-1)[1].next_action, '')
    assert.equal(modal.state.formData.value.next_action, '')
    assert.deepEqual(modal.focus, ['#follow-up-next_action', '#follow-up-next_action'])
    modal.close()
    assert.equal(modal.focus.at(-1), 'restored')
  })
}
const expectedOptions = {
  4: ['Awaiting Tax Refund', 'Awaiting Tax Refund; Processing KIAN and Preparing an Objection Submission',
    'Awaiting Tax Refund; Processing KIAN and Recording Tax Expense',
    'Processing KIAN; Paying the Underpaid Tax Assessment (SKP KB) and Preparing an Objection Submission',
    'Processing KIAN; Paying the Underpaid Tax Assessment (SKP KB) and Recording Tax Expense'],
  7: ['Awaiting Tax Refund', 'Awaiting Tax Refund and Preparing an Appeal Submission',
    'Awaiting Tax Refund and Recording Tax Adjustment Expense', 'Preparing an Appeal Submission', 'Recording Tax Adjustment Expense'],
  9: ['Drafting a Rebuttal Letter', 'Awaiting a Court Summons'],
  12: ['Recording Tax Adjustment Expense', 'Awaiting Tax Refund']
}
for (const [stage, expected] of Object.entries(expectedOptions)) {
  test(`Stage ${stage}: exact ordered dropdown, no auto-selection, historical preservation/replacement/clear`, async () => {
    const modal = await editor(Number(stage))
    const controls = nodes(modal.client())
    const select = controls.find(node => node.type === 'select')
    assert.ok(select)
    assert.equal(select.props.required, undefined)
    assert.deepEqual(nodes(select).filter(node => node.type === 'option').map(node => node.children), ['Select an action', ...expected])
    assert.equal(modal.state.formData.value.next_action, '')
    assert.equal(modal.events.length, 0)
    modal.props.initialData.next_action = 'Historical custom value'
    modal.reopen()
    assert.equal(modal.state.historicalAction.value, true)
    const legacyNodes = nodes(modal.client())
    assert.match(legacyNodes.find(node => node.props?.id === 'follow-up-action-current').children, /Current value: Historical custom value/)
    modal.state.formData.value.status_comment = 'Changed comment'
    modal.state.save()
    assert.equal(modal.events.at(-1)[1].next_action, 'Historical custom value')
    select.props.onChange({ target: { value: expected[0] } })
    assert.equal(modal.state.historicalAction.value, false)
    modal.state.save()
    assert.equal(modal.events.at(-1)[1].next_action, expected[0])
    assert.equal(modal.events.at(-1)[1].next_action_due_date, '2026-12-01')
    nodes(modal.client()).find(node => node.type === 'button' && node.children === 'Clear action').props.onClick()
    modal.state.save()
    assert.equal(modal.events.at(-1)[1].next_action, '')
    assert.ok(controls.some(node => node.type === 'ol' && node.props.class.includes('break-words')))
  })
}
test('Stage 1 retains empty free text and custom values', async () => {
  const modal = await editor(1)
  assert.equal(modal.state.actionPolicy.value.mode, 'text')
  assert.equal(modal.state.formData.value.next_action, '')
  modal.props.initialData.next_action = 'Original'
  modal.reopen()
  assert.equal(modal.state.formData.value.next_action, 'Original')
})
test('Stage 10 maps canonical final decisions and responds to authoritative refresh without replacing saved text', async () => {
  const modal = await editor(10, '', { decision: { id: 10, status: 'submitted', decision_type: 'granted' }, workflowState: workflow() })
  assert.equal(modal.state.actionPolicy.value.mode, 'text')
  assert.equal(modal.state.formData.value.next_action, 'Awaiting Tax Refund')
  modal.props.initialData.next_action = 'Saved before revision'
  modal.reopen()
  for (const [decision, expected] of [
    ['partially_granted', ['Awaiting Tax Refund and Preparing a Judicial Review Petition', 'Awaiting Tax Refund and Recording Tax Adjustment Expense']],
    ['rejected', ['Preparing a Judicial Review Petition', 'Recording Tax Adjustment Expense']]
  ]) {
    modal.props.decision = { id: 10, status: 'approved', decision_type: decision }
    modal.props.workflowState = workflow()
    assert.deepEqual([...modal.state.actionPolicy.value.options], expected)
    assert.equal(modal.state.formData.value.next_action, 'Saved before revision')
    assert.equal(modal.state.historicalAction.value, true)
    modal.reopen()
    assert.equal(modal.state.formData.value.next_action, 'Saved before revision')
  }
  assert.equal(modal.events.length, 0)
})
test('Stage 10 draft, missing, unknown, skp_kb and inconsistent evidence safely retain action and allow date/comment save', async () => {
  const goodDecision = { id: 10, status: 'submitted', decision_type: 'rejected' }
  for (const extra of [
    {}, { decision: goodDecision }, { decision: null, workflowState: workflow() },
    { decision: { ...goodDecision, status: 'draft' }, workflowState: workflow() },
    { decision: { ...goodDecision, decision_type: 'Not Granted' }, workflowState: workflow() },
    { decision: { ...goodDecision, decision_type: 'skp_kb' }, workflowState: workflow() },
    { decision: goodDecision, workflowState: { stages: [{ stage: 10, status: 'locked', draft: true }] } },
    { decision: goodDecision, workflowState: { ...workflow(), diagnostics: [{ stage: 10, code: 'final_record_conflicts_with_draft_history' }] } }
  ]) {
    const modal = await editor(10, 'Retain me', extra)
    assert.equal(modal.state.actionPolicy.value.mode, 'unresolved')
    assert.equal(modal.state.formData.value.next_action, 'Retain me')
    assert.equal(nodes(modal.client()).find(node => node.props?.id === 'follow-up-next_action').props.readonly, true)
    modal.state.formData.value.status_comment = 'Only comment edited'
    modal.state.save()
    assert.equal(modal.events[0][1].next_action, 'Retain me')
  }
})
test('All catalog strings fit the existing nullable text max:1000 contract', () => {
  const policies = [1, 2, 3, 4, 5, 6, 7, 8, 9, 11, 12].map(stage => nextActionPolicy(stage))
  for (const type of ['granted', 'partially_granted', 'rejected']) policies.push(nextActionPolicy(10, { id: 10, status: 'submitted', decision_type: type }, workflow()))
  const strings = policies.flatMap(policy => policy.options || [policy.defaultValue])
  assert.equal(Math.max(...strings.map(value => value.length)), 99)
})

test('Dropdown controls preserve pending protection and keyboard focus wrapping', async () => {
  const modal = await editor(4, 'Historical value')
  modal.props.isSaving = true
  const controls = nodes(modal.client())
  for (const node of controls.filter(node => ['select', 'input', 'textarea', 'button'].includes(node.type))) assert.equal(node.props.disabled, true)
  modal.state.save(); modal.state.cancel()
  assert.equal(modal.events.length, 0)
  modal.props.isSaving = false
  const focus = []
  const first = { focus: () => focus.push('first') }, last = { focus: () => focus.push('last') }
  modal.state.dialog.value = { querySelectorAll: selector => {
    assert.match(selector, /select:not\(:disabled\)/)
    assert.match(selector, /summary/)
    return [first, last]
  } }
  modal.document.activeElement = first
  modal.state.onDialogKeydown({ key: 'Tab', shiftKey: true, preventDefault() {} })
  modal.document.activeElement = last
  modal.state.onDialogKeydown({ key: 'Tab', shiftKey: false, preventDefault() {} })
  assert.deepEqual(focus, ['last', 'first'])
  modal.state.onDialogKeydown({ key: 'Escape', preventDefault() {} })
  assert.equal(modal.events.at(-1)[0], 'close')
})

test('Catalog selection uses the existing single PUT and operational keys for each dropdown stage', async () => {
  const properties = { 4: 'skp_record', 7: 'objection_decision', 9: 'appeal_explanation_request', 10: 'appeal_decision', 12: 'supreme_court_decision' }
  for (const [id, property] of Object.entries(properties)) {
    const stage = Number(id), calls = []
    const page = await component('pages/TaxCaseDetail.vue', {
      useTaxCaseStore: () => ({}), useToast: () => ({ showSuccess() {}, showError() {} }),
      fetch: async (url, request) => {
        calls.push({ url, request, body: JSON.parse(request.body) })
        return { ok: true, json: async () => ({ data: { id: stage, ...JSON.parse(request.body) } }) }
      }
    })
    const record = { id: stage, next_action: stage === 12 ? 'kian' : 'Old text', follow_up_next_action: 'Old operational text', next_action_due_date: '2026-12-01', status_comment: 'Keep comment', status: 'submitted', decision_type: 'rejected' }
    page.state.caseData.value = { id: 9, can_update_follow_up: true, can_update_stage12_follow_up: true,
      workflow_state: { ...workflow(), closure_state: 'open' }, [property]: record }
    page.state.openNextActionModal({ id: stage, viewable: true, accessible: false })
    const modal = await editor(stage, '', { initialData: page.state.nextActionInitialData.value,
      decision: page.state.caseData.value.appeal_decision, workflowState: page.state.caseData.value.workflow_state })
    assert.equal(calls.length, 0)
    const selection = modal.state.actionPolicy.value.options[0]
    nodes(modal.client()).find(node => node.type === 'select').props.onChange({ target: { value: selection } })
    modal.state.save()
    await page.state.saveNextAction(modal.events[0][1])
    assert.equal(calls.length, 1)
    assert.equal(calls[0].request.method, 'PUT')
    assert.deepEqual(calls[0].body, { [stage === 12 ? 'follow_up_next_action' : 'next_action']: selection,
      next_action_due_date: '2026-12-01', status_comment: 'Keep comment' })
    assert.equal(record.next_action, stage === 12 ? 'kian' : selection)
    assert.equal(record.decision_type, 'rejected')
  }
})
