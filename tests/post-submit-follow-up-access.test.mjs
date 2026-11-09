// Synthetic component logic only; no browser, network or database.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { component } from './support/vue-presentation-fixture.mjs'

async function detail(fetch = async () => ({ ok: true, json: async () => ({ data: {} }) })) {
  const toasts = []
  const page = await component('pages/TaxCaseDetail.vue', {
    fetch, useTaxCaseStore: () => ({}), useToast: () => ({ showSuccess: (...args) => toasts.push(args), showError: (...args) => toasts.push(args) })
  })
  page.state.caseData.value = { id: 9, can_update_follow_up: true,
    sp2_record: { id: 2, next_action: 'Existing action', next_action_due_date: '2026-12-01T00:00:00.000000Z', status_comment: 'Existing comment' } }
  return { ...page, toasts }
}

test('NA1-01/06/07/08: completed and available main stages with identity expose follow-up; locked, closed and Stage 12 do not', async () => {
  const { state } = await detail()
  for (const stage of [{ id: 2, viewable: true, accessible: false }, { id: 2, viewable: true, accessible: true }]) {
    assert.equal(state.canEditFollowUp(stage), true)
    state.openNextActionModal(stage)
    assert.equal(state.nextActionInitialData.value.next_action, 'Existing action')
    assert.equal(state.nextActionInitialData.value.next_action_due_date, '2026-12-01')
    assert.equal(state.nextActionInitialData.value.status_comment, 'Existing comment')
  }
  for (const stage of [{ id: 2, viewable: false }, { id: 3, viewable: true }, { id: 12, viewable: true }, { id: 16, viewable: true }]) assert.equal(state.canEditFollowUp(stage), false)
  state.caseData.value.can_update_follow_up = false
  assert.equal(state.canEditFollowUp({ id: 2, viewable: true }), false)
})

test('NA1-09: pending blocks duplicate save/close, success refreshes only selected row, reopening remains usable', async () => {
  let finish, calls = 0
  const { state, toasts } = await detail(async () => { calls++; return await new Promise(resolve => { finish = resolve }) })
  state.openNextActionModal({ id: 2, viewable: true })
  const payload = { next_action: 'Changed', next_action_due_date: '', status_comment: 'Comment' }
  const pending = state.saveNextAction(payload)
  assert.equal(state.isSavingNextAction.value, true)
  await state.saveNextAction(payload)
  state.closeNextActionModal()
  assert.equal(state.isNextActionModalOpen.value, true)
  assert.equal(calls, 1)
  finish({ ok: true, json: async () => ({ data: { id: 2, ...payload } }) })
  await pending
  assert.equal(state.isNextActionModalOpen.value, false)
  assert.equal(state.isSavingNextAction.value, false)
  assert.equal(state.caseData.value.sp2_record.next_action, 'Changed')
  assert.equal(toasts.length, 1)
  state.openNextActionModal({ id: 2, viewable: true })
  assert.equal(state.nextActionInitialData.value.next_action, 'Changed')
})

for (const status of [403, 409, 422]) test(`NA1-09: HTTP ${status} retains editor/input and permits deliberate retry`, async () => {
  let calls = 0
  const { state } = await detail(async () => { calls++; return { ok: false, status, json: async () => ({ message: 'Rejected', errors: { next_action: ['Check this value'] } }) } })
  state.openNextActionModal({ id: 2, viewable: true })
  await state.saveNextAction({ next_action: 'Changed' })
  assert.equal(calls, 1)
  assert.equal(state.isSavingNextAction.value, false)
  assert.equal(state.isNextActionModalOpen.value, true)
  assert.match(state.nextActionError.value, /Rejected.*Check this value/)
  assert.equal(state.caseData.value.sp2_record.next_action, 'Existing action')
})

test('NA1-05/09: modal can clear all fields; pending blocks emit; Escape closes when idle', async () => {
  const props = { isSaving: false }, events = []
  const { state } = await component('components/NextActionModal.vue', {
    defineProps: () => props, defineEmits: () => (...args) => events.push(args)
  })
  state.save()
  assert.equal(events.length, 1)
  assert.deepEqual(JSON.parse(JSON.stringify(events[0])), ['save', { next_action: '', next_action_due_date: '', status_comment: '' }])
  props.isSaving = true
  state.save(); state.cancel()
  assert.equal(events.length, 1)
  props.isSaving = false
  state.onDialogKeydown({ key: 'Escape', preventDefault() {} })
  assert.equal(events[1][0], 'close')
})

test('S12: canonical identity, explicit permission and operational key preserve legacy routing on save/reopen', async () => {
  let sent
  const { state } = await detail(async (url, options) => {
    sent = { url, payload: JSON.parse(options.body) }
    return { ok: true, json: async () => ({ data: { id: 12, next_action: 'kian', ...sent.payload } }) }
  })
  state.caseData.value.can_update_follow_up = false
  state.caseData.value.can_update_stage12_follow_up = true
  state.caseData.value.workflow_state = { closure_state: 'open' }
  state.caseData.value.supreme_court_decision = { id: 12, next_action: 'kian', follow_up_next_action: 'Call court',
    next_action_due_date: '2026-12-01', status_comment: 'Waiting' }
  const stage = { id: 12, name: 'Supreme Court Decision', viewable: true, accessible: false }
  assert.equal(state.canEditFollowUp(stage), true)
  state.openNextActionModal(stage)
  assert.equal(state.nextActionInitialData.value.next_action, 'Call court')
  await state.saveNextAction({ next_action: 'Operational text', next_action_due_date: '', status_comment: '' })
  assert.match(sent.url, /supreme-court-decisions\/12$/)
  assert.deepEqual(sent.payload, { follow_up_next_action: 'Operational text', next_action_due_date: '', status_comment: '' })
  assert.equal(state.caseData.value.supreme_court_decision.next_action, 'kian')
  state.openNextActionModal(stage)
  assert.equal(state.nextActionInitialData.value.next_action, 'Operational text')
  state.caseData.value.can_update_stage12_follow_up = false
  assert.equal(state.canEditFollowUp(stage), false)
})

test('S12: an unset operational action stays empty instead of displaying the enum; locked business stage stays unavailable', async () => {
  const { state } = await detail()
  state.caseData.value.can_update_stage12_follow_up = true
  state.caseData.value.workflow_state = { closure_state: 'open' }
  state.caseData.value.supreme_court_decision = { id: 12, next_action: 'refund' }
  state.openNextActionModal({ id: 12, viewable: true, accessible: false })
  assert.equal(state.nextActionInitialData.value.next_action, '')
  assert.equal(state.canEditFollowUp({ id: 12, viewable: false }), false)
})
