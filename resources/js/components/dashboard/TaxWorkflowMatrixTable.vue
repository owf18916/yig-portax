<template>
  <div class="overflow-x-auto rounded-lg border border-gray-200">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
      <thead class="bg-gray-50">
        <tr>
          <th v-if="showEntity" scope="col" class="sticky left-0 z-20 min-w-[12rem] bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
            Entity
          </th>
          <th scope="col" class="sticky z-20 min-w-[10rem] bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600" :class="showEntity ? 'left-48' : 'left-0'">
            Fiscal Period
          </th>
          <th v-for="column in columns" :key="column.key" scope="col" class="min-w-[9rem] px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
            {{ column.label }}
          </th>
          <th scope="col" class="min-w-[10rem] px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
            Refund
          </th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100 bg-white">
        <tr v-for="row in rows" :key="row.row_key" class="hover:bg-gray-50">
          <td v-if="showEntity" class="sticky left-0 z-10 min-w-[12rem] bg-white px-4 py-3 font-medium text-gray-900">
            <div>{{ row.entity.name }}</div>
            <div class="text-xs text-gray-500">{{ row.entity.code }}</div>
          </td>
          <td class="sticky z-10 min-w-[10rem] bg-white px-4 py-3 font-semibold text-gray-900" :class="showEntity ? 'left-48' : 'left-0'">
            <div>{{ row.period.period_code }}</div>
            <div class="text-xs text-gray-500">FY {{ row.period.fiscal_year }}</div>
          </td>
          <td v-for="column in columns" :key="`${row.row_key}-${column.key}`" class="px-4 py-3">
            <WorkflowStatusCell :cell="row.stages[column.key]" @navigate="$emit('navigate', $event)" />
          </td>
          <td class="px-4 py-3">
            <WorkflowStatusCell :cell="row.refund" @navigate="$emit('navigate', $event)" />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import WorkflowStatusCell from './WorkflowStatusCell.vue'

defineProps({
  rows: { type: Array, required: true },
  columns: { type: Array, required: true },
  showEntity: { type: Boolean, default: false }
})

defineEmits(['navigate'])
</script>
