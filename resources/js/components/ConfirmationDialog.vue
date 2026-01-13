<template>
  <!-- Confirmation Dialog Overlay -->
  <Teleport to="body">
    <transition name="dialog">
      <div v-if="isOpen" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl max-w-sm mx-4 transform transition-all">
          <!-- Header -->
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">{{ title }}</h3>
          </div>

          <!-- Content -->
          <div class="px-6 py-4">
            <p class="text-gray-600 text-sm leading-relaxed">{{ message }}</p>
            
            <!-- Additional details (optional) -->
            <div v-if="details" class="mt-3 p-3 bg-gray-50 rounded text-sm text-gray-700">
              {{ details }}
            </div>
          </div>

          <!-- Actions -->
          <div class="px-6 py-4 border-t border-gray-200 flex gap-3 justify-end">
            <button
              @click="cancel"
              class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded hover:bg-gray-200 transition"
            >
              {{ cancelLabel }}
            </button>
            <button
              @click="confirm"
              :class="[
                'px-4 py-2 text-sm font-medium rounded transition',
                variant === 'danger'
                  ? 'bg-red-500 text-white hover:bg-red-600'
                  : 'bg-blue-500 text-white hover:bg-blue-600'
              ]"
            >
              {{ confirmLabel }}
            </button>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<script setup>
import { ref } from 'vue'

const props = defineProps({
  isOpen: {
    type: Boolean,
    required: true
  },
  title: {
    type: String,
    default: 'Confirm Action'
  },
  message: {
    type: String,
    required: true
  },
  details: {
    type: String,
    default: null
  },
  confirmLabel: {
    type: String,
    default: 'Confirm'
  },
  cancelLabel: {
    type: String,
    default: 'Cancel'
  },
  variant: {
    type: String,
    enum: ['default', 'danger'],
    default: 'default'
  }
})

const emit = defineEmits(['confirm', 'cancel'])

const confirm = () => {
  emit('confirm')
}

const cancel = () => {
  emit('cancel')
}

// Allow closing with Escape key
if (props.isOpen) {
  const handleEscape = (e) => {
    if (e.key === 'Escape') {
      cancel()
    }
  }
  document.addEventListener('keydown', handleEscape)
  
  // Cleanup
  onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleEscape)
  })
}
</script>

<style scoped>
.dialog-enter-active,
.dialog-leave-active {
  transition: opacity 0.2s ease;
}

.dialog-enter-from,
.dialog-leave-to {
  opacity: 0;
}

.dialog-enter-active > div,
.dialog-leave-active > div {
  transition: transform 0.2s ease;
}

.dialog-enter-from > div,
.dialog-leave-to > div {
  transform: scale(0.95);
}
</style>
