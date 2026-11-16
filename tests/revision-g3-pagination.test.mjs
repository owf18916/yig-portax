import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import * as vue from 'vue'
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc'
import { component } from './support/vue-presentation-fixture.mjs'
import * as fields from '../resources/js/composables/useRevisionFields.js'

const meta = (page = 1, pending = null) => ({ current_page: page, last_page: 3, total: 45, pending })
const row = (id, stage = 11, status = 'approved') => ({ id, stage_code: stage, revision_status: status, created_at: '2026-11-01', proposed_values: { review_amount: '12.34' } })
const setup = async (listRevisions) => {
  const props = vue.reactive({ caseId: 9, stageId: 11, entityType: 'tax-cases', fields: [], currentDocuments: [], periodsList: [],
    taxCase: { currency: { code: 'USD' }, workflow_histories: [{ stage_id: 11, status: 'submitted' }] } })
  let contextChanged, unmount
  const fixture = await component('components/RevisionHistoryPanel.vue', { ...fields, defineProps: () => props,
    useToast: () => ({ showSuccess() {}, showError() {} }), useRevisionAPI: () => ({ listRevisions }),
    watch: (source, cb) => { contextChanged = cb }, onUnmounted: cb => { unmount = cb },
  })
  return { ...fixture, props, contextChanged: () => contextChanged(), unmount: () => unmount() }
}

test('history navigates bounded pages, pending jump and canonical refresh after every mutation', async () => {
  const requests = []
  let pending = { id: 1, page: 3 }
  const page = await setup(async (entity, id, options) => {
    requests.push([entity, id, options.stageCode, options.page])
    return { data: [row(options.page === 3 ? 1 : 45, 11, options.page === 3 ? 'requested' : 'approved')], meta: meta(options.page, pending) }
  })
  await page.state.reload()
  assert.equal(page.state.canRequestRevision.value, false)
  assert.match(await page.render(), /View pending Revision #1/)
  assert.match(await page.render(), /USD 12.34/)
  const flatten = n => !n || typeof n !== 'object' ? [] : [n, ...(Array.isArray(n.children) ? n.children.flatMap(flatten) : [])]
  const next = flatten(page.client()).find(n => n.type === 'button' && n.children === 'Next')
  await next.props.onClick()
  assert.equal(page.state.pageMeta.value.current_page, 2)
  const jump = flatten(page.client()).find(n => n.type === 'button' && String(n.children).includes('View pending'))
  await jump.props.onClick()
  assert.equal(page.state.pageRows.value[0].id, 1)
  assert.equal(page.state.pageMeta.value.current_page, 3)
  pending = null
  for (const handler of ['onRevisionRequested', 'onRevisionApproved', 'onRevisionRejected']) {
    page.state[handler](row(99))
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(page.state.pageMeta.value.current_page, 1)
    assert.equal(page.state.pageRows.value.length, 1)
    assert.equal(page.state.canRequestRevision.value, true)
  }
  assert.equal(requests.length, 6)
  assert.deepEqual(requests[1], ['tax-cases', 9, 11, 2])
})

test('out-of-order responses, errors and case/stage navigation never restore stale history', async () => {
  const pending = []
  const page = await setup((...args) => new Promise((resolve, reject) => pending.push({ args, resolve, reject })))
  const old = page.state.reload(2)
  page.props.caseId = 10; page.props.stageId = 4
  page.contextChanged()
  assert.equal(page.state.historyLoading.value, true)
  assert.equal(page.state.pageRows.value.length, 0)
  pending[1].resolve({ data: [row(7, 4)], meta: meta(1) })
  await new Promise(resolve => setImmediate(resolve))
  pending[0].resolve({ data: [row(99)], meta: meta(2) })
  await old
  assert.equal(page.state.pageRows.value[0].id, 7)
  const fail = page.state.reload(); pending[2].reject(new Error('Synthetic page failure')); await fail
  assert.equal(page.state.canRequestRevision.value, false)
  assert.match(await page.render(), /Synthetic page failure/)
  assert.doesNotMatch(await page.render(), /No revisions for this stage yet/)
  const retry = page.state.reload(); pending[3].resolve({ data: [], meta: { ...meta(), last_page: 1, total: 0 } }); await retry
  assert.match(await page.render(), /No revisions for this stage yet/)
  const gone = page.state.reload(); page.unmount()
  pending[4].resolve({ data: [row(88,4)], meta: meta() }); await gone
  assert.equal(page.state.pageRows.value.length, 0)
})

test('Stage 11 raw input, exact preview and capacity checks never convert through Number', async () => {
  const props = { stageId: 11, fields: [], prefillData: {}, workflowState: null }
  const form = await component('components/StageForm.vue', { defineProps: () => props })
  props.fields = [{ id: 1, type: 'number', key: 'review_amount', label: 'Review Amount', required: true }]
  for (const value of ['12', '12.34', '0.01', '999999999999999.99']) {
    form.state.formData.review_amount = value
    assert.equal(form.state.validateForm(), true)
    assert.equal(form.state.formData.review_amount, value)
    assert.equal(fields.revisionProposal(11, 'review_amount', { review_amount: value }), value)
  }
  for (const value of ['12.345', '1000000000000000', '1e3', '-1']) {
    form.state.formData.review_amount = value
    assert.equal(form.state.validateForm(), false)
    assert.equal(form.state.formData.review_amount, value)
    assert.throws(() => fields.revisionProposal(11, 'review_amount', { review_amount: value }))
  }
  const flatten = n => !n || typeof n !== 'object' ? [] : [n, ...(Array.isArray(n.children) ? n.children.flatMap(flatten) : [])]
  const control = flatten(form.client()).find(n => n.type === 'FormField' && n.props?.inputmode === 'decimal')
  assert.equal(control.props.type, 'text')
  control.props['onUpdate:modelValue']('12.345')
  assert.equal(form.state.formData.review_amount, '12.345')
  assert.equal(fields.revisionMoneyPreview('999999999999999.99','USD'), 'USD 999,999,999,999,999.99')
})

test('API client sends server stage/page and preserves the bounded envelope', async () => {
  const source = (await readFile(new URL('../resources/js/composables/useRevisionAPI.js', import.meta.url),'utf8')).replace(/^import .*$/gm,'').replace('export function','function')
  const urls=[]
  const result={ data: [row(1)], meta: meta(2) }
  const context=vm.createContext({ ...vue, URLSearchParams, fetch: async url => { urls.push(url); return { ok:true,json:async()=>result } } })
  vm.runInContext(source + ';globalThis.api=useRevisionAPI()',context)
  assert.equal(await context.api.listRevisions('tax-cases',9,{stageCode:11,page:2}),result)
  assert.equal(urls[0],'/api/tax-cases/9/revisions?page=2&stage_code=11')
})

test('all affected Vue wrappers compile in memory and no longer fetch full case history', async () => {
  const paths = ['AppealDecision','AppealExplanationRequest','AppealSubmission','BankTransferRequest','ObjectionDecision','ObjectionSubmission','RefundReceived','SkpFiling','Sp2Filing','SphpFiling','SptFiling','SpuhRecord','SupremeCourtDecision','SupremeCourtSubmission','SuratInstruksiTransfer']
  for (const name of paths) {
    const source = await readFile(new URL(`../resources/js/pages/${name}Form.vue`,import.meta.url),'utf8')
    assert.doesNotMatch(source,/listRevisions|:revisions=/)
    const { descriptor, errors } = parse(source)
    assert.deepEqual(errors,[])
    compileScript(descriptor,{id:name})
    assert.deepEqual(compileTemplate({source:descriptor.template.content,filename:name,id:name}).errors,[])
  }
})
