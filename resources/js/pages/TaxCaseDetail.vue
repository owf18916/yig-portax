<template>
  <div class="space-y-6">
    <div class="flex items-center space-x-4">
      <Button @click="$router.back()" variant="secondary">← Back</Button>
      <h1 class="text-3xl font-bold text-gray-900">{{ caseNumber }}</h1>
    </div>

    <Alert
      v-if="apiError"
      type="error"
      title="Error"
      :message="apiError"
    />

    <LoadingSpinner v-if="loading" message="Loading tax case..." />

    <div v-else class="space-y-6">
      <!-- Case Overview -->
      <Card title="Case Overview" subtitle="Basic information">
        <div class="grid grid-cols-2 gap-6">
          <div>
            <p class="text-sm text-gray-600">Case Number</p>
            <p class="text-lg font-bold">{{ caseData.case_number }}</p>
          </div>
          <div>
            <p class="text-sm text-gray-600">Case Type</p>
            <p :class="['text-lg font-bold px-3 py-1 rounded-full inline-block', caseData.case_type === 'VAT' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800']">
              {{ caseData.case_type }}
            </p>
          </div>
          <div>
            <p class="text-sm text-gray-600">Entity Name</p>
            <p class="text-lg font-bold">{{ caseData.entity_name }}</p>
          </div>
          <div>
            <p class="text-sm text-gray-600">Status</p>
            <p class="inline-block px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
              {{ formatStatus(caseData.status?.name || caseData.status || 'Draft') }}
            </p>
          </div>
          <div>
            <p class="text-sm text-gray-600">Disputed Amount</p>
            <p class="text-lg font-bold">{{ formatCurrency(caseData.disputed_amount || 0, caseData.currency?.code) }}</p>
          </div>
        </div>
      </Card>

      <!-- Workflow Progress with Collapsible Sections -->
      <Card title="Workflow Progress" subtitle="Track the case through stages">
        <div class="space-y-4">
          <!-- MAIN FLOW SECTION -->
          <div class="border rounded-lg overflow-hidden">
            <button
              @click="expandedSections.main = !expandedSections.main"
              class="w-full px-4 py-3 bg-gray-100 hover:bg-gray-200 flex items-center justify-between transition"
            >
              <div class="flex items-center space-x-3">
                <span :class="['transform transition', expandedSections.main ? 'rotate-90' : '']">▶</span>
                <h3 class="font-semibold text-gray-900">MAIN FLOW</h3>
                <span class="text-sm text-gray-600">(Stages 1-12)</span>
              </div>
            </button>
            <transition
              enter-active-class="transition ease-out duration-100"
              enter-from-class="transform opacity-0 -translate-y-2"
              enter-to-class="transform opacity-100"
              leave-active-class="transition ease-in duration-75"
              leave-from-class="transform opacity-100"
              leave-to-class="transform opacity-0 -translate-y-2"
            >
              <div v-show="expandedSections.main" class="space-y-2 p-4 bg-gray-50">
                <div v-for="stage in getStagesByBranch('main')" :key="stage.id" class="flex items-center space-x-3 p-3 rounded-lg bg-white border border-gray-200 hover:border-blue-300 transition">
                  <div
                    :class="[
                      'w-8 h-8 rounded-full flex items-center justify-center text-white font-bold shrink-0',
                      stage.completed ? 'bg-green-500' : stage.accessible ? 'bg-blue-500' : 'bg-gray-300'
                    ]"
                  >
                    {{ stage.id }}
                  </div>
                  <div class="flex-1">
                    <div class="flex items-center space-x-2">
                      <p class="font-medium text-gray-900">{{ stage.name }}</p>
                      <span v-if="stage.id === 4 || stage.id === 7 || stage.id === 10 || stage.id === 12" class="text-xs font-semibold px-2 py-0.5 bg-blue-100 text-blue-800 rounded">
                        Decision Point
                      </span>
                    </div>
                    <p class="text-sm text-gray-600">{{ stage.description }}</p>
                  </div>
                  <Button
                    v-if="canAccessStage(stage.id)"
                    @click="$router.push(`/tax-cases/${$route.params.id}/workflow/${stage.id}`)"
                    variant="primary"
                    size="sm"
                    :disabled="!stage.accessible"
                  >
                    Access
                  </Button>
                </div>
              </div>
            </transition>
          </div>

          <!-- REFUND PROCESS SECTION -->
          <div class="border rounded-lg overflow-hidden" v-if="workflowStages.some(s => s.branch === 'refund')">
            <button
              @click="expandedSections.refund = !expandedSections.refund"
              class="w-full px-4 py-3 bg-green-100 hover:bg-green-200 flex items-center justify-between transition"
              :disabled="!isRefundBranchActive()"
              :class="isRefundBranchActive() ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'"
            >
              <div class="flex items-center space-x-3">
                <span :class="['transform transition', expandedSections.refund ? 'rotate-90' : '']">▶</span>
                <h3 class="font-semibold text-green-900">REFUND PROCESS</h3>
                <span class="text-sm text-green-700">(Stages 13-15)</span>
                <span v-if="!isRefundBranchActive()" class="text-xs text-green-600 italic">- Awaiting decision</span>
              </div>
              <span v-if="isRefundBranchActive()" class="px-2 py-1 bg-green-500 text-white text-xs font-semibold rounded">Active</span>
            </button>
            <transition
              enter-active-class="transition ease-out duration-100"
              enter-from-class="transform opacity-0 -translate-y-2"
              enter-to-class="transform opacity-100"
              leave-active-class="transition ease-in duration-75"
              leave-from-class="transform opacity-100"
              leave-to-class="transform opacity-0 -translate-y-2"
            >
              <div v-show="expandedSections.refund && isRefundBranchActive()" class="space-y-2 p-4 bg-green-50">
                <div v-for="stage in getStagesByBranch('refund')" :key="stage.id" class="flex items-center space-x-3 p-3 rounded-lg bg-white border border-green-200 hover:border-green-400 transition">
                  <div
                    :class="[
                      'w-8 h-8 rounded-full flex items-center justify-center text-white font-bold shrink-0',
                      stage.completed ? 'bg-green-500' : stage.accessible ? 'bg-green-500' : 'bg-gray-300'
                    ]"
                  >
                    {{ stage.id }}
                  </div>
                  <div class="flex-1">
                    <p class="font-medium text-gray-900">{{ stage.name }}</p>
                    <p class="text-sm text-gray-600">{{ stage.description }}</p>
                  </div>
                  <Button
                    v-if="canAccessStage(stage.id)"
                    @click="$router.push(`/tax-cases/${$route.params.id}/workflow/${stage.id}`)"
                    variant="primary"
                    size="sm"
                    :disabled="!stage.accessible"
                  >
                    Access
                  </Button>
                </div>
              </div>
            </transition>
          </div>

          <!-- KIAN SUBMISSION SECTION -->
          <div class="border rounded-lg overflow-hidden" v-if="workflowStages.some(s => s.branch === 'kian')">
            <button
              @click="expandedSections.kian = !expandedSections.kian"
              class="w-full px-4 py-3 bg-amber-100 hover:bg-amber-200 flex items-center justify-between transition"
              :disabled="!isKianBranchActive()"
              :class="isKianBranchActive() ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'"
            >
              <div class="flex items-center space-x-3">
                <span :class="['transform transition', expandedSections.kian ? 'rotate-90' : '']">▶</span>
                <h3 class="font-semibold text-amber-900">KIAN SUBMISSION</h3>
                <span class="text-sm text-amber-700">(Stage 16)</span>
                <span v-if="!isKianBranchActive()" class="text-xs text-amber-600 italic">- Awaiting decision</span>
              </div>
              <span v-if="isKianBranchActive()" class="px-2 py-1 bg-amber-500 text-white text-xs font-semibold rounded">Active</span>
            </button>
            <transition
              enter-active-class="transition ease-out duration-100"
              enter-from-class="transform opacity-0 -translate-y-2"
              enter-to-class="transform opacity-100"
              leave-active-class="transition ease-in duration-75"
              leave-from-class="transform opacity-100"
              leave-to-class="transform opacity-0 -translate-y-2"
            >
              <div v-show="expandedSections.kian && isKianBranchActive()" class="space-y-2 p-4 bg-amber-50">
                <div v-for="stage in getStagesByBranch('kian')" :key="stage.id" class="flex items-center space-x-3 p-3 rounded-lg bg-white border border-amber-200 hover:border-amber-400 transition">
                  <div
                    :class="[
                      'w-8 h-8 rounded-full flex items-center justify-center text-white font-bold shrink-0',
                      stage.completed ? 'bg-amber-500' : stage.accessible ? 'bg-amber-500' : 'bg-gray-300'
                    ]"
                  >
                    {{ stage.id }}
                  </div>
                  <div class="flex-1">
                    <p class="font-medium text-gray-900">{{ stage.name }}</p>
                    <p class="text-sm text-gray-600">{{ stage.description }}</p>
                  </div>
                  <Button
                    v-if="canAccessStage(stage.id)"
                    @click="$router.push(`/tax-cases/${$route.params.id}/workflow/${stage.id}`)"
                    variant="primary"
                    size="sm"
                    :disabled="!stage.accessible"
                  >
                    Access
                  </Button>
                </div>
              </div>
            </transition>
          </div>
        </div>
      </Card>

      <!-- Documents section removed - documents uploaded per stage -->
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Card from '../components/Card.vue'
import Button from '../components/Button.vue'
import Alert from '../components/Alert.vue'
import LoadingSpinner from '../components/LoadingSpinner.vue'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const apiError = ref('')
const caseNumber = ref('TAX-2026-001')
const caseData = ref({
  case_number: '',
  case_type: 'CIT',
  entity_name: '',
  amount: 0,
  status: 'draft'
})
const documents = ref([])
const workflowHistory = ref([])

// State for collapsible sections
const expandedSections = ref({
  main: true,    // Main flow always expanded by default
  refund: false, // Refund collapsed by default, auto-expand when active
  kian: false    // KIAN collapsed by default, auto-expand when active
})

const workflowStages = ref([
  // Main Flow - Sequential Stages (1-12)
  { id: 1, name: 'SPT Filing', description: 'Surat Pemberitahuan Tahunan - Initial tax return submission', branch: 'main', completed: false, accessible: true },
  { id: 2, name: 'SP2 Receipt', description: 'Surat Pemberitahuan Pemeriksaan - Audit notification', branch: 'main', completed: false, accessible: false },
  { id: 3, name: 'SPHP Receipt', description: 'Surat Pemberitahuan Hasil Pemeriksaan - Audit findings notification', branch: 'main', completed: false, accessible: false },
  { id: 4, name: 'SKP Receipt', description: 'Surat Ketetapan Pajak - Tax assessment letter (Decision Point)', branch: 'main', completed: false, accessible: false },
  { id: 5, name: 'Objection Submission', description: 'Surat Keberatan - Filing objection to SKP', branch: 'main', completed: false, accessible: false },
  { id: 6, name: 'SPUH Receipt', description: 'Surat Pemberitahuan Untuk Hadir - Summon letter', branch: 'main', completed: false, accessible: false },
  { id: 7, name: 'Objection Decision', description: 'Keputusan Keberatan - Decision on objection (Decision Point)', branch: 'main', completed: false, accessible: false },
  { id: 8, name: 'Appeal Submission', description: 'Surat Banding - Filing appeal to tax court', branch: 'main', completed: false, accessible: false },
  { id: 9, name: 'Appeal Explanation Request', description: 'Permintaan Penjelasan Banding - Tax court explanation request', branch: 'main', completed: false, accessible: false },
  { id: 10, name: 'Appeal Decision', description: 'Keputusan Banding - Tax court decision (Decision Point)', branch: 'main', completed: false, accessible: false },
  { id: 11, name: 'Supreme Court Submission', description: 'Surat Peninjauan Kembali - Supreme court review submission', branch: 'main', completed: false, accessible: false },
  { id: 12, name: 'Supreme Court Decision', description: 'Keputusan Peninjauan Kembali - Final decision from Supreme Court (Decision Point)', branch: 'main', completed: false, accessible: false },
  
  // Refund Branch - Sequential Stages (13-15)
  { id: 13, name: 'Bank Transfer Request', description: 'Surat Permintaan Transfer - Requesting refund transfer', branch: 'refund', completed: false, accessible: false },
  { id: 14, name: 'Transfer Instruction', description: 'Surat Instruksi Transfer - Bank transfer instruction from tax authority', branch: 'refund', completed: false, accessible: false },
  { id: 15, name: 'Refund Received', description: 'Actual receipt of refund funds', branch: 'refund', completed: false, accessible: false },
  
  // KIAN Branch - Final Stage (16)
  { id: 16, name: 'KIAN Submission', description: 'Laporan KIAN - Internal loss recognition document', branch: 'kian', completed: false, accessible: false }
])

// Helper function: Cek pilihan routing user dari SKP record
const getUserRoutingChoice = () => {
  // Try both camelCase and snake_case for compatibility
  const skpRecord = caseData.value.skpRecord || caseData.value.skp_record
  if (!skpRecord) return null
  return skpRecord.user_routing_choice || null
}

// Helper function: Get Stage 7 decision type (for auto-routing)
const getStage7Decision = () => {
  // Try both camelCase and snake_case for compatibility
  const objDecision = caseData.value.objectionDecision || caseData.value.objection_decision
  if (objDecision && objDecision.decision_type) {
    console.log('[getStage7Decision] ✓ Found in objectionDecision/objection_decision:', objDecision.decision_type)
    return objDecision.decision_type
  }
  
  // FALLBACK: Check workflow history for Stage 7 decision_value
  // Sometimes the decision might be in workflow history before objectionDecision is fully loaded
  const stage7History = workflowHistory.value.find(h => h.stage_id === 7 && h.status === 'submitted')
  if (stage7History && stage7History.decision_value) {
    console.log('[getStage7Decision] ⚠️ Using Stage 7 decision from workflow history (fallback):', stage7History.decision_value)
    return stage7History.decision_value
  }
  
  console.log('[getStage7Decision] ❌ No Stage 7 decision found anywhere!')
  return null
}

// Helper function: Get Stage 12 decision type (Final decision point)
const getStage12Decision = () => {
  // Try both camelCase and snake_case for compatibility
  const pkDecision = caseData.value.supremeCourtDecisionRecord || caseData.value.supreme_court_decision_record
  if (pkDecision && pkDecision.next_action) {
    console.log('[getStage12Decision] ✓ Found next_action in supremeCourtDecisionRecord:', pkDecision.next_action)
    return pkDecision.next_action
  }
  
  // FALLBACK: Check workflow history for Stage 12
  const stage12History = workflowHistory.value.find(h => h.stage_id === 12 && h.status === 'submitted')
  if (stage12History && stage12History.decision_value) {
    console.log('[getStage12Decision] ⚠️ Using Stage 12 decision from workflow history (fallback):', stage12History.decision_value)
    return stage12History.decision_value
  }
  
  console.log('[getStage12Decision] ❌ No Stage 12 decision found anywhere!')
  return null
}

// Helper function: Check apakah refund branch active
const isRefundBranchActive = () => {
  return getUserRoutingChoice() === 'refund'
}

// Helper function: Check apakah KIAN branch active (simple logic for now)
const isKianBranchActive = () => {
  // KIAN active jika sudah completed semua refund stages
  // Untuk sekarang, return false (KIAN akan dikembangkan kemudian)
  return false
}

// Helper function: Get stages by branch
const getStagesByBranch = (branch) => {
  return workflowStages.value.filter(s => s.branch === branch)
}

// Function untuk update accessibility berdasarkan workflow history
const updateStageAccessibility = () => {
  console.log('[updateStageAccessibility] 🔄 STARTING accessibility calculation...')
  
  // Helper function: cek apakah stage tertentu sudah completed
  const isStageCompleted = (stageId) => {
    const result = workflowHistory.value.some(
      h => h.stage_id === stageId && (h.status === 'submitted' || h.status === 'completed')
    )
    if (stageId === 7) {
      console.log(`[isStageCompleted] Stage ${stageId}: ${result} (found ${workflowHistory.value.filter(h => h.stage_id === stageId).length} records)`)
    }
    return result
  }
  
  // Helper function: ambil decision dari decision point stages (4, 7, 10, 12)
  const getDecisionForStage = (stageId) => {
    const history = workflowHistory.value.find(
      h => h.stage_id === stageId && h.decision
    )
    return history ? history.decision : null
  }
  
  // Helper function: Check apakah stage 4 completed
  const isStage4Completed = () => {
    return isStageCompleted(4)
  }
  
  workflowStages.value.forEach((stage) => {
    stage.accessible = false
    stage.completed = isStageCompleted(stage.id)
    
    if (stage.branch === 'main') {
      // MAIN FLOW: Sequential logic
      if (stage.id === 1) {
        // Stage 1 selalu accessible
        stage.accessible = true
      } else if (stage.id === 5) {
        // SPECIAL: Stage 5 (Objection) - hanya accessible jika:
        // 1. Stage 4 completed AND
        // 2. User memilih "objection" (NOT refund branch active)
        const userChoice = getUserRoutingChoice()
        if (isStage4Completed() && userChoice === 'objection') {
          stage.accessible = true
          console.log('[Accessibility] Stage 5 ACCESSIBLE - Objection path chosen')
        } else if (isStage4Completed() && userChoice === 'refund') {
          stage.accessible = false
          console.log('[Accessibility] Stage 5 LOCKED - Refund path chosen')
        } else if (!isStage4Completed()) {
          stage.accessible = false
          console.log('[Accessibility] Stage 5 LOCKED - Stage 4 not completed')
        }
      } else if (stage.id === 7) {
        // SPECIAL: Stage 7 (Objection Decision) - Auto-routing based on decision_type
        // If Stage 7 completed:
        //   - granted → must go to Stage 13 (Refund)
        //   - rejected → must go to Stage 8 (Appeal)
        //   - partially_granted → user must choose (via frontend buttons)
        if (isStageCompleted(6)) {
          // Stage 6 (SPUH) completed, so Stage 7 is now accessible
          stage.accessible = true
          console.log('[Accessibility] Stage 7 ACCESSIBLE - Previous stage (Stage 6) completed')
        } else {
          stage.accessible = false
          console.log('[Accessibility] Stage 7 LOCKED - Stage 6 not completed')
        }
      } else if (stage.id === 8) {
        // SPECIAL: Stage 8 (Appeal) - Accessible only if:
        // - Stage 7 completed AND decision_type is 'rejected' OR partially_granted with user choice
        const stage7Decision = getStage7Decision()
        const stage7Completed = isStageCompleted(7)
        
        if (!stage7Completed) {
          stage.accessible = false
          console.log('[Accessibility] Stage 8 LOCKED - Stage 7 not completed')
        } else if (stage7Decision === 'rejected') {
          // Auto-routed to Appeal
          stage.accessible = true
          console.log('[Accessibility] Stage 8 ACCESSIBLE - Stage 7 decision: REJECTED')
        } else if (stage7Decision === 'partially_granted') {
          // Check if user made choice to go to Appeal (via workflow_histories stage_to)
          const stage7History = workflowHistory.value.find(h => h.stage_id === 7 && h.status === 'submitted')
          if (stage7History && stage7History.stage_to === 8) {
            stage.accessible = true
            console.log('[Accessibility] Stage 8 ACCESSIBLE - Stage 7 partially granted, user chose Appeal')
          } else {
            stage.accessible = false
            console.log('[Accessibility] Stage 8 LOCKED - Waiting for user choice at Stage 7')
          }
        } else {
          // granted → goes to Stage 13, not Stage 8
          stage.accessible = false
          console.log('[Accessibility] Stage 8 LOCKED - Stage 7 decision: GRANTED (goes to Refund, not Appeal)')
        }
      } else if (stage.id > 5) {
        // Stages setelah 5 (except 7-8, those handled above) (6, 9-12)
        // Jika refund branch active, jangan bisa access stage 6-12
        const userChoice = getUserRoutingChoice()
        if (userChoice === 'refund' && isStage4Completed()) {
          stage.accessible = false
          console.log(`[Accessibility] Stage ${stage.id} LOCKED - Refund path active`)
        } else if (userChoice === 'objection' || !isRefundBranchActive()) {
          // Normal sequential: accessible jika prev stage completed
          const previousStage = stage.id - 1
          stage.accessible = isStageCompleted(previousStage)
          console.log(`[Accessibility] Stage ${stage.id} - Sequential logic (prev stage completed: ${stage.accessible})`)
        }
      } else {
        // Stages 2-4: sequential
        const previousStage = stage.id - 1
        stage.accessible = isStageCompleted(previousStage)
      }
    } else if (stage.branch === 'refund') {
      // REFUND BRANCH: Accessible if:
      // 1. User chose "refund" path from Stage 4 AND Stage 4 completed, OR
      // 2. Stage 7 decision is "granted" (auto-routed) OR "partially_granted" with user choice to refund
      const userChoice = getUserRoutingChoice()
      const stage7Decision = getStage7Decision()
      const stage7Completed = isStageCompleted(7)
      const stage4Completed = isStage4Completed()
      
      let canAccessRefund = false
      let reason = ''
      
      // Check if Stage 4 refund path active
      if (userChoice === 'refund' && stage4Completed) {
        canAccessRefund = true
        reason = 'Stage 4 refund path chosen'
      }
      // Check if Stage 7 granted (auto-routed to refund)
      else if (stage7Completed && stage7Decision === 'granted') {
        canAccessRefund = true
        reason = 'Stage 7 decision: GRANTED'
      }
      // Check if Stage 7 rejected but user chose refund, OR partially_granted with refund choice
      else if (stage7Completed && (stage7Decision === 'rejected' || stage7Decision === 'partially_granted')) {
        // Check if Stage 7 workflow history has stage_to = 13 (user or auto chose refund)
        const stage7History = workflowHistory.value.find(
          h => h.stage_id === 7 && (h.status === 'submitted' || h.status === 'completed')
        )
        if (stage7History && stage7History.stage_to === 13) {
          canAccessRefund = true
          reason = `Stage 7 decision: ${stage7Decision} with stage_to=13`
        }
      }
      
      console.log(`[Accessibility DEBUG] Stage ${stage.id} (refund branch) - canAccessRefund=${canAccessRefund}, stage7Completed=${stage7Completed}, stage7Decision='${stage7Decision}', reason='${reason}'`)
      
      if (canAccessRefund) {
        if (stage.id === 13) {
          stage.accessible = true
          console.log(`[Accessibility] ✓ Stage 13 ACCESSIBLE - ${reason}`)
        } else {
          // Stage 14 accessible jika stage 13 completed, dst
          const previousStage = stage.id - 1
          stage.accessible = isStageCompleted(previousStage)
          console.log(`[Accessibility] Stage ${stage.id} - Refund path sequential (prev completed: ${stage.accessible})`)
        }
      } else if (userChoice === 'objection') {
        stage.accessible = false
        console.log(`[Accessibility] Stage ${stage.id} LOCKED - Objection path chosen`)
      } else {
        // userChoice is empty or null
        stage.accessible = false
        console.log(`[Accessibility] Stage ${stage.id} LOCKED - No routing choice made yet (userChoice='${userChoice}')`)
      }
    } else if (stage.branch === 'kian') {
      // KIAN BRANCH: Accessible jika final rejection atau objection process complete
      // Stage 12 (Supreme Court Decision) is the decision point for refund vs KIAN
      // If Stage 12 completed with 'kian' choice → Stages 16+ accessible
      // If Stage 12 completed with 'refund' choice → Stages 13-15 accessible instead
      
      const stage12Decision = getStage12Decision()
      const stage12Completed = isStageCompleted(12)
      
      console.log(`[Accessibility DEBUG] KIAN branch - stage=${stage.id}, stage12Completed=${stage12Completed}, stage12Decision='${stage12Decision}'`)
      
      if (stage.id === 16) {
        // Stage 16 (KIAN Report) - accessible if Stage 12 completed with 'kian' choice
        if (!stage12Completed) {
          stage.accessible = false
          console.log('[Accessibility] Stage 16 LOCKED - Stage 12 not completed')
        } else if (stage12Decision === 'kian') {
          stage.accessible = true
          console.log('[Accessibility] ✓ Stage 16 ACCESSIBLE - Stage 12 decision: KIAN')
        } else {
          stage.accessible = false
          console.log('[Accessibility] Stage 16 LOCKED - Stage 12 decision: REFUND (goes to Stage 13 instead)')
        }
      } else if (stage.id > 16) {
        // Stages setelah 16 (e.g., 17, 18)
        if (stage12Decision === 'kian') {
          // KIAN path active: sequential logic from Stage 16
          const previousStage = stage.id - 1
          stage.accessible = isStageCompleted(previousStage)
          console.log(`[Accessibility] Stage ${stage.id} - KIAN path sequential (prev completed: ${stage.accessible})`)
        } else {
          stage.accessible = false
          console.log(`[Accessibility] Stage ${stage.id} LOCKED - KIAN path not active (stage12Decision='${stage12Decision}')`)
        }
      }
    }
  })
  
  // Auto-expand refund section jika active
  if (isRefundBranchActive()) {
    expandedSections.value.refund = true
  }
}

onMounted(async () => {
  try {
    const response = await fetch(`/api/tax-cases/${route.params.id}`, {
      credentials: 'include',
      headers: { 'Accept': 'application/json' }
    })
    if (!response.ok) throw new Error('Failed to load case')
    const responseData = await response.json()
    
    // Handle API response wrapper: { success, message, data: {...} }
    if (responseData.data) {
      const data = responseData.data
      // If it's the case object itself (not pagination)
      if (data.id && data.case_number) {
        caseData.value = data
      } else if (Array.isArray(data)) {
        // Shouldn't happen for single case, but handle it
        caseData.value = data[0] || {}
      } else {
        caseData.value = data
      }
    } else {
      caseData.value = responseData
    }
    
    // Load workflow history untuk determine accessibility
    if (caseData.value.workflowHistories && Array.isArray(caseData.value.workflowHistories)) {
      workflowHistory.value = caseData.value.workflowHistories
    }
    
    // Always fetch workflow history separately to ensure we have latest data
    if (caseData.value.id) {
      try {
        const historyResponse = await fetch(`/api/tax-cases/${route.params.id}/workflow-history`, {
          credentials: 'include',
          headers: { 'Accept': 'application/json' }
        })
        if (historyResponse.ok) {
          const historyData = await historyResponse.json()
          const histories = historyData.data || historyData
          if (Array.isArray(histories)) {
            workflowHistory.value = histories
          }
        }
      } catch (e) {
        console.warn('Could not load workflow history:', e)
      }
    }
    
    // Update stage accessibility berdasarkan SKP record user_routing_choice
    updateStageAccessibility()
    
    caseNumber.value = caseData.value.case_number || 'TAX-2026-001'
    console.log('✓ Case loaded, caseData:', caseData.value)
    console.log('✓ skpRecord:', caseData.value.skpRecord)
    console.log('✓ objectionDecision:', caseData.value.objectionDecision)
    console.log('✓ objectionDecision?.decision_type:', caseData.value.objectionDecision?.decision_type)
    console.log('✓ Stage 4 user choice:', getUserRoutingChoice())
    console.log('✓ Stage 7 decision:', getStage7Decision())
  } catch (error) {
    apiError.value = error.message
    console.error('Failed to load case:', error)
  } finally {
    loading.value = false
  }
})

// ⭐ Reload function to refresh case data and recalculate accessibility
const reloadCaseData = async () => {
  console.log('[TaxCaseDetail] 🔄 RELOAD TRIGGERED - Fetching fresh case data...')
  loading.value = true
  try {
    const response = await fetch(`/api/tax-cases/${route.params.id}`, {
      credentials: 'include',
      headers: { 'Accept': 'application/json' }
    })
    if (!response.ok) throw new Error('Failed to reload case')
    const responseData = await response.json()
    
    if (responseData.data) {
      const data = responseData.data
      if (data.id && data.case_number) {
        caseData.value = data
      } else if (Array.isArray(data)) {
        caseData.value = data[0] || {}
      } else {
        caseData.value = data
      }
    } else {
      caseData.value = responseData
    }
    
    // Reload workflow history
    if (caseData.value.workflowHistories && Array.isArray(caseData.value.workflowHistories)) {
      workflowHistory.value = caseData.value.workflowHistories
    }
    
    if (caseData.value.id) {
      try {
        const historyResponse = await fetch(`/api/tax-cases/${route.params.id}/workflow-history`, {
          credentials: 'include',
          headers: { 'Accept': 'application/json' }
        })
        if (historyResponse.ok) {
          const historyData = await historyResponse.json()
          const histories = historyData.data || historyData
          if (Array.isArray(histories)) {
            workflowHistory.value = histories
          }
        }
      } catch (e) {
        console.warn('Could not reload workflow history:', e)
      }
    }
    
    // ⭐ DEBUG: Log what we loaded
    console.log('Full caseFetchedData loaded')
    console.log('  skpRecord:', caseData.value.skpRecord)
    console.log('  objectionDecision:', caseData.value.objectionDecision)
    console.log('  objection_decision:', caseData.value.objection_decision)
    console.log('  objectionDecision?.decision_type:', caseData.value.objectionDecision?.decision_type)
    console.log('  objection_decision?.decision_type:', caseData.value.objection_decision?.decision_type)
    console.log('  workflowHistory count:', workflowHistory.value.length)
    
    // Recalculate accessibility
    updateStageAccessibility()
    console.log('✓ Case data reloaded successfully')
  } catch (error) {
    console.error('Failed to reload case:', error)
    apiError.value = error.message
  } finally {
    loading.value = false
  }
}

// ⭐ Watch for route changes and reload data
watch(
  () => route.params.id,
  (newId, oldId) => {
    if (newId && newId !== oldId) {
      console.log(`[TaxCaseDetail] Route changed: ${oldId} → ${newId}, reloading data`)
      reloadCaseData()
    }
  }
)

// ⭐ Watch for changes in SKP record user_routing_choice to update stage accessibility
watch(
  () => [caseData.value?.skpRecord?.user_routing_choice, caseData.value?.skp_record?.user_routing_choice],
  ([newRoutingChoice1, newRoutingChoice2], [oldRoutingChoice1, oldRoutingChoice2]) => {
    const newChoice = newRoutingChoice1 || newRoutingChoice2
    const oldChoice = oldRoutingChoice1 || oldRoutingChoice2
    if (newChoice !== oldChoice) {
      console.log('[TaxCaseDetail] SKP routing choice changed:', oldChoice, '→', newChoice)
      updateStageAccessibility()
    }
  }
)

// ⭐ Watch for changes in Objection Decision to update stage accessibility
watch(
  () => caseData.value,
  (newCaseData) => {
    if (newCaseData) {
      const newDecision = newCaseData.objectionDecision?.decision_type || newCaseData.objection_decision?.decision_type
      if (newDecision) {
        console.log('[TaxCaseDetail] 🔔 Objection decision detected:', newDecision)
        updateStageAccessibility()
      }
    }
  },
  { deep: true }
)

// ⭐ Watch for changes in workflow history (stage_to field for routing)
watch(
  () => workflowHistory.value.map(h => `${h.stage_id}-${h.stage_to}`).join(','),
  (newHistory, oldHistory) => {
    if (newHistory !== oldHistory) {
      console.log('[TaxCaseDetail] Workflow history changed, recalculating accessibility')
      updateStageAccessibility()
    }
  }
)

const canAccessStage = (stageId) => {
  const stage = workflowStages.value.find(s => s.id === stageId)
  return stage && stage.accessible
}

const formatStatus = (status) => {
  if (!status) return 'Draft'
  return status.charAt(0).toUpperCase() + status.slice(1)
}

const formatCurrency = (amount, currencyCode = 'IDR') => {
  // Map currency codes to locale and options
  const currencyMap = {
    'IDR': { locale: 'id-ID', code: 'IDR' },
    'USD': { locale: 'en-US', code: 'USD' },
    'EUR': { locale: 'de-DE', code: 'EUR' },
    'SGD': { locale: 'en-SG', code: 'SGD' }
  }
  
  const currencyConfig = currencyMap[currencyCode] || currencyMap['IDR']
  
  return new Intl.NumberFormat(currencyConfig.locale, {
    style: 'currency',
    currency: currencyConfig.code,
    minimumFractionDigits: 0
  }).format(amount)
}
</script>
