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
      :stageName="`Stage 14: Surat Instruksi Transfer`"
      :stageDescription="`Submit bank transfer instruction from tax authority`"
      :stageId="14"
      :nextStageId="15"
      :caseId="caseId"
      :caseNumber="caseNumber"
      :fields="fields"
      :apiEndpoint="`/api/tax-cases/${caseId}/workflow/14`"
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
        :stage-id="14"
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

const caseNumber = ref('TAX-2026-001')
const preFilledMessage = ref('Loading...')
const isLoading = ref(true)
const caseStatus = ref(null)
const caseData = ref({})
const revisions = ref([])
const currentUser = ref(null)
const currentDocuments = ref([])

const availableFields = [
  'instruction_number',
  'transfer_amount',
  'bank_code',
  'bank_name',
  'account_number',
  'account_holder',
  'notes'
]

const fields = ref([
  {
    id: 1,
    key: 'instruction_number',
    type: 'text',
    name: 'instruction_number',
    label: 'Nomor Surat Instruksi Transfer',
    required: true,
    placeholder: 'e.g., SIT-2026-001'
  },
  {
    id: 2,
    key: 'transfer_amount',
    type: 'number',
    name: 'transfer_amount',
    label: 'Jumlah Transfer (Rp)',
    required: true,
    placeholder: '0'
  },
  {
    id: 3,
    key: 'bank_code',
    type: 'text',
    name: 'bank_code',
    label: 'Kode Bank',
    required: true,
    placeholder: 'e.g., BCA, BNI, Mandiri'
  },
  {
    id: 4,
    key: 'bank_name',
    type: 'text',
    name: 'bank_name',
    label: 'Nama Bank',
    required: true
  },
  {
    id: 5,
    key: 'account_number',
    type: 'text',
    name: 'account_number',
    label: 'Nomor Rekening',
    required: true
  },
  {
    id: 6,
    key: 'account_holder',
    type: 'text',
    name: 'account_holder',
    label: 'Nama Pemilik Rekening',
    required: true
  },
  {
    id: 7,
    key: 'notes',
    type: 'textarea',
    name: 'notes',
    label: 'Catatan',
    required: false
  }
])

const prefillData = ref({
  instruction_number: '',
  transfer_amount: null,
  bank_code: '',
  bank_name: '',
  account_number: '',
  account_holder: '',
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
    preFilledMessage.value = '📝 Ready to enter Stage 14 data'
  } catch (error) {
    console.error('Error loading case:', error)
    preFilledMessage.value = '⚠️ Error loading case data'
  }
}

const loadStageData = async () => {
  try {
    const response = await fetch(`/api/tax-cases/${caseId}/workflow/14`)
    if (response.ok) {
      const stageData = await response.json()
      const data = stageData.data || stageData
      if (data) {
        prefillData.value = {
          instruction_number: data.instruction_number || '',
          transfer_amount: data.transfer_amount || null,
          bank_code: data.bank_code || '',
          bank_name: data.bank_name || '',
          account_number: data.account_number || '',
          account_holder: data.account_holder || '',
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
    const docsRes = await fetch(`/api/tax-cases/${caseId}/documents?stage_code=14`)
    if (docsRes.ok) {
      const docsData = await docsRes.json()
      let allDocs = docsData.data || docsData
      allDocs = Array.isArray(allDocs) ? allDocs : []
      currentDocuments.value = allDocs.filter(doc => doc.stage_code === '14' || doc.stage_code === 14)
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
