# Phase 4 Frontend Integration - Import Guide

## Quick Reference for Component Usage

### Store (State Management)

```javascript
import { useTaxCaseStore } from '@/stores/taxCaseStore'

// In component setup:
const store = useTaxCaseStore()

// Access state:
store.currentCase          // Current selected tax case
store.cases                // List of all cases
store.loading              // Loading flag
store.error                // Error message
store.success              // Success message
store.workflowHistory      // Timeline events
store.documents            // Case documents
store.entities             // Reference data
store.fiscalYears          // Reference data
store.currencies           // Reference data
store.caseStatuses         // Reference data

// Call actions:
await store.loadReferenceData()           // Load all master data
await store.fetchCases()                  // Get case list
await store.fetchCase(caseId)             // Load single case
await store.createCase(payload)           // Create new case
await store.updateCase(caseId, payload)   // Update case
await store.createSp2(caseId, payload)    // Create SP2
await store.approveSp2(caseId, recordId)  // Approve SP2
// ... and 20+ more stage actions
```

### API Composable (HTTP Client)

```javascript
import { useTaxCaseApi } from '@/composables/useTaxCaseApi'

// In component setup:
const api = useTaxCaseApi()

// Endpoints (60+ methods):
api.getTaxCases(params)                   // Get list
api.getTaxCase(caseId)                    // Get single
api.createTaxCase(payload)                // Create
api.updateTaxCase(caseId, payload)        // Update
api.getWorkflowHistory(caseId)            // Workflow events
api.getDocuments(caseId)                  // Case documents

// Reference data:
api.getEntities()                         // Entities list
api.getFiscalYears()                      // Fiscal years
api.getCurrencies()                       // Currencies
api.getCaseStatuses()                     // Case statuses
api.getCurrentUser()                      // Auth info

// Stage operations (similar pattern for each):
api.createSp2(caseId, payload)
api.getSp2(caseId, recordId)
api.approveSp2(caseId, recordId, payload)
api.rejectSp2(caseId, recordId, payload)
// ... 50+ more endpoints
```

### Form Validation Composable

```javascript
import { useFormValidation, isValidNPWP, isValidAmount } from '@/composables/useFormValidation'

// In component setup:
const { 
    formErrors,           // Object { fieldName: [errors] }
    fieldErrors,          // Object { fieldName: [errors] }
    isSubmitting,         // Boolean
    hasErrors,            // Computed
    validateField,        // Function(fieldName, value, rules)
    validateForm,         // Function(formData, rules)
    clearErrors,          // Function
    clearFieldError,      // Function(fieldName)
    handleValidationError // Function(error) for API responses
} = useFormValidation()

// Validation rules object:
const validationRules = {
    email: {
        required: true,
        label: 'Email Address',
        email: true
    },
    amount: {
        required: true,
        label: 'Amount',
        custom: (value) => isValidAmount(value),
        customMessage: 'Must be a valid positive number'
    },
    npwp: {
        required: true,
        label: 'NPWP',
        custom: (value) => isValidNPWP(value),
        customMessage: 'Format: XX.XXX.XXX.X-XXX.XXX'
    }
}

// Usage in form:
if (validateForm(formData, validationRules)) {
    // Form is valid, submit
}

// Custom validators available:
isValidEmail(email)        // email format
isValidPhone(phone)        // phone format
isValidDate(date)          // date format
isValidNPWP(npwp)         // Indonesia NPWP format
isValidAmount(amount)      // numeric >= 0
```

### Components

#### Dashboard (Main Page)

```vue
<template>
    <!-- Rendered at route: / or /dashboard -->
</template>

<script setup>
import { useTaxCaseStore } from '@/stores/taxCaseStore'
import TaxCaseForm from '@/components/forms/TaxCaseForm.vue'
import CaseStatusCards from '@/components/CaseStatusCards.vue'
import WorkflowTimeline from '@/components/WorkflowTimeline.vue'
</script>
```

**Features:**
- Case list with click-to-select
- New case form toggle
- Case details view
- Workflow timeline
- Status cards
- Auto-load reference data on mount

#### TaxCaseForm (Stage 1 Creation)

```vue
<script setup>
import { useTaxCaseStore } from '@/stores/taxCaseStore'
import { useFormValidation, isValidNPWP, isValidAmount } from '@/composables/useFormValidation'

defineProps({
    caseId: [String, Number]  // optional, enables edit mode
})

defineEmits(['submit', 'cancel'])
</script>
```

**Fields:**
- entity_id (select, required)
- fiscal_year_id (select, required)
- case_type (select, required) 
- npwp (text, required, format validation)
- spt_number (text, optional)
- tax_amount (number, required)
- notes (textarea, optional)

**Events:**
- `@submit` - emitted after successful creation
- `@cancel` - emitted when user cancels

#### CaseStatusCards (Progress Display)

```vue
<script setup>
import { useTaxCaseStore } from '@/stores/taxCaseStore'
</script>
```

**Displays:**
- Current stage (1-13)
- Case status (draft, in_progress, etc.)
- Progress percentage (0-100%)
- Case reference number
- 13-stage workflow timeline with checkmarks

#### WorkflowTimeline (History View)

```vue
<script setup>
import { useTaxCaseStore } from '@/stores/taxCaseStore'
</script>
```

**Displays:**
- All workflow events in chronological order
- Stage name and action icon
- Timestamp
- User who made the action
- Action badge (created, approved, rejected, etc.)
- Additional notes/metadata

---

## Common Implementation Patterns

### Pattern 1: Load Case Data on Mount

```vue
<script setup>
import { onMounted } from 'vue'
import { useTaxCaseStore } from '@/stores/taxCaseStore'

const store = useTaxCaseStore()
const props = defineProps({ caseId: [String, Number] })

onMounted(async () => {
    if (store.entities.length === 0) {
        await store.loadReferenceData()
    }
    if (props.caseId) {
        await store.fetchCase(props.caseId)
    }
})
</script>
```

### Pattern 2: Form Submission with Validation

```vue
<script setup>
import { ref } from 'vue'
import { useTaxCaseStore } from '@/stores/taxCaseStore'
import { useFormValidation } from '@/composables/useFormValidation'

const store = useTaxCaseStore()
const { formErrors, validateForm, handleValidationError } = useFormValidation()
const isSubmitting = ref(false)

const form = ref({
    field1: '',
    field2: ''
})

const validationRules = {
    field1: { required: true },
    field2: { required: true, minLength: 5 }
}

const handleSubmit = async () => {
    if (!validateForm(form.value, validationRules)) {
        return  // Show errors
    }

    isSubmitting.value = true
    try {
        await store.createSomething(form.value)
        // Success - store shows success message
        emit('submit')
    } catch (error) {
        if (error.response?.data?.errors) {
            handleValidationError(error)
        } else {
            console.error('Error:', error)
        }
    } finally {
        isSubmitting.value = false
    }
}
</script>
```

### Pattern 3: Computed Status Classes

```javascript
const getStatusBadgeClass = (status) => {
    const colors = {
        'draft': 'bg-gray-100 text-gray-800',
        'in_progress': 'bg-blue-100 text-blue-800',
        'approved': 'bg-green-100 text-green-800',
        'rejected': 'bg-red-100 text-red-800'
    }
    return colors[status] || 'bg-gray-100 text-gray-800'
}

const formatStatus = (status) => {
    return status
        .replace(/_/g, ' ')
        .split(' ')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ')
}
```

### Pattern 4: Watch Store Changes

```vue
<script setup>
import { watch } from 'vue'
import { useTaxCaseStore } from '@/stores/taxCaseStore'

const store = useTaxCaseStore()

// React to case selection
watch(() => store.currentCase, (newCase) => {
    if (newCase) {
        // Do something with the new case
    }
}, { deep: true })

// React to success
watch(() => store.success, (message) => {
    if (message) {
        // Success message appeared
        // Auto-dismiss after 3 seconds (handled by store)
    }
})

// React to errors
watch(() => store.error, (message) => {
    if (message) {
        // Error message appeared
    }
})
</script>
```

---

## Creating New Stage Form Components

### Template for New Stage Form

```vue
<template>
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <!-- Header with stage info -->
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h2 class="text-xl font-bold text-gray-900">Stage X: Description</h2>
                <p class="mt-1 text-sm text-gray-600">Details about this stage</p>
            </div>
            <span class="inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-sm font-medium text-blue-800">
                Stage X/13
            </span>
        </div>

        <!-- Errors -->
        <div v-if="hasErrors" class="mb-6 rounded-lg bg-red-50 p-4">
            <!-- Error list -->
        </div>

        <!-- Form fields -->
        <form @submit.prevent="handleSubmit" class="space-y-6">
            <!-- Your fields here -->
            
            <!-- Submit buttons -->
            <div class="flex gap-3">
                <button type="submit" :disabled="isSubmitting || store.loading">
                    Approve Record
                </button>
                <button type="button" @click="$emit('reject')">
                    Reject Record
                </button>
            </div>
        </form>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useTaxCaseStore } from '@/stores/taxCaseStore'
import { useFormValidation } from '@/composables/useFormValidation'

const props = defineProps({
    caseId: [String, Number],
    recordId: [String, Number]
})

const emit = defineEmits(['submit', 'reject', 'cancel'])

const store = useTaxCaseStore()
const { formErrors, validateForm, clearFieldError } = useFormValidation()

const isSubmitting = ref(false)
const hasErrors = computed(() => Object.keys(formErrors.value).length > 0)

const form = ref({
    // Your fields
})

const validationRules = {
    // Your rules
}

const handleSubmit = async () => {
    if (!validateForm(form.value, validationRules)) {
        return
    }

    isSubmitting.value = true
    try {
        await store.approveSomeRecord(props.caseId, props.recordId, form.value)
        emit('submit')
    } catch (error) {
        console.error('Error:', error)
    } finally {
        isSubmitting.value = false
    }
}

onMounted(async () => {
    // Load any necessary data
})
</script>
```

---

## Debugging Tips

### Check Store State in Browser Console

```javascript
// In browser DevTools console:
import { useTaxCaseStore } from '@/stores/taxCaseStore'
const store = useTaxCaseStore()
console.log(store.currentCase)
console.log(store.loading)
console.log(store.error)
console.log(store.workflowHistory)
```

### Watch API Calls

Check Network tab in DevTools for:
- `/api/tax-cases` - Case list
- `/api/tax-cases/{id}` - Single case
- `/api/tax-cases/{id}/workflow-history` - Timeline
- `/api/entities` - Reference data
- `/api/tax-cases/{id}/sp2` - Stage 2 operations

### Validate Form Schema

Each form should validate against rules before submit. Check browser console for validation errors before API calls.

### Check Pinia DevTools

Install Pinia DevTools browser extension to:
- View store state
- Track mutations
- Replay actions
- Time-travel debug

---

## Next Components to Create

**Immediate (Session 30):**
1. Sp2RecordForm (Stage 2 - approve/reject)
2. SphpRecordForm (Stage 3 - approve/reject)
3. SkpRecordForm (Stage 4 - approve + decision routing)

**Follow-up (Session 31):**
4. ObjectionSubmissionForm (Stage 5)
5. ObjectionDecisionForm (Stage 7 - approve + routing)

**Later (Sessions 32+):**
6. AppealSubmissionForm (Stage 8)
7. AppealDecisionForm (Stage 10 - approve + routing)
8. SupremeCourtSubmissionForm (Stage 11)
9. SupremeCourtDecisionForm (Stage 12 - approve + routing)
10. RefundProcessForm + BankTransferManager

---

**Document Version:** Phase 4 Session 29 - Complete
**Last Updated:** After TaxCaseForm, CaseStatusCards, WorkflowTimeline creation
**Status:** ✅ Ready for stage-specific form implementation
