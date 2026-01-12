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

  const labels = props.data.map(item => item.entity)
  const citData = props.data.map(item => item.cit)
  const vatData = props.data.map(item => item.vat)

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

onMounted(() => {
  createChart()
})
</script>
