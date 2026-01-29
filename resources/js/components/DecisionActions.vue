<template>
  <div class="border-t-2 border-gray-200 pt-6 mt-6">
    <h3 class="text-lg font-semibold text-gray-900 mb-4">📊 Decision Actions</h3>
    <p class="text-sm text-gray-600 mb-4">
      Choose your next actions. You can create a refund trigger, continue to next stage, or do both. 
      <strong>Note:</strong> Refund amounts are set in the dedicated Refund Filling stages (13-15).
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
              Trigger refund creation. Amount will be set in the refund filling stage.
            </p>
          </div>
        </label>
      </div>

      <!-- Action 2: Continue to Next Stage -->
      <div class="bg-green-50 border-2 border-green-200 rounded-lg p-4">
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
      <div v-if="!actions.createRefund && !actions.continueToNextStage" class="bg-yellow-50 border-2 border-yellow-300 rounded-lg p-3">
        <div class="flex items-start">
          <div class="flex-shrink-0">
            <span class="text-lg">⚠️</span>
          </div>
          <div class="ml-3">
            <p class="text-sm font-medium text-yellow-800">
              Warning: Neither action selected
            </p>
            <p class="text-xs text-yellow-700 mt-1">
              If you don't create a refund or continue to next stage, this case will be marked as complete. You may be able to create a KIAN (Internal Loss Recognition) request later.
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
          <li v-if="actions.continueToNextStage">
            ✓ Continue to {{ nextStageName }}
          </li>
          <li v-else>
            ✗ Stop here (may create KIAN later)
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
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
  modelValue: {
    type: Object,
    default: () => ({
      createRefund: false,
      refundAmount: 0,
      continueToNextStage: false
    })
  }
})

const emit = defineEmits(['update:modelValue', 'change'])

// Local state for actions
const actions = ref({
  createRefund: props.modelValue?.createRefund || false,
  refundAmount: props.modelValue?.refundAmount || 0,
  continueToNextStage: props.modelValue?.continueToNextStage || false
})

// Computed: Formatted amounts for display
const formattedAvailableAmount = computed(() => {
  return new Intl.NumberFormat('id-ID').format(props.availableAmount)
})

// Note: refundAmountError is no longer needed since we don't have amount input at decision points

// Handler: Update individual action and emit change
const updateAction = (key, value) => {
  actions.value[key] = value
  console.log(`[DecisionActions] ${key} changed to:`, value, 'type:', typeof value)
  
  const newActions = {
    createRefund: actions.value.createRefund,
    refundAmount: 0, // Always 0 at decision points - amount is set in refund stage
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
        refundAmount: newValue.refundAmount || 0,
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
