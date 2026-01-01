<template>
  <div class="bg-white rounded-lg shadow-md p-6">
    <div v-if="title" class="mb-4">
      <h2 class="text-xl font-bold text-gray-900">{{ title }}</h2>
      <p v-if="subtitle" class="text-gray-600 text-sm mt-1">{{ subtitle }}</p>
    </div>
    <slot />
  </div>
</template>

<script setup>
defineProps({
  title: String,
  subtitle: String
})
</script>
