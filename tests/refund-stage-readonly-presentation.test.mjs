// Actual page scripts/templates with in-memory GETs; no browser or database.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import * as vue from 'vue'
import * as serverRenderer from 'vue/server-renderer'
import { parse, compileTemplate } from '@vue/compiler-sfc'
import { formatRefundMethod, formatRefundAmount } from '../resources/js/utils/refundPresentation.js'

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

function fixture(stage, data, refundId = '41', query = { stageId: '0' }, failure = null, fetchOverride = null) {
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
    serverRenderer, formatRefundMethod, formatRefundAmount, console: { log() {}, error() {} },
    onMounted() {}, useRoute: () => testRoute,
    useRouter: () => ({ back() {}, push: target => navigations.push(target) }), useToast: () => ({ showSuccess() {}, showError() {} }),
    setTimeout: callback => callback(),
    fetch: async (url, options) => {
      calls.push({ url, method: options.method })
      if (options.body) calls.at(-1).body = JSON.parse(options.body)
      if (fetchOverride) return fetchOverride(url, options)
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
    buttons: () => {
      const collect = node => [ ...(node?.type === 'button' ? [node] : []), ...(Array.isArray(node?.children) ? node.children.flatMap(collect) : []) ]
      return collect(context.client(vue.proxyRefs(context.state), []))
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
      // Stages 2/3 current_stage describes recorded completion, not the next editable form.
      for (const currentStage of stage === 4 ? [stage - 1, stage] : [stage - 1]) {
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

test('new Stage 4 Refund deep link uses refreshed KIAN gate without affecting other origins', async () => {
  const data = { stage4_kian_access_blocked: true }
  const page = fixture(1, data, null, { stageId: '4' })
  await page.state.loadCaseData()
  assert.equal(page.state.newRefundBlocked.value, true)
  await page.state.submitForm()
  assert.match(page.state.apiError.value, /required Stage 4 KIAN/)
  assert.ok(page.calls.every(call => call.method === 'GET'))
  data.stage4_kian_access_blocked = false
  await page.state.loadCaseData()
  assert.equal(page.state.newRefundBlocked.value, false)
  page.state.stageFourKianBlocked.value = true
  page.route.query.stageId = '7'
  assert.equal(page.state.newRefundBlocked.value, false)
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

// Corrective E: compiled handlers and in-memory responses; no browser or real API.
const buttonText = node => typeof node.children === 'string' ? node.children.trim() : ''
const forwardButton = page => page.buttons().find(node => /^Continue to Stage/.test(buttonText(node)))
const clickBack = page => page.buttons().find(node => node.props.type === 'button' && buttonText(node) === 'Back').props.onClick()
const assertTarget = (page, stage, refundId = '41', caseId = '9') => {
  assert.equal(page.navigations.at(-1).name, `RefundStage${stage}FormWithId`)
  assert.equal(page.navigations.at(-1).params.id, caseId)
  assert.equal(page.navigations.at(-1).params.refundId, refundId)
}

for (const stage of [2, 3]) {
  test(`Stage ${stage} submit -> next -> deterministic Back -> Continue uses one POST only`, async () => {
    const data = { ...evidence, current_stage: stage - 1, refund_status: 'pending', can_submit: true }
    const page = fixture(stage, data)
    assert.equal(forwardButton(page), undefined, 'hidden before GET')
    await page.load()
    assert.equal(forwardButton(page), undefined, 'predecessor not complete')
    await page.state.submitForm()
    assertTarget(page, stage + 1)
    assert.equal(page.calls.filter(call => call.method === 'POST').length, 1)
    data.current_stage = stage
    data.can_submit = false
    const next = fixture(stage + 1, data)
    await next.load()
    clickBack(next)
    assertTarget(next, stage)
    await page.load()
    assertControls(await page.render(), true, stage)
    assert.equal(page.state.formData.value[stage === 2 ? 'request_number' : 'instruction_number'], stage === 2 ? 'REQ-A' : 'INS-A')
    const before = page.calls.length
    forwardButton(page).props.onClick()
    assertTarget(page, stage + 1)
    assert.equal(page.calls.length, before, 'Continue is pure navigation')
    assert.equal(page.calls.filter(call => call.method === 'POST').length, 1)
  })

  test(`Stage ${stage} continuation across origins, currencies, Preliminary and reloads`, async () => {
    for (const origin of [0, 4, 7, 10, 12]) for (const currency of ['USD', 'IDR']) {
      const page = fixture(stage, { ...evidence, current_stage: stage, can_submit: origin === 0, is_preliminary: origin === 0, stage_id: origin, currency_code: currency })
      for (let reload = 0; reload < 2; reload++) {
        await page.load()
        assertControls(await page.render(), true, stage)
        forwardButton(page).props.onClick()
        assertTarget(page, stage + 1)
        assert.match(await page.render(), origin === 0 ? /Rp/ : new RegExp(currency))
      }
      assert.equal(page.calls.length, 2)
      assert.ok(page.calls.every(call => call.method === 'GET'))
    }
  })

  test(`Stage ${stage} incomplete or invalid progression ignores query and write flags`, async () => {
    for (const current of [undefined, null, 0, 1, ...(stage === 3 ? [2] : []), 2.5, 3.5, 5, 'junk']) {
      for (const canSubmit of [false, true]) {
        const page = fixture(stage, { ...evidence, current_stage: current, can_submit: canSubmit }, '41', { stageId: '12', current_stage: '4' })
        await page.load()
        assert.equal(forwardButton(page), undefined)
        page.state.continueToNextStage()
        assert.equal(page.navigations.length, 0)
        assert.ok(page.calls.every(call => call.method === 'GET'))
      }
    }
  })

  test(`Stage ${stage} denied, unknown, foreign or missing ID cannot continue`, async () => {
    for (const status of [403, 404, 422]) {
      const page = fixture(stage, evidence, '41', {}, { path: `/${stage}`, status, message: 'Denied' })
      await page.load()
      assert.equal(forwardButton(page), undefined)
      page.state.continueToNextStage()
      assert.equal(page.navigations.length, 0)
      assert.doesNotMatch(await page.render(), /REQ-A|INS-A|Retained A/)
    }
    const page = fixture(stage, evidence, null)
    await page.load()
    page.state.continueToNextStage()
    page.state.goBack()
    assert.equal(page.calls.length, 0)
    assert.equal(page.navigations.length, 0)
  })
}

for (const stage of [2, 3, 4]) {
  test(`Stage ${stage} loaded A is cleared synchronously on case/Refund change`, async () => {
    const pending = []
    let first = true
    const page = fixture(stage, {}, '41', {}, null, async () => {
      if (first) { first = false; return { ok: true, json: async () => ({ data: evidence }) } }
      return new Promise(resolve => { pending.push(resolve) })
    })
    await page.load()
    assert.equal(page.state.formData.value.notes, 'Retained A')
    page.route.params.id = '10'
    page.route.params.refundId = '42'
    assert.equal(page.state.formData.value.notes, '')
    assert.equal(page.state.refundNumber.value, '')
    assert.equal(page.state.isLoading.value, true)
    assert.equal(page.state.canSubmit.value, false)
    if (stage < 4) {
      assert.equal(forwardButton(page), undefined)
      page.state.continueToNextStage()
      assert.equal(page.navigations.length, 0)
    }
    pending[1]({ ok: true, json: async () => ({ data: { ...evidence, notes: 'Only B' } }) })
    await new Promise(setImmediate)
    pending[0]({ ok: true, json: async () => ({ data: evidence }) })
    await new Promise(setImmediate)
    assert.equal(page.state.formData.value.notes, 'Only B')
    clickBack(page)
    assertTarget(page, stage - 1, '42', '10')
    assert.equal(page.calls.at(-1).url, `/api/tax-cases/10/refunds/42/refund-stages/${stage}`)
  })

  test(`Stage ${stage} ID change clears evidence and ignores late GET success or error`, async () => {
    for (const staleError of [false, true]) {
      const pending = []
      const page = fixture(stage, {}, '41', {}, null, url => new Promise(resolve => pending.push({ url, resolve })))
      const oldLoad = page.load()
      page.route.params.refundId = '42'
      assert.equal(page.state.formData.value.notes, '')
      if (stage < 4) {
        page.state.continueToNextStage()
        assert.equal(forwardButton(page), undefined)
      }
      assert.equal(page.navigations.length, 0)
      pending[1].resolve({ ok: true, json: async () => ({ data: { ...evidence, notes: 'Only B', request_number: 'REQ-B', instruction_number: 'INS-B', receipt_number: 'RCPT-B' } }) })
      await new Promise(setImmediate)
      pending[0].resolve(staleError ? { ok: false, status: 403, json: async () => ({ message: 'Stale denial' }) } : { ok: true, json: async () => ({ data: evidence }) })
      await oldLoad
      assert.equal(page.state.formData.value.notes, 'Only B')
      assert.equal(page.state.apiError.value, '')
      assert.equal(page.state.isLoading.value, false)
      clickBack(page)
      assertTarget(page, stage - 1, '42')
      if (stage < 4) {
        forwardButton(page).props.onClick()
        assertTarget(page, stage + 1, '42')
      } else assert.doesNotMatch(await page.render(), /Continue to Stage|Stage 5/)
      assert.ok(page.calls.every(call => call.method === 'GET'))
    }
  })

  test(`Stage ${stage} reload failure revokes capability; Back ignores browser history`, async () => {
    let denied = false
    const page = fixture(stage, {}, '41', {}, null, async () => denied ? { ok: false, status: 404, json: async () => ({ message: 'Gone' }) } : { ok: true, json: async () => ({ data: evidence }) })
    await page.load()
    denied = true
    await page.load()
    if (stage < 4) {
      assert.equal(forwardButton(page), undefined)
      page.state.continueToNextStage()
      assert.equal(page.navigations.length, 0)
    }
    clickBack(page)
    assertTarget(page, stage - 1)
    page.buttons().find(node => buttonText(node) === 'Back to TaxCase Detail').props.onClick()
    assert.equal(page.navigations.at(-1).name, 'TaxCaseDetail')
    assert.equal(page.navigations.at(-1).params.id, '9')
    assert.ok(page.calls.every(call => call.method === 'GET'))
  })
}

test('selected direct-refresh routes remain explicit for Stages 2–4', async () => {
  const source = await readFile(new URL('../resources/js/router/index.js', import.meta.url), 'utf8')
  for (const stage of [2, 3, 4]) {
    assert.match(source, new RegExp(`path: '/tax-cases/:id/refunds/:refundId/refund-stages/${stage}',\\s*name: 'RefundStage${stage}FormWithId'`))
  }
})
