// Framework-independent lifecycle, also exercised by the native Node harness.
export function createSessionOwner({ probe, now = Date.now, publish = () => {} }) {
  const state = { status: 'unknown', user: null, generation: 0, notice: '', checkedAt: 0, locked: false }
  const listeners = new Set(), resets = new Set()
  let flight = null
  const emit = () => listeners.forEach(fn => fn(state))
  function boundary() {
    state.generation++
    flight = null
    state.user = null
    state.checkedAt = 0
    resets.forEach(fn => fn())
  }
  function invalidate({ notice = '', locked = false, broadcast = false } = {}) {
    boundary()
    Object.assign(state, { status: locked ? 'unauthenticated' : 'unknown', locked, notice })
    emit()
    if (broadcast) publish(locked ? 'logout' : 'change')
  }
  function accept(user, broadcast = true) {
    boundary()
    Object.assign(state, { user, status: 'authenticated', locked: false, checkedAt: now(), notice: '' })
    emit()
    if (broadcast) publish('login')
  }
  function expire(generation) {
    if (generation !== state.generation || state.status === 'unauthenticated') return
    invalidate({ locked: true, broadcast: true, notice: 'Session expired. Sign in again. Unsaved changes were not resubmitted.' })
  }
  async function verify({ force = false } = {}) {
    if (state.locked) return state.status
    if (flight) return flight
    if (!force && state.status === 'authenticated' && now() - state.checkedAt < 60000) return state.status
    const generation = state.generation
    state.status = 'verifying'
    emit()
    const pending = (async () => {
      try {
        const response = await Promise.resolve().then(probe)
        if (generation !== state.generation) return state.status
        if (response.status === 401) {
          if (state.user) expire(generation)
          else invalidate({ locked: true })
          return state.status
        }
        if (!response.ok || response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Verification unavailable')
        const result = await response.json()
        if (generation !== state.generation) return state.status
        if (!result.success || !result.data?.id) throw new Error('Invalid identity response')
        const identity = user => JSON.stringify([user?.id, user?.entity_id, user?.is_active, user?.role])
        if (state.user && identity(state.user) !== identity(result.data)) boundary()
        Object.assign(state, { user: result.data, status: 'authenticated', checkedAt: now() })
        emit()
      } catch {
        if (generation === state.generation) {
          state.status = 'unavailable'
          emit()
        }
      } finally {
        if (flight === pending) flight = null
      }
      return state.status
    })()
    flight = pending
    return pending
  }
  function notify(message) { state.notice = message; emit() }
  return { state, verify, accept, invalidate, expire, notify, subscribe(fn) { listeners.add(fn); fn(state); return () => listeners.delete(fn) }, onReset(fn) { resets.add(fn); return () => resets.delete(fn) } }
}

export function safeReturnPath(value) {
  return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') && !value.includes('\\') && !/[\r\n]/.test(value) && !value.startsWith('/login') ? value : '/'
}
