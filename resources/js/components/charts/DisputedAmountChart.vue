<template>
  <div class="w-full space-y-4">
    <!-- Currency Selector -->
    <div class="flex items-center gap-2">
      <label class="text-sm font-medium text-gray-700">Currency:</label>
      <select 
        v-model="selectedCurrency" 
        class="px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      >
        <option value="">All Currencies</option>
        <option v-for="curr in availableCurrencies" :key="curr" :value="curr">
          {{ curr }}
        </option>
      </select>
    </div>
    
    <!-- Chart -->
    <canvas ref="chartRef"></canvas>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, computed } from 'vue'
import Chart from 'chart.js/auto'

const props = defineProps({
  data: {
    type: Array,
    required: true,
    default: () => []
  }
})

const chartRef = ref(null)
let chartInstance = null
const selectedCurrency = ref('USD') // Default to USD

// Get available currencies from data
const availableCurrencies = computed(() => {
  const currencies = new Set()
  props.data.forEach(entity => {
    if (entity.currencies && Array.isArray(entity.currencies)) {
      entity.currencies.forEach(curr => {
        currencies.add(curr.code)
      })
    }
  })
  return Array.from(currencies).sort()
})

// Get chart data for selected currency
const getChartData = () => {
  const labels = []
  const citData = []
  const vatData = []

  props.data.forEach(entity => {
    if (!entity.currencies || !Array.isArray(entity.currencies)) return

    // If no currency selected, combine all
    if (!selectedCurrency.value) {
      let citTotal = 0
      let vatTotal = 0
      entity.currencies.forEach(curr => {
        citTotal += curr.cit || 0
        vatTotal += curr.vat || 0
      })
      labels.push(entity.entity)
      citData.push(citTotal)
      vatData.push(vatTotal)
    } else {
      // Find specific currency
      const currencyData = entity.currencies.find(c => c.code === selectedCurrency.value)
      if (currencyData) {
        labels.push(`${entity.entity} (${currencyData.code})`)
        citData.push(currencyData.cit || 0)
        vatData.push(currencyData.vat || 0)
      }
    }
  })

  return { labels, citData, vatData }
}

const formatCurrency = (value) => {
  if (value >= 1000000) {
    return (value / 1000000).toFixed(1) + 'M'
  } else if (value >= 1000) {
    return (value / 1000).toFixed(1) + 'K'
  }
  return value.toFixed(0)
}

const createChart = () => {
  if (!chartRef.value || !props.data || props.data.length === 0) return

  // Destroy previous chart if exists
  if (chartInstance) {
    chartInstance.destroy()
  }

  const { labels, citData, vatData } = getChartData()

  if (labels.length === 0) {
    return
  }

  const ctx = chartRef.value.getContext('2d')
  
  chartInstance = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'CIT Amount',
          data: citData,
          backgroundColor: '#8b5cf6',
          borderColor: '#6d28d9',
          borderWidth: 1,
          borderRadius: 4,
          borderSkipped: false,
          barPercentage: 0.7,
          categoryPercentage: 0.8
        },
        {
          label: 'VAT Amount',
          data: vatData,
          backgroundColor: '#f59e0b',
          borderColor: '#d97706',
          borderWidth: 1,
          borderRadius: 4,
          borderSkipped: false,
          barPercentage: 0.7,
          categoryPercentage: 0.8
        }
      ]
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: true,
      plugins: {
        legend: {
          display: true,
          position: 'top',
          labels: {
            boxWidth: 15,
            padding: 15,
            font: {
              size: 12,
              weight: '500'
            },
            usePointStyle: false
          }
        },
        tooltip: {
          backgroundColor: 'rgba(0, 0, 0, 0.8)',
          padding: 12,
          titleFont: {
            size: 13,
            weight: 'bold'
          },
          bodyFont: {
            size: 12
          },
          borderColor: '#e5e7eb',
          borderWidth: 1,
          displayColors: true,
          boxPadding: 8,
          callbacks: {
            label: function(context) {
              const value = context.parsed.x
              return context.dataset.label + ': ' + formatCurrency(value)
            }
          }
        }
      },
      scales: {
        x: {
          stacked: true,
          grid: {
            display: true,
            color: '#e5e7eb',
            drawBorder: true
          },
          ticks: {
            font: {
              size: 11
            },
            color: '#6b7280',
            callback: function(value) {
              return formatCurrency(value)
            }
          }
        },
        y: {
          stacked: true,
          grid: {
            display: false,
            drawBorder: true
          },
          ticks: {
            font: {
              size: 12
            },
            color: '#374151',
            padding: 8
          }
        }
      }
    }
  })
}

watch(() => props.data, () => {
  createChart()
}, { deep: true })

watch(() => selectedCurrency.value, () => {
  createChart()
})

onMounted(() => {
  createChart()
})
</script>
