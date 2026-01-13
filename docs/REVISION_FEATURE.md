# FITUR REVISION - DOKUMENTASI LENGKAP

**Project:** PorTax - Tax Case Management System  
**Fitur:** Revision Management untuk SPT Filling (Stage 1)  
**Tanggal:** January 13, 2026  
**Status:** ✅ IMPLEMENTED

---

## 📋 DAFTAR ISI

1. [Pengenalan](#pengenalan)
2. [Database Design](#database-design)
3. [API Endpoints](#api-endpoints)
4. [Authorization & Policies](#authorization--policies)
5. [Events](#events)
6. [Contoh Penggunaan](#contoh-penggunaan)
7. [Vue Components](#vue-components)
8. [Frontend Integration](#frontend-integration)
9. [Error Handling](#error-handling)
10. [Migration & Setup](#migration--setup)

---

## 📖 PENGENALAN

### Apa itu Fitur Revision?

Fitur Revision memungkinkan user/PIC **mengedit data yang sudah di-submit** pada tahap SPT Filling (Stage 1) melalui workflow formal dengan approval dari Holding.

### Workflow (4 Tahap)

```
1. REQUEST REVISION (User/PIC)
   ├─ Pilih fields yang ingin direvisi
   ├─ Berikan alasan (min 10 karakter)
   └─ Status: PENDING_APPROVAL

2. APPROVAL (Holding)
   ├─ Jika APPROVE → form bisa diedit
   └─ Jika REJECT → bisa request ulang

3. SUBMIT REVISED DATA (User/PIC)
   ├─ Edit fields yang di-approve saja
   └─ Submit data baru

4. REVISION DECISION (Holding)
   ├─ Review before-after comparison
   ├─ Jika GRANT → data diupdate
   └─ Jika NOT_GRANT → bisa request ulang
```

### Status Revisi

```
PENDING_APPROVAL → APPROVED → SUBMITTED → GRANTED
                ↘ REJECTED    ↘ NOT_GRANTED
```

---

## 🗄️ DATABASE DESIGN

### Migration File
```php
// 2026_01_13_000001_add_revision_fields_to_tax_cases.php

ALTER TABLE tax_cases ADD:
  - revision_status ENUM('CURRENT', 'IN_REVISION', 'REVISED') DEFAULT 'CURRENT'
  - last_revision_id BIGINT UNSIGNED NULLABLE
```

**Status:**  ✅ MIGRATED

### Tabel Revisions (Enhanced)

```sql
CREATE TABLE revisions (
    id                   BIGINT UNSIGNED PRIMARY KEY,
    revisable_type       VARCHAR(255),           -- 'TaxCase', 'Sp2Record', dll
    revisable_id         BIGINT UNSIGNED,        -- ID dari record yang direvisi
    revision_status      ENUM (
        'PENDING_APPROVAL',  'APPROVED',  'REJECTED',
        'SUBMITTED',         'GRANTED',   'NOT_GRANTED'
    ),
    original_data        JSON,                   -- Data sebelum revisi
    revised_data         JSON NULLABLE,          -- Data setelah direvisi
    
    -- Timeline & Users
    requested_by         BIGINT UNSIGNED,
    requested_at         TIMESTAMP,
    approved_by          BIGINT UNSIGNED NULLABLE,
    approved_at          TIMESTAMP NULLABLE,
    submitted_by         BIGINT UNSIGNED NULLABLE,
    submitted_at         TIMESTAMP NULLABLE,
    decided_by           BIGINT UNSIGNED NULLABLE,
    decided_at           TIMESTAMP NULLABLE,
    
    -- Alasan
    approval_reason      TEXT NULLABLE,
    rejection_reason     TEXT NULLABLE,
    decision_reason      TEXT NULLABLE,
    
    created_at           TIMESTAMP,
    updated_at           TIMESTAMP
);
```

---

## 🔌 API ENDPOINTS

### 1. Request Revision

**Endpoint:** `POST /api/tax-cases/{caseId}/revisions/request`

**Auth:** User/PIC only

**Request:**
```json
{
    "fields": ["disputed_amount", "filing_date"],
    "reason": "Perlu update berdasarkan temuan audit"
}
```

**Validation:**
- `fields`: required, array, min 1
- `fields.*`: in: spt_number, filing_date, received_date, disputed_amount, vat_in_amount, vat_out_amount, description
- `reason`: required, min 10 chars, max 1000 chars

**Success Response (201):**
```json
{
    "message": "Revision request submitted successfully",
    "revision": {
        "id": 1,
        "revisable_type": "TaxCase",
        "revisable_id": 42,
        "revision_status": "PENDING_APPROVAL",
        "original_data": {
            "disputed_amount": "500000000",
            "filing_date": "2026-01-08"
        },
        "revised_data": null,
        "requested_by": 5,
        "requested_at": "2026-01-13T10:30:00Z",
        "requested_by_details": { "id": 5, "name": "Budi Santoso", "email": "budi@company.com" }
    }
}
```

**Error:**
```json
{
    "error": "Cannot request revision for unsubmitted data"  // atau
    "error": "There is already a revision in progress",
    "pending_revision_id": 1
}
```

---

### 2. Approve/Reject Request

**Endpoint:** `PATCH /api/revisions/{id}/approve`

**Auth:** Holding only

**Request:**
```json
{
    "action": "approve",  // atau "reject"
    "reason": "Request valid, fields bisa diedit"
}
```

**Validation:**
- `action`: required, in: approve,reject
- `reason`: required if action=reject

**Response (200):**
```json
{
    "message": "Revision approved successfully",
    "revision": {
        "id": 1,
        "revision_status": "APPROVED",
        "approved_by": 3,
        "approved_at": "2026-01-13T14:00:00Z"
    }
}
```

---

### 3. Submit Revised Data

**Endpoint:** `PATCH /api/revisions/{id}/submit`

**Auth:** User/PIC only (harus yang request, dan status APPROVED)

**Request:**
```json
{
    "revised_data": {
        "disputed_amount": "550000000",
        "filing_date": "2026-01-10"
    }
}
```

**Validation:**
- `revised_data`: required, array
- Keys harus match dengan original_data keys

**Response (200):**
```json
{
    "message": "Revision submitted successfully",
    "revision": {
        "id": 1,
        "revision_status": "SUBMITTED",
        "original_data": { "disputed_amount": "500000000", "filing_date": "2026-01-08" },
        "revised_data": { "disputed_amount": "550000000", "filing_date": "2026-01-10" },
        "submitted_by": 5,
        "submitted_at": "2026-01-13T15:30:00Z"
    }
}
```

---

### 4. Decide on Revision

**Endpoint:** `PATCH /api/revisions/{id}/decide`

**Auth:** Holding only (status harus SUBMITTED)

**Request:**
```json
{
    "decision": "grant",  // atau "not_grant"
    "reason": "Data sudah benar dan sesuai dokumentasi"
}
```

**Validation:**
- `decision`: required, in: grant,not_grant
- `reason`: required, min 10 chars, max 1000 chars

**Response (200) - Jika GRANT:**
```json
{
    "message": "Revision decision: grant",
    "revision": {
        "id": 1,
        "revision_status": "GRANTED",
        "decided_by": 3,
        "decided_at": "2026-01-13T16:45:00Z",
        "decision_reason": "Data sudah benar..."
    }
}
```

**Hasil jika GRANT:**
- Data tax_case di-update dengan revised_data
- revision_status tax_case → REVISED
- last_revision_id tax_case → revision ID ini

**Hasil jika NOT_GRANT:**
- revision_status tax_case → CURRENT (bisa request ulang)

---

### 5. List Revisions

**Endpoint:** `GET /api/tax-cases/{caseId}/revisions`

**Auth:** Case owner, same entity, Holding, Admin

**Response (200):**
```json
{
    "data": [
        { "id": 3, "revision_status": "PENDING_APPROVAL", ... },
        { "id": 2, "revision_status": "GRANTED", ... },
        { "id": 1, "revision_status": "GRANTED", ... }
    ],
    "meta": { "current_page": 1, "per_page": 15, "total": 3 }
}
```

---

### 6. Get Revision Detail

**Endpoint:** `GET /api/revisions/{id}`

**Auth:** Requester, Holding, Admin, same entity

**Response (200):**
```json
{
    "revision": { ... },
    "comparison": {
        "original": { "disputed_amount": "500000000", "filing_date": "2026-01-08" },
        "revised": { "disputed_amount": "550000000", "filing_date": "2026-01-10" },
        "changes": {
            "disputed_amount": {
                "original": "500000000",
                "revised": "550000000"
            },
            "filing_date": {
                "original": "2026-01-08",
                "revised": "2026-01-10"
            }
        }
    }
}
```

---

## 🔐 AUTHORIZATION & POLICIES

### RevisionPolicy Rules

| Action | User/PIC | Holding | Admin | Kondisi |
|--------|----------|---------|-------|---------|
| request | ✅ | ❌ | ✅ | Hanya untuk entity sendiri |
| approve | ❌ | ✅ | ❌ | Status PENDING_APPROVAL |
| submit | ✅ | ❌ | ❌ | Hanya requester, status APPROVED |
| decide | ❌ | ✅ | ❌ | Status SUBMITTED |
| view | ✅* | ✅ | ✅ | *Own revisions atau same entity |
| viewAny | ✅* | ✅ | ✅ | *Case owner atau same entity |

**File:** `app/Policies/RevisionPolicy.php`

---

## 🔔 EVENTS

6 events ready untuk listeners/notifications:

```php
// Fired saat user request revisi
event(new RevisionRequested($revision, $taxCase));

// Fired saat holding approve/reject
event(new RevisionApproved($revision));    // Notify user bisa edit
event(new RevisionRejected($revision));    // Notify user dengan alasan

// Fired saat user submit data
event(new RevisionSubmitted($revision));   // Notify holding untuk review

// Fired saat holding decide
event(new RevisionGranted($revision));     // Update data, notify user
event(new RevisionNotGranted($revision));  // Notify user dengan alasan
```

**File:** `app/Events/Revision*.php` (6 files)

---

## 💡 CONTOH PENGGUNAAN

### Complete Workflow

**Step 1: Request**
```bash
POST /api/tax-cases/42/revisions/request
{
  "fields": ["disputed_amount"],
  "reason": "Amount perlu diupdate berdasarkan audit findings"
}
```

**Step 2: Approve**
```bash
PATCH /api/revisions/1/approve
{
  "action": "approve",
  "reason": "Request valid"
}
```

**Step 3: Submit**
```bash
PATCH /api/revisions/1/submit
{
  "revised_data": {
    "disputed_amount": "550000000"
  }
}
```

**Step 4: Decide**
```bash
PATCH /api/revisions/1/decide
{
  "decision": "grant",
  "reason": "Amount sudah benar"
}
```

---

## 🎨 VUE COMPONENTS

### 1. RevisionHistoryPanel.vue

```vue
<template>
  <div class="revision-history-panel">
    <div class="panel-header">
      <h3>📋 Revision History</h3>
      <button 
        v-if="canRequestRevision" 
        @click="showRequestModal = true"
        class="btn btn-primary"
      >
        [+ Request New Revision]
      </button>
    </div>

    <!-- Revisions List -->
    <div class="revisions-list">
      <div 
        v-for="revision in revisions" 
        :key="revision.id"
        class="revision-item"
        :class="`status-${revision.revision_status.toLowerCase()}`"
      >
        <div class="revision-header">
          <span class="revision-id">#{{ revision.id }}</span>
          <span class="status-badge" :class="statusClass(revision.revision_status)">
            {{ statusLabel(revision.revision_status) }}
          </span>
          <span class="revision-date">{{ formatDate(revision.created_at) }}</span>
        </div>

        <div class="revision-details">
          <p><strong>Requested by:</strong> {{ revision.requested_by_details?.name }}</p>
          <p><strong>Reason:</strong> {{ revision.reason }}</p>

          <div v-if="revision.revision_status === 'PENDING_APPROVAL'" class="pending-section">
            <span class="status-icon">⏳ Waiting for Holding approval</span>
          </div>

          <div v-if="revision.revision_status === 'APPROVED'" class="approved-section">
            <span class="status-icon">✓ Approved at {{ formatDate(revision.approved_at) }}</span>
            <p v-if="revision.approval_reason"><strong>Approval Reason:</strong> {{ revision.approval_reason }}</p>
          </div>

          <div v-if="revision.revision_status === 'REJECTED'" class="rejected-section">
            <span class="status-icon">✗ Rejected</span>
            <p><strong>Reason:</strong> {{ revision.rejection_reason }}</p>
          </div>

          <div v-if="revision.revision_status === 'SUBMITTED'" class="submitted-section">
            <span class="status-icon">⏳ Submitted at {{ formatDate(revision.submitted_at) }}</span>
            <span class="waiting-text">Waiting for Holding decision</span>
          </div>

          <div v-if="revision.revision_status === 'GRANTED'" class="granted-section">
            <span class="status-icon">✅ GRANTED</span>
            <p><strong>Decided at:</strong> {{ formatDate(revision.decided_at) }}</p>
            <p><strong>Decision:</strong> {{ revision.decision_reason }}</p>
            <button @click="showComparison(revision)" class="btn btn-sm btn-info">
              [View Changes]
            </button>
          </div>

          <div v-if="revision.revision_status === 'NOT_GRANTED'" class="not-granted-section">
            <span class="status-icon">✗ NOT GRANTED</span>
            <p><strong>Reason:</strong> {{ revision.decision_reason }}</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Request Revision Modal -->
    <RequestRevisionModal 
      v-if="showRequestModal"
      :case-id="caseId"
      @submit="onRevisionRequested"
      @close="showRequestModal = false"
    />

    <!-- Before-After Comparison Modal -->
    <BeforeAfterModal 
      v-if="selectedRevision"
      :revision="selectedRevision"
      @close="selectedRevision = null"
    />
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import RequestRevisionModal from './RequestRevisionModal.vue'
import BeforeAfterModal from './BeforeAfterModal.vue'

const props = defineProps({
  caseId: { type: Number, required: true },
  taxCase: { type: Object, required: true },
  revisions: { type: Array, default: () => [] },
  currentUser: { type: Object, required: true }
})

const emit = defineEmits(['revision-requested', 'refresh'])

const showRequestModal = ref(false)
const selectedRevision = ref(null)

const canRequestRevision = computed(() => {
  // Data harus sudah submitted
  if (!props.taxCase.submitted_at) return false
  
  // Tidak boleh ada revision in progress
  const inProgress = props.revisions.find(r => 
    ['PENDING_APPROVAL', 'APPROVED', 'SUBMITTED'].includes(r.revision_status)
  )
  return !inProgress
})

const statusClass = (status) => ({
  'PENDING_APPROVAL': 'warning',
  'APPROVED': 'info',
  'REJECTED': 'danger',
  'SUBMITTED': 'warning',
  'GRANTED': 'success',
  'NOT_GRANTED': 'danger'
}[status])

const statusLabel = (status) => ({
  'PENDING_APPROVAL': '⏳ Awaiting Approval',
  'APPROVED': '✓ Approved',
  'REJECTED': '✗ Rejected',
  'SUBMITTED': '⏳ Awaiting Decision',
  'GRANTED': '✅ GRANTED',
  'NOT_GRANTED': '✗ NOT GRANTED'
}[status])

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('id-ID', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

const showComparison = (revision) => {
  selectedRevision.value = revision
}

const onRevisionRequested = (revision) => {
  showRequestModal.value = false
  emit('revision-requested', revision)
  emit('refresh')
}
</script>

<style scoped>
.revision-history-panel {
  border: 1px solid #ddd;
  border-radius: 4px;
  padding: 15px;
  margin-top: 20px;
  background: #f9f9f9;
}

.panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 15px;
  padding-bottom: 10px;
  border-bottom: 2px solid #eee;
}

.panel-header h3 {
  margin: 0;
  font-size: 16px;
  font-weight: bold;
}

.revisions-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.revision-item {
  border: 1px solid #e0e0e0;
  border-radius: 4px;
  padding: 12px;
  background: white;
}

.revision-item.status-pending_approval {
  border-left: 4px solid #ffc107;
}

.revision-item.status-granted {
  border-left: 4px solid #28a745;
}

.revision-item.status-rejected,
.revision-item.status-not_granted {
  border-left: 4px solid #dc3545;
}

.revision-header {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-bottom: 8px;
}

.revision-id {
  font-weight: bold;
  color: #666;
}

.status-badge {
  padding: 2px 6px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: bold;
}

.status-badge.warning {
  background: #fff3cd;
  color: #856404;
}

.status-badge.danger {
  background: #f8d7da;
  color: #721c24;
}

.status-badge.success {
  background: #d4edda;
  color: #155724;
}

.status-badge.info {
  background: #d1ecf1;
  color: #0c5460;
}

.revision-date {
  margin-left: auto;
  font-size: 12px;
  color: #999;
}

.revision-details p {
  margin: 5px 0;
  font-size: 13px;
}

.status-icon {
  display: inline-block;
  margin: 5px 0;
  font-weight: bold;
}

.btn {
  padding: 6px 12px;
  border: none;
  border-radius: 3px;
  cursor: pointer;
  font-size: 13px;
}

.btn-primary {
  background: #007bff;
  color: white;
}

.btn-info {
  background: #17a2b8;
  color: white;
  margin-top: 5px;
}

.btn-sm {
  padding: 4px 8px;
  font-size: 12px;
}
</style>
```

### 2. RequestRevisionModal.vue

```vue
<template>
  <div class="modal-overlay" @click.self="close">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Request Revision</h2>
        <button class="close-btn" @click="close">✕</button>
      </div>

      <div class="modal-body">
        <form @submit.prevent="submit">
          <div class="form-group">
            <label>Select fields to revise:</label>
            <div class="fields-checklist">
              <label v-for="field in availableFields" :key="field" class="field-checkbox">
                <input 
                  type="checkbox" 
                  :value="field"
                  v-model="selectedFields"
                >
                <span>{{ fieldLabel(field) }}</span>
              </label>
            </div>
          </div>

          <div class="form-group">
            <label>Explanation / Reason:</label>
            <textarea 
              v-model="reason"
              placeholder="Please explain why revision is needed (min 10 characters)"
              rows="5"
              maxlength="1000"
              class="form-control"
              required
            ></textarea>
            <small>{{ reason.length }}/1000 characters</small>
          </div>

          <div v-if="error" class="alert alert-danger">
            {{ error }}
          </div>

          <div class="modal-footer">
            <button type="button" @click="close" class="btn btn-secondary">Cancel</button>
            <button 
              type="submit" 
              class="btn btn-primary"
              :disabled="!canSubmit"
            >
              Submit Request
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  caseId: { type: Number, required: true }
})

const emit = defineEmits(['submit', 'close'])

const selectedFields = ref([])
const reason = ref('')
const error = ref('')
const loading = ref(false)

const availableFields = [
  'spt_number',
  'filing_date',
  'received_date',
  'disputed_amount',
  'vat_in_amount',
  'vat_out_amount',
  'description'
]

const canSubmit = computed(() => {
  return selectedFields.value.length > 0 && reason.value.length >= 10
})

const fieldLabel = (field) => ({
  'spt_number': 'SPT Number',
  'filing_date': 'Filing Date',
  'received_date': 'Received Date',
  'reported_amount': 'Reported Amount',
  'disputed_amount': 'Disputed Amount',
  'vat_in_amount': 'VAT In Amount',
  'vat_out_amount': 'VAT Out Amount',
  'description': 'Description'
}[field])

const submit = async () => {
  loading.value = true
  error.value = ''

  try {
    const response = await fetch(
      `/api/tax-cases/${props.caseId}/revisions/request`,
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${localStorage.getItem('auth_token')}`
        },
        body: JSON.stringify({
          fields: selectedFields.value,
          reason: reason.value
        })
      }
    )

    if (!response.ok) {
      const data = await response.json()
      throw new Error(data.error || 'Failed to submit request')
    }

    const data = await response.json()
    emit('submit', data.revision)
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}

const close = () => emit('close')
</script>

<style scoped>
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-content {
  background: white;
  border-radius: 8px;
  width: 90%;
  max-width: 600px;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px;
  border-bottom: 1px solid #eee;
}

.modal-header h2 {
  margin: 0;
  font-size: 18px;
}

.close-btn {
  background: none;
  border: none;
  font-size: 24px;
  cursor: pointer;
  color: #999;
}

.modal-body {
  padding: 20px;
}

.form-group {
  margin-bottom: 15px;
}

.form-group label {
  display: block;
  margin-bottom: 8px;
  font-weight: bold;
  font-size: 14px;
}

.fields-checklist {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-bottom: 10px;
}

.field-checkbox {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
}

.field-checkbox input {
  cursor: pointer;
}

.form-control {
  width: 100%;
  padding: 10px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-family: inherit;
}

.form-control:focus {
  outline: none;
  border-color: #007bff;
  box-shadow: 0 0 5px rgba(0, 123, 255, 0.3);
}

.form-group small {
  display: block;
  color: #999;
  font-size: 12px;
  margin-top: 5px;
}

.alert {
  padding: 10px;
  border-radius: 4px;
  margin-bottom: 15px;
  font-size: 14px;
}

.alert-danger {
  background: #f8d7da;
  color: #721c24;
  border: 1px solid #f5c6cb;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding: 20px;
  border-top: 1px solid #eee;
}

.btn {
  padding: 10px 20px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 500;
}

.btn-primary {
  background: #007bff;
  color: white;
}

.btn-primary:disabled {
  background: #ccc;
  cursor: not-allowed;
}

.btn-secondary {
  background: #6c757d;
  color: white;
}
</style>
```

### 3. BeforeAfterComparison.vue

```vue
<template>
  <div class="modal-overlay" @click.self="close">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Before-After Comparison</h2>
        <button class="close-btn" @click="close">✕</button>
      </div>

      <div class="modal-body">
        <div class="comparison-table">
          <table>
            <thead>
              <tr>
                <th>Field</th>
                <th class="col-original">Original Value</th>
                <th class="col-revised">Revised Value</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(change, field) in changes" :key="field" class="changed-row">
                <td class="field-name">{{ fieldLabel(field) }}</td>
                <td class="col-original">
                  <span class="old-value">{{ change.original }}</span>
                </td>
                <td class="col-revised">
                  <span class="new-value">{{ change.revised }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="Object.keys(changes).length === 0" class="no-changes">
          No changes recorded
        </div>
      </div>

      <div class="modal-footer">
        <button @click="close" class="btn btn-secondary">Close</button>
      </div>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  revision: { type: Object, required: true }
})

const emit = defineEmits(['close'])

const changes = props.revision.revised_data ? 
  Object.entries(props.revision.original_data || {}).reduce((acc, [field, value]) => {
    if (props.revision.revised_data[field] !== value) {
      acc[field] = {
        original: value,
        revised: props.revision.revised_data[field]
      }
    }
    return acc
  }, {}) : {}

const fieldLabel = (field) => ({
  'spt_number': 'SPT Number',
  'filing_date': 'Filing Date',
  'received_date': 'Received Date',
  'reported_amount': 'Reported Amount',
  'disputed_amount': 'Disputed Amount',
  'vat_in_amount': 'VAT In Amount',
  'vat_out_amount': 'VAT Out Amount',
  'description': 'Description'
}[field] || field)

const close = () => emit('close')
</script>

<style scoped>
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-content {
  background: white;
  border-radius: 8px;
  width: 90%;
  max-width: 700px;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px;
  border-bottom: 1px solid #eee;
}

.modal-header h2 {
  margin: 0;
  font-size: 18px;
}

.close-btn {
  background: none;
  border: none;
  font-size: 24px;
  cursor: pointer;
  color: #999;
}

.modal-body {
  padding: 20px;
  max-height: 400px;
  overflow-y: auto;
}

.comparison-table {
  overflow-x: auto;
}

table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

thead {
  background: #f5f5f5;
}

th {
  padding: 10px;
  text-align: left;
  border-bottom: 2px solid #ddd;
  font-weight: bold;
}

td {
  padding: 10px;
  border-bottom: 1px solid #eee;
}

.changed-row {
  background: #fffaf0;
}

.field-name {
  font-weight: bold;
  width: 25%;
}

.col-original {
  width: 37.5%;
}

.col-revised {
  width: 37.5%;
}

.old-value {
  display: inline-block;
  padding: 4px 6px;
  background: #ffebee;
  color: #c62828;
  border-radius: 3px;
}

.new-value {
  display: inline-block;
  padding: 4px 6px;
  background: #e8f5e9;
  color: #2e7d32;
  border-radius: 3px;
}

.no-changes {
  text-align: center;
  padding: 20px;
  color: #999;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  padding: 20px;
  border-top: 1px solid #eee;
}

.btn {
  padding: 10px 20px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
}

.btn-secondary {
  background: #6c757d;
  color: white;
}
</style>
```

---

## 🎯 FRONTEND INTEGRATION

### 1. Install di SPT Form Component

```vue
<template>
  <div class="spt-form">
    <!-- Form Header dengan Button Request Revision -->
    <div class="form-header">
      <h2>SPT Filling (Stage 1)</h2>
      <button 
        v-if="canRequestRevision"
        @click="showRevisionModal = true"
        class="btn btn-outline"
      >
        🔄 Request Revision
      </button>
    </div>

    <!-- Form Fields -->
    <form @submit.prevent="submit">
      <!-- ... existing form fields ... -->
    </form>

    <!-- Revision History Panel -->
    <RevisionHistoryPanel 
      :case-id="caseId"
      :tax-case="taxCase"
      :revisions="revisions"
      :current-user="currentUser"
      @refresh="loadRevisions"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import RevisionHistoryPanel from './RevisionHistoryPanel.vue'

const props = defineProps({
  caseId: { type: Number, required: true },
  currentUser: { type: Object, required: true }
})

const taxCase = ref({})
const revisions = ref([])

const canRequestRevision = computed(() => {
  if (!taxCase.value.submitted_at) return false
  const inProgress = revisions.value.find(r =>
    ['PENDING_APPROVAL', 'APPROVED', 'SUBMITTED'].includes(r.revision_status)
  )
  return !inProgress
})

onMounted(() => {
  loadRevisions()
})

const loadRevisions = async () => {
  try {
    const response = await fetch(
      `/api/tax-cases/${props.caseId}/revisions`,
      {
        headers: { 'Authorization': `Bearer ${localStorage.getItem('auth_token')}` }
      }
    )
    const data = await response.json()
    revisions.value = data.data || []
  } catch (err) {
    console.error('Failed to load revisions:', err)
  }
}
</script>
```

---

## ⚠️ ERROR HANDLING

| Error | Cause | Solution |
|-------|-------|----------|
| "Cannot request revision for unsubmitted data" | Data belum di-submit | Submit data dulu |
| "There is already a revision in progress" | Ada revision pending | Tunggu atau check revision ID |
| "Can only approve revisions in PENDING_APPROVAL" | Status salah | Check status revision sebelumnya |
| "This action is unauthorized" | Role salah | User/PIC vs Holding |
| "Revised data fields must match original fields" | Field tidak match | Pastikan semua field ada |

---

## 🚀 MIGRATION & SETUP

### 1. Run Migration
```bash
php artisan migrate
```

**Status:** ✅ SUDAH DIJALANKAN

### 2. Verify Routes
```bash
php artisan route:list | grep revision
```

### 3. Test Endpoint
```bash
curl -X POST http://localhost:8000/api/tax-cases/1/revisions/request \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"fields":["disputed_amount"],"reason":"update berdasarkan audit"}'
```

---

## 📋 RINGKASAN

✅ **Backend:**
- 6 API endpoints ready
- Authorization policy implemented
- 6 events created
- Database migrated

✅ **Frontend:**
- RevisionHistoryPanel.vue
- RequestRevisionModal.vue
- BeforeAfterComparison.vue

✅ **Documentation:**
- 1 comprehensive file saja (ini)

**Status:** READY FOR PRODUCTION

---

**Last Updated:** January 13, 2026  
**Status:** ✅ COMPLETE
