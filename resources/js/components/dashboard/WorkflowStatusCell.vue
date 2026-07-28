<template>
  <button
    type="button"
    class="inline-flex min-w-[8.5rem] items-center justify-center gap-2 rounded-md border px-3 py-2 text-xs font-semibold transition focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed"
    :class="classes"
    :disabled="!cell.clickable"
    :title="cell.reason || cell.label"
    @click="$emit('navigate', cell.action)"
  >
    <span aria-hidden="true">{{ icon }}</span>
    <span>{{ cell.label }}</span>
  </button>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  cell: {
    type: Object,
    required: true
  }
})

defineEmits(['navigate'])

const icon = computed(() => {
  const icons = {
    completed: '✓',
    draft: '•',
    action_required: '!',
    not_available_yet: '…',
    not_applicable: '-',
    eligible: '!',
    input_required: '!',
    in_progress: '→',
    not_available: '-'
  }
  return icons[props.cell.status] || '•'
})

const classes = computed(() => {
  const map = {
    completed: 'border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100',
    draft: 'border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100',
    action_required: 'border-blue-200 bg-blue-50 text-blue-800 hover:bg-blue-100',
    not_available_yet: 'border-gray-200 bg-gray-50 text-gray-500',
    not_applicable: 'border-gray-200 bg-white text-gray-400',
    eligible: 'border-blue-200 bg-blue-50 text-blue-800 hover:bg-blue-100',
    input_required: 'border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100',
    in_progress: 'border-indigo-200 bg-indigo-50 text-indigo-800 hover:bg-indigo-100',
    completed_refund: 'border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100',
    not_available: 'border-gray-200 bg-gray-50 text-gray-500'
  }

  if (props.cell.status === 'completed' && props.cell.count !== undefined) {
    return map.completed_refund
  }

  return map[props.cell.status] || map.draft
})
</script>
