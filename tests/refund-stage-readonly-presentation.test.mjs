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

function fixture(stage, data, refundId = '41') {
  const page = pages.get(stage)
  const calls = []
  const context = vm.createContext({
    ...vue, vue, clientVue: {
      ...vue,
      resolveComponent: name => name,
      withDirectives: (node, directives) => Object.assign(node, { testDirectives: directives }),
    },
    serverRenderer, formatRefundMethod, console: { log() {}, error() {} },
    onMounted() {}, useRoute: () => ({ params: { id: '9', refundId }, query: { stageId: '0' } }),
    useRouter: () => ({ back() {} }), useToast: () => ({ showSuccess() {}, showError() {} }),
    fetch: async (url, options) => {
      calls.push({ url, method: options.method })
      return { ok: true, json: async () => ({ data }) }
    },
  })
  const names = [...page.script.matchAll(/^const (\w+) =/gm)].map(match => match[1])
  vm.runInContext(`${page.script}\nglobalThis.state = { ${names.join(', ')} };\n${page.ssr}\nglobalThis.ssr = ssrRender;`, context)
  vm.runInContext(`{ ${page.client}\nglobalThis.client = render; }`, context)
  return {
    state: context.state, calls,
    load: () => stage === 1 ? context.state.loadExistingRefund() : context.state.loadRefundData(),
    // Observe the real compiled select's model directive and option VNodes.
    select: () => {
      const find = node => node?.type === 'select' ? node : Array.isArray(node?.children) ? node.children.map(find).find(Boolean) : undefined
      const node = find(context.client(vue.proxyRefs(context.state), []))
      const model = node.testDirectives[0][1]
      return { model, selected: node.children.find(option => option.props.value === model), node }
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
        assert.equal(node.props.disabled, currentStage > 1)
      }
      assert.deepEqual(page.calls, Array(2).fill({ url: '/api/tax-cases/9/refunds/41/refund-stages/1', method: 'GET' }))
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
