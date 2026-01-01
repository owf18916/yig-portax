<template>
  <div class="space-y-6">
    <div class="flex items-center space-x-4">
      <Button @click="$router.back()" variant="secondary">← Back</Button>
      <h1 class="text-3xl font-bold text-gray-900">{{ caseNumber }}</h1>
    </div>

    <Alert
      v-if="apiError"
      type="error"
      title="Error"
      :message="apiError"
    />

    <LoadingSpinner v-if="loading" message="Loading tax case..." />

    <div v-else class="space-y-6">
      <!-- Case Overview -->
      <Card title="Case Overview" subtitle="Basic information">
        <div class="grid grid-cols-2 gap-6">
          <div>
            <p class="text-sm text-gray-600">Case Number</p>
            <p class="text-lg font-bold">{{ caseData.case_number }}</p>
          </div>
          <div>
            <p class="text-sm text-gray-600">Case Type</p>
            <p :class="['text-lg font-bold px-3 py-1 rounded-full inline-block', caseData.case_type === 'VAT' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800']">
              {{ caseData.case_type }}
            </p>
          </div>
          <div>
            <p class="text-sm text-gray-600">Entity Name</p>
            <p class="text-lg font-bold">{{ caseData.entity_name }}</p>
          </div>
          <div>
            <p class="text-sm text-gray-600">Status</p>
            <p class="inline-block px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
              {{ formatStatus(caseData.status) }}
            </p>
          </div>
        </div>
      </Card>

      <!-- Workflow Progress -->
      <Card title="Workflow Progress" subtitle="Track the case through stages">
        <div class="space-y-3">
          <div v-for="stage in workflowStages" :key="stage.id" class="flex items-center space-x-3">
            <div
              :class="[
                'w-8 h-8 rounded-full flex items-center justify-center text-white font-bold',
                stage.completed ? 'bg-green-500' : 'bg-gray-300'
              ]"
            >
              ✓
            </div>
            <div class="flex-1">
              <p class="font-medium">{{ stage.name }}</p>
              <p class="text-sm text-gray-600">{{ stage.description }}</p>
            </div>
            <Button
              v-if="canAccessStage(stage.id)"
              @click="$router.push(`/tax-cases/${$route.params.id}/workflow/${stage.id}`)"
              variant="primary"
              :disabled="!stage.accessible"
            >
              Access
            </Button>
          </div>
        </div>
      </Card>

      <!-- Documents section removed - documents uploaded per stage -->
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Card from '../components/Card.vue'
import Button from '../components/Button.vue'
import Alert from '../components/Alert.vue'
import LoadingSpinner from '../components/LoadingSpinner.vue'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const apiError = ref('')
const caseNumber = ref('TAX-2026-001')
const caseData = ref({
  case_number: '',
  case_type: 'CIT',
  entity_name: '',
  amount: 0,
  status: 'draft'
})
const documents = ref([])

const workflowStages = ref([
  { id: 1, name: 'SPT Filing', description: 'Initial tax return submission', completed: false, accessible: true },
  { id: 2, name: 'SP2 Record', description: 'Second level tax record', completed: false, accessible: false },
  { id: 3, name: 'SPHP Record', description: 'Tax correction record', completed: false, accessible: false },
  { id: 4, name: 'SKP Record', description: 'Tax audit report', completed: false, accessible: false },
  { id: 5, name: 'Objection', description: 'Formal objection filing', completed: false, accessible: false },
  { id: 6, name: 'Appeal', description: 'Administrative appeal', completed: false, accessible: false },
  { id: 7, name: 'Supreme Court', description: 'Cassation to Supreme Court', completed: false, accessible: false },
  { id: 8, name: 'Refund', description: 'Process refund if approved', completed: false, accessible: false }
])

onMounted(async () => {
  try {
    const response = await fetch(`/api/tax-cases/${route.params.id}`)
    if (!response.ok) throw new Error('Failed to load case')
    caseData.value = await response.json()
    caseNumber.value = caseData.value.case_number
  } catch (error) {
    apiError.value = error.message
  } finally {
    loading.value = false
  }
})

const canAccessStage = (stageId) => {
  return true
}

const formatStatus = (status) => {
  return status.charAt(0).toUpperCase() + status.slice(1)
}
</script>
