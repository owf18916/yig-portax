// Native event/storage fixture: this does not claim a real browser execution.
import { test } from 'node:test'
import assert from 'node:assert/strict'
const handlers = new Map(), values = new Map([['user', JSON.stringify({ id: 999 })]])
globalThis.localStorage = { getItem: key => values.get(key), setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) }
const localLocks = new Map()
globalThis.sessionStorage = { getItem: key => localLocks.get(key), setItem: (key, value) => localLocks.set(key, value), removeItem: key => localLocks.delete(key) }
globalThis.window = { addEventListener: (name, handler) => handlers.set(name, handler) }
const { session, useCurrentUser } = await import('../resources/js/composables/useCurrentUser.js')
const response = id => new Response(JSON.stringify({ success: true, data: { id, entity_id: id } }), { headers: { 'Content-Type': 'application/json' } })
test('actual browser adapter ignores stale storage, handles logout/login storage events and suppresses old probe', async () => {
  assert.equal(useCurrentUser().currentUser.value, null)
  assert.equal(values.has('user'), false)
  globalThis.fetch = async () => response(1)
  await session.verify()
  assert.equal(useCurrentUser().currentUser.value.id, 1)
  handlers.get('storage')({ key: 'portax-session-change', newValue: JSON.stringify({ action: 'logout' }) })
  assert.equal(useCurrentUser().authStatus.value, 'unauthenticated')
  assert.equal(useCurrentUser().currentUser.value, null)
  assert.equal(localLocks.get('portax-session-lock'), '1')
  let finish, calls = 0
  globalThis.fetch = () => { calls++; return new Promise(resolve => { finish = resolve }) }
  handlers.get('storage')({ key: 'portax-session-change', newValue: JSON.stringify({ action: 'login' }) })
  const flight = session.verify()
  await Promise.resolve()
  assert.equal(useCurrentUser().currentUser.value, null)
  session.accept({ id: 3 }, false)
  finish(response(2))
  await flight
  assert.equal(calls, 1)
  assert.equal(useCurrentUser().currentUser.value.id, 3)
  assert.equal(localLocks.has('portax-session-lock'), false)
  assert.equal(values.has('user'), false)
})
test('actual private-link helper downloads checked bytes with filename and rejects HTML without activating a link', async () => {
  const { downloadPrivateDocument } = await import('../resources/js/composables/useSessionApi.js')
  const links = []
  globalThis.document = { createElement: () => {
    const link = { click() { links.push({ filename: this.download, href: this.href }) } }
    return link
  } }
  globalThis.fetch = async () => new Response('fixture PDF', { headers: { 'Content-Type': 'application/pdf', 'Content-Disposition': 'attachment; filename="evidence.pdf"' } })
  await downloadPrivateDocument(1)
  assert.equal(links.length, 1)
  assert.equal(links[0].filename, 'evidence.pdf')
  assert.match(links[0].href, /^blob:/)
  globalThis.fetch = async () => new Response('<html>Login</html>', { headers: { 'Content-Type': 'text/html' } })
  await assert.rejects(downloadPrivateDocument(1), /HTML/)
  assert.equal(links.length, 1)
})
