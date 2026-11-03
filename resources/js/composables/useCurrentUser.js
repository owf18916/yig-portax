import { ref } from 'vue'
import { createSessionOwner } from '../auth/session.js'

const SIGNAL = 'portax-session-change'
const LOCK = 'portax-session-lock'
function storage(action) { try { return action() } catch { return null } }
export const session = createSessionOwner({
  probe: () => globalThis.fetch('/api/me', { credentials: 'include', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: AbortSignal.timeout(10000) }),
  publish: action => storage(() => localStorage.setItem(SIGNAL, JSON.stringify({ action, nonce: crypto.randomUUID() })))
})
if (storage(() => sessionStorage.getItem(LOCK))) session.invalidate({ locked: true })
storage(() => localStorage.removeItem('user'))
const currentUser = ref(null)
const authStatus = ref(session.state.status)
const authGeneration = ref(0)
const authNotice = ref('')
export const logoutPending = ref(false)
session.subscribe(state => {
  currentUser.value = state.user
  authStatus.value = state.status
  authGeneration.value = state.generation
  authNotice.value = state.notice
  storage(() => state.locked ? sessionStorage.setItem(LOCK, '1') : sessionStorage.removeItem(LOCK))
})
if (typeof window !== 'undefined') {
  window.addEventListener('storage', event => {
    if (event.key !== SIGNAL && event.key !== 'user' && event.key !== null) return
    let action
    try { action = JSON.parse(event.newValue)?.action } catch { /* old client */ }
    session.invalidate({ locked: action === 'logout', notice: 'Session changed in another tab. Unsaved changes were not resubmitted.' })
    if (action !== 'logout') void session.verify({ force: true })
  })
  window.addEventListener('focus', () => {
    if (session.state.status === 'authenticated') void session.verify()
  })
}
export function useCurrentUser() {
  return { currentUser, authStatus, authGeneration, authNotice, verifySession: session.verify, setCurrentUser: session.accept,
    clearCurrentUser: () => session.invalidate({ locked: true, broadcast: true }) }
}
