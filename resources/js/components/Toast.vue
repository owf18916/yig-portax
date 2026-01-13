<template>
  <div class="fixed top-4 right-4 z-50 space-y-3 pointer-events-none">
    <transition-group name="toast" tag="div">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        :class="[
          'pointer-events-auto p-4 rounded-lg shadow-lg flex items-center space-x-3 min-w-[300px] max-w-[500px] animate-fadeInDown',
          typeClasses(toast.type)
        ]"
      >
        <!-- Icon -->
        <span class="text-xl flex-shrink-0">
          {{ typeIcon(toast.type) }}
        </span>

        <!-- Content -->
        <div class="flex-1">
          <p class="font-semibold">{{ toast.title }}</p>
          <p v-if="toast.message" class="text-sm opacity-90">{{ toast.message }}</p>
        </div>

        <!-- Close Button -->
        <button
          @click="removeToast(toast.id)"
          class="flex-shrink-0 ml-2 text-lg font-bold opacity-70 hover:opacity-100 transition-opacity"
        >
          ×
        </button>
      </div>
    </transition-group>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const toasts = ref([])
let toastId = 0

const typeClasses = (type) => {
  const classes = {
    success: 'bg-green-500 text-white',
    error: 'bg-red-500 text-white',
    warning: 'bg-yellow-500 text-white',
    info: 'bg-blue-500 text-white'
  }
  return classes[type] || classes.info
}

const typeIcon = (type) => {
  const icons = {
    success: '✅',
    error: '❌',
    warning: '⚠️',
    info: 'ℹ️'
  }
  return icons[type] || icons.info
}

const addToast = (title, message = '', type = 'info', duration = 4000) => {
  const id = toastId++
  const toast = { id, title, message, type }
  toasts.value.push(toast)

  if (duration > 0) {
    setTimeout(() => {
      removeToast(id)
    }, duration)
  }

  return id
}

const removeToast = (id) => {
  toasts.value = toasts.value.filter(t => t.id !== id)
}

const clearAll = () => {
  toasts.value = []
}

// Expose methods for use from components
defineExpose({
  addToast,
  removeToast,
  clearAll,
  toasts
})
</script>

<style scoped>
@keyframes fadeInDown {
  from {
    opacity: 0;
    transform: translateY(-20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.animate-fadeInDown {
  animation: fadeInDown 0.3s ease-out;
}

.toast-move,
.toast-enter-active,
.toast-leave-active {
  transition: all 0.3s ease;
}

.toast-enter-from {
  opacity: 0;
  transform: translateX(30px);
}

.toast-leave-to {
  opacity: 0;
  transform: translateX(30px);
}
</style>
