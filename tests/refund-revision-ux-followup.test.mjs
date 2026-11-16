import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import * as vue from 'vue'
import * as metadata from '../resources/js/composables/useRevisionFields.js'
import { refundOriginDescription } from '../resources/js/utils/refundPresentation.js'
import { component } from './support/vue-presentation-fixture.mjs'

const source = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')
const globals = props => ({ ...metadata, defineProps: () => props,
  useToast: () => ({ showSuccess() {}, showError() {} }),
  fetch: () => { throw new Error('Unexpected network request') } })
const modalProps = (stageId = 4) => vue.reactive({ stageId, caseId: 9, fields: [], currentDocuments: [], taxCase: { currency: { code: 'USD' }, spt_position_type: 'LB' } })

for (const currency of ['USD', 'IDR', '']) {
  test(`exact money display/preview with currency '${currency}'`, () => {
    for (const [raw, expected] of [['5000000', '5,000,000.00'], ['0', '0.00'], ['001.2', '001.20'], ['999999999999999999.99', '999,999,999,999,999,999.99']]) {
      assert.equal(metadata.revisionMoneyPreview(raw, currency), [currency, expected].filter(Boolean).join(' '))
      assert.equal(metadata.revisionDisplayValue('refund_amount', raw, currency), [currency, expected].filter(Boolean).join(' '))
    }
    for (const raw of ['', null, '1.234', '1e6', 'NaN', ' 5 ', '1,000', '-1', '1000000000000000000', '1.']) assert.equal(metadata.revisionMoneyPreview(raw, currency), '')
    assert.equal(metadata.revisionDisplayValue('refund_amount', '-5000.25', currency), [currency, '-5,000.25'].filter(Boolean).join(' '))
    for (const raw of [null, undefined, '']) assert.equal(metadata.revisionDisplayValue('refund_amount', raw, currency), '(empty)')
    for (const field of ['id', 'quantity', 'counter', 'decision_date']) assert.equal(metadata.revisionDisplayValue(field, '5000000', currency), '5000000')
    assert.equal(metadata.revisionDisplayValue('create_refund', false, currency), 'No')
    assert.equal(metadata.revisionDisplayValue('continue_to_next_stage', true, currency), 'Yes')
    assert.equal(metadata.revisionDisplayValue('refund_amount', '1.234', currency), [currency, '1.234'].filter(Boolean).join(' '))
    assert.equal(metadata.revisionCurrency({ currency_id: 1 }), '')
  })
}

test('every backend Stage 1–12 allowlist has matching canonical modal keys and readable labels', async () => {
  const backend = await source('app/Config/RevisionFieldConfig.php')
  const modal = await source('resources/js/components/RequestRevisionModalV2.vue')
  for (let stage = 1; stage <= 12; stage++) {
    const phpLine = backend.split('\n').find(line => new RegExp(`^\\s*${stage} =>`).test(line))
    const fields = [...phpLine.matchAll(/'([^']+)'=>'/g)].map(match => match[1])
    const jsLine = modal.split('\n').find(line => new RegExp(`^\\s*${stage}:`).test(line))
    assert.deepEqual([...jsLine.matchAll(/'([^']+)'/g)].map(match => match[1]), fields)
    const fixture = await component('components/RequestRevisionModalV2.vue', globals(modalProps(stage)))
    for (const field of [...fields, 'supporting_docs']) {
      assert.equal(fixture.state.fieldLabel(field), metadata.revisionFieldLabel(field, stage))
      assert.doesNotMatch(fixture.state.fieldLabel(field), /_/)
      assert.notEqual(fixture.state.fieldLabel(field), field)
    }
    assert.match(await fixture.render(metadata), /Select fields to revise/)
    if ([4, 7, 10, 12].includes(stage)) assert.equal(fixture.state.getFieldType('refund_amount'), 'number')
  }
  assert.equal(metadata.revisionFieldLabel('future_business_field'), 'Future Business Field')
})

test('real modal input handler preserves raw text; reactive preview follows value and same-case currency', async () => {
  const props = modalProps()
  let payload
  const fixture = await component('components/RequestRevisionModalV2.vue', { ...globals(props),
    fetch: async (url, options) => { payload = JSON.parse(options.body.get('payload')); assert.equal(url, '/api/tax-cases/9/revisions/request'); return { ok: true, json: async () => ({ revision: { id: 3 } }) } },
  })
  const state = fixture.state
  state.selectedFields.value = ['refund_amount', 'create_refund']
  state.handleFieldToggle()
  state.proposedValues.value.create_refund = false
  state.reason.value = 'Documentary correction'
  const findInput = node => node?.type === 'input' && node.props?.inputmode === 'decimal' ? node : Array.isArray(node?.children) ? node.children.map(findInput).find(Boolean) : undefined
  const input = findInput(fixture.client(metadata))
  assert.equal(input.props.type, 'text')
  for (const raw of ['5000000', '999999999999999999.99', '1.234', '1e6', '-1', '0']) {
    input.props['onUpdate:modelValue'](raw)
    assert.equal(state.proposedValues.value.refund_amount, raw)
    const html = await fixture.render(metadata)
    assert.equal(html.includes('USD 5,000,000.00'), raw === '5000000')
    if (['1.234', '1e6', '-1'].includes(raw)) {
      payload = undefined
      await state.submit()
      assert.equal(payload, undefined)
      assert.equal(state.proposedValues.value.refund_amount, raw)
    }
  }
  state.proposedValues.value.refund_amount = '0005000000.10'
  props.taxCase.currency.code = 'IDR'
  assert.match(await fixture.render(metadata), /IDR 0,005,000,000.10/)
  await state.submit()
  assert.equal(payload.proposed_values.refund_amount, '0005000000.10')
  assert.equal(payload.proposed_values.create_refund, false)
  assert.deepEqual(payload.fields, ['refund_amount', 'create_refund'])
  assert.equal(payload.stage_code, 4)
})

test('history preserves submitted/draft availability and formats only money', async () => {
  const props = vue.reactive({ caseId: 9, stageId: 4, fields: [], entityType: 'tax-cases', currentDocuments: [], periodsList: [], revisions: [],
    taxCase: { currency: { code: 'USD' }, workflow_histories: [] } })
  const page = await component('components/RevisionHistoryPanel.vue', globals(props))
  assert.equal(page.state.canRequestRevision.value, false)
  props.taxCase.workflow_histories.push({ stage_id: 4, status: 'submitted' })
  assert.equal(page.state.canRequestRevision.value, true)
  props.revisions.push({ id: 1, stage_code: 4, revision_status: 'approved', created_at: '2026-11-16', proposed_values: { refund_amount: '5000000.12', create_refund: false, decision_number: '5000000' } })
  props.revisions.push({ id: 2, stage_code: 7, revision_status: 'approved', created_at: '2026-11-16', proposed_values: { refund_amount: '999' } })
  const html = await page.render()
  assert.match(html, /Refund Amount: USD 5,000,000.12/)
  assert.match(html, /Create Refund Process: No/)
  assert.match(html, /Decision Letter Number: 5000000/)
  assert.doesNotMatch(html, /999/)
})

test('comparison and approval show exact original/proposed values without touching stored history', async () => {
  const props = { caseId: 9, stageId: 4, fields: [], periodsList: [], allDocuments: [], entityType: 'tax-cases', taxCase: { currency: { code: 'IDR' } },
    revision: { id: 1, stage_code: 4, revision_status: 'approved', original_data: { refund_amount: '10000000.01' }, revised_data: { refund_amount: '8000000.02' }, proposed_values: { refund_amount: '8000000.02' } } }
  const snapshot = JSON.stringify(props.revision)
  const comparison = await component('components/BeforeAfterComparison.vue', globals(props))
  assert.match(await comparison.render(), /IDR 10,000,000.01/)
  assert.match(await comparison.render(), /IDR 8,000,000.02/)
  const approval = await component('components/RevisionApprovalModalV2.vue', globals(props))
  assert.equal(approval.state.getOriginalValue('refund_amount'), 'IDR 10,000,000.01')
  assert.equal(approval.state.formatProposedValue('refund_amount', '8000000.02'), 'IDR 8,000,000.02')
  assert.equal(JSON.stringify(props.revision), snapshot)
})

test('generated Refund reference gets grounded origin copy; original reference remains in rendered page', async () => {
  for (const [stage, origin] of [[4, 'SKP'], [7, 'Objection'], [10, 'Appeal'], [12, 'Supreme Court']]) assert.equal(refundOriginDescription(`DECISION-${stage}-11-7`), `Refund from ${origin} Decision (Stage ${stage})`)
  for (const value of ['OFFICIAL-123', 'DECISION-5-11-7', 'DECISION-malformed']) assert.equal(refundOriginDescription(value), value)
  const page = await component('pages/RefundStage2Form.vue', { ...globals({}), useRoute: () => ({ params: { id: '9', refundId: '41' } }), fetch: async () => ({ ok: true, json: async () => ({ data: { refund_number: 'DECISION-4-11-7', refund_amount: '5000000', currency_code: 'USD', current_stage: 1, can_submit: false } }) }) })
  await page.state.loadRefundData()
  const html = await page.render()
  assert.match(html, /Refund from SKP Decision \(Stage 4\)/)
  assert.match(html, /Reference: DECISION-4-11-7/)
})
