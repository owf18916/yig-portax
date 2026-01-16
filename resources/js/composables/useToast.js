import { ref, inject } from 'vue'

// Global toast instance reference
let toastComponentRef = null

/**
 * Store toast component reference - called from App.vue
 */
export const setToastComponent = (ref) => {
  toastComponentRef = ref
}

/**
 * Toast composable for showing notifications
 * 
 * Usage:
 * const { showToast } = useToast()
 * showToast('Success', 'Item saved', 'success', 3000)
 */
export const useToast = () => {
  const showToast = (title, message = '', type = 'info', duration = 4000) => {
    if (!toastComponentRef) {
      console.warn('Toast component not initialized')
      return
    }

    return toastComponentRef.value.addToast(title, message, type, duration)
  }

  const showSuccess = (title, message = '', duration = 3000) => {
    return showToast(title, message, 'success', duration)
  }

  const showError = (title, message = '', duration = 4000) => {
    return showToast(title, message, 'error', duration)
  }

  const showWarning = (title, message = '', duration = 4000) => {
    return showToast(title, message, 'warning', duration)
  }

  const showInfo = (title, message = '', duration = 4000) => {
    return showToast(title, message, 'info', duration)
  }

  return {
    showToast,
    showSuccess,
    showError,
    showWarning,
    showInfo
  }
}
