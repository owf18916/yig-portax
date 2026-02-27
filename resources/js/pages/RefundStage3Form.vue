<template>
  <div class="h-full">
    <!-- Loading Overlay -->
    <div v-if="isLoading" class="fixed inset-0 backdrop-blur-sm bg-white/30 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl p-8 text-center shadow-2xl border border-white/50">
        <div class="animate-spin rounded-full h-12 w-12 border-4 border-blue-200 border-t-blue-500 mx-auto mb-4"></div>
        <p class="text-gray-700 font-medium">Loading...</p>
      </div>
    </div>

    <div class="max-w-4xl mx-auto p-6">
      <!-- Header -->
      <div class="mb-6">
        <button @click="$router.back()" class="text-blue-600 hover:text-blue-800 mb-4 flex items-center gap-1">
          <span>←</span> Back
        </button>
        <h1 class="text-3xl font-bold text-gray-900">Refund Stage 3/4</h1>
        <p class="text-gray-600 mt-2">Transfer Instruction - Surat Instruksi Transfer</p>
      </div>

      <!-- Stage Progress Bar -->
      <div class="mb-8">
        <div class="flex justify-between mb-3">
          <div class="text-center flex-1">
            <div class="w-10 h-10 rounded-full bg-green-500 text-white flex items-center justify-center mx-auto mb-2 text-sm font-bold">✓</div>
            <p class="text-xs text-gray-600">Stage 1: Initiated</p>
          </div>
          <div class="flex-1 flex items-center justify-center">
            <div class="flex-1 h-1 bg-green-500"></div>
          </div>
          <div class="text-center flex-1">
            <div class="w-10 h-10 rounded-full bg-green-500 text-white flex items-center justify-center mx-auto mb-2 text-sm font-bold">✓</div>
            <p class="text-xs text-gray-600">Stage 2: Transfer</p>
          </div>
          <div class="flex-1 flex items-center justify-center">
            <div class="flex-1 h-1 bg-green-500"></div>
          </div>
          <div class="text-center flex-1">
            <div class="w-10 h-10 rounded-full bg-blue-500 text-white flex items-center justify-center mx-auto mb-2 text-sm font-bold">3</div>
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
          <!-- Refund Summary -->
          <div class="grid grid-cols-2 gap-4 bg-gray-50 p-4 rounded-lg">
            <div>
              <p class="text-xs text-gray-600">Refund Number</p>
              <p class="font-semibold text-gray-900">{{ refundNumber }}</p>
            </div>
            <div>
              <p class="text-xs text-gray-600">Refund Amount</p>
              <p class="font-semibold text-gray-900">{{ formatCurrency(refundAmount) }}</p>
            </div>
          </div>

          <!-- Instruction Number -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Instruction Number *</label>
            <input
              v-model="formData.instruction_number"
              type="text"
              placeholder="e.g., INSTR-20260224-001"
              required
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              :disabled="isSubmitting"
            />
          </div>

          <!-- Instruction Dates -->
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Instruction Issue Date *</label>
              <input
                v-model="formData.instruction_issue_date"
                type="date"
                required
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                :disabled="isSubmitting"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Instruction Received Date *</label>
              <input
                v-model="formData.instruction_received_date"
                type="date"
                required
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                :disabled="isSubmitting"
              />
            </div>
          </div>

          <!-- Bank Details Section -->
          <div class="border-t pt-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Bank Details</h3>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Bank Code</label>
                <input
                  v-model="formData.bank_code"
                  type="text"
                  placeholder="e.g., 008"
                  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                  :disabled="isSubmitting"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Bank Name *</label>
                <input
                  v-model="formData.bank_name"
                  type="text"
                  placeholder="e.g., Bank Indonesia"
                  required
                  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                  :disabled="isSubmitting"
                />
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4 mt-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Account Number *</label>
                <input
                  v-model="formData.account_number"
                  type="text"
                  placeholder="e.g., 123456789012"
                  required
                  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                  :disabled="isSubmitting"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Account Holder *</label>
                <input
                  v-model="formData.account_holder"
                  type="text"
                  placeholder="e.g., PT XYZ"
                  required
                  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                  :disabled="isSubmitting"
                />
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Account Name</label>
              <input
                v-model="formData.account_name"
                type="text"
                placeholder="e.g., Main Account"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                :disabled="isSubmitting"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Transfer Amount</label>
              <input
                v-model.number="formData.transfer_amount"
                type="number"
                step="0.01"
                min="0"
                placeholder="Amount to transfer (if different from refund amount)"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                :disabled="isSubmitting"
              />
            </div>
          </div>

          <!-- Notes -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Notes (Optional)</label>
            <textarea
              v-model="formData.notes"
              rows="3"
              placeholder="Add any notes..."
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              :disabled="isSubmitting"
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
              type="submit"
              class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium disabled:opacity-50"
              :disabled="isSubmitting"
            >
              {{ isSubmitting ? 'Updating...' : 'Update Instruction (Stage 3)' }}
            </button>
          </div>
        </form>
      </div>

      <!-- Info Box -->
      <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
        <p class="text-sm text-blue-900">
          <strong>Stage 3:</strong> Record the bank transfer instruction details. After updating, you'll confirm receipt in Stage 4.
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
const refundId = route.params.refundId

const isLoading = ref(false)
const isSubmitting = ref(false)
const apiError = ref('')

const refundNumber = ref('')
const refundAmount = ref(0)

const formData = ref({
  instruction_number: '',
  instruction_issue_date: '',
  instruction_received_date: new Date().toISOString().split('T')[0],
  bank_code: '',
  bank_name: '',
  account_number: '',
  account_holder: '',
  account_name: '',
  transfer_amount: null,
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

const loadRefundData = async () => {
  try {
    isLoading.value = true
    const endpoint = refundId
      ? `/api/tax-cases/${caseId}/refunds/${refundId}/refund-stages/3`
      : `/api/tax-cases/${caseId}/refund-stages/3`

    const response = await fetch(endpoint, {
      method: 'GET',
      credentials: 'include',
      headers: { 'Accept': 'application/json' }
    })

    if (!response.ok) throw new Error('Failed to load refund data')

    const result = await response.json()
    const data = result.data || result

    refundNumber.value = data.refund_number || 'N/A'
    refundAmount.value = data.refund_amount || 0
    
    // Pre-fill bank details if available
    if (data.bank_code) formData.value.bank_code = data.bank_code
    if (data.bank_name) formData.value.bank_name = data.bank_name
    if (data.account_number) formData.value.account_number = data.account_number
    if (data.account_holder) formData.value.account_holder = data.account_holder
    if (data.account_name) formData.value.account_name = data.account_name
  } catch (error) {
    apiError.value = error.message
    console.error('Failed to load refund:', error)
  } finally {
    isLoading.value = false
  }
}

const submitForm = async () => {
  try {
    isSubmitting.value = true
    apiError.value = ''

    if (!formData.value.instruction_number) throw new Error('Instruction number is required')
    if (!formData.value.instruction_issue_date) throw new Error('Instruction issue date is required')
    if (!formData.value.instruction_received_date) throw new Error('Instruction received date is required')
    if (!formData.value.bank_name) throw new Error('Bank name is required')
    if (!formData.value.account_number) throw new Error('Account number is required')
    if (!formData.value.account_holder) throw new Error('Account holder is required')

    const endpoint = refundId
      ? `/api/tax-cases/${caseId}/refunds/${refundId}/refund-stages/3`
      : `/api/tax-cases/${caseId}/refund-stages/3`

    const response = await fetch(endpoint, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
      },
      body: JSON.stringify({
        instruction_number: formData.value.instruction_number,
        instruction_issue_date: formData.value.instruction_issue_date,
        instruction_received_date: formData.value.instruction_received_date,
        bank_code: formData.value.bank_code || null,
        bank_name: formData.value.bank_name,
        account_number: formData.value.account_number,
        account_holder: formData.value.account_holder,
        account_name: formData.value.account_name || null,
        transfer_amount: formData.value.transfer_amount || null,
        notes: formData.value.notes || null
      })
    })

    if (!response.ok) {
      const error = await response.json()
      throw new Error(error.message || error.error || `API Error: ${response.status}`)
    }

    showSuccess('Success', 'Instruction updated! Proceeding to Stage 4...')

    setTimeout(() => {
      router.push({
        name: 'RefundStage4FormWithId',
        params: { id: caseId, refundId: refundId || route.params.refundId }
      })
    }, 1500)
  } catch (error) {
    apiError.value = error.message
    showError('Error', error.message)
  } finally {
    isSubmitting.value = false
  }
}

onMounted(() => {
  loadRefundData()
})
</script>
