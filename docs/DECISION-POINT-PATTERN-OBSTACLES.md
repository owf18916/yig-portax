# Decision Point Pattern - Common Obstacles & Solutions

**Document Type:** Common Issues Guide for All Decision Point Stages  
**Version:** 1.0  
**Last Updated:** January 19, 2026  
**Applies To:** Stage 4 (SKP), Stage 7 (Objection Decision), Stage 10 (Appeal Decision), Stage 12 (Supreme Court Decision)

---

## Overview

All **Decision Point** stages (4, 7, 10, 12) share a common pattern:
1. User enters data
2. User makes a decision (select dropdown or radio button)
3. Based on decision, next stage is determined
4. Workflow path is locked (user cannot access alternative paths)

These stages have similar architecture and therefore similar obstacles. This document centralizes common issues and solutions.

---

## The 4 Critical Obstacles

### 🔴 OBSTACLE #1: Decision Field Not Prefilled on Page Reload

**Symptom:** User selects decision, submits form, refreshes page → decision is NOT checked/selected in UI

**Technical Details:**
- Decision field stored in database (e.g., `user_routing_choice`, `keputusan_banding`, etc.)
- API returns the decision value
- But UI shows as unchecked/unselected

**Root Causes:**

1. **Decision field not in `fields` array**
   - In stage form components, `fields` array contains form field definitions
   - Only fields in this array get initialized in formData during onMounted()
   - Decision-related fields (like `user_routing_choice`) are NOT in fields array
   - Result: formData[field] is undefined, so radio button binding fails

2. **Watch doesn't sync special fields**
   - Watch for prefillData only loops through props.fields
   - Special fields not in fields array are never synced
   - Even if API returns the value, formData never gets updated

**Solution:**

In **StageForm.vue**, update both onMounted() and watch:

```javascript
// Step 1: Initialize special fields in onMounted()
onMounted(() => {
  // ... existing code for regular fields ...
  
  // ⭐ Add explicit initialization for special decision fields
  if (props.prefillData?.user_routing_choice) {
    formData['user_routing_choice'] = props.prefillData.user_routing_choice
  }
})

// Step 2: Also sync in watch
watch(() => [props.fields, props.prefillData], ([newFields, newPrefillData]) => {
  // ... existing code for regular fields ...
  
  // ⭐ Also sync special fields
  if (newPrefillData?.user_routing_choice) {
    formData['user_routing_choice'] = newPrefillData.user_routing_choice
  }
}, { deep: true })
```

**Debug Steps:**
```javascript
// In browser console:
console.log(formData.value.user_routing_choice)  // Should show 'refund' or 'objection', not undefined
console.log(prefillData.value)  // Should show all data from API
```

---

### 🔴 OBSTACLE #2: Stage Accessibility Not Updating (Locked When Should Be Accessible)

**Symptom:** User makes decision at Stage 4/7/10, but decision path doesn't unlock. E.g., chose "Refund" but Stage 13 still shows "LOCKED" or no "Access" button

**Technical Details:**
- Decision saved to database correctly ✅
- User can re-enter the form and decision is shown ✅
- But TaxCaseDetail page doesn't show next stage as accessible ❌

**Root Causes:**

1. **API returns snake_case, frontend expects camelCase**
   - Backend returns: `"skp_record": { "user_routing_choice": "refund" }`
   - Frontend code accesses: `caseData.value.skpRecord.user_routing_choice`
   - Result: undefined (wrong property name)

2. **getUserRoutingChoice() returns null**
   - Function tries to access skpRecord (camelCase)
   - But API provides skp_record (snake_case)
   - Returns null, so accessibility logic treats it as "no decision made"

3. **Stage accessibility locked because userChoice is null**
   - Stages check: `if (userChoice === 'refund') { accessible = true }`
   - Since userChoice is null, this never matches
   - Stage stays locked

**Solution:**

In **TaxCaseDetail.vue**, handle BOTH naming conventions:

```javascript
// ⭐ Support both camelCase and snake_case
const getUserRoutingChoice = () => {
  const skpRecord = caseData.value.skpRecord || caseData.value.skp_record
  if (!skpRecord) return null
  return skpRecord.user_routing_choice || null
}

// ⭐ Also watch BOTH properties
watch(
  () => [caseData.value?.skpRecord?.user_routing_choice, caseData.value?.skp_record?.user_routing_choice],
  ([newRoutingChoice1, newRoutingChoice2], [oldRoutingChoice1, oldRoutingChoice2]) => {
    const newChoice = newRoutingChoice1 || newRoutingChoice2
    const oldChoice = oldRoutingChoice1 || oldRoutingChoice2
    if (newChoice !== oldChoice) {
      console.log('Decision changed:', newChoice)
      updateStageAccessibility()
    }
  }
)
```

**Why This Happens:**
- Laravel returns snake_case by default (tax_cases, skp_records, etc.)
- JavaScript uses camelCase conventions (taxCases, skpRecords, etc.)
- Eloquent models don't auto-convert during JSON serialization
- Solution: support both in frontend, or configure Laravel to convert

**Debug Steps:**
```javascript
// In browser console, after loading case:
console.log(caseData.value)  // Inspect structure
console.log(caseData.value.skpRecord)  // Check if this exists
console.log(caseData.value.skp_record)  // Check if this exists instead
console.log(getUserRoutingChoice())  // Should return 'refund', 'objection', etc.
```

---

### 🔴 OBSTACLE #3: Watcher Not Triggering

**Symptom:** Added watcher for decision field, but it never fires when data loads or changes

**Technical Details:**
- Watcher defined but never executes
- Accessibility logic never updates
- Stage remains locked

**Root Causes:**

1. **Watcher checks wrong property name**
   - Watch path uses camelCase: `() => caseData.value.skpRecord.user_routing_choice`
   - But API provides snake_case: `caseData.value.skp_record.user_routing_choice`
   - Watcher never sees the actual property, so never triggers

2. **Watcher added before data loaded**
   - If watcher added during setup, data might not be loaded yet
   - Watcher path is null/undefined
   - Even when data loads later, watcher references stale closure

3. **Not watching for initial load**
   - Watch only triggers on CHANGE, not on initial value
   - If watcher added after data already loaded, it never sees the value
   - Need to explicitly call handler on mount

**Solution:**

```javascript
// ⭐ Watch BOTH naming conventions
watch(
  () => [caseData.value?.skpRecord?.user_routing_choice, caseData.value?.skp_record?.user_routing_choice],
  ([newRoutingChoice1, newRoutingChoice2]) => {
    const newChoice = newRoutingChoice1 || newRoutingChoice2
    console.log('Decision changed to:', newChoice)
    updateStageAccessibility()
  }
)

// ⭐ Also add explicit update after data loaded in onMounted
onMounted(async () => {
  // ... load data ...
  updateStageAccessibility()  // Trigger explicitly
})
```

**Debug Steps:**
```javascript
// Add console logs to watch:
watch(
  () => [caseData.value?.skpRecord?.user_routing_choice, caseData.value?.skp_record?.user_routing_choice],
  ([c1, c2]) => {
    console.log('Watch triggered! Values:', c1, c2)  // Should see this message
    updateStageAccessibility()
  }
)
```

---

### 🔴 OBSTACLE #4: Accessibility Logic Too Permissive (Wrong Stages Accessible)

**Symptom:** Both paths appear accessible simultaneously, OR no stages appear accessible when they should

**Technical Details:**
- User chose "Refund"
- Stage 5 (Objection) should be LOCKED
- Stage 13 (Refund) should be ACCESSIBLE
- But both appear with "Access" buttons, or neither has button

**Root Causes:**

1. **Missing else conditions**
   - Code only adds `stage.accessible = true` when needed
   - Doesn't set `stage.accessible = false` for locked stages
   - Vue uses previous state, so locked stages stay accessible from before

2. **Fallback logic incomplete**
   - If userChoice is null, all stages default to not accessible ✓
   - If userChoice is 'refund', Stage 13 gets true, but Stage 5 never gets false ✗
   - If userChoice is 'objection', Stage 5 gets true, but all refund stages need false ✗

**Solution:**

In **updateStageAccessibility()**, be EXPLICIT about locking:

```javascript
const updateStageAccessibility = () => {
  workflowStages.value.forEach((stage) => {
    stage.accessible = false  // ⭐ Default to locked
    
    if (stage.branch === 'main') {
      if (stage.id === 5) {  // Objection stage
        const userChoice = getUserRoutingChoice()
        if (isStage4Completed() && userChoice === 'objection') {
          stage.accessible = true  // Unlock only if objection chosen
        } else {
          stage.accessible = false  // ⭐ Explicitly lock for other choices
        }
      }
    } else if (stage.branch === 'refund') {
      const userChoice = getUserRoutingChoice()
      if (userChoice === 'refund' && isStage4Completed()) {
        stage.accessible = true  // Unlock only if refund chosen
      } else {
        stage.accessible = false  // ⭐ Explicitly lock for other choices
      }
    }
  })
}
```

**Key Pattern:**
```javascript
stage.accessible = false  // Start with locked
if (should_be_accessible) {
  stage.accessible = true
}
// Don't rely on previous state - always set explicitly
```

---

## Implementation Checklist for Each Decision Point Stage

When implementing Stage 7, 10, or 12, follow this checklist:

### Backend
- [ ] Ensure API returns complete object (not just ID)
- [ ] Include all decision fields in response
- [ ] Test API response in Postman/ThunderClient
- [ ] Verify next_stage_id is calculated correctly based on decision

### Frontend - Form Component
- [ ] Initialize ALL special decision fields in onMounted() (not just fields in fields array)
- [ ] Update watch to sync special decision fields
- [ ] Add console.log to verify formData is populated

### Frontend - Detail Page
- [ ] Handle BOTH snake_case and camelCase in getter functions
- [ ] Add watcher for BOTH naming conventions
- [ ] Add explicit console.log in watcher to confirm it triggers
- [ ] Use explicit `stage.accessible = false` statements (not just omitting `= true`)
- [ ] Test with DevTools Console showing all logs

### Testing
- [ ] Complete stage with decision
- [ ] Refresh page → decision should be prefilled
- [ ] Check TaxCaseDetail → next stage should be accessible
- [ ] Check console → should see "Decision changed to:" log
- [ ] Verify only ONE path is accessible (locked stages have no button)

---

## Quick Reference: Properties by Stage

| Stage | Model | snake_case | camelCase | Decision Field | Next Stages |
|-------|-------|-----------|----------|----------------|------------|
| 4 | SkpRecord | skp_record | skpRecord | user_routing_choice | 5 or 13 |
| 7 | ObjectionDecision | objection_decision | objectionDecision | keputusan_keberatan | 8 or 13 |
| 10 | AppealDecision | appeal_decision | appealDecision | keputusan_banding | 11 or 13 |
| 12 | SupremeCourtDecision | supreme_court_decision_record | supremeCourtDecisionRecord | keputusan_pk | 13 or 16 |

---

## Prevention Checklist

- [ ] Always access nested properties defensively: `obj.prop1 || obj.snake_prop1`
- [ ] Always initialize ALL fields in formData, not just those in fields array
- [ ] Always watch for BOTH naming conventions
- [ ] Always explicitly set `stage.accessible = false` in else branches
- [ ] Always add console.log for debugging decision flow
- [ ] Always test after page reload (to catch prefill issues)
- [ ] Always test DevTools Console (for logs and errors)

---

## References

- [Stage 4 SKP - Full Obstacles Section](STAGE-4-SKP-IMPLEMENTATION.md#known-obstacles--solutions)
- [Stage 7 Objection Decision - Quick Reference](STAGE-7-OBJECTION-DECISION-IMPLEMENTATION.md#-known-obstacles--solutions-inherited-from-stage-4-pattern)
- [Stage 10 Appeal Decision - Quick Reference](STAGE-10-APPEAL-DECISION-IMPLEMENTATION.md#-known-obstacles--solutions-inherited-from-stage-4-pattern)
- [Stage 12 Supreme Court Decision - Quick Reference](STAGE-12-SUPREME-COURT-DECISION-IMPLEMENTATION.md#-known-obstacles--solutions-inherited-from-stage-4-pattern)

---

**End of Decision Point Pattern - Common Obstacles & Solutions**

*Use this document as a quick reference when implementing new decision point stages*
*Refer to STAGE-4-SKP-IMPLEMENTATION.md for detailed technical solutions*
