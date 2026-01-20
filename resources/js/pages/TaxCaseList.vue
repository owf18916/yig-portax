<template>
  <div class="space-y-6">
    <Card title="Tax Cases" subtitle="View and manage your tax cases">
      <!-- Search Bar -->
      <div class="mb-6">
        <div class="flex justify-between items-center">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search by case number..."
            class="px-4 py-2 border border-gray-300 rounded-lg w-64 focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <div class="flex space-x-2">
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
        </div>
      </div>

      <LoadingSpinner v-if="loading" message="Loading tax cases..." />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-gray-100 border-b">
              <!-- Case Number Column -->
              <th class="px-4 py-3 text-left">
                <div class="font-medium mb-2">Case Number</div>
                <input
                  v-model="filterCaseNumber"
                  type="text"
                  placeholder="Filter..."
                  class="w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
              </th>

              <!-- Type Column -->
              <th class="px-4 py-3 text-left">
                <div class="font-medium mb-2">Type</div>
                <select
                  v-model="filterType"
                  class="w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                  <option value="">All</option>
                  <option value="CIT">CIT</option>
                  <option value="VAT">VAT</option>
                </select>
              </th>

              <!-- Entity Column -->
              <th class="px-4 py-3 text-left">
                <div class="font-medium mb-2">Entity</div>
                <select
                  v-model="filterEntity"
                  class="w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                  <option value="">All</option>
                  <option v-for="entity in availableEntities" :key="entity.id" :value="entity.id">
                    {{ entity.name }}
                  </option>
                </select>
              </th>

              <!-- Case Status Column -->
              <th class="px-4 py-3 text-left">
                <div class="font-medium mb-2">Case Status</div>
                <select
                  v-model="filterStatus"
                  class="w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                  <option value="">All</option>
                  <option value="open">Open</option>
                  <option value="closed">Closed</option>
                </select>
              </th>

              <!-- Current Stage Column -->
              <th class="px-4 py-3 text-left">
                <div class="font-medium mb-2">Current Stage</div>
                <select
                  v-model="filterCurrentStage"
                  class="w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                  <option value="">All</option>
                  <option v-for="stage in availableStages" :key="stage" :value="stage">
                    {{ getStageName(stage) }}
                  </option>
                </select>
              </th>

              <!-- Stage Status Column -->
              <th class="px-4 py-3 text-left">
                <div class="font-medium mb-2">Stage Status</div>
                <select
                  v-model="filterStageStatus"
                  class="w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                  <option value="">All</option>
                  <option value="draft">Draft</option>
                  <option value="submitted">Submitted</option>
                  <option value="approved">Approved</option>
                  <option value="rejected">Rejected</option>
                  <option value="completed">Completed</option>
                </select>
              </th>

              <!-- Period Column -->
              <th class="px-4 py-3 text-left">
                <div class="font-medium mb-2">Period</div>
                <select
                  v-model="filterPeriod"
                  class="w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                  <option value="">All</option>
                  <option v-for="period in availablePeriods" :key="period" :value="period">
                    {{ period }}
                  </option>
                </select>
              </th>

              <!-- Next Action Column -->
              <th class="px-4 py-3 text-left">
                <div class="font-medium">Next Action</div>
              </th>

              <!-- Amount Column -->
              <th class="px-4 py-3 text-right">
                <div class="font-medium">Amount</div>
              </th>

              <!-- Actions Column -->
              <th class="px-4 py-3 text-center">
                <div class="font-medium">Actions</div>
              </th>
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
              <td class="px-4 py-2 text-sm">
                <div class="flex flex-col gap-1">
                  <span v-if="taxCase.next_action" class="text-gray-700">
                    {{ taxCase.next_action.length > 50 ? taxCase.next_action.substring(0, 50) + '...' : taxCase.next_action }}
                  </span>
                  <span v-else class="text-gray-400 italic">-</span>
                  <span v-if="taxCase.next_action_due_date" class="text-xs text-orange-600 font-medium">
                    Due: {{ new Date(taxCase.next_action_due_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) }}
                  </span>
                </div>
              </td>
              <td class="px-4 py-2 text-right">{{ formatCurrency(taxCase.disputed_amount || 0, taxCase.currency?.code) }}</td>
              <td class="px-4 py-2 text-center space-x-2 flex justify-center items-center">
                <Button
                  @click="$router.push(`/tax-cases/${taxCase.id}`)"
                  variant="secondary"
                >
                  View
                </Button>
                <button
                  v-if="!taxCase.is_completed"
                  @click="openNextActionModal(taxCase)"
                  :title="`Edit Next Action${taxCase.next_action ? '' : ' - No action set'}`"
                  class="p-2 text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors duration-150 group relative"
                >
                  <!-- Edit Icon (Pencil) -->
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                  </svg>
                  <!-- Tooltip -->
                  <div class="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 hidden group-hover:block bg-gray-900 text-white text-xs py-1 px-2 rounded whitespace-nowrap z-10">
                    Edit Next Action
                  </div>
                </button>
              </td>
            </tr>
          </tbody>
        </table>

        <div v-if="filteredCases.length === 0" class="text-center py-8 text-gray-500">
          <p>No tax cases found</p>
        </div>
      </div>
    </Card>

    <!-- Next Action Modal -->
    <NextActionModal
      :is-open="isNextActionModalOpen"
      :tax-case-data="selectedTaxCase"
      @save="saveNextAction"
      @cancel="closeNextActionModal"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import Card from '../components/Card.vue'
import Button from '../components/Button.vue'
import LoadingSpinner from '../components/LoadingSpinner.vue'
import NextActionModal from '../components/NextActionModal.vue'

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
    console.log('Tax cases loaded:', taxCases.value)
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
      // Filter only HOLDING type entities
      if (Array.isArray(data)) {
        availableEntities.value = data.filter(e => e.entity_type === 'HOLDING')
      } else if (data.data && Array.isArray(data.data)) {
        availableEntities.value = data.data.filter(e => e.entity_type === 'HOLDING')
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

// ============= NEXT ACTION MODAL LOGIC =============
const isNextActionModalOpen = ref(false)
const selectedTaxCase = ref(null)
const isSavingNextAction = ref(false)

// ============= EXPORT LOGIC =============
const isExporting = ref(false)

const openNextActionModal = (taxCase) => {
  selectedTaxCase.value = taxCase
  isNextActionModalOpen.value = true
}

const closeNextActionModal = () => {
  isNextActionModalOpen.value = false
  selectedTaxCase.value = null
}

const saveNextAction = async (formData) => {
  if (!selectedTaxCase.value) return
  
  try {
    isSavingNextAction.value = true
    
    const response = await fetch(`/api/tax-cases/${selectedTaxCase.value.id}/next-action`, {
      method: 'PUT',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
      },
      body: JSON.stringify(formData)
    })
    
    const result = await response.json()
    
    if (!response.ok) {
      throw new Error(result.message || 'Failed to save next action')
    }
    
    // Update the tax case in the list with new data
    const index = taxCases.value.findIndex(tc => tc.id === selectedTaxCase.value.id)
    if (index !== -1) {
      taxCases.value[index] = {
        ...taxCases.value[index],
        ...formData
      }
    }
    
    closeNextActionModal()
  } catch (error) {
    console.error('Error saving next action:', error)
    alert('Error saving next action: ' + error.message)
  } finally {
    isSavingNextAction.value = false
  }
}

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
    console.log('Export URL:', url)
    console.log('Export params:', Object.fromEntries(params))
    
    const response = await fetch(url, {
      credentials: 'include',
      headers: {
        'Accept': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
      }
    })
    
    console.log('Export response status:', response.status)
    
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
    
    console.log('Export successful:', filename)
    
  } catch (error) {
    console.error('Export error:', error)
    alert('Error exporting tax cases: ' + error.message)
  } finally {
    isExporting.value = false
  }
}
</script>
