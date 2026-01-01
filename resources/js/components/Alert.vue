<template>
  <div
    v-if="visible"
    :class="[
      'p-4 rounded-lg mb-4',
      typeClasses
    ]"
  >
    <div class="flex justify-between items-start">
      <div>
        <h3 class="font-bold mb-1">{{ title }}</h3>
        <p>{{ message }}</p>
      </div>
      <button
        @click="visible = false"
        class="text-gray-600 hover:text-gray-900 font-bold"
      >
        ×
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  type: {
    type: String,
    default: 'info',
    validator: v => ['success', 'error', 'warning', 'info'].includes(v)
  },
  title: String,
  message: String
})

const visible = ref(true)

const typeClasses = computed(() => ({
  success: 'bg-green-50 border-l-4 border-green-500 text-green-700',
  error: 'bg-red-50 border-l-4 border-red-500 text-red-700',
  warning: 'bg-yellow-50 border-l-4 border-yellow-500 text-yellow-700',
  info: 'bg-blue-50 border-l-4 border-blue-500 text-blue-700'
})[props.type])
</script>
