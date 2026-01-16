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
    />

    <!-- REVISION HISTORY PANEL - Integrated -->
    <div class="px-4 py-6">
      <RevisionHistoryPanel 
        :case-id="caseId"
        :tax-case="{ submitted_at: prefillData.submitted_at }"
        :revisions="revisions"
        :current-user="currentUser"
        :current-documents="currentDocuments"
        :available-fields="availableFields"
        @revision-requested="loadRevisions"
        @refresh="loadRevisions"
      />
    </div>
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
const currentDocuments = ref([])

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

// Available fields untuk revisi - hanya field yang bisa dirubah setelah submission
const availableFields = [
  'period_id',
  'currency_id',
  'disputed_amount',
  'supporting_docs'
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
  disputed_amount: null,
  submitted_at: null
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
      disputed_amount: caseData.disputed_amount ? parseFloat(caseData.disputed_amount) : null,
      submitted_at: caseData.submitted_at || null  // Track submission status for revisions
    }

    caseNumber.value = caseData.case_number || 'N/A'
    caseStatus.value = caseData.case_status_id
    preFilledMessage.value = `✅ Pre-filled from ${caseData.case_type} case (${caseData.case_number})`

    // Load revisions untuk form ini
    await loadRevisions()

    // Load documents
    await loadDocuments()

    // Load current user info
    try {
      const userRes = await fetch('/api/user')
      if (userRes.ok) {
        const userData = await userRes.json()
        console.log('User data from /api/user:', userData)
        // Load user with role
        if (userData.data) {
          currentUser.value = userData.data
        } else if (userData.id) {
          currentUser.value = userData
        }
        console.log('Current user after assignment:', currentUser.value)
        console.log('User role:', currentUser.value?.role)
        // If role not loaded, try to fetch from user endpoint with role
        if (!currentUser.value?.role) {
          console.log('Role not loaded, trying /api/users/' + currentUser.value?.id)
          const detailRes = await fetch(`/api/users/${currentUser.value?.id}`)
          if (detailRes.ok) {
            currentUser.value = await detailRes.json()
            console.log('User after detail fetch:', currentUser.value)
          }
        }
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
      revisions.value = []
      return
    }
    
    const data = await response.json()
    revisions.value = data.data || data || []
    
    // Also refresh tax case data to update fields if revision was approved
    await refreshTaxCase()
  } catch (err) {
    console.error('Failed to load revisions:', err)
    revisions.value = []
  }
}

// Refresh tax case data after approval
const refreshTaxCase = async () => {
  try {
    const caseRes = await fetch(`/api/tax-cases/${caseId}`)
    
    if (!caseRes.ok) {
      console.warn(`Failed to refresh tax case: ${caseRes.status}`)
      return
    }
    
    const caseResponse = await caseRes.json()
    const caseData = caseResponse.data ? caseResponse.data : caseResponse
    
    // Update prefilled data dengan nilai terbaru
    prefillData.value = {
      entity_name: caseData.entity_name || '',
      period_id: caseData.period_id,
      currency_id: caseData.currency_id,
      disputed_amount: caseData.disputed_amount ? parseFloat(caseData.disputed_amount) : null,
      submitted_at: caseData.submitted_at || null
    }
    
    caseNumber.value = caseData.case_number || 'N/A'
    caseStatus.value = caseData.case_status_id
  } catch (err) {
    console.error('Failed to refresh tax case:', err)
  }
}

// Load documents untuk case ini
const loadDocuments = async () => {
  try {
    // Load all documents (all statuses) for revision history display
    const response = await fetch(`/api/documents?tax_case_id=${caseId}&status=DRAFT,ACTIVE,ARCHIVED,DELETED`)
    
    if (!response.ok) {
      console.warn(`Failed to load documents: ${response.status}`)
      currentDocuments.value = []
      return
    }
    
    const data = await response.json()
    currentDocuments.value = (data.data || data || []).map(doc => ({
      id: doc.id,
      name: doc.original_filename,
      file_name: doc.original_filename,
      original_filename: doc.original_filename,
      size: doc.file_size ? (doc.file_size / 1024 / 1024).toFixed(2) : 'unknown'
    }))
  } catch (err) {
    console.error('Failed to load documents:', err)
    currentDocuments.value = []
  }
}
</script>
