<template>
  <!-- ⭐ REDIRECT: If stageId is 1-16, render specific form, not generic WorkflowForm -->
  <div v-if="!specificFormComponent" class="space-y-6">
    <div class="flex items-center space-x-4">
      <Button @click="$router.back()" variant="secondary">← Back</Button>
      <h1 class="text-3xl font-bold text-gray-900">{{ currentStage.name }}</h1>
      <span v-if="caseType" :class="['ml-auto px-3 py-1 rounded-full text-sm font-medium', caseType === 'VAT' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800']">
        {{ caseType }}
      </span>
    </div>

    <Alert
      v-if="successMessage"
      type="success"
      title="Success"
      :message="successMessage"
    />

    <Alert
      v-if="apiError"
      type="error"
      title="Error"
      :message="apiError"
    />

    <Card :title="`${currentStage.name} Form`" :subtitle="currentStage.description">
      <form @submit.prevent="submitForm" class="space-y-4">
        <!-- Dynamic form fields based on workflow stage AND case type -->
        <div v-if="currentStage.fields" class="space-y-4">
          <template v-for="field in getFieldsForCase()" :key="field.name">
            <FormField
              :label="field.label"
              :type="field.type"
              :required="field.required"
              v-model="formData[field.name]"
              :error="formErrors[field.name]"
              :rows="field.type === 'textarea' ? 4 : undefined"
            />
          </template>
        </div>

        <div class="flex space-x-4">
          <Button type="submit" variant="primary" :disabled="submitting">
            {{ submitting ? 'Submitting...' : 'Submit & Save' }}
          </Button>
          <Button @click="saveDraft" variant="secondary" :disabled="submitting">
            Save as Draft
          </Button>
        </div>
      </form>
    </Card>

    <!-- Stage Documentation -->
    <Card title="Stage Documentation" subtitle="Reference information for this workflow stage">
      <div class="prose prose-sm max-w-none">
        <p v-html="currentStage.documentation"></p>
      </div>
    </Card>
  </div>
  
  <!-- ⭐ Render specific form component for stages 1-16 -->
  <component v-else :is="specificFormComponent" />
</template>

<script setup>
import { ref, reactive, onMounted, computed, defineAsyncComponent } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Card from '../components/Card.vue'
import Button from '../components/Button.vue'
import FormField from '../components/FormField.vue'
import Alert from '../components/Alert.vue'

const route = useRoute()
const router = useRouter()

// ⭐ Import all specific form components
const SptFilingForm = defineAsyncComponent(() => import('./SptFilingForm.vue'))
const Sp2FilingForm = defineAsyncComponent(() => import('./Sp2FilingForm.vue'))
const SphpFilingForm = defineAsyncComponent(() => import('./SphpFilingForm.vue'))
const SkpFilingForm = defineAsyncComponent(() => import('./SkpFilingForm.vue'))
const ObjectionSubmissionForm = defineAsyncComponent(() => import('./ObjectionSubmissionForm.vue'))
const SpuhRecordForm = defineAsyncComponent(() => import('./SpuhRecordForm.vue'))
const ObjectionDecisionForm = defineAsyncComponent(() => import('./ObjectionDecisionForm.vue'))
const AppealSubmissionForm = defineAsyncComponent(() => import('./AppealSubmissionForm.vue'))
const AppealExplanationRequestForm = defineAsyncComponent(() => import('./AppealExplanationRequestForm.vue'))
const AppealDecisionForm = defineAsyncComponent(() => import('./AppealDecisionForm.vue'))
const SupremeCourtSubmissionForm = defineAsyncComponent(() => import('./SupremeCourtSubmissionForm.vue'))
const SupremeCourtDecisionForm = defineAsyncComponent(() => import('./SupremeCourtDecisionForm.vue'))
const BankTransferRequestForm = defineAsyncComponent(() => import('./BankTransferRequestForm.vue'))
const SuratInstruksiTransferForm = defineAsyncComponent(() => import('./SuratInstruksiTransferForm.vue'))
const RefundReceivedForm = defineAsyncComponent(() => import('./RefundReceivedForm.vue'))
const KianSubmissionForm = defineAsyncComponent(() => import('./KianSubmissionForm.vue'))

// ⭐ Map stage IDs to their specific form components
const stageFormMap = {
  1: SptFilingForm,
  2: Sp2FilingForm,
  3: SphpFilingForm,
  4: SkpFilingForm,
  5: ObjectionSubmissionForm,
  6: SpuhRecordForm,
  7: ObjectionDecisionForm,
  8: AppealSubmissionForm,
  9: AppealExplanationRequestForm,
  10: AppealDecisionForm,
  11: SupremeCourtSubmissionForm,
  12: SupremeCourtDecisionForm,
  13: BankTransferRequestForm,
  14: SuratInstruksiTransferForm,
  15: RefundReceivedForm,
  16: KianSubmissionForm
}

// ⭐ Determine which component to render
const stageId = computed(() => parseInt(route.params.stageId, 10))
const specificFormComponent = computed(() => {
  const component = stageFormMap[stageId.value]
  console.log(`[WorkflowForm] Stage ${stageId.value}: ${component ? 'Using specific form' : 'Using generic form'}`)
  return component || null
})


// ⭐ Generic form variables (only used if no specific form is available)
const submitting = ref(false)
const apiError = ref('')
const successMessage = ref('')
const caseType = ref('CIT')
const formData = reactive({})
const formErrors = reactive({})

const workflowStages = {
  1: {
    id: 1,
    name: 'SPT Filing',
    description: 'Initial Surat Pemberitahuan (SPT) - Tax Return Submission',
    endpoint: '/api/workflows/spt/submit',
    documentation: 'Submit your annual tax return with supporting documentation.',
    fields: [
      { name: 'spt_number', label: 'SPT Number', type: 'text', required: true },
      { name: 'filing_year', label: 'Fiscal Year', type: 'text', required: true },
      { name: 'reported_income', label: 'Reported Income (Rp)', type: 'number', required: true },
      { name: 'notes', label: 'Additional Notes', type: 'textarea', required: false }
    ]
  },
  2: {
    id: 2,
    name: 'SP2 Record',
    description: 'Second Level Tax Record - Follow-up Notification',
    endpoint: '/api/workflows/sp2/submit',
    documentation: 'Submit SP2 record with amendments if needed.',
    fields: [
      { name: 'sp2_number', label: 'SP2 Number', type: 'text', required: true },
      { name: 'amended_amount', label: 'Amended Amount (Rp)', type: 'number', required: true },
      { name: 'reason', label: 'Reason for Amendment', type: 'textarea', required: true }
    ]
  },
  3: {
    id: 3,
    name: 'SPHP Record',
    description: 'Tax Correction Notification - Self-correction',
    endpoint: '/api/workflows/sphp/submit',
    documentation: 'Self-correct your tax report before audit.',
    fields: [
      { name: 'sphp_number', label: 'SPHP Number', type: 'text', required: true },
      { name: 'correction_amount', label: 'Correction Amount (Rp)', type: 'number', required: true },
      { name: 'explanation', label: 'Explanation', type: 'textarea', required: true }
    ]
  },
  4: {
    id: 4,
    name: 'SKP Record',
    description: 'Tax Audit Report - Official Assessment',
    endpoint: '/api/workflows/skp/submit',
    documentation: 'Audit report results and assessment details.',
    fields: [
      { name: 'skp_number', label: 'SKP Number', type: 'text', required: true },
      { name: 'audit_findings', label: 'Audit Findings', type: 'textarea', required: true },
      { name: 'assessed_amount', label: 'Assessed Additional Tax (Rp)', type: 'number', required: true }
    ]
  },
  5: {
    id: 5,
    name: 'Objection',
    description: 'Formal Objection - Administrative Remedy',
    endpoint: '/api/workflows/objection/submit',
    documentation: 'File objection against tax assessment.',
    fields: [
      { name: 'objection_number', label: 'Objection Number', type: 'text', required: true },
      { name: 'grounds', label: 'Grounds for Objection', type: 'textarea', required: true },
      { name: 'proposed_reduction', label: 'Proposed Amount Reduction (Rp)', type: 'number', required: true }
    ]
  },
  6: {
    id: 6,
    name: 'Appeal',
    description: 'Administrative Appeal - Higher Level Review',
    endpoint: '/api/workflows/appeal/submit',
    documentation: 'Appeal to higher tax authority if objection rejected.',
    fields: [
      { name: 'appeal_number', label: 'Appeal Number', type: 'text', required: true },
      { name: 'appeal_grounds', label: 'Appeal Grounds', type: 'textarea', required: true },
      { name: 'requested_amount', label: 'Requested Amount (Rp)', type: 'number', required: true }
    ]
  },
  7: {
    id: 7,
    name: 'Supreme Court',
    description: 'Cassation to Supreme Court',
    endpoint: '/api/workflows/supreme-court/submit',
    documentation: 'Final legal remedy through cassation.',
    fields: [
      { name: 'cassation_number', label: 'Cassation Number', type: 'text', required: true },
      { name: 'legal_basis', label: 'Legal Basis for Cassation', type: 'textarea', required: true },
      { name: 'case_summary', label: 'Case Summary', type: 'textarea', required: true }
    ]
  },
  8: {
    id: 8,
    name: 'Refund',
    description: 'Tax Refund Process - Final Settlement',
    endpoint: '/api/workflows/refund/submit',
    documentation: 'Process and settle tax refund.',
    fields: [
      { name: 'refund_amount', label: 'Refund Amount (Rp)', type: 'number', required: true },
      { name: 'bank_account', label: 'Bank Account Number', type: 'text', required: true },
      { name: 'bank_name', label: 'Bank Name', type: 'text', required: true }
    ]
  }
}

const currentStage = ref(workflowStages[route.params.stageId] || workflowStages[1])

onMounted(() => {
  // Initialize form data with empty values
  if (currentStage.value.fields) {
    currentStage.value.fields.forEach(field => {
      formData[field.name] = ''
    })
  }

  // Load case type dari API
  fetch(`/api/tax-cases/${route.params.id}`)
    .then(r => r.json())
    .then(data => {
      caseType.value = data.case_type || 'CIT'
    })
    .catch(err => console.error('Failed to load case:', err))
})

const submitForm = async () => {
  // Validate form
  if (!validateForm()) {
    apiError.value = 'Please fix errors and try again'
    return
  }

  submitting.value = true
  apiError.value = ''
  successMessage.value = ''

  try {
    const response = await fetch(currentStage.value.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        case_id: route.params.id,
        data: formData
      })
    })

    if (!response.ok) throw new Error('Form submission failed')
    successMessage.value = 'Form submitted successfully!'
    setTimeout(() => router.back(), 2000)
  } catch (error) {
    apiError.value = error.message
  } finally {
    submitting.value = false
  }
}

const validateForm = () => {
  formErrors.value = {}
  let isValid = true

  if (currentStage.value.fields) {
    currentStage.value.fields.forEach(field => {
      const value = formData[field.name]
      
      if (field.required && !value) {
        formErrors.value[field.name] = `${field.label} is required`
        isValid = false
      }
      
      if (field.type === 'number' && value && isNaN(value)) {
        formErrors.value[field.name] = `${field.label} must be a number`
        isValid = false
      }
      
      if (field.type === 'email' && value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
        formErrors.value[field.name] = `${field.label} must be a valid email`
        isValid = false
      }
    })
  }

  return isValid
}

const getFieldsForCase = () => {
  const baseFields = currentStage.value.fields || []
  
  // For stage 1 (SPT Filing), show different fields for CIT vs VAT
  if (currentStage.value.id === 1 && caseType.value === 'VAT') {
    // Replace 'reported_income' field with VAT-specific fields
    return baseFields.map(field => {
      if (field.name === 'reported_income') {
        return null // Remove this field for VAT
      }
      return field
    }).filter(Boolean).concat([
      { name: 'ppn_masukan', label: 'PPN Masukan (VAT In) (Rp)', type: 'number', required: true },
      { name: 'ppn_keluaran', label: 'PPN Keluaran (VAT Out) (Rp)', type: 'number', required: true }
    ])
  }
  
  return baseFields
}

const saveDraft = async () => {
  submitting.value = true
  apiError.value = ''

  try {
    await fetch('/api/tax-cases/save-draft', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        case_id: route.params.id,
        stage_id: route.params.stageId,
        data: formData
      })
    })
    successMessage.value = 'Draft saved successfully!'
  } catch (error) {
    apiError.value = 'Failed to save draft'
  } finally {
    submitting.value = false
  }
}
</script>
