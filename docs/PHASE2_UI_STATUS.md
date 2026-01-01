# PHASE 2 - UI/UX Status Review

**Date:** January 1, 2026  
**Status:** 40% COMPLETE - Form pages need expansion  

---

## ✅ Currently Complete

### Pages
1. **Dashboard.vue** ✅
   - Welcome screen with quick stats
   - Navigation to main features

2. **TaxCaseList.vue** ✅
   - Display all tax cases
   - Filter by case type (CIT/VAT)
   - "+ New CIT Case" button
   - "+ New VAT Case" button
   - Case list with badge styling

3. **TaxCaseDetail.vue** ✅
   - Case overview with basic info
   - Workflow progress tracker (8 stages)
   - Document upload section
   - Status display

4. **CreateCITCase.vue** ✅
   - CIT case creation form
   - Company (read-only from user)
   - Fiscal Year input
   - Period auto-set to March
   - Disputed Amount input
   - Auto-generated case number preview

5. **CreateVATCase.vue** ✅
   - VAT case creation form
   - Company (read-only from user)
   - Year-Month period (YYYY-MM)
   - PPN Masukan + PPN Keluaran inputs
   - Disputed amount calculation
   - Direction display (Overpayment/Underpayment)
   - Auto-generated case number preview

6. **WorkflowForm.vue** ✅
   - Dynamic workflow stage form
   - Form validation
   - Conditional field rendering

### Components (Reusable)
1. **Button.vue** ✅ - All variants
2. **Card.vue** ✅ - Title, subtitle, spacing
3. **FormField.vue** ✅ - Text, number, textarea, month inputs
4. **Alert.vue** ✅ - Success, error, warning, info
5. **LoadingSpinner.vue** ✅ - Loading states

---

## ❌ Still Missing - Workflow Stage Forms

Based on PORTAX_FLOW.md, there are **12 workflow stages** but only basic form structure exists:

### Stage 1: SPT Filing ❌
**Needs:** Dedicated form for initial submission
- Entity dropdown
- Period selection
- Currency dropdown
- Nilai Sengketa input
- Filing decision (Yes/No)

### Stage 2: SP2 Record ❌
**Needs:** SP2 document recording form
- Nomor SP2 input
- Tanggal Diterbitkan (date picker)
- Tanggal Diterima (date picker)
- Auditor details (name, phone, email)
- Document upload

### Stage 3: SPHP Record ❌
**Needs:** SPHP findings form
- Nomor SPHP input
- Issue/Receipt dates
- Audit findings breakdown:
  - Royalty amount
  - Service amount
  - Other amount
- Document upload

### Stage 4: SKP Record ❌
**Needs:** SKP assessment form
- Nomor SKP input
- Issue/Receipt dates
- **Decision Point 1: SKP Type selection**
  - SKP LB (Lebih Bayar)
  - NIHIL
  - SKP KB (Kurang Bayar)
- Nilai SKP input
- Audit corrections breakdown
- Document upload

### Stage 5: Objection Submission ❌
**Needs:** Objection filing form
- Nomor Surat Keberatan input
- Tanggal Dilaporkan (date picker)
- Nilai Keberatan input
- Document upload

### Stage 6: SPUH Record ❌
**Needs:** Summon letter handling
- Nomor SPUH input
- Issue/Receipt dates
- Later: Reply letter info
- Document upload

### Stage 7: Objection Decision ❌
**Needs:** Objection decision form
- Nomor Surat Keputusan input
- Tanggal Keputusan (date picker)
- **Decision Point 2: Decision type**
  - Dikabulkan
  - Dikabulkan Sebagian
  - Ditolak
- Nilai input
- Document upload

### Stage 8: Appeal Submission ❌
**Needs:** Appeal filing form
- Nomor Surat Banding input
- Tanggal Dilaporkan (date picker)
- Nilai input
- Document upload

### Stage 9: Appeal Explanation ❌
**Needs:** Explanation request handling
- Nomor Surat Permintaan input
- Issue/Receipt dates
- Later: Explanation submission
- Document upload

### Stage 10: Appeal Decision ❌
**Needs:** Appeal court decision form
- Nomor Surat Keputusan input
- Tanggal Keputusan (date picker)
- **Decision Point 3: Decision type**
  - Dikabulkan
  - Dikabulkan Sebagian
  - Ditolak
- Nilai input
- Document upload

### Stage 11: Supreme Court Review ❌
**Needs:** Supreme Court review form
- Nomor Surat Permohonan input
- Submission date
- Reasons for review
- Document upload

### Stage 12: Refund Processing ❌
**Needs:** Refund procedure form
- Refund method selection:
  - Bank transfer
  - Check/Giro
  - Offset against future taxes
- Bank details (if transfer)
- Amount
- Status tracking

---

## 📊 Recommendations

### Option A: Complete All Forms Before Phase 3 (Recommended)
**Time:** 3-4 hours
**Benefit:** Full frontend flow testable end-to-end
**Steps:**
1. Create generic `WorkflowStageForm.vue` with dynamic field builder
2. Create 12 specific stage forms
3. Add decision logic (routing between stages)
4. Test entire flow
5. Then move to Phase 3 (database)

### Option B: Go to Phase 3 Now, Add Forms Later
**Time:** Faster to backend integration
**Risk:** Frontend flow incomplete, may discover issues during backend wiring
**Steps:**
1. Create database + models now
2. Wire Phase 1-2 API endpoints to real data
3. Then implement remaining stage forms

### Option C: Hybrid Approach (Best Balance)
**Time:** 2-3 hours + Phase 3
**Steps:**
1. Create generic stage form component today
2. Start Phase 3 (database + models)
3. Implement specific forms during Phase 4 (integration)

---

## 🎯 Current Blocking Points

1. **Stage forms** - WorkflowForm.vue is generic, not specific to each stage
2. **Decision points** - No conditional routing logic (SKP type → Refund vs Objection)
3. **KIAN flow** - Not yet implemented
4. **Approval workflow** - Mentioned in docs but not in UI

---

## 🎯 CHOSEN APPROACH: Option C (Hybrid)

**Decision Made:** January 1, 2026 - 15:00 WIB

### Immediate Plan (Today)

**In Progress:**
- [x] Generic stage form builder component
- [ ] 3 critical stage forms:
  - SPT Filing Form
  - SKP Record Form (with decision logic)
  - Objection Decision Form (with routing)
- [ ] Decision point routing logic
- [ ] Basic happy path testing

**Timeline:** 2-3 hours today

**Blockers:** NONE - can start immediately

### Then Start Phase 3 (Parallel Work)

**In Parallel:**
- Database migrations + models
- API controllers replacing mock data
- User-company relationships

**Phase 3 Work:** 3-4 hours

### Complete During Phase 4 (Integration)

**Later:**
- Remaining 9 stage forms
- Complex decision flows
- KIAN procedure forms
- Advanced approval logic

---

## Expected Outcomes

### After Today (Hybrid Part 1)
✅ Generic form builder complete  
✅ SPT, SKP, Objection forms done  
✅ Decision routing logic works  
✅ Basic workflow testable  
✅ Frontend 60% complete  

### After Phase 3
✅ Database complete  
✅ API models in place  
✅ Ready for integration  

### After Phase 4
✅ All 12 stage forms complete  
✅ Full workflow end-to-end  
✅ Production ready  

---

## Quality Checklist

- [ ] Generic form builder passes validation
- [ ] SPT form saves to mock API
- [ ] SKP form with decision routing works
- [ ] Objection form submits correctly
- [ ] All forms show proper error states
- [ ] Document upload field present in each
- [ ] Success/error alerts display
- [ ] Navigation between stages works
