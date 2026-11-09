// Presentation only. Never used for workflow routing or backend validation.
const defaults = Object.freeze({
  2: 'Preparing data and documents for submission to the tax auditor',
  3: 'Drafting a response to the SPHP',
  5: 'Awaiting SPUH',
  6: 'Drafting a Response to the SPUH',
  8: 'Awaiting SUB / Appeal Description Letter',
  11: 'Awaiting Judicial Review Decision'
})
const options = Object.freeze({
  4: Object.freeze([
    'Awaiting Tax Refund',
    'Awaiting Tax Refund; Processing KIAN and Preparing an Objection Submission',
    'Awaiting Tax Refund; Processing KIAN and Recording Tax Expense',
    'Processing KIAN; Paying the Underpaid Tax Assessment (SKP KB) and Preparing an Objection Submission',
    'Processing KIAN; Paying the Underpaid Tax Assessment (SKP KB) and Recording Tax Expense'
  ]),
  7: Object.freeze([
    'Awaiting Tax Refund',
    'Awaiting Tax Refund and Preparing an Appeal Submission',
    'Awaiting Tax Refund and Recording Tax Adjustment Expense',
    'Preparing an Appeal Submission',
    'Recording Tax Adjustment Expense'
  ]),
  9: Object.freeze(['Drafting a Rebuttal Letter', 'Awaiting a Court Summons']),
  12: Object.freeze(['Recording Tax Adjustment Expense', 'Awaiting Tax Refund']),
  partially_granted: Object.freeze([
    'Awaiting Tax Refund and Preparing a Judicial Review Petition',
    'Awaiting Tax Refund and Recording Tax Adjustment Expense'
  ]),
  rejected: Object.freeze(['Preparing a Judicial Review Petition', 'Recording Tax Adjustment Expense'])
})

export function nextActionPolicy(stageId, decision, workflowState) {
  if (stageId === 10) {
    const evidence = workflowState?.stages?.find(stage => stage.stage === 10)
    // Only authoritative, completed evidence may choose a conditional catalog.
    if (!decision?.id || !['submitted', 'approved', 'completed'].includes(decision.status)
      || evidence?.status !== 'completed' || evidence.draft
      || evidence.reason === 'inconsistent_persisted_state'
      || workflowState.diagnostics?.some(item => item.stage === 10 &&
        (item.code?.includes('conflicts_with') || item.code === 'non_sequential_completion'))) {
      return { mode: 'unresolved' }
    }
    if (decision.decision_type === 'granted') return { mode: 'text', defaultValue: 'Awaiting Tax Refund' }
    if (['partially_granted', 'rejected'].includes(decision.decision_type)) {
      return { mode: 'select', options: options[decision.decision_type] }
    }
    return { mode: 'unresolved' }
  }
  if (options[stageId]) return { mode: 'select', options: options[stageId] }
  return { mode: 'text', defaultValue: defaults[stageId] || '' }
}

export function initializeNextAction(policy, savedValue) {
  return savedValue || (policy.mode === 'text' ? policy.defaultValue : '') || ''
}
