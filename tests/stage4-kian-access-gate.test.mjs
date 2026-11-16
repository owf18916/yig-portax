// Actual Vue scripts/templates, in-memory capabilities only; no network or DB.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import * as vue from 'vue'
import { formatRefundAmount, refundAmountDifference } from '../resources/js/utils/refundPresentation.js'
import { component } from './support/vue-presentation-fixture.mjs'

test('Stage 4 post-submit Continue follows refreshed server gate; Submit remains independent', async () => {
  const props = vue.reactive({ stageName: 'SKP', stageDescription: 'SKP', caseNumber: 'TEST', exposure: null, isLoading: false, stageId: 4, nextStageId: 5, caseId: 9, fields: [], prefillData: {},
    workflowState: { stages: [{ stage: 5, reason: 'stage4_kian_not_submitted' }] } })
  const page = await component('components/StageForm.vue', { defineProps: () => props })
  page.state.submissionComplete.value = true
  page.state.formData.continue_to_next_stage = true
  assert.equal(page.state.computedNextStageId.value, null)
  assert.doesNotMatch(await page.render(), /Continue to Stage 5/)
  props.workflowState = { stages: [{ stage: 5, reason: null, submittable: true }] }
  assert.equal(page.state.computedNextStageId.value, 5)
  assert.match(await page.render(), /Continue to Stage 5/)
  for (const stage of [7, 10, 12]) {
    props.stageId = stage
    props.nextStageId = stage === 12 ? null : stage + 1
    assert.equal(page.state.computedNextStageId.value, stage === 12 ? null : stage + 1)
  }
})

test('Objection deep link blocks unfinished entry but retains submitted history', async () => {
  const page = await component('pages/ObjectionSubmissionForm.vue')
  page.state.caseData.value = { stage4_kian_access_blocked: true, workflow_state: { completed_stages: [1, 2, 3, 4] } }
  assert.match(await page.render(), /Submit the required Stage 4 KIAN/)
  page.state.caseData.value.stage4_kian_access_blocked = false
  assert.doesNotMatch(await page.render(), /Submit the required Stage 4 KIAN/)
  page.state.caseData.value.stage4_kian_access_blocked = true
  page.state.caseData.value.workflow_state.completed_stages.push(5)
  assert.doesNotMatch(await page.render(), /Submit the required Stage 4 KIAN/)
})

test('detail gates only Stage 4 Refund continuation; persisted detail and other origins remain accessible', async () => {
  const page = await component('pages/TaxCaseDetail.vue', {
    useTaxCaseStore: () => ({}), useToast: () => ({ showSuccess() {}, showError() {} })
  })
  page.state.caseData.value = { stage4_kian_access_blocked: true }
  const refund = { id: 41, stage_id: 4, current_refund_stage: 1 }
  assert.equal(page.state.shouldShowRefundButton(4), false)
  assert.equal(page.state.isRefundStageAccessible(refund, 1), true)
  assert.equal(page.state.isRefundStageAccessible(refund, 2), false)
  for (const stage_id of [0, 7, 10, 12]) {
    assert.equal(page.state.isRefundStageAccessible({ ...refund, stage_id }, 2), true)
  }
  page.state.caseData.value = { stage4_kian_access_blocked: false }
  assert.equal(page.state.isRefundStageAccessible(refund, 2), true)
})

for (const currency of ['USD', 'IDR']) {
  test(`G1 ${currency} case Refund card shows independent amount sources without per-card requests`, async () => {
    const page = await component('pages/TaxCaseDetail.vue', {
      useTaxCaseStore: () => ({}), useToast: () => ({}), refundAmountDifference,
      fetch: () => { throw new Error('Rendering cards must not fetch') },
    })
    page.state.loadState.value = 'success'
    page.state.caseData.value = { id: 9, currency: { code: currency }, refund_processes: [{ id: 41, stage_id: 7, refund_amount: '8000.00', refund_number: 'G1', bank_transfer_requests: [{ transfer_amount: '10000.00' }] }] }
    const html = await page.render({ formatRefundAmount, refundAmountDifference })
    assert.match(html, /Current Refund Amount/)
    assert.match(html, new RegExp(`${currency} 10,000\\.00`))
    assert.match(html, new RegExp(`-${currency} 2,000\\.00`))
    assert.match(html, /Information only; no automatic adjustment/)
  })
}
