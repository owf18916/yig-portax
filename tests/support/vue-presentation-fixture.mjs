// Component logic and SSR only: no browser, host, database, or real API.
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import * as vue from 'vue'
import * as serverRenderer from 'vue/server-renderer'
import { parse, compileTemplate } from '@vue/compiler-sfc'

export async function component(path, globals = {}) {
  const { descriptor } = parse(await readFile(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8'))
  const compiled = compileTemplate({ source: descriptor.template.content, filename: path, id: 'preliminary-test', ssr: true, ssrCssVars: [] })
  assert.deepEqual(compiled.errors, [])
  const code = compiled.code.replace(/import \{([^}]+)\} from "([^"]+)"/g, (_, names, module) =>
    `const {${names.replace(/ as /g, ': ')}} = ${module === 'vue' ? 'vue' : 'serverRenderer'}`)
    .replace('export function ssrRender', 'function ssrRender')
  const script = descriptor.scriptSetup.content.replace(/^import .*$/gm, '')
  const client = compileTemplate({ source: descriptor.template.content, filename: path, id: 'presentation-client' })
  assert.deepEqual(client.errors, [])
  const clientCode = client.code.replace(/import \{([^}]+)\} from "vue"/g, (_, names) =>
    `const {${names.replace(/ as /g, ': ')}} = clientVue`).replace('export function render', 'function clientRenderFn')
  const names = [...script.matchAll(/^(?:const|function|async function) (\w+)/gm)].map(m => m[1])
  const context = vm.createContext({ ...vue, vue, serverRenderer, FormData, clientVue: { ...vue, resolveComponent: name => name, withDirectives: node => node }, console: { log() {}, warn() {}, error() {} },
    onMounted() {}, onUnmounted() {}, watch() {}, defineEmits: () => () => {}, defineProps: () => ({}),
    useRoute: () => ({ params: { id: '9' } }), useRouter: () => ({ push() {} }),
    useRevisionAPI: () => ({ listRevisions: async () => [] }), session: {}, ...globals })
  vm.runInContext(`${script}\nglobalThis.state = { ${names.join(',')} };\n${code}\nglobalThis.render = ssrRender`, context)
  vm.runInContext(`{ ${clientCode}\nglobalThis.clientRender = clientRenderFn }`, context)
  const shell = { props: ['title', 'subtitle', 'message'], setup: (p, { slots }) => () => vue.h('div', [p.title, p.subtitle, p.message, slots.default?.()]) }
  return { state: context.state, client: () => context.clientRender(vue.proxyRefs(context.state), []), render: async (bindings = {}) => serverRenderer.renderToString(vue.createSSRApp({
    setup: () => ({ ...context.state.props, ...context.state, ...bindings }), ssrRender: context.render,
    components: Object.fromEntries(['Button', 'Card', 'Alert', 'Toast', 'ConfirmationDialog', 'LoadingSpinner', 'FormField', 'StageForm', 'RevisionHistoryPanel', 'SptFilingMethod', 'DecisionActions'].map(n => [n, shell])),
  })) }
}
export const eligibleCase = { id: 9, case_type: 'VAT', spt_position_type: 'LB', spt_type: 'Pengembalian Pendahuluan',
  preliminary_can_decide: true, workflow_state: { completed_stages: [1] }, currency: { code: 'IDR' } }

