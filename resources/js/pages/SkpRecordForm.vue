<template>
  <div>
    <StageForm
      :stageName="`Stage 4: SKP Record (Tax Assessment)`"
      :stageDescription="`Record the SKP (Surat Ketetapan Pajak) - Tax Assessment Letter`"
      :stageId="4"
      :nextStageId="7"
      :caseId="caseId"
      :caseNumber="caseNumber"
      :fields="fields"
      :apiEndpoint="`/api/tax-cases/${caseId}/workflow/4`"
      @submit="handleSubmit"
      @saveDraft="handleSaveDraft"
    />
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
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
    key: 'skp_number',
    label: 'SKP Number (Nomor SKP)',
    placeholder: 'e.g., SKP-2024-001234',
    required: true,
    value: ''
  },
  {
    id: 2,
    type: 'date',
    key: 'issue_date',
    label: 'Issue Date (Tanggal Diterbitkan)',
    required: true,
    value: ''
  },
  {
    id: 3,
    type: 'date',
    key: 'receipt_date',
    label: 'Receipt Date (Tanggal Diterima)',
    required: true,
    value: ''
  },
  {
    id: 4,
    type: 'radio',
    key: 'skp_type',
    label: '⭐ SKP Type (DECISION POINT)',
    options: [
      { value: 'LB', label: 'SKP LB (Lebih Bayar - Overpayment) ➜ Leads to REFUND' },
      { value: 'NIHIL', label: 'NIHIL (Zero) ➜ Leads to OBJECTION' },
      { value: 'KB', label: 'SKP KB (Kurang Bayar - Underpayment) ➜ Leads to OBJECTION' }
    ],
    required: true,
    value: ''
  },
  {
    id: 5,
    type: 'number',
    key: 'skp_amount',
    label: 'SKP Amount (Nilai SKP)',
    placeholder: 'e.g., 5000000000',
    required: true,
    value: ''
  },
  {
    id: 6,
    type: 'number',
    key: 'royalty_correction',
    label: 'Audit Correction - Royalty',
    placeholder: '0',
    required: false,
    value: ''
  },
  {
    id: 7,
    type: 'number',
    key: 'service_correction',
    label: 'Audit Correction - Service',
    placeholder: '0',
    required: false,
    value: ''
  },
  {
    id: 8,
    type: 'number',
    key: 'other_correction',
    label: 'Audit Correction - Other',
    placeholder: '0',
    required: false,
    value: ''
  },
  {
    id: 9,
    type: 'textarea',
    key: 'other_notes',
    label: 'Notes for Other Corrections',
    placeholder: 'If applicable, provide details about other corrections...',
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
  console.log('SKP Record submitted:', event)

  // Get SKP type to determine next stage
  const skpType = event.data.skp_type // Should be sent from StageForm

  // Decision routing logic
  let nextStageId = null
  if (skpType === 'LB') {
    // SKP LB (Lebih Bayar) → Refund Process (Stage 12)
    nextStageId = 12
    console.log('✅ SKP LB detected → Routing to REFUND PROCESS (Stage 12)')
  } else if (skpType === 'NIHIL' || skpType === 'KB') {
    // NIHIL or SKP KB → Objection (Stage 5)
    nextStageId = 5
    console.log('❌ SKP NIHIL/KB detected → Routing to OBJECTION (Stage 5)')
  }

  try {
    // Update case status and routing
    const response = await fetch(`/api/tax-cases/${caseId}/workflow/decision`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        stage_id: 4,
        decision_key: 'skp_type',
        decision_value: skpType,
        next_stage_id: nextStageId,
        data: event.data
      })
    })

    if (response.ok) {
      console.log(`✅ Decision recorded, next stage: ${nextStageId}`)
      // Redirect back to case detail - user will see updated progress
      setTimeout(() => {
        router.push(`/tax-cases/${caseId}`)
      }, 2000)
    }
  } catch (error) {
    console.error('Failed to process decision:', error)
  }
}

const handleSaveDraft = (event) => {
  console.log('SKP Record draft saved:', event)
}
</script>
