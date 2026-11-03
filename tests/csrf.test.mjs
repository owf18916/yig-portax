import { test } from 'node:test'
import assert from 'node:assert/strict'
import axios from 'axios'
import { createCsrf, cookieToken } from '../resources/js/auth/csrf.js'
import { createSessionOwner } from '../resources/js/auth/session.js'
import { createTransport } from '../resources/js/auth/transport.js'

function fixture(cookie = 'XSRF-TOKEN=encrypted%2Bvalue%3D', bootstrap) {
  const owner = createSessionOwner({ probe: () => {} })
  owner.accept({ id: 1 }, false)
  let calls = 0
  const csrf = createCsrf(owner, {
    readCookie: () => cookie, origin: () => 'https://portax.test',
    nativeFetch: async (...args) => { calls++; if (bootstrap) await bootstrap(...args); cookie = 'XSRF-TOKEN=fresh%2Bcookie'; return new Response(null, { status: 204 }) },
  })
  return { owner, csrf, calls: () => calls, setCookie: value => { cookie = value } }
}

test('cookie decoding once; malformed/absent cookies; no meta token authority', () => {
  assert.equal(cookieToken('other=x; XSRF-TOKEN=a%2Bb%3D%252F'), 'a+b=%2F')
  assert.equal(cookieToken('XSRF-TOKEN=%ZZ'), '')
  assert.equal(cookieToken('another=1'), '')
})

test('20 concurrent absent-cookie writes bootstrap once; fresh cookies never bootstrap', async () => {
  const f = fixture('')
  const results = await Promise.all(Array.from({ length: 20 }, () => f.csrf.headers('/api/test', 'POST', { 'x-csrf-token': 'stale', 'x-xsrf-token': 'stale' })))
  assert.equal(f.calls(), 1)
  results.forEach(headers => {
    assert.equal(headers.get('X-CSRF-TOKEN'), null)
    assert.equal(headers.get('X-XSRF-TOKEN'), 'fresh+cookie')
  })
  await f.csrf.headers('/api/test', 'DELETE')
  assert.equal(f.calls(), 1)
})

test('failed bootstrap is bounded, shared and can recover on a later explicit call', async () => {
  let failures = true
  const f = fixture('', () => { if (failures) throw Error('offline') })
  const results = await Promise.allSettled(Array.from({ length: 10 }, () => f.csrf.token()))
  assert.equal(results.filter(r => r.status === 'rejected').length, 10)
  assert.equal(f.calls(), 1)
  failures = false
  assert.equal(await f.csrf.token(), 'fresh+cookie')
  assert.equal(f.calls(), 2)
})

test('generation boundary during bootstrap prevents fetch and XHR writes', async () => {
  for (const transport of ['fetch', 'xhr']) {
    let finish, sent = 0
    const f = fixture('', () => new Promise(resolve => { finish = resolve }))
    const api = createTransport(f.owner, async () => { sent++; return new Response('{}') }, f.csrf)
    const work = transport === 'fetch'
      ? api.apiFetch('/api/test', { method: 'POST' })
      : api.prepareUpload({ setRequestHeader() { sent++ } }, '/api/documents', new FormData(), f.owner.state.generation)
    f.owner.invalidate({ locked: true })
    finish()
    await assert.rejects(work, /Session changed/)
    assert.equal(sent, 0)
  }
})

test('same origin only; safe requests strip tokens; body tokens cannot override cookie', async () => {
  const f = fixture()
  await assert.rejects(f.csrf.headers('https://elsewhere.test/api', 'POST'), /same origin/)
  const safe = await f.csrf.headers('/api/me', 'GET', { 'X-CSRF-TOKEN': 'stale', 'X-XSRF-TOKEN': 'stale' })
  assert.equal(safe.has('X-CSRF-TOKEN'), false)
  assert.equal(safe.has('X-XSRF-TOKEN'), false)
  const form = new FormData(); form.append('_token', 'stale')
  for (const body of [form, { _token: 'stale' }, JSON.stringify({ _token: 'stale' }), '_token=stale']) {
    await assert.rejects(f.csrf.headers('/api/test', 'POST', {}, body), /Competing body token/)
  }
})

test('login refreshes anonymous token; rotation feeds next write/logout; login 401 stays local', async () => {
  const f = fixture()
  f.owner.invalidate({ locked: true })
  let posts = 0
  const api = createTransport(f.owner, async (url, options) => {
    posts++
    assert.equal(options.headers.has('X-CSRF-TOKEN'), false)
    assert.equal(options.headers.get('X-XSRF-TOKEN'), posts === 1 ? 'fresh+cookie' : 'rotated')
    if (url === '/api/login') f.setCookie('XSRF-TOKEN=rotated')
    return new Response('{}', { status: 200, headers: { 'content-type': 'application/json' } })
  }, f.csrf)
  await api.sessionFetch('/api/login', { method: 'POST', headers: { 'X-CSRF-TOKEN': 'old-meta' } })
  f.owner.accept({ id: 2 }, false)
  await api.apiFetch('/api/test', { method: 'POST' })
  await api.sessionFetch('/api/logout', { method: 'POST' })
  assert.equal(posts, 3)
  assert.equal(f.calls(), 1)
  const generation = f.owner.state.generation
  const rejected = createTransport(f.owner, async () => new Response('{}', { status: 401 }), f.csrf)
  assert.equal((await rejected.sessionFetch('/api/login', { method: 'POST' })).status, 401)
  assert.equal(f.owner.state.generation, generation)
})

test('both Axios instances inject current cookie, replace conflicting headers and never replay 419', async () => {
  for (const client of [axios, axios.create({ baseURL: '/api' })]) {
    const f = fixture()
    const api = createTransport(f.owner, undefined, f.csrf)
    api.installAxios(client)
    let sends = 0
    await assert.rejects(client.post('/api/test', { data: 1 }, {
      headers: { 'X-CSRF-TOKEN': 'meta', 'X-XSRF-TOKEN': 'old' },
      adapter: async config => {
        sends++
        assert.equal(config.headers.has('X-CSRF-TOKEN'), false)
        assert.equal(config.headers.get('X-XSRF-TOKEN'), 'encrypted+value=')
        assert.equal(config.withXSRFToken, false)
        const response = { status: 419, headers: {}, config }
        throw new axios.AxiosError('mismatch', '', config, {}, response)
      },
    }), /419/)
    assert.equal(sends, 1)
    assert.equal(f.owner.state.status, 'authenticated')
    client.interceptors.request.clear(); client.interceptors.response.clear()
  }
})

test('XHR multipart keeps browser boundary and reads rotated cookie at preparation', async () => {
  const f = fixture(), headers = new Headers(), body = new FormData()
  body.append('document', new Blob(['%PDF-1.4']), 'test.pdf')
  f.setCookie('XSRF-TOKEN=rotated')
  const api = createTransport(f.owner, undefined, f.csrf)
  await api.prepareUpload({ setRequestHeader: (key, value) => headers.set(key, value) }, '/api/documents', body, f.owner.state.generation)
  assert.equal(headers.get('X-XSRF-TOKEN'), 'rotated')
  assert.equal(headers.has('Content-Type'), false)
  assert.equal(body.get('document').name, 'test.pdf')
})

test('mixed fetch/Axios/XHR writers share one absent-cookie bootstrap and preserve FormData', async () => {
  const f = fixture(''), client = axios.create(), body = new FormData()
  body.append('file', new Blob(['%PDF']), 'fixture.pdf')
  let sent = 0
  const api = createTransport(f.owner, async (url, options) => {
    assert.equal(options.headers.get('X-XSRF-TOKEN'), 'fresh+cookie')
    assert.equal(options.body, body)
    sent++
    return new Response('{}', { headers: { 'content-type': 'application/json' } })
  }, f.csrf)
  api.installAxios(client)
  await Promise.all([
    api.apiFetch('/api/documents', { method: 'POST', body }),
    client.post('/api/documents', body, { adapter: async config => {
      assert.equal(config.headers.get('X-XSRF-TOKEN'), 'fresh+cookie')
      assert.equal(config.data, body)
      sent++
      return { status: 200, data: {}, headers: {}, config }
    } }),
    api.prepareUpload({ setRequestHeader(key, value) { if (key === 'x-xsrf-token') { assert.equal(value, 'fresh+cookie'); sent++ } } }, '/api/documents', body, f.owner.state.generation),
  ])
  assert.equal(f.calls(), 1)
  assert.equal(sent, 3)
})
