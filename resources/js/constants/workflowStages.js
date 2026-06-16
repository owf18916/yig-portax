export const mainWorkflowStages = [
  {
    id: 1,
    name: 'SPT Filing',
    subtitle: 'Initial Tax Return Submission',
    emoji: '📋',
    description: 'File the initial SPT tax return with the tax authority.',
    requiredDocs: ['SPT tax return form', 'Supporting financial statements', 'Entity registration documents'],
    inputFields: ['Entity', 'Period', 'Currency', 'Dispute Amount']
  },
  {
    id: 2,
    name: 'SP2 Audit Notice',
    subtitle: 'Audit Notification',
    emoji: '📝',
    description: 'Record the SP2 audit notice from the tax authority.',
    requiredDocs: ['SP2 audit notice', 'Auditor details', 'Examination schedule'],
    inputFields: ['SP2 Number', 'Issue Date', 'Receipt Date', 'Auditor Name']
  },
  {
    id: 3,
    name: 'SPHP Audit Findings',
    subtitle: 'Audit Findings Notification',
    emoji: '🔍',
    description: 'Record the SPHP audit findings and correction details.',
    requiredDocs: ['SPHP letter', 'Audit findings breakdown', 'Correction details'],
    inputFields: ['SPHP Number', 'Issue Date', 'Receipt Date', 'Correction Amounts']
  },
  {
    id: 4,
    name: 'SKP Assessment',
    subtitle: 'Tax Assessment Letter',
    emoji: '🔬',
    description: 'Record the SKP tax assessment letter and assessment result.',
    requiredDocs: ['SKP letter', 'SKP type classification'],
    inputFields: ['SKP Number', 'Issue Date', 'Receipt Date', 'SKP Type', 'SKP Amount']
  },
  {
    id: 5,
    name: 'Objection Submission',
    subtitle: 'Formal Objection Filing',
    emoji: '⚠️',
    description: 'File a formal objection against the tax assessment.',
    requiredDocs: ['Objection Letter', 'Supporting evidence', 'Legal basis for objection'],
    inputFields: ['Objection Letter Number', 'Submission Date', 'Objection Amount']
  },
  {
    id: 6,
    name: 'SPUH Record',
    subtitle: 'Objection Hearing Notice',
    emoji: '⚖️',
    description: 'Record the SPUH notice and hearing or attendance details.',
    requiredDocs: ['SPUH letter', 'Reply letter', 'Attendance evidence'],
    inputFields: ['SPUH Number', 'Issue Date', 'Receipt Date', 'Response']
  },
  {
    id: 7,
    name: 'Objection Decision',
    subtitle: 'Objection Review Decision',
    emoji: '✍️',
    description: 'Record the decision on the submitted objection.',
    requiredDocs: ['Objection decision letter', 'Decision findings'],
    inputFields: ['Decision Number', 'Decision Date', 'Decision Type']
  },
  {
    id: 8,
    name: 'Appeal Submission',
    subtitle: 'Tax Court Appeal Filing',
    emoji: '📜',
    description: 'File an appeal to the tax court.',
    requiredDocs: ['Appeal Letter', 'Legal basis', 'Supporting evidence'],
    inputFields: ['Appeal Number', 'Submission Date', 'Appeal Amount']
  },
  {
    id: 9,
    name: 'Appeal Explanation Request',
    subtitle: 'Tax Court Explanation Request',
    emoji: '📚',
    description: 'Record the request for explanation during the appeal process.',
    requiredDocs: ['Explanation request letter', 'Additional evidence'],
    inputFields: ['Request Number', 'Request Date', 'Explanation Content']
  },
  {
    id: 10,
    name: 'Appeal Decision',
    subtitle: 'Tax Court Decision',
    emoji: '🏛️',
    description: 'Record the tax court decision on the appeal.',
    requiredDocs: ['Appeal decision letter'],
    inputFields: ['Decision Number', 'Decision Date', 'Decision Result']
  },
  {
    id: 11,
    name: 'Supreme Court Submission',
    subtitle: 'Peninjauan Kembali Submission',
    emoji: '⚡',
    description: 'File the Supreme Court review submission.',
    requiredDocs: ['Supreme Court submission', 'Legal basis'],
    inputFields: ['Submission Number', 'Submission Date', 'Reasons']
  },
  {
    id: 12,
    name: 'Supreme Court Decision',
    subtitle: 'Final Supreme Court Ruling',
    emoji: '📋',
    description: 'Record the final Supreme Court decision.',
    requiredDocs: ['Supreme Court decision letter'],
    inputFields: ['Decision Number', 'Decision Date', 'Final Ruling']
  }
]

export const workflowStageLabels = Object.fromEntries(
  mainWorkflowStages.map(stage => [stage.id, stage.name])
)

export const findWorkflowStage = stageId => (
  mainWorkflowStages.find(stage => stage.id === Number(stageId)) || null
)
