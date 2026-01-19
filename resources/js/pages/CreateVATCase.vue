<template>
  <div class="space-y-6 max-w-2xl">
    <div class="flex items-center space-x-4">
      <Button @click="$router.back()" variant="secondary">← Back</Button>
      <h1 class="text-3xl font-bold text-gray-900">Create New VAT Case</h1>
    </div>

    <Alert
      v-if="successMessage"
      type="success"
      title="Success"
      :message="successMessage"
    />

    <Alert
      v-if="apiError"
      type="error"
      title="Error"
      :message="apiError"
    />

    <Card title="Case Information" subtitle="Fill in the case details">
      <form @submit.prevent="submitForm" class="space-y-4">
        <!-- Company (Read-only from user) -->
        <div class="p-3 bg-gray-50 rounded-lg border border-gray-200">
          <p class="text-sm text-gray-600">Company</p>
          <p class="text-lg font-bold">{{ company.name }}</p>
          <p class="text-xs text-gray-500">{{ company.code }}</p>
        </div>

        <!-- Year-Month Period (Dropdown) -->
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2">
            Period (YYYY-MM) *
          </label>
          <select 
            v-model="formData.period_id" 
            :disabled="loadingAvailablePeriods"
            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent disabled:bg-gray-100 disabled:cursor-not-allowed"
            required
          >
            <option value="">{{ loadingAvailablePeriods ? 'Loading periods...' : 'Select a period' }}</option>
            <option v-for="period in availableVATPeriods" :key="period.id" :value="period.id">
              {{ formatPeriod(period) }}
            </option>
          </select>
          <p v-if="formErrors.period" class="text-sm text-red-600 mt-1">
            {{ formErrors.period }}
          </p>
        </div>

        <!-- PPN Masukan (Input Tax) -->
        <FormField
          label="PPN Masukan (Input Tax)"
          type="number"
          v-model="formData.ppn_masukan"
          placeholder="e.g., 1000000000"
          required
          :error="formErrors.ppn_masukan"
        />

        <!-- PPN Keluaran (Output Tax) -->
        <FormField
          label="PPN Keluaran (Output Tax)"
          type="number"
          v-model="formData.ppn_keluaran"
          placeholder="e.g., 800000000"
          required
          :error="formErrors.ppn_keluaran"
        />

        <!-- Calculation Display -->
        <div class="p-3 bg-gray-50 rounded-lg border border-gray-200">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <p class="text-xs text-gray-600">Disputed Amount</p>
              <p class="text-lg font-bold text-gray-900">IDR {{ calculateDispute().toLocaleString() }}</p>
            </div>
            <div>
              <p class="text-xs text-gray-600">Direction</p>
              <p class="text-lg font-bold" :class="getDirectionColor()">
                {{ getDirection() }}
              </p>
            </div>
          </div>
        </div>

        <!-- Case Type Badge -->
        <div class="p-3 bg-purple-50 rounded-lg">
          <p class="text-sm text-gray-600">Case Type</p>
          <span class="inline-block px-3 py-1 rounded-full text-sm font-medium bg-purple-100 text-purple-800">
            VAT (Value Added Tax)
          </span>
        </div>

        <!-- Submit -->
        <div class="flex space-x-4 pt-4">
          <Button type="submit" variant="primary" :disabled="submitting">
            <span v-if="submitting" class="inline-block animate-spin mr-2">⏳</span>
            {{ submitting ? 'Creating...' : 'Create Case' }}
          </Button>
          <Button @click="$router.back()" variant="secondary" :disabled="submitting">
            Cancel
          </Button>
        </div>
      </form>
    </Card>

    <Card title="Auto-Generated Case Number" subtitle="This will be generated when you create the case">
      <div class="p-4 bg-gray-50 rounded-lg border border-gray-200 font-mono text-lg">
        {{ previewCaseNumber || '——————' }}
      </div>
      <p class="text-xs text-gray-500 mt-2">Format: [Company Code][Year][Month Code][Type]</p>
    </Card>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import Card from '../components/Card.vue'
import Button from '../components/Button.vue'
import FormField from '../components/FormField.vue'
import Alert from '../components/Alert.vue'

const router = useRouter()

const submitting = ref(false)
const apiError = ref('')
const successMessage = ref('')

const company = ref({
  id: null,
  name: 'Loading...',
  code: ''
})

const periods = ref([])  // Store all periods
const availableVATPeriods = ref([])  // Store available periods (not used in tax_case for VAT)
const usedPeriodIds = ref([])  // Store period_ids already used in tax_case
const loadingCompany = ref(true)
const loadingAvailablePeriods = ref(false)

const formData = reactive({
  period_id: '',
  ppn_masukan: '',
  ppn_keluaran: ''
})

const formErrors = reactive({
  period: '',
  ppn_masukan: '',
  ppn_keluaran: ''
})

const monthCodes = {
  '01': 'Jan', '02': 'Feb', '03': 'Mar', '04': 'Apr', '05': 'May', '06': 'Jun',
  '07': 'Jul', '08': 'Aug', '09': 'Sep', '10': 'Oct', '11': 'Nov', '12': 'Dec'
}

const formatPeriod = (period) => {
  const year = period.year
  const month = String(period.month).padStart(2, '0')
  return `${year}-${month}`
}

const previewCaseNumber = computed(() => {
  if (!formData.period_id || !company.value.code) {
    return ''
  }
  const selectedPeriod = availableVATPeriods.value.find(p => p.id === formData.period_id)
  if (!selectedPeriod) return ''
  
  // Extract first 2 letters from entity code (uppercase)
  const entityCode = company.value.code.substring(0, 2).toUpperCase()
  const yearCode = String(selectedPeriod.year).slice(-2)
  const monthCode = monthCodes[String(selectedPeriod.month).padStart(2, '0')] || ''
  
  return `${entityCode}${yearCode}${monthCode}V`
})

const calculateDispute = () => {
  const masukan = parseFloat(formData.ppn_masukan) || 0
  const keluaran = parseFloat(formData.ppn_keluaran) || 0
  return Math.abs(masukan - keluaran)
}

const getDirection = () => {
  const masukan = parseFloat(formData.ppn_masukan) || 0
  const keluaran = parseFloat(formData.ppn_keluaran) || 0
  // Overpayment (Lebih Bayar) jika PPN Masukan > PPN Keluaran
  // Underpayment (Kurang Bayar) jika PPN Masukan < PPN Keluaran
  return masukan > keluaran ? 'Overpayment' : 'Underpayment'
}

const getDirectionColor = () => {
  const direction = getDirection()
  // Overpayment = green (taxpayer gets refund) / Underpayment = red (taxpayer owes more)
  return direction === 'Overpayment' ? 'text-green-600' : 'text-red-600'
}

const fetchPeriods = async () => {
  loadingAvailablePeriods.value = true
  try {
    // Fetch all periods
    const periodResponse = await fetch('/api/periods')
    if (!periodResponse.ok) throw new Error('Failed to fetch periods')
    const periodData = await periodResponse.json()
    const allPeriods = Array.isArray(periodData) ? periodData : periodData.data || []
    
    // Get used periods in tax_case for current entity
    const usedResponse = await fetch(`/api/tax-cases?entity_id=${company.value.id}&case_type=VAT&limit=1000`)
    if (usedResponse.ok) {
      const usedData = await usedResponse.json()
      let usedCases = []
      if (Array.isArray(usedData)) {
        usedCases = usedData
      } else if (usedData && usedData.data) {
        // Check if usedData.data is pagination object with data property
        if (Array.isArray(usedData.data.data)) {
          usedCases = usedData.data.data
        } else if (Array.isArray(usedData.data)) {
          usedCases = usedData.data
        }
      } else if (usedData && typeof usedData === 'object') {
        // Try to find data in other common structures
        usedCases = Object.values(usedData).find(val => Array.isArray(val)) || []
      }
      
      usedPeriodIds.value = usedCases
        .filter(c => c && c.case_type === 'VAT')
        .map(c => c.period_id)
        .filter(id => id)
    }
    
    // Filter periods that haven't been used for VAT
    // Also filter out current month and future periods
    const now = new Date()
    const currentYear = now.getFullYear()
    const currentMonth = now.getMonth() + 1 // getMonth() returns 0-11
    
    const availablePeriods = allPeriods.filter(p => {
      const hasBeenUsed = usedPeriodIds.value.includes(p.id)
      const isNotFuture = p.year < currentYear || (p.year === currentYear && p.month < currentMonth)
      
      return !hasBeenUsed && isNotFuture
    })
    
    periods.value = allPeriods
    availableVATPeriods.value = availablePeriods.sort((a, b) => {
      if (a.year !== b.year) return b.year - a.year
      return b.month - a.month
    })
  } catch (error) {
    console.error('Error fetching periods:', error)
  } finally {
    loadingAvailablePeriods.value = false
  }
}

onMounted(() => {
  loadCurrentUser()
  fetchPeriods()
})

const loadCurrentUser = () => {
  loadingCompany.value = true
  try {
    // Get user from localStorage (set during login)
    const userStr = localStorage.getItem('user')
    if (!userStr) {
      throw new Error('User not found in localStorage')
    }
    
    const user = JSON.parse(userStr)
    
    if (user && user.entity_id) {
      // Use entity data from user object if available
      if (user.entity) {
        company.value = {
          id: user.entity.id,
          name: user.entity.name,
          code: user.entity.code
        }
      } else {
        // Otherwise fetch entity details
        fetchEntityDetails(user.entity_id)
      }
    } else {
      console.warn('No entity_id found in user:', user)
      apiError.value = 'User does not have an assigned company'
    }
  } catch (error) {
    apiError.value = error.message || 'Failed to load user company'
    console.error('Error loading current user:', error)
  } finally {
    loadingCompany.value = false
  }
}

const fetchEntityDetails = async (entityId) => {
  try {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    
    const response = await fetch(`/api/entities/${entityId}`, {
      credentials: 'include',
      headers: {
        'Accept': 'application/json',
        ...(csrfToken && { 'X-CSRF-TOKEN': csrfToken })
      }
    })
    
    if (response.ok) {
      const result = await response.json()
      const entityData = result.data || result
      company.value = {
        id: entityData.id,
        name: entityData.name,
        code: entityData.code
      }
    } else {
      console.error('Failed to fetch entity:', response.status)
    }
  } catch (error) {
    console.error('Error fetching entity:', error)
  }
}

const validateForm = async () => {
  formErrors.period = ''
  formErrors.ppn_masukan = ''
  formErrors.ppn_keluaran = ''
  let isValid = true

  if (!formData.period_id) {
    formErrors.period = 'Period is required'
    isValid = false
  }

  if (!formData.ppn_masukan || parseFloat(formData.ppn_masukan) <= 0) {
    formErrors.ppn_masukan = 'PPN Masukan must be greater than 0'
    isValid = false
  }

  if (!formData.ppn_keluaran || parseFloat(formData.ppn_keluaran) < 0) {
    formErrors.ppn_keluaran = 'PPN Keluaran cannot be negative'
    isValid = false
  }

  // Check for duplicate case (same entity + same period + same case type)
  if (isValid && company.value.id && formData.period_id) {
    try {
      const response = await fetch(`/api/tax-cases?entity_id=${company.value.id}&period_id=${formData.period_id}&case_type=VAT`, {
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      })
      if (response.ok) {
        const result = await response.json()
        const existingCases = result.data || result
        if (Array.isArray(existingCases) && existingCases.length > 0) {
          apiError.value = 'A VAT case already exists for this company and period'
          isValid = false
        }
      }
    } catch (error) {
      console.error('Error checking duplicate:', error)
    }
  }

  return isValid
}

const submitForm = async () => {
  if (!(await validateForm())) {
    apiError.value = 'Please fix errors and try again'
    return
  }

  submitting.value = true
  apiError.value = ''
  successMessage.value = ''
  
  // Show loading cursor
  document.body.style.cursor = 'wait'

  try {
    // Get the selected period details
    const selectedPeriod = availableVATPeriods.value.find(p => p.id === formData.period_id)
    
    if (!selectedPeriod) {
      throw new Error('Selected period not found')
    }
    
    const year = selectedPeriod.year
    const month = String(selectedPeriod.month).padStart(2, '0')
    const yearCode = String(year).slice(-2)
    const monthCode = monthCodes[month]
    
    // Extract first 2 letters from entity code (uppercase)
    const entityCode = company.value.code.substring(0, 2).toUpperCase()
    const caseNumber = `${entityCode}${yearCode}${monthCode}V`

    // Get CSRF token from meta tag
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')

    const response = await fetch('/api/tax-cases', {
      method: 'POST',
      headers: { 
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        ...(csrfToken && { 'X-CSRF-TOKEN': csrfToken })
      },
      credentials: 'include',
      body: JSON.stringify({
        entity_id: company.value.id,
        case_number: caseNumber,
        case_type: 'VAT',
        fiscal_year_id: selectedPeriod.fiscal_year_id,  // CHANGED: use fiscal_year_id dari period
        period_id: selectedPeriod.id,  // CHANGED: kirim period_id, bukan period string
        ppn_masukan: parseFloat(formData.ppn_masukan),
        ppn_keluaran: parseFloat(formData.ppn_keluaran),
        disputed_amount: calculateDispute()
      })
    })

    if (!response.ok) {
      const errorData = await response.json()
      throw new Error(errorData.message || 'Failed to create case')
    }
    
    const result = await response.json()
    console.log('Create case response:', result)
    
    const caseId = result.id || result.data?.id
    if (!caseId) {
      throw new Error('No case ID returned from server')
    }
    
    successMessage.value = 'VAT case created successfully!'
    setTimeout(() => {
      document.body.style.cursor = 'auto'
      router.push(`/tax-cases/${caseId}`)
    }, 1000)
  } catch (error) {
    apiError.value = error.message || 'Failed to create case'
    console.error('Error creating case:', error)
    document.body.style.cursor = 'auto'
  } finally {
    submitting.value = false
  }
}
</script>
