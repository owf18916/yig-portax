import { ref, computed } from 'vue'

/**
 * useRevisionAPI - Composable for generic revision API interactions
 * Supports any model type (TaxCase, SKP, SPHP, ObjectionSubmission) dynamically
 * 
 * Usage:
 *   const { requestRevision, decideRevision, getRevision, loading, error } = useRevisionAPI()
 *   await requestRevision('tax-cases', 123, proposedValues, documentChanges, reason, fields)
 *   await decideRevision('tax-cases', 123, 456, 'approve', null)
 */

export function useRevisionAPI() {
  const loading = ref(false)
  const error = ref(null)
  const successMessage = ref(null)

  /**
   * Get CSRF token from meta tag
   */
  const getCsrfToken = () => {
    return document.querySelector('meta[name="csrf-token"]')?.content || ''
  }

  /**
   * Build endpoint URL for any model type
   * Examples:
   *   buildUrl('tax-cases', 123, 'revisions/request')
   *   buildUrl('tax-cases', 123, 'revisions/456/decide')
   */
  const buildUrl = (entityType, entityId, path) => {
    return `/api/${entityType}/${entityId}/${path}`
  }

  /**
   * Handle API response
   */
  const handleResponse = async (response) => {
    if (!response.ok) {
      const errorData = await response.json()
      throw new Error(errorData.error || errorData.message || `HTTP ${response.status}`)
    }
    return response.json()
  }

  /**
   * Request a new revision for any entity type
   * 
   * @param {string} entityType - Entity type ('tax-cases', 'skp-records', 'sphp-records', 'objection-submissions')
   * @param {number} entityId - Entity ID
   * @param {object} proposedValues - Values being proposed
   * @param {object} documentChanges - Document changes { files_to_delete: [...], files_to_add: [...] }
   * @param {string} reason - Reason for revision
   * @param {array} fields - Fields being revised
   * @returns {Promise<object>} Created revision object
   */
  const requestRevision = async (
    entityType,
    entityId,
    proposedValues,
    documentChanges,
    reason,
    fields
  ) => {
    loading.value = true
    error.value = null
    successMessage.value = null

    try {
      const url = buildUrl(entityType, entityId, 'revisions/request')
      
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': getCsrfToken()
        },
        body: JSON.stringify({
          fields,
          reason,
          proposed_values: proposedValues,
          proposed_document_changes: documentChanges
        })
      })

      const data = await handleResponse(response)
      successMessage.value = data.message || 'Revision requested successfully'
      return data.revision
    } catch (err) {
      error.value = err.message
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Decide on a revision (approve or reject)
   * 
   * @param {string} entityType - Entity type ('tax-cases', 'skp-records', 'sphp-records', 'objection-submissions')
   * @param {number} entityId - Entity ID
   * @param {number} revisionId - Revision ID
   * @param {string} decision - 'approve' or 'reject'
   * @param {string|null} rejectionReason - Reason if rejecting (required if decision is 'reject')
   * @returns {Promise<object>} Updated revision object
   */
  const decideRevision = async (
    entityType,
    entityId,
    revisionId,
    decision,
    rejectionReason = null
  ) => {
    loading.value = true
    error.value = null
    successMessage.value = null

    try {
      const url = buildUrl(entityType, entityId, `revisions/${revisionId}/decide`)
      
      const payload = {
        decision,
        ...(decision === 'reject' && { rejection_reason: rejectionReason })
      }

      const response = await fetch(url, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': getCsrfToken()
        },
        body: JSON.stringify(payload)
      })

      const data = await handleResponse(response)
      successMessage.value = data.message || 'Decision submitted successfully'
      return data.revision
    } catch (err) {
      error.value = err.message
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Get revision details with comparison
   * 
   * @param {string} entityType - Entity type
   * @param {number} entityId - Entity ID
   * @param {number} revisionId - Revision ID
   * @returns {Promise<object>} Revision object with comparison data
   */
  const getRevision = async (entityType, entityId, revisionId) => {
    loading.value = true
    error.value = null

    try {
      const url = buildUrl(entityType, entityId, `revisions/${revisionId}`)
      
      const response = await fetch(url, {
        headers: {
          'X-CSRF-TOKEN': getCsrfToken()
        }
      })

      const data = await handleResponse(response)
      return data.data || data.revision
    } catch (err) {
      error.value = err.message
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Get all revisions for an entity
   * 
   * @param {string} entityType - Entity type
   * @param {number} entityId - Entity ID
   * @returns {Promise<array>} Array of revision objects
   */
  const listRevisions = async (entityType, entityId) => {
    loading.value = true
    error.value = null

    try {
      const url = buildUrl(entityType, entityId, 'revisions')
      
      const response = await fetch(url, {
        headers: {
          'X-CSRF-TOKEN': getCsrfToken()
        }
      })

      const data = await handleResponse(response)
      return data.data || data.revisions || []
    } catch (err) {
      error.value = err.message
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Clear error and success messages
   */
  const clearMessages = () => {
    error.value = null
    successMessage.value = null
  }

  return {
    // State
    loading: computed(() => loading.value),
    error: computed(() => error.value),
    successMessage: computed(() => successMessage.value),

    // Methods
    requestRevision,
    decideRevision,
    getRevision,
    listRevisions,
    clearMessages,
    buildUrl // Export for custom use cases
  }
}
