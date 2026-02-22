<template>
  <div class="h-full">
    <!-- Loading Overlay -->
    <div v-if="isLoading" class="fixed inset-0 backdrop-blur-sm bg-white/30 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl p-8 text-center shadow-2xl border border-white/50">
        <div class="animate-spin rounded-full h-12 w-12 border-4 border-blue-200 border-t-blue-500 mx-auto mb-4"></div>
        <p class="text-gray-700 font-medium">Loading form data...</p>
      </div>
    </div>

    <!-- ✅ NEW: KIAN Status by Stage - Multiple KIAN per case concept -->
    <div v-if="!isLoading && kianStatusByStage && Object.keys(kianStatusByStage).length > 0" class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-4">
      <div class="flex items-start">
        <div class="flex-1">
          <h3 class="text-sm font-semibold text-blue-900 mb-2">💰 KIAN Opportunities Available</h3>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <!-- Stage 4 KIAN -->
            <div v-if="kianStatusByStage['4']?.needsKian" 
              :class="['p-3 rounded-lg border', kianStatusByStage['4']?.submitted ? 'bg-green-50 border-green-200' : 'bg-white border-blue-200']">
              <div class="flex items-center justify-between">
                <div>
                  <p class="text-xs font-semibold text-blue-900">Stage 4 - SKP Decision</p>
                  <p class="text-xs text-blue-700 mt-1">Loss: Rp {{ formatAmount(kianStatusByStage['4']?.lossAmount) }}</p>
                </div>
                <button v-if="!kianStatusByStage['4']?.submitted"
                  @click="selectStageForKian(4)"
                  class="px-3 py-1 bg-blue-500 text-white text-xs rounded hover:bg-blue-600">
                  Submit KIAN
                </button>
                <span v-else class="px-3 py-1 bg-green-500 text-white text-xs rounded">✓ Submitted</span>
              </div>
            </div>

            <!-- Stage 7 KIAN -->
            <div v-if="kianStatusByStage['7']?.needsKian" 
              :class="['p-3 rounded-lg border', kianStatusByStage['7']?.submitted ? 'bg-green-50 border-green-200' : 'bg-white border-blue-200']">
              <div class="flex items-center justify-between">
                <div>
                  <p class="text-xs font-semibold text-blue-900">Stage 7 - Objection Decision</p>
                  <p class="text-xs text-blue-700 mt-1">Loss: Rp {{ formatAmount(kianStatusByStage['7']?.lossAmount) }}</p>
                </div>
                <button v-if="!kianStatusByStage['7']?.submitted"
                  @click="selectStageForKian(7)"
                  class="px-3 py-1 bg-blue-500 text-white text-xs rounded hover:bg-blue-600">
                  Submit KIAN
                </button>
                <span v-else class="px-3 py-1 bg-green-500 text-white text-xs rounded">✓ Submitted</span>
              </div>
            </div>

            <!-- Stage 10 KIAN -->
            <div v-if="kianStatusByStage['10']?.needsKian" 
              :class="['p-3 rounded-lg border', kianStatusByStage['10']?.submitted ? 'bg-green-50 border-green-200' : 'bg-white border-blue-200']">
              <div class="flex items-center justify-between">
                <div>
                  <p class="text-xs font-semibold text-blue-900">Stage 10 - Appeal Decision</p>
                  <p class="text-xs text-blue-700 mt-1">Loss: Rp {{ formatAmount(kianStatusByStage['10']?.lossAmount) }}</p>
                </div>
                <button v-if="!kianStatusByStage['10']?.submitted"
                  @click="selectStageForKian(10)"
                  class="px-3 py-1 bg-blue-500 text-white text-xs rounded hover:bg-blue-600">
                  Submit KIAN
                </button>
                <span v-else class="px-3 py-1 bg-green-500 text-white text-xs rounded">✓ Submitted</span>
              </div>
            </div>

            <!-- Stage 12 KIAN -->
            <div v-if="kianStatusByStage['12']?.needsKian" 
              :class="['p-3 rounded-lg border', kianStatusByStage['12']?.submitted ? 'bg-green-50 border-green-200' : 'bg-white border-blue-200']">
              <div class="flex items-center justify-between">
                <div>
                  <p class="text-xs font-semibold text-blue-900">Stage 12 - Supreme Court Decision</p>
                  <p class="text-xs text-blue-700 mt-1">Loss: Rp {{ formatAmount(kianStatusByStage['12']?.lossAmount) }}</p>
                </div>
                <button v-if="!kianStatusByStage['12']?.submitted"
                  @click="selectStageForKian(12)"
                  class="px-3 py-1 bg-blue-500 text-white text-xs rounded hover:bg-blue-600">
                  Submit KIAN
                </button>
                <span v-else class="px-3 py-1 bg-green-500 text-white text-xs rounded">✓ Submitted</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ✅ KIAN Submission Form for selected stage -->
    <div v-if="selectedStageForKian" class="bg-white p-6 rounded-lg border" :class="isKianSubmitted ? 'border-green-300 bg-green-50' : 'border-gray-200'">
      <div class="flex items-center justify-between mb-4">
        <p class="text-sm" :class="isKianSubmitted ? 'text-green-700 font-semibold' : 'text-gray-600'">
          {{ isKianSubmitted ? '✅ KIAN Submitted for ' : 'Submitting KIAN for ' }}{{ getStageName(selectedStageForKian) }}
          <span v-if="isKianSubmitted" class="ml-2 text-xs">(Read-only mode)</span>
        </p>
      </div>
      
      <form @submit.prevent="isKianSubmitted ? null : submitKianForStage" class="space-y-4">
        <!-- KIAN Number -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Nomor KIAN</label>
          <input 
            v-model="kianFormData.kian_number"
            type="text"
            placeholder="e.g., KIAN-2024-001"
            required
            :readonly="isKianSubmitted"
            :class="['w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent', isKianSubmitted ? 'bg-gray-50 text-gray-600' : '']"
          />
        </div>

        <!-- Submission Date -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal Pengajuan</label>
          <input 
            v-model="kianFormData.submission_date"
            type="date"
            required
            :readonly="isKianSubmitted"
            :class="['w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent', isKianSubmitted ? 'bg-gray-50 text-gray-600' : '']"
          />
        </div>

        <!-- Loss Amount (Pre-filled, Read-only) -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Loss Amount (Pre-filled from Stage)</label>
          <input 
            :value="'Rp ' + formatAmount(preFilledLossAmount)"
            type="text"
            readonly
            class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-600"
          />
          <p class="text-xs text-gray-500 mt-1">This is calculated automatically from Stage {{ selectedStageForKian }} decision</p>
        </div>

        <!-- Notes -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Catatan</label>
          <textarea 
            v-model="kianFormData.notes"
            placeholder="Optional notes"
            rows="3"
            :readonly="isKianSubmitted"
            :class="['w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent', isKianSubmitted ? 'bg-gray-50 text-gray-600' : '']"
          ></textarea>
        </div>

        <!-- Action Buttons -->
        <div class="flex gap-2">
          <button 
            v-if="!isKianSubmitted"
            type="submit"
            :disabled="isSubmittingKian"
            class="flex-1 px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 disabled:bg-gray-400"
          >
            {{ isSubmittingKian ? 'Submitting...' : 'Submit KIAN' }}
          </button>
          <button 
            v-else
            type="button"
            disabled
            class="flex-1 px-4 py-2 bg-green-500 text-white rounded-lg cursor-not-allowed"
          >
            ✅ KIAN Submitted
          </button>
          <button 
            type="button"
            @click="selectedStageForKian = null; isKianSubmitted = false"
            class="flex-1 px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400"
          >
            Close
          </button>
        </div>
      </form>
    </div>

    <!-- Original StageForm for backward compatibility / general info -->
    <StageForm
      v-if="!selectedStageForKian"
      :stageName="`Stage 16: KIAN Report Submission`"
      :stageDescription="`Submit internal loss recognition document (KIAN) when disputed tax amount cannot be refunded`"
      :stageId="16"
      :nextStageId="null"
      :caseId="caseId"
      :caseNumber="caseNumber"
      :fields="fields"
      :apiEndpoint="`/api/tax-cases/${caseId}/workflow/16`"
      :isReviewMode="false"
      :isLoading="isLoading"
      :caseStatus="caseStatus"
      :preFilledMessage="preFilledMessage"
      :prefillData="prefillData"
      :isTerminalStage="true"
      @submit="refreshTaxCase"
      @saveDraft="refreshTaxCase"
    />

    <!-- REVISION HISTORY PANEL - Integrated -->
    <div class="px-4 py-6">
      <RevisionHistoryPanel 
        :case-id="caseId"
        :stage-id="16"
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
const { listRevisions } = useRevisionAPI()

// Helper function to format date for HTML date input (YYYY-MM-DD)
const formatDateForInput = (date) => {
  if (!date) return null
  const d = new Date(date)
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${d.getFullYear()}-${month}-${day}`
}

// Helper to format amount
const formatAmount = (amount) => {
  if (!amount) return '0'
  return Number(amount).toLocaleString('id-ID')
}

// Helper to get stage name
const getStageName = (stageId) => {
  const names = {
    4: 'Stage 4 - SKP Decision',
    7: 'Stage 7 - Objection Decision',
    10: 'Stage 10 - Appeal Decision',
    12: 'Stage 12 - Supreme Court Decision'
  }
  return names[stageId] || `Stage ${stageId}`
}

const caseNumber = ref('TAX-2026-001')
const preFilledMessage = ref('Loading...')
const isLoading = ref(true)
const caseStatus = ref(null)
const caseData = ref({})
const revisions = ref([])
const currentUser = ref(null)
const currentDocuments = ref([])

// ✅ NEW: KIAN Status by Stage
const kianStatusByStage = ref({})

// ✅ NEW: Selected stage for KIAN submission
const selectedStageForKian = ref(null)
const preFilledLossAmount = ref(0)
const isSubmittingKian = ref(false)
const isKianSubmitted = ref(false)

// ✅ NEW: KIAN form data
const kianFormData = ref({
  kian_number: '',
  submission_date: new Date().toISOString().split('T')[0],
  notes: ''
})

// Available fields untuk revisi
const availableFields = [
  'kian_number',
  'submission_date',
  'kian_amount',
  'approval_date',
  'kian_reason',
  'supporting_docs'
]

const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'kian_number',
    label: 'Nomor KIAN (KIAN Number)',
    required: true,
    readonly: false,
    placeholder: 'e.g., KIAN-2024-001'
  },
  {
    id: 2,
    type: 'date',
    key: 'submission_date',
    label: 'Tanggal Dilaporkan (Report Date)',
    required: true,
    readonly: false
  },
  {
    id: 3,
    type: 'number',
    key: 'kian_amount',
    label: 'Amount (Loss Amount)',
    required: true,
    readonly: false,
    placeholder: 'Enter loss amount in Rp'
  },
  {
    id: 4,
    type: 'date',
    key: 'approval_date',
    label: 'Tanggal Approval (Approval Date)',
    required: true,
    readonly: false
  }
])

// Pre-fill form data
const prefillData = ref({
  kian_number: '',
  submission_date: null,
  kian_amount: 0,
  approval_date: null,
  kian_reason: '',
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

    // ✅ NEW: Load KIAN status by stage
    if (caseFetchedData.kian_status_by_stage) {
      kianStatusByStage.value = caseFetchedData.kian_status_by_stage
    }

    // Pre-fill dengan existing KIAN Submission record jika ada
    const kianSubmission = caseFetchedData.kian_submission
    if (kianSubmission) {
      prefillData.value = {
        kian_number: kianSubmission.kian_number || '',
        submission_date: formatDateForInput(kianSubmission.submission_date),
        kian_amount: kianSubmission.kian_amount || 0,
        approval_date: formatDateForInput(kianSubmission.approved_at) || null,
        kian_reason: kianSubmission.kian_reason || '',
        workflowHistories: caseFetchedData.workflow_histories || []
      }
    } else {
      prefillData.value.workflowHistories = caseFetchedData.workflow_histories || []
    }

    caseNumber.value = caseFetchedData.case_number || 'N/A'
    caseStatus.value = caseFetchedData.case_status_id
    preFilledMessage.value = `✅ Case: ${caseFetchedData.case_number} | Multiple KIAN per Stage`

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

    // ✅ NEW: Auto-select stage if stageId in route params
    const routeStageId = route.params.stageId
    if (routeStageId) {
      const stageId = parseInt(routeStageId, 10)
      if ([4, 7, 10, 12].includes(stageId) && kianStatusByStage.value[stageId]?.needsKian) {
        selectStageForKian(stageId)
      }
    }

  } catch (error) {
    preFilledMessage.value = '❌ Error loading case data'
    console.error('Error:', error)
  } finally {
    isLoading.value = false
  }
})

// ✅ NEW: Select stage and prepare form (fetch existing KIAN if any)
const selectStageForKian = async (stageId) => {
  selectedStageForKian.value = stageId
  preFilledLossAmount.value = kianStatusByStage.value[stageId]?.lossAmount || 0
  
  // Reset form to defaults
  kianFormData.value = {
    kian_number: '',
    submission_date: new Date().toISOString().split('T')[0],
    notes: ''
  }

  // ✅ NEW: Try to fetch existing KIAN submission for this stage
  try {
    const response = await fetch(`/api/tax-cases/${caseId}/kian-submissions/${stageId}`)
    if (response.ok) {
      const result = await response.json()
      
      // If existing KIAN found, pre-fill the form
      if (result.data && result.data.id) {
        const existingKian = result.data
        kianFormData.value = {
          kian_number: existingKian.kian_number || '',
          submission_date: formatDateForInput(existingKian.submission_date) || new Date().toISOString().split('T')[0],
          notes: existingKian.notes || ''
        }
        
        // ✅ Check if KIAN is already submitted/approved → set read-only mode
        if (existingKian.status && ['submitted', 'approved'].includes(existingKian.status)) {
          isKianSubmitted.value = true
        } else {
          isKianSubmitted.value = false
        }
        
        // Indicate that we're editing an existing submission
        console.log(`Loaded existing KIAN submission for Stage ${stageId}:`, existingKian)
      } else {
        console.log(`No existing KIAN submission for Stage ${stageId}, creating new`)
        isKianSubmitted.value = false
      }
    } else {
      console.warn(`Could not fetch KIAN for stage ${stageId}:`, response.status)
      isKianSubmitted.value = false
    }
  } catch (error) {
    console.error(`Error fetching KIAN for stage ${stageId}:`, error)
    isKianSubmitted.value = false
    // Continue anyway - it's not critical if fetch fails
  }
}

// ✅ NEW: Submit KIAN for selected stage
const submitKianForStage = async () => {
  try {
    isSubmittingKian.value = true
    
    const response = await fetch(
      `/api/tax-cases/${caseId}/kian-submissions/${selectedStageForKian.value}`,
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
        },
        body: JSON.stringify({
          stage_id: selectedStageForKian.value,
          kian_number: kianFormData.value.kian_number,
          submission_date: kianFormData.value.submission_date,
          notes: kianFormData.value.notes
        })
      }
    )

    if (!response.ok) {
      const error = await response.json()
      throw new Error(error.message || 'Failed to submit KIAN')
    }

    const result = await response.json()
    console.log('✅ KIAN submitted:', result)

    // Reset form
    selectedStageForKian.value = null
    
    // Refresh case data
    await refreshTaxCase()
  } catch (error) {
    console.error('Error submitting KIAN:', error)
    alert('Error: ' + error.message)
  } finally {
    isSubmittingKian.value = false
  }
}

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
    const docsRes = await fetch(`/api/tax-cases/${caseId}/documents?stage_code=16`)
    if (docsRes.ok) {
      const docsData = await docsRes.json()
      let allDocs = docsData.data || docsData
      
      allDocs = Array.isArray(allDocs) ? allDocs : []
      console.log('📄 [KIAN] All documents from API:', allDocs)
      console.log('📄 [KIAN] Total docs count:', allDocs.length)
      
      // Filter to stage 16 documents
      const stageDocs = allDocs.filter(doc => doc.stage_code === '16' || doc.stage_code === 16)
      currentDocuments.value = stageDocs
      
      console.log('📄 [KIAN] Documents set to currentDocuments:', currentDocuments.value)
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
      
      // ✅ NEW: Refresh KIAN status by stage
      if (caseFetchedData.kian_status_by_stage) {
        kianStatusByStage.value = caseFetchedData.kian_status_by_stage
      }
      
      // Refresh prefill data dari kian_submission terbaru
      const kianSubmission = caseFetchedData.kian_submission
      if (kianSubmission) {
        prefillData.value = {
          kian_number: kianSubmission.kian_number || '',
          submission_date: formatDateForInput(kianSubmission.submission_date),
          kian_amount: kianSubmission.kian_amount || 0,
          approval_date: formatDateForInput(kianSubmission.approved_at) || null,
          kian_reason: kianSubmission.kian_reason || '',
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
