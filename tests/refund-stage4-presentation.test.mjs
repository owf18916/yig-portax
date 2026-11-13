// In-memory API responses and server rendering; no browser or financial writes.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import * as vue from 'vue'
import * as serverRenderer from 'vue/server-renderer'
import { parse, compileTemplate } from '@vue/compiler-sfc'
import { formatRefundMethod, formatRefundAmount } from '../resources/js/utils/refundPresentation.js'

const filename = 'RefundStage4Form.vue'
const source = await readFile(new URL('../resources/js/pages/RefundStage4Form.vue', import.meta.url), 'utf8')
const { descriptor } = parse(source)
const compiled = compileTemplate({ source: descriptor.template.content, filename, id: 'stage4-test', ssr: true, ssrCssVars: [] })
assert.deepEqual(compiled.errors, [])
const renderCode = compiled.code
  .replace(/import \{([^}]+)\} from "([^"]+)"/g, (_, names, module) =>
    `const {${names.replace(/ as /g, ': ')}} = ${module === 'vue' ? 'vue' : 'serverRenderer'}`)
  .replace('export function ssrRender', 'function ssrRender')

function fixture(data, refundId = '41') {
  const calls = []
  const context = vm.createContext({
    ...vue, vue, serverRenderer, formatRefundMethod, formatRefundAmount, console,
    onMounted: () => {}, useRoute: () => ({ params: { id: '9', refundId } }),
    useRouter: () => ({ back() {} }), useToast: () => ({ showSuccess() {}, showError() {} }),
    fetch: async (url, options) => {
      calls.push({ url, method: options.method })
      return { ok: true, json: async () => ({ data }) }
    },
  })
  vm.runInContext(descriptor.scriptSetup.content.replace(/^import .*$/gm, '') + `
    globalThis.state = { currencyCode, isLoading, isSubmitting, canSubmit, apiError, isPreliminary,
      refundNumber, refundAmount, refundMethod, refundStatus, receiptCompleted,
      formData, formatCurrency, amountWarning, completionMessage, loadRefundData, submitForm };
    ${renderCode}
    globalThis.render = ssrRender;
  `, context)
  return { state: context.state, calls, render: async () => serverRenderer.renderToString(vue.createSSRApp({
    setup: () => context.state, ssrRender: context.render,
    components: { FormField: { render: () => null } },
  })) }
}

const completed = {
  refund_number: 'ORD-A', refund_amount: '800.12', refund_method: 'bank_transfer',
  refund_status: 'completed', is_preliminary: false, can_submit: false,
  receipt_number: 'RCPT-A', received_date: '2026-11-04', received_amount: '800.12', notes: 'Retained A',
}

test('ordinary completed receipt renders the persisted method and Refund-only footer after load/reload', async () => {
  const page = fixture(completed)
  for (let reload = 0; reload < 2; reload++) {
    await page.state.loadRefundData()
    const html = await page.render()
    assert.match(html, /Bank Transfer/)
    assert.doesNotMatch(html, /N\/A/)
    assert.match(html, /complete only the selected Refund/)
    assert.match(html, /leaves the TaxCase\/SPT status and main workflow unchanged/)
    assert.doesNotMatch(html, /automatically closes|status will be updated/)
    assert.match(html, /value="RCPT-A"[^>]*disabled/)
    assert.match(html, /disabled>Complete Refund/)
    assert.equal(page.state.formData.value.received_amount, '800.12')
    assert.equal(page.state.receiptCompleted.value, true)
    assert.equal(page.state.canSubmit.value, false)
  }
  assert.deepEqual(page.calls, Array(2).fill({ url: '/api/tax-cases/9/refunds/41/refund-stages/4', method: 'GET' }))
})

test('separate Preliminary receipt retains approved/full-receipt automatic CLOSED messaging', async () => {
  const page = fixture({ ...completed, is_preliminary: true, refund_number: 'PRE-B', receipt_number: 'RCPT-B' }, '42')
  await page.state.loadRefundData()
  const html = await page.render()
  assert.match(html, /Bank Transfer/)
  assert.match(html, /approved Preliminary Refund/)
  assert.match(html, /single receipt equal to the full approved amount/)
  assert.match(html, /automatically closes the eligible TaxCase\/SPT \(CLOSED\)/)
  assert.doesNotMatch(html, /RCPT-A|ORD-A|complete only the selected Refund/)
  assert.match(html, /RCPT-B/)
  assert.equal(page.calls[0].url, '/api/tax-cases/9/refunds/42/refund-stages/4')
  assert.equal(page.calls[0].method, 'GET')
})

test('missing and unknown methods never fabricate a supported method', async () => {
  for (const method of [undefined, null, '', 'unrecognized']) {
    const page = fixture({ ...completed, refund_method: method })
    await page.state.loadRefundData()
    const html = await page.render()
    assert.match(html, method ? /unrecognized/ : /N\/A/)
    assert.doesNotMatch(html, /Bank Transfer/)
  }
  for (const [method, label] of Object.entries({ BANK_TRANSFER: 'Bank Transfer', bank_transfer: 'Bank Transfer', CHEQUE: 'Cheque', check: 'Cheque', CASH: 'Cash', credit: 'Credit' })) {
    assert.equal(formatRefundMethod(method), label)
  }
})

test('another selected ordinary Refund renders its own method and receipt', async () => {
  const page = fixture({ ...completed, refund_number: 'ORD-B', refund_method: 'check', receipt_number: 'RCPT-B', notes: 'Retained B' }, '43')
  await page.state.loadRefundData()
  const html = await page.render()
  assert.match(html, /Cheque/)
  assert.match(html, /ORD-B/)
  assert.match(html, /RCPT-B/)
  assert.match(html, /Retained B/)
  assert.doesNotMatch(html, /ORD-A|RCPT-A|Retained A|Bank Transfer/)
  assert.deepEqual(page.calls, [{ url: '/api/tax-cases/9/refunds/43/refund-stages/4', method: 'GET' }])
})
