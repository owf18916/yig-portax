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
      'skp_date': 'SKP Date',
      'amount': 'Amount',
      'attachments': 'Attachments'
    },
    availableFields: [
      'skp_number',
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
      'appeal_letter_number': 'Appeal Letter Number',
      'submission_date': 'Submission Date',
      'appeal_amount': 'Appeal Amount',
      'dispute_number': 'Dispute Number',
      'supporting_docs': 'Supporting Documents'
    },
    availableFields: [
      'appeal_letter_number',
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
  return labels[fieldName] || fieldName
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
