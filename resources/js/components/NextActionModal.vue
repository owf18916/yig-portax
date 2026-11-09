<template>
  <!-- Next Action Modal -->
  <Teleport to="body">
    <transition name="modal">
      <div v-if="isOpen" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
        <div ref="dialog" role="dialog" aria-modal="true" aria-labelledby="follow-up-title" tabindex="-1" @keydown="onDialogKeydown" class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto transform transition-all">
          <!-- Header -->
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 id="follow-up-title" class="text-lg font-semibold text-gray-900">Edit follow-up</h3>
            <p class="text-sm text-gray-600 mt-1">Update follow-up actions for this case</p>
          </div>

          <!-- Content -->
          <div class="px-6 py-4 space-y-4">
            <!-- Next Action Field -->
            <div>
              <label for="follow-up-next_action" class="block text-sm font-medium text-gray-700 mb-1">
                Next Action
              </label>
              <textarea
                id="follow-up-next_action"
                :disabled="isSaving"
                maxlength="1000"
                v-model="formData.next_action"
                placeholder="Enter the next action to take on this case"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"
                rows="3"
              ></textarea>
              <p class="text-xs text-gray-500 mt-1">Max 1000 characters</p>
            </div>

            <!-- Next Action Due Date Field -->
            <div>
              <label for="follow-up-next_action_due_date" class="block text-sm font-medium text-gray-700 mb-1">
                Next Action Due Date
              </label>
              <input
                id="follow-up-next_action_due_date"
                :disabled="isSaving"
                v-model="formData.next_action_due_date"
                type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              />
            </div>

            <!-- Status Comment Field -->
            <div>
              <label for="follow-up-status_comment" class="block text-sm font-medium text-gray-700 mb-1">
                Status Comment
              </label>
              <textarea
                id="follow-up-status_comment"
                :disabled="isSaving"
                maxlength="1000"
                v-model="formData.status_comment"
                placeholder="Additional status comment or notes"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"
                rows="3"
              ></textarea>
              <p class="text-xs text-gray-500 mt-1">Max 1000 characters</p>
            </div>
          </div>

          <!-- Actions -->
          <div class="px-6 py-4 border-t border-gray-200 flex gap-3 justify-end">
            <button
              type="button"
              :disabled="isSaving"
              @click="cancel"
              class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded hover:bg-gray-200 transition"
            >
              Cancel
            </button>
            <button
              type="button"
              @click="save"
              :disabled="isSaving"
              :class="[
                'px-4 py-2 text-sm font-medium rounded transition',
                isSaving
                  ? 'bg-gray-400 text-white cursor-not-allowed'
                  : 'bg-blue-500 text-white hover:bg-blue-600'
              ]"
            >
              {{ isSaving ? 'Saving...' : 'Save' }}
            </button>
          </div>

          <!-- Error Message -->
          <div role="alert" v-if="errorMessage" class="px-6 pb-4">
            <div class="p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
              {{ errorMessage }}
            </div>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<script setup>
import { ref, watch, nextTick } from 'vue'

const props = defineProps({
  isOpen: {
    type: Boolean,
    default: false
  },
  isSaving: { type: Boolean, default: false },
  errorMessage: { type: String, default: '' },
  stage: {
    type: Object,
    default: () => ({})
  },
  initialData: {
    type: Object,
    default: null
  }
})

const emit = defineEmits(['save', 'close', 'cancel'])

const formData = ref({
  next_action: '',
  next_action_due_date: '',
  status_comment: ''
})

const dialog = ref(null)
let previousFocus = null
const onDialogKeydown = (event) => {
  if (event.key === 'Escape') { event.preventDefault(); cancel(); return }
  if (event.key !== 'Tab') return
  const controls = [...dialog.value.querySelectorAll('button:not(:disabled), input:not(:disabled), textarea:not(:disabled)')]
  if (!controls.length) { event.preventDefault(); return }
  const first = controls[0], last = controls[controls.length - 1]
  if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog.value)) {
    event.preventDefault(); last.focus()
  } else if (!event.shiftKey && (document.activeElement === last || document.activeElement === dialog.value)) {
    event.preventDefault(); first.focus()
  }
}

// Watch for when modal opens to populate form data from initialData
watch(() => props.isOpen, (newVal) => {
  if (newVal && props.initialData) {
    formData.value = {
      next_action: props.initialData.next_action || '',
      next_action_due_date: props.initialData.next_action_due_date?.slice(0, 10) || '',
      status_comment: props.initialData.status_comment || ''
    }
  } else if (newVal) {
    // If no initialData, clear the form
    formData.value = {
      next_action: '',
      next_action_due_date: '',
      status_comment: ''
    }
  }
  if (newVal) {
    previousFocus = document.activeElement
    nextTick(() => dialog.value?.querySelector('textarea')?.focus())
  } else {
    previousFocus?.focus()
  }
})

const save = () => {
  if (!props.isSaving) emit('save', { ...formData.value })
}

const cancel = () => {
  if (!props.isSaving) emit('close')
}
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.3s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-to,
.modal-leave-from {
  opacity: 1;
}
</style>
