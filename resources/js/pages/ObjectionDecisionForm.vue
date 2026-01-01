<template>
  <div>
    <StageForm
      :stageName="`Stage 7: Objection Decision`"
      :stageDescription="`Record the decision on the Surat Keberatan (Objection) from the tax authority`"
      :stageId="7"
      :nextStageId="8"
      :caseId="caseId"
      :caseNumber="caseNumber"
      :fields="fields"
      :apiEndpoint="`/api/tax-cases/${caseId}/workflow/7`"
      @submit="handleSubmit"
      @saveDraft="handleSaveDraft"
    />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import StageForm from '../components/StageForm.vue'

const route = useRoute()
const router = useRouter()
const caseId = route.params.id
const caseNumber = ref('TAX-2026-001')

const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'decision_number',
    label: 'Decision Number (Nomor Surat Keputusan)',
    placeholder: 'e.g., KPT-2024-001234',
    required: true,
    value: ''
  },
  {
    id: 2,
    type: 'date',
    key: 'decision_date',
    label: 'Decision Date (Tanggal Keputusan)',
    required: true,
    value: ''
  },
  {
    id: 3,
    type: 'radio',
    key: 'decision_type',
    label: '⭐ Objection Decision (DECISION POINT)',
    options: [
      { value: 'granted', label: 'Dikabulkan (Granted) ➜ REFUND' },
      { value: 'partially_granted', label: 'Dikabulkan Sebagian (Partially Granted) ➜ REFUND' },
      { value: 'rejected', label: 'Ditolak (Rejected) ➜ APPEAL' }
    ],
    required: true,
    value: ''
  },
  {
    id: 4,
    type: 'number',
    key: 'decision_amount',
    label: 'Decision Amount (Nilai)',
    placeholder: 'e.g., 5000000000',
    required: true,
    value: ''
  },
  {
    id: 5,
    type: 'textarea',
    key: 'decision_notes',
    label: 'Decision Notes/Summary',
    placeholder: 'Summary of the objection decision...',
    required: false,
    value: ''
  }
])

onMounted(async () => {
  try {
    const response = await fetch(`/api/tax-cases/${caseId}`)
    if (response.ok) {
      const caseData = await response.json()
      caseNumber.value = caseData.case_number
    }
  } catch (error) {
    console.error('Failed to load case data:', error)
  }
})

const handleSubmit = async (event) => {
  // event contains { stageId, nextStageId, caseId, data }
  console.log('Objection Decision submitted:', event)

  // Get decision type to determine next stage
  const decisionType = event.data.decision_type // Should be sent from StageForm

  // Decision routing logic
  let nextStageId = null
  if (decisionType === 'granted' || decisionType === 'partially_granted') {
    // Granted or Partially Granted → Refund Process (Stage 12)
    nextStageId = 12
    console.log(`✅ Decision: ${decisionType} → Routing to REFUND PROCESS (Stage 12)`)
  } else if (decisionType === 'rejected') {
    // Rejected → Appeal (Stage 8)
    nextStageId = 8
    console.log('❌ Decision: Rejected → Routing to APPEAL (Stage 8)')
  }

  try {
    // Update case status and routing
    const response = await fetch(`/api/tax-cases/${caseId}/workflow/decision`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        stage_id: 7,
        decision_key: 'decision_type',
        decision_value: decisionType,
        next_stage_id: nextStageId,
        data: event.data
      })
    })

    if (response.ok) {
      console.log(`✅ Decision recorded, next stage: ${nextStageId}`)
      // Redirect back to case detail
      setTimeout(() => {
        router.push(`/tax-cases/${caseId}`)
      }, 2000)
    }
  } catch (error) {
    console.error('Failed to process decision:', error)
  }
}

const handleSaveDraft = (event) => {
  console.log('Objection Decision draft saved:', event)
}
</script>
