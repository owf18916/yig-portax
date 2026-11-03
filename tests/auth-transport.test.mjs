import { test } from 'node:test'
import assert from 'node:assert/strict'
import axios from 'axios'
import { createSessionOwner } from '../resources/js/auth/session.js'
import { createTransport } from '../resources/js/auth/transport.js'
// Browser cookie/origin fixture; no HTTP or browser claims from this adapter harness.
globalThis.document = { cookie: 'XSRF-TOKEN=fixture-cookie' }
globalThis.location = { origin: 'http://localhost' }
const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } })
const owner = () => {
  const value = createSessionOwner({ probe: async () => json({}, 401) })
  value.accept({ id: 1 }, false)
  return value
}
test('fetch preserves 403, 404, 422 and their caller-owned bodies', async () => {
  const session = owner()
  for (const status of [403, 404, 422, 500]) {
    const api = createTransport(session, async () => json({ message: 'caller message', errors: { period_id: ['invalid'] } }, status))
    const response = await api.apiFetch('/api/test')
    assert.equal(response.status, status)
    assert.equal((await response.json()).message, 'caller message')
    assert.equal(session.state.status, 'authenticated')
  }
})
test('20 simultaneous protected fetch 401s cause one invalidation and no replay', async () => {
  const session = owner()
  let resets = 0, requests = 0
  session.onReset(() => resets++)
  const { apiFetch } = createTransport(session, async () => { requests++; return json({}, 401) })
  const results = await Promise.allSettled(Array.from({ length: 20 }, () => apiFetch('/api/case', { method: 'POST' })))
  assert.equal(results.filter(result => result.status === 'rejected').length, 20)
  assert.equal(resets, 1)
  assert.equal(requests, 20)
  assert.match(session.state.notice, /Session expired/)
})
test('fetch checks JSON headers, preserves multipart body and replaces competing CSRF headers', async () => {
  const session = owner(), body = new FormData()
  const { apiFetch } = createTransport(session, async (url, options) => {
    assert.equal(options.headers.get('Accept'), 'application/json')
    assert.equal(options.headers.get('X-CSRF-TOKEN'), null)
    assert.equal(options.headers.get('X-XSRF-TOKEN'), 'fixture-cookie')
    assert.equal(options.headers.has('Content-Type'), false)
    assert.equal(options.body, body)
    return json({ ok: true })
  })
  assert.deepEqual(await (await apiFetch('/api/documents', { method: 'POST', body, headers: { 'X-CSRF-TOKEN': 'fixture' } })).json(), { ok: true })
})
test('HTML, redirected login and wrong binary MIME are never accepted as downloads', async () => {
  const session = owner()
  const redirected = json({})
  Object.defineProperty(redirected, 'redirected', { value: true })
  for (const response of [new Response('<html>Login</html>', { headers: { 'Content-Type': 'text/html' } }), redirected]) {
    await assert.rejects(createTransport(session, async () => response).apiFetch('/api/export'), /redirect|HTML/)
  }
  const response = await createTransport(session, async () => json({})).apiFetch('/api/export')
  await assert.rejects(response.blob(), /document type/)
  const plain = await createTransport(session, async () => new Response('not JSON')).apiFetch('/api/test')
  await assert.rejects(plain.json(), /Expected JSON/)
})
test('authorized PDF and XLSX bytes, MIME and disposition remain intact', async () => {
  for (const mime of ['application/pdf', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']) {
    const { apiFetch } = createTransport(owner(), async () => new Response('fixture-bytes', { headers: { 'Content-Type': mime, 'Content-Disposition': 'attachment; filename="fixture.pdf"' } }))
    const response = await apiFetch('/api/download')
    assert.match(response.headers.get('Content-Disposition'), /fixture.pdf/)
    const blob = await response.blob()
    assert.equal(blob.type, mime)
    assert.equal(await blob.text(), 'fixture-bytes')
  }
})
test('stale headers and stale body reads cannot publish account A data', async () => {
  const session = owner()
  let finish
  const pending = new Promise(resolve => { finish = resolve })
  const { apiFetch } = createTransport(session, () => pending)
  const oldRead = apiFetch('/api/case')
  await new Promise(resolve => setImmediate(resolve))
  session.accept({ id: 2 }, false)
  finish(json({ secret: 'fixture' }))
  await assert.rejects(oldRead, /previous account response/)
  const bodyResponse = json({})
  let finishBody
  bodyResponse.json = () => new Promise(resolve => { finishBody = resolve })
  const response = await createTransport(session, async () => bodyResponse).apiFetch('/api/case')
  const body = response.json()
  session.accept({ id: 3 }, false)
  finishBody({ secret: 'fixture' })
  await assert.rejects(body, /previous account response/)
})
test('stale successful writes retain completion evidence, no automatic replay', async () => {
  const session = owner()
  let finish, requests = 0
  const pending = new Promise(resolve => { finish = resolve })
  const { apiFetch } = createTransport(session, () => { requests++; return pending })
  const write = apiFetch('/api/case', { method: 'POST' })
  await new Promise(resolve => setImmediate(resolve))
  session.accept({ id: 2 }, false)
  finish(json({}, 201))
  await assert.rejects(write, /HTTP 201.*reported completion/)
  assert.equal(requests, 1)
  assert.match(session.state.notice, /reported completion/)
})
test('419 is recoverable, distinct from 403, and never retries a write', async () => {
  const session = owner()
  let requests = 0
  const { apiFetch } = createTransport(session, async () => { requests++; return json({}, 419) })
  await assert.rejects(apiFetch('/api/case', { method: 'POST' }), /Session\/token error/)
  assert.equal(requests, 1)
  assert.equal(session.state.status, 'authenticated')
})
test('network write failure warns of uncertainty without marking logged out', async () => {
  const session = owner()
  const { apiFetch } = createTransport(session, async () => { throw Error('offline') })
  await assert.rejects(apiFetch('/api/case', { method: 'POST' }), /offline/)
  assert.equal(session.state.status, 'authenticated')
  assert.match(session.state.notice, /unconfirmed/)
})
test('locked and unavailable sessions cannot send protected requests', async () => {
  const session = owner()
  let requests = 0
  const { apiFetch } = createTransport(session, async () => { requests++; return json({}) })
  session.invalidate({ locked: true })
  await assert.rejects(apiFetch('/api/case', { method: 'POST' }), /No write was sent/)
  assert.equal(requests, 0)
})
for (const [name, client] of [['default', axios], ['custom', axios.create({ baseURL: '/api' })]]) {
  test(`${name} Axios adapter preserves error.response for 403/422 and expires on 401`, async () => {
    const session = owner()
    createTransport(session).installAxios(client)
    for (const status of [403, 422, 401]) {
      const adapter = async config => {
        assert.equal(config.headers.get('Accept'), 'application/json')
        const response = { status, data: { errors: { field: ['invalid'] } }, headers: { 'content-type': 'application/json' }, config }
        throw new axios.AxiosError('fixture', 'ERR_BAD_REQUEST', config, {}, response)
      }
      await assert.rejects(client.get('/api/case', { adapter }), error => error.response?.status === status && error.response?.data.errors.field[0] === 'invalid')
      assert.equal(session.state.status, status === 401 ? 'unauthenticated' : 'authenticated')
    }
  })
}
test('XHR classifier covers PDF upload 401/403/422/419, HTML and stale completion', () => {
  for (const status of [201, 401, 403, 422, 419]) {
    const session = owner(), api = createTransport(session)
    const xhr = { status, responseURL: '/api/documents', getResponseHeader: () => 'application/json' }
    if (status === 419 || status === 401) assert.throws(() => api.inspectUpload(xhr, session.state.generation), /419|Session expired/)
    else api.inspectUpload(xhr, session.state.generation)
    assert.equal(session.state.status, status === 401 ? 'unauthenticated' : 'authenticated')
  }
  const session = owner(), api = createTransport(session), old = session.state.generation
  session.accept({ id: 2 }, false)
  assert.throws(() => api.inspectUpload({ status: 201, responseURL: '/api/documents', getResponseHeader: () => 'application/json' }, old), /reported completion/)
  assert.throws(() => api.inspectUpload({ status: 200, responseURL: '/login', getResponseHeader: () => 'text/html' }, session.state.generation), /HTML/)
})
