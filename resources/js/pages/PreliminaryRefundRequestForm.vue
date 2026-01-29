<template>
  <div class="space-y-6">
    <div class="flex items-center space-x-4">
      <Button @click="$router.back()" variant="secondary">← Back</Button>
      <h1 class="text-3xl font-bold text-gray-900">Preliminary Refund Request</h1>
    </div>

    <!-- Alerts -->
    <Alert v-if="error" type="error" title="Error" :message="error" />
    <Alert v-if="success" type="success" title="Success" :message="success" />

    <!-- Info Alert -->
    <Alert 
      type="info" 
      title="Pengembalian Pendahuluan"
      message="This is a preliminary refund request for partial SKP amount. Complete this form to request the remaining disputed amount not covered by the SKP decision."
    />

    <!-- Loading -->
    <LoadingSpinner v-if="loading" message="Loading form..." />

    <Card v-else title="Request Details" subtitle="Fill in the preliminary refund request details">
      <form @submit.prevent="handleSubmit" class="space-y-6">
        <!-- Case Summary -->
        <div class="grid grid-cols-2 gap-6 p-4 bg-gray-50 rounded-lg">
          <div>
            <p class="text-sm text-gray-600">Case Number</p>
            <p class="font-bold text-lg">{{ caseData.case_number }}</p>
          </div>
          <div>
            <p class="text-sm text-gray-600">Disputed Amount</p>
            <p class="font-bold text-lg">{{ formatCurrency(caseData.disputed_amount) }}</p>
          </div>
          <div>
            <p class="text-sm text-gray-600">SKP Amount</p>
            <p class="font-bold text-lg">{{ formatCurrency(skpAmount) }}</p>
          </div>
          <div>
            <p class="text-sm text-gray-600">Available for Preliminary Request</p>
            <p class="font-bold text-lg text-green-600">{{ formatCurrency(availableAmount) }}</p>
          </div>
        </div>

        <!-- Request Number -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Request Number (Auto-generated)</label>
          <input 
            v-model="formData.request_number" 
            type="text" 
            disabled
            class="w-full px-4 py-2 bg-gray-100 border border-gray-300 rounded-lg text-gray-600"
          />
        </div>

        <!-- Submission Date -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">
            Submission Date <span class="text-red-500">*</span>
          </label>
          <input 
            v-model="formData.submission_date" 
            type="date" 
            required
            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
          />
        </div>

        <!-- Requested Amount -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">
            Requested Amount <span class="text-red-500">*</span>
          </label>
          <div class="relative">
            <span class="absolute left-4 top-2 text-gray-500">Rp</span>
            <input 
              v-model.number="formData.requested_amount" 
              type="number" 
              step="1"
              required
              :max="availableAmount"
              min="0"
              class="w-full px-4 py-2 pl-8 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              :class="{ 'border-red-500': formData.requested_amount > availableAmount }"
            />
          </div>
          <p v-if="formData.requested_amount > availableAmount" class="mt-1 text-sm text-red-600">
            Amount exceeds available refund of {{ formatCurrency(availableAmount) }}
          </p>
          <p class="mt-1 text-sm text-gray-500">Maximum available: {{ formatCurrency(availableAmount) }}</p>
        </div>

        <!-- Notes -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Notes (Optional)</label>
          <textarea 
            v-model="formData.notes" 
            rows="4"
            placeholder="Enter any additional notes or explanation..."
            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
          ></textarea>
        </div>

        <!-- Document Upload -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Supporting Documents (Optional)</label>
          <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center">
            <input 
              type="file" 
              multiple
              @change="handleFileUpload"
              class="hidden"
              ref="fileInput"
            />
            <Button 
              type="button"
              @click="$refs.fileInput.click()"
              variant="secondary"
              class="inline-block"
            >
              Choose Files
            </Button>
            <p v-if="uploadedFiles.length > 0" class="mt-4 text-sm text-gray-600">
              {{ uploadedFiles.length }} file(s) selected
            </p>
          </div>
        </div>

        <!-- Actions -->
        <div class="flex space-x-4">
          <Button 
            type="submit"
            :disabled="loading || formData.requested_amount > availableAmount"
            class="flex-1"
          >
            {{ loading ? 'Submitting...' : 'Submit Request' }}
          </Button>
          <Button 
            type="button"
            variant="secondary"
            @click="$router.back()"
            class="flex-1"
          >
            Cancel
          </Button>
        </div>
      </form>
    </Card>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTaxCaseApi } from '../composables/useTaxCaseApi'
import { useToast } from '../composables/useToast'
import Card from '../components/Card.vue'
import Button from '../components/Button.vue'
import Alert from '../components/Alert.vue'
import LoadingSpinner from '../components/LoadingSpinner.vue'

const route = useRoute()
const router = useRouter()
const caseId = parseInt(route.params.id, 10)
const api = useTaxCaseApi()
const { showSuccess, showError } = useToast()

const loading = ref(true)
const error = ref('')
const success = ref('')
const caseData = ref({})
const skpAmount = ref(0)
const uploadedFiles = ref([])

const formData = ref({
  request_number: '',
  submission_date: new Date().toISOString().split('T')[0],
  requested_amount: 0,
  notes: ''
})

const availableAmount = computed(() => {
  return Math.max(0, (caseData.value.disputed_amount || 0) - (skpAmount.value || 0))
})

const formatCurrency = (amount) => {
  const num = parseFloat(amount) || 0
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2
  }).format(num)
}

const handleFileUpload = (event) => {
  uploadedFiles.value = Array.from(event.target.files || [])
}

const loadCaseData = async () => {
  try {
    loading.value = true
    const response = await api.getTaxCase(caseId)
    caseData.value = response.data || response
    
    // Get SKP amount from skp record
    if (caseData.value.skpRecord) {
      skpAmount.value = caseData.value.skpRecord.skp_amount || 0
    }
    
    // Auto-generate request number
    formData.value.request_number = `PRR-${Date.now()}-${caseId}`
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to load case data'
    showError(error.value)
  } finally {
    loading.value = false
  }
}

const handleSubmit = async () => {
  if (formData.value.requested_amount > availableAmount.value) {
    error.value = 'Requested amount cannot exceed available amount'
    return
  }

  try {
    loading.value = true
    error.value = ''

    const payload = {
      submission_date: formData.value.submission_date,
      requested_amount: formData.value.requested_amount,
      notes: formData.value.notes || null
    }

    await api.createPreliminaryRefundRequest(caseId, payload)
    
    success.value = 'Preliminary refund request submitted successfully'
    showSuccess(success.value)
    
    // Redirect back to tax case detail
    setTimeout(() => {
      router.push(`/tax-cases/${caseId}`)
    }, 1500)
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to submit request'
    showError(error.value)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadCaseData()
})
</script>
