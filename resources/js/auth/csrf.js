const unsafe = method => !['GET', 'HEAD', 'OPTIONS'].includes((method || 'GET').toUpperCase())

// Laravel encrypts this cookie; decode the browser encoding exactly once.
export function cookieToken(cookie) {
  const value = cookie.split(';').map(part => part.trim()).find(part => part.startsWith('XSRF-TOKEN='))
  try { return value ? decodeURIComponent(value.slice('XSRF-TOKEN='.length)) : '' } catch { return '' }
}

export function createCsrf(owner, {
  readCookie = () => document.cookie,
  nativeFetch = (...args) => globalThis.fetch(...args),
  origin = () => globalThis.location.origin,
} = {}) {
  let flight = null
  owner.onReset(() => { flight = null })
  const current = generation => {
    if (generation !== owner.state.generation) throw new Error('Session changed before sending. No write was sent.')
  }
  function sameOrigin(input) {
    if (new URL(input?.url || input, origin()).origin !== origin()) throw new Error('Session transport requires the same origin.')
  }
  async function token({ refresh = false } = {}) {
    const generation = owner.state.generation
    if (refresh || !cookieToken(readCookie())) {
      if (!flight) {
        const pending = (async () => {
          const response = await nativeFetch('/api/csrf-token', {
            credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(10000),
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          })
          current(generation)
          if (!response.ok || response.redirected || response.status !== 204) throw new Error('Could not initialize the session token. Try signing in again.')
        })()
        flight = pending
        pending.finally(() => { if (flight === pending) flight = null }).catch(() => {})
      }
      await flight
    }
    current(generation)
    const value = cookieToken(readCookie())
    if (!value) throw new Error('Session token cookie unavailable. Check cookies and sign in again.')
    return value
  }
  function checkBody(body) {
    let value = body
    if (typeof value === 'string') { try { value = JSON.parse(value) } catch { value = new URLSearchParams(value) } }
    if (value?.has?.('_token') || (value && Object.hasOwn(value, '_token'))) throw new Error('Competing body token rejected. No write was sent.')
  }
  async function headers(input, method, supplied, body, options) {
    sameOrigin(input)
    const result = new Headers(supplied)
    result.delete('X-CSRF-TOKEN')
    result.delete('X-XSRF-TOKEN')
    if (unsafe(method)) {
      checkBody(body)
      result.set('X-XSRF-TOKEN', await token(options))
    }
    return result
  }
  return { headers, token, sameOrigin }
}
