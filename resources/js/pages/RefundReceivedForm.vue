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
      :stageName="`Stage 15: Refund Received`"
      :stageDescription="`Confirm receipt of refund from tax authority`"
      :stageId="15"
      :nextStageId="null"
      :caseId="caseId"
      :caseNumber="caseNumber"
      :fields="fields"
      :apiEndpoint="apiEndpoint"
      :isReviewMode="false"
      :isLoading="isLoading"
      :caseStatus="caseStatus"
      :preFilledMessage="preFilledMessage"
      :prefillData="prefillData"
      :showDecisionOptions="false"
      @submit="refreshTaxCase"
      @saveDraft="refreshTaxCase"
      @update:formData="syncFormDataToParent"
    />

    <!-- REVISION HISTORY PANEL - Integrated -->
    <div class="px-4 py-6">
      <RevisionHistoryPanel 
        :case-id="caseId"
        :stage-id="15"
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
import { useRoute } from 'vue-router'
import { useRevisionAPI } from '@/composables/useRevisionAPI'
import StageForm from '../components/StageForm.vue'
import RevisionHistoryPanel from '../components/RevisionHistoryPanel.vue'

const route = useRoute()
const caseId = parseInt(route.params.id, 10)
const refundId = route.params.refundId ? parseInt(route.params.refundId, 10) : null
const { listRevisions } = useRevisionAPI()

// Compute API endpoint based on whether refundId is provided
const apiEndpoint = computed(() => {
  if (refundId) {
    return `/api/tax-cases/${caseId}/refunds/${refundId}/workflow/15`
  }
  return `/api/tax-cases/${caseId}/workflow/15`
})

const caseNumber = ref('TAX-2026-001')
const preFilledMessage = ref('Loading...')
const isLoading = ref(true)
const caseStatus = ref(null)
const caseData = ref({})
const revisions = ref([])
const currentUser = ref(null)
const currentDocuments = ref([])

const availableFields = [
  'receipt_number',
  'processed_date',
  'receipt_date',
  'transfer_amount',
  'notes'
]

const fields = ref([
  {
    id: 1,
    key: 'receipt_number',
    type: 'text',
    name: 'receipt_number',
    label: 'Nomor Bukti Penerimaan Dana',
    required: true,
    placeholder: 'e.g., BPD-2026-001'
  },
  {
    id: 2,
    key: 'processed_date',
    type: 'date',
    name: 'processed_date',
    label: 'Tanggal Transfer Diproses',
    required: true
  },
  {
    id: 3,
    key: 'receipt_date',
    type: 'date',
    name: 'receipt_date',
    label: 'Tanggal Penerimaan Dana',
    required: true
  },
  {
    id: 4,
    key: 'transfer_amount',
    type: 'number',
    name: 'transfer_amount',
    label: 'Jumlah Dana yang Diterima (Rp)',
    required: true,
    placeholder: '0'
  },
  {
    id: 5,
    key: 'notes',
    type: 'textarea',
    name: 'notes',
    label: 'Catatan / Keterangan Tambahan',
    required: false
  }
])

const prefillData = ref({
  receipt_number: '',
  processed_date: '',
  receipt_date: '',
  transfer_amount: null,
  notes: ''
})

const syncFormDataToParent = (formData) => {
  console.log('Form data synced:', formData)
}

const loadCaseData = async () => {
  try {
    const response = await fetch(`/api/tax-cases/${caseId}`)
    if (!response.ok) throw new Error('Failed to load case')
    const responseData = await response.json()
    const data = responseData.data || responseData
    caseData.value = data
    caseNumber.value = data.case_number || 'N/A'
    caseStatus.value = data.status?.name || data.status || 'Draft'
    preFilledMessage.value = '📝 Ready to enter Stage 15 data - Final refund stage'
  } catch (error) {
    console.error('Error loading case:', error)
    preFilledMessage.value = '⚠️ Error loading case data'
  }
}

const loadStageData = async () => {
  try {
    const response = await fetch(apiEndpoint.value)
    if (response.ok) {
      const stageData = await response.json()
      const data = stageData.data || stageData
      if (data) {
        prefillData.value = {
          receipt_number: data.receipt_number || '',
          processed_date: data.processed_date || '',
          receipt_date: data.receipt_date || '',
          transfer_amount: data.transfer_amount || null,
          notes: data.notes || ''
        }
      }
    }
  } catch (error) {
    console.error('No existing stage data found or error loading:', error)
    // This is ok - first time filling the form
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

const loadDocuments = async () => {
  try {
    const docsRes = await fetch(`/api/tax-cases/${caseId}/documents?stage_code=15`)
    if (docsRes.ok) {
      const docsData = await docsRes.json()
      let allDocs = docsData.data || docsData
      allDocs = Array.isArray(allDocs) ? allDocs : []
      currentDocuments.value = allDocs.filter(doc => doc.stage_code === '15' || doc.stage_code === 15)
    }
  } catch (error) {
    console.error('Error loading documents:', error)
  }
}

const loadCurrentUser = async () => {
  try {
    const userRes = await fetch('/api/users/current')
    if (userRes.ok) {
      currentUser.value = await userRes.json()
    }
  } catch (error) {
    console.error('Error loading current user:', error)
  }
}

const refreshTaxCase = async () => {
  try {
    const caseRes = await fetch(`/api/tax-cases/${caseId}`)
    if (caseRes.ok) {
      const caseResponse = await caseRes.json()
      const caseFetchedData = caseResponse.data ? caseResponse.data : caseResponse
      caseData.value = caseFetchedData
      caseNumber.value = caseFetchedData.case_number || 'N/A'
    }
  } catch (error) {
    console.error('Error refreshing case:', error)
  }
}

onMounted(async () => {
  try {
    isLoading.value = true
    await Promise.all([
      loadCaseData(),
      loadStageData(),
      loadRevisions(),
      loadDocuments(),
      loadCurrentUser()
    ])
  } catch (error) {
    preFilledMessage.value = '❌ Error loading form'
    console.error('Mount error:', error)
  } finally {
    isLoading.value = false
  }
})
</script>
