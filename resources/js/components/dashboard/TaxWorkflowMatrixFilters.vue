<template>
  <div class="grid gap-3 md:grid-cols-5">
    <label v-if="taxCategory === 'CIT'" class="block">
      <span class="text-xs font-semibold text-gray-600">Fiscal Year</span>
      <select v-model="local.fiscal_year_id" class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500">
        <option value="">All</option>
        <option v-for="year in fiscalYears" :key="year.id" :value="year.id">{{ year.year }}</option>
      </select>
    </label>

    <label v-if="taxCategory === 'VAT'" class="block">
      <span class="text-xs font-semibold text-gray-600">From</span>
      <input v-model="local.from_period" type="month" min="2013-03" class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500">
    </label>

    <label v-if="taxCategory === 'VAT'" class="block">
      <span class="text-xs font-semibold text-gray-600">To</span>
      <input v-model="local.to_period" type="month" min="2013-03" class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500">
    </label>

    <label v-if="showEntityFilter" class="block">
      <span class="text-xs font-semibold text-gray-600">Entity</span>
      <select v-model="local.entity_id" class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500">
        <option value="">All</option>
        <option v-for="entity in entities" :key="entity.id" :value="entity.id">{{ entity.name }}</option>
      </select>
    </label>

    <label class="block">
      <span class="text-xs font-semibold text-gray-600">Status</span>
      <select v-model="local.status" class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500">
        <option value="">All</option>
        <option value="action_required,input_required,eligible">Needs input</option>
        <option value="draft">Draft</option>
        <option value="completed">Completed</option>
        <option value="not_available_yet,not_applicable,not_available">Unavailable</option>
      </select>
    </label>

    <label class="flex items-end gap-2 rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700">
      <input v-model="local.include_completed" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
      <span>Show completed</span>
    </label>

    <div class="flex items-end gap-2">
      <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2" @click="apply">
        Apply
      </button>
      <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2" @click="reset">
        Reset
      </button>
    </div>
  </div>
</template>

<script setup>
import { reactive, watch } from 'vue'

const props = defineProps({
  taxCategory: { type: String, required: true },
  fiscalYears: { type: Array, default: () => [] },
  entities: { type: Array, default: () => [] },
  showEntityFilter: { type: Boolean, default: false },
  filters: { type: Object, required: true }
})

const emit = defineEmits(['apply'])

const local = reactive({ ...props.filters })
const formatMonth = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
const defaultVatRange = () => {
  const to = new Date()
  to.setDate(1)
  const from = new Date(to)
  from.setMonth(from.getMonth() - 11)

  return {
    from_period: formatMonth(from),
    to_period: formatMonth(to)
  }
}

watch(() => props.filters, (filters) => Object.assign(local, filters), { deep: true })

const apply = () => emit('apply', { ...local, page: 1 })
const reset = () => {
  Object.assign(local, {
    fiscal_year_id: '',
    ...(props.taxCategory === 'VAT' ? defaultVatRange() : { from_period: '', to_period: '' }),
    entity_id: '',
    status: '',
    include_completed: true,
    page: 1
  })
  apply()
}
</script>
