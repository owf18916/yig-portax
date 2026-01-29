<template>
  <div v-if="isOpen" class="modal-overlay" @click.self="closeModal">
    <div class="modal-content max-w-2xl">
      <div class="modal-header">
        <h2>Ajukan KIAN</h2>
        <button type="button" class="close-btn" @click="closeModal">
          <span>&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <!-- Info Alert -->
        <div class="alert alert-info">
          <p class="mb-2">
            <strong>Upaya Hukum Keberatan Atas Hasil Penghitungan (KIAN)</strong>
          </p>
          <p class="text-sm">
            Anda dapat mengajukan KIAN karena masih ada kerugian sebesar 
            <strong>Rp {{ formatAmount(lossAmount) }}</strong> yang belum dikembalikan.
          </p>
        </div>

        <!-- Eligibility Reason -->
        <div class="eligibility-box">
          <h3 class="mb-2 font-semibold text-gray-700">Alasan Kelayakan</h3>
          <p class="text-sm text-gray-600">{{ reason }}</p>
        </div>

        <!-- Form -->
        <form @submit.prevent="submit">
          <div class="form-group">
            <label class="form-label">
              <span class="required">*</span> Nomor KIAN:
            </label>
            <input 
              v-model="form.kian_number"
              type="text"
              placeholder="Contoh: KIAN-001-2024"
              class="form-control"
              required
              @blur="validateKianNumber"
            >
            <p v-if="errors.kian_number" class="error-message">{{ errors.kian_number }}</p>
          </div>

          <div class="form-group">
            <label class="form-label">
              <span class="required">*</span> Tanggal Pengajuan:
            </label>
            <input 
              v-model="form.submission_date"
              type="date"
              class="form-control"
              :max="today"
              required
              @blur="validateSubmissionDate"
            >
            <p v-if="errors.submission_date" class="error-message">{{ errors.submission_date }}</p>
          </div>

          <div class="form-group">
            <label class="form-label">
              <span class="required">*</span> Jumlah Kerugian yang Diklaim (Rp):
            </label>
            <input 
              v-model="form.loss_amount"
              type="number"
              placeholder="0"
              class="form-control"
              step="1"
              min="1"
              :max="lossAmount"
              required
              @blur="validateLossAmount"
            >
            <p v-if="errors.loss_amount" class="error-message">{{ errors.loss_amount }}</p>
            <p class="text-xs text-gray-500 mt-1">
              Maksimal: Rp {{ formatAmount(lossAmount) }}
            </p>
          </div>

          <div class="form-group">
            <label class="form-label">Catatan Tambahan:</label>
            <textarea 
              v-model="form.notes"
              placeholder="Tambahkan catatan jika diperlukan"
              rows="4"
              maxlength="500"
              class="form-control textarea"
            ></textarea>
            <div class="character-count">
              {{ form.notes.length }}/500 characters
            </div>
          </div>

          <!-- Document Upload Section -->
          <div class="form-group">
            <label class="form-label">Dokumen Pendukung:</label>
            <div class="upload-area" @dragover.prevent="dragOver = true" @dragleave="dragOver = false" @drop.prevent="handleFileDrop">
              <input 
                type="file" 
                ref="fileInput"
                @change="handleFileSelect"
                multiple
                accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"
                class="hidden"
              >
              <div :class="['upload-content', { 'drag-over': dragOver }]">
                <p class="mb-2">
                  <svg class="w-8 h-8 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                  </svg>
                </p>
                <p class="text-sm font-medium text-gray-700">
                  Drag files here or 
                  <a href="#" @click.prevent="$refs.fileInput.click()" class="text-blue-600 hover:text-blue-700">
                    click to browse
                  </a>
                </p>
                <p class="text-xs text-gray-500 mt-1">
                  PDF, DOC, DOCX, XLS, XLSX, JPG, PNG (Max 10MB per file)
                </p>
              </div>
            </div>

            <!-- File List -->
            <div v-if="form.documents.length > 0" class="file-list mt-3">
              <div v-for="(doc, index) in form.documents" :key="index" class="file-item">
                <span class="file-name">{{ doc.name }}</span>
                <span class="file-size">({{ formatFileSize(doc.size) }})</span>
                <button 
                  type="button"
                  @click="removeFile(index)"
                  class="btn-remove"
                >
                  ✕
                </button>
              </div>
            </div>
            <p v-if="errors.documents" class="error-message">{{ errors.documents }}</p>
          </div>

          <!-- Error Alert -->
          <div v-if="error" class="alert alert-danger">
            <strong>Error:</strong> {{ error }}
          </div>

          <!-- Success Alert -->
          <div v-if="success" class="alert alert-success">
            <strong>Berhasil!</strong> KIAN telah berhasil diajukan.
          </div>

          <!-- Loading State -->
          <div v-if="loading" class="alert alert-info">
            <span class="inline-block animate-spin mr-2">⟳</span>
            Memproses pengajuan KIAN...
          </div>

          <!-- Modal Footer -->
          <div class="modal-footer">
            <button 
              type="button" 
              @click="closeModal" 
              class="btn btn-secondary"
              :disabled="loading"
            >
              Batal
            </button>
            <button 
              type="submit" 
              class="btn btn-primary"
              :disabled="!canSubmit || loading"
            >
              {{ loading ? 'Memproses...' : 'Ajukan KIAN' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useTaxCaseApi } from '../composables/useTaxCaseApi'

const props = defineProps({
  taxCase: {
    type: Object,
    required: true
  },
  lossAmount: {
    type: Number,
    required: true
  },
  reason: {
    type: String,
    required: true
  },
  isOpen: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['close', 'submit', 'success'])

const { createKianSubmission } = useTaxCaseApi()

const form = ref({
  kian_number: '',
  submission_date: new Date().toISOString().split('T')[0],
  loss_amount: '',
  notes: '',
  documents: []
})

const errors = ref({})
const error = ref('')
const success = ref(false)
const loading = ref(false)
const dragOver = ref(false)
const fileInput = ref(null)

const today = computed(() => new Date().toISOString().split('T')[0])

const canSubmit = computed(() => {
  return form.value.kian_number &&
    form.value.submission_date &&
    form.value.loss_amount &&
    !Object.values(errors.value).some(e => e) &&
    !loading.value
})

const formatAmount = (amount) => {
  return new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0
  }).format(amount)
}

const formatFileSize = (bytes) => {
  if (bytes === 0) return '0 Bytes'
  const k = 1024
  const sizes = ['Bytes', 'KB', 'MB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i]
}

const validateKianNumber = () => {
  errors.value.kian_number = ''
  if (!form.value.kian_number) {
    errors.value.kian_number = 'Nomor KIAN harus diisi'
  }
}

const validateSubmissionDate = () => {
  errors.value.submission_date = ''
  if (!form.value.submission_date) {
    errors.value.submission_date = 'Tanggal pengajuan harus diisi'
  } else if (form.value.submission_date > today.value) {
    errors.value.submission_date = 'Tanggal pengajuan tidak boleh melebihi hari ini'
  }
}

const validateLossAmount = () => {
  errors.value.loss_amount = ''
  const amount = parseFloat(form.value.loss_amount)
  
  if (!form.value.loss_amount) {
    errors.value.loss_amount = 'Jumlah kerugian harus diisi'
  } else if (isNaN(amount) || amount <= 0) {
    errors.value.loss_amount = 'Jumlah kerugian harus lebih besar dari 0'
  } else if (amount > props.lossAmount) {
    errors.value.loss_amount = `Jumlah kerugian tidak boleh melebihi Rp ${formatAmount(props.lossAmount)}`
  }
}

const handleFileSelect = (e) => {
  const files = Array.from(e.target.files)
  addFiles(files)
}

const handleFileDrop = (e) => {
  dragOver.value = false
  const files = Array.from(e.dataTransfer.files)
  addFiles(files)
}

const addFiles = (files) => {
  files.forEach(file => {
    // Validate file
    const maxSize = 10 * 1024 * 1024 // 10MB
    const allowedTypes = ['application/pdf', 'application/msword', 
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'application/vnd.ms-excel',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'image/jpeg', 'image/png']

    if (file.size > maxSize) {
      error.value = `File ${file.name} terlalu besar (max 10MB)`
      return
    }

    if (!allowedTypes.includes(file.type)) {
      error.value = `File ${file.name} tidak didukung`
      return
    }

    // Add to documents list
    if (!form.value.documents.find(d => d.name === file.name && d.size === file.size)) {
      form.value.documents.push(file)
    }
  })
}

const removeFile = (index) => {
  form.value.documents.splice(index, 1)
}

const submit = async () => {
  // Validate all fields
  validateKianNumber()
  validateSubmissionDate()
  validateLossAmount()

  if (!canSubmit.value) return

  loading.value = true
  error.value = ''
  success.value = false

  try {
    // Prepare FormData for file uploads
    const formData = new FormData()
    formData.append('kian_number', form.value.kian_number)
    formData.append('submission_date', form.value.submission_date)
    formData.append('loss_amount', parseFloat(form.value.loss_amount))
    formData.append('notes', form.value.notes || '')

    // Add documents
    form.value.documents.forEach((doc, index) => {
      formData.append(`documents[${index}]`, doc)
    })

    await createKianSubmission(props.taxCase.id, formData)
    
    success.value = true
    emit('success')
    
    setTimeout(() => {
      closeModal()
    }, 1500)
  } catch (err) {
    error.value = err.response?.data?.message || 'Gagal mengajukan KIAN'
    console.error('KIAN submission error:', err)
  } finally {
    loading.value = false
  }
}

const closeModal = () => {
  emit('close')
}
</script>

<style scoped>
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-content {
  background: white;
  border-radius: 0.5rem;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
  max-height: 90vh;
  overflow-y: auto;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1.5rem;
  border-bottom: 1px solid #e5e7eb;
}

.modal-header h2 {
  margin: 0;
  font-size: 1.5rem;
  font-weight: 600;
  color: #1f2937;
}

.close-btn {
  background: none;
  border: none;
  font-size: 1.5rem;
  color: #6b7280;
  cursor: pointer;
  padding: 0;
  width: 2rem;
  height: 2rem;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.375rem;
  transition: background-color 0.2s;
}

.close-btn:hover {
  background-color: #f3f4f6;
  color: #1f2937;
}

.modal-body {
  padding: 1.5rem;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 1rem;
  padding: 1.5rem;
  border-top: 1px solid #e5e7eb;
  background-color: #f9fafb;
}

.alert {
  padding: 1rem;
  border-radius: 0.5rem;
  margin-bottom: 1rem;
}

.alert-info {
  background-color: #dbeafe;
  border-left: 4px solid #3b82f6;
  color: #1e40af;
}

.alert-info p {
  margin: 0.5rem 0;
}

.alert-danger {
  background-color: #fee2e2;
  border-left: 4px solid #ef4444;
  color: #991b1b;
}

.alert-success {
  background-color: #dcfce7;
  border-left: 4px solid #22c55e;
  color: #166534;
}

.eligibility-box {
  background-color: #fef3c7;
  border-left: 4px solid #f59e0b;
  padding: 1rem;
  border-radius: 0.5rem;
  margin-bottom: 1.5rem;
}

.form-group {
  margin-bottom: 1.5rem;
}

.form-label {
  display: block;
  margin-bottom: 0.5rem;
  font-weight: 500;
  color: #374151;
  font-size: 0.875rem;
}

.required {
  color: #ef4444;
}

.form-control {
  width: 100%;
  padding: 0.5rem;
  border: 1px solid #d1d5db;
  border-radius: 0.375rem;
  font-size: 0.875rem;
  font-family: inherit;
}

.form-control:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-control.textarea {
  resize: vertical;
  font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
}

.character-count {
  font-size: 0.75rem;
  color: #6b7280;
  margin-top: 0.25rem;
}

.character-count .error {
  color: #ef4444;
}

.error-message {
  color: #ef4444;
  font-size: 0.75rem;
  margin-top: 0.25rem;
}

.upload-area {
  border: 2px dashed #d1d5db;
  border-radius: 0.5rem;
  padding: 2rem;
  text-align: center;
  cursor: pointer;
  transition: all 0.2s;
}

.upload-area:hover {
  border-color: #9ca3af;
  background-color: #f9fafb;
}

.upload-area.drag-over {
  border-color: #3b82f6;
  background-color: #eff6ff;
}

.upload-content {
  color: #6b7280;
}

.hidden {
  display: none;
}

.file-list {
  border: 1px solid #e5e7eb;
  border-radius: 0.375rem;
  overflow: hidden;
}

.file-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.75rem;
  border-bottom: 1px solid #e5e7eb;
}

.file-item:last-child {
  border-bottom: none;
}

.file-name {
  font-size: 0.875rem;
  font-weight: 500;
  color: #374151;
  flex: 1;
  word-break: break-word;
}

.file-size {
  font-size: 0.75rem;
  color: #9ca3af;
  margin: 0 0.5rem;
}

.btn-remove {
  background-color: #fee2e2;
  color: #991b1b;
  border: none;
  padding: 0.25rem 0.5rem;
  border-radius: 0.25rem;
  cursor: pointer;
  font-weight: bold;
  transition: background-color 0.2s;
}

.btn-remove:hover {
  background-color: #fecaca;
}

.btn {
  padding: 0.5rem 1rem;
  border-radius: 0.375rem;
  font-weight: 500;
  border: none;
  cursor: pointer;
  font-size: 0.875rem;
  transition: all 0.2s;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-primary {
  background-color: #3b82f6;
  color: white;
}

.btn-primary:hover:not(:disabled) {
  background-color: #2563eb;
}

.btn-secondary {
  background-color: #e5e7eb;
  color: #374151;
}

.btn-secondary:hover:not(:disabled) {
  background-color: #d1d5db;
}

.mb-2 {
  margin-bottom: 0.5rem;
}

.mt-1 {
  margin-top: 0.25rem;
}

.mt-3 {
  margin-top: 0.75rem;
}

.text-sm {
  font-size: 0.875rem;
}

.text-xs {
  font-size: 0.75rem;
}

.text-gray-600 {
  color: #4b5563;
}

.text-gray-700 {
  color: #374151;
}

.text-gray-500 {
  color: #6b7280;
}

.font-semibold {
  font-weight: 600;
}

.inline-block {
  display: inline-block;
}

.animate-spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}

.max-w-2xl {
  max-width: 42rem;
}

.mr-2 {
  margin-right: 0.5rem;
}

.mx-auto {
  margin-left: auto;
  margin-right: auto;
}
</style>
