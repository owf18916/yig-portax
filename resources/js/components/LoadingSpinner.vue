<template>
  <div class="flex justify-center items-center">
    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
    <span class="ml-3 text-gray-600">{{ message }}</span>
  </div>
</template>

<script setup>
defineProps({
  message: {
    type: String,
    default: 'Loading...'
  }
})
</script>
