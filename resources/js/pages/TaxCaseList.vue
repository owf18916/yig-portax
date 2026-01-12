<template>
  <div class="space-y-6">
    <Card title="Tax Cases" subtitle="View and manage your tax cases">
      <!-- Search and Filters -->
      <div class="mb-6 space-y-4">
        <!-- Search Bar -->
        <div class="flex justify-between items-center">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search by case number..."
            class="px-4 py-2 border border-gray-300 rounded-lg w-64 focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <div class="flex space-x-2">
            <Button @click="$router.push('/tax-cases/create/cit')" variant="primary">
              + New CIT Case
            </Button>
            <Button @click="$router.push('/tax-cases/create/vat')" variant="primary">
              + New VAT Case
            </Button>
          </div>
        </div>

        <!-- Filters -->
        <div class="grid grid-cols-3 gap-4">
          <!-- Type Filter -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Type</label>
            <select
              v-model="filterType"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">All Types</option>
              <option value="CIT">CIT</option>
              <option value="VAT">VAT</option>
            </select>
          </div>

          <!-- Entity Filter -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Entity</label>
            <select
              v-model="filterEntity"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">All Entities</option>
              <option v-for="entity in availableEntities" :key="entity.id" :value="entity.id">
                {{ entity.name }}
              </option>
            </select>
          </div>

          <!-- Status Filter -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
            <select
              v-model="filterStatus"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">All Status</option>
              <option value="DRAFT">Draft</option>
              <option value="OPEN">Open</option>
              <option value="COMPLETED">Completed</option>
            </select>
          </div>
        </div>
      </div>

      <LoadingSpinner v-if="loading" message="Loading tax cases..." />

      <div v-else-if="filteredCases.length === 0" class="text-center py-8 text-gray-500">
        <p>No tax cases found</p>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-100 border-b">
            <tr>
              <th class="px-4 py-2 text-left">Case Number</th>
              <th class="px-4 py-2 text-left">Type</th>
              <th class="px-4 py-2 text-left">Entity Name</th>
              <th class="px-4 py-2 text-left">Status</th>
              <th class="px-4 py-2 text-right">Amount</th>
              <th class="px-4 py-2 text-center">Actions</th>
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
                <span :class="getStatusClass(taxCase.status?.code || 'DRAFT')">
                  {{ formatStatus(taxCase.status?.name || 'Draft') }}
                </span>
              </td>
              <td class="px-4 py-2 text-right">{{ formatCurrency(taxCase.disputed_amount || 0, taxCase.currency?.code) }}</td>
              <td class="px-4 py-2 text-center space-x-2">
                <Button
                  @click="$router.push(`/tax-cases/${taxCase.id}`)"
                  variant="secondary"
                >
                  View
                </Button>
              </td>
            </tr>
          </tbody>
        </table>
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

const router = useRouter()

const searchQuery = ref('')
const filterType = ref('')
const filterEntity = ref('')
const filterStatus = ref('')
const loading = ref(true)
const taxCases = ref([])
const availableEntities = ref([])

const filteredCases = computed(() => {
  return taxCases.value.filter(tc => {
    // Search filter
    const matchesSearch = 
      tc.case_number.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
      tc.entity_name.toLowerCase().includes(searchQuery.value.toLowerCase())
    
    // Type filter
    const matchesType = !filterType.value || tc.case_type === filterType.value
    
    // Entity filter
    const matchesEntity = !filterEntity.value || tc.entity_id == filterEntity.value
    
    // Status filter
    const matchesStatus = !filterStatus.value || (tc.status?.code === filterStatus.value)
    
    return matchesSearch && matchesType && matchesEntity && matchesStatus
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

const getStatusClass = (status) => {
  const statusLower = (status || 'DRAFT').toLowerCase()
  const classes = {
    draft: 'bg-gray-100 text-gray-800 px-3 py-1 rounded-full text-xs font-medium',
    open: 'bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-xs font-medium',
    submitted: 'bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-xs font-medium',
    approved: 'bg-green-100 text-green-800 px-3 py-1 rounded-full text-xs font-medium',
    rejected: 'bg-red-100 text-red-800 px-3 py-1 rounded-full text-xs font-medium',
    completed: 'bg-green-100 text-green-800 px-3 py-1 rounded-full text-xs font-medium',
    closed: 'bg-gray-600 text-white px-3 py-1 rounded-full text-xs font-medium'
  }
  return classes[statusLower] || classes.draft
}

const formatStatus = (status) => {
  if (!status) return 'Draft'
  return status.charAt(0).toUpperCase() + status.slice(1)
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

const createCase = async () => {
  let validationError = false

  if (!newCase.value.entity_name) {
    alert('Please enter entity name')
    return
  }

  if (!newCase.value.fiscal_year) {
    alert('Please enter fiscal year')
    return
  }

  // Validate period format
  if (newCase.value.case_type === 'VAT') {
    // VAT: YYYY-MM format
    if (!newCase.value.period || !/^\d{4}-\d{2}$/.test(newCase.value.period)) {
      alert('VAT period must be in YYYY-MM format (e.g., 2024-03)')
      return
    }
  } else {
    // CIT: Auto March
    if (!newCase.value.fiscal_year) {
      alert('Please enter fiscal year')
      return
    }
    newCase.value.period = `${newCase.value.fiscal_year}-03` // Auto March for CIT
  }

  try {
    // Generate case number: JA17MarV (or JA17MarC)
    const entityCode = newCase.value.entity_name.substring(0, 2).toUpperCase()
    const yearCode = String(newCase.value.fiscal_year).slice(-2)
    
    let monthCode = ''
    if (newCase.value.case_type === 'CIT') {
      monthCode = 'Mar' // Always March for CIT
    } else {
      // Extract month from YYYY-MM format
      const [year, month] = newCase.value.period.split('-')
      const monthNames = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
      monthCode = monthNames[parseInt(month)]
    }
    
    const typeCode = newCase.value.case_type === 'VAT' ? 'V' : 'C'
    const caseNumber = `${entityCode}${yearCode}${monthCode}${typeCode}`

    const response = await fetch('/api/tax-cases', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        case_number: caseNumber,
        case_type: newCase.value.case_type,
        entity_name: newCase.value.entity_name,
        fiscal_year: newCase.value.fiscal_year,
        period: newCase.value.period
      })
    })

    if (response.ok) {
      closeCreateForm()
      // Reload cases
      const data = await fetch('/api/tax-cases').then(r => r.json())
      taxCases.value = data.data || []
    }
  } catch (error) {
    console.error('Failed to create case:', error)
    alert('Failed to create case. Please try again.')
  }
}

const fetchPeriodsForYear = async () => {
  // TODO: In Phase 3, fetch periods from database
  // For now, just validate year
  if (newCase.value.fiscal_year) {
    // Auto-set period to March for CIT
    newCase.value.period = `${newCase.value.fiscal_year}-03`
  }
}

const openCreateForm = (caseType) => {
  newCase.value.case_type = caseType
  showCreateForm.value = true
}

const closeCreateForm = () => {
  showCreateForm.value = false
  newCase.value = {
    case_type: 'CIT',
    entity_name: '',
    fiscal_year: new Date().getFullYear(),
    period: ''
  }
}
</script>
