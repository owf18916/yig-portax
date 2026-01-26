<template>
  <div class="space-y-6">
    <!-- Toast Notifications -->
    <Toast ref="toastRef" />

    <!-- Close Confirmation Dialog -->
    <ConfirmationDialog
      :is-open="showCloseConfirmation"
      title="Close Tax Case"
      :message="`Are you sure you want to close tax case ${selectedCaseForClose?.case_number}? This action cannot be undone.`"
      confirm-label="Close Case"
      cancel-label="Cancel"
      variant="warning"
      @confirm="confirmCloseCase"
      @cancel="cancelCloseCase"
    />

    <Card title="Tax Cases" subtitle="View and manage your tax cases">
      <!-- Action Buttons -->
      <div class="mb-6 flex justify-end space-x-2">
        <Button @click="exportToExcel" :disabled="isExporting" variant="secondary">
          <span v-if="!isExporting">📥 Export to Excel</span>
          <span v-else>Exporting...</span>
        </Button>
        <Button @click="$router.push('/tax-cases/create/cit')" variant="primary">
          + New CIT Case
        </Button>
        <Button @click="$router.push('/tax-cases/create/vat')" variant="primary">
          + New VAT Case
        </Button>
      </div>

      <LoadingSpinner v-if="loading" message="Loading tax cases..." />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <!-- Header Title Row -->
            <tr class="bg-gradient-to-r from-blue-50 to-blue-100 border-b-2 border-blue-300">
              <th class="px-4 py-3 text-left w-32">
                <div class="font-semibold text-gray-700">Case Number</div>
              </th>
              <th class="px-4 py-3 text-left w-20">
                <div class="font-semibold text-gray-700">Type</div>
              </th>
              <th class="px-4 py-3 text-left flex-1 min-w-max">
                <div class="font-semibold text-gray-700">Entity</div>
              </th>
              <th class="px-4 py-3 text-left w-28">
                <div class="font-semibold text-gray-700">Case Status</div>
              </th>
              <th class="px-4 py-3 text-left w-32">
                <div class="font-semibold text-gray-700">Current Stage</div>
              </th>
              <th class="px-4 py-3 text-left w-28">
                <div class="font-semibold text-gray-700">Stage Status</div>
              </th>
              <th class="px-4 py-3 text-left w-24">
                <div class="font-semibold text-gray-700">Period</div>
              </th>
              <th class="px-4 py-3 text-right w-32">
                <div class="font-semibold text-gray-700">Amount</div>
              </th>
              <th class="px-4 py-3 text-center w-24">
                <div class="font-semibold text-gray-700">Actions</div>
              </th>
            </tr>

            <!-- Filter Row -->
            <tr class="bg-gray-50 border-b">
              <!-- Case Number Filter -->
              <td class="px-4 py-2.5 w-32">
                <input
                  v-model="filterCaseNumber"
                  type="text"
                  placeholder="Filter..."
                  class="w-full px-2.5 py-1.5 text-xs border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition"
                />
              </td>

              <!-- Type Filter -->
              <td class="px-4 py-2.5 w-20">
                <select
                  v-model="filterType"
                  class="w-full px-2.5 py-1.5 text-xs border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition bg-white"
                >
                  <option value="">All</option>
                  <option value="CIT">CIT</option>
                  <option value="VAT">VAT</option>
                </select>
              </td>

              <!-- Entity Filter -->
              <td class="px-4 py-2.5 flex-1 min-w-max">
                <select
                  v-model="filterEntity"
                  class="w-full px-2.5 py-1.5 text-xs border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition bg-white"
                >
                  <option value="">All</option>
                  <option v-for="entity in availableEntities" :key="entity.id" :value="entity.id">
                    {{ entity.name }}
                  </option>
                </select>
              </td>

              <!-- Case Status Filter -->
              <td class="px-4 py-2.5 w-28">
                <select
                  v-model="filterStatus"
                  class="w-full px-2.5 py-1.5 text-xs border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition bg-white"
                >
                  <option value="">All</option>
                  <option value="open">Open</option>
                  <option value="closed">Closed</option>
                </select>
              </td>

              <!-- Current Stage Filter -->
              <td class="px-4 py-2.5 w-32">
                <select
                  v-model="filterCurrentStage"
                  class="w-full px-2.5 py-1.5 text-xs border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition bg-white"
                >
                  <option value="">All</option>
                  <option v-for="stage in availableStages" :key="stage" :value="stage">
                    {{ getStageName(stage) }}
                  </option>
                </select>
              </td>

              <!-- Stage Status Filter -->
              <td class="px-4 py-2.5 w-28">
                <select
                  v-model="filterStageStatus"
                  class="w-full px-2.5 py-1.5 text-xs border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition bg-white"
                >
                  <option value="">All</option>
                  <option value="draft">Draft</option>
                  <option value="submitted">Submitted</option>
                  <option value="approved">Approved</option>
                  <option value="rejected">Rejected</option>
                  <option value="completed">Completed</option>
                </select>
              </td>

              <!-- Period Filter -->
              <td class="px-4 py-2.5 w-24">
                <select
                  v-model="filterPeriod"
                  class="w-full px-2.5 py-1.5 text-xs border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition bg-white"
                >
                  <option value="">All</option>
                  <option v-for="period in availablePeriods" :key="period" :value="period">
                    {{ period }}
                  </option>
                </select>
              </td>

              <!-- Amount Column (Empty) -->
              <td class="px-4 py-2.5 w-32"></td>

              <!-- Actions Column (Empty) -->
              <td class="px-4 py-2.5 w-24"></td>
            </tr>
          </thead>
          <tbody>
            <tr v-for="taxCase in filteredCases" :key="taxCase.id" class="border-b hover:bg-gray-50">
              <td class="px-4 py-2 font-mono text-blue-600">{{ taxCase.case_number }}</td>
              <td class="px-4 py-2">
                <span :class="['px-3 py-1 rounded-full text-xs font-medium', taxCase.case_type === 'VAT' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800']">
                  {{ taxCase.case_type }}
                </span>
              </td>
              <td class="px-4 py-2">{{ taxCase.entity_name }}</td>
              <td class="px-4 py-2">
                <span :class="getCaseStatusClass(taxCase.is_completed)">
                  {{ getCaseStatusLabel(taxCase.is_completed) }}
                </span>
              </td>
              <td class="px-4 py-2">
                <span class="px-2 py-1 bg-indigo-100 text-indigo-800 rounded text-xs font-medium">
                  {{ getStageName(taxCase.current_stage) }}
                </span>
              </td>
              <td class="px-4 py-2">
                <span :class="getStageStatusClass(getStageStatus(taxCase))">
                  {{ formatStatus(getStageStatus(taxCase)) }}
                </span>
              </td>
              <td class="px-4 py-2 text-sm">
                {{ taxCase.period?.period_code || '-' }}
              </td>
              <td class="px-4 py-2 text-right">{{ formatCurrency(taxCase.disputed_amount || 0, taxCase.currency?.code) }}</td>
              <td class="px-4 py-2 text-center">
                <div class="flex items-center justify-center gap-2">
                  <Button
                    @click="$router.push(`/tax-cases/${taxCase.id}`)"
                    variant="secondary"
                  >
                    View
                  </Button>
                  <button
                    v-if="!taxCase.is_completed"
                    @click="openCloseConfirmation(taxCase)"
                    class="px-3 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white font-medium transition-colors duration-200"
                    title="Close this tax case"
                  >
                    ✓
                  </button>
                  <span v-else class="text-xs px-3 py-2 text-gray-500">
                    🔒
                  </span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>

        <div v-if="filteredCases.length === 0" class="text-center py-8 text-gray-500">
          <p>No tax cases found</p>
        </div>
      </div>
    </Card>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import Card from '../components/Card.vue'
import Button from '../components/Button.vue'
import LoadingSpinner from '../components/LoadingSpinner.vue'
import ConfirmationDialog from '../components/ConfirmationDialog.vue'
import Toast from '../components/Toast.vue'

const router = useRouter()

// Stage name mapping
const stageNames = {
  1: 'SPT Filing',
  2: 'SP2',
  3: 'SPHP',
  4: 'SKP',
  5: 'Objection Submission',
  6: 'SPUH',
  7: 'Objection Decision',
  8: 'Appeal Submission',
  9: 'Appeal Explanation',
  10: 'Appeal Decision',
  11: 'Supreme Court Submission',
  12: 'Supreme Court Decision',
  13: 'Bank Transfer Request',
  14: 'Transfer Instruction',
  15: 'Refund Received',
  16: 'KIAN Report'
}

// Search & filter state
const searchQuery = ref('')
const filterCaseNumber = ref('')
const filterType = ref('')
const filterEntity = ref('')
const filterStatus = ref('')
const filterCurrentStage = ref('')
const filterStageStatus = ref('')
const filterPeriod = ref('')

// Toast reference
const toastRef = ref(null)

// Close case confirmation state
const showCloseConfirmation = ref(false)
const selectedCaseForClose = ref(null)
const isClosing = ref(false)

// Data state
const loading = ref(true)
const taxCases = ref([])
const availableEntities = ref([])
const availableStages = computed(() => {
  const stages = new Set()
  taxCases.value.forEach(tc => {
    if (tc.current_stage) stages.add(tc.current_stage)
  })
  return Array.from(stages).sort((a, b) => a - b)
})

const availablePeriods = computed(() => {
  const periods = new Set()
  taxCases.value.forEach(tc => {
    if (tc.period?.period_code) periods.add(tc.period.period_code)
  })
  return Array.from(periods).sort()
})

const filteredCases = computed(() => {
  return taxCases.value.filter(tc => {
    // Search filter
    const matchesSearch = 
      tc.case_number.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
      tc.entity_name.toLowerCase().includes(searchQuery.value.toLowerCase())
    
    // Case number filter
    const matchesCaseNumber = !filterCaseNumber.value || 
      tc.case_number.toLowerCase().includes(filterCaseNumber.value.toLowerCase())
    
    // Type filter
    const matchesType = !filterType.value || tc.case_type === filterType.value
    
    // Entity filter
    const matchesEntity = !filterEntity.value || tc.entity_id == filterEntity.value
    
    // Status filter (based on is_completed field: 0=open, 1=closed)
    const matchesStatus = !filterStatus.value || (
      (filterStatus.value === 'open' && !tc.is_completed) ||
      (filterStatus.value === 'closed' && tc.is_completed)
    )
    
    // Current stage filter
    const matchesCurrentStage = !filterCurrentStage.value || 
      tc.current_stage == filterCurrentStage.value
    
    // Stage status filter
    const matchesStageStatus = !filterStageStatus.value || 
      (tc.stage_status || 'draft') === filterStageStatus.value
    
    // Period filter
    const matchesPeriod = !filterPeriod.value || 
      tc.period?.period_code === filterPeriod.value
    
    return matchesSearch && matchesCaseNumber && matchesType && matchesEntity && 
           matchesStatus && matchesCurrentStage && matchesStageStatus && matchesPeriod
  })
})

const fetchTaxCases = async () => {
  try {
    loading.value = true
    const response = await fetch('/api/tax-cases', {
      credentials: 'include',
      headers: { 'Accept': 'application/json' }
    })
    const responseData = await response.json()
    
    // Handle Laravel pagination response: { success, message, data: { data: [...], ... } }
    if (responseData.data) {
      const apiData = responseData.data
      // If data is pagination object (has 'data' property with array)
      if (apiData.data && Array.isArray(apiData.data)) {
        taxCases.value = apiData.data
      } else if (Array.isArray(apiData)) {
        taxCases.value = apiData
      } else {
        taxCases.value = []
      }
    } else if (Array.isArray(responseData)) {
      taxCases.value = responseData
    } else {
      taxCases.value = []
    }
  } catch (error) {
    console.error('Failed to load tax cases:', error)
    taxCases.value = []
  } finally {
    loading.value = false
  }
}

const fetchEntities = async () => {
  try {
    const response = await fetch('/api/entities', {
      credentials: 'include',
      headers: { 'Accept': 'application/json' }
    })
    const responseData = await response.json()
    
    // Handle response wrapper
    if (responseData.data) {
      const data = responseData.data
      // Use entities as returned by API (server-side filtering by entity_type)
      if (Array.isArray(data)) {
        availableEntities.value = data
      } else if (data.data && Array.isArray(data.data)) {
        availableEntities.value = data.data
      }
    }
  } catch (error) {
    console.error('Failed to load entities:', error)
    availableEntities.value = []
  }
}

onMounted(async () => {
  await Promise.all([
    fetchTaxCases(),
    fetchEntities()
  ])
})

const getStageStatusClass = (stageStatus) => {
  const statusLower = (stageStatus || 'draft').toLowerCase()
  const classes = {
    draft: 'bg-gray-100 text-gray-800 px-2 py-1 rounded text-xs font-medium',
    submitted: 'bg-yellow-100 text-yellow-800 px-2 py-1 rounded text-xs font-medium',
    approved: 'bg-green-100 text-green-800 px-2 py-1 rounded text-xs font-medium',
    rejected: 'bg-red-100 text-red-800 px-2 py-1 rounded text-xs font-medium',
    completed: 'bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs font-medium'
  }
  return classes[statusLower] || classes.draft
}

const getCaseStatusClass = (isCompleted) => {
  if (isCompleted) {
    return 'bg-gray-600 text-white px-3 py-1 rounded-full text-xs font-medium'
  }
  return 'bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-xs font-medium'
}

const getCaseStatusLabel = (isCompleted) => {
  return isCompleted ? 'Closed' : 'Open'
}

const formatStatus = (status) => {
  if (!status) return 'Draft'
  return status.charAt(0).toUpperCase() + status.slice(1).toLowerCase()
}

const formatCurrency = (amount, currencyCode = 'IDR') => {
  // Map currency codes to locale and options
  const currencyMap = {
    'IDR': { locale: 'id-ID', code: 'IDR' },
    'USD': { locale: 'en-US', code: 'USD' },
    'EUR': { locale: 'de-DE', code: 'EUR' },
    'SGD': { locale: 'en-SG', code: 'SGD' }
  }
  
  const currencyConfig = currencyMap[currencyCode] || currencyMap['IDR']
  
  return new Intl.NumberFormat(currencyConfig.locale, {
    style: 'currency',
    currency: currencyConfig.code,
    minimumFractionDigits: 0
  }).format(amount)
}

const getStageName = (stageId) => {
  return stageNames[stageId] || `Stage ${stageId}`
}

const getStageStatus = (taxCase) => {
  // If workflow histories exist, get the latest status for current stage
  if (taxCase.workflow_histories && taxCase.workflow_histories.length > 0) {
    const currentStageHistories = taxCase.workflow_histories.filter(
      wh => wh.stage_id === taxCase.current_stage
    )
    if (currentStageHistories.length > 0) {
      // Get the most recent one (they should be sorted by created_at desc from API)
      return currentStageHistories[0].status || 'draft'
    }
  }
  
  // Default for Stage 1 when just created
  if (taxCase.current_stage === 1 && (!taxCase.workflow_histories || taxCase.workflow_histories.length === 0)) {
    return 'submitted'
  }
  
  return taxCase.stage_status || 'draft'
}

// ============= EXPORT LOGIC =============
const isExporting = ref(false)

// ============= EXPORT TO EXCEL =============
const exportToExcel = async () => {
  // Guard: prevent multiple exports
  if (isExporting.value) {
    console.warn('Export already in progress, ignoring duplicate request')
    return
  }
  
  try {
    isExporting.value = true
    
    // Build query parameters from active filters
    const params = new URLSearchParams()
    
    if (searchQuery.value) {
      params.append('search', searchQuery.value)
    }
    if (filterCaseNumber.value) {
      params.append('case_number', filterCaseNumber.value)
    }
    if (filterType.value) {
      params.append('case_type', filterType.value)
    }
    if (filterEntity.value) {
      params.append('entity_id', filterEntity.value)
    }
    if (filterStatus.value) {
      params.append('case_status', filterStatus.value)
    }
    if (filterCurrentStage.value) {
      params.append('current_stage', filterCurrentStage.value)
    }
    if (filterPeriod.value) {
      params.append('period_id', filterPeriod.value)
    }
    if (filterStageStatus.value) {
      params.append('stage_status', filterStageStatus.value)
    }
    
    const url = `/api/tax-cases/export?${params.toString()}`
    
    const response = await fetch(url, {
      credentials: 'include',
      headers: {
        'Accept': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
      }
    })
    
    if (!response.ok) {
      const error = await response.json()
      throw new Error(error.message || 'Failed to export tax cases')
    }
    
    // Create blob and download
    const blob = await response.blob()
    const downloadUrl = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = downloadUrl
    
    // Extract filename from response header or use default
    const contentDisposition = response.headers.get('content-disposition')
    let filename = 'tax-cases-export.xlsx'
    if (contentDisposition) {
      const filenameMatch = contentDisposition.match(/filename="?([^"]+)"?/)
      if (filenameMatch) {
        filename = filenameMatch[1]
      }
    }
    
    link.setAttribute('download', filename)
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(downloadUrl)
    
  } catch (error) {
    console.error('Export error:', error)
    alert('Error exporting tax cases: ' + error.message)
  } finally {
    isExporting.value = false
  }
}

// ============= CLOSE CASE LOGIC =============
const openCloseConfirmation = (taxCase) => {
  selectedCaseForClose.value = taxCase
  showCloseConfirmation.value = true
}

const cancelCloseCase = () => {
  showCloseConfirmation.value = false
  selectedCaseForClose.value = null
}

const confirmCloseCase = async () => {
  if (!selectedCaseForClose.value) return
  
  isClosing.value = true
  const caseId = selectedCaseForClose.value.id
  const caseNumber = selectedCaseForClose.value.case_number
  
  try {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    
    const response = await fetch(`/api/tax-cases/${caseId}/close`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        ...(csrfToken && { 'X-CSRF-TOKEN': csrfToken })
      },
      credentials: 'include',
      body: JSON.stringify({})
    })
    
    const responseData = await response.json()
    console.log('Close response:', { status: response.status, data: responseData })
    
    if (!response.ok) {
      const errorMessage = responseData.message || responseData.error || 'Failed to close tax case'
      throw new Error(errorMessage)
    }
    
    const result = responseData
    
    // Update the case in the list
    const index = taxCases.value.findIndex(tc => tc.id === caseId)
    if (index !== -1) {
      taxCases.value[index] = result.data
    }
    
    // Show success message using toast
    toastRef.value?.addToast(
      'Success',
      `Tax case ${caseNumber} has been closed successfully`,
      'success',
      3000
    )
    
    // Reset state
    showCloseConfirmation.value = false
    selectedCaseForClose.value = null
    
  } catch (error) {
    console.error('Error closing tax case:', error)
    toastRef.value?.addToast(
      'Error',
      error.message || 'Failed to close tax case',
      'error',
      4000
    )
  } finally {
    isClosing.value = false
  }
}
</script>
