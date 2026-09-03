import { ref } from 'vue'

const USER_STORAGE_KEY = 'user'

function storedUser() {
  try {
    const value = localStorage.getItem(USER_STORAGE_KEY)
    return value ? JSON.parse(value) : null
  } catch {
    localStorage.removeItem(USER_STORAGE_KEY)
    return null
  }
}

const currentUser = ref(storedUser())

function setCurrentUser(user) {
  currentUser.value = user
  localStorage.setItem(USER_STORAGE_KEY, JSON.stringify(user))
}

function clearCurrentUser() {
  currentUser.value = null
  localStorage.removeItem(USER_STORAGE_KEY)
}

export function useCurrentUser() {
  return { currentUser, setCurrentUser, clearCurrentUser }
}
