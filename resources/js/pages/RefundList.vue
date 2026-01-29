<template>
  <Card title="Refund Processes" subtitle="Track all refunds for this case">
    <div class="space-y-4">
      <!-- Summary Stats -->
      <div v-if="hasRefunds" class="grid grid-cols-3 gap-4 p-4 bg-blue-50 rounded-lg border border-blue-200">
        <div>
          <p class="text-sm text-gray-600">Total Refunded</p>
          <p class="text-2xl font-bold text-blue-600">{{ formatCurrency(totalRefunded, currencyCode) }}</p>
        </div>
        <div>
          <p class="text-sm text-gray-600">Available Amount</p>
          <p class="text-2xl font-bold text-green-600">{{ formatCurrency(availableAmount, currencyCode) }}</p>
        </div>
        <div>
          <p class="text-sm text-gray-600">Refund Count</p>
          <p class="text-2xl font-bold text-purple-600">{{ refunds.length }}</p>
        </div>
      </div>

      <!-- No Refunds Message -->
      <div v-else class="p-6 text-center bg-gray-50 rounded-lg border border-gray-200">
        <p class="text-gray-600">No refund processes created yet</p>
        <p class="text-sm text-gray-500 mt-2">Refunds will appear here when created at each decision stage</p>
      </div>

      <!-- Refund List Table -->
      <div v-if="hasRefunds" class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-gray-50 border-b border-gray-200">
              <th class="text-left px-4 py-3 font-semibold text-gray-700">#</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-700">Stage Source</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-700">Refund Number</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-700">Amount</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-700">Method</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-700">Status</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-700">Date</th>
              <th class="text-center px-4 py-3 font-semibold text-gray-700">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(refund, index) in refunds" :key="refund.id" class="border-b border-gray-200 hover:bg-gray-50">
              <td class="px-4 py-3 font-semibold text-gray-900">{{ refund.sequence_number }}</td>
              <td class="px-4 py-3">
                <span :class="getStageSourceBadgeClass(refund.stage_source)">
                  {{ formatStageSource(refund.stage_source) }}
                </span>
              </td>
              <td class="px-4 py-3 text-gray-700">{{ refund.refund_number }}</td>
              <td class="px-4 py-3 text-right font-semibold text-gray-900">{{ formatCurrency(refund.refund_amount, currencyCode) }}</td>
              <td class="px-4 py-3 text-gray-700">{{ formatMethod(refund.refund_method) }}</td>
              <td class="px-4 py-3">
                <span :class="getStatusBadgeClass(refund.status)">
                  {{ formatStatus(refund.status) }}
                </span>
              </td>
              <td class="px-4 py-3 text-gray-700">{{ formatDate(refund.refund_date) }}</td>
              <td class="px-4 py-3 text-center">
                <button
                  @click="emit('view-refund', refund)"
                  class="text-blue-600 hover:text-blue-800 font-medium text-sm"
                >
                  View
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Create New Refund Button -->
      <div v-if="canCreateMore" class="pt-4 border-t border-gray-200">
        <button
          @click="emit('create-refund')"
          class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition"
        >
          <span class="mr-2">+</span>
          Create Another Refund
        </button>
      </div>
    </div>
  </Card>
</template>

<script setup>
import { computed } from 'vue'
import Card from '../components/Card.vue'

const props = defineProps({
  refunds: {
    type: Array,
    default: () => [],
    description: 'Array of refund processes'
  },
  totalRefunded: {
    type: Number,
    default: 0,
    description: 'Total amount refunded across all processes'
  },
  availableAmount: {
    type: Number,
    default: 0,
    description: 'Remaining amount that can be refunded'
  },
  currencyCode: {
    type: String,
    default: 'IDR',
    description: 'Currency code for formatting'
  },
  canCreateMore: {
    type: Boolean,
    default: false,
    description: 'Whether a new refund can be created'
  }
})

const emit = defineEmits(['view-refund', 'create-refund'])

// Computed
const hasRefunds = computed(() => props.refunds && props.refunds.length > 0)

// Formatting helpers
const formatCurrency = (amount, currency = 'IDR') => {
  const num = parseFloat(amount) || 0
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: currency,
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(num)
}

const formatDate = (date) => {
  if (!date) return '-'
  return new Date(date).toLocaleDateString('id-ID', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

const formatStatus = (status) => {
  const statusMap = {
    'draft': 'Draft',
    'submitted': 'Submitted',
    'approved': 'Approved',
    'rejected': 'Rejected',
    'pending': 'Pending',
    'processing': 'Processing',
    'completed': 'Completed',
  }
  return statusMap[status?.toLowerCase()] || status
}

const formatMethod = (method) => {
  const methodMap = {
    'BANK_TRANSFER': 'Bank Transfer',
    'CHEQUE': 'Cheque',
    'CASH': 'Cash',
    'bank_transfer': 'Bank Transfer',
    'check': 'Cheque',
    'credit': 'Credit',
  }
  return methodMap[method] || method
}

const formatStageSource = (source) => {
  const sourceMap = {
    'PRELIMINARY': 'Pengembalian Pendahuluan',
    'SKP': 'SKP Decision',
    'OBJECTION': 'Objection Decision',
    'APPEAL': 'Appeal Decision',
    'SUPREME_COURT': 'Supreme Court Decision',
  }
  return sourceMap[source] || source
}

const getStatusBadgeClass = (status) => {
  const baseClass = 'px-3 py-1 rounded-full text-xs font-medium'
  const statusClass = {
    'draft': 'bg-gray-100 text-gray-800',
    'submitted': 'bg-blue-100 text-blue-800',
    'approved': 'bg-green-100 text-green-800',
    'rejected': 'bg-red-100 text-red-800',
    'pending': 'bg-yellow-100 text-yellow-800',
    'processing': 'bg-purple-100 text-purple-800',
    'completed': 'bg-green-100 text-green-800',
  }
  return `${baseClass} ${statusClass[status?.toLowerCase()] || statusClass['draft']}`
}

const getStageSourceBadgeClass = (source) => {
  const baseClass = 'px-3 py-1 rounded-full text-xs font-medium'
  const sourceClass = {
    'PRELIMINARY': 'bg-indigo-100 text-indigo-800',
    'SKP': 'bg-blue-100 text-blue-800',
    'OBJECTION': 'bg-orange-100 text-orange-800',
    'APPEAL': 'bg-purple-100 text-purple-800',
    'SUPREME_COURT': 'bg-red-100 text-red-800',
  }
  return `${baseClass} ${sourceClass[source] || sourceClass['SKP']}`
}
</script>
