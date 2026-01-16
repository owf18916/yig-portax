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
              {{ formatStatus(caseData.status?.name || caseData.status || 'Draft') }}
            </p>
          </div>
          <div>
            <p class="text-sm text-gray-600">Disputed Amount</p>
            <p class="text-lg font-bold">{{ formatCurrency(caseData.disputed_amount || 0, caseData.currency?.code) }}</p>
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
const workflowHistory = ref([])

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

// Function untuk update accessibility berdasarkan workflow history
const updateStageAccessibility = () => {
  workflowStages.value.forEach((stage, index) => {
    // Cek apakah stage saat ini atau stage sebelumnya sudah completed
    const currentStageCompleted = workflowHistory.value.some(
      h => h.stage_id === stage.id && (h.status === 'submitted' || h.status === 'completed')
    )
    
    const previousStageCompleted = index === 0 || workflowHistory.value.some(
      h => h.stage_id === workflowStages.value[index - 1].id && (h.status === 'submitted' || h.status === 'completed')
    )
    
    // Stage bisa diakses jika:
    // 1. Stage pertama (SPT Filing)
    // 2. Stage sebelumnya sudah submitted/completed
    stage.accessible = index === 0 || previousStageCompleted
    
    // Stage completed jika sudah ada di workflow history dengan status submitted/completed
    stage.completed = currentStageCompleted
  })
}

onMounted(async () => {
  try {
    const response = await fetch(`/api/tax-cases/${route.params.id}`, {
      credentials: 'include',
      headers: { 'Accept': 'application/json' }
    })
    if (!response.ok) throw new Error('Failed to load case')
    const responseData = await response.json()
    
    // Handle API response wrapper: { success, message, data: {...} }
    if (responseData.data) {
      const data = responseData.data
      // If it's the case object itself (not pagination)
      if (data.id && data.case_number) {
        caseData.value = data
      } else if (Array.isArray(data)) {
        // Shouldn't happen for single case, but handle it
        caseData.value = data[0] || {}
      } else {
        caseData.value = data
      }
    } else {
      caseData.value = responseData
    }
    
    // Load workflow history untuk determine accessibility
    if (caseData.value.workflow_history && Array.isArray(caseData.value.workflow_history)) {
      workflowHistory.value = caseData.value.workflow_history
    } else if (caseData.value.id) {
      // Jika tidak ada di case data, try fetch dari endpoint terpisah
      try {
        const historyResponse = await fetch(`/api/tax-cases/${route.params.id}/workflow-history`, {
          credentials: 'include',
          headers: { 'Accept': 'application/json' }
        })
        if (historyResponse.ok) {
          const historyData = await historyResponse.json()
          workflowHistory.value = historyData.data || historyData
        }
      } catch (e) {
        console.warn('Could not load workflow history:', e)
      }
    }
    
    // Update stage accessibility berdasarkan workflow history
    updateStageAccessibility()
    
    caseNumber.value = caseData.value.case_number || 'TAX-2026-001'
    console.log('Case data loaded:', caseData.value)
    console.log('Workflow history:', workflowHistory.value)
  } catch (error) {
    apiError.value = error.message
    console.error('Failed to load case:', error)
  } finally {
    loading.value = false
  }
})

const canAccessStage = (stageId) => {
  const stage = workflowStages.value.find(s => s.id === stageId)
  return stage && stage.accessible
}

const formatStatus = (status) => {
  if (!status) return 'Draft'
  return status.charAt(0).toUpperCase() + status.slice(1)
}

const formatCurrency = (amount, currencyCode = 'IDR') => {
  // Map currency codes to locale and options
  const currencyMap = {
    'IDR': { locale: 'id-ID', code: 'IDR' },
    'USD': { locale: 'en-US', code: 'USD' },
    'EUR': { locale: 'de-DE', code: 'EUR' },
    'SGD': { locale: 'en-SG', code: 'SGD' }
  }
  
  const currencyConfig = currencyMap[currencyCode] || currencyMap['IDR']
  
  return new Intl.NumberFormat(currencyConfig.locale, {
    style: 'currency',
    currency: currencyConfig.code,
    minimumFractionDigits: 0
  }).format(amount)
}
</script>
