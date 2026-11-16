import { formatRefundAmount } from '../utils/refundPresentation.js'

const REVISION_LABELS = {
    'spt_position_type': 'Posisi SPT',
    'spt_type': 'Jenis Pengajuan Lebih Bayar',
    'period_id': 'Fiscal Period',
    'currency_id': 'Currency',
    'disputed_amount': 'SPT Amount',
    'sp2_number': 'Nomor SP2 (SP2 Number)',
    'issue_date': 'Tanggal Diterbitkan (Issue Date)',
    'receipt_date': 'Tanggal Diterima (Receipt Date)',
    'auditor_name': 'Auditor Name',
    'auditor_phone': 'Auditor Phone',
    'auditor_email': 'Auditor Email',
    'sphp_number': 'Nomor SPHP (SPHP Number)',
    'sphp_issue_date': 'Tanggal Diterbitkan (Issue Date)',
    'sphp_receipt_date': 'Tanggal Diterima (Receipt Date)',
    'royalty_finding': 'Royalty Finding Amount',
    'service_finding': 'Service Finding Amount',
    'other_finding': 'Other Finding Amount',
    'other_finding_notes': 'Notes for Other Findings',
    'skp_number': 'Nomor SKP (SKP Number)',
    'skp_type': 'Jenis SKP (SKP Type)',
    'skp_amount': 'Nilai SKP (SKP Amount)',
    'royalty_correction': 'Royalty Correction Amount',
    'service_correction': 'Service Correction Amount',
    'other_correction': 'Other Correction Amount',
    'correction_notes': 'Catatan untuk koreksi (Notes for Corrections)',
    'user_routing_choice': 'Routing Choice (Refund/Objection)',
    'objection_number': 'Nomor Surat Keberatan (Objection Letter Number)',
    'submission_date': 'Tanggal Dilaporkan (Submission Date)',
    'objection_amount': 'Nilai Keberatan (Objection Amount)',
    'objection_grounds': 'Alasan Keberatan (Grounds for Objection)',
    'supporting_evidence': 'Bukti Pendukung (Supporting Evidence)',
    'decision_number': 'Decision Letter Number',
    'decision_date': 'Tanggal Keputusan (Decision Date)',
    'decision_type': 'Keputusan (Decision Type)',
    'decision_amount': 'Nilai Keputusan (Decision Amount)',
    'request_number': 'Nomor Surat Permintaan Penjelasan Banding (Request Number)',
    'request_issue_date': 'Tanggal Diterbitkan (Issue Date)',
    'request_receipt_date': 'Tanggal Diterima (Receipt Date)',
    'explanation_letter_number': 'Nomor Surat Penjelasan (Explanation Letter Number)',
    'explanation_submission_date': 'Tanggal Dilaporkan (Submission Date)',
    'supreme_court_letter_number': 'Nomor Surat Peninjauan Kembali (Supreme Court Letter Number)',
    'review_amount': 'Nilai (Review Amount)',

  'spt_number': 'SPT Number', 'filing_date': 'Filing Date', 'received_date': 'Received Date',
  'reported_amount': 'Reported Amount', 'vat_in_amount': 'Input VAT Amount', 'vat_out_amount': 'Output VAT Amount',
  'description': 'Description', 'auditor_position': 'Auditor Position', 'notes': 'Notes',
  'skp_due_date': 'SKP Due Date', 'spuh_number': 'SPUH Number', 'reply_number': 'Reply Letter Number', 'reply_date': 'Reply Date',
  'appeal_number': 'Appeal Letter Number', 'dispute_number': 'Dispute Number', 'appeal_amount': 'Appeal Amount',
  'appeal_grounds': 'Grounds for Appeal', 'decision_notes': 'Decision Notes', 'submission_number': 'Submission Number',
  'submission_amount': 'Submission Amount', 'refund_amount': 'Refund Amount',
  'supporting_docs': 'Supporting Documents', 'create_refund': 'Create Refund Process', 'continue_to_next_stage': 'Continue to Next Stage'
}

// Presentation metadata only; API keys and server allowlists remain authoritative.
export const revisionFieldLabel = (field, stage, definitions = []) => {
  if (field === 'disputed_amount') return String(stage) === '1' ? 'SPT Amount' : 'Disputed Amount'
  return REVISION_LABELS[field] || definitions.find(def => def.key === field)?.label
    || String(field).replace(/_/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase())
}

const MONEY_FIELDS = new Set(['reported_amount', 'disputed_amount', 'vat_in_amount', 'vat_out_amount',
  'royalty_finding', 'service_finding', 'other_finding', 'skp_amount', 'royalty_correction',
  'service_correction', 'other_correction', 'objection_amount', 'appeal_amount', 'decision_amount',
  'submission_amount', 'review_amount', 'refund_amount'])
export const isRevisionMoney = field => MONEY_FIELDS.has(field)
// Mirrors RevisionFieldConfig::REQUIRED_VALUES; checked across all 12 stages.
const REQUIRED_REVISION_VALUES = {
  1: ['spt_position_type', 'reported_amount', 'disputed_amount'],
  3: ['sphp_number', 'sphp_issue_date'],
  4: ['skp_number', 'issue_date', 'skp_type', 'skp_amount'],
  5: ['objection_number', 'submission_date', 'objection_amount'],
  6: ['spuh_number', 'issue_date', 'receipt_date'],
  7: ['decision_number', 'decision_date', 'decision_type', 'decision_amount'],
  8: ['appeal_number', 'submission_date', 'appeal_amount'],
  10: ['decision_number', 'decision_date', 'decision_type', 'decision_amount'],
  11: ['submission_number', 'submission_date', 'submission_amount'],
  12: ['decision_number', 'decision_date', 'decision_type', 'decision_amount'],
}
export const revisionCanClear = (stage, field) => !['create_refund', 'continue_to_next_stage'].includes(field)
  && !(REQUIRED_REVISION_VALUES[Number(stage)] || []).includes(field)
export const revisionProposal = (stage, field, values) => {
  if (!Object.prototype.hasOwnProperty.call(values, field) || values[field] === undefined) {
    throw new Error('Enter a proposed value or explicitly clear the field.')
  }
  const value = values[field]
  if (value === null || value === '') {
    if (!revisionCanClear(stage, field)) throw new Error('This field cannot be cleared; enter a value.')
    return null
  }
  if (['create_refund', 'continue_to_next_stage'].includes(field) && typeof value !== 'boolean') {
    throw new Error('Choose Yes or No explicitly.')
  }
  if (Number(stage) === 11 && field === 'review_amount' && !/^\d{1,15}(?:\.\d{1,2})?$/.test(String(value))) {
    throw new Error('Enter a Review Amount with at most 15 whole digits and two decimal places.')
  }
  return value
}
export const revisionCurrency = taxCase => /^[A-Z]{3}$/.test(taxCase?.currency?.code || '') ? taxCase.currency.code : ''
export const revisionMoneyPreview = (value, currency = '') => {
  const raw = String(value ?? '')
  return /^\d{1,18}(?:\.\d{1,2})?$/.test(raw) ? formatRefundAmount(raw, currency) : ''
}
export const revisionDisplayValue = (field, value, currency = '') => {
  if (value === null || value === undefined || value === '') return '(empty)'
  if (['create_refund', 'continue_to_next_stage'].includes(field)) return [true, 1, '1'].includes(value) ? 'Yes' : 'No'
  if (isRevisionMoney(field)) return formatRefundAmount(value, currency)
  return value
}

/**
 * Frontend Field Configuration for Revisions
 * Mirrors backend RevisionFieldConfig for consistency
 * 
 * Usage:
 *   import { useRevisionFields } from '@/composables/useRevisionFields'
 *   const { getFieldLabel, getFieldLabels, getAvailableFields } = useRevisionFields()
 */

const FIELD_CONFIGURATIONS = {
  'tax-cases': {
    labels: {
      'period_id': 'Tax Period',
      'currency_id': 'Currency',
      'disputed_amount': 'Disputed Amount',
      'supporting_docs': 'Supporting Documents'
    },
    availableFields: [
      'period_id',
      'currency_id',
      'disputed_amount',
      'supporting_docs'
    ],
    documentFields: ['supporting_docs']
  },
  'skp-records': {
    labels: {
      'skp_number': 'SKP Number',
      'skp_due_date': 'Tanggal Jatuh Tempo SKP',
      'skp_date': 'SKP Date',
      'amount': 'Amount',
      'attachments': 'Attachments'
    },
    availableFields: [
      'skp_number',
      'skp_due_date',
      'skp_date',
      'amount',
      'attachments'
    ],
    documentFields: ['attachments']
  },
  'sphp-records': {
    labels: {
      'sphp_number': 'SPHP Number',
      'sphp_date': 'SPHP Date',
      'amount': 'Amount',
      'documents': 'Documents'
    },
    availableFields: [
      'sphp_number',
      'sphp_date',
      'amount',
      'documents'
    ],
    documentFields: ['documents']
  },
  'objection-submissions': {
    labels: {
      'objection_amount': 'Objection Amount',
      'objection_date': 'Objection Date',
      'description': 'Description',
      'attachments': 'Attachments'
    },
    availableFields: [
      'objection_amount',
      'objection_date',
      'description',
      'attachments'
    ],
    documentFields: ['attachments']
  },
  'spuh-records': {
    labels: {
      'spuh_number': 'SPUH Number',
      'issue_date': 'Issue Date',
      'receipt_date': 'Receipt Date',
      'reply_number': 'Reply Letter Number',
      'reply_date': 'Reply Date',
      'supporting_docs': 'Supporting Documents'
    },
    availableFields: [
      'spuh_number',
      'issue_date',
      'receipt_date',
      'reply_number',
      'reply_date',
      'supporting_docs'
    ],
    documentFields: ['supporting_docs']
  },
  'appeal-submissions': {
    labels: {
      'appeal_number': 'Appeal Letter Number',
      'submission_date': 'Submission Date',
      'appeal_amount': 'Appeal Amount',
      'dispute_number': 'Dispute Number',
      'supporting_docs': 'Supporting Documents'
    },
    availableFields: [
      'appeal_number',
      'submission_date',
      'appeal_amount',
      'dispute_number',
      'supporting_docs'
    ],
    documentFields: ['supporting_docs']
  }
}

/**
 * Get all available field labels for a model type
 * @param {string} modelType - Model type (e.g., 'tax-cases', 'skp-records')
 * @returns {object} Object with field names as keys and labels as values
 */
export const getFieldLabels = (modelType) => {
  return FIELD_CONFIGURATIONS[modelType]?.labels || {}
}

/**
 * Get label for a specific field
 * @param {string} modelType - Model type
 * @param {string} fieldName - Field name
 * @returns {string} Field label or the field name if not found
 */
export const getFieldLabel = (modelType, fieldName) => {
  const labels = getFieldLabels(modelType)
  return labels[fieldName] || revisionFieldLabel(fieldName)
}

/**
 * Get all available fields for a model type
 * @param {string} modelType - Model type
 * @returns {array} Array of field names
 */
export const getAvailableFields = (modelType) => {
  return FIELD_CONFIGURATIONS[modelType]?.availableFields || []
}

/**
 * Get document fields for a model type
 * @param {string} modelType - Model type
 * @returns {array} Array of document field names
 */
export const getDocumentFields = (modelType) => {
  return FIELD_CONFIGURATIONS[modelType]?.documentFields || []
}

/**
 * Check if a field is a document field
 * @param {string} modelType - Model type
 * @param {string} fieldName - Field name
 * @returns {boolean}
 */
export const isDocumentField = (modelType, fieldName) => {
  return getDocumentFields(modelType).includes(fieldName)
}

/**
 * Composable wrapper for use in Vue components
 */
export function useRevisionFields() {
  return {
    getFieldLabels,
    getFieldLabel,
    getAvailableFields,
    getDocumentFields,
    isDocumentField
  }
}

export default {
  getFieldLabels,
  getFieldLabel,
  getAvailableFields,
  getDocumentFields,
  isDocumentField,
  useRevisionFields
}
