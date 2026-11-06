<template>
  <section aria-labelledby="legacy-refund-title" class="mx-auto my-6 max-w-2xl rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-6">
    <h1 id="legacy-refund-title" ref="heading" tabindex="-1" class="text-2xl font-semibold text-gray-900 focus:outline-blue-600">
      This legacy Refund page is no longer supported
    </h1>
    <p class="mt-3 text-sm text-gray-600">
      Open an accessible tax case and explicitly select a Refund to use Refund stages 1–4.
    </p>
    <router-link to="/tax-cases" class="mt-6 inline-flex rounded-md px-3 py-2 text-sm font-medium text-blue-700 hover:bg-blue-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
      Go to Tax Cases
    </router-link>
  </section>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const heading = ref(null)
const focusHeading = () => heading.value?.focus()

onMounted(focusHeading)
watch(() => route.fullPath, focusHeading, { flush: 'post' })
</script>
