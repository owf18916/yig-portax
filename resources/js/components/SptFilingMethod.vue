<template>
  <div v-if="position === 'LB' || modelValue != null && modelValue !== ''" class="space-y-2">
    <label class="block text-sm font-semibold">Jenis Pengajuan Lebih Bayar</label>
    <p v-if="legacy" class="text-sm text-amber-800">
      Nilai historis: {{ modelValue }}. Nilai ini dipertahankan; perubahan harus dipilih secara eksplisit.
    </p>
    <select :value="modelValue ?? ''" :disabled="disabled" :required="required && position === 'LB'"
      class="w-full px-4 py-2 border border-gray-300 rounded-lg disabled:bg-gray-100"
      @change="$emit('update:modelValue', $event.target.value || null)">
      <option value="">{{ position === 'LB' ? 'Pilih jenis pengajuan (kosong jika belum ditentukan)' : 'Hapus jenis pengajuan secara eksplisit' }}</option>
      <option v-if="legacy" :value="modelValue" disabled>{{ modelValue }} (historis)</option>
      <option v-for="method in methods" :key="method" :value="method">{{ method }}</option>
    </select>
    <p class="text-xs text-gray-600">Hanya untuk LB. Restitusi mengikuti pemeriksaan biasa; Kompensasi dibawa ke masa berikutnya tanpa Refund otomatis. Pengembalian Pendahuluan hanya untuk VAT; penyelesaian mengikuti bukti proses Refund.</p>
    <p v-if="position === 'LB' && !modelValue" class="text-xs text-amber-800">Jenis pengajuan belum ditentukan. Tidak ada metode yang dipilih otomatis.</p>
  </div>
</template>

<script setup>
import { computed } from 'vue'
const props = defineProps({
  modelValue: { type: String, default: null },
  position: { type: String, default: null },
  caseType: { type: String, default: null },
  disabled: Boolean,
  required: Boolean
})
defineEmits(['update:modelValue'])
const methods = computed(() => props.position !== 'LB' ? []
  : ['Restitusi', 'Kompensasi', ...(props.caseType === 'VAT' ? ['Pengembalian Pendahuluan'] : [])])
const legacy = computed(() => props.modelValue != null && props.modelValue !== '' && !methods.value.includes(props.modelValue))
</script>
