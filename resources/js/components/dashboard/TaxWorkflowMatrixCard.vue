<template>
  <Card title="Tax Workflow Matrix" subtitle="CIT and VAT workflow status by authorized entity and period">
    <div class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex rounded-md border border-gray-200 bg-gray-50 p-1">
          <button
            v-for="tab in tabs"
            :key="tab"
            type="button"
            class="rounded px-4 py-2 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
            :class="taxCategory === tab ? 'bg-white text-blue-700 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
            @click="changeTab(tab)"
          >
            {{ tab }}
          </button>
        </div>
        <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2" @click="load">
          Retry
        </button>
      </div>

      <TaxWorkflowMatrixFilters
        :tax-category="taxCategory"
        :filters="filters"
        :fiscal-years="matrix?.meta?.available_fiscal_years || []"
        :entities="entities"
        :show-entity-filter="showEntityColumn"
        @apply="applyFilters"
      />

      <div v-if="loading" class="space-y-2">
        <div v-for="index in 5" :key="index" class="h-12 animate-pulse rounded-md bg-gray-100"></div>
      </div>

      <div v-else-if="error" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
        <p class="font-semibold">{{ error.title }}</p>
        <ul v-if="error.messages?.length" class="mt-2 list-disc space-y-1 pl-5">
          <li v-for="message in error.messages" :key="message">{{ message }}</li>
        </ul>
      </div>

      <div v-else-if="!matrix || matrix.rows.length === 0" class="rounded-lg border border-gray-200 bg-gray-50 p-8 text-center text-gray-600">
        No workflow periods match the selected filters.
      </div>

      <div v-else-if="hasNoTaxCases" class="rounded-lg border border-gray-200 bg-gray-50 p-8 text-center text-gray-600">
        No tax cases are available yet.
      </div>

      <TaxWorkflowMatrixTable
        v-else
        :rows="matrix.rows"
        :columns="matrix.columns"
        :show-entity="showEntityColumn"
        @navigate="navigate"
      />

      <div v-if="matrix?.meta" class="flex flex-wrap items-center justify-between gap-3 text-sm text-gray-600">
        <div>
          Page {{ matrix.meta.current_page }} of {{ matrix.meta.last_page }} · {{ matrix.meta.total }} rows
        </div>
        <div class="flex items-center gap-2">
          <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 font-semibold disabled:opacity-50" :disabled="matrix.meta.current_page <= 1 || loading" @click="setPage(matrix.meta.current_page - 1)">
            Previous
          </button>
          <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 font-semibold disabled:opacity-50" :disabled="matrix.meta.current_page >= matrix.meta.last_page || loading" @click="setPage(matrix.meta.current_page + 1)">
            Next
          </button>
        </div>
      </div>
    </div>
  </Card>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Card from '../Card.vue'
import TaxWorkflowMatrixFilters from './TaxWorkflowMatrixFilters.vue'
import TaxWorkflowMatrixTable from './TaxWorkflowMatrixTable.vue'
import { useDashboardWorkflowMatrixApi } from '../../composables/useDashboardWorkflowMatrixApi'

const router = useRouter()
const tabs = ['CIT', 'VAT']
const taxCategory = ref('CIT')
const matrix = ref(null)
const entities = ref([])
const { data, loading, error, fetchMatrix } = useDashboardWorkflowMatrixApi()

const filters = reactive({
  fiscal_year_id: '',
  from_period: '',
  to_period: '',
  entity_id: '',
  status: '',
  include_completed: true,
  page: 1,
  per_page: 20,
  sort: 'period_desc'
})

const showEntityColumn = computed(() => matrix.value?.meta?.show_entity_column || false)
const hasNoTaxCases = computed(() => matrix.value?.rows?.length > 0 && matrix.value.rows.every((row) => !row.tax_case))
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

const loadEntities = async () => {
  try {
    const response = await fetch('/api/entities', { headers: { Accept: 'application/json' }, credentials: 'include' })
    const payload = await response.json()
    entities.value = payload.data?.data || payload.data || payload || []
  } catch {
    entities.value = []
  }
}

const load = async () => {
  try {
    const payload = await fetchMatrix({
      ...filters,
      tax_category: taxCategory.value,
      fiscal_year_id: taxCategory.value === 'CIT' ? filters.fiscal_year_id : '',
      from_period: taxCategory.value === 'VAT' ? filters.from_period : '',
      to_period: taxCategory.value === 'VAT' ? filters.to_period : ''
    })
    matrix.value = payload
  } catch {
    matrix.value = null
  }
}

const applyFilters = (nextFilters) => {
  Object.assign(filters, nextFilters)
  load()
}

const changeTab = (tab) => {
  taxCategory.value = tab
  Object.assign(filters, {
    fiscal_year_id: '',
    ...(tab === 'VAT' ? defaultVatRange() : { from_period: '', to_period: '' }),
    status: '',
    page: 1
  })
  load()
}

const setPage = (page) => {
  filters.page = page
  load()
}

const navigate = (action) => {
  if (!action || action.type !== 'route') return
  router.push({ name: action.name, params: action.params || {}, query: action.query || {} })
}

onMounted(async () => {
  await Promise.all([loadEntities(), load()])
})
</script>
