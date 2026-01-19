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
      :stageName="`Stage 8: Appeal Submission (Surat Banding)`"
      :stageDescription="`Enter appeal submission details to file appeal to tax court`"
      :stageId="8"
      :nextStageId="9"
      :caseId="caseId"
      :caseNumber="caseNumber"
      :fields="fields"
      :apiEndpoint="`/api/tax-cases/${caseId}/workflow/8`"
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
        :stage-id="8"
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
  'appeal_letter_number',
  'submission_date',
  'appeal_amount',
  'dispute_number',
  'supporting_docs'
]

// Phase 1: Required fields (Initial Appeal)
// Phase 2: Optional field (Dispute Number - assigned by court)
const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'appeal_letter_number',
    label: 'Nomor Surat Banding (Appeal Letter Number)',
    required: true,
    readonly: false,
    placeholder: 'e.g., SB/2024/001'
  },
  {
    id: 2,
    type: 'date',
    key: 'submission_date',
    label: 'Tanggal Dilaporkan (Submission Date)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'number',
    key: 'appeal_amount',
    label: 'Nilai (Appeal Amount)',
    required: true,
    readonly: false,
    placeholder: 'e.g., 500000000'
  },
  {
    id: 4,
    type: 'text',
    key: 'dispute_number',
    label: 'Nomor Sengketa (Dispute Number)',
    required: false,
    readonly: false,
    placeholder: 'e.g., 001/BDG/2024'
  }
])

// Pre-fill form data
const prefillData = ref({
  appeal_letter_number: '',
  submission_date: null,
  appeal_amount: 0,
  dispute_number: '',
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

    // Pre-fill dengan existing Appeal Submission record jika ada
    const appealRecord = caseFetchedData.appeal_submission
    if (appealRecord) {
      prefillData.value = {
        appeal_letter_number: appealRecord.appeal_letter_number || '',
        submission_date: formatDateForInput(appealRecord.submission_date),
        appeal_amount: appealRecord.appeal_amount || 0,
        dispute_number: appealRecord.dispute_number || '',
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
    const docsRes = await fetch(`/api/tax-cases/${caseId}/documents?stage_code=8`)
    if (docsRes.ok) {
      const docsData = await docsRes.json()
      let allDocs = docsData.data || docsData
      
      allDocs = Array.isArray(allDocs) ? allDocs : []
      console.log('📄 [APPEAL] All documents from API:', allDocs)
      console.log('📄 [APPEAL] Total docs count:', allDocs.length)
      
      // Filter to stage 8 documents
      const stageDocs = allDocs.filter(doc => doc.stage_code === '8' || doc.stage_code === 8)
      currentDocuments.value = stageDocs
      
      console.log('📄 [APPEAL] Documents set to currentDocuments:', currentDocuments.value)
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
      
      // Refresh prefill data dari appeal_submission terbaru
      const appealRecord = caseFetchedData.appeal_submission
      if (appealRecord) {
        prefillData.value = {
          appeal_letter_number: appealRecord.appeal_letter_number || '',
          submission_date: formatDateForInput(appealRecord.submission_date),
          appeal_amount: appealRecord.appeal_amount || 0,
          dispute_number: appealRecord.dispute_number || '',
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
