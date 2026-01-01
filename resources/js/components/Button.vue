<template>
  <button
    :class="[
      'px-4 py-2 rounded-lg font-medium transition-colors duration-200',
      variantClasses,
      {
        'opacity-50 cursor-not-allowed': disabled,
        'w-full': block
      }
    ]"
    :disabled="disabled"
    @click="$emit('click')"
  >
    <slot />
  </button>
</template>

<script setup>
const props = defineProps({
  variant: {
    type: String,
    default: 'primary',
    validator: v => ['primary', 'secondary', 'danger', 'success'].includes(v)
  },
  disabled: Boolean,
  block: Boolean
})

const variantClasses = {
  primary: 'bg-blue-600 hover:bg-blue-700 text-white',
  secondary: 'bg-gray-200 hover:bg-gray-300 text-gray-800',
  danger: 'bg-red-600 hover:bg-red-700 text-white',
  success: 'bg-green-600 hover:bg-green-700 text-white'
}[props.variant]
</script>
