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
        <button @click="goBack" class="text-blue-600 hover:text-blue-800 mb-4 flex items-center gap-1">
          <span>←</span> Back
        </button>
        <button type="button" @click="router.push({ name: 'TaxCaseDetail', params: { id: caseId } })" class="text-blue-600 hover:text-blue-800 mb-4 ml-4">Back to TaxCase Detail</button>
        <h1 class="text-3xl font-bold text-gray-900">Refund Stage 2/4</h1>
        <p class="text-gray-600 mt-2">Bank Transfer Request - Surat Permintaan Transfer</p>
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
            <div class="w-10 h-10 rounded-full bg-blue-500 text-white flex items-center justify-center mx-auto mb-2 text-sm font-bold">2</div>
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
              <p class="text-xs text-gray-600">Status</p>
              <p class="font-semibold text-blue-600">In Progress</p>
            </div>
          </div>

          <!-- Request Number -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Request Number *</label>
            <input
              v-model="formData.request_number"
              type="text"
              placeholder="e.g., REQN-20260224-001"
              required
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              :disabled="isLoading || isReadOnly || isSubmitting || !canSubmit"
            />
          </div>

          <!-- Request Date -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Request Date *</label>
            <input
              v-model="formData.request_date"
              type="date"
              required
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              :disabled="isLoading || isReadOnly || isSubmitting || !canSubmit"
            />
          </div>

          <!-- Transfer Date -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Planned Transfer Date *</label>
            <input
              v-model="formData.transfer_date"
              type="date"
              required
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              :disabled="isLoading || isReadOnly || isSubmitting || !canSubmit"
            />
          </div>

          <!-- Notes -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Notes (Optional)</label>
            <textarea
              v-model="formData.notes"
              rows="3"
              placeholder="Add any notes..."
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              :disabled="isLoading || isReadOnly || isSubmitting || !canSubmit"
            ></textarea>
          </div>

          <!-- Buttons -->
          <div class="flex gap-3 pt-4 border-t">
            <button
              type="button"
              @click="goBack"
              class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 font-medium"
              :disabled="isSubmitting"
            >
              Back
            </button>
            <button
              v-if="canContinue"
              type="button"
              @click="continueToNextStage"
              class="flex-1 px-4 py-2 border border-blue-600 text-blue-700 rounded-lg hover:bg-blue-50 font-medium"
            >
              Continue to Stage 3
            </button>
            <button
              type="submit"
              class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium disabled:opacity-50"
              :disabled="isLoading || isReadOnly || isSubmitting || !canSubmit"
            >
              {{ isSubmitting ? 'Submitting...' : 'Submit Transfer Request (Stage 2)' }}
            </button>
          </div>
        </form>
      </div>

      <!-- Info Box -->
      <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
        <p class="text-sm text-blue-900">
          <strong>Stage 2:</strong> Submit bank transfer request. After submission, you'll receive a bank instruction in Stage 3.
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { formatRefundAmount } from '../utils/refundPresentation.js'
import { apiFetch as fetch } from '../composables/useSessionApi.js'

import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from '@/composables/useToast'

const { showSuccess, showError } = useToast()

const route = useRoute()
const router = useRouter()

const caseId = computed(() => route.params.id)
const refundId = computed(() => route.params.refundId)
let loadVersion = 0

const isLoading = ref(false)
const isSubmitting = ref(false)
const canSubmit = ref(false)
const isReadOnly = ref(false)  // Track if stage is read-only (completed)
const currentRefundStage = ref(0)
const apiError = ref('')

const currencyCode = ref('')
const isPreliminary = ref(false)
const refundNumber = ref('')
const refundAmount = ref(0)
const refundMethod = ref('')

const emptyFormData = () => ({
  request_number: '',
  request_date: new Date().toISOString().split('T')[0],
  transfer_date: '',
  notes: ''
})
const formData = ref(emptyFormData())

const formatCurrency = (value) => {
  if (!isPreliminary.value) return formatRefundAmount(value, currencyCode.value)
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2
  }).format(value)
}

const loadRefundData = async () => {
  const version = ++loadVersion
  const selectedCaseId = caseId.value
  const selectedRefundId = refundId.value
  const isSelected = () => version === loadVersion && caseId.value === selectedCaseId && refundId.value === selectedRefundId
  canSubmit.value = false
  apiError.value = ''
  formData.value = emptyFormData()
  refundMethod.value = ''
  refundNumber.value = ''
  refundAmount.value = 0
  currencyCode.value = ''
  isPreliminary.value = false
  try {
    isLoading.value = true
    isReadOnly.value = true
    currentRefundStage.value = 0
    if (!selectedRefundId) throw new Error('Select a Refund explicitly from the case before saving payment evidence.')
    const endpoint = `/api/tax-cases/${selectedCaseId}/refunds/${selectedRefundId}/refund-stages/2`

    console.log('[RefundStage2] Loading from endpoint:', endpoint)

    const response = await fetch(endpoint, {
      method: 'GET',
      credentials: 'include',
      headers: { 'Accept': 'application/json' }
    })

    console.log('[RefundStage2] Response status:', response.status)

    if (!response.ok) {
      const errorData = await response.json()
      console.error('[RefundStage2] API error response:', { status: response.status, data: errorData })
      throw new Error(errorData.message || errorData.error || `HTTP ${response.status}: Failed to load refund data`)
    }

    const result = await response.json()
    console.log('[RefundStage2] Successfully loaded refund data:', result)
    
    if (!isSelected()) return
    const data = result.data || result
    currencyCode.value = data.currency_code || ''
    isPreliminary.value = !!data.is_preliminary
    canSubmit.value = !!data.can_submit
    
    for (const key of ['request_number', 'request_date', 'transfer_date', 'notes']) {
      if (data[key]) formData.value[key] = key.endsWith('date') ? data[key].slice(0, 10) : data[key]
    }
    // Track current stage and set read-only flag
    currentRefundStage.value = Number(data.current_stage) || 0
    isReadOnly.value = !canSubmit.value || currentRefundStage.value >= 2  // Recorded Stage 2 evidence stays read-only

    refundNumber.value = data.refund_number || 'N/A'
    refundAmount.value = data.refund_amount || 0
    refundMethod.value = data.refund_method || 'N/A'
  } catch (error) {
    if (!isSelected()) return
    apiError.value = error.message
    console.error('[RefundStage2] Failed to load refund:', error)
  } finally {
    if (isSelected()) isLoading.value = false
  }
}

const submitForm = async () => {
  const selectedCaseId = caseId.value
  const selectedRefundId = refundId.value
  const isSelected = () => caseId.value === selectedCaseId && refundId.value === selectedRefundId
  try {
    isSubmitting.value = true
    apiError.value = ''
    if (isLoading.value || isReadOnly.value || !canSubmit.value) throw new Error('This payment stage is locked or its predecessor is incomplete. Reload the selected Refund.')

    if (!formData.value.request_number) {
      throw new Error('Request number is required')
    }
    if (!formData.value.request_date) {
      throw new Error('Request date is required')
    }
    if (!formData.value.transfer_date) {
      throw new Error('Transfer date is required')
    }

    if (!selectedRefundId) throw new Error('Select a Refund explicitly from the case before saving payment evidence.')
    const endpoint = `/api/tax-cases/${selectedCaseId}/refunds/${selectedRefundId}/refund-stages/2`

    const response = await fetch(endpoint, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        request_number: formData.value.request_number,
        request_date: formData.value.request_date,
        transfer_date: formData.value.transfer_date,
        notes: formData.value.notes || null
      })
    })

    if (!response.ok) {
      const error = await response.json()
      throw new Error(Object.values(error.errors || {}).flat().join(' ') || error.message || error.error || `API Error: ${response.status}`)
    }

    if (!isSelected()) return
    showSuccess('Success', 'Transfer request submitted! Proceeding to Stage 3...')

    setTimeout(() => {
      if (!isSelected()) return
      router.push({
        name: 'RefundStage3FormWithId',
        params: { id: selectedCaseId, refundId: selectedRefundId }
      })
    }, 1500)
  } catch (error) {
    if (!isSelected()) return
    apiError.value = error.message
    showError('Error', error.message)
  } finally {
    isSubmitting.value = false
  }
}

// current_stage is persisted completion evidence, not permission to submit again.
const canContinue = computed(() => !!caseId.value && !!refundId.value && !isLoading.value && !isSubmitting.value && !apiError.value && [2, 3, 4].includes(currentRefundStage.value))
const continueToNextStage = () => {
  if (!canContinue.value) return
  router.push({ name: 'RefundStage3FormWithId', params: { id: caseId.value, refundId: refundId.value } })
}

const goBack = () => {
  if (!caseId.value || !refundId.value || isSubmitting.value) return
  router.push({ name: 'RefundStage1FormWithId', params: { id: caseId.value, refundId: refundId.value } })
}

onMounted(() => {
  loadRefundData()
})
watch(() => [route.params.id, route.params.refundId], loadRefundData, { flush: 'sync' })
</script>
