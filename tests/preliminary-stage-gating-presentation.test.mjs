// Component logic and SSR only: no browser, host, database, or real API.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import * as vue from 'vue'

import { component, eligibleCase } from './support/vue-presentation-fixture.mjs'

test('Stage 1 renders Preliminary CTA and never a normal Stage 2 Continue; server refresh restores ordinary capability', async () => {
  const props = vue.reactive({ stageName: 'SPT', stageDescription: 'Filed SPT', exposure: null, isLoading: false, stageId: 1, nextStageId: 2, caseId: 9, caseNumber: 'TEST', fields: [], prefillData: {},
    workflowState: { stages: [{ stage: 2, submittable: false }] }, preliminaryRequestAvailable: true })
  const page = await component('components/StageForm.vue', { defineProps: () => props })
  page.state.submissionComplete.value = true
  assert.doesNotMatch(await page.render(), /Continue to Stage 2/)
  assert.match(await page.render(), /Catat Pengajuan Pengembalian Pendahuluan/)
  props.workflowState = { stages: [{ stage: 2, submittable: true }] }
  props.preliminaryRequestAvailable = false
  assert.match(await page.render(), /Continue to Stage 2/)
  props.workflowState = null
  assert.equal(page.state.computedNextStageId.value, null)
})

test('stale Stage 2 URL has no form while loading, locked, or denied; ordinary available and completed reads remain visible', async () => {
  const page = await component('pages/Sp2FilingForm.vue')
  assert.equal(page.state.stageAccessible.value, false)
  page.state.isLoading.value = false
  assert.match(await page.render(), /Stage 2 is unavailable/)
  for (const status of ['locked', 'available', 'completed']) {
    page.state.caseData.value = { workflow_state: { stages: [{ stage: 2, status }] } }
    assert.equal(page.state.stageAccessible.value, status !== 'locked')
  }
})

test('request form renders English controls, shared styling, PDF restrictions, and readonly outcomes', async () => {
  const page = await component('pages/PreliminaryRefundForm.vue')
  page.state.caseData.value = eligibleCase
  assert.match(await page.render(), /Loading or saving/)
  page.state.busy.value = false
  let html = await page.render()
  assert.match(html, /Submission date \(required\)/)
  assert.match(html, /Requested amount \(required\)/)
  assert.match(html, /maximum 10 MB/)
  assert.match(html, /focus:ring-blue-500/)
  assert.match(html, /accept=".pdf,application\/pdf"/)
  assert.doesNotMatch(html, /Tanggal|Jumlah|Kembali|Memuat/)
  for (const status of ['APPROVED', 'REJECTED', 'PENDING', 'POSTPONED']) {
    page.state.request.value = { approval_status: status, request_number: 'R-9' }
    assert.doesNotMatch(await page.render(), /<form/)
  }
  page.state.request.value = { approval_status: 'SUBMITTED', submission_document: { id: 1, original_filename: 'proof.pdf' } }
  assert.match(await page.render(), /Tax Office outcome/)
  page.state.caseData.value = { ...eligibleCase, is_completed: true }
  html = await page.render()
  assert.match(html, /Closed \(CLOSED\): evidence remains available/)
  assert.doesNotMatch(html, /<form/)
  page.state.caseData.value = { ...eligibleCase, preliminary_can_decide: false }
  assert.doesNotMatch(await page.render(), /<form/)
  assert.match(await page.render(), /You can view evidence only/)
})

test('submission preserves POST multipart shape and reloads evidence; validation and forbidden copy use English', async () => {
  const calls = []
  const page = await component('pages/PreliminaryRefundForm.vue', { fetch: async (url, options) => {
    calls.push({ url, ...options })
    return { ok: true, json: async () => ({ data: url.endsWith('/preliminary-refund-request') ? { id: 7, approval_status: 'SUBMITTED' } : eligibleCase }) }
  } })
  await page.state.send('create')
  assert.equal(calls.length, 0)
  assert.match(page.state.error.value, /A PDF document/)
  page.state.documentFile.value = { name: 'proof.pdf', size: 120, type: 'application/pdf' }
  page.state.submissionDate.value = '2026-11-04'
  page.state.requestedAmount.value = '900.12'
  await page.state.send('create')
  assert.equal(calls[0].url, '/api/tax-cases/9/preliminary-refund-request')
  assert.equal(calls[0].method, 'POST')
  assert.deepEqual([...calls[0].body.keys()], ['document', 'submission_date', 'requested_amount'])
  assert.equal(calls[0].body.get('requested_amount'), '900.12')
  assert.equal(calls[0].credentials, 'include')
  assert.equal(page.state.request.value.id, 7)
  assert.match(page.state.success.value, /recorded successfully/)
  for (const status of [403, 419, 422]) {
    const failed = await component('pages/PreliminaryRefundForm.vue', { fetch: async () => ({ ok: false, status,
      json: async () => ({ message: 'PRIVATE_BACKEND_MESSAGE', errors: { submission_date: ['PRIVATE_VALIDATION'] } }) }) })
    await assert.rejects(failed.state.api('/mock'), e => e.status === status && !/PRIVATE_/.test(e.message))
  }
})
