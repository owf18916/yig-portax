// Compiled Vue logic and SSR with synthetic API responses; no browser or real API.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { component } from './support/vue-presentation-fixture.mjs'

async function fixture(capability, status = 'NO_VALID_TO', confirm = () => true) {
  const calls = []
  let record = { id: 7, tax_case_id: 9, can_retry: capability, current_status: status, attempts: [{ id: 1, status, to_recipients: [], error_message: 'smtp-secret' }] }
  const page = await component('pages/NotificationLogs.vue', {
    confirm, useRoute: () => ({ query: {} }),
    URLSearchParams,
    fetch: async (url, options = {}) => {
      calls.push({ url, ...options })
      return { ok: true, json: async () => url.includes('?') ? { data: [record] } : record }
    },
  })
  await page.state.open(7)
  return { ...page, calls, setRecord(value) { record = value } }
}

test('missing, malformed, revoked and unsafe-state capabilities fail closed', async () => {
  for (const cap of [undefined, null, false, 0, 1, 'true', {}, []]) {
    const page = await fixture(cap)
    assert.doesNotMatch(await page.render(), />Retry<\/button>/)
    await page.state.retry()
    assert.equal(page.calls.filter(c => c.method === 'POST').length, 0)
  }
  for (const status of ['FAILED','SKIPPED_NO_RECIPIENT','PROCESSING','QUEUED','SENT','DELIVERY_UNCERTAIN','SUPPRESSED']) {
    const page = await fixture(true, status)
    assert.doesNotMatch(await page.render(), />Retry<\/button>/)
    await page.state.retry()
    assert.equal(page.calls.filter(c => c.method === 'POST').length, 0)
  }
  const page = await fixture(true)
  assert.match(await page.render(), />Retry<\/button>/)
  page.setRecord({ id: 7, current_status: 'NO_VALID_TO', can_retry: false })
  await page.state.open(7)
  assert.doesNotMatch(await page.render(), />Retry<\/button>/)
})

test('confirmation cancel is side-effect free; confirmed retry includes case identity and reloads grants', async () => {
  const cancel = await fixture(true, 'PRE_SEND_FAILED', () => false)
  await cancel.state.retry()
  assert.equal(cancel.calls.filter(c => c.method === 'POST').length, 0)
  const page = await fixture(true)
  page.setRecord({ id: 7, tax_case_id: 9, current_status: 'QUEUED', can_retry: false })
  await page.state.retry()
  const writes = page.calls.filter(c => c.method === 'POST')
  assert.equal(writes.length, 1)
  assert.equal(writes[0].body, JSON.stringify({ tax_case_id: 9 }))
  assert.equal(page.state.detail.value.can_retry, false)
  assert.equal(page.state.retrying.value, false)
})

test('uncertainty explains investigation, hides raw errors and never offers retry', async () => {
  const page = await fixture(false, 'DELIVERY_UNCERTAIN')
  const html = await page.render()
  assert.match(html, /Investigation is required/)
  assert.doesNotMatch(html, /smtp-secret|>Retry<\/button>/)
})
