// Actual page scripts/templates with in-memory GETs; no browser or database.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import * as vue from 'vue'
import * as serverRenderer from 'vue/server-renderer'
import { parse, compileTemplate } from '@vue/compiler-sfc'
import { formatRefundMethod } from '../resources/js/utils/refundPresentation.js'

const pages = new Map()
for (const stage of [1, 2, 3, 4]) {
  const filename = `RefundStage${stage}Form.vue`
  const source = await readFile(new URL(`../resources/js/pages/${filename}`, import.meta.url), 'utf8')
  const { descriptor } = parse(source)
  const compile = (ssr) => {
    const result = compileTemplate({ source: descriptor.template.content, filename, id: `stage-${stage}`, ssr, ssrCssVars: [] })
    assert.deepEqual(result.errors, [])
    return result.code.replace(/import \{([^}]+)\} from "([^"]+)"/g, (_, names, module) =>
      `const {${names.replace(/ as /g, ': ')}} = ${module === 'vue' ? (ssr ? 'vue' : 'clientVue') : 'serverRenderer'}`)
      .replace(/export function (ssrRender|render)/, 'function $1')
  }
  pages.set(stage, { script: descriptor.scriptSetup.content.replace(/^import .*$/gm, ''), ssr: compile(true), client: compile(false) })
}

function fixture(stage, data, refundId = '41', query = { stageId: '0' }, failure = null) {
  const page = pages.get(stage)
  const calls = []
  const navigations = []
  const testRoute = vue.reactive({ params: { id: '9', refundId }, query })
  const context = vm.createContext({
    ...vue, vue, clientVue: {
      ...vue,
      resolveComponent: name => name,
      withDirectives: (node, directives) => Object.assign(node, { testDirectives: directives }),
    },
    serverRenderer, formatRefundMethod, console: { log() {}, error() {} },
    onMounted() {}, useRoute: () => testRoute,
    useRouter: () => ({ back() {}, push: target => navigations.push(target) }), useToast: () => ({ showSuccess() {}, showError() {} }),
    setTimeout: callback => callback(),
    fetch: async (url, options) => {
      calls.push({ url, method: options.method })
      if (options.body) calls.at(-1).body = JSON.parse(options.body)
      return failure && url.endsWith(failure.path) ? { ok: false, status: failure.status, json: async () => ({ message: failure.message }) } : { ok: true, json: async () => ({ data }) }
    },
  })
  const names = [...page.script.matchAll(/^const (\w+) =/gm)].map(match => match[1])
  vm.runInContext(`${page.script}\nglobalThis.state = { ${names.join(', ')} };\n${page.ssr}\nglobalThis.ssr = ssrRender;`, context)
  vm.runInContext(`{ ${page.client}\nglobalThis.client = render; }`, context)
  return {
    state: context.state, calls, navigations, route: testRoute,
    load: () => stage === 1 ? context.state.loadExistingRefund() : context.state.loadRefundData(),
    // Observe the real compiled select's model directive and option VNodes.
    select: () => {
      const find = node => node?.type === 'select' ? node : Array.isArray(node?.children) ? node.children.map(find).find(Boolean) : undefined
      const node = find(context.client(vue.proxyRefs(context.state), []))
      const model = node.testDirectives[0][1]
      return { model, selected: node.children.find(option => option.props.value === model), node }
    },
    continueButton: () => {
      const find = node => node?.type === 'button' && node.props.type === 'button' && node.props.class?.includes('bg-blue-600')
        ? node : Array.isArray(node?.children) ? node.children.map(find).find(Boolean) : undefined
      return find(context.client(vue.proxyRefs(context.state), []))
    },
    render: () => serverRenderer.renderToString(vue.createSSRApp({
      setup: () => context.state, ssrRender: context.ssr,
      // Forward model and control props so custom fields are included in assertions.
      components: { FormField: { props: ['modelValue', 'disabled'], setup: props => () => vue.h('input', { value: props.modelValue, disabled: props.disabled }) } },
    })),
  }
}

const evidence = {
  refund_number: 'REF-A', refund_amount: '800.12', refund_method: 'bank_transfer',
  current_stage: 4, refund_status: 'completed', can_submit: true,
  request_number: 'REQ-A', request_date: '2026-10-01', transfer_date: '2026-10-02',
  instruction_number: 'INS-A', instruction_issue_date: '2026-10-03', instruction_received_date: '2026-10-04',
  bank_code: '001', bank_name: 'Bank A', account_number: '12345', account_holder: 'Holder A', account_name: 'Account A', transfer_amount: '800.12',
  receipt_number: 'RCPT-A', received_date: '2026-10-05', received_amount: '800.12', notes: 'Retained A',
}

function assertControls(html, disabled, stage) {
  const controls = html.match(/<(?:input|select|textarea)\b[^>]*>|<button\b[^>]*type="submit"[^>]*>/g) || []
  assert.equal(controls.length, { 2: 5, 3: 11, 4: 5 }[stage], 'all evidence fields and submit are covered')
  for (const control of controls) assert.equal(/\sdisabled(?:[\s=>])/.test(control), disabled, control)
}

for (const preliminary of [false, true]) {
  for (const currentStage of [1, 4]) {
    test(`Stage 1 canonical selection on load/reload: preliminary=${preliminary}, stage=${currentStage}`, async () => {
      const page = fixture(1, { ...evidence, is_preliminary: preliminary, current_stage: currentStage })
      for (let reload = 0; reload < 2; reload++) {
        await page.load()
        assert.equal(page.state.formData.value.refund_method, 'bank_transfer', 'GET scalar stays canonical')
        const { selected, node } = page.select()
        assert.equal(selected?.children, 'Bank Transfer')
        assert.equal(node.props.disabled, true)
      }
      assert.deepEqual(page.calls, Array.from({ length: 2 }, () => currentStage === 1 ? [1, 2] : [1]).flat().map(stage => ({ url: `/api/tax-cases/9/refunds/41/refund-stages/${stage}`, method: 'GET' })))
    })
  }
  for (const stage of [2, 3, 4]) {
    test(`Stage ${stage} completed evidence read-only on load/reload: preliminary=${preliminary}`, async () => {
      const page = fixture(stage, { ...evidence, is_preliminary: preliminary, can_submit: preliminary })
      for (let reload = 0; reload < 2; reload++) {
        await page.load()
        assert.equal(page.state.canSubmit.value, preliminary, 'advisory contract retained')
        const html = await page.render()
        assertControls(html, true, stage)
        for (const key of Object.keys(page.state.formData.value)) {
          assert.equal(page.state.formData.value[key], evidence[key], `retained ${key}`)
        }
        if (stage === 4) {
          assert.match(html, /Bank Transfer/)
          assert.match(html, preliminary ? /automatically closes the eligible TaxCase/ : /complete only the selected Refund/)
        }
      }
      assert.deepEqual(page.calls, Array(2).fill({ url: `/api/tax-cases/9/refunds/41/refund-stages/${stage}`, method: 'GET' }))
    })
    test(`Stage ${stage} eligible new evidence remains enabled: preliminary=${preliminary}`, async () => {
      for (const currentStage of [stage - 1, stage]) {
        const page = fixture(stage, { refund_amount: '800.12', is_preliminary: preliminary, current_stage: currentStage, refund_status: 'pending', can_submit: true })
        await page.load()
        if (stage === 4) page.state.formData.value.received_amount = '800.12'
        assertControls(await page.render(), false, stage)
        if (stage >= 3) assert.equal(/<textarea[^>]*\sreadonly/.test(await page.render()), !preliminary, 'existing Ordinary notes restriction retained')
        page.state.canSubmit.value = false
        assertControls(await page.render(), true, stage)
      }
    })
  }
}

test('Stage 1 missing/unknown values stay neutral and existing options keep their values', async () => {
  for (const method of [undefined, null, '', 'unrecognized', 'BANK_TRANSFER', 'CHEQUE', 'CASH']) {
    const page = fixture(1, { ...evidence, refund_method: method })
    await page.load()
    const { selected, model, node } = page.select()
    assert.equal(model, method || '')
    assert.equal(selected?.children, { BANK_TRANSFER: 'Bank Transfer', CHEQUE: 'Cheque', CASH: 'Cash' }[method] ?? (method ? undefined : '-- Select Method --'))
    node.props['onUpdate:modelValue']('CHEQUE')
    assert.equal(page.state.formData.value.refund_method, 'CHEQUE', 'selection keeps existing write value')
  }
})

test('selected A/B identities retain only their own method and evidence across all stages', async () => {
  for (const stage of [1, 2, 3, 4]) {
    const a = fixture(stage, evidence)
    const b = fixture(stage, { ...evidence, refund_number: 'REF-B', refund_method: 'CHEQUE', request_number: 'REQ-B', instruction_number: 'INS-B', receipt_number: 'RCPT-B', notes: 'Retained B' }, '42')
    for (let reload = 0; reload < 2; reload++) {
      await a.load()
      await b.load()
      assert.equal(a.state.formData.value.notes, 'Retained A')
      assert.equal(b.state.formData.value.notes, 'Retained B')
      if (stage === 1) {
        assert.equal(a.select().selected?.children, 'Bank Transfer')
        assert.equal(b.select().selected?.children, 'Cheque')
      } else {
        assert.doesNotMatch(await a.render(), /REF-B|REQ-B|INS-B|RCPT-B|Retained B/)
        assert.doesNotMatch(await b.render(), /REF-A|REQ-A|INS-A|RCPT-A|Retained A/)
      }
    }
    for (const [page, id] of [[a, '41'], [b, '42']]) assert.deepEqual(page.calls, Array(2).fill({ url: `/api/tax-cases/9/refunds/${id}/refund-stages/${stage}`, method: 'GET' }))
  }
})

for (const [id, origin] of [['61', 'SKP'], ['62', 'Objection']]) {
  for (const query of [{}, { stageId: '4' }, { stageId: '7' }, { stageId: 'junk' }, { stageId: ['0', '4'] }]) {
    test(`existing Stage 1 retains ${id}/${origin} with query ${JSON.stringify(query)}`, async () => {
      const page = fixture(1, { ...evidence, stage_label: origin, current_stage: 1, refund_amount: '800.12', can_submit: true }, id, query)
      for (let reload = 0; reload < 2; reload++) {
        await page.load()
        const html = await page.render()
        assert.doesNotMatch(html, /Create Refund \(Stage 1\)/)
        assert.match(html, new RegExp(`Origin: ${origin}`))
        await page.state.submitForm()
        const button = page.continueButton()
        assert.equal(button.props.disabled, false)
        button.props.onClick()
        assert.equal(page.navigations.at(-1).params.refundId, id)
        assert.equal(page.navigations.at(-1).params.id, '9')
        assert.equal(page.navigations.at(-1).name, 'RefundStage2FormWithId')
      }
      assert.equal(page.calls.length, 4)
      assert.ok(page.calls.every(call => call.method === 'GET' && call.url.startsWith(`/api/tax-cases/9/refunds/${id}/`)))
    })
  }
}

test('existing zero amount and backend denial remain blocked without a writer', async () => {
  const page = fixture(1, { ...evidence, is_preliminary: false, current_stage: 1, refund_amount: '0.00', can_submit: false })
  await page.load()
  assert.equal(page.state.formData.value.refund_amount, '0.00')
  assert.match(await page.render(), /blocked by backend eligibility/)
  assert.match(await page.render(), /positive authorized approved amount/)
  assert.match(await page.render(), /value="0.00" disabled/)
  assert.equal(page.continueButton().props.disabled, true)
  await page.state.submitForm()
  page.state.continueExistingRefund()
  assert.equal(page.navigations.length, 0)
  assert.ok(page.calls.every(call => call.method === 'GET'))
})

for (const status of [403, 404, 422]) {
  for (const path of ['/1', '/2']) {
    test(`existing GET ${path} HTTP ${status} cannot navigate or create`, async () => {
      const page = fixture(1, { ...evidence, current_stage: 1 }, '61', {}, { path, status, message: `Backend block ${status}` })
      await page.load()
      assert.equal(page.state.apiError.value, `Backend block ${status}`)
      page.state.continueExistingRefund()
      await page.state.submitForm()
      assert.equal(page.navigations.length, 0)
      assert.ok(page.calls.every(call => call.method === 'GET'))
    })
  }
}

for (const stageId of [undefined, '', 'junk', '4oops', 'NaN', '1', ['4']]) {
  test(`new creation rejects invalid origin ${JSON.stringify(stageId)} without a request`, async () => {
    const page = fixture(1, {}, null, { stageId })
    await page.state.submitForm()
    assert.match(page.state.apiError.value, /valid refund origin/)
    assert.equal(page.calls.length, 0)
  })
}

test('new creation preserves explicitly supported preliminary and decision origins', () => {
  for (const origin of ['0', '4', '7', '10', '12']) {
    assert.equal(fixture(1, {}, null, { stageId: origin }).state.stageId.value, Number(origin))
  }
})

test('same-component identity change immediately blocks old continuation and loads the new scoped ID', async () => {
  const page = fixture(1, { ...evidence, current_stage: 1, can_submit: true })
  await page.load()
  page.route.params.refundId = '62'
  page.state.continueExistingRefund()
  assert.equal(page.navigations.length, 0, 'old capability is cleared synchronously')
  await vue.nextTick()
  // Drain the in-memory case + Stage 1 + availability GET continuations.
  await new Promise(setImmediate)
  page.state.continueExistingRefund()
  assert.equal(page.navigations.at(-1).params.refundId, '62')
  assert.ok(page.calls.slice(2).every(call => call.method === 'GET' && !call.url.includes('/refunds/41/')))
})

test('supported genuinely new origins still submit their exact payload via session API', async () => {
  for (const origin of ['0', '4', '7', '10', '12']) {
    const page = fixture(1, { id: 81 }, null, { stageId: origin })
    page.state.formData.value = { refund_amount: '800.12', refund_method: 'BANK_TRANSFER', notes: 'Synthetic' }
    await page.state.submitForm()
    assert.equal(page.state.apiError.value, '')
    assert.equal(page.calls.length, 1)
    assert.equal(page.calls[0].method, 'POST')
    assert.equal(page.calls[0].url, '/api/tax-cases/9/refund-stages/1')
    assert.equal(page.calls[0].body.stage_id, Number(origin))
    assert.equal(page.navigations.at(-1).params.refundId, 81)
  }
})

test('existing card entry and router keep both synthetic origin identities', async () => {
  const source = await readFile(new URL('../resources/js/pages/TaxCaseDetail.vue', import.meta.url), 'utf8')
  const { descriptor } = parse(source)
  const script = descriptor.scriptSetup.content
  const start = script.indexOf('const navigateToRefundStage =')
  // Execute only the existing card navigation and current-stage functions.
  const block = script.slice(start, script.indexOf('const isRefundStageAccessible =', start))
  const navigations = []
  const context = vm.createContext({ console: { log() {}, error() {} }, caseData: vue.ref({ id: 9, current_stage: 12 }), router: { push: target => navigations.push(target) } })
  vm.runInContext(`${block}\nglobalThis.open = openRefund`, context)
  for (const refund of [{ id: 61, stage_id: 4 }, { id: 62, stage_id: 7 }]) {
    context.open({ ...refund, current_refund_stage: 1 })
    assert.equal(navigations.at(-1).params.refundId, refund.id)
    assert.equal(navigations.at(-1).params.id, 9)
    assert.equal(navigations.at(-1).name, 'RefundStage1FormWithId')
  }
  const router = await readFile(new URL('../resources/js/router/index.js', import.meta.url), 'utf8')
  assert.match(router, /path: '\/tax-cases\/:id\/refunds\/:refundId\/refund-stages\/1',\s*name: 'RefundStage1FormWithId'/)
})
