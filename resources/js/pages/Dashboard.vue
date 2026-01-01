<template>
  <div class="space-y-6">
    <!-- Welcome Section -->
    <Card title="Welcome to PORTAX" subtitle="Tax Case Management System">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-blue-50 p-4 rounded-lg">
          <p class="text-sm text-blue-600 font-medium">System Status</p>
          <p class="text-2xl font-bold text-blue-700 mt-1">{{ systemStatus }}</p>
        </div>
        <div class="bg-green-50 p-4 rounded-lg">
          <p class="text-sm text-green-600 font-medium">Total Cases</p>
          <p class="text-2xl font-bold text-green-700 mt-1">{{ totalCases }}</p>
        </div>
        <div class="bg-purple-50 p-4 rounded-lg">
          <p class="text-sm text-purple-600 font-medium">API Status</p>
          <p class="text-2xl font-bold text-purple-700 mt-1">{{ apiStatus }}</p>
        </div>
      </div>
    </Card>

    <!-- API Health Check / Test App -->
    <Card title="System Health Check" subtitle="Monitor API and application status">
      <div class="space-y-2">
        <div class="flex items-center justify-between p-3 bg-gray-50 rounded">
          <span>API Health Endpoint</span>
          <span v-if="healthStatus.api" class="text-green-600 font-bold">✓ Active</span>
          <span v-else class="text-red-600 font-bold">✗ Inactive</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 rounded">
          <span>Database Connection</span>
          <span v-if="healthStatus.database" class="text-green-600 font-bold">✓ Connected</span>
          <span v-else class="text-red-600 font-bold">✗ Disconnected</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 rounded">
          <span>Workflow Engine</span>
          <span v-if="healthStatus.workflows" class="text-green-600 font-bold">✓ Ready</span>
          <span v-else class="text-red-600 font-bold">✗ Not Ready</span>
        </div>
        <div v-if="apiTimestamp" class="text-xs text-gray-500 mt-2">
          Last checked: {{ apiTimestamp }}
        </div>
      </div>
    </Card>

    <!-- Quick Actions -->
    <Card title="Quick Actions" subtitle="Common tasks">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <Button @click="$router.push('/tax-cases')" variant="primary" block>
          View All Tax Cases
        </Button>
        <Button @click="$router.push('/tax-cases/create/cit')" variant="primary" block>
          New CIT Case
        </Button>
        <Button @click="$router.push('/tax-cases/create/vat')" variant="primary" block>
          New VAT Case
        </Button>
        <Button @click="checkHealth" variant="secondary" block>
          Check System Health
        </Button>
      </div>
    </Card>

    <!-- Workflow Stages -->
    <div class="space-y-4">
      <h2 class="text-2xl font-bold text-gray-900">Workflow Stages</h2>
      <p class="text-gray-600">Click on any stage card below to view cases and handle submissions</p>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div
          v-for="stage in workflowStages"
          :key="stage.id"
          @click="selectedStageId = selectedStageId === stage.id ? null : stage.id"
          class="cursor-pointer rounded-lg border-2 border-gray-200 bg-white p-4 hover:border-blue-500 hover:shadow-lg transition-all"
        >
          <div class="flex items-start gap-3">
            <span class="text-4xl">{{ stage.emoji }}</span>
            <div class="flex-1">
              <h3 class="font-bold text-gray-900">{{ stage.name }}</h3>
              <p class="text-sm text-gray-600">{{ stage.subtitle }}</p>
              <p class="text-xs text-gray-500 mt-2">{{ casesInStage(stage.id) }} cases</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Selected Stage Details -->
    <WorkflowStageDrawer
      :isOpen="selectedStageId !== null"
      :stageId="selectedStageId"
      @close="selectedStageId = null"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import Card from '../components/Card.vue'
import Button from '../components/Button.vue'
import WorkflowStageDrawer from '../components/WorkflowStageDrawer.vue'

const selectedStageId = ref(null)
const allCases = ref([])
const loading = ref(true)

// Welcome stats
const systemStatus = ref('Operational')
const totalCases = ref(0)
const apiStatus = ref('Checking...')
const apiTimestamp = ref('')

// Health check
const healthStatus = ref({
  api: false,
  database: false,
  workflows: false
})

const workflowStages = [
  {
    id: 1,
    name: 'SPT Filing',
    subtitle: 'Initial Tax Return Submission',
    emoji: '📋',
    description: 'File your initial tax return (SPT - Surat Pemberitahuan) with the tax authority.',
    requiredDocs: ['SPT (Tax Return) form', 'Supporting financial statements', 'Entity registration documents'],
    inputFields: ['Entity', 'Period', 'Currency', 'Dispute Amount']
  },
  {
    id: 2,
    name: 'SP2 Record',
    subtitle: 'Second Level Tax Record',
    emoji: '📝',
    description: 'Record the SP2 (Surat Pemberitahuan Koreksi) - the notification of tax corrections.',
    requiredDocs: ['SP2 letter', 'Amendments if needed'],
    inputFields: ['SP2 Number', 'Issued Date', 'Amended Amount']
  },
  {
    id: 3,
    name: 'SPHP Record',
    subtitle: 'Audit Findings Notification',
    emoji: '🔍',
    description: 'Record the SPHP (Surat Pemberitahuan Hasil Pemeriksaan) - audit findings and corrections.',
    requiredDocs: ['SPHP letter', 'Audit findings breakdown'],
    inputFields: ['SPHP Number', 'Issue Date', 'Royalty Correction', 'Service Correction']
  },
  {
    id: 4,
    name: 'SKP Record',
    subtitle: 'Tax Assessment Letter',
    emoji: '🔬',
    description: 'Record the SKP (Surat Ketetapan Pajak) - the tax assessment letter.',
    requiredDocs: ['SKP letter', 'Tax assessment details'],
    inputFields: ['SKP Number', 'Issue Date', 'SKP Type (LB/NIHIL/KB)', 'Amount']
  },
  {
    id: 5,
    name: 'Objection',
    subtitle: 'Formal Objection Filing',
    emoji: '⚠️',
    description: 'File a formal objection (Surat Keberatan) against the tax authority decision.',
    requiredDocs: ['Objection Letter', 'Supporting evidence'],
    inputFields: ['Objection Number', 'Submission Date', 'Objection Amount']
  },
  {
    id: 6,
    name: 'SPUH Record',
    subtitle: 'Administrative Appeal',
    emoji: '⚖️',
    description: 'Record the SPUH (Surat Pemberitahuan Usulan Harga) summon letter.',
    requiredDocs: ['SPUH letter', 'Reply letter'],
    inputFields: ['SPUH Number', 'Issue Date', 'Response']
  },
  {
    id: 7,
    name: 'Objection Decision',
    subtitle: 'Objection Review Decision',
    emoji: '✍️',
    description: 'Record the decision on the filed objection.',
    requiredDocs: ['Decision letter', 'Objection findings'],
    inputFields: ['Decision Number', 'Decision Date', 'Decision Type']
  },
  {
    id: 8,
    name: 'Appeal',
    subtitle: 'Court Appeal Filing',
    emoji: '📜',
    description: 'File an appeal (Surat Banding) to the tax court.',
    requiredDocs: ['Appeal Letter', 'Legal basis'],
    inputFields: ['Appeal Number', 'Filing Date', 'Appeal Amount']
  },
  {
    id: 9,
    name: 'Appeal Explanation',
    subtitle: 'Additional Appeal Documents',
    emoji: '📚',
    description: 'Provide additional explanation for the appeal case.',
    requiredDocs: ['Explanation letter', 'Additional evidence'],
    inputFields: ['Explanation Content', 'Supporting Documents']
  },
  {
    id: 10,
    name: 'Appeal Decision',
    subtitle: 'Appeal Court Decision',
    emoji: '🏛️',
    description: 'Record the court decision on the appeal.',
    requiredDocs: ['Court decision letter'],
    inputFields: ['Decision Number', 'Decision Date', 'Decision Result']
  },
  {
    id: 11,
    name: 'Supreme Court Review',
    subtitle: 'Peninjauan Kembali (Cassation)',
    emoji: '⚡',
    description: 'File for Peninjauan Kembali (Supreme Court review) if needed.',
    requiredDocs: ['Cassation request', 'Legal basis'],
    inputFields: ['Request Number', 'Filing Date', 'Reasons']
  },
  {
    id: 12,
    name: 'Supreme Court Decision',
    subtitle: 'Final Supreme Court Ruling',
    emoji: '📋',
    description: 'Record the Supreme Court decision - final and binding.',
    requiredDocs: ['Supreme Court decision'],
    inputFields: ['Decision Number', 'Decision Date', 'Final Ruling']
  },
  {
    id: 13,
    name: 'Refund',
    subtitle: 'Tax Refund Processing',
    emoji: '💰',
    description: 'Process and settle the tax refund based on the final decision.',
    requiredDocs: ['Refund approval', 'Bank details'],
    inputFields: ['Refund Amount', 'Bank Account', 'Transfer Date']
  }
]

const selectedStageInfo = computed(() => {
  return workflowStages.find(s => s.id === selectedStageId.value) || {}
})

const stageCases = computed(() => {
  if (!selectedStageId.value) return []
  if (!Array.isArray(allCases.value)) return []
  return allCases.value.filter(c => c && c.current_stage === selectedStageId.value)
})

const casesInStage = (stageId) => {
  if (!Array.isArray(allCases.value)) return 0
  return allCases.value.filter(c => c && c.current_stage === stageId).length
}

const getStatusBadge = (status) => {
  const badges = {
    'draft': 'inline-flex items-center rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-800',
    'in_progress': 'inline-flex items-center rounded-full bg-blue-100 px-2 py-1 text-xs font-medium text-blue-800',
    'pending_approval': 'inline-flex items-center rounded-full bg-yellow-100 px-2 py-1 text-xs font-medium text-yellow-800',
    'approved': 'inline-flex items-center rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-800',
    'rejected': 'inline-flex items-center rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-800',
    'completed': 'inline-flex items-center rounded-full bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-800',
    'closed': 'inline-flex items-center rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-800'
  }
  return badges[status] || badges['draft']
}

const checkHealth = async () => {
  try {
    const response = await fetch('/api/health')
    const data = await response.json()
    
    healthStatus.value = {
      api: data.status === 'ok',
      database: data.database === 'connected',
      workflows: data.workflows === 'ready'
    }
    
    apiStatus.value = data.status === 'ok' ? 'Healthy' : 'Down'
    apiTimestamp.value = new Date().toLocaleString()
  } catch (error) {
    console.error('Health check failed:', error)
    apiStatus.value = 'Error'
    healthStatus.value = { api: false, database: false, workflows: false }
  }
}

const loadCases = async () => {
  try {
    const response = await fetch('/api/tax-cases?limit=100')
    const data = await response.json()
    
    // Handle different API response structures
    if (Array.isArray(data)) {
      allCases.value = data
    } else if (data.data && Array.isArray(data.data)) {
      allCases.value = data.data
    } else if (data.cases && Array.isArray(data.cases)) {
      allCases.value = data.cases
    } else {
      console.warn('Unexpected API response structure:', data)
      allCases.value = []
    }
    
    totalCases.value = data.meta?.total || allCases.value.length
    console.log('Loaded cases:', allCases.value.length)
  } catch (error) {
    console.error('Failed to load cases:', error)
    allCases.value = []
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await Promise.all([
    checkHealth(),
    loadCases()
  ])
})
</script>