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

        <!-- Fiscal Year Selection -->
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2">
            Fiscal Year *
          </label>
          <select 
            v-model.number="formData.fiscal_year" 
            :disabled="loadingFiscalYears"
            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent disabled:bg-gray-100 disabled:cursor-not-allowed"
            required
          >
            <option v-if="loadingFiscalYears" disabled>Loading fiscal years...</option>
            <option v-for="fy in fiscalYears" :key="fy.id" :value="fy.year">
              {{ fy.year }}
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
const currencies = ref([])
const loadingEntities = ref(false)
const loadingFiscalYears = ref(false)
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
  const yearCode = String(formData.fiscal_year).slice(-2)
  return `${entity.code}${yearCode}MarC`
})

onMounted(() => {
  loadCurrentUser()
  fetchFiscalYears()
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

const fetchFiscalYears = async () => {
  loadingFiscalYears.value = true
  try {
    const response = await fetch('/api/fiscal-years')
    if (!response.ok) throw new Error('Failed to fetch fiscal years')
    const data = await response.json()
    fiscalYears.value = Array.isArray(data) ? data : data.data || []
  } catch (error) {
    console.error('Error fetching fiscal years:', error)
  } finally {
    loadingFiscalYears.value = false
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
    const fiscalYearRecord = currentFiscalYear.value
    
    if (!fiscalYearRecord) {
      throw new Error('Fiscal year data not found')
    }
    
    const yearCode = String(formData.fiscal_year).slice(-2)
    const caseNumber = `${entity.code}${yearCode}MarC`

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
        fiscal_year_id: fiscalYearRecord.id,
        period: `${formData.fiscal_year}-03`,
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
