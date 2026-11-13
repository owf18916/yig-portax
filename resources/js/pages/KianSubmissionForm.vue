<template>
  <main class="mx-auto max-w-4xl space-y-4 p-4 sm:p-6">
    <h1 class="text-2xl font-bold">Internal KIAN</h1>
    <p>Select the origin stage to create, continue or view its KIAN. Additional KIAN is optional.</p>
    <p v-if="error" role="alert">{{ error }}</p>
    <p v-if="isLoading" role="status">Loading KIAN stages...</p>
    <template v-else v-for="(status, stage) in kianStatusByStage" :key="stage">
      <button v-if="status.kianId || status.draft_actions?.create" type="button" class="block rounded border px-4 py-3 text-left focus:ring-2 focus:ring-blue-500" @click="selectStageForKian(Number(stage))">
        Stage {{ stage }}: {{ status.submitted ? 'View KIAN' : status.kianId ? (status.draft_actions?.save_draft ? 'Continue Draft KIAN' : 'View KIAN') : Number(stage) === 4 ? 'Create KIAN' : 'Additional KIAN' }}
      </button>
    </template>
  </main>
</template>
<script setup>
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiFetch as fetch } from '../composables/useSessionApi.js'
const route = useRoute()
const router = useRouter()
const kianStatusByStage = ref({})
const isLoading = ref(true)
const error = ref('')
let generation = 0
let loadedCase = null
async function load() {
  const current = ++generation
  const id = Number(route.params.id)
  loadedCase = null; isLoading.value = true; kianStatusByStage.value = {}; error.value = ''
  try {
    const response = await fetch(`/api/tax-cases/${id}`)
    if (!response.ok) throw new Error('Unable to load KIAN stages.')
    const result = await response.json()
    if (current !== generation) return
    const data = result.data ?? result
    if (Number(data.id) !== id) throw new Error('Case scope mismatch.')
    kianStatusByStage.value = data.kian_status_by_stage ?? {}; loadedCase = id
  } catch { if (current === generation) error.value = 'Unable to load KIAN stages.' }
  finally { if (current === generation) isLoading.value = false }
}
function selectStageForKian(stage) {
  const state = kianStatusByStage.value[stage]
  if (isLoading.value || loadedCase !== Number(route.params.id) || ![4, 7, 10, 12].includes(stage) || !(state?.kianId || state?.draft_actions?.create)) return
  router.push(`/tax-cases/${loadedCase}/kian/${stage}`)
}
watch(() => route.params.id, load, { immediate: true })
</script>
