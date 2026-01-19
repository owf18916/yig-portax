# Stage 8 Architecture Diagram

## System Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           BROWSER (User Interface)                      │
│                                                                          │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │ URL: /tax-cases/:id/workflow/8                                     │ │
│  │                                                                    │ │
│  │ ┌──────────────────────────────────────────────────────────────┐  │ │
│  │ │ AppealSubmissionForm.vue (COMPONENT)                         │  │ │
│  │ │                                                              │  │ │
│  │ │ Template:                                                   │  │ │
│  │ │  ├─ Loading Overlay                                         │  │ │
│  │ │  ├─ Case Info Banner                                        │  │ │
│  │ │  ├─ Split Screen (50/50):                                   │  │ │
│  │ │  │  ├─ LEFT: StageForm Component                            │  │ │
│  │ │  │  │  ├─ Form Title & Description                          │  │ │
│  │ │  │  │  ├─ FormField (appeal_letter_number)                  │  │ │
│  │ │  │  │  ├─ FormField (submission_date)                       │  │ │
│  │ │  │  │  ├─ FormField (appeal_amount)                         │  │ │
│  │ │  │  │  ├─ FormField (dispute_number)                        │  │ │
│  │ │  │  │  ├─ Document Upload Section                           │  │ │
│  │ │  │  │  └─ Action Buttons (Submit, Save Draft)               │  │ │
│  │ │  │  │                                                        │  │ │
│  │ │  │  └─ RIGHT: RevisionHistoryPanel Component                │  │ │
│  │ │  │     ├─ Revision List                                     │  │ │
│  │ │  │     ├─ Revision Details                                  │  │ │
│  │ │  │     ├─ RequestRevisionModalV2                            │  │ │
│  │ │  │     ├─ Approval/Rejection Actions                        │  │ │
│  │ │  │     └─ Document Preview                                  │  │ │
│  │ │  │                                                           │  │ │
│  │ │                                                              │  │ │
│  │ │ Script:                                                     │  │ │
│  │ │  ├─ Data State:                                             │  │ │
│  │ │  │  ├─ caseId                                               │  │ │
│  │ │  │  ├─ fields (4 form fields)                               │  │ │
│  │ │  │  ├─ prefillData (appeal_submission data)                 │  │ │
│  │ │  │  ├─ revisions (revision history)                         │  │ │
│  │ │  │  └─ currentDocuments (stage 8 docs)                      │  │ │
│  │ │  │                                                           │  │ │
│  │ │  └─ Lifecycle:                                              │  │ │
│  │ │     ├─ onMounted:                                            │  │ │
│  │ │     │  ├─ fetch /api/tax-cases/:id                          │  │ │
│  │ │     │  ├─ loadRevisions()                                    │  │ │
│  │ │     │  └─ loadDocuments()                                    │  │ │
│  │ │     │                                                        │  │ │
│  │ │     └─ Methods:                                              │  │ │
│  │ │        ├─ refreshTaxCase() [on submit]                      │  │ │
│  │ │        ├─ loadRevisions()                                    │  │ │
│  │ │        └─ loadDocuments()                                    │  │ │
│  │ │                                                              │  │ │
│  │ └──────────────────────────────────────────────────────────────┘  │ │
│  │                                                                    │ │
│  │ Router Configuration:                                             │ │
│  │  Path: /tax-cases/:id/workflow/8                                  │ │
│  │  Name: AppealSubmissionForm                                        │ │
│  │  Component: AppealSubmissionForm.vue                               │ │
│  │  Auth: Required                                                    │ │
│  │                                                                    │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↓
                           HTTP (REST API)
                                    ↓
┌─────────────────────────────────────────────────────────────────────────┐
│                         BACKEND (Laravel API)                          │
│                                                                          │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │ Route: POST /api/tax-cases/{id}/workflow/8                        │ │
│  │ File: routes/api.php (lines 312-321)                              │ │
│  │                                                                    │ │
│  │ Handler Logic:                                                   │ │
│  │  ├─ Extract stage data (fields 1-4)                              │ │
│  │  ├─ Create appealData array                                      │ │
│  │  ├─ Add tax_case_id                                              │ │
│  │  ├─ Call AppealSubmission::updateOrCreate()                      │ │
│  │  ├─ Create WorkflowHistory record                                │ │
│  │  ├─ Update TaxCase.current_stage = 8                             │ │
│  │  └─ Return updated TaxCase with relationships                    │ │
│  │                                                                    │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                    ↓                                     │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │ Workflow Processing                                                │ │
│  │                                                                    │ │
│  │ RequestRevision Flow:                                             │ │
│  │  ├─ RevisionService::requestRevision()                            │ │
│  │  ├─ Detect stage_code = 8                                         │ │
│  │  ├─ Load appealSubmission relationship                            │ │
│  │  ├─ Capture original data (all 4 fields)                          │ │
│  │  ├─ Create Revision record with:                                 │ │
│  │  │  ├─ stage_code = 8                                             │ │
│  │  │  ├─ original_data                                              │ │
│  │  │  ├─ proposed_values                                            │ │
│  │  │  └─ reason                                                      │ │
│  │  └─ Return Revision with requestedBy                              │ │
│  │                                                                    │ │
│  │ ApproveRevision Flow:                                             │ │
│  │  ├─ RevisionService::approveRevision()                            │ │
│  │  ├─ Load appealSubmission relationship                            │ │
│  │  ├─ Update appealSubmission with proposed_values                  │ │
│  │  ├─ Mark Revision as approved                                     │ │
│  │  ├─ Update WorkflowHistory                                        │ │
│  │  └─ Return Revision with approvedBy                               │ │
│  │                                                                    │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                    ↓                                     │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │ Database Layer                                                     │ │
│  │                                                                    │ │
│  │ Tables Affected:                                                  │ │
│  │                                                                    │ │
│  │ ┌─ tax_cases                                                      │ │
│  │ │  ├─ id                                                          │ │
│  │ │  ├─ case_number                                                 │ │
│  │ │  ├─ current_stage = 8 (updated)                                 │ │
│  │ │  └─ ...other fields...                                          │ │
│  │ │                                                                  │ │
│  │ ├─ appeal_submissions (Main Data Table)                           │ │
│  │ │  ├─ id                                                          │ │
│  │ │  ├─ tax_case_id (FK)                                            │ │
│  │ │  ├─ appeal_letter_number (Field 1)                              │ │
│  │ │  ├─ submission_date (Field 2)                                   │ │
│  │ │  ├─ appeal_amount (Field 3)                                     │ │
│  │ │  ├─ dispute_number (Field 4)                                    │ │
│  │ │  ├─ status                                                      │ │
│  │ │  └─ timestamps                                                  │ │
│  │ │                                                                  │ │
│  │ ├─ workflow_histories (Audit Trail)                               │ │
│  │ │  ├─ id                                                          │ │
│  │ │  ├─ tax_case_id (FK)                                            │ │
│  │ │  ├─ stage_id = 8                                                │ │
│  │ │  ├─ status (draft/submitted)                                    │ │
│  │ │  ├─ action                                                      │ │
│  │ │  └─ user_id                                                     │ │
│  │ │                                                                  │ │
│  │ ├─ revisions (Revision Tracking)                                  │ │
│  │ │  ├─ id                                                          │ │
│  │ │  ├─ revisable_type = "AppealSubmission"                         │ │
│  │ │  ├─ revisable_id                                                │ │
│  │ │  ├─ stage_code = 8                                              │ │
│  │ │  ├─ original_data (JSON)                                        │ │
│  │ │  ├─ proposed_values (JSON)                                      │ │
│  │ │  ├─ revision_status                                             │ │
│  │ │  └─ requested_by / approved_by                                  │ │
│  │ │                                                                  │ │
│  │ └─ documents (File Management)                                    │ │
│  │    ├─ id                                                          │ │
│  │    ├─ documentable_type = "AppealSubmission"                      │ │
│  │    ├─ documentable_id                                             │ │
│  │    ├─ stage_code = 8                                              │ │
│  │    ├─ file_path                                                   │ │
│  │    └─ timestamps                                                  │ │
│  │                                                                    │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Data Flow Diagram

### 1. Initial Load Flow:
```
User navigates to /tax-cases/123/workflow/8
             ↓
AppealSubmissionForm.vue mounts
             ↓
onMounted triggered
             ↓
fetch /api/tax-cases/123
             ↓
TaxCaseController::show() returns TaxCase with relationships:
  - appeal_submission (with all 4 fields)
  - workflow_histories
             ↓
Component receives data
             ↓
formatDateForInput() converts dates
             ↓
prefillData populated with:
  - appeal_letter_number
  - submission_date
  - appeal_amount
  - dispute_number
             ↓
StageForm renders with data
             ↓
loadRevisions() & loadDocuments() triggered
             ↓
Page ready for user interaction
```

### 2. Submit Flow:
```
User fills form fields (1-4)
             ↓
User clicks Submit
             ↓
StageForm validates required fields (1-3)
             ↓
POST /api/tax-cases/123/workflow/8
  {
    "appeal_letter_number": "SB/2024/001",
    "submission_date": "2024-01-20",
    "appeal_amount": 500000000,
    "dispute_number": "001/BDG/2024"
  }
             ↓
Route handler processes Stage 8 endpoint
             ↓
AppealSubmission::updateOrCreate([
  'tax_case_id' => 123
], $appealData)
             ↓
WorkflowHistory::create([
  'stage_id' => 8,
  'status' => 'submitted'
])
             ↓
TaxCase::update([
  'current_stage' => 8
])
             ↓
Response returns updated TaxCase
             ↓
Component displays success message
             ↓
refreshTaxCase() reloads data
             ↓
Form displays latest data
```

### 3. Revision Request Flow:
```
User clicks "Request Revision" in RevisionHistoryPanel
             ↓
RequestRevisionModalV2 opens
             ↓
User selects fields (e.g., appeal_amount, dispute_number)
User enters reason
             ↓
User clicks "Request Revision"
             ↓
Modal posts to revision API
             ↓
RevisionService::requestRevision() called with:
  - $revisable = TaxCase (123)
  - $fields = ['appeal_amount', 'dispute_number']
  - $stageCode = 8
             ↓
Service detects stage_code == 8
             ↓
Service loads appealSubmission relationship
             ↓
Service captures original values:
  - appeal_amount: 500000000
  - dispute_number: "001/BDG/2024"
             ↓
Revision::create([
  'revisable_type' => 'AppealSubmission',
  'revisable_id' => <appeal_submission_id>,
  'stage_code' => 8,
  'original_data' => {...},
  'proposed_values' => {...},
  'revision_status' => 'requested'
])
             ↓
Event RevisionRequested fired
             ↓
Modal closes, list refreshes
             ↓
Admin reviews revision
```

### 4. Revision Approval Flow:
```
Admin clicks "Approve" on revision
             ↓
RevisionService::approveRevision() called
             ↓
Service loads appealSubmission relationship
             ↓
Service updates appealSubmission with proposed_values:
  AppealSubmission->update([
    'appeal_amount' => <proposed_value>,
    'dispute_number' => <proposed_value>
  ])
             ↓
Revision marked as approved:
  Revision->update([
    'revision_status' => 'approved',
    'approved_by' => <admin_id>
  ])
             ↓
Event RevisionApproved fired
             ↓
WorkflowHistory updated with approval info
             ↓
Component refreshes and displays new state
             ↓
RevisionHistoryPanel shows approval details
```

---

## Component Hierarchy:
```
AppealSubmissionForm.vue (Root)
├── StageForm.vue
│   ├── FormField.vue (x4 for each field)
│   ├── textarea (for notes)
│   ├── DocumentUpload section
│   └── Button (Submit, Save Draft)
└── RevisionHistoryPanel.vue
    ├── RevisionList
    ├── RequestRevisionModalV2.vue
    │   ├── FormField (for field selection)
    │   ├── textarea (for reason)
    │   └── Button (Request)
    └── DocumentPreview
```

---

## State Management:
```
AppealSubmissionForm.vue Local State:
├─ caseId (from route params)
├─ fields (4 form field definitions)
├─ prefillData (current data object)
├─ formData (form inputs, reactive)
├─ revisions (revision history array)
├─ currentDocuments (stage 8 documents)
├─ caseData (full tax case object)
├─ currentUser (authenticated user)
├─ isLoading (loading indicator)
├─ caseStatus (tax case status)
└─ preFilledMessage (display message)
```

---

## API Endpoints Used:
```
1. GET /api/tax-cases/:id
   └─ Fetch TaxCase with relationships

2. POST /api/tax-cases/:id/workflow/8
   └─ Save/Submit Stage 8 data

3. GET /api/revisions?case_id=:id
   └─ Fetch revision history

4. POST /api/revisions/:id/approve
   └─ Approve revision

5. POST /api/revisions
   └─ Create revision request

6. GET /api/tax-cases/:id/documents?stage_code=8
   └─ Fetch stage documents

7. GET /api/user
   └─ Get current user info
```

---

**Architecture Version:** 1.0  
**Last Updated:** January 19, 2026  
**Status:** Complete & Documented
