import { test } from 'node:test'
import assert from 'node:assert/strict'
import { component } from './support/vue-presentation-fixture.mjs'
import { taxCaseListParams } from '../resources/js/composables/taxCaseListFilters.js'
import { watch } from 'vue'

function response(numbers = [], page = 1) {
  return { ok: true, json: async () => ({ data: { data: numbers.map(id => ({ id })),
    current_page: page, last_page: 2, total: 21,
    filter_options: { stages: [1, 4], periods: ['2026-03'], fiscal_years: [2026] } } }) }
}

async function fixture(fetch, globals = {}) {
  const downloads = []
  const page = await component('pages/TaxCaseList.vue', {
    workflowStageLabels: {}, URLSearchParams, taxCaseListParams, fetch,
    window: { URL: { createObjectURL: () => { downloads.push('create'); return 'blob:synthetic' },
      revokeObjectURL: () => downloads.push('revoke') } },
    document: { createElement: () => ({ setAttribute: (key, value) => downloads.push([key, value]), click: () => downloads.push('click') }),
      body: { appendChild: () => downloads.push('append'), removeChild: () => downloads.push('remove') } },
    alert: () => assert.fail('Unexpected alert'),
    ...globals,
  })
  return { ...page, downloads }
}

test('actual list and export request the same nine filters; export omits page; download sequence retained', async () => {
  const calls = []
  const page = await fixture(async (url, options) => {
    calls.push({ url, options })
    if (url.startsWith('/api/tax-cases/export?')) return { ok: true, blob: async () => ({}), headers: { get: () => 'attachment; filename="synthetic.xlsx"' } }
    return response([7, 8], 2)
  })
  const s = page.state
  for (const [key, value] of Object.entries({ searchQuery: 'Alpha', filterCaseNumber: 'Case_%', filterType: 'CIT',
    filterEntity: 4, filterStatus: 'open', filterCurrentStage: 4, filterStageStatus: 'submitted',
    filterPeriod: '2026-03', filterFiscalYear: 2026 })) s[key].value = value
  await s.fetchTaxCases(2)
  assert.equal(s.currentPage.value, 2)
  assert.equal(s.totalCases.value, 21)
  assert.deepEqual(Array.from(s.filteredCases.value, x => x.id), [7, 8])
  await s.exportToExcel()
  const list = new URL(calls[0].url, 'https://synthetic.invalid').searchParams
  const exported = new URL(calls[1].url, 'https://synthetic.invalid').searchParams
  assert.equal(list.get('page'), '2')
  list.delete('page')
  assert.equal(list.toString(), exported.toString())
  assert.equal([...exported].length, 9)
  assert.equal(exported.get('period_code'), '2026-03')
  assert.equal(exported.has('period_id'), false)
  assert.equal(exported.get('fiscal_year'), '2026')
  assert.ok(calls.every(call => call.options.credentials === 'include'))
  assert.deepEqual(page.downloads, ['create', ['download', 'synthetic.xlsx'], 'append', 'click', 'remove', 'revoke'])
})

test('stale list response cannot replace the latest filter result, options, page, or loading state', async () => {
  const pending = []
  const { state: s } = await fixture(() => new Promise(resolve => pending.push(resolve)))
  const first = s.fetchTaxCases(2)
  s.filterCaseNumber.value = 'new'
  const second = s.fetchTaxCases(1)
  pending[1](response([99], 1))
  await second
  pending[0](response([11], 2))
  await first
  assert.deepEqual(Array.from(s.taxCases.value, x => x.id), [99])
  assert.equal(s.currentPage.value, 1)
  assert.equal(s.loading.value, false)
})

test('failed or pending list prevents export against unseen results', async () => {
  let calls = 0
  const { state: s, downloads } = await fixture(async () => { calls++; return { ok: false } })
  await s.exportToExcel()
  assert.equal(calls, 0)
  await s.fetchTaxCases()
  assert.equal(s.loadError.value, true)
  await s.exportToExcel()
  assert.equal(calls, 1)
  assert.deepEqual(downloads, [])
})

test('reactive filter change requests page one and uses server results without local filtering', async () => {
  const calls = []
  let stop
  const { state: s } = await fixture(async url => {
    calls.push(url)
    return response([99], Number(new URL(url, 'https://synthetic.invalid').searchParams.get('page')))
  }, { watch: (...args) => { stop = watch(...args) } })
  try {
    await s.fetchTaxCases(2)
    s.filterFiscalYear.value = 2026
    assert.equal(s.loading.value, true)
    await new Promise(setImmediate)
    assert.equal(calls.length, 2)
    const params = new URL(calls[1], 'https://synthetic.invalid').searchParams
    assert.equal(params.get('page'), '1')
    assert.equal(params.get('fiscal_year'), '2026')
    assert.equal(s.currentPage.value, 1)
    assert.deepEqual(Array.from(s.filteredCases.value, item => item.id), [99])
  } finally { stop() }
})
