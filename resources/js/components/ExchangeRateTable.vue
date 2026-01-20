<template>
  <Card title="Exchange Rates" subtitle="Current exchange rates for all active currencies">
    <div class="space-y-4">
      <!-- Setup Rate Button -->
      <div class="mb-4">
        <Button @click="openSetupModal" variant="primary" block>
          <svg class="inline w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          Setup Rate
        </Button>
      </div>

      <!-- Loading State -->
      <div v-if="loading" class="flex items-center justify-center py-8">
        <div class="text-center">
          <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
          <p class="text-gray-600 mt-2">Loading rates...</p>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else-if="exchangeRates.length === 0" class="text-center py-8">
        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <p class="text-gray-500 mt-2">No exchange rates configured yet</p>
      </div>

      <!-- Table -->
      <div v-else class="overflow-x-auto">
        <table class="w-full">
          <thead>
            <tr class="border-b-2 border-gray-200 bg-gray-50">
              <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700">Currency</th>
              <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700">Code</th>
              <th class="px-4 py-3 text-right text-sm font-semibold text-gray-700">Exchange Rate</th>
              <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700">Last Updated</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(rate, index) in exchangeRates"
              :key="rate.id"
              :class="index % 2 === 0 ? 'bg-white' : 'bg-gray-50'"
              class="border-b border-gray-200 hover:bg-blue-50 transition-colors"
            >
              <td class="px-4 py-3">
                <div class="flex items-center gap-2">
                  <span class="text-2xl">{{ rate.symbol }}</span>
                  <span class="text-sm font-medium text-gray-900">{{ rate.name }}</span>
                </div>
              </td>
              <td class="px-4 py-3">
                <span class="inline-block px-2 py-1 bg-blue-100 text-blue-800 text-xs font-semibold rounded">
                  {{ rate.code }}
                </span>
              </td>
              <td class="px-4 py-3 text-right">
                <span class="font-mono font-semibold text-gray-900">
                  {{ formatRate(rate.exchange_rate) }}
                </span>
              </td>
              <td class="px-4 py-3 text-sm text-gray-600">
                <span v-if="rate.last_updated_at">
                  {{ formatDate(rate.last_updated_at) }}
                </span>
                <span v-else class="text-gray-400 italic">Never updated</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Refresh Button -->
      <div class="flex justify-end pt-2">
        <button
          @click="refreshRates"
          :disabled="loading"
          class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200 transition-colors disabled:opacity-50 disabled:cursor-not-allowed font-medium"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          Refresh
        </button>
      </div>

      <!-- Setup Rate Modal -->
      <ExchangeRateModal
        :isOpen="showSetupModal"
        @close="closeSetupModal"
        @success="handleSetupSuccess"
      />
    </div>
  </Card>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'
import Card from './Card.vue'
import Button from './Button.vue'
import ExchangeRateModal from './ExchangeRateModal.vue'
import { useToast } from '../composables/useToast'

const loading = ref(false)
const exchangeRates = ref([])
const showSetupModal = ref(false)
const { showSuccess: showSuccessToast, showError: showErrorToast } = useToast()

onMounted(() => {
  fetchExchangeRates()
})

const fetchExchangeRates = async () => {
  try {
    loading.value = true
    const response = await axios.get('/api/exchange-rates')
    
    if (response.data.success) {
      exchangeRates.value = response.data.data
    }
  } catch (error) {
    console.error('Failed to fetch exchange rates:', error)
    showErrorToast('Failed to load exchange rates')
  } finally {
    loading.value = false
  }
}

const refreshRates = async () => {
  await fetchExchangeRates()
  showSuccessToast('Exchange rates refreshed')
}

const openSetupModal = () => {
  showSetupModal.value = true
}

const closeSetupModal = () => {
  showSetupModal.value = false
}

const handleSetupSuccess = async () => {
  await fetchExchangeRates()
  showSuccessToast('Exchange rates updated successfully')
}

const formatRate = (rate) => {
  // Format with 2 decimal places and add thousands separator
  return Number(rate).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  })
}

const formatDate = (dateString) => {
  if (!dateString) return 'N/A'
  const date = new Date(dateString)
  const options = {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  }
  return date.toLocaleDateString('en-US', options)
}
</script>
