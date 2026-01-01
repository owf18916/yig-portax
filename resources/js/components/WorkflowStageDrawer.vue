<template>
  <!-- Semi-Transparent Dark Overlay with Blur -->
  <transition name="fade">
    <div
      v-if="isOpen"
      class="fixed inset-0 bg-black/50 backdrop-blur-sm z-40 cursor-pointer"
      @click="closeDrawer"
    />
  </transition>

  <!-- Drawer -->
  <transition name="slide">
    <div
      v-if="isOpen"
      class="fixed right-0 top-0 h-screen w-full max-w-2xl bg-white shadow-lg z-50 overflow-y-auto"
    >
      <!-- Header -->
      <div class="sticky top-0 bg-white border-b border-gray-200 p-6 flex items-center justify-between">
        <div class="flex items-center space-x-4">
          <span class="text-4xl">{{ stageEmoji }}</span>
          <div>
            <h2 class="text-2xl font-bold text-gray-900">{{ stageName }}</h2>
            <p class="text-sm text-gray-600">{{ stageSubtitle }}</p>
          </div>
        </div>
        <button
          @click="closeDrawer"
          class="text-gray-400 hover:text-gray-600 text-2xl"
        >
          ✕
        </button>
      </div>

      <!-- Content -->
      <div class="p-6 space-y-6">
        <!-- Stage Info Section -->
        <div class="space-y-4">
          <h3 class="text-lg font-semibold text-gray-900">📋 Stage Information</h3>
          
          <!-- Description -->
          <div>
            <p class="text-sm text-gray-600 mb-2">Description</p>
            <p class="text-gray-900">{{ stageInfo.description }}</p>
          </div>

          <!-- Required Documents -->
          <div>
            <p class="text-sm text-gray-600 mb-2">Required Documents</p>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
              <ul class="space-y-2">
                <li v-for="doc in stageInfo.requiredDocs" :key="doc" class="flex items-start space-x-2 text-gray-700">
                  <span class="text-blue-600 mt-1">📄</span>
                  <span>{{ doc }}</span>
                </li>
              </ul>
            </div>
          </div>

          <!-- Input Fields Description -->
          <div>
            <p class="text-sm text-gray-600 mb-2">Input Fields</p>
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
              <ul class="space-y-2">
                <li v-for="field in stageInfo.inputFields" :key="field" class="flex items-start space-x-2 text-gray-700">
                  <span class="text-gray-400">•</span>
                  <span>{{ field }}</span>
                </li>
              </ul>
            </div>
          </div>
        </div>

        <!-- Divider -->
        <div class="border-t border-gray-200"></div>

        <!-- Tax Cases Table -->
        <div class="space-y-4">
          <h3 class="text-lg font-semibold text-gray-900">📊 Tax Cases in This Stage</h3>
          
          <div v-if="cases.length === 0" class="text-center py-8 text-gray-500">
            <p class="text-lg">Belum Ada Data</p>
            <p class="text-sm">No cases in this stage yet</p>
          </div>

          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                  <th class="text-left px-4 py-3 font-semibold text-gray-700">Case Number</th>
                  <th class="text-left px-4 py-3 font-semibold text-gray-700">Entity Name</th>
                  <th class="text-left px-4 py-3 font-semibold text-gray-700">Case Type</th>
                  <th class="text-right px-4 py-3 font-semibold text-gray-700">Amount</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="caseItem in cases"
                  :key="caseItem.id"
                  class="border-b border-gray-100 hover:bg-blue-50 transition"
                >
                  <td class="px-4 py-3">
                    <router-link
                      :to="`/tax-cases/${caseItem.id}`"
                      class="text-blue-600 hover:text-blue-800 hover:underline font-medium"
                    >
                      {{ caseItem.case_number }}
                    </router-link>
                  </td>
                  <td class="px-4 py-3 text-gray-900">{{ caseItem.entity_name }}</td>
                  <td class="px-4 py-3">
                    <span
                      :class="[
                        'inline-block px-3 py-1 rounded-full text-xs font-medium',
                        caseItem.case_type === 'VAT' 
                          ? 'bg-purple-100 text-purple-800' 
                          : 'bg-blue-100 text-blue-800'
                      ]"
                    >
                      {{ caseItem.case_type }}
                    </span>
                  </td>
                  <td class="px-4 py-3 text-right text-gray-900">
                    {{ formatCurrency(caseItem.amount) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </transition>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'

const props = defineProps({
  isOpen: {
    type: Boolean,
    default: false
  },
  stageId: {
    type: Number,
    default: null
  }
})

const emit = defineEmits(['close'])

const cases = ref([])
const loading = ref(false)

// Stage definitions with info
const stageDefinitions = {
  1: {
    name: 'SPT Filing',
    subtitle: 'Initial Tax Return Submission',
    emoji: '📋',
    description: 'File your initial tax return (SPT - Surat Pemberitahuan) with the tax authority. This is the first step in the tax dispute process.',
    requiredDocs: [
      'SPT (Tax Return) form',
      'Supporting financial statements',
      'Entity registration documents',
      'Period documentation'
    ],
    inputFields: [
      'Entity Name',
      'Fiscal Period',
      'Currency',
      'Nilai Sengketa (Disputed Amount)',
      'Filing Decision (Filed/Not Filed)'
    ]
  },
  2: {
    name: 'SP2 Record',
    subtitle: 'Second Level Tax Record',
    emoji: '📑',
    description: 'Record the SP2 (Surat Pemberitahuan Pemeriksaan) - the notification that the tax authority has initiated an audit examination.',
    requiredDocs: [
      'SP2 letter from tax authority',
      'Auditor details',
      'Examination schedule'
    ],
    inputFields: [
      'SP2 Number',
      'Issue Date',
      'Receipt Date',
      'Auditor Name',
      'Auditor Contact Information'
    ]
  },
  3: {
    name: 'SPHP Record',
    subtitle: 'Tax Correction Record',
    emoji: '✏️',
    description: 'Record the SPHP (Surat Pemberitahuan Hasil Pemeriksaan) - the notification of audit findings and corrections.',
    requiredDocs: [
      'SPHP letter from tax authority',
      'Audit findings breakdown',
      'Correction details'
    ],
    inputFields: [
      'SPHP Number',
      'Issue Date',
      'Receipt Date',
      'Royalty Correction Amount',
      'Service Correction Amount',
      'Other Correction Amount'
    ]
  },
  4: {
    name: 'SKP Record',
    subtitle: 'Tax Assessment Letter',
    emoji: '🔍',
    description: 'Record the SKP (Surat Ketetapan Pajak) - the tax assessment letter that determines the final tax amount and classification.',
    requiredDocs: [
      'SKP letter from tax authority',
      'Tax assessment details',
      'SKP type classification (LB/NIHIL/KB)'
    ],
    inputFields: [
      'SKP Number',
      'Issue Date',
      'Receipt Date',
      'SKP Type (Lebih Bayar/NIHIL/Kurang Bayar)',
      'SKP Amount',
      'Audit Corrections Breakdown'
    ]
  },
  5: {
    name: 'Objection',
    subtitle: 'Formal Objection Filing',
    emoji: '⚠️',
    description: 'File a formal objection (Surat Keberatan) against the tax authority\'s decision if you disagree with the assessment.',
    requiredDocs: [
      'Surat Keberatan (Objection Letter)',
      'Supporting evidence',
      'Legal basis for objection'
    ],
    inputFields: [
      'Objection Letter Number',
      'Submission Date',
      'Objection Amount',
      'Objection Grounds'
    ]
  },
  6: {
    name: 'Appeal',
    subtitle: 'Administrative Appeal',
    emoji: '⚖️',
    description: 'File an appeal (Surat Banding) to the tax court if the objection is rejected or partially granted but you wish to pursue further.',
    requiredDocs: [
      'Surat Banding (Appeal Letter)',
      'Court filing documents',
      'Legal arguments'
    ],
    inputFields: [
      'Appeal Letter Number',
      'Submission Date',
      'Appeal Amount',
      'Appeal Grounds'
    ]
  },
  7: {
    name: 'Supreme Court',
    subtitle: 'Cassation to Supreme Court',
    emoji: '⚖️',
    description: 'Request a cassation (review) to the Supreme Court if you wish to challenge the tax court\'s decision on points of law.',
    requiredDocs: [
      'Permohonan Peninjauan Kembali',
      'Supreme Court filing forms',
      'Legal memorandum'
    ],
    inputFields: [
      'Cassation Request Number',
      'Submission Date',
      'Legal Points in Question',
      'Amount in Dispute'
    ]
  },
  8: {
    name: 'Refund',
    subtitle: 'Process Refund',
    emoji: '💰',
    description: 'Process the refund if your case has been approved. Choose refund method and provide banking details.',
    requiredDocs: [
      'Bank account details',
      'Refund authorization',
      'Tax office confirmation'
    ],
    inputFields: [
      'Refund Method (Bank Transfer/Check/Offset)',
      'Bank Name (if applicable)',
      'Account Number (if applicable)',
      'Refund Amount',
      'Expected Processing Timeline'
    ]
  }
}

const stageInfo = computed(() => {
  return stageDefinitions[props.stageId] || {}
})

const stageName = computed(() => stageInfo.value.name || 'Unknown Stage')
const stageSubtitle = computed(() => stageInfo.value.subtitle || '')
const stageEmoji = computed(() => stageInfo.value.emoji || '📋')

const closeDrawer = () => {
  emit('close')
}

const formatCurrency = (amount) => {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0
  }).format(amount || 0)
}

const loadCases = async () => {
  loading.value = true
  try {
    // Fetch all cases
    const response = await fetch('/api/tax-cases')
    if (response.ok) {
      let allCases = await response.json()
      
      // Handle different response formats
      if (!Array.isArray(allCases)) {
        if (allCases.data && Array.isArray(allCases.data)) {
          allCases = allCases.data
        } else {
          allCases = []
        }
      }
      
      // Filter cases by stage (mock - in Phase 3 this will be real)
      cases.value = allCases.filter(c => c.current_stage === props.stageId)
    }
  } catch (error) {
    console.error('Failed to load cases:', error)
    cases.value = [] // Fallback to empty array
  } finally {
    loading.value = false
  }
}

// Load cases when drawer opens or stage changes
watch(() => props.isOpen, (newVal) => {
  if (newVal && props.stageId) {
    loadCases()
  }
})

// Initial load if already open
onMounted(() => {
  if (props.isOpen && props.stageId) {
    loadCases()
  }
})

import { watch } from 'vue'
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.slide-enter-active,
.slide-leave-active {
  transition: transform 0.3s ease;
}

.slide-enter-from,
.slide-leave-to {
  transform: translateX(100%);
}
</style>
