<template>
  <Transition name="modal-fade">
    <div v-if="isOpen" class="fixed inset-0 z-50 overflow-hidden">
      <!-- Blur Backdrop -->
      <div
        class="absolute inset-0 bg-black/30 backdrop-blur-sm"
        @click="closeModal"
      />

      <!-- Drawer Slide In -->
      <Transition name="slide-in-right">
        <div
          v-if="isOpen"
          class="absolute right-0 top-0 h-full w-full max-w-md bg-white shadow-2xl overflow-y-auto"
        >
          <!-- Header -->
          <div class="sticky top-0 bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">
              {{ isEdit ? 'Edit Announcement' : 'Create Announcement' }}
            </h2>
            <button
              @click="closeModal"
              class="text-gray-400 hover:text-gray-600 transition-colors"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <!-- Form Content -->
          <form @submit.prevent="submitForm" class="p-6 space-y-4">
            <!-- Title -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
              <input
                v-model="form.title"
                type="text"
                maxlength="255"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                placeholder="Announcement title"
              />
              <p v-if="errors.title" class="text-red-600 text-xs mt-1">{{ errors.title[0] }}</p>
            </div>

            <!-- Content -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Content</label>
              <textarea
                v-model="form.content"
                rows="4"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                placeholder="Announcement content"
              />
              <p v-if="errors.content" class="text-red-600 text-xs mt-1">{{ errors.content[0] }}</p>
            </div>

            <!-- Type -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
              <select
                v-model="form.type"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              >
                <option value="info">Info</option>
                <option value="success">Success</option>
                <option value="warning">Warning</option>
                <option value="error">Error</option>
              </select>
              <p v-if="errors.type" class="text-red-600 text-xs mt-1">{{ errors.type[0] }}</p>
            </div>

            <!-- Published At -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Publish Date</label>
              <input
                v-model="form.published_at"
                type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              />
              <p v-if="errors.published_at" class="text-red-600 text-xs mt-1">{{ errors.published_at[0] }}</p>
            </div>

            <!-- Expires At -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Expiry Date (Optional)</label>
              <input
                v-model="form.expires_at"
                type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              />
              <p v-if="errors.expires_at" class="text-red-600 text-xs mt-1">{{ errors.expires_at[0] }}</p>
            </div>

            <!-- Is Active -->
            <div class="flex items-center">
              <input
                v-model="form.is_active"
                type="checkbox"
                id="is_active"
                class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
              />
              <label for="is_active" class="ml-2 block text-sm text-gray-700">Active</label>
            </div>

            <!-- Error Message -->
            <div v-if="errorMessage" class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm">
              {{ errorMessage }}
            </div>

            <!-- Loading State -->
            <div v-if="loading" class="text-center py-2">
              <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-blue-600 mx-auto"></div>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-3 pt-6 border-t border-gray-200">
              <button
                v-if="isEdit"
                type="button"
                @click="confirmDelete"
                class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-50 font-medium transition-colors"
              >
                Delete
              </button>
              <div class="flex-1" />
              <button
                type="button"
                @click="closeModal"
                class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 font-medium transition-colors"
              >
                Cancel
              </button>
              <button
                type="submit"
                :disabled="loading"
                class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50 font-medium transition-colors"
              >
                {{ isEdit ? 'Update' : 'Create' }}
              </button>
            </div>
          </form>

          <!-- Delete Confirmation Dialog -->
          <div v-if="showDeleteConfirm" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50">
            <div class="bg-white rounded-lg shadow-xl max-w-sm w-full mx-4 p-6">
              <h3 class="text-lg font-semibold text-gray-900 mb-2">Delete Announcement?</h3>
              <p class="text-gray-600 mb-6">This action cannot be undone.</p>
              <div class="flex gap-3">
                <button
                  type="button"
                  @click="showDeleteConfirm = false"
                  class="flex-1 px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 font-medium"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  @click="deleteAnnouncement"
                  :disabled="loading"
                  class="flex-1 px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-50 font-medium"
                >
                  Delete
                </button>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </div>
  </Transition>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
  isOpen: Boolean,
  editData: Object,
  onSubmit: Function,
  onClose: Function
})

const form = ref({
  title: '',
  content: '',
  type: 'info',
  published_at: '',
  expires_at: '',
  is_active: true
})

const errors = ref({})
const errorMessage = ref('')
const loading = ref(false)
const showDeleteConfirm = ref(false)

const isEdit = computed(() => !!props.editData?.id)

watch(() => props.isOpen, (newVal) => {
  if (newVal) {
    if (props.editData) {
      form.value = {
        title: props.editData.title,
        content: props.editData.content,
        type: props.editData.type,
        published_at: props.editData.published_at ? props.editData.published_at.split('T')[0] : '',
        expires_at: props.editData.expires_at ? props.editData.expires_at.split('T')[0] : '',
        is_active: props.editData.is_active
      }
    } else {
      form.value = {
        title: '',
        content: '',
        type: 'info',
        published_at: new Date().toISOString().split('T')[0],
        expires_at: '',
        is_active: true
      }
    }
    errors.value = {}
    errorMessage.value = ''
  }
}, { immediate: true })

const closeModal = () => {
  errors.value = {}
  errorMessage.value = ''
  showDeleteConfirm.value = false
  if (props.onClose) {
    props.onClose()
  }
}

const confirmDelete = () => {
  showDeleteConfirm.value = true
}

const deleteAnnouncement = async () => {
  loading.value = true
  errorMessage.value = ''

  try {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    const response = await fetch(`/api/announcements/${props.editData.id}`, {
      method: 'DELETE',
      headers: {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrfToken || ''
      },
      credentials: 'include'
    })

    const data = await response.json()

    if (!response.ok) {
      throw {
        response: { status: response.status, data }
      }
    }

    closeModal()
    if (props.onSubmit) {
      // Signal deletion to parent to reload data
      await props.onSubmit({ action: 'delete' })
    }
  } catch (error) {
    errorMessage.value = error.response?.data?.message || error.message || 'Failed to delete announcement'
  } finally {
    loading.value = false
    showDeleteConfirm.value = false
  }
}

const submitForm = async () => {
  loading.value = true
  errors.value = {}
  errorMessage.value = ''

  try {
    if (props.onSubmit) {
      await props.onSubmit(form.value)
      closeModal()
    }
  } catch (error) {
    if (error.response?.status === 422 && error.response?.data?.errors) {
      errors.value = error.response.data.errors
    } else {
      errorMessage.value = error.response?.data?.message || error.message || 'Failed to save announcement'
    }
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
/* Fade transition for backdrop */
.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: opacity 0.3s ease;
}

.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}

/* Slide in transition for drawer */
.slide-in-right-enter-active,
.slide-in-right-leave-active {
  transition: transform 0.3s ease;
}

.slide-in-right-enter-from {
  transform: translateX(100%);
}

.slide-in-right-leave-to {
  transform: translateX(100%);
}
</style>
