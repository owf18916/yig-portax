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
      :stageName="`Stage ${stageId}: KIAN Submission`"
      :stageDescription="getStageDescription(stageId)"
      :stageId="stageId"
      :nextStageId="null"
      :caseId="caseId"
      :caseNumber="caseNumber"
      :fields="fields"
      :apiEndpoint="`/api/tax-cases/${caseId}/kian-submissions/${stageId}`"
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
        :stage-id="stageId"
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
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useRevisionAPI } from '@/composables/useRevisionAPI'
import StageForm from '../components/StageForm.vue'
import RevisionHistoryPanel from '../components/RevisionHistoryPanel.vue'

const route = useRoute()
const router = useRouter()
const caseId = parseInt(route.params.id, 10)
const stageId = parseInt(route.params.stageId, 10)
const { listRevisions } = useRevisionAPI()

// Helper function to format date for HTML date input (YYYY-MM-DD)
const formatDateForInput = (date) => {
  if (!date) return null
  const d = new Date(date)
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${d.getFullYear()}-${month}-${day}`
}

// Helper to get stage description
const getStageDescription = (stageId) => {
  const descriptions = {
    4: 'Submit KIAN (Keberatan Atas Internalnya) - Internal Loss Recognition for SKP Decision',
    7: 'Submit KIAN (Keberatan Atas Internalnya) - Internal Loss Recognition for Objection Decision',
    10: 'Submit KIAN (Keberatan Atas Internalnya) - Internal Loss Recognition for Appeal Decision',
    12: 'Submit KIAN (Keberatan Atas Internalnya) - Internal Loss Recognition for Supreme Court Decision'
  }
  return descriptions[stageId] || 'Submit KIAN - Internal Loss Recognition Document'
}

// Available fields untuk revisi
const availableFields = [
  'kian_number',
  'submission_date',
  'loss_amount',
  'notes',
  'supporting_docs'
]

// Form fields for all KIAN stages
const fields = ref([
  {
    id: 1,
    key: 'kian_number',
    label: 'Nomor KIAN (KIAN Number)',
    type: 'text',
    placeholder: 'e.g., KIAN-2024-001',
    required: true,
    order: 1
  },
  {
    id: 2,
    key: 'submission_date',
    label: 'Tanggal Pengajuan (Submission Date)',
    type: 'date',
    required: true,
    order: 2
  },
  {
    id: 3,
    key: 'loss_amount',
    label: 'Loss Amount (Pre-calculated)',
    type: 'text',
    readonly: true,
    order: 3
  },
  {
    id: 4,
    key: 'notes',
    label: 'Catatan (Notes)',
    type: 'textarea',
    placeholder: 'Optional notes',
    order: 4
  }
])

const caseNumber = ref('TAX-2026-001')
const preFilledMessage = ref('Loading...')
const isLoading = ref(true)
const caseStatus = ref(null)
const caseData = ref({})
const revisions = ref([])
const currentUser = ref(null)
const currentDocuments = ref([])

// Pre-fill form data
const prefillData = ref({
  kian_number: '',
  submission_date: null,
  loss_amount: '0',
  notes: '',
  workflowHistories: []
})

// Helper to format amount
const formatAmount = (amount) => {
  if (!amount) return '0'
  return Number(amount).toLocaleString('id-ID')
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
    caseNumber.value = caseFetchedData.case_number || 'N/A'
    caseStatus.value = caseFetchedData.case_status_id

    // Get loss amount from kian_status_by_stage
    let lossAmount = 0
    if (caseFetchedData.kian_status_by_stage && caseFetchedData.kian_status_by_stage[stageId]) {
      lossAmount = caseFetchedData.kian_status_by_stage[stageId].lossAmount || 0
    }

    // Try to load existing KIAN submission for this stage
    try {
      const kianRes = await fetch(`/api/tax-cases/${caseId}/kian-submissions/${stageId}`)
      if (kianRes.ok) {
        const kianResult = await kianRes.json()
        console.log('KIAN fetch result:', kianResult)
        
        if (kianResult.data && kianResult.data.id) {
          // Existing KIAN found - populate all fields
          const existing = kianResult.data
          prefillData.value = {
            kian_number: existing.kian_number || '',
            submission_date: formatDateForInput(existing.submission_date) || null,
            loss_amount: formatAmount(existing.loss_amount || existing.kian_amount || lossAmount),
            notes: existing.notes || ''
          }
          preFilledMessage.value = `✅ Existing KIAN found for Stage ${stageId} - Nomor: ${existing.kian_number}`
          console.log('Loaded existing KIAN:', prefillData.value)
        } else {
          // New KIAN submission - use defaults
          prefillData.value = {
            kian_number: '',
            submission_date: null,
            loss_amount: formatAmount(lossAmount),
            notes: ''
          }
          preFilledMessage.value = `📝 New KIAN Submission for Stage ${stageId}`
          console.log('No existing KIAN, using defaults:', prefillData.value)
        }
      } else {
        console.warn('KIAN fetch returned not ok:', kianRes.status)
        prefillData.value = {
          kian_number: '',
          submission_date: null,
          loss_amount: formatAmount(lossAmount),
          notes: ''
        }
        preFilledMessage.value = `📝 New KIAN Submission for Stage ${stageId}`
      }
    } catch (err) {
      console.warn('Could not fetch existing KIAN:', err)
      prefillData.value = {
        kian_number: '',
        submission_date: null,
        loss_amount: formatAmount(lossAmount),
        notes: ''
      }
      preFilledMessage.value = `📝 New KIAN Submission for Stage ${stageId}`
    }

    // Load documents
    await loadDocuments()

    // Load revisions
    await loadRevisions()

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
    const docsRes = await fetch(`/api/tax-cases/${caseId}/documents?stage_code=${stageId}`)
    if (docsRes.ok) {
      const docsData = await docsRes.json()
      let allDocs = docsData.data || docsData
      
      allDocs = Array.isArray(allDocs) ? allDocs : []
      console.log(`📄 [KIAN Stage ${stageId}] All documents from API:`, allDocs)
      console.log(`📄 [KIAN Stage ${stageId}] Total docs count:`, allDocs.length)
      
      // Filter to stage documents
      const stageDocs = allDocs.filter(doc => doc.stage_code === `${stageId}` || doc.stage_code === stageId)
      currentDocuments.value = stageDocs
      
      console.log(`📄 [KIAN Stage ${stageId}] Documents set to currentDocuments:`, currentDocuments.value)
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
      
      // Refresh prefill data dari kian submission terbaru
      try {
        const kianRes = await fetch(`/api/tax-cases/${caseId}/kian-submissions/${stageId}`)
        if (kianRes.ok) {
          const kianResult = await kianRes.json()
          if (kianResult.data && kianResult.data.id) {
            const existing = kianResult.data
            prefillData.value = {
              kian_number: existing.kian_number || '',
              submission_date: formatDateForInput(existing.submission_date) || null,
              loss_amount: formatAmount(existing.loss_amount || existing.kian_amount),
              notes: existing.notes || '',
              workflowHistories: caseFetchedData.workflow_histories || []
            }
          }
        }
      } catch (err) {
        console.warn('Could not refresh existing KIAN:', err)
      }
      
      await loadRevisions()
      await loadDocuments()
    }
  } catch (error) {
    console.error('Failed to refresh case:', error)
  }
}
</script>
