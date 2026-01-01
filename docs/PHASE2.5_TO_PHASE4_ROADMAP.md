# PorTax - Phase 2.5 to Phase 4 Roadmap

**Start Date**: January 1, 2026, 15:30 WIB  
**End Date**: January 11, 2026  
**Approach**: Hybrid (Frontend forms + Parallel database)  
**Total Hours**: ~12-13 hours over 11 days

---

## TODAY: Phase 2.5 Implementation (Hybrid Part 1)

### Objective
Create generic form builder + 3 critical stage forms to test basic workflow

### Tasks (2-3 hours)

1. **Generic Stage Form Builder Component** (45 min)
   - Location: `resources/js/components/StageForm.vue`
   - Dynamic field rendering
   - Validation framework
   - Document upload section
   - Success/error alerts
   - Flexible data model

2. **SPT Filing Form** (30 min)
   - Route: `/tax-cases/:id/workflow/1`
   - Fields: Entity dropdown, Period, Currency, Amount, Filing decision
   - Integration with case creation
   - Document upload

3. **SKP Record Form** (30 min)
   - Route: `/tax-cases/:id/workflow/4`
   - Fields: SKP Number, Dates, SKP Type (decision point)
   - Decision routing:
     - SKP LB → Refund flow
     - NIHIL/SKP KB → Objection flow
   - Audit corrections breakdown

4. **Objection Decision Form** (30 min)
   - Route: `/tax-cases/:id/workflow/7`
   - Fields: Decision number, Date, Decision type (decision point)
   - Decision routing:
     - Dikabulkan → Refund
     - Ditolak → Appeal
   - Amount tracking

5. **Decision Logic Router** (20 min)
   - Handle SKP type → next stage mapping
   - Handle Objection decision → next stage mapping
   - Update case status based on decision
   - Mock API calls

### Expected Output
- ✅ Generic form builder tested & working
- ✅ 3 forms visible in UI
- ✅ Basic form submission works
- ✅ Decision routing logic implemented
- ✅ At least one happy path testable end-to-end

### Files to Create
```
resources/js/components/StageForm.vue
resources/js/pages/SptFilingForm.vue
resources/js/pages/SkpRecordForm.vue
resources/js/pages/ObjectionDecisionForm.vue
resources/js/utils/decisionRouter.js
```

### Files to Modify
```
resources/js/router/index.js (add 3 routes)
resources/js/pages/TaxCaseDetail.vue (update stage linking)
```

---

## PARALLEL: Phase 3 (Jan 2-4)

### Objective
Database schema + Eloquent models ready for Phase 4 integration

### Tasks (3-4 hours, 30 min/day)

1. **Database Migrations** (90 min)
   - tax_cases (enhance with more fields)
   - workflow_records (generic for all stages)
   - decisions (for decision points)
   - case_history (audit trail)
   - documents (document tracking)
   - Run migrations & verify schema

2. **Eloquent Models** (60 min)
   - TaxCase model with relationships
   - WorkflowRecord model
   - Decision model
   - Document model
   - User model updates (company association)
   - Model factories for testing

3. **API Controllers** (60 min)
   - TaxCaseController (replace mock endpoint)
   - WorkflowController (handle stage forms)
   - DecisionController (handle decisions)
   - DocumentController (file uploads)
   - Update routes to use real controllers

4. **User-Company Integration** (30 min)
   - Create user_companies junction table
   - Update PortaxUser model
   - Authentication middleware
   - Test user can only see their cases

### Expected Output
- ✅ 5 migrations created & run
- ✅ 5 models with relationships
- ✅ Mock data seeders created
- ✅ Can create test case via artisan
- ✅ Ready for Phase 4 wiring

### Files to Create
```
database/migrations/*_create_workflow_records.php
database/migrations/*_create_decisions.php
database/migrations/*_create_user_companies.php
app/Models/WorkflowRecord.php
app/Models/Decision.php
app/Http/Controllers/WorkflowController.php
database/factories/TaxCaseFactory.php
database/seeders/TaxCaseSeeder.php
```

---

## Phase 4: Integration (Jan 5-8)

### Objective
Wire Vue.js frontend to real database + Complete remaining forms

### Tasks (4 hours)

1. **Frontend API Integration** (90 min)
   - Update axios/fetch calls to use real endpoints
   - Remove mock data
   - Add authentication headers (JWT/Bearer)
   - Error handling with real API errors

2. **Remaining 8 Stage Forms** (120 min)
   - SP2 Record, SPHP Record
   - Objection Letter, SPUH
   - Appeal, Appeal Explanation, Appeal Decision
   - Supreme Court Review
   - Refund Processing

3. **Complete Decision Logic** (30 min)
   - All routing paths working
   - Case status updates
   - Workflow progress tracking
   - Document associations

### Expected Output
- ✅ Frontend talking to real database
- ✅ Can create case → fill stages → see progress
- ✅ All 12 stages have forms
- ✅ Decision logic complete
- ✅ End-to-end workflow testable

---

## Phase 5: Testing & Polish (Jan 9-11)

### Objective
Production-ready application

### Tasks (2-3 hours)

1. **Testing** (90 min)
   - Walk through complete CIT workflow
   - Walk through complete VAT workflow
   - Test all decision points
   - Test document uploads
   - Test error cases

2. **Polish** (60 min)
   - UI consistency
   - Error messages clarity
   - Loading states
   - Success notifications
   - Responsive design

3. **Documentation** (30 min)
   - User guide
   - API documentation
   - Deployment guide
   - Known issues/limitations

### Expected Output
- ✅ Zero bugs in happy path
- ✅ All workflows testable
- ✅ Ready for demo
- ✅ Production deployment ready

---

## SUCCESS CRITERIA

### Minimum (Must Have by Jan 11)
- [x] Dashboard loads
- [x] Can create CIT/VAT case
- [x] Can fill at least 3 workflow stages
- [x] Case progresses through stages
- [x] Documents can be uploaded
- [x] No critical errors

### Target (Should Have)
- [x] All 12 stages have forms
- [x] Decision logic working
- [x] Real database integration
- [x] User-company segregation
- [x] Approval workflows
- [x] Complete documentation

### Nice to Have
- [ ] Advanced filtering
- [ ] Bulk operations
- [ ] Export functionality
- [ ] Analytics dashboard

---

## Risk Mitigation

| Risk | Impact | Mitigation |
|------|--------|-----------|
| Hybrid approach too ambitious | High | Start with forms today, Phase 3 can slip to Jan 5 |
| Database schema changes needed mid-Phase4 | Medium | Design migrations thoroughly on Jan 2 |
| Decision logic complex | Medium | Test each decision path separately |
| Time running out | Medium | Prioritize happy paths over edge cases |
| Deployment issues | Low | Use existing Laravel deployment knowledge |

---

## Daily Standup Template

Use this to track progress:

```
📅 Date: [Jan X]
⏱️ Hours Today: [X]
✅ Completed:
   - [Task 1]
   - [Task 2]
⏳ In Progress:
   - [Task]
🚧 Blockers:
   - [Blocker or "None"]
📊 Overall % Complete: [X%]
```

---

## Important Notes

1. **Commit Frequently** - Every form/migration should be committed
2. **Test as You Go** - Don't wait until Phase 4 to test
3. **Document Changes** - Update docs when requirements change
4. **Parallel Work** - Phase 2.5 and 3 can overlap
5. **Flexibility** - Adjust scope if falling behind, remove nice-to-haves first

---

## What's NOT Changing

✅ Vue.js 3 architecture - solid, keep it  
✅ Existing components - all working well  
✅ API endpoint design - good structure  
✅ Tailwind CSS styling - consistent  

---

## Questions? Check Here First

**Q: What if Phase 2.5 takes 4 hours?**  
A: Push Phase 3 start to Jan 2 afternoon, still finish on time

**Q: What if database schema needs changes in Phase 4?**  
A: That's why we test Phase 3 thoroughly - migrate early, migrate often

**Q: Do I need to test every workflow today?**  
A: No, just SPT → SKP → Decision happy path

**Q: What about authentication?**  
A: Mock for now, integrate in Phase 3 when we have real database

**Q: Can I skip KIAN procedure?**  
A: Yes, remove from Phase 4 if needed (lower priority)

