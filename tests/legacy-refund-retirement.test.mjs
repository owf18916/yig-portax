// Actual router records/guard and compiled Vue page; no HTTP, browser, or database.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import * as vue from 'vue'
import { createRouter, createMemoryHistory, RouterLink } from 'vue-router'
import { compileScript, parse } from '@vue/compiler-sfc'
import { renderToString } from 'vue/server-renderer'
import { component } from './support/vue-presentation-fixture.mjs'
import { safeReturnPath } from '../resources/js/auth/session.js'

const source = async path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')
const routerSource = await source('resources/js/router/index.js')
function routerFixture(status = 'authenticated') {
  let verifications = 0
  const context = vm.createContext({
    createRouter, createWebHistory: createMemoryHistory, safeReturnPath,
    // Preserve lazy import targets without loading unrelated pages or accessing a host.
    defineAsyncComponent: loader => ({ target: loader.toString().match(/import\('([^']+)'\)/)[1], render: () => null }),
    useCurrentUser: () => ({ currentUser: { value: { role: { permissions: [] } } }, verifySession: async () => { verifications++; return status } }),
  })
  vm.runInContext(routerSource.replace(/^import .*$/gm, '').replace('export default router', 'globalThis.router = router'), context)
  return { router: context.router, verifications: () => verifications }
}

const { descriptor } = parse(await source('resources/js/pages/LegacyRefundUnsupported.vue'))
const compiled = compileScript(descriptor, { id: 'legacy-refund', inlineTemplate: true })
const pageCode = compiled.content.replace(/^import .* from ['"]vue['"];?$/gm, line =>
  line.replace(/^import /, 'const ').replace(/ as /g, ': ').replace(/ from ['"]vue['"];?$/, ' = vue'))
  .replace(/^import .* from ['"]vue-router['"];?$/gm, 'const { useRoute } = routerApi')
  .replace('export default', 'globalThis.page =')

function pageFixture(route) {
  const calls = []
  const context = vm.createContext({ vue, routerApi: { useRoute: () => route },
    fetch: (...args) => { calls.push(args); throw new Error('Retired page must not request data') },
  })
  vm.runInContext(pageCode, context)
  return { page: context.page, calls }
}

for (const stage of [13, 14, 15]) {
  test(`legacy ${stage}: direct entry, same-session navigation, and fresh-router refresh`, async () => {
    const path = `/tax-cases/9/workflow/${stage}`
    for (const fresh of [true, false, true]) {
      const { router, verifications } = routerFixture()
      if (!fresh) await router.push('/tax-cases/9/refunds/42/refund-stages/3')
      await router.push(`${path}?refundId=41`)
      assert.equal(router.currentRoute.value.matched[0].components.default.target, '../pages/LegacyRefundUnsupported.vue')
      assert.equal(router.currentRoute.value.meta.requiresAuth, true)
      assert.equal(router.currentRoute.value.params.refundId, undefined)
      assert.equal(verifications(), fresh ? 1 : 2)
    }
  })
  test(`legacy ${stage}: guest retains login and safe return path`, async () => {
    const { router } = routerFixture('unauthenticated')
    const path = `/tax-cases/9/workflow/${stage}`
    await router.push(path)
    assert.equal(router.currentRoute.value.path, '/login')
    assert.equal(router.currentRoute.value.query.returnTo, path)
  })
}

test('all case identities and Refund counts receive the same static safe destination', async () => {
  let expected
  for (const identity of ['authorized-two-refunds', 'authorized-no-refund', 'cross-entity', 'denied', 'stale', 'nonexistent']) {
    const route = vue.reactive({ fullPath: `/tax-cases/${identity}/workflow/13`, params: { id: identity }, query: { refundId: '42' } })
    const fixture = pageFixture(route)
    const { router } = routerFixture()
    await router.push(route.fullPath)
    const app = vue.createSSRApp(fixture.page).use(router)
    app.component('router-link', RouterLink)
    const html = await renderToString(app)
    assert.match(html, /This legacy Refund page is no longer supported/)
    assert.match(html, /href="\/tax-cases"/)
    assert.doesNotMatch(html, /<(?:form|input|button|textarea|select)\b|Save|Submit|Upload|refundId|authorized-two-refunds|cross-entity|nonexistent/)
    assert.match(html, /aria-labelledby="legacy-refund-title"/)
    assert.match(html, /tabindex="-1"/)
    assert.match(html, /max-w-2xl.*p-4.*sm:p-6/)
    assert.match(html, /focus-visible:outline/)
    if (expected) assert.equal(html, expected)
    expected = html
    assert.deepEqual(fixture.calls, [])
  }
})

test('heading receives focus on mount and navigation; safe link stays keyboard accessible', async () => {
  const route = vue.reactive({ fullPath: '/tax-cases/9/workflow/13' })
  const fixture = pageFixture(route)
  let focuses = 0
  const node = type => ({ type, children: [], props: {}, focus() { assert.equal(type, 'h1'); focuses++ } })
  const renderer = vue.createRenderer({
    createElement: node, createText: () => node('text'), createComment: () => node('comment'),
    setText() {}, setElementText() {}, parentNode: () => null, nextSibling: () => null,
    patchProp: (el, key, _old, value) => { el.props[key] = value },
    insert: (el, parent) => parent.children.push(el), remove() {},
  })
  const app = renderer.createApp(fixture.page)
  app.component('router-link', { props: ['to'], setup: (props, { slots }) => () => vue.h('a', { href: props.to }, slots.default()) })
  const root = node('root')
  app.mount(root)
  assert.equal(focuses, 1)
  route.fullPath = '/tax-cases/other/workflow/14'
  await vue.nextTick()
  route.fullPath = '/tax-cases/missing/workflow/15'
  await vue.nextTick()
  assert.equal(focuses, 3)
  const link = root.children[0].children.find(el => el.type === 'a')
  assert.equal(link.props.href, '/tax-cases')
  assert.equal(link.props.tabindex, undefined, 'native link remains in tab order')
  assert.deepEqual(fixture.calls, [])
  app.unmount()
})

test('generic workflow fallback maps retired numeric variants to unsupported without loading data', async () => {
  for (const stageId of ['13', '14', '15', '013', '014', '015']) {
    const mounts = []
    const calls = []
    const page = await component('pages/WorkflowForm.vue', {
      onMounted: fn => mounts.push(fn),
      useRoute: () => ({ params: { id: 'private-case', stageId } }),
      defineAsyncComponent: loader => ({ target: loader.toString() }),
      fetch: (...args) => { calls.push(args); throw new Error('Unexpected case request') },
    })
    for (const mount of mounts) await mount()
    assert.match(page.state.specificFormComponent.value.target, /LegacyRefundUnsupported.vue/)
    assert.deepEqual(calls, [])
  }
})

test('canonical routes keep explicit A/B selection and main 1–12/KIAN 16 keep their pages', async () => {
  const { router } = routerFixture()
  for (const refund of ['41', '42']) for (const stage of [1, 2, 3, 4]) {
    const resolved = router.resolve(`/tax-cases/9/refunds/${refund}/refund-stages/${stage}`)
    assert.equal(resolved.params.refundId, refund)
    assert.equal(resolved.matched[0].components.default.target, `../pages/RefundStage${stage}Form.vue`)
  }
  for (const stage of [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 16]) {
    assert.notEqual(router.resolve(`/tax-cases/9/workflow/${stage}`).matched[0].components.default.target, '../pages/LegacyRefundUnsupported.vue')
  }
  const detail = await source('resources/js/pages/TaxCaseDetail.vue')
  assert.match(await source('resources/js/components/DecisionActions.vue'), /Refund amounts are set in the dedicated Refund stages \(1-4\)/)
  assert.doesNotMatch(detail, /Legacy Stages|getStagesByBranch\('refund'\)|branch: 'refund'|refunds\/\$\{refund.id\}\/workflow/)
  assert.match(detail, /if \(!refundId\)/)
  const pushes = []
  const page = await component('pages/TaxCaseDetail.vue', {
    useRouter: () => ({ push: to => pushes.push(to) }),
    useTaxCaseStore: () => ({}), useToast: () => ({ showSuccess() {}, showError() {} }),
  })
  page.state.caseData.value = { id: 9, refund_processes: [{ id: 41 }, { id: 42 }] }
  page.state.navigateToRefundStage(2)
  assert.equal(pushes.length, 0)
  page.state.navigateToRefundStage(2, 41)
  page.state.navigateToRefundStage(3, 42)
  assert.equal(pushes[0].params.refundId, 41)
  assert.equal(pushes[1].params.refundId, 42)
  assert.equal(pushes[0].name, 'RefundStage2FormWithId')
  assert.equal(pushes[1].name, 'RefundStage3FormWithId')
})

test('direct HTTP refresh still returns the SPA shell (source proof)', async () => {
  assert.match(await source('routes/web.php'), /Route::get\('\/\{any\}'[\s\S]*return view\('app'\)[\s\S]*where\('any', '\.\*'\)/)
})
