# PORTAX FLOW CHANGES - Conceptual Prompt Template
## For GitHub Copilot - Context-Aware Implementation

---

## 📋 PROJECT CONTEXT & STACK

### **Technology Stack (Confirmed)**
- **Backend:** Laravel 12, PHP 8.2, MySQL 8
- **Frontend:** Vue 3.5.26 (Composition API), Vue Router 4.6.4, TailwindCSS 4.0
- **State Management:** Pinia 3.0.4
- **HTTP Client:** Axios 1.13.2
- **Architecture:** Pure REST API (NOT Inertia.js)
- **Authentication:** Session-based (NOT Sanctum/Token)
- **Build Tool:** Vite 7.0.7

### **Project Structure**
```
/yig-portax
├── app/
│   ├── Http/Controllers/Api/    # 24 existing API controllers
│   ├── Models/                  # 24 existing models (fully implemented)
│   ├── Jobs/                    # Queue jobs
│   ├── Mail/                    # Mailable classes
│   └── Policies/                # Authorization policies
├── resources/js/
│   ├── pages/                   # Page components (18+ existing forms)
│   ├── components/              # Reusable components (20+)
│   ├── stores/                  # Pinia stores (taxCaseStore.js)
│   ├── router/                  # Vue Router (168 routes)
│   └── composables/             # Vue 3 composables
├── routes/
│   ├── api.php                  # API routes (712 lines)
│   └── web.php                  # SPA fallback
└── database/migrations/         # Existing migrations
```

### **Existing Patterns & Conventions**
- **Component Naming:** PascalCase (e.g., TaxCaseDetail.vue)
- **API Response Format:** `{ success: boolean, message: string, data: any }`
- **Base Controller:** All API controllers extend `ApiController` with `success()` and `error()` methods
- **Form Validation:** Manual validation (NO VeeValidate)
- **Styling:** Breeze theme with TailwindCSS (consistent with existing components)

---

## 🎯 CRITICAL INSTRUCTION FOR COPILOT

**BEFORE writing ANY code:**
1. ✅ **ANALYZE existing codebase first** - Look at similar files, patterns, naming conventions
2. ✅ **FOLLOW existing patterns** - Match the style, structure, and conventions already in use
3. ✅ **CHECK related files** - Review models, controllers, components that will be affected
4. ✅ **MAINTAIN consistency** - Use same coding style, formatting, and architecture patterns

**DO NOT:**
- ❌ Create code that conflicts with existing patterns
- ❌ Introduce new libraries or dependencies without justification
- ❌ Change established conventions
- ❌ Write code without understanding the existing context

---

## 📝 CHANGE REQUIREMENTS

---

## CHANGE 1: Multiple Refunds per Tax Case

### **Current State Analysis Required**
**Task:** Analyze the following existing files:
- `app/Models/TaxCase.php` - Check current refund relationship
- `app/Models/RefundProcess.php` - Check current fields and methods
- `database/migrations/*_create_refund_processes_table.php` - Check current schema
- `app/Http/Controllers/Api/RefundProcessController.php` - Check current API endpoints
- `resources/js/stores/taxCaseStore.js` - Check how refund data is managed
- `resources/js/pages/TaxCaseDetail.vue` - Check how refund is currently displayed

### **Conceptual Changes Required**

#### **Database Level**
**Goal:** Enable one tax case to have multiple refund processes

**Requirements:**
1. Remove the unique constraint on `tax_case_id` in `refund_processes` table
2. Add tracking field for which stage triggered this refund (SKP/Objection/Appeal/Supreme Court/Preliminary)
3. Add sequence number field to track refund order per tax case (1, 2, 3, etc.)
4. Add optional field to link back to the decision record that triggered this refund
5. Maintain all existing fields and relationships

**Migration Guidelines:**
- Create a new migration (don't modify existing ones)
- Include proper rollback logic
- Add appropriate indexes for query performance

#### **Backend Model Level**
**Goal:** Update models to support one-to-many relationship

**TaxCase Model Requirements:**
1. Change refund relationship from `hasOne` to `hasMany` (plural naming)
2. Add helper method to get the latest refund
3. Add helper method to check if can create another refund
4. Add helper method to calculate total refunded amount
5. Add helper method to get available amount for refund (disputed - already refunded)

**RefundProcess Model Requirements:**
1. Add constants for different stage sources
2. Add scopes to filter by stage source
3. Add scope to get latest refund for a tax case
4. Add static method to generate next sequence number for a tax case
5. Add method to get human-readable stage source label
6. Update fillable array with new fields

**Decision Models Requirements (SKP, Objection, Appeal, SupremeCourt):**
1. Each decision model should have a method to create refund when decision is made
2. The method should accept parameters: create_refund (boolean), refund_amount, continue_to_next_stage (boolean)
3. These two actions (refund and continue) must be independent of each other
4. When creating refund, automatically set proper stage_source and sequence_number

#### **Backend Controller Level**
**Goal:** Update API to handle multiple refunds

**RefundProcessController Requirements:**
1. Index endpoint should support filtering by tax_case_id and stage_source
2. Store endpoint should auto-generate sequence_number (don't accept from client)
3. Store endpoint should validate refund_amount doesn't exceed available amount
4. Include proper error handling and validation messages

**Decision Controllers Requirements (SKP, Objection, Appeal, SupremeCourt):**
1. Update store/update endpoints to accept two new boolean fields: `create_refund`, `continue_to_next_stage`
2. If `create_refund` is true, also accept `refund_amount` field
3. Process these actions independently (can do both, either, or neither)
4. Validate refund_amount if create_refund is true
5. Update tax_case.current_stage based on continue_to_next_stage

#### **Frontend Store Level (Pinia)**
**Goal:** Update state management for multiple refunds

**taxCaseStore.js Requirements:**
1. Change refund state from singular to plural (array)
2. Add action to fetch all refunds for a tax case
3. Add action to create new refund
4. Add computed property for total refunded amount
5. Update existing actions that reference refund to handle array

#### **Frontend Component Level**
**Goal:** Display and manage multiple refunds

**New Component Needed:**
- Create a `RefundList.vue` component that:
  - Displays all refunds for a tax case in a list/table
  - Shows refund sequence number, stage source, amount, status, date
  - Has proper styling consistent with existing components
  - Allows viewing details of each refund
  - Shows total refunded amount summary

**Update Existing Components:**
- `TaxCaseDetail.vue`: Integrate RefundList component, replace single refund display
- All decision form pages: Will need new UI for independent actions (see Change 3)

---

## CHANGE 2: SPT Type & Pengembalian Pendahuluan Flow

### **Current State Analysis Required**
**Task:** Analyze the following existing files:
- `app/Models/TaxCase.php` - Check existing fields and SPT filing logic
- `database/migrations/*_create_tax_cases_table.php` - Check current schema
- `resources/js/pages/SptFilingForm.vue` - Check current form structure
- `resources/js/pages/SkpFilingForm.vue` - Check SKP form and flow
- `app/Http/Controllers/Api/TaxCaseController.php` - Check tax case creation logic

### **Conceptual Changes Required**

#### **Database Level**
**Goal:** Add SPT type field and create preliminary refund request table

**tax_cases Table:**
1. Add `spt_type` field (nullable string, max 50 chars)
2. Possible values: "Pengembalian Pendahuluan", "Restitusi", "Kompensasi"
3. Position: after spt_number field

**New Table: preliminary_refund_requests**
1. One-to-one relationship with tax_cases (unique constraint on tax_case_id)
2. Fields needed:
   - request_number (string, nullable)
   - submission_date (date, nullable)
   - requested_amount (decimal 20,2, required)
   - approval_status (enum: PENDING, APPROVED, REJECTED, default PENDING)
   - approved_amount (decimal 20,2, default 0)
   - approved_date (date, nullable)
   - notes (text, nullable)
   - next_action (text, nullable)
   - next_action_due_date (date, nullable)
   - status_comment (text, nullable)
3. Include soft deletes
4. Cascade delete when tax_case is deleted
5. Add indexes for tax_case_id and approval_status

#### **Backend Model Level**
**Goal:** Support Pengembalian Pendahuluan workflow

**New Model: PreliminaryRefundRequest**
1. One-to-one relationship with TaxCase
2. Polymorphic relationship with Documents (for supporting docs)
3. Status constants (PENDING, APPROVED, REJECTED)
4. Methods needed:
   - isPending(), isApproved(), isRejected() - check status
   - approve($amount) - approve request and create refund process
   - reject($reason) - reject request (should trigger KIAN email)
   - createRefundIfApproved() - create RefundProcess with stage_source='PRELIMINARY'

**TaxCase Model Updates:**
1. Add `spt_type` to fillable array
2. Add hasOne relationship to PreliminaryRefundRequest
3. Add method: isPengembalianPendahuluan() - check if spt_type matches
4. Add method: shouldSkipAuditStages() - return true if Pengembalian Pendahuluan
5. Add method: getAvailableStages() - return array of stage numbers based on spt_type

#### **Backend Controller Level**
**Goal:** CRUD operations for preliminary refund requests

**New Controller: PreliminaryRefundRequestController**
1. Extend ApiController (follow existing pattern)
2. Standard CRUD operations (index, store, show, update, destroy)
3. Additional endpoints:
   - approve(request, id) - approve request with approved_amount
   - reject(request, id) - reject request with optional reason
4. Validation rules:
   - tax_case_id must be unique (one request per case)
   - requested_amount must be positive
   - cannot update/delete if status is not PENDING
5. When rejecting, dispatch KIAN reminder email job (see Change 4)

**API Routes:**
- Add resource routes for preliminary-refund-requests
- Add custom routes for approve and reject actions
- Group under authenticated middleware

#### **Frontend Component Level**
**Goal:** Support SPT type selection and preliminary refund request form

**SptFilingForm.vue Updates:**
1. Add dropdown field for `spt_type` with three options
2. Position: after existing SPT number field
3. Show conditional info alert when "Pengembalian Pendahuluan" is selected
4. Alert message: Explain that this type will skip SP2 and SPHP stages
5. Follow existing form styling and validation patterns

**New Component: PreliminaryRefundRequestForm.vue**
1. Create as new page component (not modal)
2. Display tax case info at top (case number, dispute amount, SKP amount, remaining)
3. Form fields:
   - Request number (optional)
   - Submission date (required, default today)
   - Requested amount (required, max = remaining amount)
   - Notes (optional, textarea)
   - Document upload (multiple files)
4. Actions: Cancel and Submit buttons
5. Follow styling pattern from other form pages

**SkpFilingForm.vue Updates:**
1. After SKP submission, check if:
   - spt_type is "Pengembalian Pendahuluan"
   - AND skp_amount < disputed_amount
2. If both true, show option/prompt to create preliminary refund request
3. Option could be a button or alert with action button
4. Button should route to PreliminaryRefundRequestForm with tax_case_id

#### **Business Logic Flow**
**Pengembalian Pendahuluan Workflow:**
```
1. User selects "Pengembalian Pendahuluan" in SPT form
2. Tax case goes directly to SKP stage (skip SP2, SPHP)
3. At SKP stage:
   a. If SKP amount = Dispute amount:
      - Create refund (full amount, stage_source: SKP)
      - Case can be re-opened for audit later
   b. If SKP amount < Dispute amount:
      - Create refund #1 (partial, stage_source: SKP)
      - Show option to create Preliminary Refund Request
      - User submits request for remaining amount
      - Tax office approves/rejects via backend
      - If approved: Create refund #2 (stage_source: PRELIMINARY)
      - If rejected: Trigger KIAN email reminder
```

---

## CHANGE 3: Independent Refund & Continue Actions

### **Current State Analysis Required**
**Task:** Analyze the following existing files:
- `resources/js/pages/SkpFilingForm.vue` - Check current decision form structure
- `resources/js/pages/ObjectionDecisionForm.vue` - Check pattern
- `resources/js/pages/AppealDecisionForm.vue` - Check pattern
- `resources/js/pages/SupremeCourtDecisionForm.vue` - Check pattern
- Check how decisions currently lead to next stage or refund

### **Conceptual Changes Required**

#### **Current Problem**
Currently, at decision stages, there's likely a binary choice:
- EITHER: Create refund (case ends)
- OR: Continue to next stage (no refund)

#### **New Requirement**
Decision and refund actions must be INDEPENDENT:
- User can choose to create refund AND continue to next stage
- User can choose to create refund and NOT continue (case ends)
- User can choose NOT to create refund but continue to next stage
- User can choose neither (case ends, may trigger KIAN)

#### **Frontend Component Level**

**New Reusable Component: DecisionActions.vue**
1. Purpose: Standardized UI for decision action choices
2. Props needed:
   - availableAmount (number) - max refund amount available
   - nextStageName (string) - name of next stage for display
3. Two independent checkboxes:
   - "Create Refund Process" - with conditional amount input field
   - "Continue to [NextStageName]" - checkbox for next stage
4. Show warning if neither checkbox is selected (will close case)
5. Emit event with actions object when changed
6. Follow Breeze styling (consistent with existing forms)

**Update All Decision Form Components:**
1. Integrate DecisionActions component into form
2. Position: after main decision fields, before submit button
3. Visual separation: border-top, padding, clear section title
4. Handle emitted actions and include in form submission
5. Forms to update:
   - SkpFilingForm.vue
   - ObjectionDecisionForm.vue
   - AppealDecisionForm.vue
   - SupremeCourtDecisionForm.vue

#### **Backend Processing**
**Each Decision Model Must:**
1. Accept three parameters in processing:
   - create_refund (boolean)
   - refund_amount (decimal, nullable)
   - continue_to_next_stage (boolean)
2. Process refund creation independently:
   - If create_refund is true, create RefundProcess record
   - Set proper stage_source based on decision type
   - Auto-generate sequence_number
3. Process stage continuation independently:
   - If continue_to_next_stage is true, update tax_case.current_stage
   - If false, mark tax_case.is_completed = true
4. Check KIAN eligibility:
   - If continue_to_next_stage is false
   - AND decision_amount < disputed_amount
   - OR decision_type is REJECTED/PARTIALLY_GRANTED
   - Then trigger KIAN reminder (see Change 4)

#### **Validation Logic**
1. If create_refund is true, refund_amount must be provided and > 0
2. refund_amount cannot exceed available refund amount
3. Both checkboxes can be unchecked (valid scenario, case closes)
4. Backend should validate these rules in request validation

---

## CHANGE 4: KIAN Button & Email Reminders

### **Current State Analysis Required**
**Task:** Analyze the following existing files:
- `app/Models/TaxCase.php` - Check existing methods and relationships
- `app/Jobs/` - Check existing job patterns
- `app/Mail/` - Check existing mailable patterns
- `resources/js/pages/TaxCaseDetail.vue` - Check current layout and actions
- `resources/views/emails/` - Check existing email templates (if any)
- `app/Models/AuditLog.php` - Check logging pattern

### **Conceptual Changes Required**

#### **KIAN Eligibility Conditions**
A tax case is eligible for KIAN (Internal Loss Recognition) when:

**At SKP Stage:**
- SKP amount < Dispute amount
- AND user chose NOT to continue to objection

**At Preliminary Refund Request:**
- Request status = REJECTED

**At Objection Decision Stage:**
- Decision amount < Dispute amount
- AND user chose NOT to continue to appeal

**At Appeal Decision Stage:**
- Decision amount < Dispute amount
- AND user chose NOT to continue to supreme court

**At Supreme Court Decision Stage:**
- Decision type = PARTIALLY_GRANTED or REJECTED
- (No next stage available, this is final)

#### **Backend Model Level**

**TaxCase Model Methods Needed:**
1. `canCreateKian(): bool`
   - Check if KIAN submission doesn't already exist
   - Check if any eligibility condition is met
   - Return true if eligible, false otherwise

2. `needsKianReminder(): bool`
   - Similar to canCreateKian but focuses on reminder logic
   - Check all stages and their conditions
   - Return true if KIAN reminder should be sent

3. `getKianEligibilityReason(): string`
   - Return human-readable explanation why KIAN is needed
   - Different message per stage/condition
   - Used in email and UI display

**Implementation Strategy:**
- Check each stage sequentially
- Return first matching condition
- Include specific details (amounts, decision types)

#### **Backend Job Level**

**New Job: SendKianReminderJob**
1. Should be queued (implements ShouldQueue)
2. Constructor accepts: TaxCase, stage name, reason
3. In handle() method:
   - Get all users from tax case entity (affiliate users)
   - Get all users from holding entity (for CC)
   - Send email to entity users with CC to holding users
   - Log action to audit_logs table
4. Use existing queue infrastructure
5. Follow existing job patterns in project

#### **Backend Mail Level**

**New Mailable: KianReminderMail**
1. Constructor accepts: TaxCase, stage, reason
2. Define email subject with case number
3. Pass data to view: taxCase, entity, stageName, reason, lossAmount
4. Helper method to format stage name for display
5. Helper method to calculate loss amount (dispute - refunded)

**Email Template: resources/views/emails/kian-reminder.blade.php**
1. Professional HTML email layout
2. Sections needed:
   - Header with alert color (red theme)
   - Case details in formatted box (number, type, year, stage, amounts)
   - Reason for KIAN in highlighted box
   - Action button linking to tax case detail page
   - Footer with system name and disclaimer
3. Responsive design (looks good on mobile and desktop)
4. Follow any existing email template style in project

#### **Email Triggering Logic**
**When to Send Email:**
- Immediately after a decision is saved that meets KIAN conditions
- Only send once per condition (check audit_logs to avoid duplicates)
- Send after PreliminaryRefundRequest is rejected

**How to Trigger:**
- In decision controller's store/update method
- After saving decision, check if KIAN reminder needed
- Dispatch SendKianReminderJob with proper parameters

**Logging:**
- Log to audit_logs table with action: 'KIAN_REMINDER_SENT'
- Store recipients list in new_values JSON field
- Include stage and reason in log

#### **Frontend Component Level**

**TaxCaseDetail.vue Updates:**
1. Add "Create KIAN" button in action button group
2. Position: after "Access" button, before other actions
3. Button styling: Use warning/danger color (red/orange) to indicate required action
4. Button visibility: Only show if taxCase.can_create_kian is true
5. On click: Open KIAN modal (don't navigate to new page)

**Display KIAN Eligibility Notice:**
1. If can_create_kian is true, show alert box above case details
2. Alert style: Warning/danger theme (red background, red border)
3. Alert content: Display taxCase.kian_eligibility_reason
4. Alert dismissible: No (it's important, should stay visible)

**New Modal Component: KianModal.vue**
1. Modal structure (overlay + centered card)
2. Display reason prominently at top
3. Form fields:
   - KIAN number (string, required)
   - Submission date (date, required, default today)
   - Loss amount (decimal, required)
   - Notes/explanation (textarea, optional)
   - Document upload (multiple files, optional)
4. Actions: Cancel (close modal) and Submit (create KIAN)
5. On successful submission:
   - Close modal
   - Show success message
   - Refresh tax case details
6. Follow existing modal patterns in project

#### **Backend API Updates**

**TaxCaseController Updates:**
1. In show() method, add to response:
   - can_create_kian (boolean)
   - kian_eligibility_reason (string, nullable)
2. Load necessary relationships for KIAN check
3. Call model methods to compute these values

**KianSubmissionController:**
- Likely already exists, verify it handles creation properly
- Ensure it accepts all fields from modal form
- Add validation for required fields

---

## 🔧 IMPLEMENTATION STRATEGY

### **Recommended Order**
1. **Database Changes First**
   - Run and test all migrations
   - Verify schema changes are correct
   - Test rollback functionality

2. **Backend Models & Logic**
   - Update/create models
   - Add helper methods
   - Test model methods in tinker

3. **Backend API Controllers**
   - Update/create controllers
   - Add validation rules
   - Test endpoints with Postman/Insomnia

4. **Frontend Store (Pinia)**
   - Update state management
   - Test actions with API calls

5. **Frontend Components**
   - Create/update components
   - Integrate with existing pages
   - Test user interactions

6. **Email & Queue System**
   - Create job and mailable
   - Test email sending (use Mailtrap)
   - Verify queue processing

7. **Integration Testing**
   - Test complete workflows end-to-end
   - Test all decision paths
   - Verify data integrity

### **Key Testing Scenarios**
1. Create tax case with each SPT type
2. Create multiple refunds at different stages
3. Test independent refund + continue combinations
4. Test KIAN eligibility and reminder sending
5. Test Pengembalian Pendahuluan complete flow
6. Test preliminary refund approval and rejection

---

## 📋 QUALITY GUIDELINES

### **Code Quality**
1. ✅ Follow PSR-12 for PHP code
2. ✅ Use TypeScript types if project uses TypeScript
3. ✅ Write descriptive variable and method names
4. ✅ Add comments for complex logic only
5. ✅ Keep methods focused (single responsibility)

### **Consistency**
1. ✅ Match existing file organization
2. ✅ Use same import styles (relative vs absolute)
3. ✅ Follow existing naming conventions exactly
4. ✅ Match existing spacing and formatting
5. ✅ Use same error handling patterns

### **Performance**
1. ✅ Add database indexes for foreign keys and frequently queried fields
2. ✅ Use eager loading to avoid N+1 queries
3. ✅ Use queue for email sending (don't send synchronously)
4. ✅ Cache computed values when appropriate

### **Security**
1. ✅ Validate all user inputs on backend
2. ✅ Use Laravel's CSRF protection
3. ✅ Check authorization in controllers (policies)
4. ✅ Sanitize data before database insertion
5. ✅ Escape output in views

### **Documentation**
1. ✅ Add PHPDoc for public methods
2. ✅ Document complex business logic
3. ✅ Update README if adding new features
4. ✅ Document API endpoints if changed

---

## 🚫 WHAT NOT TO DO

1. ❌ Don't modify existing migration files (create new ones)
2. ❌ Don't remove or rename existing database fields
3. ❌ Don't change existing API response formats
4. ❌ Don't introduce breaking changes to frontend props/events
5. ❌ Don't use different styling frameworks (stick to TailwindCSS)
6. ❌ Don't add new dependencies without good reason
7. ❌ Don't bypass existing authentication/authorization
8. ❌ Don't hardcode values (use constants or config)
9. ❌ Don't write overly complex code (KISS principle)
10. ❌ Don't skip error handling

---

## 💬 HOW TO USE THIS TEMPLATE WITH COPILOT

### **Step 1: Analyze First**
```
Analyze the following files to understand current implementation:
- [list specific files]

Explain the current pattern used for [specific functionality].
```

### **Step 2: Request Implementation**
```
Based on the current codebase patterns, implement [CHANGE X: Title] 
as described in the conceptual template.

Follow these specific requirements:
- [list key requirements from template]

Maintain consistency with existing code in:
- [list specific files that should be referenced]
```

### **Step 3: Review & Iterate**
```
Review the code you generated for [component/controller/model name].
Does it follow the same patterns as [existing similar file]?
What could be improved for consistency?
```

---

## 📝 NOTES

### **Migration Best Practices**
- Always create new migrations (don't edit existing)
- Test migration and rollback before committing
- Use meaningful migration names with date prefix
- Add comments for complex schema changes

### **API Design**
- Keep response structure consistent
- Version breaking changes (if needed: /api/v2/)
- Document new endpoints
- Return appropriate HTTP status codes

### **Vue Components**
- Keep components small and focused
- Use composition API (script setup)
- Extract reusable logic to composables
- Props down, events up pattern

### **State Management**
- Keep store focused on data management
- Don't put presentation logic in store
- Use computed for derived state
- Actions should be async, mutations synchronous (if using old pattern)

---

## ✅ COMPLETION CRITERIA

A change is considered complete when:
1. ✅ Code follows existing patterns and conventions
2. ✅ All validations are in place (frontend and backend)
3. ✅ Error handling covers edge cases
4. ✅ UI is consistent with existing design
5. ✅ API endpoints tested and working
6. ✅ Database changes properly migrated
7. ✅ No console errors or warnings
8. ✅ Existing functionality still works (no regressions)
9. ✅ Code is readable and maintainable
10. ✅ Performance is acceptable

---

**END OF TEMPLATE**