<template>
  <!-- Modal Background with Blur -->
  <div v-if="isOpen" class="fixed inset-0 bg-black/30 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <!-- Modal Container -->
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-96 overflow-y-auto">
      <!-- Modal Header -->
      <div class="sticky top-0 bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-4 flex items-center justify-between border-b border-blue-200">
        <div>
          <h2 class="text-xl font-bold text-white">Setup Exchange Rates</h2>
          <p class="text-blue-100 text-sm mt-1">Update exchange rates for each currency</p>
        </div>
        <button
          @click="onClose"
          class="text-white hover:text-blue-100 transition-colors"
        >
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Modal Content -->
      <div class="p-6 space-y-4">
        <div v-if="loading" class="flex items-center justify-center py-8">
          <div class="text-center">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
            <p class="text-gray-600 mt-2">Loading currencies...</p>
          </div>
        </div>

        <div v-else class="space-y-4">
          <!-- Currency Rate Input Items -->
          <div
            v-for="(currency, index) in currencies"
            :key="currency.id"
            class="p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:bg-blue-50 transition-colors"
          >
            <div class="flex items-end gap-4">
              <!-- Currency Info -->
              <div class="flex-shrink-0">
                <div class="text-2xl font-bold text-blue-600">{{ currency.symbol }}</div>
                <div class="text-sm text-gray-600">{{ currency.code }}</div>
              </div>

              <!-- Currency Name -->
              <div class="flex-1 min-w-0">
                <label :for="`rate-${currency.id}`" class="text-sm font-medium text-gray-700">
                  {{ currency.name }}
                </label>
                <p class="text-xs text-gray-500 mt-1">
                  Current rate: <span class="font-semibold text-gray-700">{{ currency.exchange_rate }}</span>
                </p>
              </div>

              <!-- Rate Input Field -->
              <div class="flex-shrink-0 w-32">
                <div class="relative">
                  <input
                    :id="`rate-${currency.id}`"
                    v-model.number="formData.rates[index].exchange_rate"
                    type="number"
                    step="0.01"
                    min="0.01"
                    placeholder="0.00"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  />
                  <span class="absolute right-3 top-2 text-gray-500 text-sm">{{ currency.symbol }}</span>
                </div>
              </div>
            </div>
          </div>

          <div v-if="currencies.length === 0" class="text-center py-8">
            <p class="text-gray-500">No active currencies found</p>
          </div>
        </div>
      </div>

      <!-- Modal Footer -->
      <div v-if="!loading" class="sticky bottom-0 bg-gray-50 px-6 py-4 border-t border-gray-200 flex gap-3 justify-end">
        <button
          @click="onClose"
          class="px-4 py-2 text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition-colors font-medium"
        >
          Cancel
        </button>
        <button
          @click="handleSubmit"
          :disabled="submitting"
          class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors font-medium disabled:bg-blue-400 disabled:cursor-not-allowed flex items-center gap-2"
        >
          <svg v-if="!submitting" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          <svg v-else class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          {{ submitting ? 'Saving...' : 'Save Rates' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import axios from 'axios'
import { useToast } from '../composables/useToast'

const props = defineProps({
  isOpen: {
    type: Boolean,
    required: true
  }
})

const emit = defineEmits(['close', 'success'])

const loading = ref(false)
const submitting = ref(false)
const currencies = ref([])
const formData = ref({
  rates: []
})

const { showSuccess: showSuccessToast, showError: showErrorToast } = useToast()

// Fetch currencies when modal opens
onMounted(async () => {
  if (props.isOpen) {
    await fetchCurrencies()
  }
})

watch(() => props.isOpen, async (newVal) => {
  if (newVal) {
    await fetchCurrencies()
  }
})

const fetchCurrencies = async () => {
  try {
    loading.value = true
    const response = await axios.get('/api/exchange-rates')
    
    if (response.data.success) {
      currencies.value = response.data.data
      // Initialize form data with current rates
      formData.value.rates = currencies.value.map(currency => ({
        currency_id: currency.id,
        exchange_rate: currency.exchange_rate
      }))
    }
  } catch (error) {
    console.error('Failed to fetch currencies:', error)
    showErrorToast('Error', 'Failed to load currencies. Please try again.')
  } finally {
    loading.value = false
  }
}

const handleSubmit = async () => {
  // Validate that at least one rate is filled
  const hasValidRates = formData.value.rates.some(r => r.exchange_rate > 0)
  
  if (!hasValidRates) {
    showErrorToast('Validation Error', 'Please enter at least one exchange rate')
    return
  }

  try {
    submitting.value = true
    const response = await axios.put('/api/exchange-rates', formData.value)
    
    if (response.data.success) {
      emit('success', response.data.data)
      onClose()
    }
  } catch (error) {
    console.error('Failed to update exchange rates:', error)
    const errorMsg = error.response?.data?.message || 'Failed to update exchange rates'
    showErrorToast('Error', errorMsg)
  } finally {
    submitting.value = false
  }
}

const onClose = () => {
  emit('close')
}
</script>
