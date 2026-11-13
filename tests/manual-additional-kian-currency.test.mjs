import { test } from 'node:test'
import assert from 'node:assert/strict'
import * as vue from 'vue'
import { component } from './support/vue-presentation-fixture.mjs'

for (const currency of ['USD', 'IDR', 'Currency unavailable']) for (const stage of [4, 7, 10, 12]) {
  test(`${currency} Stage ${stage}: manual amount stays blank on create and exact on draft/submitted reload`, async () => {
    let record = null
    const route = vue.reactive({ params: { id: '9', stageId: String(stage) } })
    const page = await component('pages/KianForm.vue', {
      useRoute: () => route,
      fetch: async url => ({ ok: true, json: async () => ({
        data: url.includes('/documents?') ? [] : url.includes('/kian-submissions/') ? record : {
          id: 9, case_number: 'SYNTHETIC', currency: currency === 'Currency unavailable' ? null : { code: currency },
          kian_status_by_stage: { [stage]: { needsKian: null, evaluationState: 'unresolved', lossAmount: '999.99' } },
        },
        draft_actions: { create: record === null, save_draft: record === null || record.status === 'draft', submit: record === null || record.status === 'draft' },
      }) }),
    })
    await page.state.loadPage()
    assert.equal(page.state.draftEditable.value, true)
    assert.equal(page.state.prefillData.value.loss_amount, '')
    assert.equal(page.state.prefillData.value.write_off_amount, '0.00')
    const writeOff = page.state.fields.value.find(f => f.key === 'write_off_amount')
    assert.equal(writeOff.type, 'text')
    assert.match(writeOff.label, new RegExp(currency))
    const field = page.state.fields.value.find(f => f.key === 'loss_amount')
    assert.equal(field.type, 'text')
    assert.notEqual(field.readonly, true)
    assert.match(field.label, new RegExp(currency))
    record = { id: 31, tax_case_id: 9, stage_id: stage, status: 'draft', kian_amount: '50000.00', loss_amount: '50000.00', write_off_amount: '500.25' }
    await page.state.loadPage()
    assert.equal(page.state.prefillData.value.loss_amount, '50000.00')
    assert.equal(page.state.prefillData.value.write_off_amount, '500.25')
    assert.equal(page.state.draftEditable.value, true)
    record.status = 'submitted'
    await page.state.loadPage()
    assert.equal(page.state.draftEditable.value, false)
    assert.equal(page.state.amountLabel('50000.00'), `${currency} 50,000.00`)
    assert.equal(page.state.amountLabel('999999999999999999.99'), `${currency} 999,999,999,999,999,999.99`)
    const html = await page.render()
    assert.match(html, new RegExp(`${currency} 50,000.00`))
    assert.match(html, new RegExp(`Write Off Amount[\\s\\S]*${currency} 500.25`))
    const nodes = []
    const visit = node => { if (!node || typeof node !== 'object') return; nodes.push(node); if (Array.isArray(node.children)) node.children.forEach(visit) }
    visit(page.client())
    assert.equal(nodes.some(n => n.type === 'StageForm'), false)
    assert.doesNotMatch(html, /Rp|pre-calculated|calculated automatically/)
  })
}

test('case detail uses optional capabilities, saved amounts and currency independently of exposure', async () => {
  const page = await component('pages/TaxCaseDetail.vue', {
    useTaxCaseStore: () => ({}), useToast: () => ({ showSuccess() {}, showError() {} }),
  })
  page.state.loadState.value = 'success'
  page.state.caseData.value = { id: 9, currency: { code: 'USD' }, kian_status_by_stage: {
    4: { needsKian: true, kianId: 1, submitted: true, lossAmount: '50000.00', writeOffAmount: '500.25', draft_actions: {} },
    7: { needsKian: null, evaluationState: 'unresolved', lossAmount: null, draft_actions: { create: true } },
    10: { needsKian: false, kianId: 2, lossAmount: '1.99', writeOffAmount: '0.00', draft_actions: { save_draft: true } },
    12: { needsKian: true, lossAmount: null, draft_actions: { create: false } },
  } }
  const html = await page.render()
  assert.match(html, /USD 50,000.00/)
  assert.match(html, /Write Off Amount: USD 500.25/)
  assert.match(html, /Write Off Amount: USD 0.00/)
  assert.match(html, /Additional KIAN/)
  assert.match(html, /Continue Draft KIAN/)
  assert.match(html, /View KIAN/)
  assert.doesNotMatch(html, /KIAN exposure is unresolved|compatible Decision Point inputs|Stage 12 - Supreme|Rp 50/)
  page.state.caseData.value.currency.code = 'IDR'
  assert.equal(page.state.formatKianAmount('50000.00'), 'IDR 50,000.00')
  assert.match(await page.render(), /Write Off Amount: IDR 500.25/)
  page.state.caseData.value.currency = null
  assert.match(await page.render(), /Write Off Amount: Currency unavailable 500.25/)
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

test('active shared form preserves write-off strings in save and submit payloads', async () => {
  const payloads = []
  const props = vue.reactive({ stageId: 7, caseId: 9, stageName: 'KIAN', stageDescription: 'KIAN', caseNumber: 'SYNTHETIC', exposure: null, isLoading: false, apiEndpoint: '/api/tax-cases/9/kian-submissions/7', fields: [{ id: 5, key: 'write_off_amount', label: 'Write Off Amount (USD)', type: 'text', required: true }], prefillData: { kianSubmissionStatus: 'draft', kianDraftActions: { save_draft: true, submit: true } } })
  const page = await component('components/StageForm.vue', { defineProps: () => props, fetch: async (url, options) => { payloads.push(JSON.parse(options.body)); return { ok: true, json: async () => ({ success: true, data: {} }) } } })
  for (const value of ['0.00', '500.25', '999999999999999999.99']) {
    page.state.formData.write_off_amount = value
    assert.equal(page.state.validateForm(), true)
    assert.equal(page.state.formData.write_off_amount, value)
  }
  for (const value of ['', '-1', '1e3', 'NaN', 'Infinity', '1.001', '1000000000000000000', 500]) {
    page.state.formData.write_off_amount = value
    assert.equal(page.state.validateForm(), false)
  }
  page.state.formData.write_off_amount = '500.25'
  const html = await page.render()
  assert.match(html, /recorded for reference only/)
  await page.state.executeSaveDraft()
  page.state.uploadedFiles.value = [{ id: 1, name: 'signed.pdf', documentType: 'supporting_document' }]
  await page.state.executeSubmitForm()
  assert.equal(payloads.length, 2)
  assert.equal(payloads[0].write_off_amount, '500.25')
  assert.equal(payloads[1].write_off_amount, '500.25')
})
