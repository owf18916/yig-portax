# Phase 4 - Component Architecture & Data Flow

## Complete Component Hierarchy

```
App.vue (root)
└── Router
    └── Dashboard.vue (main page)
        ├── TaxCaseForm (Stage 1 creation - NEW)
        │   ├── useFormValidation (composable)
        │   └── useTaxCaseStore (Pinia store)
        │
        ├── CaseStatusCards (Progress visualization - NEW)
        │   └── useTaxCaseStore
        │
        ├── WorkflowTimeline (History display - NEW)
        │   └── useTaxCaseStore
        │
        └── Tabs:
            ├── Cases List Tab
            │   ├── Sp2RecordForm (Stage 2 - TO BUILD)
            │   ├── SphpRecordForm (Stage 3 - TO BUILD)
            │   ├── SkpRecordForm (Stage 4 - TO BUILD)
            │   ├── ObjectionSubmissionForm (Stage 5 - TO BUILD)
            │   ├── ObjectionDecisionForm (Stage 7 - TO BUILD)
            │   ├── AppealSubmissionForm (Stage 8 - TO BUILD)
            │   ├── AppealDecisionForm (Stage 10 - TO BUILD)
            │   ├── SupremeCourtSubmissionForm (Stage 11 - TO BUILD)
            │   ├── SupremeCourtDecisionForm (Stage 12 - TO BUILD)
            │   ├── RefundProcessForm (Stage 12 - TO BUILD)
            │   └── KianSubmissionForm (Stage 12 - TO BUILD)
            ├── Case Details Tab
            │   └── Shows metadata and allows editing
            └── Timeline Tab
                └── WorkflowTimeline
```

## Data Flow Architecture

### 1. Initial App Load

```
App Mount
  └─> Router loads Dashboard.vue
       └─> Dashboard onMounted()
            ├─> store.loadReferenceData()
            │   ├─> API: /api/entities
            │   ├─> API: /api/fiscal-years
            │   ├─> API: /api/currencies
            │   └─> API: /api/case-statuses
            │       └─> store.entities, store.fiscalYears, etc. updated
            │
            └─> store.fetchCases()
                └─> API: /api/tax-cases
                    └─> store.cases updated
```

### 2. Create New Case Flow

```
User Action: Click "New Tax Case" button
  └─> Dashboard: showNewCaseForm = true
       └─> TaxCaseForm displays

User Action: Fill form + Click "Create Tax Case"
  └─> TaxCaseForm.handleSubmit()
       ├─> useFormValidation.validateForm()
       │   └─> Check all fields against rules
       │       └─> If invalid: show formErrors
       │
       └─> (if valid) store.createCase(payload)
            ├─> useTaxCaseApi.createTaxCase()
            │   ├─> HTTP POST /api/tax-cases
            │   └─> Response: new case object
            │
            ├─> store.currentCase = response
            ├─> store.success = "Case created successfully"
            ├─> Auto-clear success message after 3s
            │
            └─> TaxCaseForm emits 'submit'
                 └─> Dashboard.handleNewCaseSubmit()
                      ├─> toggleNewCaseForm() (hide form)
                      ├─> store.fetchCases() (refresh list)
                      └─> activeTab = 'cases'

Result: New case appears in list, user can select it
```

### 3. Select Case & View Timeline

```
User Action: Click case in list
  └─> Dashboard.selectCase(caseId)
       ├─> store.fetchCase(caseId)
       │   ├─> HTTP GET /api/tax-cases/{id}
       │   ├─> store.currentCase = response
       │   │
       │   ├─> Parallel: fetchWorkflowHistory(caseId)
       │   │   └─> HTTP GET /api/tax-cases/{id}/workflow-history
       │   │       └─> store.workflowHistory = events
       │   │
       │   └─> Parallel: fetchDocuments(caseId)
       │       └─> HTTP GET /api/tax-cases/{id}/documents
       │           └─> store.documents = docs
       │
       ├─> activeTab = 'details'
       │
       └─> CaseStatusCards auto-renders (watches store.currentCase)
            └─> Shows stage, status, progress

User can then:
  - Click "Case Details" tab → See all metadata
  - Click "Timeline" tab → See WorkflowTimeline
  - Stay on main tab → See cases list with selected highlighted
```

### 4. Stage Operations (Generic Pattern)

```
User Action: Click "Approve" on stage form
  └─> StageForm.handleSubmit()
       ├─> useFormValidation.validateForm()
       │   └─> Validate stage-specific fields
       │
       └─> store.approveStage(caseId, recordId, payload)
            ├─> useTaxCaseApi.approveStage()
            │   ├─> HTTP POST /api/tax-cases/{id}/stage/{recordId}/approve
            │   └─> Response: updated stage + case with new current_stage
            │
            ├─> store.stageData = response
            ├─> store.currentCase = updated case data
            ├─> store.success = "Stage approved"
            │
            └─> StageForm emits 'submit'
                 └─> Parent component refreshes

Result: 
  - Case stage advances
  - Workflow history updated
  - CaseStatusCards shows new stage
  - Timeline shows new event
```

### 5. Decision Routing Flow (SKP Example)

```
User Action: Approve SKP Record with type=LB
  └─> SkpRecordForm.handleSubmit()
       ├─> Validate SKP fields
       └─> store.approveSkp(caseId, recordId, {skp_type: 'LB', ...})
            ├─> API: POST /api/tax-cases/{id}/skp/{recordId}/approve
            │   └─> Backend Controller Routes:
            │       LB → moves current_stage to 12 (RefundProcess)
            │       NIHIL → moves to 5 (ObjectionSubmission)
            │       KB → moves to 5 (ObjectionSubmission)
            │
            ├─> store.currentCase.current_stage = 12 or 5
            ├─> store.success = "SKP approved. Routing..."
            │
            └─> CaseStatusCards auto-updates
                 └─> Shows new stage and target

UI shows: "If you selected LB, next stage is Refund Process. If NIHIL/KB, next is Objection."
```

## State Management Architecture

### Store Structure (useTaxCaseStore)

```
State:
├── Main Data
│   ├── currentCase         // Active case object
│   ├── cases              // List of all cases
│   └── loading/error/success   // UI states
│
├── Stage Data (13 refs)
│   ├── sp2Data
│   ├── sphpData
│   ├── skpData
│   ├── objectionSubmissionData
│   ├── spuhData
│   ├── objectionDecisionData
│   ├── appealSubmissionData
│   ├── appealExplanationData
│   ├── appealDecisionData
│   ├── supremeCourtSubmissionData
│   ├── supremeCourtDecisionData
│   ├── refundProcessData
│   └── kianData
│
└── Reference Data
    ├── entities          // Entities dropdown
    ├── fiscalYears       // Fiscal years dropdown
    ├── currencies        // Currencies for amounts
    ├── caseStatuses      // Status labels
    ├── workflowHistory   // Timeline events
    ├── documents         // Case attachments
    └── bankTransfers     // Refund process transfers

Computed:
├── currentStage        // current_case.current_stage
├── caseStatus          // current_case.status
├── isLoading/hasError/hasSuccess

Actions:
├── clearNotifications()
├── loadReferenceData()  // Load all dropdowns
├── fetchCases()        // Get case list
├── fetchCase()         // Get single case
├── createCase()        // Create new case
├── updateCase()        // Edit case
├── fetchWorkflowHistory()
├── fetchDocuments()
│
├── Stage Create/Approve/Reject (26+ actions)
│   └── Pattern: 
│       1. Call API via composable
│       2. Update stage ref + currentCase
│       3. Set success message
│       4. Auto-clear after 3s
│       5. Throw on error (let component catch)
│
└── Refund-specific:
    ├── addBankTransfer()
    ├── processBankTransfer()
    └── bankTransfers ref for tracking
```

## Validation Flow Architecture

### Validation Layers

```
User Input
  └─> HTML5 Input Types (type="number", type="email", etc.)
       └─> Browser basic validation
            └─> Custom Validators (useFormValidation)
                 ├─> Required check
                 ├─> Length checks (minLength, maxLength)
                 ├─> Format checks (email, phone, date, NPWP)
                 ├─> Range checks (min, max)
                 ├─> Custom functions
                 │
                 └─> Error Collection
                      ├─> formErrors { fieldName: [messages] }
                      └─> fieldErrors { fieldName: [messages] }
                           └─> UI displays errors below field
                                
On Submit:
  └─> If client validation passes
       └─> API Call
            ├─> If 422 Validation Error
            │   └─> handleValidationError()
            │        └─> Populate formErrors with server messages
            │             └─> UI re-renders with new errors
            │
            └─> If 200 Success
                 └─> Update store
                      └─> Show success message
```

### Validation Rule Schema

```javascript
const rules = {
    fieldName: {
        required: true,              // Not null/empty
        label: 'Field Label',        // For error messages
        minLength: 5,                // Minimum length
        maxLength: 50,               // Maximum length
        min: 100,                    // Minimum numeric value
        max: 10000,                  // Maximum numeric value
        email: true,                 // Email format
        phone: true,                 // Phone format
        date: true,                  // Date format
        custom: (value) => {         // Custom function
            return value % 2 === 0   // Return true if valid
        },
        customMessage: 'Must be even'  // Error message for custom
    }
}
```

## Component Lifecycle Examples

### TaxCaseForm Lifecycle

```
Mount:
  onMounted() → loadReferenceData() if needed → Form ready

User Interaction:
  Input → clearFieldError() → realtime validation
  
Submit:
  validateForm() → If invalid, show errors
               → If valid, store.createCase()
                         → Show loading
                         → API call
                         → Success → emit 'submit'
                         → Error → show in form errors

Unmount:
  Cleanup (nothing special needed)
```

### Dashboard Lifecycle

```
Mount:
  onMounted() → 
    loadReferenceData() → entities, fiscalYears, etc.
    fetchCases() → List of all cases
    Watch store changes

User Selects Case:
  selectCase() →
    fetchCase() → currentCase + history + documents
    activeTab = 'details'
    CaseStatusCards watches store, auto-renders
    WorkflowTimeline watches store, auto-renders

User Creates New Case:
  Click "New Tax Case" → TaxCaseForm shows
  Fill form → validate → submit
  Store emits success → Dashboard hears it
  clearNewCaseForm() → refresh list → Done

Unmount:
  Cleanup (nothing special needed)
```

## API Contract Examples

### GET /api/tax-cases (Response)

```json
{
  "data": [
    {
      "id": 1,
      "case_number": "TC-2024-0001",
      "entity_id": 1,
      "fiscal_year_id": 2024,
      "case_type": "CIT",
      "npwp": "01.234.567.8-901.234",
      "spt_number": "SPT-001",
      "tax_amount": "1000000000",
      "current_stage": 4,
      "status": "in_progress",
      "created_at": "2024-01-15T10:30:00Z",
      "updated_at": "2024-01-16T15:45:00Z"
    }
  ]
}
```

### POST /api/tax-cases (Request)

```json
{
  "entity_id": 1,
  "fiscal_year_id": 2024,
  "case_type": "CIT",
  "npwp": "01.234.567.8-901.234",
  "spt_number": "SPT-001",
  "tax_amount": "1000000000",
  "notes": "Initial filing"
}
```

### POST /api/tax-cases/{id}/skp/{recordId}/approve (Request)

```json
{
  "skp_type": "LB",  // or NIHIL, KB
  "issued_date": "2024-01-10",
  "summary": "Audit findings summary"
}
```

Response includes:
- Updated stage data
- Updated currentCase with new `current_stage`
- Workflow history event added

## Performance Considerations

1. **Parallel Data Loading**
   - fetchCase() loads case + history + documents in parallel
   - loadReferenceData() loads all dropdowns in parallel

2. **State Reuse**
   - Reference data cached in store after first load
   - Prevents duplicate API calls

3. **Computed Properties**
   - currentStage, caseStatus computed from currentCase
   - No watchers needed for derived state

4. **Notification Auto-Clear**
   - Success/error messages auto-clear after 3 seconds
   - Prevents store pollution

5. **Form Validation**
   - Client-side validation before API call
   - Reduces server requests

---

**Document Version:** Phase 4 Session 29 - Complete
**Status:** ✅ Ready for implementation
**Next:** Build remaining 10 stage-specific form components
