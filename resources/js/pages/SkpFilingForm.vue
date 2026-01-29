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
      :stageName="`Stage 4: SKP Filing (Tax Assessment Notification)`"
      :stageDescription="`Enter SKP (Tax Assessment Notification) details received from tax authority`"
      :stageId="4"
      :nextStageId="5"
      :caseId="caseId"
      :caseNumber="caseNumber"
      :fields="fields"
      :apiEndpoint="`/api/tax-cases/${caseId}/workflow/4`"
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
        :stage-id="4"
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
import DecisionActions from '../components/DecisionActions.vue'

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
const showDecisionOptions = ref(false)

// Available fields untuk revisi
const availableFields = [
  'skp_number',
  'issue_date',
  'receipt_date',
  'skp_type',
  'skp_amount',
  'royalty_correction',
  'service_correction',
  'other_correction',
  'correction_notes',
  'supporting_docs',
  'create_refund',
  'continue_to_next_stage'
]

const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'skp_number',
    label: 'Nomor SKP (SKP Number)',
    required: true,
    readonly: false
  },
  {
    id: 2,
    type: 'date',
    key: 'issue_date',
    label: 'Tanggal Diterbitkan (Issue Date)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'date',
    key: 'receipt_date',
    label: 'Tanggal Diterima (Receipt Date)',
    required: true,
    readonly: false
  },
  {
    id: 4,
    type: 'select',
    key: 'skp_type',
    label: 'Jenis SKP (SKP Type)',
    required: true,
    readonly: false,
    options: [
      { value: 'LB', label: 'SKP LB (Lebih Bayar - Overpayment)' },
      { value: 'NIHIL', label: 'NIHIL (Zero)' },
      { value: 'KB', label: 'SKP KB (Kurang Bayar - Underpayment)' }
    ]
  },
  {
    id: 5,
    type: 'number',
    key: 'skp_amount',
    label: 'Nilai SKP (SKP Amount)',
    required: true,
    readonly: false
  },
  {
    id: 6,
    type: 'number',
    key: 'royalty_correction',
    label: 'Royalty Correction Amount',
    required: false,
    readonly: false
  },
  {
    id: 7,
    type: 'number',
    key: 'service_correction',
    label: 'Service Correction Amount',
    required: false,
    readonly: false
  },
  {
    id: 8,
    type: 'number',
    key: 'other_correction',
    label: 'Other Correction Amount',
    required: false,
    readonly: false
  },
  {
    id: 9,
    type: 'textarea',
    key: 'correction_notes',
    label: 'Catatan untuk koreksi Other (Notes for Other Corrections)',
    required: false,
    readonly: false
  }
])

// Pre-fill form data
const prefillData = ref({
  skp_number: '',
  issue_date: null,
  receipt_date: null,
  skp_type: '',
  skp_amount: 0,
  royalty_correction: 0,
  service_correction: 0,
  other_correction: 0,
  correction_notes: '',
  user_routing_choice: '',
  create_refund: false,
  refund_amount: 0,
  continue_to_next_stage: false,
  workflowHistories: []
})

// ⭐ DECISION ACTIONS STATE
const decisionActions = ref({
  createRefund: false,
  refundAmount: 0,
  continueToNextStage: false
})

// ⭐ WATCHER: Show decision options INSTANTLY when skp_type is selected
watch(() => prefillData.value.skp_type, (newType) => {
  if (newType) {
    showDecisionOptions.value = true
  }
})

// ⭐ HANDLE DECISION ACTIONS: Store in prefillData
const handleDecisionActionsChange = (actions) => {
  decisionActions.value = actions
  prefillData.value.create_refund = actions.createRefund
  prefillData.value.refund_amount = actions.refundAmount
  prefillData.value.continue_to_next_stage = actions.continueToNextStage
  console.log('[SkpFilingForm] Decision actions updated:', actions)
}

// ⭐ COMPUTED: Available refund amount (disputed - skp_amount)
const availableRefundAmount = computed(() => {
  if (!caseData.value) return 0
  const disputed = caseData.value.disputed_amount || 0
  const skpAmount = prefillData.value.skp_amount || 0
  return Math.max(0, disputed - skpAmount)
})

// ⭐ SYNC FORM DATA FROM STAGEFORM: Update prefillData in real-time
const syncFormDataToParent = (formDataUpdate) => {
  if (formDataUpdate) {
    Object.keys(formDataUpdate).forEach(key => {
      if (key in prefillData.value) {
        prefillData.value[key] = formDataUpdate[key]
      }
    })
    console.log('[SkpFilingForm] Form data synced:', formDataUpdate)
  }
}

// ⭐ HELPER: Get SKP type label
const getSkpTypeLabel = (type) => {
  const labels = {
    'LB': 'SKP LB (Lebih Bayar - Overpayment)',
    'NIHIL': 'SKP NIHIL (Zero)',
    'KB': 'SKP KB (Kurang Bayar - Underpayment)'
  }
  return labels[type] || type
}

// Computed property: Check if Stage 4 is submitted
const isStage4Submitted = computed(() => {
  if (!caseData.value || !caseData.value.workflow_histories) return false
  
  return caseData.value.workflow_histories.some(
    h => h.stage_id === 4 && (h.status === 'submitted' || h.status === 'approved')
  )
})

// Computed property: Check if Refund button should show (only for SKP type = LB)
const showRefundButton = computed(() => {
  if (!isStage4Submitted.value) return false
  const skpType = prefillData.value.skp_type
  return skpType === 'LB'
})

// Computed property: Get current SKP type for display
const currentSkpType = computed(() => {
  return prefillData.value.skp_type || 'Not selected'
})

const router = useRouter()
const { showSuccess, showError } = useToast()

// Handle routing to Objection (Stage 5) - Lock workflow to Objection path
const proceedToObjection = async () => {
  try {
    // Update workflow history with stage_to=5 to lock the path
    // Also update current_stage to 5
    const response = await fetch(`/api/tax-cases/${caseId}/workflow-decision`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
      },
      body: JSON.stringify({
        current_stage_id: 4,
        next_stage_id: 5,
        decision_type: 'objection',
        decision_reason: 'User selected to proceed with Objection path'
      })
    })

    if (response.ok) {
      showSuccess('Workflow locked to Objection path (Stage 5)')
      // Navigate to Stage 5 (Surat Keberatan)
      setTimeout(() => {
        router.push(`/tax-cases/${caseId}/workflow/5`)
      }, 1000)
    } else {
      const errorData = await response.json()
      throw new Error(errorData.message || 'Failed to update workflow')
    }
  } catch (error) {
    showError('Error proceeding to Objection: ' + error.message)
    console.error('Error:', error)
  }
}

// Handle routing to Refund (Stage 13) - Lock workflow to Refund path
const proceedToRefund = async () => {
  try {
    // Update workflow history with stage_to=13 to lock the path
    // Also update current_stage to 13
    const response = await fetch(`/api/tax-cases/${caseId}/workflow-decision`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
      },
      body: JSON.stringify({
        current_stage_id: 4,
        next_stage_id: 13,
        decision_type: 'refund',
        decision_reason: 'User selected to proceed with Refund path (SKP type: LB)'
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

    // Pre-fill dengan existing SKP record jika ada
    const skpRecord = caseFetchedData.skp_record
    if (skpRecord) {
      prefillData.value = {
        skp_number: skpRecord.skp_number || '',
        issue_date: formatDateForInput(skpRecord.issue_date),
        receipt_date: formatDateForInput(skpRecord.receipt_date),
        skp_type: skpRecord.skp_type || '',
        skp_amount: skpRecord.skp_amount || 0,
        royalty_correction: skpRecord.royalty_correction || 0,
        service_correction: skpRecord.service_correction || 0,
        other_correction: skpRecord.other_correction || 0,
        correction_notes: skpRecord.correction_notes || '',
        user_routing_choice: skpRecord.user_routing_choice || '',
        create_refund: skpRecord.create_refund ?? false,
        continue_to_next_stage: skpRecord.continue_to_next_stage ?? false,
        workflowHistories: caseFetchedData.workflow_histories || []
      }
      // Also update decisionActions to reflect loaded data
      decisionActions.value = {
        createRefund: skpRecord.create_refund ?? false,
        refundAmount: 0,
        continueToNextStage: skpRecord.continue_to_next_stage ?? false
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
    const docsRes = await fetch(`/api/tax-cases/${caseId}/documents?stage_code=4`)
    if (docsRes.ok) {
      const docsData = await docsRes.json()
      let allDocs = docsData.data || docsData
      
      allDocs = Array.isArray(allDocs) ? allDocs : []
      console.log('📄 [SKP] All documents from API:', allDocs)
      console.log('📄 [SKP] Total docs count:', allDocs.length)
      
      // Filter to stage 4 documents
      const stageDocs = allDocs.filter(doc => doc.stage_code === '4' || doc.stage_code === 4)
      currentDocuments.value = stageDocs
      
      console.log('📄 [SKP] Documents set to currentDocuments:', currentDocuments.value)
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
      
      // Refresh prefill data dari skp_record terbaru
      const skpRecord = caseFetchedData.skp_record
      if (skpRecord) {
        prefillData.value = {
          skp_number: skpRecord.skp_number || '',
          issue_date: formatDateForInput(skpRecord.issue_date),
          receipt_date: formatDateForInput(skpRecord.receipt_date),
          skp_type: skpRecord.skp_type || '',
          skp_amount: skpRecord.skp_amount || 0,
          royalty_correction: skpRecord.royalty_correction || 0,
          service_correction: skpRecord.service_correction || 0,
          other_correction: skpRecord.other_correction || 0,
          correction_notes: skpRecord.correction_notes || '',
          user_routing_choice: skpRecord.user_routing_choice || '',
          create_refund: skpRecord.create_refund ?? false,
          continue_to_next_stage: skpRecord.continue_to_next_stage ?? false,
          workflowHistories: caseFetchedData.workflow_histories || []
        }
        // Also update decisionActions to reflect loaded data
        decisionActions.value = {
          createRefund: skpRecord.create_refund ?? false,
          refundAmount: 0,
          continueToNextStage: skpRecord.continue_to_next_stage ?? false
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
