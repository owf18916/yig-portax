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
        <!-- Company (Read-only from user) -->
        <div class="p-3 bg-gray-50 rounded-lg border border-gray-200">
          <p class="text-sm text-gray-600">Company</p>
          <p class="text-lg font-bold">{{ company.name }}</p>
          <p class="text-xs text-gray-500">{{ company.code }}</p>
        </div>

        <!-- Fiscal Year -->
        <FormField
          label="Fiscal Year"
          type="number"
          v-model="formData.fiscal_year"
          placeholder="e.g., 2024"
          required
          :error="formErrors.fiscal_year"
        />

        <!-- CIT Filing Period (Auto March) -->
        <div class="p-3 bg-blue-50 rounded-lg border border-blue-200">
          <p class="text-sm text-gray-600">CIT Filing Period</p>
          <p class="text-lg font-bold text-blue-700">March (Automatic)</p>
          <p class="text-xs text-gray-500">CIT must be filed in March as closing date</p>
        </div>

        <!-- Disputed Amount -->
        <FormField
          label="Disputed Amount (IDR)"
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
            {{ submitting ? 'Creating...' : 'Create Case' }}
          </Button>
          <Button @click="$router.back()" variant="secondary">
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
  name: 'PT. Default Company',
  code: 'PT'
})

const formData = reactive({
  fiscal_year: new Date().getFullYear(),
  amount: ''
})

const formErrors = reactive({
  fiscal_year: '',
  amount: ''
})

const previewCaseNumber = computed(() => {
  if (!formData.fiscal_year || !formData.amount) {
    return ''
  }
  const yearCode = String(formData.fiscal_year).slice(-2)
  return `${company.value.code}${yearCode}MarC`
})

onMounted(() => {
  // TODO: In Phase 3, load authenticated user's company
  // For now, use default
})

const validateForm = () => {
  formErrors.fiscal_year = ''
  formErrors.amount = ''
  let isValid = true

  if (!formData.fiscal_year) {
    formErrors.fiscal_year = 'Fiscal year is required'
    isValid = false
  }

  if (!formData.amount || formData.amount <= 0) {
    formErrors.amount = 'Disputed amount must be greater than 0'
    isValid = false
  }

  return isValid
}

const submitForm = async () => {
  if (!validateForm()) {
    apiError.value = 'Please fix errors and try again'
    return
  }

  submitting.value = true
  apiError.value = ''
  successMessage.value = ''

  try {
    const yearCode = String(formData.fiscal_year).slice(-2)
    const caseNumber = `${company.value.code}${yearCode}MarC`

    const response = await fetch('/api/tax-cases', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        case_number: caseNumber,
        case_type: 'CIT',
        entity_name: company.value.name,
        fiscal_year: formData.fiscal_year,
        period: `${formData.fiscal_year}-03`,
        amount: formData.amount
      })
    })

    if (!response.ok) throw new Error('Failed to create case')
    
    successMessage.value = 'CIT case created successfully!'
    setTimeout(() => router.push('/tax-cases'), 2000)
  } catch (error) {
    apiError.value = error.message || 'Failed to create case'
  } finally {
    submitting.value = false
  }
}
</script>
