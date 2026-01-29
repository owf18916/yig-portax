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
      :stageName="`Stage 7: Objection Decision (Keputusan Keberatan)`"
      :stageDescription="`Record the tax authority's decision on the Surat Keberatan (Objection)`"
      :stageId="7"
      :nextStageId="8"
      :caseId="caseId"
      :caseNumber="caseNumber"
      :fields="fields"
      :apiEndpoint="`/api/tax-cases/${caseId}/workflow/7`"
      :isReviewMode="false"
      :isLoading="isLoading"
      :caseStatus="caseStatus"
      :preFilledMessage="preFilledMessage"
      :prefillData="prefillData"
      :requireDocuments="true"
      :showDecisionOptions="true"
      @submit="refreshTaxCase"
      @saveDraft="refreshTaxCase"
      @update:formData="syncFormDataToParent"
    />

    <!-- REVISION HISTORY PANEL - Integrated -->
    <div class="px-4 py-6">
      <RevisionHistoryPanel 
        :case-id="caseId"
        :stage-id="7"
        :tax-case="caseData"
        :revisions="revisions"
        :current-user="currentUser"
        :current-documents="currentDocuments"
        :available-fields="availableFields"
        :fields="fields"
        @revision-requested="refreshTaxCase"
        @refresh="refreshTaxCase"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useRevisionAPI } from '@/composables/useRevisionAPI'
import { useToast } from '@/composables/useToast'
import StageForm from '../components/StageForm.vue'
import RevisionHistoryPanel from '../components/RevisionHistoryPanel.vue'

const route = useRoute()
const router = useRouter()
const caseId = parseInt(route.params.id, 10)
const { listRevisions } = useRevisionAPI()
const { showSuccess, showError } = useToast()

// Helper function to format date for HTML date input (YYYY-MM-DD)
const formatDateForInput = (date) => {
  if (!date) return null
  const d = new Date(date)
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${d.getFullYear()}-${month}-${day}`
}

const caseNumber = ref('TAX-2026-001')
const preFilledMessage = ref('Loading...')
const isLoading = ref(true)
const caseStatus = ref(null)
const caseData = ref({})
const revisions = ref([])
const currentUser = ref(null)
const currentDocuments = ref([])

// Available fields untuk revisi
const availableFields = [
  'decision_number',
  'decision_date',
  'decision_type',
  'decision_amount',
  'create_refund',
  'continue_to_next_stage'
]

const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'decision_number',
    label: 'Nomor Surat Keputusan Keberatan (Decision Number)',
    required: true,
    readonly: false,
    placeholder: 'e.g., KPB/2024/001'
  },
  {
    id: 2,
    type: 'date',
    key: 'decision_date',
    label: 'Tanggal Keputusan (Decision Date)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'select',
    key: 'decision_type',
    label: 'Keputusan (Decision)',
    required: true,
    readonly: false,
    options: [
      { value: 'granted', label: 'Dikabulkan (Granted - Accepted)' },
      { value: 'partially_granted', label: 'Dikabulkan Sebagian (Partially Granted)' },
      { value: 'rejected', label: 'Ditolak (Rejected)' }
    ]
  },
  {
    id: 4,
    type: 'number',
    key: 'decision_amount',
    label: 'Nilai (Decision Amount)',
    required: true,
    readonly: false,
    placeholder: 'Enter decision amount in Rp'
  }
])

// Pre-fill form data
const prefillData = ref({
  decision_number: '',
  decision_date: null,
  decision_type: '',
  decision_amount: 0,
  workflowHistories: []
})

// Computed property: Check if Stage 7 is submitted
const isStage7Submitted = computed(() => {
  if (!caseData.value || !caseData.value.workflow_histories) return false
  
  return caseData.value.workflow_histories.some(
    h => h.stage_id === 7 && (h.status === 'submitted' || h.status === 'approved')
  )
})

// Computed property: Get current decision type
const currentDecisionType = computed(() => {
  return prefillData.value.decision_type || null
})

// Computed property: Format decision amount for display
const formattedDecisionAmount = computed(() => {
  const amount = prefillData.value.decision_amount || 0
  return new Intl.NumberFormat('id-ID').format(amount)
})

const loadTaxCase = async () => {
  try {
    isLoading.value = true
    const response = await fetch(`/api/tax-cases/${caseId}`)
    
    if (!response.ok) {
      throw new Error('Failed to load case')
    }

    const result = await response.json()
    const caseFetchedData = result.data ? result.data : result
    
    if (!caseFetchedData || !caseFetchedData.id) {
      throw new Error('Case data not found')
    }

    caseNumber.value = caseFetchedData.case_number || 'TAX-2026-001'
    caseStatus.value = caseFetchedData.case_status
    caseData.value = caseFetchedData

    console.log('Full caseFetchedData:', caseFetchedData)
    console.log('workflow_histories:', caseFetchedData.workflow_histories)

    // Load objectionDecision data if available (check both camelCase and snake_case)
    const objDecision = caseFetchedData.objectionDecision || caseFetchedData.objection_decision
    if (objDecision) {
      const od = objDecision
      prefillData.value = {
        decision_number: od.decision_number || '',
        decision_date: formatDateForInput(od.decision_date),
        decision_type: od.decision_type || '',
        decision_amount: od.decision_amount || 0,
        create_refund: od.create_refund ?? false,
        continue_to_next_stage: od.continue_to_next_stage ?? false,
        workflowHistories: caseFetchedData.workflow_histories || []
      }
      preFilledMessage.value = '✅ Stage 7 data loaded from previous submission'
      console.log('Stage 7 Decision Data:', { objDecision, prefillData: prefillData.value })
    } else {
      preFilledMessage.value = '📝 Ready to enter Stage 7 data'
      console.log('No objectionDecision found yet')
    }

    await loadRevisions()
  } catch (error) {
    console.error('Error loading case:', error)
    preFilledMessage.value = '⚠️ Error loading case data'
    showError('Failed to load case data: ' + error.message)
  } finally {
    isLoading.value = false
  }
}

const loadRevisions = async () => {
  try {
    const result = await listRevisions('tax-cases', caseId)
    if (result && Array.isArray(result)) {
      revisions.value = result
    }
  } catch (error) {
    console.error('Error loading revisions:', error)
  }
}

const refreshTaxCase = async () => {
  console.log('[ObjectionDecisionForm] Refreshing tax case after submission...')
  await loadTaxCase()
  
  // ⭐ After loading, navigate back to parent TaxCaseDetail so it can re-calculate accessibility
  // This ensures TaxCaseDetail watcher triggers and Stage 13 becomes accessible
  console.log('[ObjectionDecisionForm] Navigating back to case detail view...')
  setTimeout(() => {
    router.push(`/tax-cases/${caseId}`)
  }, 800)
}

// Sync form data from StageForm back to parent
const syncFormDataToParent = (formDataUpdate) => {
  if (formDataUpdate) {
    Object.keys(formDataUpdate).forEach(key => {
      if (key in prefillData.value) {
        prefillData.value[key] = formDataUpdate[key]
      }
    })
    console.log('[ObjectionDecisionForm] Form data synced:', formDataUpdate)
  }
}

// Handle routing to Appeal (Stage 8) - User choice for partially_granted
const proceedToAppeal = async () => {
  try {
    // Call decision-choice endpoint
    const response = await fetch(`/api/tax-cases/${caseId}/workflow-decision`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
      },
      body: JSON.stringify({
        current_stage_id: 7,
        next_stage_id: 8,
        decision_type: 'appeal',
        decision_reason: 'User selected to proceed with Appeal path after partially granted decision'
      })
    })

    if (response.ok) {
      showSuccess('Workflow locked to Appeal path (Stage 8)')
      // Navigate to Stage 8 (Appeal Submission)
      setTimeout(() => {
        router.push(`/tax-cases/${caseId}/workflow/8`)
      }, 1000)
    } else {
      const errorData = await response.json()
      throw new Error(errorData.message || 'Failed to update workflow')
    }
  } catch (error) {
    showError('Error proceeding to Appeal: ' + error.message)
    console.error('Error:', error)
  }
}

// Handle routing to Refund (Stage 13) - User choice for partially_granted
const proceedToRefund = async () => {
  try {
    // Call decision-choice endpoint
    const response = await fetch(`/api/tax-cases/${caseId}/workflow-decision`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
      },
      body: JSON.stringify({
        current_stage_id: 7,
        next_stage_id: 13,
        decision_type: 'refund',
        decision_reason: 'User selected to proceed with Refund path after partially granted decision'
      })
    })

    if (response.ok) {
      showSuccess('Workflow locked to Refund path (Stage 13)')
      // Navigate to Stage 13 (Bank Transfer Request)
      setTimeout(() => {
        router.push(`/tax-cases/${caseId}/workflow/13`)
      }, 1000)
    } else {
      const errorData = await response.json()
      throw new Error(errorData.message || 'Failed to update workflow')
    }
  } catch (error) {
    showError('Error proceeding to Refund: ' + error.message)
    console.error('Error:', error)
  }
}

// Watcher to monitor decision data changes
watch(prefillData, (newVal) => {
  console.log('prefillData updated:', newVal)
  console.log('isStage7Submitted:', isStage7Submitted.value)
  console.log('currentDecisionType:', currentDecisionType.value)
  console.log('Should show buttons?', isStage7Submitted.value && currentDecisionType.value === 'partially_granted')
}, { deep: true })

onMounted(() => {
  loadTaxCase()
})
</script>
