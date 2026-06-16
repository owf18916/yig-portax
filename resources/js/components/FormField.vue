<template>
  <div class="mb-4">
    <label v-if="label" class="block text-sm font-medium text-gray-700 mb-1">
      {{ label }}
      <span v-if="required" class="text-red-500">*</span>
    </label>
    <textarea
      v-if="type === 'textarea'"
      :value="modelValue"
      :placeholder="placeholder"
      :required="required"
      :disabled="disabled"
      :rows="rows"
      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
      @input="$emit('update:modelValue', $event.target.value)"
    />
    <input
      v-else
      ref="inputRef"
      v-bind="inputAttrs"
      :type="inputType"
      :value="displayValue"
      :placeholder="placeholder"
      :required="required"
      :disabled="disabled"
      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
      @input="handleInput"
      @blur="handleBlur"
    />
    <p v-if="error" class="text-red-500 text-sm mt-1">{{ error }}</p>
  </div>
</template>

<script setup>
import { computed, nextTick, ref, useAttrs, watch } from 'vue'
import {
  coerceNumberBounds,
  countNumberCharacters,
  findCaretFromNumberCharacterCount,
  formatNumberInput,
  normalizeNumberInput
} from '../composables/useNumberFormat'

defineOptions({
  inheritAttrs: false
})

const props = defineProps({
  label: String,
  type: {
    type: String,
    default: 'text'
  },
  modelValue: [String, Number],
  placeholder: String,
  required: Boolean,
  disabled: Boolean,
  error: String,
  rows: {
    type: Number,
    default: 3
  },
  decimalPlaces: {
    type: [Number, String],
    default: null
  },
  thousandSeparator: {
    type: String,
    default: ','
  },
  decimalSeparator: {
    type: String,
    default: '.'
  },
  integerOnly: {
    type: Boolean,
    default: false
  },
  allowNegative: {
    type: Boolean,
    default: false
  },
  min: {
    type: [Number, String],
    default: null
  },
  max: {
    type: [Number, String],
    default: null
  }
})

const emit = defineEmits(['update:modelValue'])
const attrs = useAttrs()
const inputRef = ref(null)
const internalDisplayValue = ref('')

const isFormattedNumber = computed(() => props.type === 'formatted-number' || props.type === 'number')
const inputType = computed(() => isFormattedNumber.value ? 'text' : props.type)
const inputAttrs = computed(() => {
  if (!isFormattedNumber.value) return attrs

  const { step, min, max, ...rest } = attrs
  return {
    ...rest,
    inputmode: props.integerOnly ? 'numeric' : 'decimal'
  }
})
const numberOptions = computed(() => ({
  thousandSeparator: props.thousandSeparator,
  decimalSeparator: props.decimalSeparator,
  decimalPlaces: props.decimalPlaces,
  integerOnly: props.integerOnly,
  allowNegative: props.allowNegative,
  min: props.min,
  max: props.max
}))
const displayValue = computed(() => {
  if (!isFormattedNumber.value) return props.modelValue
  return internalDisplayValue.value
})

watch(
  () => [props.modelValue, numberOptions.value],
  () => {
    if (isFormattedNumber.value) {
      internalDisplayValue.value = formatNumberInput(props.modelValue, numberOptions.value)
    }
  },
  { immediate: true, deep: true }
)

const handleInput = (event) => {
  if (!isFormattedNumber.value) {
    emit('update:modelValue', event.target.value)
    return
  }

  const rawValue = event.target.value
  const caretNumberChars = countNumberCharacters(
    rawValue.slice(0, event.target.selectionStart ?? rawValue.length),
    numberOptions.value
  )
  const normalized = normalizeNumberInput(rawValue, numberOptions.value)
  const formatted = formatNumberInput(normalized, numberOptions.value)

  internalDisplayValue.value = formatted
  emit('update:modelValue', normalized)

  nextTick(() => {
    if (!inputRef.value) return
    const nextCaret = findCaretFromNumberCharacterCount(formatted, caretNumberChars, numberOptions.value)
    inputRef.value.setSelectionRange(nextCaret, nextCaret)
  })
}

const handleBlur = () => {
  if (!isFormattedNumber.value) return

  const bounded = coerceNumberBounds(normalizeNumberInput(props.modelValue, numberOptions.value), numberOptions.value)
  const formatted = formatNumberInput(bounded, numberOptions.value)

  internalDisplayValue.value = formatted
  if (bounded !== props.modelValue) {
    emit('update:modelValue', bounded)
  }
}
</script>
