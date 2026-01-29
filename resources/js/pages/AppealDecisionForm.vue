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
      :stageName="`Stage 10: Keputusan Banding (Appeal Decision)`"
      :stageDescription="`Enter Appeal Decision (Keputusan Banding) details from the court`"
      :stageId="10"
      :nextStageId="11"
      :caseId="caseId"
      :caseNumber="caseNumber"
      :fields="fields"
      :apiEndpoint="`/api/tax-cases/${caseId}/workflow/10`"
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
        :stage-id="10"
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
  'user_routing_choice',
  'supporting_docs',
  'create_refund',
  'continue_to_next_stage'
]

const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'decision_number',
    label: 'Nomor Surat Keputusan Banding (Appeal Decision Number)',
    required: true,
    readonly: false
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
      { value: 'granted', label: 'Dikabulkan (Granted)' },
      { value: 'partially_granted', label: 'Dikabulkan Sebagian (Partially Granted)' },
      { value: 'rejected', label: 'Ditolak (Rejected)' }
    ]
  },
  {
    id: 4,
    type: 'number',
    key: 'decision_amount',
    label: 'Nilai Keputusan (Decision Amount)',
    required: true,
    readonly: false
  },
  {
    id: 5,
    type: 'textarea',
    key: 'decision_notes',
    label: 'Catatan Keputusan (Decision Notes)',
    required: false,
    readonly: false
  }
])

// Pre-fill form data
const prefillData = ref({
  decision_number: '',
  decision_date: null,
  decision_type: '',
  decision_amount: 0,
  decision_notes: '',
  next_stage: null,
  user_routing_choice: '',
  workflowHistories: []
})

// ⭐ SYNC FORM DATA FROM STAGEFORM: Update prefillData in real-time
const syncFormDataToParent = (formDataUpdate) => {
  if (formDataUpdate) {
    Object.keys(formDataUpdate).forEach(key => {
      if (key in prefillData.value) {
        prefillData.value[key] = formDataUpdate[key]
      }
    })
    console.log('[AppealDecisionForm] Form data synced:', formDataUpdate)
  }
}

// ⭐ HELPER: Get Decision type label
const getDecisionLabel = (decision) => {
  const labels = {
    'dikabulkan': 'Dikabulkan (Granted)',
    'dikabulkan_sebagian': 'Dikabulkan Sebagian (Partially Granted)',
    'ditolak': 'Ditolak (Rejected)'
  }
  return labels[decision] || decision
}

// Computed property: Check if Stage 10 is submitted
const isStage10Submitted = computed(() => {
  if (!caseData.value || !caseData.value.workflow_histories) return false
  
  return caseData.value.workflow_histories.some(
    h => h.stage_id === 10 && (h.status === 'submitted' || h.status === 'approved')
  )
})

// Computed property: Get current decision for display
const currentDecision = computed(() => {
  return prefillData.value.keputusan_banding || 'Not selected'
})

const router = useRouter()
const { showSuccess, showError } = useToast()

// Handle routing to Supreme Court (Stage 11) - Lock workflow to Supreme Court path
const proceedToSupremeCourt = async () => {
  try {
    const response = await fetch(`/api/tax-cases/${caseId}/workflow-decision`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
      },
      body: JSON.stringify({
        current_stage_id: 10,
        next_stage_id: 11,
        decision_type: 'supreme_court',
        decision_reason: 'User selected to proceed to Supreme Court (Peninjauan Kembali)'
      })
    })

    if (response.ok) {
      showSuccess('Workflow locked to Supreme Court path (Stage 11)')
      // Navigate to Stage 11 (Peninjauan Kembali)
      setTimeout(() => {
        router.push(`/tax-cases/${caseId}/workflow/11`)
      }, 1000)
    } else {
      const errorData = await response.json()
      throw new Error(errorData.message || 'Failed to update workflow')
    }
  } catch (error) {
    showError('Error proceeding to Supreme Court: ' + error.message)
    console.error('Error:', error)
  }
}

// Handle routing to Refund (Stage 13) - Lock workflow to Refund path
const proceedToRefund = async () => {
  try {
    const response = await fetch(`/api/tax-cases/${caseId}/workflow-decision`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
      },
      body: JSON.stringify({
        current_stage_id: 10,
        next_stage_id: 13,
        decision_type: 'refund',
        decision_reason: `User selected to proceed with Refund path (Decision: ${prefillData.value.keputusan_banding})`
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

    // Pre-fill dengan existing Appeal Decision record jika ada
    const appealDecision = caseFetchedData.appeal_decision
    if (appealDecision) {
      prefillData.value = {
        decision_number: appealDecision.decision_number || '',
        decision_date: formatDateForInput(appealDecision.decision_date),
        decision_type: appealDecision.decision_type || '',
        decision_amount: appealDecision.decision_amount || 0,
        decision_notes: appealDecision.decision_notes || '',
        next_stage: appealDecision.next_stage || null,
        create_refund: appealDecision.create_refund ?? false,
        continue_to_next_stage: appealDecision.continue_to_next_stage ?? false,
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
    const docsRes = await fetch(`/api/tax-cases/${caseId}/documents?stage_code=10`)
    if (docsRes.ok) {
      const docsData = await docsRes.json()
      let allDocs = docsData.data || docsData
      
      allDocs = Array.isArray(allDocs) ? allDocs : []
      console.log('📄 [Appeal Decision] All documents from API:', allDocs)
      console.log('📄 [Appeal Decision] Total docs count:', allDocs.length)
      
      // Filter to stage 10 documents
      const stageDocs = allDocs.filter(doc => doc.stage_code === '10' || doc.stage_code === 10)
      currentDocuments.value = stageDocs
      
      console.log('📄 [Appeal Decision] Documents set to currentDocuments:', currentDocuments.value)
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
      
      // Refresh prefill data dari appeal_decision terbaru
      const appealDecision = caseFetchedData.appeal_decision
      if (appealDecision) {
        prefillData.value = {
          decision_number: appealDecision.decision_number || '',
          decision_date: formatDateForInput(appealDecision.decision_date),
          decision_type: appealDecision.decision_type || '',
          decision_amount: appealDecision.decision_amount || 0,
          decision_notes: appealDecision.decision_notes || '',
          next_stage: appealDecision.next_stage || null,
          create_refund: appealDecision.create_refund ?? false,
          continue_to_next_stage: appealDecision.continue_to_next_stage ?? false,
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
