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
      :nextStageId="2"
      :caseId="caseId"
      :caseNumber="caseNumber"
      :fields="fields"
      :apiEndpoint="`/api/tax-cases/${caseId}/workflow/1`"
      :isReviewMode="true"
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
        :stage-id="1"
        :tax-case="caseData"
        :revisions="revisions"
        :current-user="currentUser"
        :current-documents="currentDocuments"
        :periods-list="periodsList"
        :available-fields="availableFields"
        :fields="fields"
        @revision-requested="loadRevisions"
        @refresh="loadRevisions"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useRevisionAPI } from '@/composables/useRevisionAPI'
import StageForm from '../components/StageForm.vue'
import Alert from '../components/Alert.vue'
import RevisionHistoryPanel from '../components/RevisionHistoryPanel.vue'

const route = useRoute()
const caseId = parseInt(route.params.id, 10)
const { listRevisions } = useRevisionAPI()
const caseNumber = ref('TAX-2026-001')
const originalCaseNumber = ref('') // Store original case number pattern
const preFilledMessage = ref('Loading...')
const isLoading = ref(true)
const caseStatus = ref(null)
const caseData = ref({}) // Store full case data untuk pass ke RevisionHistoryPanel
const revisions = ref([])
const currentUser = ref(null)
const currentDocuments = ref([])
const periodsList = ref([]) // Store periods for reference

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
// LOGIC: Lock jika stage 1 (SPT Filing) sudah di-submit via workflow_history
const isFieldLocked = (fieldName) => {
  // Jika belum ada workflow history untuk stage 1, semua field editable
  if (!prefillData.value.workflowHistories || prefillData.value.workflowHistories.length === 0) {
    return false
  }

  // Cek apakah stage 1 (SPT Filing) sudah submitted
  const stageSubmitted = prefillData.value.workflowHistories.some(
    h => h.stage_id === 1 && (h.status === 'submitted' || h.status === 'approved')
  )
  
  // Jika stage belum submitted, semua field editable
  if (!stageSubmitted) {
    return false
  }

  // Jika tidak ada approved revision, semua field lock
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

// Helper function to generate case number based on period
const generateCaseNumberFromPeriod = (periodId) => {
  if (!periodId || !originalCaseNumber.value) return originalCaseNumber.value

  // Find the selected period from the periods list
  const selectedPeriod = periodsList.value.find(p => p.id === periodId)
  if (!selectedPeriod || !selectedPeriod.year || !selectedPeriod.month) {
    return originalCaseNumber.value
  }

  // Month number to abbreviation mapping
  const monthAbbr = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
  const newMonth = monthAbbr[selectedPeriod.month] || monthAbbr[3] // Default to Mar if invalid

  // Get last 2 digits of year
  const newYear = selectedPeriod.year.toString().slice(-2)

  // Find the pattern in original case number
  // Pattern: PREFIX + YEAR(2digits) + MONTH(3letters) + SUFFIX
  // Example: PASI26MarC -> PREFIX=PASI, YEAR=26, MONTH=Mar, SUFFIX=C
  
  const caseNumStr = originalCaseNumber.value
  
  // Match: alphabets at start, then 2 digits, then 3 letters, then rest
  const regex = /^([A-Z]+)(\d{2})([A-Z][a-z]{2})(.*)$/
  const match = caseNumStr.match(regex)
  
  if (!match) {
    // Fallback if pattern doesn't match
    return originalCaseNumber.value
  }

  const [, prefix, , monthAbbreviation, suffix] = match
  
  // Build new case number: PREFIX + NEW_YEAR + NEW_MONTH + SUFFIX
  const newCaseNumber = `${prefix}${newYear}${newMonth}${suffix}`
  
  return newCaseNumber
}

// Watcher for period_id changes
watch(
  () => prefillData.value.period_id,
  (newPeriodId) => {
    if (newPeriodId && originalCaseNumber.value) {
      const newCaseNum = generateCaseNumberFromPeriod(newPeriodId)
      caseNumber.value = newCaseNum
      console.log('Case number updated from period change:', newCaseNum)
    }
  }
)

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
    
    // Store periods for later reference when period changes
    periodsList.value = periods

    // Handle wrapped response (if API returns {success, data: {...}})
    const caseFetchedData = caseResponse.data ? caseResponse.data : caseResponse

    if (!caseFetchedData || !caseFetchedData.id) {
      throw new Error('Case data not found')
    }

    // Store full case data untuk pass ke RevisionHistoryPanel
    caseData.value = caseFetchedData

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
      entity_name: caseFetchedData.entity_name || '',
      period_id: caseFetchedData.period_id,  // Ini akan di-bind langsung di StageForm
      currency_id: caseFetchedData.currency_id,  // Ini akan di-bind langsung di StageForm
      disputed_amount: caseFetchedData.disputed_amount ? parseFloat(caseFetchedData.disputed_amount) : null,
      workflowHistories: caseFetchedData.workflow_histories || []  // Get workflow history untuk lock logic
    }

    caseNumber.value = caseFetchedData.case_number || 'N/A'
    originalCaseNumber.value = caseFetchedData.case_number || 'N/A' // Store original for period changes
    caseStatus.value = caseFetchedData.case_status_id
    preFilledMessage.value = `✅ Pre-filled from ${caseFetchedData.case_type} case (${caseFetchedData.case_number})`

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
    const revisionsData = await listRevisions('tax-cases', caseId)
    revisions.value = revisionsData
    
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
      submitted_at: caseData.submitted_at || null,
      workflowHistories: caseData.workflow_histories || []
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
    // Fetch only documents for stage 1 (SPT) - use the new endpoint with stage filtering
    const docsRes = await fetch(`/api/tax-cases/${caseId}/documents?stage_code=1`)
    if (docsRes.ok) {
      const docsData = await docsRes.json()
      let stageDocs = docsData.data || docsData
      
      stageDocs = Array.isArray(stageDocs) ? stageDocs : []
      currentDocuments.value = stageDocs
    } else {
      currentDocuments.value = []
    }
  } catch (err) {
    console.error('Failed to load documents:', err)
    currentDocuments.value = []
  }
}
</script>
