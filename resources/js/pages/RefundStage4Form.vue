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
        <h1 class="text-3xl font-bold text-gray-900">Refund Stage 4/4</h1>
        <p class="text-gray-600 mt-2">Refund Completed - Refund Selesai</p>
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
            <div class="w-10 h-10 rounded-full bg-green-500 text-white flex items-center justify-center mx-auto mb-2 text-sm font-bold">✓</div>
            <p class="text-xs text-gray-600">Stage 3: Instruction</p>
          </div>
          <div class="flex-1 flex items-center justify-center">
            <div class="flex-1 h-1 bg-green-500"></div>
          </div>
          <div class="text-center flex-1">
            <div class="w-10 h-10 rounded-full bg-blue-500 text-white flex items-center justify-center mx-auto mb-2 text-sm font-bold">4</div>
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
            <div>
              <p class="text-xs text-gray-600">Refund Method</p>
              <p class="font-semibold text-gray-900">{{ refundMethod }}</p>
            </div>
            <div>
              <p class="text-xs text-gray-600">Current Status</p>
              <p class="font-semibold text-blue-600">{{ refundStatus }}</p>
            </div>
          </div>

          <!-- Receipt Number -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Receipt Number *</label>
            <input
              v-model="formData.receipt_number"
              type="text"
              placeholder="e.g., RCPT-20260224-001"
              required
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              :disabled="isSubmitting || !canSubmit"
            />
          </div>

          <!-- Receipt Date -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Received Date *</label>
            <input
              v-model="formData.received_date"
              type="date"
              required
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              :disabled="receiptCompleted || isSubmitting || !canSubmit"
            />
          </div>

          <!-- Received Amount -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Received Amount *</label>
            <div class="relative">
              <span class="absolute left-4 top-2.5 text-gray-500">Rp</span>
              <FormField
                v-model="formData.received_amount"
                type="text"
                inputmode="decimal"
                :decimal-places="2"
                step="0.01"
                min="0"
                placeholder="0.00"
                required
                class="w-full px-4 py-2 pl-9 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                :disabled="receiptCompleted || isSubmitting || !canSubmit"
              />
            </div>
            <p class="text-xs text-gray-500 mt-1">
              Must equal the authorized transfer amount exactly ({{ formatCurrency(refundAmount) }})
            </p>
          </div>

          <!-- Amount Verification -->
          <div v-if="amountWarning" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
            <p class="text-yellow-800 text-sm">
              ⚠️ {{ amountWarning }}
            </p>
          </div>

          <!-- Notes -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Notes (Optional)</label>
            <textarea
              v-model="formData.notes"
              :readonly="!isPreliminary"
              rows="3"
              placeholder="Add any notes about receipt or transfer..."
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              :disabled="receiptCompleted || isSubmitting || !canSubmit"
            ></textarea>
          </div>

          <!-- Buttons -->
          <div class="flex gap-3 pt-4 border-t">
            <button
              type="button"
              @click="$router.back()"
              class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 font-medium"
              :disabled="receiptCompleted || isSubmitting || !canSubmit"
            >
              Back
            </button>
            <button
              type="submit"
              class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium disabled:opacity-50"
              :disabled="receiptCompleted || isSubmitting || !canSubmit || !formData.received_amount"
            >
              {{ isSubmitting ? 'Completing...' : 'Complete Refund (Stage 4 - Final)' }}
            </button>
          </div>
        </form>
      </div>

      <!-- Info Box -->
      <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
        <p class="text-sm text-blue-900">
          <strong>Stage 4 - Final Stage:</strong> {{ completionMessage }}
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { apiFetch as fetch } from '../composables/useSessionApi.js'

import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from '@/composables/useToast'
import FormField from '@/components/FormField.vue'
import { formatRefundMethod } from '../utils/refundPresentation.js'

const { showSuccess, showError } = useToast()

const route = useRoute()
const router = useRouter()

const caseId = route.params.id
const refundId = route.params.refundId

const isLoading = ref(false)
const isSubmitting = ref(false)
const canSubmit = ref(false)
const apiError = ref('')
const isPreliminary = ref(false)

const refundNumber = ref('')
const refundAmount = ref(0)
const refundMethod = ref('')
const refundStatus = ref('')
const receiptCompleted = ref(false)

const formData = ref({
  receipt_number: '',
  received_date: new Date().toISOString().split('T')[0],
  received_amount: null,
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

const amountWarning = computed(() => 'A single receipt must equal the authorized transfer amount exactly. Partial and excess payments are rejected.')
const completionMessage = computed(() => isPreliminary.value
  ? 'For an approved Preliminary Refund, confirming a single receipt equal to the full approved amount completes the Refund and automatically closes the eligible TaxCase/SPT (CLOSED).'
  : 'Confirm receipt to complete only the selected Refund. Ordinary Refund completion leaves the TaxCase/SPT status and main workflow unchanged and does not close the case.')

const loadRefundData = async () => {
  try {
    isLoading.value = true
    if (!refundId) throw new Error('Select a Refund explicitly from the case before saving payment evidence.')
    const endpoint = `/api/tax-cases/${caseId}/refunds/${refundId}/refund-stages/4`

    const response = await fetch(endpoint, {
      method: 'GET',
      credentials: 'include',
      headers: { 'Accept': 'application/json' }
    })

    if (!response.ok) throw new Error('Failed to load refund data')

    const result = await response.json()
    const data = result.data || result
    canSubmit.value = !!data.can_submit
    isPreliminary.value = !!data.is_preliminary
    formData.value.notes = data.notes || ''

    refundNumber.value = data.refund_number || 'N/A'
    refundAmount.value = data.refund_amount || 0
    refundMethod.value = formatRefundMethod(data.refund_method) || 'N/A'
    refundStatus.value = data.refund_status || 'PENDING'
    receiptCompleted.value = data.refund_status?.toLowerCase() === 'completed'
    if (receiptCompleted.value) {
      formData.value.receipt_number = data.receipt_number || ''
      formData.value.received_date = data.received_date?.slice(0, 10) || ''
      formData.value.received_amount = data.received_amount
      formData.value.notes = data.notes || ''
    }
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
    if (!canSubmit.value) throw new Error('This payment stage is locked or its predecessor is incomplete. Reload the selected Refund.')

    if (!formData.value.receipt_number) throw new Error('Receipt number is required')
    if (!formData.value.received_date) throw new Error('Received date is required')
    if (!formData.value.received_amount) throw new Error('Received amount is required')

    if (formData.value.received_amount <= 0) {
      throw new Error('Received amount must be greater than 0')
    }

    if (!refundId) throw new Error('Select a Refund explicitly from the case before saving payment evidence.')
    const endpoint = `/api/tax-cases/${caseId}/refunds/${refundId}/refund-stages/4`

    const response = await fetch(endpoint, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        receipt_number: formData.value.receipt_number,
        received_date: formData.value.received_date,
        received_amount: String(formData.value.received_amount),
        notes: formData.value.notes || null
      })
    })

    if (!response.ok) {
      const error = await response.json()
      throw new Error(Object.values(error.errors || {}).flat().join(' ') || error.message || error.error || `API Error: ${response.status}`)
    }

    showSuccess('Success', 'Refund completed successfully!')

    setTimeout(() => {
      router.push({
        name: 'TaxCaseDetail',
        params: { id: caseId }
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
