import { test } from 'node:test'
import assert from 'node:assert/strict'
import * as vue from 'vue'
import { component } from './support/vue-presentation-fixture.mjs'

for (const currency of ['USD', 'IDR']) for (const stage of [4, 7, 10, 12]) {
  test(`${currency} Stage ${stage}: manual amount stays blank on create and exact on draft/submitted reload`, async () => {
    let record = null
    const route = vue.reactive({ params: { id: '9', stageId: String(stage) } })
    const page = await component('pages/KianForm.vue', {
      useRoute: () => route,
      fetch: async url => ({ ok: true, json: async () => ({
        data: url.includes('/documents?') ? [] : url.includes('/kian-submissions/') ? record : {
          id: 9, case_number: 'SYNTHETIC', currency: { code: currency },
          kian_status_by_stage: { [stage]: { needsKian: null, evaluationState: 'unresolved', lossAmount: '999.99' } },
        },
        draft_actions: { create: record === null, save_draft: record === null || record.status === 'draft', submit: record === null || record.status === 'draft' },
      }) }),
    })
    await page.state.loadPage()
    assert.equal(page.state.draftEditable.value, true)
    assert.equal(page.state.prefillData.value.loss_amount, '')
    const field = page.state.fields.value.find(f => f.key === 'loss_amount')
    assert.equal(field.type, 'text')
    assert.notEqual(field.readonly, true)
    assert.match(field.label, new RegExp(currency))
    record = { id: 31, tax_case_id: 9, stage_id: stage, status: 'draft', kian_amount: '50000.00', loss_amount: '50000.00' }
    await page.state.loadPage()
    assert.equal(page.state.prefillData.value.loss_amount, '50000.00')
    assert.equal(page.state.draftEditable.value, true)
    record.status = 'submitted'
    await page.state.loadPage()
    assert.equal(page.state.draftEditable.value, false)
    assert.equal(page.state.amountLabel('50000.00'), `${currency} 50,000.00`)
    assert.equal(page.state.amountLabel('999999999999999999.99'), `${currency} 999,999,999,999,999,999.99`)
    const html = await page.render()
    assert.match(html, new RegExp(`${currency} 50,000.00`))
    assert.doesNotMatch(html, /Rp|pre-calculated|calculated automatically/)
  })
}

test('case detail uses optional capabilities, saved amounts and currency independently of exposure', async () => {
  const page = await component('pages/TaxCaseDetail.vue', {
    useTaxCaseStore: () => ({}), useToast: () => ({ showSuccess() {}, showError() {} }),
  })
  page.state.loadState.value = 'success'
  page.state.caseData.value = { id: 9, currency: { code: 'USD' }, kian_status_by_stage: {
    4: { needsKian: true, kianId: 1, submitted: true, lossAmount: '50000.00', draft_actions: {} },
    7: { needsKian: null, evaluationState: 'unresolved', lossAmount: null, draft_actions: { create: true } },
    10: { needsKian: false, kianId: 2, lossAmount: '1.99', draft_actions: { save_draft: true } },
    12: { needsKian: true, lossAmount: null, draft_actions: { create: false } },
  } }
  const html = await page.render()
  assert.match(html, /USD 50,000.00/)
  assert.match(html, /Additional KIAN/)
  assert.match(html, /Continue Draft KIAN/)
  assert.match(html, /View KIAN/)
  assert.doesNotMatch(html, /KIAN exposure is unresolved|compatible Decision Point inputs|Stage 12 - Supreme|Rp 50/)
  page.state.caseData.value.currency.code = 'IDR'
  assert.equal(page.state.formatKianAmount('50000.00'), 'IDR 50,000.00')
})

test('active shared form validates exact manual money without exposure cap or coercion', async () => {
  const props = vue.reactive({ stageId: 7, caseId: 9, fields: [{ key: 'loss_amount', type: 'text', required: true }], prefillData: { kianSubmissionStatus: 'draft', kianDraftActions: { save_draft: true, submit: true } } })
  const page = await component('components/StageForm.vue', { defineProps: () => props })
  for (const value of ['0', '50000.00', '9007199254740993.12']) {
    page.state.formData.loss_amount = value
    assert.equal(page.state.validateForm(), true)
    assert.equal(page.state.formData.loss_amount, value)
  }
  for (const value of ['', '-1', '0.001', '1e5', 'NaN', 'Infinity']) {
    page.state.formData.loss_amount = value
    assert.equal(page.state.validateForm(), false)
  }
})
