# Stage 8 Implementation - Quick Reference

## What Was Implemented

Stage 8 (Appeal Submission) is now fully implemented with **exact same UI/UX as Stage 2 & Stage 3**.

---

## 📍 Frontend Route

```
/tax-cases/:id/workflow/8
```

Component: `AppealSubmissionForm.vue` (newly created)

---

## 📋 Form Fields

| # | Field Name | Type | Label | Required | Notes |
|---|---|---|---|---|---|
| 1 | `appeal_letter_number` | text | Nomor Surat Banding | ✅ | Phase 1: User input |
| 2 | `submission_date` | date | Tanggal Dilaporkan | ✅ | Phase 1: User input |
| 3 | `appeal_amount` | number | Nilai | ✅ | Phase 1: User input |
| 4 | `dispute_number` | text | Nomor Sengketa | ❌ | Phase 2: Court assigns later |

---

## 🔧 Backend Integration Points

### 1. API Endpoint
**Endpoint:** `POST /api/tax-cases/{id}/workflow/8`

**Request Data:**
```json
{
  "appeal_letter_number": "SB/2024/001",
  "submission_date": "2024-01-20",
  "appeal_amount": 500000000,
  "dispute_number": "001/BDG/2024"
}
```

**Handler Location:** `routes/api.php` (lines ~311-320)

### 2. Data Storage
**Table:** `appeal_submissions`  
**Primary Model:** `AppealSubmission`  
**Relationship:** `TaxCase->appealSubmission()` (HasOne)

### 3. Revision System
**Service:** `RevisionService` (app/Services/)

**Updates Made:**
- `requestRevision()` - Added Stage 8 handling (~line 107-117)
- `approveRevision()` - Added Stage 8 update logic (~line 268-277)

### 4. Field Configuration
**File:** `resources/js/composables/useRevisionFields.js`

**Added Configuration:**
```javascript
'appeal-submissions': {
  labels: {
    'appeal_letter_number': 'Appeal Letter Number',
    'submission_date': 'Submission Date',
    'appeal_amount': 'Appeal Amount',
    'dispute_number': 'Dispute Number',
    'supporting_docs': 'Supporting Documents'
  },
  availableFields: [...],
  documentFields: ['supporting_docs']
}
```

---

## 📁 Files Modified/Created

### Created (1 file):
- ✅ `resources/js/pages/AppealSubmissionForm.vue` - NEW component

### Modified (4 files):
- ✅ `app/Services/RevisionService.php` - Added Stage 8 handling
- ✅ `routes/api.php` - Added Stage 8 endpoint
- ✅ `resources/js/router/index.js` - Added route
- ✅ `resources/js/composables/useRevisionFields.js` - Added field config

---

## 🎯 UI/UX Pattern (Identical to Stage 2 & 3)

### Layout:
```
┌─────────────────────────────────────────────────────────────┐
│ Left (50%)                    │ Right (50%)                 │
├─────────────────────────────────────────────────────────────┤
│ ┌─────────────────────────────┐                             │
│ │ Loading Overlay (if loading)│                             │
│ └─────────────────────────────┘                             │
│                                                             │
│ StageForm Component:          RevisionHistoryPanel:         │
│ • Case Info Banner            • Revision History List      │
│ • Form Fields (4)             • Request Revision Modal     │
│ • Submit/Save Buttons         • Approve/Reject Actions     │
│ • Success Messages            • Document Management        │
│ • Documents Section           • Field-level Tracking       │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Component Hierarchy:
```
AppealSubmissionForm.vue
├── StageForm (core form component)
│   ├── FormField (repeated for each field)
│   ├── Textarea (for notes)
│   ├── Document Upload
│   └── Submit/Save Buttons
└── RevisionHistoryPanel
    ├── Revision List
    ├── RequestRevisionModalV2
    └── Document Management
```

---

## 🔄 Data Flow

### Submit Phase 1:
```
User Input → FormValidation → API (/api/.../workflow/8)
  → AppealSubmission::updateOrCreate()
  → WorkflowHistory record created
  → Case status updated
  → Response with tax case data
  → Form refreshes with latest data
```

### Request Revision:
```
User clicks "Request Revision" → Modal opens
→ User selects fields + reason
→ RevisionService::requestRevision()
→ Revision record created (stage_code=8)
→ Original data captured
→ Revision marked as 'requested'
```

### Approve Revision:
```
Admin reviews revision → Clicks "Approve"
→ RevisionService::approveRevision()
→ Updates appealSubmission with proposed values
→ Revision marked as 'approved'
→ WorkflowHistory updated
```

---

## ✨ Special Features

### 1. Multi-Phase Support
- Phase 1 fields (1-3) required by user
- Phase 2 field (4) optional, assigned by court
- Partial updates supported

### 2. Document Management
- Upload supporting docs for Stage 8
- Automatically tagged with `stage_code=8`
- Tracked in revisions

### 3. Revision Tracking
- All 4 fields tracked individually
- Original vs. proposed values stored
- Full audit trail maintained

### 4. Auto-Loading
- Fetches existing appeal_submission on page load
- Pre-fills form with latest data
- Auto-refreshes after save/submit

---

## 🧪 Quick Test

### Frontend Test:
```
1. Navigate to /tax-cases/1/workflow/8
2. Form should load with 4 fields
3. Enter test data:
   - appeal_letter_number: "SB/2024/TEST"
   - submission_date: Today's date
   - appeal_amount: 1000000
   - dispute_number: (leave empty)
4. Click Submit
5. Should see success message
6. Refresh page - data should persist
```

### Backend Test:
```
1. Check appeal_submissions table has new record
2. Check workflow_histories has stage_id=8 entry
3. Check tax_cases.current_stage = 8
4. Verify appeal_submission loaded in GET /api/tax-cases/1
```

---

## 📖 Documentation

**Main Docs:**
- [STAGE-8-APPEAL-SUBMISSION-IMPLEMENTATION.md](./STAGE-8-APPEAL-SUBMISSION-IMPLEMENTATION.md) - Requirements & design
- [STAGE-8-IMPLEMENTATION-COMPLETED.md](./STAGE-8-IMPLEMENTATION-COMPLETED.md) - Detailed implementation notes

**Reference:**
- [STAGE_FORM_TEMPLATE_STANDARD_REFINED.md](./STAGE_FORM_TEMPLATE_STANDARD_REFINED.md) - Form patterns
- [STAGE-4-SKP-IMPLEMENTATION.md](./STAGE-4-SKP-IMPLEMENTATION.md) - Similar implementation

---

## ✅ Implementation Checklist

### Backend (DONE):
- ✅ AppealSubmission model exists
- ✅ TaxCase relationship configured
- ✅ API endpoint added (POST /api/.../workflow/8)
- ✅ RevisionService updated (Stage 8 handling)
- ✅ Field configuration added

### Frontend (DONE):
- ✅ AppealSubmissionForm.vue created
- ✅ Route added (/tax-cases/:id/workflow/8)
- ✅ StageForm integration complete
- ✅ RevisionHistoryPanel integrated
- ✅ Document management enabled

### Testing:
- ⏳ Manual browser testing
- ⏳ API endpoint testing
- ⏳ Revision workflow testing
- ⏳ Document upload testing

---

**Status:** ✅ IMPLEMENTATION COMPLETE  
**Ready for:** Testing & QA  
**Date:** January 19, 2026
