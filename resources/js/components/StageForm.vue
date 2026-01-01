<template>
  <div class="space-y-6 max-w-3xl">
    <!-- Preview Mode Banner -->
    <Alert
      type="warning"
      title="⚠️ Preview Mode"
      message="Backend integration pending. This form is for UI/UX review only. Submission will be enabled in Phase 3 (starting Jan 2, 2026)."
    />

    <!-- Header -->
    <div class="flex items-center space-x-4">
      <Button @click="$router.back()" variant="secondary">← Back</Button>
      <h1 class="text-3xl font-bold text-gray-900">{{ stageName }}</h1>
    </div>

    <!-- Alerts -->
    <Alert
      v-if="successMessage"
      type="success"
      title="Success"
      :message="successMessage"
    />
    
    <!-- Continue to Next Stage Button (shown after successful submission) -->
    <div v-if="submissionComplete && nextStageId" class="flex space-x-4 pt-4">
      <Button @click="continueToNextStage" variant="primary">
        Continue to Stage {{ nextStageId }} →
      </Button>
      <Button @click="viewCaseDetail" variant="secondary">
        View Case Detail
      </Button>
    </div>

    <Alert
      v-if="apiError"
      type="error"
      title="Error"
      :message="apiError"
    />

    <!-- Main Form -->
    <Card :title="`${stageName} - Information`" :subtitle="stageDescription">
      <form @submit.prevent="submitForm" class="space-y-4">
        
        <!-- Dynamic Fields -->
        <div v-for="field in fields" :key="field.id" class="space-y-2">
          <!-- Text Input -->
          <FormField
            v-if="field.type === 'text'"
            :label="field.label"
            type="text"
            v-model="formData[field.key]"
            :placeholder="field.placeholder"
            :required="field.required"
            :error="formErrors[field.key]"
            :disabled="field.readonly"
          />

          <!-- Number Input -->
          <FormField
            v-if="field.type === 'number'"
            :label="field.label"
            type="number"
            v-model="formData[field.key]"
            :placeholder="field.placeholder"
            :required="field.required"
            :error="formErrors[field.key]"
            :disabled="field.readonly"
          />

          <!-- Date Input -->
          <FormField
            v-if="field.type === 'date'"
            :label="field.label"
            type="date"
            v-model="formData[field.key]"
            :required="field.required"
            :error="formErrors[field.key]"
          />

          <!-- Month Input -->
          <FormField
            v-if="field.type === 'month'"
            :label="field.label"
            type="month"
            v-model="formData[field.key]"
            :required="field.required"
            :error="formErrors[field.key]"
          />

          <!-- Textarea -->
          <div v-if="field.type === 'textarea'">
            <label :for="field.key" class="block text-sm font-medium text-gray-700 mb-2">
              {{ field.label }}
              <span v-if="field.required" class="text-red-500">*</span>
            </label>
            <textarea
              :id="field.key"
              v-model="formData[field.key]"
              :placeholder="field.placeholder"
              rows="4"
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <p v-if="formErrors[field.key]" class="text-red-500 text-sm mt-1">
              {{ formErrors[field.key] }}
            </p>
          </div>

          <!-- Select Dropdown -->
          <div v-if="field.type === 'select'">
            <label :for="field.key" class="block text-sm font-medium text-gray-700 mb-2">
              {{ field.label }}
              <span v-if="field.required" class="text-red-500">*</span>
            </label>
            <select
              :id="field.key"
              v-model="formData[field.key]"
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">-- Select {{ field.label }} --</option>
              <option v-for="option in field.options" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
            <p v-if="formErrors[field.key]" class="text-red-500 text-sm mt-1">
              {{ formErrors[field.key] }}
            </p>
          </div>

          <!-- Radio Buttons -->
          <div v-if="field.type === 'radio'">
            <label class="block text-sm font-medium text-gray-700 mb-2">
              {{ field.label }}
              <span v-if="field.required" class="text-red-500">*</span>
            </label>
            <div class="space-y-2">
              <div v-for="option in field.options" :key="option.value" class="flex items-center">
                <input
                  :id="`${field.key}_${option.value}`"
                  :name="field.key"
                  type="radio"
                  :value="option.value"
                  v-model="formData[field.key]"
                  class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300"
                />
                <label :for="`${field.key}_${option.value}`" class="ml-2 text-sm text-gray-700">
                  {{ option.label }}
                </label>
              </div>
            </div>
            <p v-if="formErrors[field.key]" class="text-red-500 text-sm mt-1">
              {{ formErrors[field.key] }}
            </p>
          </div>

          <!-- Checkbox -->
          <div v-if="field.type === 'checkbox'">
            <div class="flex items-center">
              <input
                :id="field.key"
                type="checkbox"
                v-model="formData[field.key]"
                class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
              />
              <label :for="field.key" class="ml-2 text-sm text-gray-700">
                {{ field.label }}
              </label>
            </div>
          </div>
        </div>

        <!-- Document Upload Section -->
        <div class="border-t pt-4 mt-4">
          <h3 class="text-lg font-medium text-gray-900 mb-3">📎 Supporting Documents</h3>
          <div class="space-y-3">
            <input
              type="file"
              multiple
              @change="handleFileUpload"
              class="block w-full text-sm text-gray-500
                file:mr-4 file:py-2 file:px-4
                file:rounded-lg file:border-0
                file:text-sm file:font-medium
                file:bg-blue-50 file:text-blue-700
                hover:file:bg-blue-100"
            />
            <div v-if="uploadedFiles.length > 0" class="mt-4 space-y-2">
              <p class="text-sm text-gray-600">{{ uploadedFiles.length }} file(s) attached:</p>
              <div v-for="file in uploadedFiles" :key="file.id" class="flex items-center justify-between p-2 bg-gray-50 rounded">
                <div class="flex items-center space-x-2">
                  <span class="text-lg">📄</span>
                  <span class="text-sm text-gray-700">{{ file.name }}</span>
                </div>
                <Button @click="removeFile(file.id)" variant="danger">Remove</Button>
              </div>
            </div>
          </div>
        </div>

        <!-- Case Info Display -->
        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
          <h3 class="text-sm font-medium text-gray-700 mb-2">📋 Case Information</h3>
          <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
              <p class="text-gray-600">Case Number</p>
              <p class="font-bold">{{ caseNumber }}</p>
            </div>
            <div>
              <p class="text-gray-600">Stage</p>
              <p class="font-bold">{{ stageId }} / 12</p>
            </div>
          </div>
        </div>

        <!-- Submit Buttons -->
        <div class="flex space-x-4 pt-4 border-t">
          <Button type="submit" variant="primary" :disabled="submitting">
            {{ submitting ? 'Submitting...' : 'Submit & Continue' }}
          </Button>
          <Button @click="saveDraft" variant="secondary" :disabled="submitting">
            Save as Draft
          </Button>
          <Button @click="$router.back()" variant="secondary">
            Cancel
          </Button>
        </div>
      </form>
    </Card>

    <!-- Loading Spinner -->
    <LoadingSpinner v-if="submitting" message="Processing form..." />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import Card from './Card.vue'
import Button from './Button.vue'
import FormField from './FormField.vue'
import Alert from './Alert.vue'
import LoadingSpinner from './LoadingSpinner.vue'

const router = useRouter()
const route = useRoute()

// Props - passed from parent form component
const props = defineProps({
  stageName: {
    type: String,
    required: true
  },
  stageDescription: {
    type: String,
    required: true
  },
  stageId: {
    type: Number,
    required: true
  },
  caseId: {
    type: String,
    required: true
  },
  caseNumber: {
    type: String,
    required: true
  },
  fields: {
    type: Array,
    required: true
  },
  apiEndpoint: {
    type: String,
    required: true
  },
  isReviewMode: {
    type: Boolean,
    default: false
  },
  nextStageId: {
    type: Number,
    default: null
  }
})

const emit = defineEmits(['submit', 'saveDraft'])

// State
const submitting = ref(false)
const apiError = ref('')
const successMessage = ref('')
const submissionComplete = ref(false)
const formData = reactive({})
const formErrors = reactive({})
const uploadedFiles = ref([])

// Initialize form data from fields
onMounted(() => {
  props.fields.forEach(field => {
    formData[field.key] = field.value || ''
  })
})

const handleFileUpload = (event) => {
  const files = event.target.files
  for (let file of files) {
    uploadedFiles.value.push({
      id: Math.random(),
      name: file.name,
      file: file,
      size: (file.size / 1024 / 1024).toFixed(2) // MB
    })
  }
  // Reset input
  event.target.value = ''
}

const removeFile = (fileId) => {
  uploadedFiles.value = uploadedFiles.value.filter(f => f.id !== fileId)
}

const validateForm = () => {
  // Clear errors
  Object.keys(formErrors).forEach(key => {
    delete formErrors[key]
  })

  let isValid = true

  // Validate required fields (skip readonly/disabled fields)
  props.fields.forEach(field => {
    // Skip validation for readonly/disabled fields
    if (field.readonly) {
      return
    }

    if (field.required && (!formData[field.key] || formData[field.key] === '')) {
      formErrors[field.key] = `${field.label} is required`
      isValid = false
    }

    // Type-specific validation
    if (field.type === 'number' && formData[field.key]) {
      if (isNaN(formData[field.key]) || formData[field.key] < 0) {
        formErrors[field.key] = `${field.label} must be a valid number`
        isValid = false
      }
    }
  })

  return isValid
}

const submitForm = async () => {
  if (!validateForm()) {
    apiError.value = 'Please fix errors and try again'
    return
  }

  submitting.value = true
  apiError.value = ''
  successMessage.value = ''

  try {
    // PREVIEW MODE: Show message instead of actual submission
    successMessage.value = `
      ✅ Form validation passed!
      
      In Phase 3 (starting Jan 2, 2026), this form will be submitted to the backend.
      
      Your form data is ready to be saved. The database integration will be available soon.
      
      For now, this is preview mode for UI/UX testing only.
    `
    
    // Log form data to console for testing
    console.log('Form would be submitted with data:', {
      stageId: props.stageId,
      caseId: props.caseId,
      formData: formData,
      uploadedFiles: uploadedFiles.value.map(f => f.name),
      nextStageId: props.nextStageId
    })

    // Mark submission as complete and show continue button
    submitting.value = false
    submissionComplete.value = true
  } catch (error) {
    apiError.value = error.message || 'Failed to process form'
    submitting.value = false
  }
}

const continueToNextStage = () => {
  if (props.nextStageId) {
    router.push(`/tax-cases/${props.caseId}/workflow/${props.nextStageId}`)
  }
}

const viewCaseDetail = () => {
  router.push(`/tax-cases/${props.caseId}`)
}

const saveDraft = async () => {
  // Save without validation
  submitting.value = true
  apiError.value = ''
  successMessage.value = ''

  try {
    const response = await fetch(`${props.apiEndpoint}?draft=true`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        ...formData,
        stage_id: props.stageId,
        case_id: props.caseId,
        status: 'draft'
      })
    })

    if (!response.ok) throw new Error('Failed to save draft')
    
    successMessage.value = 'Draft saved successfully'
    emit('saveDraft', { stageId: props.stageId, data: formData })
  } catch (error) {
    apiError.value = error.message || 'Failed to save draft'
  } finally {
    submitting.value = false
  }
}
</script>
