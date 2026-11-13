<template>
  <div class="border-t-2 border-gray-200 pt-6 mt-6">
    <h3 class="text-lg font-semibold text-gray-900 mb-4">📊 Decision Actions</h3>
    <p class="text-sm text-gray-600 mb-4">
      Choose the independent process actions that apply to this decision.
      Enter the independent amount approved for this Refund.
    </p>

    <!-- Actions Container -->
    <div class="space-y-4">
      <!-- Action 1: Create Refund -->
      <div class="bg-blue-50 border-2 border-blue-200 rounded-lg p-4">
        <label class="flex items-start cursor-pointer">
          <input
            type="checkbox"
            :checked="actions.createRefund"
            @change="updateAction('createRefund', $event.target.checked)"
            :disabled="disabled"
            class="mt-1 h-5 w-5 text-blue-600 focus:ring-blue-500 border-gray-300 rounded disabled:opacity-50 disabled:cursor-not-allowed"
          />
          <div class="ml-3 flex-1">
            <p class="font-medium text-gray-900">💰 Create Refund Process</p>
            <p class="text-xs text-gray-600 mt-1">
              Create a parallel Refund using the manual amount entered below.
            </p>
          </div>
        </label>
        <div v-if="actions.createRefund" class="mt-3">
          <label class="block text-sm font-medium" for="decision-refund-amount">Refund Amount {{ currencyCode ? `(${currencyCode})` : '' }}</label>
          <input id="decision-refund-amount" type="text" inputmode="decimal"
            :value="actions.refundAmount" :disabled="disabled"
            @input="updateAction('refundAmount', $event.target.value)"
            class="mt-1 w-full border rounded p-2" aria-describedby="refund-amount-error" />
          <p id="refund-amount-error" v-if="error || refundAmountError" class="text-sm text-red-600">{{ error || refundAmountError }}</p>
          <p class="text-xs text-gray-600 mt-1">Required and greater than zero on final submission. Up to two decimal places.</p>
        </div>
      </div>

      <!-- Action 2: Continue to Next Stage -->
      <div v-if="showContinue" class="bg-green-50 border-2 border-green-200 rounded-lg p-4">
        <label class="flex items-start cursor-pointer">
          <input
            type="checkbox"
            :checked="actions.continueToNextStage"
            @change="updateAction('continueToNextStage', $event.target.checked)"
            :disabled="disabled"
            class="mt-1 h-5 w-5 text-green-600 focus:ring-green-500 border-gray-300 rounded disabled:opacity-50 disabled:cursor-not-allowed"
          />
          <div class="ml-3 flex-1">
            <p class="font-medium text-gray-900">↪️ Continue to {{ nextStageName }}</p>
            <p class="text-xs text-gray-600 mt-1">
              Proceed to the next stage in the workflow
            </p>
          </div>
        </label>
      </div>

      <!-- Warning if neither selected -->
      <div v-if="!actions.createRefund && (!showContinue || !actions.continueToNextStage)" class="bg-yellow-50 border-2 border-yellow-300 rounded-lg p-3">
        <div class="flex items-start">
          <div class="flex-shrink-0">
            <span class="text-lg">⚠️</span>
          </div>
          <div class="ml-3">
            <p class="text-sm font-medium text-yellow-800">
              Warning: Neither action selected
            </p>
            <p class="text-xs text-yellow-700 mt-1">
              No parallel Refund process will be created. KIAN availability remains derived separately.
            </p>
          </div>
        </div>
      </div>

      <!-- Summary Box -->
      <div class="bg-gray-50 border border-gray-300 rounded-lg p-3">
        <p class="text-sm font-medium text-gray-900">Summary:</p>
        <ul class="mt-2 text-sm text-gray-700 space-y-1">
          <li v-if="actions.createRefund">
            ✓ Refund process will be created
          </li>
          <li v-else>
            ✗ No refund process
          </li>
          <li v-if="showContinue && actions.continueToNextStage">
            ✓ Continue to {{ nextStageName }}
          </li>
          <li v-else-if="showContinue">
            ✗ Stop here (may create KIAN later)
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { refundAmountError as validateRefundAmount } from '../utils/refundPresentation.js'

const props = defineProps({
  currencyCode: { type: String, default: '' },
  error: { type: String, default: '' },
  availableAmount: {
    type: Number,
    required: true,
    default: 0
  },
  nextStageName: {
    type: String,
    required: true,
    default: 'Next Stage'
  },
  disabled: {
    type: Boolean,
    default: false
  },
  showContinue: {
    type: Boolean,
    default: true
  },
  modelValue: {
    type: Object,
    default: () => ({
      createRefund: false,
      refundAmount: '',
      continueToNextStage: false
    })
  }
})

const emit = defineEmits(['update:modelValue', 'change'])

// Local state for actions
const actions = ref({
  createRefund: props.modelValue?.createRefund || false,
  refundAmount: props.modelValue?.refundAmount ?? '',
  continueToNextStage: props.modelValue?.continueToNextStage || false
})

const refundAmountError = computed(() => validateRefundAmount(actions.value.refundAmount, false))

// Handler: Update individual action and emit change
const updateAction = (key, value) => {
  actions.value[key] = value
  console.log(`[DecisionActions] ${key} changed to:`, value, 'type:', typeof value)
  
  const newActions = {
    createRefund: actions.value.createRefund,
    refundAmount: actions.value.refundAmount,
    continueToNextStage: actions.value.continueToNextStage
  }
  
  console.log('[DecisionActions] Emitting update:modelValue with:', newActions)
  emit('update:modelValue', newActions)
  emit('change', newActions)
}

// Watch for external prop changes to sync local state
watch(
  () => props.modelValue,
  (newValue) => {
    if (newValue && JSON.stringify(newValue) !== JSON.stringify(actions.value)) {
      actions.value = {
        createRefund: newValue.createRefund || false,
        refundAmount: newValue.refundAmount ?? '',
        continueToNextStage: newValue.continueToNextStage || false
      }
    }
  },
  { deep: true }
)
</script>

<style scoped>
.expand-enter-active, .expand-leave-active {
  transition: all 0.3s ease;
}

.expand-enter-from {
  opacity: 0;
  max-height: 0;
}

.expand-leave-to {
  opacity: 0;
  max-height: 0;
}

.expand-enter-to, .expand-leave-from {
  opacity: 1;
  max-height: 500px;
}
</style>
