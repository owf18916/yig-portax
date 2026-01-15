<template>
  <div class="h-full">
    <!-- Loading Overlay -->
    <div v-if="isLoading" class="fixed inset-0 backdrop-blur-sm bg-white/30 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl p-8 text-center shadow-2xl border border-white/50">
        <div class="animate-spin rounded-full h-12 w-12 border-4 border-blue-200 border-t-blue-500 mx-auto mb-4"></div>
        <p class="text-gray-700 font-medium">Loading form data...</p>
      </div>
    </div>

    <StageForm
      :stageName="`Stage 1: SPT Filing (Review & Submit)`"
      :stageDescription="`Review your initial tax return submission created during case creation`"
      :stageId="1"
      :nextStageId="4"
      :caseId="caseId"
      :caseNumber="caseNumber"
      :fields="fields"
      :apiEndpoint="`/api/tax-cases/${caseId}/workflow/1`"
      :isReviewMode="true"
      :isLoading="isLoading"
      :caseStatus="caseStatus"
      :preFilledMessage="preFilledMessage"
      :prefillData="prefillData"
      @submit="handleSubmit"
      @saveDraft="handleSaveDraft"
    />

    <!-- REVISION HISTORY PANEL - Integrated -->
    <RevisionHistoryPanel 
      v-if="!isLoading && currentUser"
      :case-id="caseId"
      :tax-case="{ submitted_at: prefillData.submitted_at }"
      :revisions="revisions"
      :current-user="currentUser"
      :available-fields="availableFields"
      @revision-requested="loadRevisions"
      @refresh="loadRevisions"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import StageForm from '../components/StageForm.vue'
import Alert from '../components/Alert.vue'
import RevisionHistoryPanel from '../components/RevisionHistoryPanel.vue'

const route = useRoute()
const caseId = parseInt(route.params.id, 10)
const caseNumber = ref('TAX-2026-001')
const preFilledMessage = ref('Loading...')
const isLoading = ref(true)
const caseStatus = ref(null)
const revisions = ref([])
const currentUser = ref(null)

const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'entity_name',
    label: 'Entity Name',
    required: true,
    readonly: true
  },
  {
    id: 2,
    type: 'select',
    key: 'period_id',
    label: 'Fiscal Period',
    required: true,
    options: []
  },
  {
    id: 3,
    type: 'select',
    key: 'currency_id',
    label: 'Currency',
    required: true,
    options: []
  },
  {
    id: 4,
    type: 'number',
    key: 'disputed_amount',
    label: 'Nilai Sengketa (Disputed Amount)',
    required: true,
    readonly: false
  }
])

// Available fields untuk revisi
const availableFields = [
  'entity_name',
  'period_id',
  'currency_id',
  'disputed_amount'
]

// Cek apakah field sedang di-lock (tidak bisa diedit)
const isFieldLocked = (fieldName) => {
  // Jika belum submitted, semua field bisa diedit
  if (!prefillData.value.submitted_at) {
    return false
  }

  // Jika tidak ada approved revision, semuanya lock
  const currentRevision = revisions.value.find(r => r.revision_status === 'APPROVED')
  if (!currentRevision) {
    return true
  }

  // Jika ada approved revision, hanya field yang di-approve bisa diedit
  return !currentRevision.original_data || 
         !currentRevision.original_data.hasOwnProperty(fieldName)
}

// Pre-fill form data dengan nilai dari tax case
const prefillData = ref({
  entity_name: '',
  period_id: null,
  currency_id: null,
  disputed_amount: null
})

onMounted(async () => {
  try {
    // Fetch everything in parallel
    const [currRes, periodRes, caseRes] = await Promise.all([
      fetch('/api/currencies'),
      fetch('/api/periods'),
      fetch(`/api/tax-cases/${caseId}`)
    ])

    // Check response status
    if (!currRes.ok || !periodRes.ok || !caseRes.ok) {
      throw new Error('API request failed')
    }

    const currencies = await currRes.json()
    const periods = await periodRes.json()
    const caseResponse = await caseRes.json()
    
    // Handle wrapped response (if API returns {success, data: {...}})
    const caseData = caseResponse.data ? caseResponse.data : caseResponse

    if (!caseData || !caseData.id) {
      throw new Error('Case data not found')
    }

    // Set dropdown options
    fields.value[1].options = periods.map(p => ({
      value: p.id,
      label: p.period_code
    }))

    fields.value[2].options = currencies.map(c => ({
      value: c.id,
      label: `${c.code} - ${c.name}`
    }))

    // Set pre-fill data dengan nilai dari tax case (benar-benar linked!)
    prefillData.value = {
      entity_name: caseData.entity_name || '',
      period_id: caseData.period_id,  // Ini akan di-bind langsung di StageForm
      currency_id: caseData.currency_id,  // Ini akan di-bind langsung di StageForm
      disputed_amount: caseData.disputed_amount ? parseFloat(caseData.disputed_amount) : null
    }

    caseNumber.value = caseData.case_number || 'N/A'
    caseStatus.value = caseData.case_status_id
    preFilledMessage.value = `✅ Pre-filled from ${caseData.case_type} case (${caseData.case_number})`

    // Load revisions untuk form ini
    await loadRevisions()

    // Load current user info
    try {
      const userRes = await fetch('/api/user')
      if (userRes.ok) {
        currentUser.value = await userRes.json()
      }
    } catch (err) {
      console.error('Failed to load user:', err)
    }

  } catch (error) {
    preFilledMessage.value = '❌ Error loading case data'
  } finally {
    isLoading.value = false
  }
})

// Load revisions untuk case ini
const loadRevisions = async () => {
  try {
    const response = await fetch(`/api/tax-cases/${caseId}/revisions`)
    
    if (!response.ok) {
      console.warn(`Failed to load revisions: ${response.status} ${response.statusText}`)
      // Silently fail - revisions are optional
      revisions.value = []
      return
    }
    
    const data = await response.json()
    revisions.value = data.data || data || []
  } catch (err) {
    console.error('Failed to load revisions:', err)
    revisions.value = []
  }
}

const handleSubmit = (event) => {
  // Handle form submission
}

const handleSaveDraft = (event) => {
  // Handle draft saving
}
</script>
