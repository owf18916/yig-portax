<template>
  <div class="h-full">
    <!-- Loading Overlay -->
    <div v-if="isLoading" class="fixed inset-0 backdrop-blur-sm bg-white/30 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl p-8 text-center shadow-2xl border border-white/50">
        <div class="animate-spin rounded-full h-12 w-12 border-4 border-blue-200 border-t-blue-500 mx-auto mb-4"></div>
        <p class="text-gray-700 font-medium">Processing...</p>
      </div>
    </div>

    <div class="max-w-4xl mx-auto p-6">
      <!-- Header -->
      <div class="mb-6">
        <button @click="$router.back()" class="text-blue-600 hover:text-blue-800 mb-4 flex items-center gap-1">
          <span>←</span> Back
        </button>
        <h1 class="text-3xl font-bold text-gray-900">Refund Stage 1/4</h1>
        <p class="text-gray-600 mt-2">
          {{ isReadOnly ? `View refund details (Stage ${currentRefundStage})` : (isEditingExisting ? 'Continue refund process' : 'Create new refund process') }}
        </p>
      </div>

      <!-- Stage Progress Bar -->
      <div class="mb-8">
        <div class="flex justify-between mb-3">
          <div class="text-center flex-1">
            <div class="w-10 h-10 rounded-full bg-green-500 text-white flex items-center justify-center mx-auto mb-2 text-sm font-bold">✓</div>
            <p class="text-xs text-gray-600">Stage 1: Initiated</p>
          </div>
          <div class="flex-1 flex items-center justify-center">
            <div class="flex-1 h-1 bg-gray-300"></div>
          </div>
          <div class="text-center flex-1">
            <div class="w-10 h-10 rounded-full bg-gray-300 text-gray-600 flex items-center justify-center mx-auto mb-2 text-sm font-bold">2</div>
            <p class="text-xs text-gray-600">Stage 2: Transfer</p>
          </div>
          <div class="flex-1 flex items-center justify-center">
            <div class="flex-1 h-1 bg-gray-300"></div>
          </div>
          <div class="text-center flex-1">
            <div class="w-10 h-10 rounded-full bg-gray-300 text-gray-600 flex items-center justify-center mx-auto mb-2 text-sm font-bold">3</div>
            <p class="text-xs text-gray-600">Stage 3: Instruction</p>
          </div>
          <div class="flex-1 flex items-center justify-center">
            <div class="flex-1 h-1 bg-gray-300"></div>
          </div>
          <div class="text-center flex-1">
            <div class="w-10 h-10 rounded-full bg-gray-300 text-gray-600 flex items-center justify-center mx-auto mb-2 text-sm font-bold">4</div>
            <p class="text-xs text-gray-600">Stage 4: Complete</p>
          </div>
        </div>
      </div>

      <!-- Alert -->
      <div v-if="apiError" class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
        <p class="text-red-800 font-medium">Error</p>
        <p class="text-red-700 text-sm">{{ apiError }}</p>
      </div>

      <!-- Form Card -->
      <div class="bg-white rounded-lg shadow border border-gray-200">
        <form @submit.prevent="submitForm" class="space-y-6 p-6">
          <!-- Alert: This stage is completed and read-only -->
          <div v-if="isReadOnly" class="bg-amber-50 border border-amber-200 rounded-lg p-4">
            <p class="text-amber-900 font-semibold">⚠️ Stage 1 Completed</p>
            <p class="text-sm text-amber-800 mt-1">This stage is read-only. View the details or go to the next stage to continue the refund process.</p>
          </div>
          
          <!-- Case Info -->
          <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <p class="text-sm text-gray-600">Case Number</p>
            <p class="text-lg font-bold text-gray-900">{{ caseNumber }}</p>
          </div>

          <!-- Refund Amount -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Refund Amount (IDR) *</label>
            <div class="relative">
              <span class="absolute left-3 top-3 text-gray-500">IDR</span>
              <input
                v-model.number="formData.refund_amount"
                type="number"
                step="0.01"
                min="0"
                placeholder="0.00"
                required
                :disabled="isSubmitting || isReadOnly"
                class="w-full pl-12 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
              />
            </div>
            <p v-if="maxRefundAmount" class="text-xs text-gray-500 mt-1">
              Maximum available: <span class="font-semibold">{{ formatCurrency(maxRefundAmount) }}</span>
            </p>
          </div>

          <!-- Refund Method -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Refund Method *</label>
            <select
              v-model="formData.refund_method"
              required
              :disabled="isSubmitting || isReadOnly"
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
            >
              <option value="">-- Select Method --</option>
              <option value="BANK_TRANSFER">Bank Transfer</option>
              <option value="CHEQUE">Cheque</option>
              <option value="CASH">Cash</option>
            </select>
          </div>

          <!-- Notes -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Notes (Optional)</label>
            <textarea
              v-model="formData.notes"
              rows="4"
              placeholder="Add any notes about this refund process..."
              :disabled="isSubmitting || isReadOnly"
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
            ></textarea>
          </div>

          <!-- Buttons -->
          <div class="flex gap-3 pt-4 border-t">
            <button
              type="button"
              @click="$router.back()"
              class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 font-medium"
              :disabled="isSubmitting"
            >
              Back
            </button>
            <button
              v-if="!isReadOnly"
              type="submit"
              class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium disabled:opacity-50"
              :disabled="isSubmitting"
            >
              {{ isSubmitting ? 'Creating...' : 'Create Refund (Stage 1)' }}
            </button>
          </div>
        </form>
      </div>

      <!-- Info Box -->
      <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
        <p class="text-sm text-blue-900">
          <strong>Stage 1:</strong> Creating a refund process initiates the refund. You can then proceed to Stage 2 to submit a transfer request to the bank.
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from '@/composables/useToast'

const { showSuccess, showError } = useToast()

const route = useRoute()
const router = useRouter()

const caseId = route.params.id
const refundId = route.params.refundId || null
const stageId = parseInt(route.query.stageId) ?? 0  // Default to 0 (PRELIMINARY) - read from query params
const isEditingExisting = ref(!!refundId)
const isReadOnly = ref(false)  // Track if stage is read-only (completed)
const currentRefundStage = ref(1)  // Track current stage

const isLoading = ref(false)
const isSubmitting = ref(false)
const apiError = ref('')
const caseNumber = ref('')
const maxRefundAmount = ref(0)

const formData = ref({
  refund_amount: null,
  refund_method: '',
  notes: ''
})

const formatCurrency = (value) => {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2
  }).format(value)
}

// Load existing refund data if refundId is provided
const loadExistingRefund = async () => {
  if (!refundId) return
  
  try {
    isLoading.value = true
    const endpoint = `/api/tax-cases/${caseId}/refunds/${refundId}/refund-stages/1`
    console.log('[RefundStage1] Loading existing refund from:', endpoint)
    
    const response = await fetch(endpoint, {
      method: 'GET',
      credentials: 'include',
      headers: {
        'Accept': 'application/json'
      }
    })

    if (!response.ok) {
      throw new Error(`Failed to load refund data: ${response.statusText}`)
    }

    const result = await response.json()
    const data = result.data || result
    
    console.log('[RefundStage1] Loaded refund data:', data)
    
    // Track current stage and set read-only flag
    currentRefundStage.value = data.current_stage || 1
    isReadOnly.value = currentRefundStage.value > 1  // Read-only if past Stage 1
    
    console.log('[RefundStage1] Current stage:', currentRefundStage.value, 'isReadOnly:', isReadOnly.value)
    
    // Pre-fill form with existing data
    formData.value.refund_amount = parseFloat(data.refund_amount) || null
    formData.value.refund_method = data.refund_method || ''
    formData.value.notes = data.notes || ''
  } catch (error) {
    console.error('[RefundStage1] Error loading refund:', error)
    apiError.value = error.message
  } finally {
    isLoading.value = false
  }
}

const loadCaseData = async () => {
  try {
    isLoading.value = true
    const response = await fetch(`/api/tax-cases/${caseId}`, {
      method: 'GET',
      credentials: 'include',
      headers: {
        'Accept': 'application/json'
      }
    })

    if (!response.ok) throw new Error('Failed to load case data')

    const result = await response.json()
    const data = result.data || result

    caseNumber.value = data.case_number || `Case #${caseId}`
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
    if (maxRefundAmount.value && amount > maxRefundAmount.value) {
      throw new Error(`Refund amount cannot exceed ${formatCurrency(maxRefundAmount.value)}`)
    }
    if (!formData.value.refund_method) {
      throw new Error('Please select a refund method')
    }

    const response = await fetch(`/api/tax-cases/${caseId}/refund-stages/1`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
      },
      body: JSON.stringify({
        stage_id: stageId,  // Use the stageId from route params (0 for preliminary, 4/7/10/12 for decision stages)
        refund_number: `PRL${stageId}-${Date.now()}`,
        refund_method: formData.value.refund_method,
        refund_amount: amount,
        notes: formData.value.notes || null
      })
    })

    if (!response.ok) {
      const error = await response.json()
      throw new Error(error.message || error.error || `API Error: ${response.status}`)
    }

    const result = await response.json()
    console.log('[RefundStage1] API Response:', result)
    
    showSuccess('Success', 'Refund created successfully! Proceeding to Stage 2...')

    // Redirect to Stage 2 form with refund ID
    const newRefundId = result.data?.id
    console.log('[RefundStage1] Extracted refundId:', newRefundId, 'from response:', result.data)
    
    if (newRefundId) {
      setTimeout(() => {
        router.push({
          name: 'RefundStage2FormWithId',
          params: { id: caseId, refundId: newRefundId }
        })
      }, 1500)
    } else {
      setTimeout(() => {
        router.back()
      }, 1500)
    }
  } catch (error) {
    apiError.value = error.message
    showError('Error', error.message)
  } finally {
    isSubmitting.value = false
  }
}

onMounted(() => {
  // Load case data regardless
  loadCaseData()
  
  // If refundId provided, load existing refund data
  if (refundId) {
    loadExistingRefund()
  }
})
</script>
