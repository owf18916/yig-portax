import { test } from 'node:test'
import assert from 'node:assert/strict'
import * as vue from 'vue'
import { component } from './support/vue-presentation-fixture.mjs'
import { refundAmountError, formatRefundAmount } from '../resources/js/utils/refundPresentation.js'

for (const stage of [4, 7, 10, 12]) {
  test(`Stage ${stage}: real action input/template preserves amount through toggles and reload`, async () => {
    const props = vue.reactive({ modelValue: { createRefund: true, refundAmount: '50000.13', continueToNextStage: false }, currencyCode: 'USD', error: '', showContinue: stage !== 12, disabled: false, nextStageName: 'Next' })
    const updates = []
    const page = await component('components/DecisionActions.vue', { defineProps: () => props, watch: vue.watch, validateRefundAmount: refundAmountError, defineEmits: () => (event, value) => { if (event === 'update:modelValue') updates.push(value) } })
    assert.match(await page.render(), /Refund Amount \(USD\)/)
    assert.match(await page.render(), /type="text" inputmode="decimal"[^>]*value="50000.13"/)
    const find = (node, predicate) => predicate(node) ? node : Array.isArray(node?.children) ? node.children.map(child => find(child, predicate)).find(Boolean) : undefined
    const input = find(page.client(), node => node?.type === 'input' && node.props.type === 'text')
    input.props.onInput({ target: { value: '9007199254740993.12' } })
    assert.equal(updates.at(-1).refundAmount, '9007199254740993.12')
    page.state.updateAction('createRefund', false)
    assert.doesNotMatch(await page.render(), /id="decision-refund-amount"/)
    page.state.updateAction('continueToNextStage', true)
    page.state.updateAction('createRefund', true)
    assert.equal(updates.at(-1).refundAmount, '9007199254740993.12')
    props.modelValue = { createRefund: true, refundAmount: '123.45', continueToNextStage: false }
    await vue.nextTick()
    assert.equal(page.state.actions.value.refundAmount, '123.45')
    if (stage === 12) assert.equal(((await page.render()).match(/type="checkbox"/g) || []).length, 1)
    props.disabled = true
    assert.match(await page.render(), /id="decision-refund-amount"[^>]*disabled/)
  })

  test(`Stage ${stage}: shared form mount/watch/draft/final payload stays exact`, async () => {
    const mounted = [], calls = [], stops = []
    const props = vue.reactive({ stageId: stage, caseId: 9, fields: [], prefillData: { create_refund: true, refund_amount: '50000.13', continue_to_next_stage: true }, isLoading: false })
    const page = await component('components/StageForm.vue', {
      defineProps: () => props, onMounted: fn => mounted.push(fn),
      watch: (...args) => { const stop = vue.watch(...args); stops.push(stop); return stop },
      fetch: async (url, options = {}) => { calls.push({ url, body: options.body && JSON.parse(options.body) }); return { ok: true, json: async () => ({ data: [] }) } },
    })
    for (const fn of mounted) await fn()
    await vue.nextTick()
    assert.equal(page.state.decisionActions.value.refundAmount, '50000.13')
    assert.equal(page.state.formData.refund_amount, '50000.13')
    page.state.decisionActions.value = { createRefund: false, refundAmount: '50000.13', continueToNextStage: false }
    await vue.nextTick()
    assert.equal(page.state.formData.refund_amount, '50000.13')
    await page.state.executeSaveDraft()
    assert.equal(calls.at(-1).body.refund_amount, '50000.13')
    assert.equal(calls.at(-1).body.create_refund, false)
    assert.equal(calls.at(-1).body.action, 'draft')
    props.prefillData = { create_refund: true, refund_amount: '56789.12', continue_to_next_stage: false }
    await vue.nextTick()
    assert.equal(page.state.formData.refund_amount, '56789.12')
    await page.state.executeSubmitForm()
    assert.equal(calls.at(-1).body.refund_amount, '56789.12')
    assert.equal(calls.at(-1).body.create_refund, true)
    assert.equal(calls.at(-1).body.action, 'submit')
    if (stage === 12) assert.equal(Object.hasOwn(calls.at(-1).body, 'continue_to_next_stage'), false)
    for (const value of ['', '0', '-1', '0.001', '1e3', 'NaN', 'Infinity', '1000000000000000000']) {
      page.state.formData.refund_amount = value
      assert.equal(page.state.validateForm(), false, value)
    }
    page.state.formData.refund_amount = ''
    assert.equal(page.state.validateForm(true), true)
    page.state.formData.create_refund = false
    assert.equal(page.state.validateForm(), true)
    for (const stop of stops) stop()
  })
}

const pages = { 4: ['SkpFilingForm', 'skp_record'], 7: ['ObjectionDecisionForm', 'objection_decision'], 10: ['AppealDecisionForm', 'appeal_decision'], 12: ['SupremeCourtDecisionForm', 'supreme_court_decision'] }
for (const currency of ['USD', 'IDR']) for (const [stage, [name, relation]] of Object.entries(pages)) {
  test(`${currency} Stage ${stage} wrapper loads and refreshes the exact stored amount and currency`, async () => {
    const mounted = []
    const record = { create_refund: true, refund_amount: '50000.13', decision_date: '2026-11-13', decision_type: 'partially_granted', skp_type: 'LB' }
    const data = { id: 9, case_number: 'SYNTHETIC', currency: { code: currency }, [relation]: record }
    const page = await component(`pages/${name}.vue`, {
      onMounted: fn => mounted.push(fn), setTimeout() {}, useToast: () => ({ showError() {} }),
      fetch: async url => ({ ok: true, json: async () => ({ data: url === '/api/tax-cases/9' ? data : [] }) }),
    })
    for (const fn of mounted) await fn()
    if (stage === '7') await page.state.loadTaxCase()
    assert.equal(page.state.prefillData.value.refund_amount, '50000.13')
    record.refund_amount = '123.45'
    await page.state.refreshTaxCase()
    assert.equal(page.state.prefillData.value.refund_amount, '123.45')
    const find = node => node?.type === 'StageForm' ? node : Array.isArray(node?.children) ? node.children.map(find).find(Boolean) : undefined
    assert.equal(find(page.client()).props['currency-code'], currency)
  })
}

for (const currency of ['USD', 'IDR', '']) for (const stage of [1, 2, 3, 4]) {
  test(`ordinary Stage ${stage} renders scoped currency '${currency}' and exact cents without extra requests`, async () => {
    const calls = []
    const page = await component(`pages/RefundStage${stage}Form.vue`, {
      useRoute: () => ({ params: { id: '9', refundId: '41' }, query: {} }),
      useToast: () => ({ showSuccess() {}, showError() {} }),
      fetch: async url => { calls.push(url); return { ok: true, json: async () => ({ data: { refund_amount: '50000.13', currency_code: currency, is_preliminary: false, can_submit: true, refund_method: 'bank_transfer', current_stage: 1 } }) } },
    })
    await (stage === 1 ? page.state.loadExistingRefund() : page.state.loadRefundData())
    const html = await page.render()
    assert.match(html, new RegExp(stage === 1 ? 'value="50000.13"' : `${currency ? currency + ' ' : ''}50,000.13`))
    if (stage === 1) assert.match(html, /value="50000.13"[^>]*disabled/)
    if (currency !== 'IDR') assert.doesNotMatch(html, /Rp|IDR/)
    assert.equal(calls.length, stage === 1 ? 2 : 1)
    assert.equal(page.state.formatCurrency('999999999999999999.99'), `${currency ? currency + ' ' : ''}999,999,999,999,999,999.99`)
  })
}

test('currency formatter never converts unknown currency to IDR', () => {
  assert.equal(formatRefundAmount('50000.13'), '50,000.13')
  assert.equal(formatRefundAmount('50000.13', 'USD'), 'USD 50,000.13')
})
