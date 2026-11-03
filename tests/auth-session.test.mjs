import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createSessionOwner, safeReturnPath } from '../resources/js/auth/session.js'

const user = id => ({ id, entity_id: id, role: { permissions: ['submit_cases'] } })
const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r }); return { promise, resolve } }

test('unknown first load: 20 concurrent private-route probes use one /me and ignore cached identity', async () => {
  let calls = 0
  const pending = deferred()
  const owner = createSessionOwner({ probe: () => { calls++; return pending.promise } })
  assert.equal(owner.state.user, null)
  const flights = Array.from({ length: 20 }, () => owner.verify())
  assert.equal(owner.state.status, 'verifying')
  pending.resolve(json({ success: true, data: user(1) }))
  await Promise.all(flights)
  assert.equal(calls, 1)
  assert.equal(owner.state.status, 'authenticated')
  await owner.verify()
  assert.equal(calls, 1)
})
test('guest session becomes unauthenticated without recursive verification', async () => {
  let calls = 0
  const owner = createSessionOwner({ probe: async () => { calls++; return json({}, 401) } })
  await owner.verify(); await owner.verify()
  assert.equal(owner.state.status, 'unauthenticated')
  assert.equal(calls, 1)
})
test('late /me cannot overwrite logout or login B', async () => {
  const pending = deferred()
  const owner = createSessionOwner({ probe: () => pending.promise })
  const flight = owner.verify()
  owner.invalidate({ locked: true })
  owner.accept(user(2))
  pending.resolve(json({ success: true, data: user(1) }))
  await flight
  assert.equal(owner.state.user.id, 2)
})
test('network/5xx/HTML verification failures stay unavailable with manual bounded retry', async () => {
  for (const probe of [async () => { throw Error('offline') }, async () => json({}, 503), async () => new Response('<html>Login</html>')]) {
    const owner = createSessionOwner({ probe })
    await owner.verify()
    assert.equal(owner.state.status, 'unavailable')
    assert.equal(owner.state.locked, false)
  }
})
test('account and cross-tab boundaries reset state and suppress previous generation', async () => {
  let resets = 0, signals = 0
  const owner = createSessionOwner({ probe: async () => json({ success: true, data: user(2) }), publish: () => signals++ })
  owner.onReset(() => resets++)
  owner.accept(user(1))
  const old = owner.state.generation
  owner.invalidate() // receiving tab: synchronously hides A before revalidation
  assert.equal(owner.state.user, null)
  await owner.verify()
  assert.equal(owner.state.user.id, 2)
  owner.expire(old)
  assert.equal(owner.state.status, 'authenticated')
  assert.equal(resets, 2)
  assert.equal(signals, 1)
})
test('concurrent 401 burst invalidates once and never replays mutations', () => {
  let signals = 0
  const owner = createSessionOwner({ probe: async () => json({}, 401), publish: () => signals++ })
  owner.accept(user(1), false)
  const generation = owner.state.generation
  for (let i = 0; i < 20; i++) owner.expire(generation)
  assert.equal(signals, 1)
  assert.equal(owner.state.status, 'unauthenticated')
})
test('freshness expires after 60 seconds, without polling or absolute session timer', async () => {
  let time = 100, calls = 0
  const owner = createSessionOwner({ now: () => time, probe: async () => { calls++; return json({ success: true, data: user(1) }) } })
  await owner.verify(); time += 59999; await owner.verify(); assert.equal(calls, 1)
  time++; await owner.verify(); assert.equal(calls, 2)
})
test('return paths reject external and malformed paths', () => {
  for (const path of ['https://example.com', '//evil.test', '/\\evil.test', '/login', '/\n/evil.test']) assert.equal(safeReturnPath(path), '/')
  assert.equal(safeReturnPath('/tax-cases?type=VAT'), '/tax-cases?type=VAT')
})
test('expired focus verification broadcasts one expiry while preserving a genuine verification 403 as unavailable', async () => {
  let signals = 0
  const owner = createSessionOwner({ probe: async () => json({}, 401), publish: () => signals++ })
  owner.accept(user(1), false)
  await owner.verify({ force: true })
  assert.equal(owner.state.status, 'unauthenticated')
  assert.match(owner.state.notice, /Session expired/)
  assert.equal(signals, 1)
  const denied = createSessionOwner({ probe: async () => json({}, 403) })
  denied.accept(user(1), false)
  await denied.verify({ force: true })
  assert.equal(denied.state.status, 'unavailable')
  assert.equal(denied.state.user.id, 1)
})
