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
      :stageName="`Stage 9: Request for Explanation (Permintaan Penjelasan Banding)`"
      :stageDescription="`Tax court requests explanation for appeal; enter request details and optionally submit explanation`"
      :stageId="9"
      :nextStageId="10"
      :caseId="caseId"
      :caseNumber="caseNumber"
      :fields="fields"
      :apiEndpoint="`/api/tax-cases/${caseId}/workflow/9`"
      :isReviewMode="false"
      :isLoading="isLoading"
      :caseStatus="caseStatus"
      :preFilledMessage="preFilledMessage"
      :prefillData="prefillData"
      @submit="refreshTaxCase"
      @saveDraft="refreshTaxCase"
    />

    <!-- REVISION HISTORY PANEL - Integrated -->
    <div class="px-4 py-6">
      <RevisionHistoryPanel 
        :case-id="caseId"
        :stage-id="9"
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
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useRevisionAPI } from '@/composables/useRevisionAPI'
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
  'request_number',
  'request_issue_date',
  'request_receipt_date',
  'explanation_letter_number',
  'explanation_submission_date',
  'supporting_docs'
]

// Phase 1 Fields (Request Receipt - required)
const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'request_number',
    label: 'Nomor Surat Permintaan Penjelasan Banding (Request Number)',
    required: true,
    readonly: false,
    placeholder: 'e.g., SPB/2024/001'
  },
  {
    id: 2,
    type: 'date',
    key: 'request_issue_date',
    label: 'Tanggal Diterbitkan (Issue Date)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'date',
    key: 'request_receipt_date',
    label: 'Tanggal Diterima (Receipt Date)',
    required: true,
    readonly: false
  },
  // Phase 2 Fields (Explanation Submission - optional)
  {
    id: 4,
    type: 'text',
    key: 'explanation_letter_number',
    label: 'Nomor Surat Penjelasan (Explanation Letter Number)',
    required: false,
    readonly: false,
    placeholder: 'e.g., PEN-2024-001'
  },
  {
    id: 5,
    type: 'date',
    key: 'explanation_submission_date',
    label: 'Tanggal Dilaporkan (Submission Date)',
    required: false,
    readonly: false
  }
])

// Pre-fill form data
const prefillData = ref({
  request_number: null,
  request_issue_date: null,
  request_receipt_date: null,
  explanation_letter_number: null,
  explanation_submission_date: null,
  workflowHistories: []
})

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

    // Pre-fill dengan existing Appeal Explanation Request record jika ada
    const explanationRequest = caseFetchedData.appeal_explanation_request
    if (explanationRequest) {
      prefillData.value = {
        request_number: explanationRequest.request_number ?? null,
        request_issue_date: formatDateForInput(explanationRequest.request_issue_date),
        request_receipt_date: formatDateForInput(explanationRequest.request_receipt_date),
        explanation_letter_number: explanationRequest.explanation_letter_number ?? null,
        explanation_submission_date: formatDateForInput(explanationRequest.explanation_submission_date),
        workflowHistories: caseFetchedData.workflow_histories || []
      }
    } else {
      prefillData.value.workflowHistories = caseFetchedData.workflow_histories || []
    }

    caseNumber.value = caseFetchedData.case_number || 'TAX-2026-001'
    caseStatus.value = caseFetchedData.case_status_id || null

    // Load revisions
    try {
      const revisionsRes = await listRevisions(caseId, 9)
      revisions.value = revisionsRes || []
    } catch (err) {
      console.warn('Error loading revisions:', err)
      revisions.value = []
    }

    // Load current user and documents
    try {
      const userRes = await fetch('/api/user')
      if (userRes.ok) {
        const userData = await userRes.json()
        currentUser.value = userData.data || userData
      }

      const docsRes = await fetch(`/api/tax-cases/${caseId}/documents?stage_code=9`)
      if (docsRes.ok) {
        const docsData = await docsRes.json()
        currentDocuments.value = docsData.data || []
      }
    } catch (err) {
      console.warn('Error loading user or documents:', err)
    }

    preFilledMessage.value = ''
    isLoading.value = false
  } catch (error) {
    console.error('Error loading case data:', error)
    preFilledMessage.value = `Error: ${error.message}`
    isLoading.value = false
  }
})

const refreshTaxCase = async () => {
  try {
    isLoading.value = true
    await new Promise(resolve => setTimeout(resolve, 500))
    
    const caseRes = await fetch(`/api/tax-cases/${caseId}`)
    if (caseRes.ok) {
      const caseResponse = await caseRes.json()
      const caseFetchedData = caseResponse.data ? caseResponse.data : caseResponse
      caseData.value = caseFetchedData

      const explanationRequest = caseFetchedData.appeal_explanation_request
      if (explanationRequest) {
        prefillData.value = {
          request_number: explanationRequest.request_number ?? null,
          request_issue_date: formatDateForInput(explanationRequest.request_issue_date),
          request_receipt_date: formatDateForInput(explanationRequest.request_receipt_date),
          explanation_letter_number: explanationRequest.explanation_letter_number ?? null,
          explanation_submission_date: formatDateForInput(explanationRequest.explanation_submission_date),
          workflowHistories: caseFetchedData.workflow_histories || []
        }
      }

      try {
        const revisionsRes = await listRevisions(caseId, 9)
        revisions.value = revisionsRes || []
      } catch (err) {
        console.warn('Error reloading revisions:', err)
      }
    }
  } catch (error) {
    console.error('Error refreshing case data:', error)
  } finally {
    isLoading.value = false
  }
}
</script>
