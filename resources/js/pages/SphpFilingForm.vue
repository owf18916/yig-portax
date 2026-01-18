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
      :stageName="`Stage 3: SPHP Filing (Audit Findings)`"
      :stageDescription="`Enter SPHP (Audit Findings Notification) details received from tax authority`"
      :stageId="3"
      :nextStageId="4"
      :caseId="caseId"
      :caseNumber="caseNumber"
      :fields="fields"
      :apiEndpoint="`/api/tax-cases/${caseId}/workflow/3`"
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
        :stage-id="3"
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
  'sphp_number',
  'sphp_issue_date',
  'sphp_receipt_date',
  'royalty_finding',
  'service_finding',
  'other_finding',
  'other_finding_notes',
  'supporting_docs'
]

const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'sphp_number',
    label: 'Nomor SPHP (SPHP Number)',
    required: true,
    readonly: false
  },
  {
    id: 2,
    type: 'date',
    key: 'sphp_issue_date',
    label: 'Tanggal Diterbitkan (Issue Date)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'date',
    key: 'sphp_receipt_date',
    label: 'Tanggal Diterima (Receipt Date)',
    required: true,
    readonly: false
  },
  {
    id: 4,
    type: 'number',
    key: 'royalty_finding',
    label: 'Royalty Finding Amount',
    required: false,
    readonly: false
  },
  {
    id: 5,
    type: 'number',
    key: 'service_finding',
    label: 'Service Finding Amount',
    required: false,
    readonly: false
  },
  {
    id: 6,
    type: 'number',
    key: 'other_finding',
    label: 'Other Finding Amount',
    required: false,
    readonly: false
  },
  {
    id: 7,
    type: 'textarea',
    key: 'other_finding_notes',
    label: 'Notes for Other Findings',
    required: false,
    readonly: false
  }
])

// Pre-fill form data
const prefillData = ref({
  sphp_number: '',
  sphp_issue_date: null,
  sphp_receipt_date: null,
  royalty_finding: 0,
  service_finding: 0,
  other_finding: 0,
  other_finding_notes: '',
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

    // Pre-fill dengan existing SPHP record jika ada
    const sphpRecord = caseFetchedData.sphp_record
    if (sphpRecord) {
      prefillData.value = {
        sphp_number: sphpRecord.sphp_number || '',
        sphp_issue_date: formatDateForInput(sphpRecord.sphp_issue_date),
        sphp_receipt_date: formatDateForInput(sphpRecord.sphp_receipt_date),
        royalty_finding: sphpRecord.royalty_finding || 0,
        service_finding: sphpRecord.service_finding || 0,
        other_finding: sphpRecord.other_finding || 0,
        other_finding_notes: sphpRecord.other_finding_notes || '',
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
    const docsRes = await fetch(`/api/tax-cases/${caseId}/documents?stage_code=3`)
    if (docsRes.ok) {
      const docsData = await docsRes.json()
      let allDocs = docsData.data || docsData
      
      allDocs = Array.isArray(allDocs) ? allDocs : []
      console.log('📄 [SPHP] All documents from API:', allDocs)
      console.log('📄 [SPHP] Total docs count:', allDocs.length)
      
      // Filter to stage 3 documents
      const stageDocs = allDocs.filter(doc => doc.stage_code === '3' || doc.stage_code === 3)
      currentDocuments.value = stageDocs
      
      console.log('📄 [SPHP] Documents set to currentDocuments:', currentDocuments.value)
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
      
      // Refresh prefill data dari sphp_record terbaru
      const sphpRecord = caseFetchedData.sphp_record
      if (sphpRecord) {
        prefillData.value = {
          sphp_number: sphpRecord.sphp_number || '',
          sphp_issue_date: formatDateForInput(sphpRecord.sphp_issue_date),
          sphp_receipt_date: formatDateForInput(sphpRecord.sphp_receipt_date),
          royalty_finding: sphpRecord.royalty_finding || 0,
          service_finding: sphpRecord.service_finding || 0,
          other_finding: sphpRecord.other_finding || 0,
          other_finding_notes: sphpRecord.other_finding_notes || '',
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
