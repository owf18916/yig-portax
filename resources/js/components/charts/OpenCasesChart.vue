<template>
  <div class="w-full">
    <canvas ref="chartRef"></canvas>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
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

// Get chart data - aggregate all currencies per entity
const getChartData = () => {
  const labels = []
  const citData = []
  const vatData = []

  props.data.forEach(entity => {
    if (!entity.currencies || !Array.isArray(entity.currencies)) return

    // Aggregate all currencies
    let citTotal = 0
    let vatTotal = 0
    entity.currencies.forEach(curr => {
      citTotal += curr.cit || 0
      vatTotal += curr.vat || 0
    })
    
    labels.push(entity.entity)
    citData.push(citTotal)
    vatData.push(vatTotal)
  })

  return { labels, citData, vatData }
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
          label: 'CIT',
          data: citData,
          backgroundColor: '#3b82f6',
          borderColor: '#1e40af',
          borderWidth: 1,
          borderRadius: 4,
          borderSkipped: false,
          barPercentage: 0.7,
          categoryPercentage: 0.8
        },
        {
          label: 'VAT',
          data: vatData,
          backgroundColor: '#10b981',
          borderColor: '#047857',
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
          boxPadding: 8
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
            color: '#6b7280'
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

onMounted(() => {
  createChart()
})
</script>

