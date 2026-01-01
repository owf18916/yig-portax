<template>
  <div>
    <Alert
      v-if="preFilledMessage"
      type="info"
      title="📋 Review Mode"
      :message="preFilledMessage"
    />
    <StageForm
      :stageName="`Stage 1: SPT Filing (Review & Submit)`"
      :stageDescription="`Review your initial tax return submission created during case creation`"
      :stageId="1"
      :nextStageId="4"
      :caseId="caseId"
      :caseNumber="caseNumber"
      :fields="fields"
      :apiEndpoint="`/api/tax-cases/${caseId}/workflow/1`"
      :isReviewMode="true"
      @submit="handleSubmit"
      @saveDraft="handleSaveDraft"
    />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import StageForm from '../components/StageForm.vue'
import Alert from '../components/Alert.vue'

const route = useRoute()
const caseId = route.params.id
const caseNumber = ref('TAX-2026-001')
const preFilledMessage = ref('This form is pre-filled with data from your case creation. Review and submit to confirm.')

const fields = ref([
  {
    id: 1,
    type: 'text',
    key: 'entity_name',
    label: 'Entity Name',
    placeholder: 'e.g., PT. Maju Jaya Abadi',
    required: true,
    value: '',
    readonly: true
  },
  {
    id: 2,
    type: 'text',
    key: 'period',
    label: 'Fiscal Period',
    required: true,
    value: '',
    readonly: true
  },
  {
    id: 3,
    type: 'text',
    key: 'currency',
    label: 'Currency',
    required: true,
    value: 'IDR',
    readonly: true
  },
  {
    id: 4,
    type: 'number',
    key: 'amount',
    label: 'Nilai Sengketa (Disputed Amount)',
    placeholder: 'e.g., 5000000000',
    required: true,
    value: '',
    readonly: true
  },
  {
    id: 5,
    type: 'radio',
    key: 'filing_decision',
    label: 'SPT Filing Status',
    options: [
      { value: 'filed', label: 'SPT has been filed' },
      { value: 'not_filed', label: 'SPT has NOT been filed' }
    ],
    required: true,
    value: 'filed'
  }
])

onMounted(async () => {
  try {
    // Fetch case data to pre-fill form
    const response = await fetch(`/api/tax-cases/${caseId}`)
    if (response.ok) {
      const caseData = await response.json()
      caseNumber.value = caseData.case_number
      
      // Pre-fill fields from case data
      const entityField = fields.value.find(f => f.key === 'entity_name')
      if (entityField) entityField.value = caseData.entity_name || ''
      
      const periodField = fields.value.find(f => f.key === 'period')
      if (periodField) {
        // Format period based on case type
        if (caseData.case_type === 'CIT') {
          periodField.value = `March ${caseData.fiscal_year}` // CIT is always March
        } else {
          periodField.value = caseData.period || ''
        }
      }
      
      const currencyField = fields.value.find(f => f.key === 'currency')
      if (currencyField) currencyField.value = caseData.currency || 'IDR'
      
      const amountField = fields.value.find(f => f.key === 'amount')
      if (amountField) amountField.value = caseData.amount || ''
      
      preFilledMessage.value = `✅ Pre-filled with data from ${caseData.case_type} case creation (${caseData.case_number}). Review and confirm to proceed.`
    }
  } catch (error) {
    console.error('Failed to load case data:', error)
    preFilledMessage.value = '⚠️ Could not pre-fill data, please enter details manually'
  }
})

const handleSubmit = (event) => {
  // event contains { stageId, nextStageId, caseId, data }
  console.log('SPT Filing submitted:', event)
  // Navigation handled by StageForm component
  // After submit, SPT stage is marked as completed
}

const handleSaveDraft = (event) => {
  console.log('SPT Filing draft saved:', event)
}
</script>
