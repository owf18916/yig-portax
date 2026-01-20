<template>
  <div class="space-y-6">
    <!-- Welcome Section with System Definition -->
    <Card title="Welcome to PORTAX" subtitle="Integrated Tax Case Management System">
      <div class="space-y-6">
        <!-- System Definition -->
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg p-6 border border-blue-100">
          <div class="flex gap-4">
            <div class="flex-shrink-0">
              <div class="flex items-center justify-center h-12 w-12 rounded-md bg-blue-600 text-white">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
              </div>
            </div>
            <div class="flex-1">
              <h3 class="text-lg font-semibold text-gray-900">About PORTAX</h3>
              <p class="mt-2 text-gray-700">
                PORTAX is an integrated tax case management system designed to manage the complete lifecycle of tax cases, 
                from SPT (Tax Return) submissions to resolution through various administrative and judicial stages. 
                The system facilitates collaboration among multiple stakeholders in the tax dispute resolution process.
              </p>
              <div class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="text-center">
                  <p class="text-2xl font-bold text-blue-600">{{ totalCases }}</p>
                  <p class="text-xs text-gray-600 mt-1">Total Cases</p>
                </div>
                <div class="text-center">
                  <p class="text-2xl font-bold text-green-600">{{ activeCases }}</p>
                  <p class="text-xs text-gray-600 mt-1">Active Cases</p>
                </div>
                <div class="text-center">
                  <p class="text-2xl font-bold text-purple-600">{{ completedCases }}</p>
                  <p class="text-xs text-gray-600 mt-1">Completed</p>
                </div>
                <div class="text-center">
                  <p class="text-2xl font-bold text-blue-700">13</p>
                  <p class="text-xs text-gray-600 mt-1">Workflow Stages</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Quick Actions -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
          <Button @click="$router.push('/tax-cases')" variant="primary" block>
            <svg class="inline w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            View All Cases
          </Button>
          <Button @click="$router.push('/tax-cases/create/cit')" variant="secondary" block>
            <svg class="inline w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            New CIT
          </Button>
          <Button @click="$router.push('/tax-cases/create/vat')" variant="secondary" block>
            <svg class="inline w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            New VAT
          </Button>
          <Button @click="refreshData" variant="secondary" block>
            <svg class="inline w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            Refresh
          </Button>
        </div>
      </div>
    </Card>

    <!-- Announcements and Exchange Rates Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Announcements Card -->
      <Card title="Announcements" subtitle="Latest updates and notifications">
        <div class="space-y-4">
          <!-- Admin Create Button -->
          <div v-if="isAdmin" class="mb-4">
            <Button @click="openCreateAnnouncementModal" variant="primary" block>
              <svg class="inline w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              Create Announcement
            </Button>
          </div>

          <!-- Announcements List -->
          <div class="space-y-3">
            <div v-if="announcements.length === 0" class="text-center py-8">
              <p class="text-gray-500">No announcements at the moment</p>
            </div>

            <div
              v-for="announcement in announcements"
              :key="announcement.id"
              :class="getAnnouncementClass(announcement.type)"
              class="p-4 rounded border-l-4 group relative"
            >
              <div class="flex items-start gap-3">
                <svg
                  :class="getAnnouncementIconClass(announcement.type)"
                  class="h-5 w-5 flex-shrink-0 mt-0.5"
                  fill="currentColor"
                  viewBox="0 0 20 20"
                >
                  <path v-if="announcement.type === 'info'" fill-rule="evenodd" d="M18 5v8a2 2 0 01-2 2h-5l-5 4v-4H4a2 2 0 01-2-2V5a2 2 0 012-2h12a2 2 0 012 2zm-11-1a1 1 0 11-2 0 1 1 0 012 0z" clip-rule="evenodd" />
                  <path v-else-if="announcement.type === 'success'" fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                  <path v-else-if="announcement.type === 'warning'" fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                  <path v-else fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                </svg>
                <div class="flex-1">
                  <p :class="getAnnouncementTitleClass(announcement.type)" class="font-semibold">{{ announcement.title }}</p>
                  <p :class="getAnnouncementTextClass(announcement.type)" class="text-sm mt-1">{{ announcement.content }}</p>
                  <p :class="getAnnouncementDateClass(announcement.type)" class="text-xs mt-1">{{ formatDate(announcement.published_at) }}</p>
                </div>
                <!-- Admin Action Buttons -->
                <div v-if="isAdmin" class="flex-shrink-0 flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                  <button
                    @click="openEditAnnouncementModal(announcement)"
                    :class="getAnnouncementTextClass(announcement.type)"
                    class="hover:underline text-xs font-medium"
                  >
                    Edit
                  </button>
                  <button
                    @click="deleteAnnouncementQuick(announcement)"
                    :class="getAnnouncementTextClass(announcement.type)"
                    class="hover:underline text-xs font-medium text-red-600"
                  >
                    Delete
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Exchange Rates Card -->
      <ExchangeRateTable :key="exchangeRateRefreshKey" />
    </div>

    <!-- Announcement Modal -->
    <AnnouncementModal
      :isOpen="showAnnouncementModal"
      :editData="selectedAnnouncement"
      :onSubmit="handleAnnouncementSubmit"
      :onClose="closeAnnouncementModal"
    />

    <!-- Exchange Rate Setup Modal -->
    <ExchangeRateModal
      :isOpen="showExchangeRateModal"
      @close="closeExchangeRateModal"
      @success="handleExchangeRateSuccess"
    />

    <!-- Analytics Charts Section -->
    <div class="space-y-4">
      <h2 class="text-2xl font-bold text-gray-900">Case Analytics</h2>
      <p class="text-gray-600">Real-time visualization of tax case data by type and entity</p>
      
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Open Cases Chart -->
        <Card title="Open Cases by Type" subtitle="Count of draft and open cases per entity">
          <div v-if="chartDataLoading" class="flex items-center justify-center h-80">
            <div class="text-center">
              <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
              <p class="text-gray-600 mt-2">Loading data...</p>
            </div>
          </div>
          <div v-else>
            <OpenCasesChart :data="openCasesData" />
          </div>
        </Card>

        <!-- Disputed Amount Chart -->
        <Card title="Disputed Amount by Type" subtitle="Total amount in open cases per entity">
          <div v-if="chartDataLoading" class="flex items-center justify-center h-80">
            <div class="text-center">
              <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
              <p class="text-gray-600 mt-2">Loading data...</p>
            </div>
          </div>
          <div v-else>
            <DisputedAmountChart :data="disputedAmountData" />
          </div>
        </Card>
      </div>
    </div>

    <!-- Workflow Stages -->
    <div class="space-y-4">
      <h2 class="text-2xl font-bold text-gray-900">Workflow Stages</h2>
      <p class="text-gray-600">Manage cases through various tax resolution process stages</p>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div
          v-for="stage in workflowStages"
          :key="stage.id"
          @click="selectedStageId = selectedStageId === stage.id ? null : stage.id"
          class="cursor-pointer rounded-lg border-2 border-gray-200 bg-white p-5 hover:border-blue-500 hover:shadow-md transition-all duration-200 group"
        >
          <div class="flex items-start gap-4">
            <div class="text-4xl group-hover:scale-110 transition-transform duration-200">{{ stage.emoji }}</div>
            <div class="flex-1 min-w-0">
              <h3 class="font-bold text-gray-900 text-sm md:text-base">{{ stage.name }}</h3>
              <p class="text-xs md:text-sm text-gray-600 mt-1">{{ stage.subtitle }}</p>
              <div class="mt-3 inline-block bg-blue-100 text-blue-800 text-xs font-semibold px-3 py-1 rounded-full">
                {{ casesInStage(stage.id) }} cases
              </div>
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
import OpenCasesChart from '../components/charts/OpenCasesChart.vue'
import DisputedAmountChart from '../components/charts/DisputedAmountChart.vue'
import AnnouncementModal from '../components/AnnouncementModal.vue'
import ExchangeRateModal from '../components/ExchangeRateModal.vue'
import ExchangeRateTable from '../components/ExchangeRateTable.vue'

const selectedStageId = ref(null)
const allCases = ref([])
const loading = ref(true)
const chartDataLoading = ref(true)
const announcements = ref([])
const currentUser = ref(null)
const showAnnouncementModal = ref(false)
const selectedAnnouncement = ref(null)
const showExchangeRateModal = ref(false)
const exchangeRateRefreshKey = ref(0)

// Dashboard stats
const totalCases = ref(0)
const activeCases = ref(0)
const completedCases = ref(0)

// Chart data
const openCasesData = ref([])
const disputedAmountData = ref([])

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

const getAnnouncementClass = (type) => {
  const classes = {
    'info': 'bg-blue-50 border-blue-500',
    'success': 'bg-green-50 border-green-500',
    'warning': 'bg-amber-50 border-amber-500',
    'error': 'bg-red-50 border-red-500'
  }
  return classes[type] || classes['info']
}

const getAnnouncementIconClass = (type) => {
  const classes = {
    'info': 'text-blue-500',
    'success': 'text-green-500',
    'warning': 'text-amber-500',
    'error': 'text-red-500'
  }
  return classes[type] || classes['info']
}

const getAnnouncementTitleClass = (type) => {
  const classes = {
    'info': 'text-blue-900',
    'success': 'text-green-900',
    'warning': 'text-amber-900',
    'error': 'text-red-900'
  }
  return classes[type] || classes['info']
}

const getAnnouncementTextClass = (type) => {
  const classes = {
    'info': 'text-blue-700',
    'success': 'text-green-700',
    'warning': 'text-amber-700',
    'error': 'text-red-700'
  }
  return classes[type] || classes['info']
}

const getAnnouncementDateClass = (type) => {
  const classes = {
    'info': 'text-blue-600',
    'success': 'text-green-600',
    'warning': 'text-amber-600',
    'error': 'text-red-600'
  }
  return classes[type] || classes['info']
}

const formatDate = (date) => {
  if (!date) return ''
  const d = new Date(date)
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

const isAdmin = computed(() => {
  return currentUser.value?.role?.name === 'Admin' || currentUser.value?.role_id === 1
})

const openCreateAnnouncementModal = () => {
  selectedAnnouncement.value = null
  showAnnouncementModal.value = true
}

const openEditAnnouncementModal = (announcement) => {
  selectedAnnouncement.value = announcement
  showAnnouncementModal.value = true
}

const closeAnnouncementModal = () => {
  showAnnouncementModal.value = false
  selectedAnnouncement.value = null
}

const deleteAnnouncementQuick = async (announcement) => {
  if (!confirm(`Delete "${announcement.title}"?`)) return

  try {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    const response = await fetch(`/api/announcements/${announcement.id}`, {
      method: 'DELETE',
      headers: {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrfToken || ''
      },
      credentials: 'include'
    })

    if (!response.ok) {
      throw new Error('Failed to delete announcement')
    }

    // Reload announcements
    await loadAnnouncements()
  } catch (error) {
    alert('Error deleting announcement: ' + error.message)
  }
}

const handleAnnouncementSubmit = async (formData) => {
  try {
    // Handle delete action
    if (formData.action === 'delete') {
      await loadAnnouncements()
      return
    }
    if (formData.action === 'delete') {
      await loadAnnouncements()
      return
    }

    const url = selectedAnnouncement.value
      ? `/api/announcements/${selectedAnnouncement.value.id}`
      : '/api/announcements'

    const method = selectedAnnouncement.value ? 'PUT' : 'POST'

    // Format dates properly
    const payload = {
      ...formData,
      published_at: formData.published_at ? new Date(formData.published_at).toISOString() : null,
      expires_at: formData.expires_at ? new Date(formData.expires_at).toISOString() : null
    }

    // Get CSRF token from meta tag
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')

    const response = await fetch(url, {
      method,
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrfToken || ''
      },
      credentials: 'include',
      body: JSON.stringify(payload)
    })

    const data = await response.json()

    if (!response.ok) {
      throw {
        response: { status: response.status, data }
      }
    }

    // Reload announcements
    await loadAnnouncements()
  } catch (error) {
    throw error
  }
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

const getCurrentUser = async () => {
  try {
    const response = await fetch('/api/me', {
      headers: {
        'Accept': 'application/json'
      },
      credentials: 'include'
    })

    if (response.ok) {
      const responseData = await response.json()
      // API returns {success, message, data: user}
      if (responseData.success && responseData.data) {
        currentUser.value = responseData.data
        console.log('Current user loaded:', responseData.data)
      }
    }
  } catch (error) {
    console.error('Failed to load current user:', error)
  }
}

const loadAnnouncements = async () => {
  try {
    const response = await fetch('/api/announcements', {
      headers: {
        'Accept': 'application/json'
      },
      credentials: 'include'
    })
    
    if (!response.ok) {
      throw new Error('Failed to fetch announcements')
    }
    
    const responseData = await response.json()
    
    console.log('Announcements response:', responseData)
    
    if (responseData.success && responseData.data) {
      // Handle paginated response
      if (responseData.data.data && Array.isArray(responseData.data.data)) {
        announcements.value = responseData.data.data
      } else if (Array.isArray(responseData.data)) {
        announcements.value = responseData.data
      }
    }
  } catch (error) {
    console.error('Failed to load announcements:', error)
    announcements.value = []
  }
}

const loadCases = async () => {
  try {
    // First check if user is authenticated
    const meResponse = await fetch('/api/me', {
      headers: {
        'Accept': 'application/json'
      },
      credentials: 'include'
    })
    
    if (!meResponse.ok) {
      throw new Error('Not authenticated')
    }
    
    const response = await fetch('/api/tax-cases?limit=1000', {
      headers: {
        'Accept': 'application/json'
      },
      credentials: 'include'
    })
    const responseData = await response.json()
    
    console.log('API Response:', responseData)
    
    let casesArray = []
    
    // Handle API response format: {success, message, data: {...}}
    if (responseData.success && responseData.data) {
      // API returns paginated data with structure: {current_page, data: [...], last_page, total, ...}
      if (responseData.data.data && Array.isArray(responseData.data.data)) {
        casesArray = responseData.data.data
      } else if (Array.isArray(responseData.data)) {
        casesArray = responseData.data
      }
    } else if (Array.isArray(responseData)) {
      casesArray = responseData
    } else if (responseData.data && Array.isArray(responseData.data)) {
      casesArray = responseData.data
    }
    
    allCases.value = casesArray
    
    console.log('Loaded cases array:', casesArray.length)
    
    // Count from actual loaded data
    const validCases = casesArray.filter(c => c && typeof c === 'object')
    totalCases.value = validCases.length
    activeCases.value = validCases.filter(c => !c.is_completed).length
    completedCases.value = validCases.filter(c => c.is_completed).length
    
    console.log('Loaded cases:', validCases.length, 'Active:', activeCases.value, 'Completed:', completedCases.value)
  } catch (error) {
    console.error('Failed to load cases:', error)
    allCases.value = []
    totalCases.value = 0
    activeCases.value = 0
    completedCases.value = 0
  } finally {
    loading.value = false
  }
}

const loadChartData = async () => {
  try {
    const response = await fetch('/api/dashboard/charts', {
      headers: {
        'Accept': 'application/json'
      },
      credentials: 'include'
    })
    
    if (!response.ok) {
      throw new Error('Failed to fetch chart data')
    }
    
    const data = await response.json()
    
    if (data.success) {
      openCasesData.value = data.openCases || []
      disputedAmountData.value = data.disputedAmounts || []
    }
  } catch (error) {
    console.error('Failed to load chart data:', error)
    openCasesData.value = []
    disputedAmountData.value = []
  } finally {
    chartDataLoading.value = false
  }
}

const refreshData = async () => {
  loading.value = true
  chartDataLoading.value = true
  await Promise.all([
    loadCases(),
    loadChartData(),
    loadAnnouncements()
  ])
}

const openExchangeRateModal = () => {
  showExchangeRateModal.value = true
}

const closeExchangeRateModal = () => {
  showExchangeRateModal.value = false
}

const handleExchangeRateSuccess = () => {
  // Refresh the exchange rate table by changing key
  exchangeRateRefreshKey.value += 1
}

onMounted(async () => {
  await Promise.all([
    getCurrentUser(),
    loadCases(),
    loadChartData(),
    loadAnnouncements()
  ])
})
</script>