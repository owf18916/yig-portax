<template>
  <div class="space-y-6 max-w-2xl">
    <div class="flex items-center space-x-4">
      <Button @click="$router.back()" variant="secondary">← Back</Button>
      <h1 class="text-3xl font-bold text-gray-900">Create New CIT Case</h1>
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
        <!-- Company (Read-only from authenticated user) -->
        <div class="p-3 bg-gray-50 rounded-lg border border-gray-200">
          <p class="text-sm text-gray-600">Company</p>
          <p class="text-lg font-bold">{{ currentEntity?.name || 'Loading...' }}</p>
          <p class="text-xs text-gray-500">{{ currentEntity?.code || '' }}</p>
        </div>

        <!-- Fiscal Year Selection (Available March Periods) -->
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2">
            Fiscal Year *
          </label>
          <select 
            v-model.number="formData.fiscal_year" 
            :disabled="loadingAvailablePeriods"
            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent disabled:bg-gray-100 disabled:cursor-not-allowed"
            required
          >
            <option value="">{{ loadingAvailablePeriods ? 'Loading available periods...' : 'Select a fiscal year' }}</option>
            <option v-for="period in availableMarchPeriods" :key="period.id" :value="period.fiscal_year_id">
              {{ period.year }}
            </option>
          </select>
          <p v-if="formErrors.fiscal_year" class="text-sm text-red-600 mt-1">
            {{ formErrors.fiscal_year }}
          </p>
        </div>

        <!-- Currency Selection -->
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2">
            Currency *
          </label>
          <select 
            v-model="formData.currency_id" 
            :disabled="loadingCurrencies"
            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent disabled:bg-gray-100 disabled:cursor-not-allowed"
            required
          >
            <option value="">
              {{ loadingCurrencies ? 'Loading currencies...' : 'Select a currency' }}
            </option>
            <option v-for="currency in currencies" :key="currency.id" :value="currency.id">
              {{ currency.name }} ({{ currency.code }})
            </option>
          </select>
          <p v-if="formErrors.currency_id" class="text-sm text-red-600 mt-1">
            {{ formErrors.currency_id }}
          </p>
        </div>

        <!-- CIT Filing Period (Auto March) -->
        <div class="p-3 bg-blue-50 rounded-lg border border-blue-200">
          <p class="text-sm text-gray-600">CIT Filing Period</p>
          <p class="text-lg font-bold text-blue-700">March (Automatic)</p>
          <p class="text-xs text-gray-500">CIT must be filed in March as closing date</p>
        </div>

        <!-- Disputed Amount -->
        <FormField
          label="Disputed Amount"
          type="number"
          v-model="formData.amount"
          placeholder="e.g., 5000000000"
          required
          :error="formErrors.amount"
        />

        <!-- Case Type Badge -->
        <div class="p-3 bg-blue-50 rounded-lg">
          <p class="text-sm text-gray-600">Case Type</p>
          <span class="inline-block px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
            CIT (Corporate Income Tax)
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

// Reference data from API
const entities = ref([])
const fiscalYears = ref([])
const periods = ref([])  // Store all periods
const availableMarchPeriods = ref([])  // Store available March periods (not used in tax_case)
const currencies = ref([])
const usedPeriodIds = ref([])  // Store period_ids already used in tax_case
const loadingEntities = ref(false)
const loadingFiscalYears = ref(false)
const loadingAvailablePeriods = ref(false)
const loadingCurrencies = ref(false)

// Form state
const selectedEntityId = ref(null)
const currentEntity = computed(() => {
  return entities.value.find(e => e.id === selectedEntityId.value)
})

const currentFiscalYear = computed(() => {
  return fiscalYears.value.find(fy => fy.year === formData.fiscal_year)
})

const formData = reactive({
  fiscal_year: new Date().getFullYear(),
  amount: '',
  currency_id: ''
})

const formErrors = reactive({
  entity_id: '',
  fiscal_year: '',
  amount: '',
  currency_id: ''
})

const previewCaseNumber = computed(() => {
  if (!selectedEntityId.value || !formData.fiscal_year || !formData.amount) {
    return ''
  }
  const entity = currentEntity.value
  if (!entity) return ''
  
  // Find the selected fiscal year period
  const selectedPeriod = availableMarchPeriods.value.find(p => p.fiscal_year_id === formData.fiscal_year)
  if (!selectedPeriod) return ''
  
  // Extract first 2 letters from entity code (uppercase)
  const entityCode = entity.code.substring(0, 2).toUpperCase()
  const yearCode = String(selectedPeriod.year).slice(-2)
  
  return `${entityCode}${yearCode}MarC`
})

onMounted(() => {
  loadCurrentUser()
  fetchPeriods()
  fetchAvailableMarchPeriods()
  fetchCurrencies()
})

const loadCurrentUser = () => {
  loadingEntities.value = true
  try {
    // Get user from localStorage (set during login)
    const userStr = localStorage.getItem('user')
    if (!userStr) {
      throw new Error('User not found in localStorage')
    }
    
    const user = JSON.parse(userStr)
    
    if (user && user.entity_id) {
      selectedEntityId.value = user.entity_id
      
      // Use entity data from user object if available
      if (user.entity) {
        entities.value = [user.entity]
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
    loadingEntities.value = false
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
      entities.value = [entityData]
    } else {
      console.error('Failed to fetch entity:', response.status)
    }
  } catch (error) {
    console.error('Error fetching entity:', error)
  }
}

const fetchAvailableMarchPeriods = async () => {
  loadingAvailablePeriods.value = true
  try {
    // Fetch all periods first
    const periodResponse = await fetch('/api/periods')
    if (!periodResponse.ok) throw new Error('Failed to fetch periods')
    const periodData = await periodResponse.json()
    const allPeriods = Array.isArray(periodData) ? periodData : periodData.data || []
    
    console.log('All periods fetched:', allPeriods.length)
    console.log('Selected entity ID:', selectedEntityId.value)
    
    // Get used periods in tax_case for current entity
    const usedResponse = await fetch(`/api/tax-cases?entity_id=${selectedEntityId.value}&case_type=CIT&limit=1000`)
    console.log('Used Response Status:', usedResponse.status, usedResponse.ok)
    
    if (usedResponse.ok) {
      const usedData = await usedResponse.json()
      console.log('Used Data Raw:', usedData)
      
      let usedCases = []
      if (Array.isArray(usedData)) {
        usedCases = usedData
      } else if (usedData && usedData.data) {
        // Check if usedData.data is pagination object with data property
        if (Array.isArray(usedData.data.data)) {
          console.log('Found usedData.data.data:', usedData.data.data)
          usedCases = usedData.data.data
        } else if (Array.isArray(usedData.data)) {
          console.log('Found usedData.data:', usedData.data)
          usedCases = usedData.data
        }
      } else if (usedData && typeof usedData === 'object') {
        // Try to find data in other common structures
        usedCases = Object.values(usedData).find(val => Array.isArray(val)) || []
      }
      
      console.log('Used Cases (after extraction):', usedCases)
      console.log('Total cases found:', usedCases.length)
      
      usedPeriodIds.value = usedCases
        .filter(c => c && c.case_type === 'CIT')
        .map(c => c.period_id)
        .filter(id => id)
      
      console.log('Filtered to CIT only:', usedCases.filter(c => c && c.case_type === 'CIT'))
      console.log('Used period IDs for CIT:', usedPeriodIds.value)
    } else {
      console.log('No used cases found or error')
    }
    
    // Filter March periods (month = 3) that haven't been used, group by year
    // Also filter out current month and future periods
    const now = new Date()
    const currentYear = now.getFullYear()
    const currentMonth = now.getMonth() + 1 // getMonth() returns 0-11
    
    const marchPeriods = allPeriods.filter(p => {
      const hasBeenUsed = usedPeriodIds.value.includes(p.id)
      const isMarch = p.month === 3
      const isNotFuture = p.year < currentYear || (p.year === currentYear && p.month < currentMonth)
      
      return isMarch && !hasBeenUsed && isNotFuture
    })
    
    console.log('All March periods:', allPeriods.filter(p => p.month === 3))
    console.log('Available March periods (after filter):', marchPeriods)
    
    // Group by fiscal year and get unique years
    const uniqueYearMap = new Map()
    marchPeriods.forEach(p => {
      if (!uniqueYearMap.has(p.fiscal_year_id)) {
        uniqueYearMap.set(p.fiscal_year_id, {
          id: p.id,
          fiscal_year_id: p.fiscal_year_id,
          year: p.year,
          month: p.month
        })
      }
    })
    
    availableMarchPeriods.value = Array.from(uniqueYearMap.values()).sort((a, b) => b.year - a.year)
    console.log('Final available March periods:', availableMarchPeriods.value)
  } catch (error) {
    console.error('Error fetching available March periods:', error)
  } finally {
    loadingAvailablePeriods.value = false
  }
}

const fetchPeriods = async () => {
  try {
    const response = await fetch('/api/periods')
    if (!response.ok) throw new Error('Failed to fetch periods')
    const data = await response.json()
    periods.value = Array.isArray(data) ? data : data.data || []
  } catch (error) {
    console.error('Error fetching periods:', error)
  }
}

const fetchCurrencies = async () => {
  loadingCurrencies.value = true
  try {
    const response = await fetch('/api/currencies')
    if (!response.ok) throw new Error('Failed to fetch currencies')
    const data = await response.json()
    currencies.value = Array.isArray(data) ? data : data.data || []
    
    // Set default currency to USD
    const usdCurrency = currencies.value.find(c => c.code === 'USD')
    if (usdCurrency) {
      formData.currency_id = usdCurrency.id
    }
  } catch (error) {
    console.error('Error fetching currencies:', error)
  } finally {
    loadingCurrencies.value = false
  }
}

const validateForm = async () => {
  formErrors.entity_id = ''
  formErrors.fiscal_year = ''
  formErrors.amount = ''
  formErrors.currency_id = ''
  let isValid = true

  if (!selectedEntityId.value) {
    formErrors.entity_id = 'Company is required'
    isValid = false
  }

  if (!formData.fiscal_year) {
    formErrors.fiscal_year = 'Fiscal year is required'
    isValid = false
  }

  if (!formData.amount || formData.amount <= 0) {
    formErrors.amount = 'Disputed amount must be greater than 0'
    isValid = false
  }

  if (!formData.currency_id) {
    formErrors.currency_id = 'Currency is required'
    isValid = false
  }

  // Check for duplicate case (same entity + same fiscal year + same case type)
  if (isValid && selectedEntityId.value && formData.fiscal_year) {
    try {
      const response = await fetch(`/api/tax-cases?entity_id=${selectedEntityId.value}&fiscal_year=${formData.fiscal_year}&case_type=CIT`, {
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      })
      if (response.ok) {
        const result = await response.json()
        const existingCases = result.data || result
        if (Array.isArray(existingCases) && existingCases.length > 0) {
          apiError.value = 'A CIT case already exists for this company and fiscal year'
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
    const entity = currentEntity.value
    
    // Find the selected March period
    const marchPeriod = availableMarchPeriods.value.find(p => p.fiscal_year_id === formData.fiscal_year)
    
    if (!marchPeriod) {
      throw new Error('March period not found for selected fiscal year')
    }
    
    const yearCode = String(marchPeriod.year).slice(-2)
    
    // Extract first 2 letters from entity code (uppercase)
    const entityCode = entity.code.substring(0, 2).toUpperCase()
    const caseNumber = `${entityCode}${yearCode}MarC`

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
        entity_id: selectedEntityId.value,
        case_number: caseNumber,
        case_type: 'CIT',
        fiscal_year_id: marchPeriod.fiscal_year_id,
        period_id: marchPeriod.id,
        status: 'PENDING',
        disputed_amount: formData.amount,
        currency_id: formData.currency_id
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
    
    successMessage.value = 'CIT case created successfully!'
    
    // Redirect to case detail
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
