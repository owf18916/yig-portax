# PorTax Workflow Branching Concept

**Document Type:** Technical Architecture & Workflow Logic  
**Date:** January 18, 2026  
**Version:** 1.0  
**Status:** Complete

---

## 📋 Table of Contents

1. [Overview](#overview)
2. [Core Concept](#core-concept)
3. [Main Flow (Sequential Stages 1-12)](#main-flow-sequential-stages-1-12)
4. [Branch Flow Architecture](#branch-flow-architecture)
5. [Refund Branch (Stages 13-15)](#refund-branch-stages-13-15)
6. [KIAN Branch (Stage 16)](#kian-branch-stage-16)
7. [Branching Logic & Decision Points](#branching-logic--decision-points)
8. [Stage Accessibility Rules](#stage-accessibility-rules)
9. [UI Implementation](#ui-implementation)
10. [Use Case Scenarios](#use-case-scenarios)
11. [Data Structure](#data-structure)

---

## 🎯 Overview

The PorTax workflow is designed as a **hybrid sequential-branching system** where:
- **Main Flow** follows a strict sequential path (Stages 1-12)
- **Branch Flows** diverge based on user decisions at specific decision points
- **Accessibility Logic** determines which stages can be accessed at any given time
- **Auto-Expansion** in UI reveals relevant branches only when active

This architecture ensures:
- ✅ Clear workflow progression
- ✅ Flexible decision-based routing
- ✅ Clean UI presentation (only relevant stages shown)
- ✅ Complete audit trail of user choices

---

## 🔄 Core Concept

### Workflow Structure
```
┌─────────────────────────────────────────────────────────────┐
│                      MAIN FLOW (1-12)                        │
│                    Sequential Progression                    │
│                                                               │
│  1→2→3→4*→5→6→7*→8→9→10*→11→12*                             │
│        ↓           ↓         ↓         ↓                     │
│     [DP1]      [DP2]      [DP3]     [DP4]                   │
│   Decision    Decision   Decision  Decision                 │
│   Points      Points     Points    Points                   │
│        │           │         │         │                     │
│        ├──────┐    ├──────┐  ├──────┐  │                     │
│        │      │    │      │  │      │  │                     │
│    REFUND  (alt)KIAN   REFUND (alt)KIAN  REFUND (alt)KIAN  │
│    13-15      16     13-15   16    13-15   16               │
└─────────────────────────────────────────────────────────────┘

* = Decision Point stages (4, 7, 10, 12)
```

### Key Principles

**1. Sequential Main Flow**
- Stages 1-12 must be completed in order
- Stage N is only accessible after Stage N-1 is completed
- Exception: Bypass to branch flow at decision points

**2. Decision-Driven Branching**
- At stages 4, 7, 10, 12: user makes critical decisions
- Decision type determines which branch is taken
- User choice is immutable once submitted

**3. Non-Sequential Access After Branch**
- Once branch is active, main flow stops sequential progression
- User cannot return to main flow from branch
- Branch flow follows its own sequential rules

**4. Multiple Branch Possibility**
- Refund AND KIAN can both be active in same case
- KIAN always follows Refund when both active
- Clear completion order must be maintained

---

## 📍 Main Flow (Sequential Stages 1-12)

### Stage Overview

| Stage | Name | Type | Description | Decision Point |
|-------|------|------|-------------|-----------------|
| 1 | SPT Filing | Filing | Surat Pemberitahuan Tahunan | No |
| 2 | SP2 Receipt | Audit | Surat Pemberitahuan Pemeriksaan | No |
| 3 | SPHP Receipt | Audit | Surat Pemberitahuan Hasil Pemeriksaan | No |
| 4 | SKP Receipt | Assessment | Surat Ketetapan Pajak | **YES** ✓ |
| 5 | Objection Submission | Dispute | Surat Keberatan | No |
| 6 | SPUH Receipt | Dispute | Surat Pemberitahuan Untuk Hadir | No |
| 7 | Objection Decision | Dispute | Keputusan Keberatan | **YES** ✓ |
| 8 | Appeal Submission | Legal | Surat Banding | No |
| 9 | Appeal Explanation | Legal | Permintaan Penjelasan Banding | No |
| 10 | Appeal Decision | Legal | Keputusan Banding | **YES** ✓ |
| 11 | Supreme Court Submission | Legal | Surat Peninjauan Kembali | No |
| 12 | Supreme Court Decision | Legal | Keputusan Peninjauan Kembali | **YES** ✓ |

### Sequential Rules

- **Stage 1:** Always accessible (entry point)
- **Stage 2-12:** Accessible only if previous stage is completed
- **No Skipping:** Must complete Stage N before accessing Stage N+1
- **No Returning:** Cannot go back to previous main stages after completion

### Complete Main Flow Scenario

```
Case Created
    ↓
[1] SPT Filing (User inputs data) → COMPLETED
    ↓
[2] SP2 Receipt (User inputs data) → COMPLETED
    ↓
[3] SPHP Receipt (User inputs data) → COMPLETED
    ↓
[4] SKP Receipt (User inputs data) → COMPLETED ← DECISION POINT
    ├─ Decision: Continue to Objection
    │  ↓
    │ [5] Objection Submission → COMPLETED
    │  ↓
    │ [6] SPUH Receipt → COMPLETED
    │  ↓
    │ [7] Objection Decision → COMPLETED ← DECISION POINT
    │  │  (User decides outcome: Granted/Partial/Rejected)
    │  └─ [BRANCH DECISION]
    │
    └─ Decision: Skip to Refund
       ↓
       [REFUND BRANCH INITIATED]
```

---

## 🌿 Branch Flow Architecture

### Branch Types

#### 1. **Refund Branch (Stages 13-15)**
Sequential flow for processing tax refunds

#### 2. **KIAN Branch (Stage 16)**
Single-stage flow for loss recognition

### Branch Trigger Points

Refund and KIAN branches can be triggered from:
- **Stage 4** (SKP Receipt): User chooses "Proceed to Refund"
- **Stage 7** (Objection Decision): Based on decision outcome
- **Stage 10** (Appeal Decision): Based on decision outcome
- **Stage 12** (Supreme Court Decision): Based on decision outcome

### Branch Interaction Model

```
DECISION AT STAGE 7 (Objection Decision)
├─ Outcome: GRANTED (Dikabulkan)
│  └─ → REFUND BRANCH ONLY (13-15)
│
├─ Outcome: PARTIALLY GRANTED (Dikabulkan Sebagian)
│  ├─ User chooses: Process Refund
│  │  └─ → REFUND BRANCH (13-15) + KIAN BRANCH (16)
│  │
│  └─ User chooses: Continue to Appeal
│     └─ → Stay in Main Flow (→ Stage 8)
│
└─ Outcome: REJECTED (Ditolak)
   └─ → KIAN BRANCH ONLY (16)
```

---

## 💰 Refund Branch (Stages 13-15)

### Overview
Sequential processing of tax refund from tax authority

### Stages

| Stage | Name | Description |
|-------|------|-------------|
| 13 | Bank Transfer Request | Surat Permintaan Transfer |
| 14 | Transfer Instruction | Surat Instruksi Transfer |
| 15 | Refund Received | Fund receipt confirmation |

### Accessibility Rules

**Refund Branch becomes accessible when:**
- User completes Stage 4 (SKP) and chooses "Proceed to Refund", OR
- User completes Stage 7 (Objection Decision) with outcome GRANTED or PARTIALLY GRANTED + chooses "Proceed to Refund", OR
- User completes Stage 10 (Appeal Decision) with outcome GRANTED or PARTIALLY GRANTED + chooses "Proceed to Refund", OR
- User completes Stage 12 (Supreme Court Decision) with outcome GRANTED or PARTIALLY GRANTED + chooses "Proceed to Refund"

**Sequential Access:**
- Stage 13: Accessible when branch is active
- Stage 14: Accessible only if Stage 13 is completed
- Stage 15: Accessible only if Stage 14 is completed

### Terminal State
- Once Stage 15 (Refund Received) is completed, Refund Branch is FINISHED
- Case moves to KIAN Branch if applicable, OR case is CLOSED

### Example Refund Flow

```
User at Stage 7 (Objection Decision)
Decision Made: PARTIALLY GRANTED
User Choice: Process Refund

[REFUND BRANCH ACTIVATED]
    ↓
[13] Bank Transfer Request (Surat Permintaan Transfer)
     User: Input transfer request letter details
     ↓ COMPLETED
[14] Transfer Instruction (Surat Instruksi Transfer)
     Tax Authority: Sends instruction
     User: Input instruction details
     ↓ COMPLETED
[15] Refund Received
     User: Confirm fund receipt
     ↓ COMPLETED

[REFUND BRANCH FINISHED]
    ↓
Case Transitions to KIAN Branch (if triggered)
```

---

## 📋 KIAN Branch (Stage 16)

### Overview
KIAN (Kerugian Internal) = Internal Loss Recognition Document

Required when disputed amount cannot be fully recovered through refund.

### When KIAN is Triggered

**KIAN becomes accessible when:**

1. **At Stage 7 (Objection Decision):**
   - Outcome = REJECTED → KIAN automatically triggered
   - Outcome = PARTIALLY GRANTED + user chooses Refund → KIAN triggered after refund completes

2. **At Stage 10 (Appeal Decision):**
   - Outcome = REJECTED → KIAN automatically triggered
   - Outcome = PARTIALLY GRANTED + user chooses Refund → KIAN triggered after refund completes

3. **At Stage 12 (Supreme Court Decision):**
   - Outcome = REJECTED → KIAN automatically triggered
   - Outcome = PARTIALLY GRANTED + user chooses Refund → KIAN triggered after refund completes

### Stage Details

| Field | Type | Description |
|-------|------|-------------|
| Stage 16 | Single Stage | KIAN Submission |
| - | Nomor KIAN | Internal loss document number |
| - | Tanggal Dilaporkan | Report date |
| - | Amount | Loss amount (unrefundable portion) |
| - | Tanggal Approval | Approval date |
| - | Supporting Docs | Internal loss documentation |

### Accessibility Rules

**Pre-Conditions for KIAN Access:**
- Refund Branch must be completed IF both branches are active
- OR Refund Branch is not active (pure rejection case)

**KIAN is Never Skippable:**
- When triggered, it MUST be completed
- Cannot bypass to close case without KIAN
- Maintains audit trail for loss recognition

### Terminal State
Once Stage 16 is completed, case enters final state:
- Status: KIAN_SUBMITTED or CASE_CLOSED
- No further workflow stages available
- Complete audit trail recorded

### Example KIAN Flow - Rejection Case

```
User at Stage 12 (Supreme Court Decision)
Decision Made: REJECTED

[KIAN BRANCH AUTOMATICALLY ACTIVATED]
    ↓
[16] KIAN Submission
     User: Input KIAN details (number, date, amount, docs)
     ↓ COMPLETED

[CASE CLOSED - FINAL STATE]
    └─ Status: KIAN_SUBMITTED
```

### Example KIAN Flow - Partial Grant + Refund

```
User at Stage 7 (Objection Decision)
Decision Made: PARTIALLY GRANTED
User Choice: Process Refund

[REFUND BRANCH ACTIVATED]
    ↓
[13-15] Refund Process... COMPLETED
    ↓
[KIAN BRANCH ACTIVATED] (for rejected portion)
    ↓
[16] KIAN Submission
     User: Input KIAN details for unrefunded portion
     ↓ COMPLETED

[CASE CLOSED - FINAL STATE]
    └─ Status: REFUND_COMPLETED + KIAN_SUBMITTED
```

---

## 🔀 Branching Logic & Decision Points

### Decision Point 1: Stage 4 (SKP Receipt)

**User Choice:**
- Option A: "Proceed to Objection" → Continue main flow (→ Stage 5)
- Option B: "Proceed to Refund" → Activate Refund Branch (→ Stage 13)

**Logic:**
```javascript
if (stage4_completed) {
  if (user_choice === 'objection') {
    stage5_accessible = true  // Continue main flow
  } else if (user_choice === 'refund') {
    refund_branch_active = true  // Activate branch
    stage13_accessible = true    // Skip to refund
    main_flow_paused = true      // Main flow stops here
  }
}
```

### Decision Point 2: Stage 7 (Objection Decision)

**Decision Outcome:**
- GRANTED (Dikabulkan)
- PARTIALLY GRANTED (Dikabulkan Sebagian)
- REJECTED (Ditolak)

**Routing Logic:**
```javascript
if (stage7_completed) {
  const outcome = user_decision // GRANTED | PARTIALLY_GRANTED | REJECTED
  
  if (outcome === 'GRANTED') {
    // User's objection fully accepted
    refund_branch_active = true
    stage13_accessible = true
    main_flow_paused = true
    
  } else if (outcome === 'PARTIALLY_GRANTED') {
    // User's objection partially accepted
    // User now chooses:
    //   - Process Refund (for granted portion)
    //   - Continue to Appeal (for rejected portion)
    
    if (user_choice === 'refund') {
      refund_branch_active = true
      stage13_accessible = true
      main_flow_paused = true
      kian_branch_pending = true  // Will activate after refund
    } else {
      stage8_accessible = true    // Continue to appeal
      kian_branch_pending = true  // Will activate if appeal rejected
    }
    
  } else if (outcome === 'REJECTED') {
    // User's objection fully rejected
    kian_branch_active = true
    stage16_accessible = true
    main_flow_paused = true
  }
}
```

### Decision Point 3: Stage 10 (Appeal Decision)

**Same Logic as Stage 7:**
- GRANTED → Refund Branch
- PARTIALLY GRANTED → User choice (Refund or Supreme Court)
- REJECTED → KIAN Branch

### Decision Point 4: Stage 12 (Supreme Court Decision)

**Same Logic as Stage 7 & 10:**
- GRANTED → Refund Branch
- PARTIALLY GRANTED → Refund Branch + KIAN Branch
- REJECTED → KIAN Branch

---

## 📊 Stage Accessibility Rules

### Rule Matrix

| Scenario | Stage Accessible | Notes |
|----------|-----------------|-------|
| Case Just Started | 1 (SPT) | Only first stage |
| Stage N Completed | N+1 (if main) | Sequential main flow |
| Stage 4 Complete + Refund Choice | 13 (Refund) | Branch activated |
| Stage 7 Complete + Rejected | 16 (KIAN) | Branch activated |
| Stage 7 Complete + Partial + Refund | 13-15 + 16 | Both branches |
| Refund Completed | 16 (if KIAN triggered) | Sequential branch |
| KIAN Stage Completed | None | Terminal state |

### Accessibility Logic Algorithm

```javascript
function updateStageAccessibility() {
  workflowStages.forEach((stage) => {
    stage.accessible = false
    stage.completed = checkIfCompleted(stage.id)
    
    if (stage.branch === 'main') {
      // MAIN FLOW: Sequential
      if (stage.id === 1) {
        stage.accessible = true  // Always first
      } else if (isPreviousStageCompleted(stage.id)) {
        // Only if main flow hasn't branched
        if (!isRefundBranchActive() && !isKianBranchActive()) {
          stage.accessible = true
        }
      }
      
    } else if (stage.branch === 'refund') {
      // REFUND BRANCH: Only if refund active
      if (isRefundBranchActive()) {
        if (stage.id === 13) {
          stage.accessible = true
        } else if (isPreviousStageCompleted(stage.id)) {
          stage.accessible = true
        }
      }
      
    } else if (stage.branch === 'kian') {
      // KIAN BRANCH: Only if kian active AND refund complete
      if (isKianBranchActive()) {
        const refundComplete = isStageCompleted(15) || !isRefundBranchActive()
        stage.accessible = refundComplete
      }
    }
  })
}
```

---

## 🎨 UI Implementation

### Collapsible Sections Design

```
┌─────────────────────────────────────────────┐
│ ▼ MAIN FLOW (Stages 1-12)                   │
├─────────────────────────────────────────────┤
│ [1] SPT Filing                ✓ COMPLETED   │
│ [2] SP2 Receipt               ✓ COMPLETED   │
│ [3] SPHP Receipt              ✓ COMPLETED   │
│ [4] SKP Receipt          ⚠ DECISION POINT   │
│ [5] Objection Submission      ⊙ ACCESSIBLE  │
│ ...                                         │
└─────────────────────────────────────────────┘

┌─────────────────────────────────────────────┐
│ ▶ REFUND PROCESS (Stages 13-15)  [ACTIVE]   │
├─────────────────────────────────────────────┤
│ Section collapsed (auto-expands when active)│
│ [13] Bank Transfer Request                  │
│ [14] Transfer Instruction                   │
│ [15] Refund Received                        │
└─────────────────────────────────────────────┘

┌─────────────────────────────────────────────┐
│ ▶ KIAN SUBMISSION (Stage 16)  - Awaiting... │
├─────────────────────────────────────────────┤
│ Section collapsed (grayed out, not active)  │
│ [16] KIAN Submission                        │
└─────────────────────────────────────────────┘
```

### Section States

| Section | State | Appearance | Behavior |
|---------|-------|------------|----------|
| Main Flow | Always Expanded | Gray background | Collapsible |
| Refund Branch | Collapsed (default) | Green background | Auto-expands when active |
| Refund Branch | Expanded (active) | Green background | Fully interactive |
| Refund Branch | Disabled (inactive) | Gray + disabled | Cannot interact |
| KIAN Branch | Collapsed (default) | Amber background | Auto-expands when active |
| KIAN Branch | Expanded (active) | Amber background | Fully interactive |
| KIAN Branch | Disabled (inactive) | Gray + disabled | Cannot interact |

### Auto-Expand Behavior

```javascript
// When stages are completed/decisions made
onWorkflowUpdate() {
  if (isRefundBranchActive()) {
    expandedSections.refund = true  // Auto-expand
  }
  if (isKianBranchActive()) {
    expandedSections.kian = true    // Auto-expand
  }
}
```

### Visual Indicators

- ✓ = Stage Completed (green circle)
- ⊙ = Stage Accessible (blue circle)
- ⊗ = Stage Not Yet Accessible (gray circle)
- ⚠ = Decision Point (special styling)
- [ACTIVE] = Branch is currently active
- - Awaiting... = Branch waiting for conditions to activate

---

## 📖 Use Case Scenarios

### Scenario 1: Direct Refund After SKP

**Flow:**
```
[1] SPT Filing → COMPLETED
[2] SP2 Receipt → COMPLETED
[3] SPHP Receipt → COMPLETED
[4] SKP Receipt → COMPLETED
    └─ Decision: Proceed to Refund

[REFUND BRANCH ACTIVATED]
[13] Bank Transfer Request → COMPLETED
[14] Transfer Instruction → COMPLETED
[15] Refund Received → COMPLETED

[CASE CLOSED]
Status: REFUND_COMPLETED
```

**Key Points:**
- Skips objection and appeal process
- Refund only (no KIAN)
- Fastest path to resolution

---

### Scenario 2: Objection Granted + Refund

**Flow:**
```
[1-3] Initial stages → COMPLETED
[4] SKP Receipt → COMPLETED
    └─ Decision: Proceed to Objection

[5] Objection Submission → COMPLETED
[6] SPUH Receipt → COMPLETED
[7] Objection Decision → COMPLETED
    └─ Decision: GRANTED (Dikabulkan)

[REFUND BRANCH ACTIVATED]
[13] Bank Transfer Request → COMPLETED
[14] Transfer Instruction → COMPLETED
[15] Refund Received → COMPLETED

[CASE CLOSED]
Status: OBJECTION_GRANTED + REFUND_COMPLETED
```

**Key Points:**
- Full objection process completed
- Objection was successful (granted)
- Refund executed
- No KIAN needed

---

### Scenario 3: Objection Rejected + KIAN

**Flow:**
```
[1-3] Initial stages → COMPLETED
[4] SKP Receipt → COMPLETED
    └─ Decision: Proceed to Objection

[5-6] Dispute stages → COMPLETED
[7] Objection Decision → COMPLETED
    └─ Decision: REJECTED (Ditolak)

[KIAN BRANCH ACTIVATED]
[16] KIAN Submission → COMPLETED
    └─ Input: Loss amount, documentation

[CASE CLOSED]
Status: OBJECTION_REJECTED + KIAN_SUBMITTED
```

**Key Points:**
- Objection failed
- Tax authority's decision stands
- KIAN required for internal documentation
- No refund available

---

### Scenario 4: Objection Partial + Refund + KIAN

**Flow:**
```
[1-3] Initial stages → COMPLETED
[4] SKP Receipt → COMPLETED
    └─ Decision: Proceed to Objection

[5-6] Dispute stages → COMPLETED
[7] Objection Decision → COMPLETED
    └─ Decision: PARTIALLY GRANTED (Dikabulkan Sebagian)
    └─ User Choice: Process Refund

[REFUND BRANCH ACTIVATED]
[13] Bank Transfer Request → COMPLETED
[14] Transfer Instruction → COMPLETED
[15] Refund Received → COMPLETED

[KIAN BRANCH ACTIVATED] (for rejected portion)
[16] KIAN Submission → COMPLETED
    └─ Amount: Remaining unrefunded portion

[CASE CLOSED]
Status: PARTIAL_SUCCESS + REFUND_COMPLETED + KIAN_SUBMITTED
```

**Key Points:**
- Objection partially successful
- Granted portion processed as refund
- Rejected portion documented as KIAN
- Complex scenario requiring both branches

---

### Scenario 5: Full Appeal Process

**Flow:**
```
[1-6] Initial stages → COMPLETED
[7] Objection Decision → COMPLETED
    └─ Decision: REJECTED
    └─ User Choice: Continue to Appeal (vs go to KIAN)

[8] Appeal Submission → COMPLETED
[9] Appeal Explanation Request → COMPLETED
[10] Appeal Decision → COMPLETED
    └─ Decision: GRANTED (Dikabulkan)

[REFUND BRANCH ACTIVATED]
[13-15] Refund Process → COMPLETED

[CASE CLOSED]
Status: APPEAL_GRANTED + REFUND_COMPLETED
```

**Key Points:**
- Multi-stage dispute resolution
- Appeal overturns objection rejection
- Refund executed based on appeal success
- Demonstrates sequential stage progression through main flow

---

### Scenario 6: Supreme Court Final Appeal

**Flow:**
```
[1-9] Previous stages → COMPLETED
[10] Appeal Decision → COMPLETED
    └─ Decision: REJECTED (Ditolak)
    └─ User Choice: Escalate to Supreme Court

[11] Supreme Court Submission → COMPLETED
[12] Supreme Court Decision → COMPLETED
    └─ Decision: PARTIALLY GRANTED
    └─ User Choice: Process Refund

[REFUND BRANCH ACTIVATED]
[13-15] Refund Process → COMPLETED

[KIAN BRANCH ACTIVATED]
[16] KIAN Submission → COMPLETED
    └─ Amount: For rejected portion

[CASE CLOSED]
Status: SUPREME_COURT_PARTIAL + REFUND_COMPLETED + KIAN_SUBMITTED
```

**Key Points:**
- Full escalation through tax dispute system
- Multiple decision points traversed
- Final decision triggers both refund and KIAN
- Represents most complex workflow path

---

## 💾 Data Structure

### Workflow History Record

Each workflow transition is recorded:

```json
{
  "id": 1,
  "case_id": 100,
  "stage_id": 7,
  "action": "submitted",
  "status": "completed",
  "decision": "PARTIALLY_GRANTED",
  "user_choice": "refund",
  "decision_value": 50000000,
  "notes": "Objection partially accepted for service charges",
  "created_by": "user_123",
  "created_at": "2026-01-18T10:30:00Z",
  "updated_at": "2026-01-18T10:30:00Z"
}
```

### Decision Point Data

```json
{
  "stage_id": 7,
  "stage_name": "Objection Decision",
  "decision_type": "objection_result",
  "possible_outcomes": [
    "GRANTED",
    "PARTIALLY_GRANTED",
    "REJECTED"
  ],
  "user_choices": {
    "GRANTED": ["refund"],
    "PARTIALLY_GRANTED": ["refund", "appeal"],
    "REJECTED": ["appeal", "kian"]
  }
}
```

### Branch Tracking

```json
{
  "case_id": 100,
  "branches_active": ["refund", "kian"],
  "refund_triggered_at_stage": 7,
  "kian_triggered_at_stage": 7,
  "refund_completed": true,
  "refund_completed_date": "2026-02-15",
  "kian_completed": false,
  "kian_completed_date": null
}
```

### Stage Accessibility State

```json
{
  "case_id": 100,
  "current_stage": 7,
  "main_flow_paused": true,
  "branch_active": "refund",
  "accessibility_map": {
    "main_stages": {
      "1": {"completed": true, "accessible": false},
      "2": {"completed": true, "accessible": false},
      "3": {"completed": true, "accessible": false},
      "4": {"completed": true, "accessible": false},
      "5": {"completed": false, "accessible": false},
      "6": {"completed": false, "accessible": false},
      "7": {"completed": true, "accessible": false},
      "8": {"completed": false, "accessible": false}
    },
    "refund_stages": {
      "13": {"completed": false, "accessible": true},
      "14": {"completed": false, "accessible": false},
      "15": {"completed": false, "accessible": false}
    },
    "kian_stages": {
      "16": {"completed": false, "accessible": false}
    }
  }
}
```

---

## 🔐 Immutability & Audit Trail

### Decision Immutability

Once a decision is made at a decision point:
- **Cannot be reversed** without revision request
- **Locked in history** for audit trail
- **Triggers subsequent stages** based on decision

### Audit Trail Capture

Every workflow transition records:
- ✓ User who made the decision
- ✓ Decision/choice made
- ✓ Date/time of decision
- ✓ Branch activated (if any)
- ✓ Next stage(s) unlocked
- ✓ System state at time of decision

---

## 🚀 Implementation Checklist

### Backend Logic
- [ ] Implement decision point handlers for stages 4, 7, 10, 12
- [ ] Create branch activation logic
- [ ] Implement stage accessibility rules engine
- [ ] Create workflow history tracking
- [ ] Add decision immutability enforcement
- [ ] Implement refund branch sequential validation
- [ ] Implement KIAN branch prerequisite validation

### Frontend Implementation
- [ ] Create collapsible section components
- [ ] Implement auto-expand behavior
- [ ] Add decision point UI components
- [ ] Display branch status and activity
- [ ] Show accessibility indicators
- [ ] Implement workflow history display
- [ ] Add decision review UI

### Database Schema
- [ ] Add branch tracking columns
- [ ] Add decision tracking columns
- [ ] Extend workflow_history table
- [ ] Create decision_points table
- [ ] Add branch_activities table

### API Endpoints
- [ ] POST /stages/{id}/complete (with decision)
- [ ] GET /cases/{id}/stage-accessibility
- [ ] GET /cases/{id}/branch-status
- [ ] POST /branches/activate
- [ ] GET /cases/{id}/workflow-history

---

## 📝 Notes & Future Considerations

1. **Revision Workflow:** Currently, decisions are immutable. Future phases may allow revisions with approval chain.

2. **Parallel Processing:** Current design assumes sequential branch processing. Future enhancement could support parallel branches.

3. **Conditional Skipping:** Some stages might be skippable under certain conditions (e.g., if no additional docs needed). Current design requires all stages.

4. **Time Limits:** Workflow doesn't enforce time limits at each stage. Future version should implement deadline tracking.

5. **Notifications:** Trigger notification workflows when stages become accessible or decisions need to be made.

6. **Approval Chain:** Some stages might require approval from multiple stakeholders before proceeding.

---

## 🔗 Related Documentation

- [PORTAX_FLOW.md](PORTAX_FLOW.md) - Complete workflow documentation
- [TaxCaseDetail.vue](../resources/js/pages/TaxCaseDetail.vue) - Frontend implementation
- [Database Schema](#) - (Link to schema documentation)
- [API Documentation](#) - (Link to API docs)

---

**End of Workflow Branching Concept Documentation**

*For questions or clarifications, refer to the PorTax architecture team.*
