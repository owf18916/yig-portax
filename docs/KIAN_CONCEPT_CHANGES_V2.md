# KIAN Concept Changes - Multiple KIAN Per Case (Version 2)

**Date:** January 30, 2026  
**Status:** Approved Concept  
**Key Change:** From "One KIAN per case" → "Multiple KIAN per stage"

---

## 📋 Executive Summary

**Old Concept:** Single KIAN record per tax case (terminal stage)

**New Concept:** Multiple KIAN records per tax case, one for each decision stage that creates loss
- Stage 4 (SKP): Can trigger KIAN
- Stage 7 (Objection Decision): Can trigger KIAN  
- Stage 10 (Appeal Decision): Can trigger KIAN
- Stage 12 (Supreme Court Decision): Can trigger KIAN
- Each is independent, calculated progressively

**KIAN Trigger Rule:** Loss exists at any stage when `decision_amount < previous_submission_amount`

---

## 🔄 Progressive Loss Calculation (Approach C)

```
STAGE 4 (SKP):
  Compare: skp_amount < disputed_amount (SPT original)
  Loss = disputed_amount - skp_amount
  
STAGE 7 (Objection Decision):
  Compare: decision_amount < objection_submission.objection_amount
  Loss = objection_amount - decision_amount
  
STAGE 10 (Appeal Decision):
  Compare: decision_amount < appeal_submission.appeal_amount
  Loss = appeal_amount - decision_amount
  
STAGE 12 (Supreme Court Decision):
  Compare: decision_amount < supreme_court_submission.review_amount
  Loss = review_amount - decision_amount
```

---

## 📊 Database Schema Changes

### kian_submissions Table

**Current Structure:**
```sql
id | tax_case_id | kian_number | submission_date | kian_amount | status | ...
(unique constraint: tax_case_id)
```

**New Structure:**
```sql
id | tax_case_id | stage_id | kian_number | submission_date | kian_amount | status | ...
(unique constraint: tax_case_id + stage_id)
```

**New Column:**
```php
$table->unsignedInteger('stage_id')
      ->comment('Stage that triggered KIAN (4, 7, 10, 12)');
```

**Migration Change:**
```php
// Remove unique constraint on tax_case_id only
// Add unique constraint on (tax_case_id, stage_id) combination
$table->unique(['tax_case_id', 'stage_id']);
```

---

## 🔧 Model Logic (TaxCase)

### Method 1: Check if KIAN needed at specific stage

```php
public function needsKianAtStage(int $stageId): bool
{
    if ($stageId === 4) {
        // Stage 4: SKP
        return $this->skpRecord && 
               $this->skpRecord->skp_amount < $this->disputed_amount;
    }
    
    if ($stageId === 7) {
        // Stage 7: Objection Decision
        return $this->objectionDecision && $this->objectionSubmission &&
               $this->objectionDecision->decision_amount < 
               $this->objectionSubmission->objection_amount;
    }
    
    if ($stageId === 10) {
        // Stage 10: Appeal Decision
        return $this->appealDecision && $this->appealSubmission &&
               $this->appealDecision->decision_amount < 
               $this->appealSubmission->appeal_amount;
    }
    
    if ($stageId === 12) {
        // Stage 12: Supreme Court Decision
        return $this->supremeCourtDecision && $this->supremeCourtSubmission &&
               in_array($this->supremeCourtDecision->decision_type, 
                   ['PARTIALLY_GRANTED', 'REJECTED']) &&
               $this->supremeCourtDecision->decision_amount < 
               $this->supremeCourtSubmission->review_amount;
    }
    
    return false;
}
```

### Method 2: Calculate loss amount at stage

```php
public function calculateLossAtStage(int $stageId): ?float
{
    if ($stageId === 4) {
        if (!$this->needsKianAtStage(4)) return null;
        return $this->disputed_amount - $this->skpRecord->skp_amount;
    }
    
    if ($stageId === 7) {
        if (!$this->needsKianAtStage(7)) return null;
        return $this->objectionSubmission->objection_amount - 
               $this->objectionDecision->decision_amount;
    }
    
    if ($stageId === 10) {
        if (!$this->needsKianAtStage(10)) return null;
        return $this->appealSubmission->appeal_amount - 
               $this->appealDecision->decision_amount;
    }
    
    if ($stageId === 12) {
        if (!$this->needsKianAtStage(12)) return null;
        return $this->supremeCourtSubmission->review_amount - 
               $this->supremeCourtDecision->decision_amount;
    }
    
    return null;
}
```

### Method 3: Check if KIAN can be created for stage

```php
public function canCreateKianForStage(int $stageId): bool
{
    // KIAN must be needed at this stage
    if (!$this->needsKianAtStage($stageId)) {
        return false;
    }
    
    // KIAN for this stage must not already exist
    return !$this->kianSubmissions()
        ->where('stage_id', $stageId)
        ->exists();
}
```

### Method 4: Get eligibility reason for stage

```php
public function getKianEligibilityReasonForStage(int $stageId): ?string
{
    $loss = $this->calculateLossAtStage($stageId);
    if (!$loss) return null;
    
    $lossFormatted = 'Rp ' . number_format($loss, 0, ',', '.');
    
    switch ($stageId) {
        case 4:
            return "SKP amount (Rp " . 
                number_format($this->skpRecord->skp_amount, 0, ',', '.') . 
                ") kurang dari SPT (Rp " . 
                number_format($this->disputed_amount, 0, ',', '.') . 
                "). Loss: {$lossFormatted}";
        
        case 7:
            return "Keputusan Keberatan (Rp " . 
                number_format($this->objectionDecision->decision_amount, 0, ',', '.') . 
                ") kurang dari pengajuan (Rp " . 
                number_format($this->objectionSubmission->objection_amount, 0, ',', '.') . 
                "). Loss: {$lossFormatted}";
        
        case 10:
            return "Keputusan Banding (Rp " . 
                number_format($this->appealDecision->decision_amount, 0, ',', '.') . 
                ") kurang dari pengajuan (Rp " . 
                number_format($this->appealSubmission->appeal_amount, 0, ',', '.') . 
                "). Loss: {$lossFormatted}";
        
        case 12:
            return "Keputusan PK (Rp " . 
                number_format($this->supremeCourtDecision->decision_amount, 0, ',', '.') . 
                ") kurang dari pengajuan (Rp " . 
                number_format($this->supremeCourtSubmission->review_amount, 0, ',', '.') . 
                "). Loss: {$lossFormatted}";
    }
    
    return null;
}
```

---

## 📧 Email Trigger Logic

**When to send:**
After decision is saved at Stage 4, 7, 10, or 12, check if loss exists:

```php
// In each decision controller (SkpRecordController, ObjectionDecisionController, etc.)

// After saving decision...
$stageId = [4, 7, 10, or 12];

if ($taxCase->needsKianAtStage($stageId)) {
    $reason = $taxCase->getKianEligibilityReasonForStage($stageId);
    $stageName = $this->getStageName($stageId);
    
    dispatch(new SendKianReminderJob(
        $taxCase,
        $stageName,
        $reason,
        $stageId  // Add stage_id parameter
    ));
}
```

**Email Content:**
- Subject: "KIAN Required: Tax Case {case_number} - {stageName}"
- Content: Shows loss amount and stage-specific reason
- Action: Link to tax case detail to submit KIAN for that stage

---

## 🖥️ API Response Changes

**GET /api/tax-cases/{id}**

```json
{
  "id": 1,
  "case_number": "ABC-2024-001",
  "disputed_amount": 1000000000,
  
  "kianStatusByStage": {
    "4": {
      "needsKian": true,
      "lossAmount": 400000000,
      "reason": "SKP amount...",
      "submitted": true,
      "kianId": 101
    },
    "7": {
      "needsKian": true,
      "lossAmount": 200000000,
      "reason": "Keputusan Keberatan...",
      "submitted": false,
      "kianId": null
    },
    "10": {
      "needsKian": true,
      "lossAmount": 300000000,
      "reason": "Keputusan Banding...",
      "submitted": false,
      "kianId": null
    },
    "12": {
      "needsKian": true,
      "lossAmount": 100000000,
      "reason": "Keputusan PK...",
      "submitted": false,
      "kianId": null
    }
  },
  
  "kianSubmissions": [
    {
      "id": 101,
      "stage_id": 4,
      "kian_number": "KIAN-24-001",
      "loss_amount": 400000000,
      "status": "submitted"
    }
  ]
}
```

---

## 🎯 Frontend Accessibility Logic

**Stage 16 (KIAN Submission) - Multiple Forms:**

```javascript
// Each stage has its own KIAN form accessible when:
// 1. Loss exists at that stage (needsKian: true)
// 2. KIAN not yet submitted for that stage (submitted: false)

if (kianStatusByStage[stageId].needsKian && !kianStatusByStage[stageId].submitted) {
    stage16.accessible = true
    stage16.formsByStage[stageId].accessible = true
}
```

**UI Display:**
- Show alert for each stage with loss
- Show button for each stage: "Submit KIAN for Stage X"
- Each button opens form for that stage with pre-filled loss_amount

---

## 📝 KIAN Form (Stage 16) - Per Stage

**Form submission endpoint:**
```
POST /api/tax-cases/{id}/workflow/16/{stageId}
```

**Fields:**
```json
{
  "stage_id": 4,
  "kian_number": "KIAN-2024-001",
  "submission_date": "2024-01-20",
  "loss_amount": 400000000,    // Pre-filled from calculation
  "approval_date": "2024-01-25",
  "notes": "..."
}
```

---

## 📊 Complete Flow Example

### Initial State
```
SPT Disputed: Rp 1,000,000,000 (tax_cases.disputed_amount)
```

### Stage 4 (SKP - Surat Ketetapan Pajak)
```
skp_records.skp_amount: Rp 600,000,000
[This is TAX OFFICE decision via SKP]

Compare: skp_amount < disputed_amount
  → Rp 600M < Rp 1B ✓ YES
Loss: Rp 1B - Rp 600M = Rp 400M
  ⚠️ MAXIMUM for Stage 5 submission = Rp 400M

→ Email sent, KIAN Stage 4 accessible
→ User submit KIAN Stage 4 ✓ (loss_amount: 400M)
```

### Stage 5 (Objection Submission - Surat Keberatan)
```
objection_submissions.objection_amount: Rp 350,000,000
[This is USER submission to tax office]
[CONSTRAINT: Must be ≤ Rp 400M (loss from Stage 4)]
[User submitted Rp 350M ✓]
```

### Stage 7 (Objection Decision - Keputusan Keberatan)
```
objection_decisions.decision_amount: Rp 300,000,000
[This is TAX OFFICE decision on user's objection]

Compare: decision_amount < objection_amount
  → Rp 300M < Rp 350M ✓ YES
Loss: Rp 350M - Rp 300M = Rp 50M
  ⚠️ MAXIMUM for Stage 8 submission = Rp 50M

→ Email sent, KIAN Stage 7 accessible
→ User doesn't submit yet (draft)
```

### Stage 8 (Appeal Submission - Surat Banding)
```
appeal_submissions.appeal_amount: Rp 45,000,000
[This is USER submission to court of appeal]
[CONSTRAINT: Must be ≤ Rp 50M (loss from Stage 7)]
[User submitted Rp 45M ✓]
```

### Stage 10 (Appeal Decision - Keputusan Banding)
```
appeal_decisions.decision_amount: Rp 30,000,000
[This is COURT OF APPEAL decision]

Compare: decision_amount < appeal_amount
  → Rp 30M < Rp 45M ✓ YES
Loss: Rp 45M - Rp 30M = Rp 15M
  ⚠️ MAXIMUM for Stage 11 submission = Rp 15M

→ Email sent, KIAN Stage 10 accessible
→ User doesn't submit yet (draft)
```

### Stage 11 (Supreme Court Submission - Peninjauan Kembali)
```
supreme_court_submissions.review_amount: Rp 12,000,000
[This is USER submission to supreme court]
[CONSTRAINT: Must be ≤ Rp 15M (loss from Stage 10)]
[User submitted Rp 12M ✓]
```

### Stage 12 (Supreme Court Decision - Keputusan PK)
```
supreme_court_decisions.decision_amount: Rp 8,000,000
[This is SUPREME COURT final decision]

Compare: decision_amount < review_amount
  → Rp 8M < Rp 12M ✓ YES
Loss: Rp 12M - Rp 8M = Rp 4M
  (No next stage, this is final)

→ Email sent, KIAN Stage 12 accessible
→ User submit KIAN Stage 12 ✓ (loss_amount: 4M)
```

### Result: Multiple KIAN Records Per Tax Case
```
KIAN Stage 4: Rp 400,000,000 (submitted) ✓
KIAN Stage 7: Rp 50,000,000 (draft, not submitted)
KIAN Stage 10: Rp 15,000,000 (draft, not submitted)
KIAN Stage 12: Rp 4,000,000 (submitted) ✓

Progressive loss narrowing:
  SPT: Rp 1B
  → After Stage 4: Loss Rp 400M (max submission Stage 5)
  → After Stage 7: Loss Rp 50M (max submission Stage 8)
  → After Stage 10: Loss Rp 15M (max submission Stage 11)
  → After Stage 12: Loss Rp 4M (final)
```

### Key Principles
```
1. Loss = Submission Amount - Decision Amount
   - Always comparing WHAT USER SUBMITTED vs WHAT AUTHORITY DECIDED
   - Loss calculated per stage independently

2. Maximum Next Submission = Current Stage Loss
   - Stage 4 loss (Rp 400M) = max objection_amount at Stage 5
   - Stage 7 loss (Rp 50M) = max appeal_amount at Stage 8
   - Stage 10 loss (Rp 15M) = max review_amount at Stage 11
   - User CANNOT submit more than available loss

3. Progressive Narrowing
   - Loss amount decreases as case progresses
   - Each stage's decision reduces available loss for next stage
   - Final loss at Stage 12 is cumulative result

4. KIAN Trigger = Loss > 0
   - Whenever decision_amount < submission_amount at any stage
   - Loss becomes KIAN opportunity for that stage
   - KIAN records are independent per stage, not cumulative

5. Multiple KIAN Opportunities
   - User can choose to submit KIAN at any stage with loss
   - Or wait until final stage (Stage 12) and submit only then
   - Each KIAN submission captures loss at that specific stage
```

---

## 🔑 Key Changes Summary

| Aspect | Old | New |
|---|---|---|
| **KIANs per case** | 1 | Multiple (one per stage) |
| **Trigger condition** | Final stage only | Any stage with loss |
| **Calculation** | Vs original disputed | Progressive vs previous amount |
| **stage_id in DB** | N/A | Required (4, 7, 10, 12) |
| **Unique constraint** | tax_case_id | (tax_case_id, stage_id) |
| **Form accessibility** | One form at end | Four forms (per stage) |
| **Email frequency** | Once | Multiple (per stage with loss) |

---

## ✅ Implementation Checklist

- [ ] Add `stage_id` column to kian_submissions table
- [ ] Update unique constraint: (tax_case_id, stage_id)
- [ ] Add TaxCase methods: needsKianAtStage(), calculateLossAtStage(), canCreateKianForStage(), getKianEligibilityReasonForStage()
- [ ] Update email trigger: add stage_id parameter to SendKianReminderJob
- [ ] Update API response: return kianStatusByStage with all 4 stages
- [ ] Update frontend: show KIAN alerts and forms per stage
- [ ] Update form submission: accept stage_id and route to correct stage
- [ ] Test: verify loss calculation at each stage and KIAN creation

---

**Status:** Ready for Implementation  
**Approved:** January 30, 2026
