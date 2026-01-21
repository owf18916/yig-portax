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
      :stageName="`Stage 12: Keputusan Peninjauan Kembali (Supreme Court Decision)`"
      :stageDescription="`Enter the final Supreme Court decision on case review (Peninjauan Kembali)`"
      :stageId="12"
      :nextStageId="13"
      :caseId="caseId"
      :caseNumber="caseNumber"
      :fields="fields"
      :apiEndpoint="`/api/tax-cases/${caseId}/workflow/12`"
      :isReviewMode="false"
      :isLoading="isLoading"
      :caseStatus="caseStatus"
      :preFilledMessage="preFilledMessage"
      :prefillData="prefillData"
      :showDecisionOptions="true"
      @submit="refreshTaxCase"
      @saveDraft="refreshTaxCase"
      @update:formData="syncFormDataToParent"
    />

    <!-- REVISION HISTORY PANEL - Integrated -->
    <div class="px-4 py-6">
      <RevisionHistoryPanel 
        :case-id="caseId"
        :stage-id="12"
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
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useRevisionAPI } from '@/composables/useRevisionAPI'
import { useToast } from '@/composables/useToast'
import StageForm from '../components/StageForm.vue'
import RevisionHistoryPanel from '../components/RevisionHistoryPanel.vue'

const route = useRoute()
const caseId = parseInt(route.params.id, 10)
const { listRevisions } = useRevisionAPI()

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
  'decision_notes',
  'next_action',
  'supporting_docs'
]

const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'decision_number',
    label: 'Decision Number (Nomor Surat Keputusan Peninjauan Kembali)',
    required: true,
    readonly: false,
    placeholder: 'e.g., SK/PK/2024/0001'
  },
  {
    id: 2,
    type: 'date',
    key: 'decision_date',
    label: 'Decision Date (Tanggal Keputusan)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'select',
    key: 'decision_type',
    label: 'Decision Type (Keputusan)',
    required: true,
    readonly: false,
    options: [
      { value: 'granted', label: 'Granted (Dikabulkan)' },
      { value: 'partially_granted', label: 'Partially Granted (Dikabulkan Sebagian)' },
      { value: 'rejected', label: 'Rejected (Ditolak)' }
    ]
  },
  {
    id: 4,
    type: 'number',
    key: 'decision_amount',
    label: 'Decision Amount (Nilai Keputusan)',
    required: true,
    readonly: false,
    placeholder: 'Enter decision amount'
  },
  {
    id: 5,
    type: 'textarea',
    key: 'decision_notes',
    label: 'Decision Notes (Catatan Keputusan)',
    required: false,
    readonly: false,
    placeholder: 'Enter any additional notes...'
  }
])

const prefillData = ref({
  decision_number: '',
  decision_date: null,
  decision_type: '',
  decision_amount: 0,
  decision_notes: '',
  next_action: '',
  workflowHistories: []
})

// ⭐ REAL-TIME DECISION OPTIONS STATE
const showDecisionOptions = ref(false)
const selectedNextAction = ref('')

// ⭐ WATCHER: Show decision options INSTANTLY when decision_type is selected
watch(() => prefillData.value.decision_type, (newType) => {
  if (newType) {
    showDecisionOptions.value = true
  }
})

// ⭐ UPDATE NEXT ACTION CHOICE: Store in prefillData
const updateNextAction = (choice) => {
  selectedNextAction.value = choice
  prefillData.value.next_action = choice
  console.log(`User selected next action: ${choice}`)
}

// ⭐ SYNC FORM DATA FROM STAGEFORM: Update prefillData in real-time
const syncFormDataToParent = (formDataUpdate) => {
  if (formDataUpdate) {
    Object.keys(formDataUpdate).forEach(key => {
      if (key in prefillData.value) {
        prefillData.value[key] = formDataUpdate[key]
      }
    })
    console.log('[SupremeCourtDecisionForm] Form data synced:', formDataUpdate)
  }
}

// ⭐ HELPER: Get decision label
const getDecisionLabel = (type) => {
  const labels = {
    'granted': 'Granted (Dikabulkan)',
    'partially_granted': 'Partially Granted (Dikabulkan Sebagian)',
    'rejected': 'Rejected (Ditolak)'
  }
  return labels[type] || type
}

// Computed property: Check if Stage 12 is submitted
const isStage12Submitted = computed(() => {
  if (!caseData.value || !caseData.value.workflow_histories) return false
  
  return caseData.value.workflow_histories.some(
    h => h.stage_id === 12 && (h.status === 'submitted' || h.status === 'approved')
  )
})

// Computed property: Get current decision for display
const currentDecision = computed(() => {
  return prefillData.value.keputusan_pk || 'Not selected'
})

const router = useRouter()
const { showSuccess, showError } = useToast()

// Handle routing to Refund (Stage 13)
const proceedToRefund = async () => {
  try {
    const response = await fetch(`/api/tax-cases/${caseId}/workflow-decision`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
      },
      body: JSON.stringify({
        current_stage_id: 12,
        next_stage_id: 13,
        decision_type: 'refund',
        decision_reason: 'User selected to proceed with Refund path'
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

// Handle routing to KIAN (Stage 16)
const proceedToKian = async () => {
  try {
    const response = await fetch(`/api/tax-cases/${caseId}/workflow-decision`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
      },
      body: JSON.stringify({
        current_stage_id: 12,
        next_stage_id: 16,
        decision_type: 'kian',
        decision_reason: 'User selected to proceed with KIAN path'
      })
    })

    if (response.ok) {
      showSuccess('Workflow locked to KIAN path (Stage 16)')
      // Navigate to Stage 16 (KIAN Report)
      setTimeout(() => {
        router.push(`/tax-cases/${caseId}/workflow/16`)
      }, 1000)
    } else {
      const errorData = await response.json()
      throw new Error(errorData.message || 'Failed to update workflow')
    }
  } catch (error) {
    showError('Error proceeding to KIAN: ' + error.message)
    console.error('Error:', error)
  }
}

onMounted(async () => {
  try {
    const [caseRes] = await Promise.all([
      fetch(`/api/tax-cases/${caseId}`)
    ])

    if (!caseRes.ok) {
      throw new Error('Failed to load case')
    }

    const caseResponse = await caseRes.json()
    const caseFetchedData = caseResponse.data ? caseResponse.data : caseResponse

    if (!caseFetchedData || !caseFetchedData.id) {
      throw new Error('Case data not found')
    }

    // Store full case data
    caseData.value = caseFetchedData

    // Pre-fill dengan existing Supreme Court Decision record jika ada
    const supremeCourtDecision = caseFetchedData.supreme_court_decision || caseFetchedData.supremeCourtDecision
    if (supremeCourtDecision) {
      prefillData.value = {
        decision_number: supremeCourtDecision.decision_number || '',
        decision_date: formatDateForInput(supremeCourtDecision.decision_date),
        decision_type: supremeCourtDecision.decision_type || '',
        decision_amount: supremeCourtDecision.decision_amount || 0,
        decision_notes: supremeCourtDecision.decision_notes || '',
        next_action: supremeCourtDecision.next_action || '',
        workflowHistories: caseFetchedData.workflow_histories || []
      }
    } else {
      prefillData.value.workflowHistories = caseFetchedData.workflow_histories || []
    }

    caseNumber.value = caseFetchedData.case_number || 'N/A'
    caseStatus.value = caseFetchedData.case_status_id
    preFilledMessage.value = `✅ Case: ${caseFetchedData.case_number}`

    // Load revisions
    await loadRevisions()

    // Load documents
    await loadDocuments()

    // Load current user
    try {
      const userRes = await fetch('/api/user')
      if (userRes.ok) {
        const userData = await userRes.json()
        if (userData.data) {
          currentUser.value = userData.data
        } else if (userData.id) {
          currentUser.value = userData
        }
      }
    } catch (err) {
      console.error('Failed to load user:', err)
    }

  } catch (error) {
    preFilledMessage.value = '❌ Error loading case data'
    console.error('Error:', error)
  } finally {
    isLoading.value = false
  }
})

const loadRevisions = async () => {
  try {
    const revisionsData = await listRevisions('tax-cases', caseId)
    revisions.value = revisionsData
  } catch (error) {
    console.error('Failed to load revisions:', error)
  }
}

const loadDocuments = async () => {
  try {
    const docsRes = await fetch(`/api/tax-cases/${caseId}/documents?stage_code=12`)
    if (docsRes.ok) {
      const docsData = await docsRes.json()
      let allDocs = docsData.data || docsData
      
      allDocs = Array.isArray(allDocs) ? allDocs : []
      console.log('📄 [Supreme Court Decision] All documents from API:', allDocs)
      console.log('📄 [Supreme Court Decision] Total docs count:', allDocs.length)
      
      // Filter to stage 12 documents
      const stageDocs = allDocs.filter(doc => doc.stage_code === '12' || doc.stage_code === 12)
      currentDocuments.value = stageDocs
      
      console.log('📄 [Supreme Court Decision] Documents set to currentDocuments:', currentDocuments.value)
    }
  } catch (error) {
    console.error('Failed to load documents:', error)
  }
}

const refreshTaxCase = async () => {
  try {
    const response = await fetch(`/api/tax-cases/${caseId}`)
    if (response.ok) {
      const caseResponse = await response.json()
      const caseFetchedData = caseResponse.data ? caseResponse.data : caseResponse
      caseData.value = caseFetchedData
      
      // Refresh prefill data dari supreme_court_decision terbaru
      const supremeCourtDecision = caseFetchedData.supreme_court_decision || caseFetchedData.supremeCourtDecision
      if (supremeCourtDecision) {
        prefillData.value = {
          decision_number: supremeCourtDecision.decision_number || '',
          decision_date: formatDateForInput(supremeCourtDecision.decision_date),
          decision_type: supremeCourtDecision.decision_type || '',
          decision_amount: supremeCourtDecision.decision_amount || 0,
          decision_notes: supremeCourtDecision.decision_notes || '',
          next_action: supremeCourtDecision.next_action || '',
          workflowHistories: caseFetchedData.workflow_histories || []
        }
      }
      
      await loadRevisions()
      await loadDocuments()
    }
  } catch (error) {
    console.error('Failed to refresh case:', error)
  }
}
</script>
