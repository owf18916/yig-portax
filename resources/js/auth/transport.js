const writing = method => !['GET', 'HEAD', 'OPTIONS'].includes((method || 'GET').toUpperCase())
export function createTransport(owner, nativeFetch = (...args) => globalThis.fetch(...args)) {
  function checkGeneration(generation, method, status) {
    if (generation === owner.state.generation) return
    const outcome = status >= 200 && status < 300 ? 'The server reported completion.' : status >= 400 && status < 500 ? 'The server rejected the request.' : 'Its completion is uncertain.'
    const message = writing(method)
      ? `Account changed while a write was pending (HTTP ${status || 'unknown'}). ${outcome} Check its result before submitting again; it was not retried.`
      : 'Account changed; the previous account response was ignored.'
    if (writing(method)) owner.notify(message)
    throw new Error(message)
  }
  function classify({ status, url = '', contentType = '', redirected = false, generation, method = 'GET' }) {
    // A late 401 contains no account data and must not expire a newer login.
    if (status === 401 && generation !== owner.state.generation) throw new Error('Previous session response ignored.')
    checkGeneration(generation, method, status)
    if (status === 401) {
      owner.expire(generation)
      throw new Error('Session expired. Sign in again; no write was retried.')
    }
    if (status === 419) {
      const message = 'Session/token error (419). Reload or sign in again. Your write was not retried.'
      owner.notify(message)
      throw new Error(message)
    }
    if (redirected || /text\/html/i.test(contentType) || /\/login(?:[?#]|$)/.test(url)) {
      throw new Error('Unexpected redirect or HTML from the API. Verify your session and try again; no write was retried.')
    }
  }
  async function apiFetch(input, options = {}) {
    if (owner.state.status !== 'authenticated') throw new Error('Verify your session before making protected requests. No write was sent.')
    const method = options.method || input?.method || 'GET'
    const generation = owner.state.generation
    const headers = new Headers(input?.headers)
    new Headers(options.headers).forEach((value, key) => headers.set(key, value))
    // JSON failures even when the success body is PDF/XLSX. No multipart Content-Type override.
    headers.set('Accept', 'application/json')
    headers.set('X-Requested-With', 'XMLHttpRequest')
    let response
    try {
      response = await nativeFetch(input, { credentials: 'include', ...options, headers })
    } catch (error) {
      if (writing(method)) {
        owner.notify('Write result unconfirmed because the connection failed. Check its result before submitting again; it was not retried.')
      }
      throw error
    }
    classify({ status: response.status, url: response.url, contentType: response.headers.get('content-type') || '', redirected: response.redirected, generation, method })
    // Check again after body consumption, not just after response headers.
    return new Proxy(response, { get(target, key) {
      if (['json', 'blob', 'text', 'arrayBuffer'].includes(key)) return async () => {
        if (key === 'json' && !/application\/(?:[\w.-]+\+)?json/i.test(target.headers.get('content-type') || '')) throw new Error('Expected JSON from the API. Check your session and connection.')
        if (key === 'blob' && target.ok && !/^(application\/(pdf|octet-stream|vnd\.openxmlformats-officedocument\.spreadsheetml\.sheet|vnd\.ms-excel)|image\/)/i.test(target.headers.get('content-type') || '')) throw new Error('Unexpected document type; download blocked.')
        const body = await target[key]()
        if (target.status !== 401) checkGeneration(generation, method, target.status)
        return body
      }
      const value = Reflect.get(target, key, target)
      return typeof value === 'function' ? value.bind(target) : value
    } })
  }
  function installAxios(client) {
    client.interceptors.request.use(config => {
      if (owner.state.status !== 'authenticated') throw new Error('Verify your session before making protected requests. No write was sent.')
      config.sessionGeneration = owner.state.generation
      config.headers.Accept = 'application/json'
      config.headers['X-Requested-With'] = 'XMLHttpRequest'
      return config
    })
    const inspect = response => {
      if (!response) return
      classify({ status: response.status, url: response.request?.responseURL || '', contentType: response.headers?.['content-type'] || '', generation: response.config.sessionGeneration, method: response.config.method })
    }
    client.interceptors.response.use(response => { inspect(response); return response }, error => {
      if (!error.response && writing(error.config?.method)) owner.notify('Write result unconfirmed because the connection failed. Check its result before submitting again; it was not retried.')
      try { inspect(error.response) }
      catch (classified) { error.message = classified.message }
      return Promise.reject(error)
    })
  }
  function inspectUpload(xhr, generation) {
    classify({ status: xhr.status, url: xhr.responseURL, contentType: xhr.getResponseHeader('Content-Type') || '', generation, method: 'POST' })
    if (xhr.status >= 200 && xhr.status < 300 && !/application\/(?:[\w.-]+\+)?json/i.test(xhr.getResponseHeader('Content-Type') || '')) throw new Error('Expected JSON upload response. Check the document list before uploading again.')
    // 401 has invalidated this upload; do not consume its body as a successful write.
    if (xhr.status >= 200 && xhr.status < 300) checkGeneration(generation, 'POST', xhr.status)
  }
  return { apiFetch, installAxios, inspectUpload, classify, checkGeneration }
}
