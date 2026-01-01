# Phase 2.5 Progress Tracker

**Started**: January 1, 2026, 15:45 WIB  
**Target Complete**: January 1, 2026, 18:30 WIB  

---

## Tasks

### 1. Generic Stage Form Builder Component
- [ ] Create file: `resources/js/components/StageForm.vue`
- [ ] Dynamic field rendering (text, number, date, select, textarea)
- [ ] Built-in validation framework
- [ ] Document upload section
- [ ] Success/error alerts
- [ ] Submit button with loading state
- **Target Time**: 45 min
- **Actual Time**: ___ min

### 2. SPT Filing Form
- [ ] Create file: `resources/js/pages/SptFilingForm.vue`
- [ ] Entity dropdown (get from case)
- [ ] Period display
- [ ] Currency selector
- [ ] Nilai Sengketa input
- [ ] Filing decision (Yes/No radio)
- [ ] Document upload
- [ ] API submission
- **Target Time**: 30 min
- **Actual Time**: ___ min

### 3. SKP Record Form with Decision Logic
- [ ] Create file: `resources/js/pages/SkpRecordForm.vue`
- [ ] SKP Number input
- [ ] Issue/Receipt dates
- [ ] SKP Type selector (SKP LB, NIHIL, SKP KB) ⭐ DECISION POINT
- [ ] Nilai SKP input
- [ ] Audit corrections breakdown (Royalty, Service, Other)
- [ ] Document upload
- [ ] Decision router: SKP LB → Refund path, else → Objection path
- [ ] Update case status based on decision
- **Target Time**: 30 min
- **Actual Time**: ___ min

### 4. Objection Decision Form with Routing
- [ ] Create file: `resources/js/pages/ObjectionDecisionForm.vue`
- [ ] Decision number input
- [ ] Decision date picker
- [ ] Decision type selector ⭐ DECISION POINT
  - Dikabulkan (Granted)
  - Dikabulkan Sebagian (Partially)
  - Ditolak (Rejected)
- [ ] Amount input
- [ ] Document upload
- [ ] Decision router:
  - Dikabulkan/Sebagian → Refund path
  - Ditolak → Appeal path
- [ ] Update case status
- **Target Time**: 30 min
- **Actual Time**: ___ min

### 5. Decision Logic Router & Integration
- [ ] Create file: `resources/js/utils/decisionRouter.js`
- [ ] Function: SKP type → next stage mapping
- [ ] Function: Objection decision → next stage mapping
- [ ] Function: Update case status
- [ ] Mock API calls for stage transitions
- [ ] Update TaxCaseDetail.vue to use new stages
- [ ] Update Router with new routes
- **Target Time**: 20 min
- **Actual Time**: ___ min

### 6. Route Configuration
- [ ] Add route: `/tax-cases/:id/workflow/1` → SptFilingForm
- [ ] Add route: `/tax-cases/:id/workflow/4` → SkpRecordForm
- [ ] Add route: `/tax-cases/:id/workflow/7` → ObjectionDecisionForm
- [ ] Test all routes load correctly
- **Target Time**: 10 min
- **Actual Time**: ___ min

### 7. Testing & Validation
- [ ] Form validation works on all 3 forms
- [ ] Document upload field appears
- [ ] API submissions trigger (mock)
- [ ] Success alerts show
- [ ] Navigation back to TaxCaseDetail works
- [ ] At least one complete flow: Create Case → Fill SPT → Fill SKP → See decision routing
- **Target Time**: 15 min
- **Actual Time**: ___ min

---

## Summary

| Task | Estimated | Actual | Status |
|------|-----------|--------|--------|
| Generic Form Builder | 45 min | ___ | ⏳ |
| SPT Filing Form | 30 min | ___ | ⏳ |
| SKP Record Form | 30 min | ___ | ⏳ |
| Objection Decision Form | 30 min | ___ | ⏳ |
| Decision Router | 20 min | ___ | ⏳ |
| Route Config | 10 min | ___ | ⏳ |
| Testing | 15 min | ___ | ⏳ |
| **TOTAL** | **180 min** | ___ | ⏳ |

---

## Notes

- Started at: ___________
- Completed at: ___________
- Blockers encountered: (none yet)
- Next steps: Phase 3 database setup (Jan 2)

---

## QA Checklist (Before marking complete)

- [ ] All files created
- [ ] No console errors
- [ ] No network errors
- [ ] Forms load quickly
- [ ] Validation messages clear
- [ ] At least one form submits successfully
- [ ] Happy path works end-to-end
