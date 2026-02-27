<template>
  <div class="h-full">
    <!-- Loading Overlay -->
    <div v-if="isLoading" class="fixed inset-0 backdrop-blur-sm bg-white/30 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl p-8 text-center shadow-2xl border border-white/50">
        <div class="animate-spin rounded-full h-12 w-12 border-4 border-blue-200 border-t-blue-500 mx-auto mb-4"></div>
        <p class="text-gray-700 font-medium">Loading form data...</p>
      </div>
    </div>

    <div class="max-w-4xl mx-auto p-6">
      <!-- Header -->
      <div class="mb-6">
        <button @click="$router.back()" class="text-blue-600 hover:text-blue-800 mb-4">← Back to Case</button>
        <h1 class="text-3xl font-bold text-gray-900">{{ title }}</h1>
        <p class="text-gray-600 mt-2">{{ description }}</p>
      </div>

      <!-- Alert -->
      <div v-if="apiError" class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
        <p class="text-red-800 font-medium">Error</p>
        <p class="text-red-700">{{ apiError }}</p>
      </div>

      <!-- Form Card -->
      <div class="bg-white rounded-lg shadow border border-gray-200">
        <form @submit.prevent="submitForm" class="space-y-6 p-6">
          <!-- Case Info -->
          <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <p class="text-sm text-gray-600">Case Number</p>
            <p class="text-lg font-bold text-gray-900">{{ caseNumber }}</p>
          </div>

          <!-- Refund Amount -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Refund Amount *</label>
            <div class="relative">
              <span class="absolute left-3 top-3 text-gray-500">IDR</span>
              <input
                v-model.number="formData.refund_amount"
                type="number"
                step="0.01"
                min="0"
                placeholder="0.00"
                required
                class="w-full pl-12 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                :disabled="isSubmitting"
              />
            </div>
            <p class="text-xs text-gray-500 mt-1">Maximum available: {{ formatCurrency(maxRefundAmount, 'IDR') }}</p>
          </div>

          <!-- Refund Method -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Refund Method *</label>
            <select
              v-model="formData.refund_method"
              required
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
              :disabled="isSubmitting"
            >
              <option value="">-- Select Method --</option>
              <option value="BANK_TRANSFER">Bank Transfer</option>
              <option value="CHEQUE">Cheque</option>
              <option value="CASH">Cash</option>
            </select>
          </div>

          <!-- Refund Date -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Refund Date *</label>
            <input
              v-model="formData.refund_date"
              type="date"
              required
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
              :disabled="isSubmitting"
            />
          </div>

          <!-- Notes -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
            <textarea
              v-model="formData.notes"
              rows="4"
              placeholder="Optional notes about this refund..."
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
              :disabled="isSubmitting"
            />
          </div>

          <!-- Form Actions -->
          <div class="flex gap-3 justify-end pt-6 border-t border-gray-200">
            <button
              type="button"
              @click="$router.back()"
              class="px-6 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg font-semibold"
              :disabled="isSubmitting"
            >
              Cancel
            </button>
            <button
              type="submit"
              class="px-6 py-2 text-white bg-green-600 hover:bg-green-700 rounded-lg font-semibold disabled:opacity-50"
              :disabled="isSubmitting || !formData.refund_amount || !formData.refund_method"
            >
              {{ isSubmitting ? 'Creating...' : 'Create Refund' }}
            </button>
          </div>
        </form>
      </div>

      <!-- Info Section -->
      <div class="mt-8 bg-amber-50 border border-amber-200 rounded-lg p-6">
        <h3 class="font-bold text-amber-900 mb-3">ℹ️ About Preliminary Refund</h3>
        <ul class="space-y-2 text-amber-800 text-sm">
          <li>• This is an independent refund process at Stage 1</li>
          <li>• You can create a refund and still continue to SP2</li>
          <li>• Both actions are completely independent</li>
          <li>• This refund will be tracked separately from the main workflow</li>
        </ul>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from '../composables/useToast'

const route = useRoute()
const router = useRouter()
const { showSuccess, showError } = useToast()

const caseId = parseInt(route.params.id, 10)
const title = 'Create Preliminary Refund'
const description = 'Independent refund process at Stage 1 (Pengembalian Pendahuluan)'

const caseNumber = ref('TAX-2026-001')
const maxRefundAmount = ref(0)
const isLoading = ref(true)
const isSubmitting = ref(false)
const apiError = ref('')

const formData = ref({
  refund_amount: '',
  refund_method: 'BANK_TRANSFER',
  refund_date: new Date().toISOString().split('T')[0],
  notes: ''
})

const formatCurrency = (amount, currencyCode = 'IDR') => {
  const currencyMap = {
    'IDR': { locale: 'id-ID', code: 'IDR' },
    'USD': { locale: 'en-US', code: 'USD' },
    'EUR': { locale: 'de-DE', code: 'EUR' }
  }
  
  const currencyConfig = currencyMap[currencyCode] || currencyMap['IDR']
  
  return new Intl.NumberFormat(currencyConfig.locale, {
    style: 'currency',
    currency: currencyConfig.code,
    minimumFractionDigits: 0
  }).format(amount)
}

const loadCaseData = async () => {
  try {
    const response = await fetch(`/api/tax-cases/${caseId}`, {
      credentials: 'include',
      headers: { 'Accept': 'application/json' }
    })
    
    if (!response.ok) throw new Error('Failed to load case')
    
    const responseData = await response.json()
    const data = responseData.data || responseData
    
    caseNumber.value = data.case_number || 'TAX-2026-001'
    maxRefundAmount.value = data.disputed_amount || 0
  } catch (error) {
    apiError.value = error.message
    console.error('Failed to load case:', error)
  } finally {
    isLoading.value = false
  }
}

const submitForm = async () => {
  try {
    isSubmitting.value = true
    apiError.value = ''
    
    // Validate amount
    const amount = parseFloat(formData.value.refund_amount)
    if (amount <= 0) {
      throw new Error('Refund amount must be greater than 0')
    }
    if (amount > maxRefundAmount.value) {
      throw new Error(`Refund amount cannot exceed ${formatCurrency(maxRefundAmount.value, 'IDR')}`)
    }
    
    const response = await fetch(`/api/tax-cases/${caseId}/refund-processes`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
      },
      body: JSON.stringify({
        stage_id: 0, // PRELIMINARY
        refund_number: `PREL-${Date.now()}`,
        refund_date: formData.value.refund_date,
        refund_method: formData.value.refund_method,
        refund_amount: amount,
        notes: formData.value.notes
      })
    })

    if (!response.ok) {
      const error = await response.json()
      throw new Error(error.message || error.error || `API Error: ${response.status}`)
    }

    showSuccess('Success', 'Preliminary refund created successfully!')
    
    // Go back to case detail
    setTimeout(() => {
      router.push(`/tax-cases/${caseId}`)
    }, 500)
  } catch (error) {
    apiError.value = error.message
    showError('Error', error.message)
  } finally {
    isSubmitting.value = false
  }
}

onMounted(() => {
  loadCaseData()
})
</script>
